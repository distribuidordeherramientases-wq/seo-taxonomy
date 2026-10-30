"""Offline safety regression tests for the PRO reconciliation flow."""

import importlib.util
import json
import os
import pathlib
import tempfile
import unittest
from unittest.mock import patch

source = pathlib.Path(__file__).resolve().parents[1] / 'reconcile_taxocommerce_pro.py'
spec = importlib.util.spec_from_file_location('reconcile_taxocommerce_pro', source)
reconcile = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reconcile)


class FakeFTP:
    def __init__(self, files):
        self.files = dict(files)
        self.cwd_path = '/wp-content/plugins'
        self.deleted = []

    def cwd(self, path):
        import posixpath
        path = path.removeprefix('./')
        target = posixpath.normpath(path if path.startswith('/') else posixpath.join(self.cwd_path, path))
        if target != '/wp-content/plugins' and not any(p.startswith(target + '/') for p in self.files):
            raise RuntimeError('not a directory: ' + target)
        self.cwd_path = target

    def mlsd(self):
        root = self.cwd_path.rstrip('/') + '/'
        dirs = set()
        result = []
        for path, data in self.files.items():
            if not path.startswith(root):
                continue
            relative = path[len(root):]
            if '/' in relative:
                dirs.add(relative.split('/')[0])
            else:
                result.append((relative, {'type': 'file', 'size': str(len(data))}))
        result += [(d, {'type': 'dir'}) for d in sorted(dirs)]
        return sorted(result)

    def pwd(self):
        return self.cwd_path

    def retrbinary(self, command, callback):
        assert command.startswith('RETR ')
        callback(self.files[self.cwd_path + '/' + command[5:]])

    def delete(self, filename):
        path = self.cwd_path + '/' + filename
        self.deleted.append(path)
        del self.files[path]

    def rmd(self, dirname):
        pass

    def quit(self):
        pass


class ProReconcileTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.original = os.getcwd()
        os.chdir(self.temp.name)
        self.oldenv = dict(os.environ)
        os.environ['INSTALLED_MODULES_JSON'] = '["taxocommerce-core"]'
        self.ftp = FakeFTP({
            '/wp-content/plugins/taxocommerce-core/taxocommerce-core.php': b'new version',
            '/wp-content/plugins/taxocommerce-core/includes/obsolete.php': b'legacy PHP',
            '/wp-content/plugins/taxocommerce-core/tests/check.php': b'test file',
            '/wp-content/plugins/taxocommerce-core/uploads/custom.png': b'preserve',
            '/wp-content/plugins/taxocommerce-core/.htaccess': b'protected',
        })
        reconcile.OUTPUT = pathlib.Path('.taxocommerce-cleanup')
        self.connect = patch.object(reconcile, 'ftp_connect', side_effect=lambda: (self.ftp, '/wp-content/plugins'))
        self.package = patch.object(reconcile, 'expected_package', return_value=({'taxocommerce-core.php'}, 'package-checksum'))
        self.tracked = patch.object(reconcile, 'source_files', return_value={'tests/check.php'})
        self.commit = patch.object(reconcile.subprocess, 'check_output', return_value='abcdef123\n')
        for mock in (self.connect, self.package, self.tracked, self.commit):
            mock.start()

    def tearDown(self):
        for mock in (self.connect, self.package, self.tracked, self.commit):
            mock.stop()
        os.environ.clear()
        os.environ.update(self.oldenv)
        os.chdir(self.original)
        self.temp.cleanup()

    def test_preview_and_exact_approval_with_backup(self):
        reconcile.plan()
        report = json.loads((reconcile.OUTPUT / 'plan.json').read_text())
        self.assertEqual(2, len(report['candidates']))
        self.assertEqual({'not_in_source', 'excluded_from_deployment'},
                         {p['classification'] for p in report['candidates']})
        self.assertEqual(2, len(report['skipped']))
        self.assertEqual(0, len(self.ftp.deleted))
        os.environ['TC_APPROVED_CLEANUP_SHA256'] = report['approval_sha256']
        reconcile.plan()
        self.assertTrue((reconcile.OUTPUT / 'backup.zip').exists())
        reconcile.apply()
        self.assertEqual(2, len(self.ftp.deleted))
        self.assertIn('/wp-content/plugins/taxocommerce-core/uploads/custom.png', self.ftp.files)
        self.assertIn('/wp-content/plugins/taxocommerce-core/taxocommerce-core.php', self.ftp.files)

    def test_missing_database_ftp_directory_is_reported_and_blocks_deletion(self):
        import ftplib
        old_modules = os.environ['INSTALLED_MODULES_JSON']
        os.environ['INSTALLED_MODULES_JSON'] = '["taxocommerce-core", "taxocommerce-database"]'
        original_cwd = self.ftp.cwd

        def missing_database(path):
            if path.endswith('/taxocommerce-database'):
                raise ftplib.error_perm('550 No such file or directory')
            return original_cwd(path)

        self.ftp.cwd = missing_database
        self.package.stop()
        self.tracked.stop()
        self.package = patch.object(reconcile, 'expected_package',
                                    side_effect=lambda m: ({m + '.php'}, 'package-checksum'))
        self.tracked = patch.object(reconcile, 'source_files', return_value=set())
        self.package.start()
        self.tracked.start()

        reconcile.plan()
        report = json.loads((reconcile.OUTPUT / 'plan.json').read_text())
        self.assertTrue(any(item['module'] == 'taxocommerce-database' and
                            'inaccessible' in item['reason'] for item in report['skipped']))
        self.assertEqual([], self.ftp.deleted)
        os.environ['TC_APPROVED_CLEANUP_SHA256'] = report['approval_sha256']
        with self.assertRaisesRegex(RuntimeError, 'inventory incomplete'):
            reconcile.plan()
        with self.assertRaisesRegex(RuntimeError, 'inventory incomplete'):
            reconcile.apply()
        os.environ['INSTALLED_MODULES_JSON'] = old_modules

    def test_stale_approval_is_rejected(self):
        reconcile.plan()
        old = json.loads((reconcile.OUTPUT / 'plan.json').read_text())['approval_sha256']
        self.ftp.files['/wp-content/plugins/taxocommerce-core/includes/obsolete.php'] = b'changed'
        os.environ['TC_APPROVED_CLEANUP_SHA256'] = old
        with self.assertRaisesRegex(ValueError, 'does not match'):
            reconcile.plan()
        self.assertEqual(0, len(self.ftp.deleted))

    def test_concurrent_modification_blocks_all_deletes(self):
        reconcile.plan()
        report = json.loads((reconcile.OUTPUT / 'plan.json').read_text())
        os.environ['TC_APPROVED_CLEANUP_SHA256'] = report['approval_sha256']
        reconcile.plan()
        self.ftp.files['/wp-content/plugins/taxocommerce-core/includes/obsolete.php'] = b'changed'
        with self.assertRaisesRegex(RuntimeError, 'changed after approval'):
            reconcile.apply()
        self.assertEqual(0, len(self.ftp.deleted))

    def test_protected_paths_and_traversal(self):
        self.assertEqual('critical plugin lifecycle file',
                         reconcile.protected('taxocommerce-core.php', 'taxocommerce-core'))
        self.assertEqual('protected runtime directory',
                         reconcile.protected('data/token.json', 'taxocommerce-core'))
        self.assertTrue(reconcile.protected('../../wp-config.php', 'taxocommerce-core'))


if __name__ == '__main__':
    unittest.main()
