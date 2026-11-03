"""Offline safety/functional regressions for manual PRO family reconciliation."""
import ftplib
import importlib.util
import os
from pathlib import Path
import posixpath
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("deploy_pro_all", Path(__file__).resolve().parents[1] / "scripts" / "deploy_pro_all.py")
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)


class FakeFTP:
    def __init__(self):
        self.dirs = {"/", "/site", "/site/wp-content", "/site/wp-content/plugins",
                     "/site/wp-content/plugins/seo-taxonomy"}
        self.files = {"/site/wp-content/plugins/seo-taxonomy/seo-taxonomy.php": b"<?php /* Plugin Name: SEO Taxonomy */"}
        self.here = "/site/wp-content/plugins/seo-taxonomy"
        self.transfers = []
        self.tamper = False

    def pwd(self):
        return self.here

    def absolute(self, name):
        return posixpath.normpath(posixpath.join(self.here, name))

    def cwd(self, path):
        dest = self.absolute(path)
        if dest not in self.dirs:
            raise ftplib.error_perm("550 directory not found")
        self.here = dest

    def mkd(self, path):
        dest = self.absolute(path)
        if posixpath.dirname(dest) not in self.dirs:
            raise ftplib.error_perm("550 parent not found")
        self.dirs.add(dest)

    def retrbinary(self, cmd, callback):
        path = self.absolute(cmd.removeprefix("RETR "))
        if path not in self.files:
            raise ftplib.error_perm("550 file not found")
        callback(self.files[path])

    def storbinary(self, cmd, stream):
        path = self.absolute(cmd.removeprefix("STOR "))
        self.files[path] = stream.read()
        self.transfers.append(path)

    def rename(self, src, dst):
        src, dst = self.absolute(src), self.absolute(dst)
        self.files[dst] = b"tampered" if self.tamper else self.files[src]
        del self.files[src]

    def delete(self, filename):
        path = self.absolute(filename)
        if path not in self.files:
            raise ftplib.error_perm("550 not found")
        del self.files[path]


class DeployProTests(unittest.TestCase):
    def test_excludes_only_non_release_files(self):
        rejected = ["taxocommerce-core/tests/test.php", "taxocommerce-core/.env", "taxocommerce-core/foo/.gitignore",
                    "taxocommerce-core/languages/taxocommerce-core.pot", "taxocommerce-core/CHANGELOG.md",
                    "taxocommerce-core/uploads/test.jpg", "taxocommerce-core/assets/test.pyc", "seo-taxonomy/index.php",
                    "taxocommerce-core/../file.php", "taxocommerce-core/src/.hidden/file.php"]
        for path in rejected:
            self.assertIsNone(mod.distributable(path), path)
        for path in ("taxocommerce-core/taxocommerce-core.php", "taxocommerce-core/readme.txt",
                     "taxocommerce-core/changelog.txt", "taxocommerce-core/languages/taxocommerce-core-es_ES.mo"):
            self.assertIsNotNone(mod.distributable(path), path)

    def test_inventory_all_slugs_without_installed_filter(self):
        with tempfile.TemporaryDirectory() as temp:
            cwd = os.getcwd()
            try:
                os.chdir(temp)
                for slug in ("taxocommerce-core", "taxocommerce-foo"):
                    Path(slug).mkdir()
                    (Path(slug) / (slug + ".php")).write_text("<?php")
                    (Path(slug) / "readme.txt").write_text("release")
                    (Path(slug) / "tests").mkdir()
                    (Path(slug) / "tests" / "test.php").write_text("<?php")
                files = b"\0".join(p.encode() for p in [
                    "taxocommerce-core/taxocommerce-core.php", "taxocommerce-core/readme.txt", "taxocommerce-core/tests/test.php",
                    "taxocommerce-foo/taxocommerce-foo.php", "taxocommerce-foo/readme.txt"]) + b"\0"
                with patch.object(mod.subprocess, "check_output", return_value=files):
                    got = mod.release_inventory()
                self.assertEqual(set(got), {"taxocommerce-core", "taxocommerce-foo"})
                self.assertNotIn("tests/test.php", got["taxocommerce-core"])
                self.assertEqual(got["taxocommerce-core"][-1], "taxocommerce-core.php")
            finally:
                os.chdir(cwd)

    def test_ftp_login_guard(self):
        ftp = FakeFTP()
        root = mod.enter_wp_plugins_root(ftp)
        self.assertEqual(root, "/site/wp-content/plugins")
        self.assertEqual(ftp.here, root)
        bad = FakeFTP()
        bad.here = "/site/wp-content/plugins"
        with self.assertRaises(RuntimeError):
            mod.enter_wp_plugins_root(bad)

    def test_reconcile_all_creates_missing_plugin_without_deleting(self):
        ftp = FakeFTP()
        root = mod.enter_wp_plugins_root(ftp)
        with tempfile.TemporaryDirectory() as temp:
            cwd = os.getcwd()
            try:
                os.chdir(temp)
                slug = "taxocommerce-core"
                (Path(slug) / "languages").mkdir(parents=True)
                (Path(slug) / "languages" / "core-es.mo").write_bytes(b"MO")
                (Path(slug) / "readme.txt").write_text("release")
                (Path(slug) / (slug + ".php")).write_text("<?php /* Version: 1.0 */")
                files = ["languages/core-es.mo", "readme.txt", slug + ".php"]
                report = []
                mod.reconcile_one(ftp, root, slug, files, report)
                self.assertEqual(report[0]["updated"], 3)
                self.assertEqual(len(ftp.transfers), 3)
                self.assertFalse(any(".taxocommerce-upload-" in path for path in ftp.files))
                self.assertIn("/site/wp-content/plugins/seo-taxonomy/seo-taxonomy.php", ftp.files)
                mod.reconcile_one(ftp, root, slug, files, report)
                self.assertEqual(report[1]["unchanged"], 3)
                self.assertEqual(len(ftp.transfers), 3)
            finally:
                os.chdir(cwd)

    def test_integrity_mismatch_aborts(self):
        ftp = FakeFTP()
        root = mod.enter_wp_plugins_root(ftp)
        with tempfile.TemporaryDirectory() as temp:
            cwd = os.getcwd()
            try:
                os.chdir(temp)
                slug = "taxocommerce-core"
                Path(slug).mkdir()
                (Path(slug) / (slug + ".php")).write_text("content")
                ftp.tamper = True
                with self.assertRaisesRegex(RuntimeError, "Final remote SHA-256 mismatch"):
                    mod.reconcile_one(ftp, root, slug, [slug + ".php"], [])


if __name__ == "__main__":
    unittest.main()
