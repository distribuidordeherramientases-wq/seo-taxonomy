"""Offline checks for the one-off modular-only FTPS obsolete-file cleanup."""
import ftplib
import importlib.util
from pathlib import Path
import posixpath
import tempfile
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "cleanup_taxocommerce_family_ftp.py"
SPEC = importlib.util.spec_from_file_location("tc_family_cleanup", SOURCE)
CLEAN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CLEAN)


class FakeFTP:
    def __init__(self):
        self.root = "/wp-content/plugins"
        self.path = self.root
        self.files = {
            "taxocommerce-core/.ftp-deploy-sync-state.json": b"old ftp sync state",
            "taxocommerce-core/tests/editorial-guide-smoke.php": b"<?php echo 'test';",
            "taxocommerce-core/tests/nested/fixture.json": b'{"test":1}',
            "taxocommerce-core/readme.txt": b"official readme",
            "taxocommerce-core/taxocommerce-core.php": b"<?php // live",
            "taxocommerce-logistica/tests/smoke.php": b"<?php // dev smoke",
            "taxocommerce-logistica/includes/live.php": b"<?php // live",
            "seo-taxonomy/tests/monolith-test.php": b"<?php // must survive",
            "seo-taxonomy/.ftp-deploy-sync-state.json": b"never delete",
        }
        self.dirs = {
            "", "taxocommerce-core", "taxocommerce-core/tests",
            "taxocommerce-core/tests/nested", "taxocommerce-logistica",
            "taxocommerce-logistica/tests", "taxocommerce-logistica/includes",
            "seo-taxonomy", "seo-taxonomy/tests",
        }
        self.closed = False
        self.removed = []

    def cwd(self, path):
        if path.startswith("/"):
            absolute = posixpath.normpath(path)
        else:
            absolute = posixpath.normpath(self.path + "/" + path)
        if absolute == self.root or absolute.startswith(self.root + "/"):
            relative = absolute[len(self.root):].lstrip("/")
            if relative in self.dirs:
                self.path = absolute
                return
        raise ftplib.error_perm("550 No such directory")

    def pwd(self):
        return self.path

    def mlsd(self):
        relative = self.path[len(self.root):].lstrip("/")
        prefix = relative + "/" if relative else ""
        items = {}
        for d in self.dirs:
            if d.startswith(prefix) and d != relative:
                suffix = d[len(prefix):]
                if "/" not in suffix and suffix:
                    items[suffix] = {"type": "dir"}
        for key, data in self.files.items():
            if key.startswith(prefix):
                suffix = key[len(prefix):]
                if "/" not in suffix:
                    items[suffix] = {"type": "file", "size": str(len(data))}
        return sorted(items.items())

    def retrbinary(self, request, fn):
        name = request.removeprefix("RETR ")
        key = self.path[len(self.root):].lstrip("/") + "/" + name
        fn(self.files[key])

    def delete(self, name):
        key = self.path[len(self.root):].lstrip("/") + "/" + name
        del self.files[key]
        self.removed.append(key)

    def rmd(self, name):
        relative = self.path[len(self.root):].lstrip("/")
        key = (relative + "/" if relative else "") + name
        if any(f.startswith(key + "/") for f in self.files) or any(
            d.startswith(key + "/") for d in self.dirs):
            raise ftplib.error_perm("550 Directory not empty")
        self.dirs.remove(key)

    def quit(self):
        self.closed = True


class FamilyCleanupTests(unittest.TestCase):
    def test_strict_paths_and_source_allowlist(self):
        with tempfile.TemporaryDirectory() as root:
            root = Path(root)
            for name in ("taxocommerce-core", "taxocommerce-logistica"):
                p = root / name
                p.mkdir()
                (p / (name + ".php")).write_text("<?php")
                (p / "readme.txt").write_text("readme")
            old = root / "seo-taxonomy"
            old.mkdir()
            (old / "seo-taxonomy.php").write_text("<?php")
            self.assertEqual(CLEAN.allowed_modules(root),
                             {"taxocommerce-core", "taxocommerce-logistica"})
        self.assertTrue(CLEAN.candidate("taxocommerce-core", "tests/a.php"))
        self.assertTrue(CLEAN.candidate("taxocommerce-core", "tests/nested/x.json"))
        self.assertTrue(CLEAN.candidate("taxocommerce-core", CLEAN.STATE))
        for module, path in (
            ("seo-taxonomy", "tests/a.php"),
            ("taxocommerce-core", "includes/live.php"),
            ("taxocommerce-core", "readme.txt"),
            ("taxocommerce-core", "../seo-taxonomy/tests/a.php"),
            ("taxocommerce-core", ".ftpquota"),
            ("taxocommerce-core", "tests/../live.php"),
        ):
            self.assertFalse(CLEAN.candidate(module, path), (module, path))

    def test_plan_backup_delete_and_verify_leave_monolith_untouched(self):
        ftp = FakeFTP()
        original_monolith = {k: v for k, v in ftp.files.items()
                             if k.startswith("seo-taxonomy/")}
        with tempfile.TemporaryDirectory() as work:
            with patch.object(CLEAN, "OUT", Path(work)), \
                 patch.object(CLEAN, "allowed_modules",
                              return_value={"taxocommerce-core", "taxocommerce-logistica"}), \
                 patch.object(CLEAN, "connect", side_effect=lambda: (ftp, ftp.root)), \
                 patch.object(ftp, "quit", return_value=None):
                CLEAN.perform("plan")
                self.assertEqual(ftp.removed, [])
                with __import__("zipfile").ZipFile(Path(work) / "backup.zip") as backup:
                    self.assertEqual(backup.read("taxocommerce-core/.ftp-deploy-sync-state.json"),
                                     b"old ftp sync state")
                CLEAN.perform("apply")
                CLEAN.perform("verify")
                self.assertEqual(len(ftp.removed), 4)
                self.assertEqual({k: v for k, v in ftp.files.items()
                                  if k.startswith("seo-taxonomy/")}, original_monolith)
                self.assertEqual(ftp.files["taxocommerce-core/readme.txt"], b"official readme")
                self.assertEqual(ftp.files["taxocommerce-core/taxocommerce-core.php"], b"<?php // live")
                self.assertEqual(ftp.files["taxocommerce-logistica/includes/live.php"], b"<?php // live")
                CLEAN.perform("plan")
                CLEAN.perform("apply")
                CLEAN.perform("verify")

    def test_scoped_staging_cleanup_removes_only_three_selected_plugins(self):
        ftp = FakeFTP()
        ftp.dirs.update({
            "taxocommerce-database", "taxocommerce-database/tests",
            "taxocommerce-images", "taxocommerce-images/tests",
        })
        ftp.files["taxocommerce-database/tests/auto-test-results-smoke.php"] = b"<?php // dev"
        ftp.files["taxocommerce-images/tests/early-i18n-smoke.php"] = b"<?php // dev"
        ftp.files["taxocommerce-database/includes/live.php"] = b"<?php // live"
        ftp.files["taxocommerce-images/includes/live.php"] = b"<?php // live"
        ftp.dirs.update({
            "taxocommerce-database/includes", "taxocommerce-images/includes",
        })
        setting = {"TC_STAGING_THREE_MODULE_CLEANUP":
                   "taxocommerce-core,taxocommerce-database,taxocommerce-images"}
        with tempfile.TemporaryDirectory() as work:
            with patch.dict("os.environ", setting), \
                 patch.object(CLEAN, "OUT", Path(work)), \
                 patch.object(CLEAN, "allowed_modules",
                              return_value={"taxocommerce-core", "taxocommerce-database",
                                            "taxocommerce-images", "taxocommerce-logistica"}), \
                 patch.object(CLEAN, "connect", side_effect=lambda: (ftp, ftp.root)), \
                 patch.object(ftp, "quit", return_value=None):
                CLEAN.perform("plan")
                self.assertEqual(ftp.removed, [])
                CLEAN.perform("apply")
                CLEAN.perform("verify")
                self.assertEqual(len(ftp.removed), 5)
                self.assertIn("taxocommerce-logistica/tests/smoke.php", ftp.files)
                self.assertIn("taxocommerce-database/includes/live.php", ftp.files)
                self.assertIn("taxocommerce-images/includes/live.php", ftp.files)
                self.assertIn("seo-taxonomy/tests/monolith-test.php", ftp.files)

    def test_scoped_staging_cleanup_requires_all_three_remote_plugins(self):
        ftp = FakeFTP()  # DB and Images are deliberately absent.
        setting = {"TC_STAGING_THREE_MODULE_CLEANUP":
                   "taxocommerce-core,taxocommerce-database,taxocommerce-images"}
        with patch.dict("os.environ", setting), \
             patch.object(CLEAN, "allowed_modules",
                          return_value={"taxocommerce-core", "taxocommerce-database",
                                        "taxocommerce-images"}), \
             patch.object(CLEAN, "connect", side_effect=lambda: (ftp, ftp.root)), \
             patch.object(ftp, "quit", return_value=None):
            with self.assertRaisesRegex(RuntimeError, "Expected Core, Database and Images"):
                CLEAN.perform("plan")
            self.assertEqual(ftp.removed, [])

    def test_modified_remote_file_aborts_without_deleting_anything(self):
        ftp = FakeFTP()
        with tempfile.TemporaryDirectory() as work:
            with patch.object(CLEAN, "OUT", Path(work)), \
                 patch.object(CLEAN, "allowed_modules",
                              return_value={"taxocommerce-core", "taxocommerce-logistica"}), \
                 patch.object(CLEAN, "connect", side_effect=lambda: (ftp, ftp.root)), \
                 patch.object(ftp, "quit", return_value=None):
                CLEAN.perform("plan")
                ftp.files["taxocommerce-core/tests/editorial-guide-smoke.php"] = b"changed"
                with self.assertRaisesRegex(RuntimeError, "changed"):
                    CLEAN.perform("apply")
                self.assertEqual(ftp.removed, [])


if __name__ == "__main__":
    unittest.main()
