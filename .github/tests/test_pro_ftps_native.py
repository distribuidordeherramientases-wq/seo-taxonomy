"""Isolated tests of the FTPS delta writer; no network or WordPress access."""
import importlib.util
import io
import json
import pathlib
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(ROOT))
spec = importlib.util.spec_from_file_location("pro_ftps_native", ROOT / "deploy_pro_ftps_native.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FakeFTPS:
    def __init__(self):
        self.root = "/plugins"
        self.folder = self.root
        self.dirs = {"/plugins", "/plugins/taxocommerce-social",
                     "/plugins/taxocommerce-social/includes"}
        self.files = {}
        self.writes = 0
        self.fail_commit = False
    def cwd(self, folder):
        if folder not in self.dirs:
            raise module.ftplib.error_perm("550 Directory missing")
        self.folder = folder
    def mkd(self, folder):
        self.dirs.add(folder)
    def key(self, name):
        return name
    def retrbinary(self, command, callback, blocksize=8192):
        name = command.split(" ", 1)[1]
        if name not in self.files:
            raise module.ftplib.error_perm("550 File missing")
        callback(self.files[name])
    def storbinary(self, command, stream, blocksize=8192):
        name = command.split(" ", 1)[1]
        self.writes += 1
        self.files[name] = stream.read()
    def rename(self, src, dest):
        if self.fail_commit and ".tc-upload-" in src and ".tc-upload-" not in dest:
            raise module.ftplib.error_perm("550 Rename failed")
        if src not in self.files:
            raise module.ftplib.error_perm("550 File missing")
        if dest in self.files:
            raise AssertionError("Cannot replace without removing old file first")
        self.files[dest] = self.files.pop(src)
    def delete(self, name):
        if name not in self.files:
            raise module.ftplib.error_perm("550 File missing")
        del self.files[name]


class FTPSTests(unittest.TestCase):
    def test_unsafe_paths_never_write(self):
        for name in ("../wrong.php", "/etc/passwd", "tests/test.php", "uploads/x.png",
                     ".env", "includes/../other.php", "includes/a.php.bak",
                     "includes\\x.php"):
            self.assertFalse(module.target_is_safe("taxocommerce-social", name), name)
        self.assertFalse(module.target_is_safe("seo-taxonomy", "main.php"))

    def test_existing_identical_file_is_noop(self):
        ftp = FakeFTPS()
        ftp.files["taxocommerce-social/includes/core.php"] = b"same"
        status, backup = module.deploy_one(
            ftp, ftp.root, "taxocommerce-social/includes/core.php", b"same", "test")
        self.assertEqual(("unchanged", None), (status, backup))
        self.assertEqual(0, ftp.writes)

    def test_update_retains_backup_until_module_verified(self):
        ftp = FakeFTPS()
        name = "taxocommerce-social/includes/core.php"
        ftp.files[name] = b"old"
        status, backup = module.deploy_one(ftp, ftp.root, name, b"new", "test")
        self.assertEqual("updated", status)
        self.assertEqual(b"new", ftp.files[name])
        self.assertEqual(b"old", ftp.files[backup])
        ftp.delete(backup)
        self.assertNotIn(backup, ftp.files)

    def test_failed_rename_rolls_back_previous_bytes(self):
        ftp = FakeFTPS()
        name = "taxocommerce-social/includes/core.php"
        ftp.files[name] = b"old"
        ftp.fail_commit = True
        with self.assertRaises(module.ftplib.error_perm):
            module.deploy_one(ftp, ftp.root, name, b"new", "test")
        self.assertEqual(b"old", ftp.files[name])
        self.assertEqual({name}, set(ftp.files))

    def test_new_file_is_staged_and_verified(self):
        ftp = FakeFTPS()
        status, backup = module.deploy_one(
            ftp, ftp.root, "taxocommerce-social/includes/new.php", b"new", "test")
        self.assertEqual(("updated", None), (status, backup))
        self.assertEqual(b"new", ftp.files["taxocommerce-social/includes/new.php"])

    def test_delta_must_be_for_existing_plugin_and_files(self):
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            (root / "taxocommerce-social").mkdir()
            (root / "taxocommerce-social/readme.txt").write_text("test", encoding="utf-8")
            path = root / "delta.json"
            path.write_text(json.dumps({"taxocommerce-social": ["readme.txt"]}), encoding="utf-8")
            old_cwd = pathlib.Path.cwd()
            try:
                import os
                os.chdir(root)
                result = module.load_delta(str(path), ["taxocommerce-social"])
                self.assertEqual({"taxocommerce-social": ["readme.txt"]}, result)
                with self.assertRaisesRegex(ValueError, "Uninstalled"):
                    module.load_delta(str(path), [])
            finally:
                os.chdir(old_cwd)


if __name__ == "__main__":
    unittest.main()
