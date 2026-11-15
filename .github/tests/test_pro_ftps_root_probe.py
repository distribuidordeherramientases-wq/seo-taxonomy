"""Read-only tests for the PRO FTPS root locator (no network)."""
import importlib.util
import pathlib
import unittest

FILE = pathlib.Path(__file__).resolve().parents[1] / "scripts/pro_ftps_root_probe.py"
spec = importlib.util.spec_from_file_location("ftp_probe", FILE)
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)


class FakeFTP:
    def __init__(self, files, originals="/"):
        self.files = files
        self.place = originals
        self.writes = 0
    def cwd(self, name):
        if name == "/":
            self.place = "/"
            return
        if name not in self.files:
            raise probe.ftplib.error_perm("550 Not available")
        self.place = name
    def pwd(self):
        return "/" if self.place == "/" else self.files[self.place]["canonical"]
    def retrbinary(self, command, callback):
        path = command.split(" ", 1)[1]
        value = self.files[self.place].get(path)
        if value is None:
            raise probe.ftplib.error_perm("550 Missing")
        callback(value)
    def storbinary(self, *args, **kwargs):
        self.writes += 1
        raise AssertionError("Never upload in diagnostic")


def header(version):
    return ("<?php\n/**\n * Plugin Name: Test\n * Version: " + version + "\n */\n").encode()


class ProbeTests(unittest.TestCase):
    def setUp(self):
        self.expected = {
            "PRO_CORE_EXPECTED_VERSION": "0.4.84",
            "PRO_WOO_EXPECTED_VERSION": "11.3.0",
        }
        self.files = {
            "./wp-content/plugins": {
                "canonical": "/verified/plugins",
                "taxocommerce-core/taxocommerce-core.php": header("0.4.84"),
                "woocommerce/woocommerce.php": header("11.3.0"),
            },
            ".": {
                "canonical": "/site",
                "taxocommerce-core/taxocommerce-core.php": header("0.4.85"),
                "woocommerce/woocommerce.php": header("11.3.0"),
            },
        }
    def test_correct_unique_root(self):
        ftp = FakeFTP(self.files)
        found = probe.find_verified_root(ftp, self.expected)
        self.assertEqual(("./wp-content/plugins", "/verified/plugins"), found)
        self.assertEqual(0, ftp.writes)
    def test_fails_if_only_one_version_matches(self):
        self.files["./wp-content/plugins"]["woocommerce/woocommerce.php"] = header("11.2.0")
        with self.assertRaisesRegex(RuntimeError, "No unique"):
            probe.find_verified_root(FakeFTP(self.files), self.expected)
    def test_fails_if_same_versions_at_two_different_roots(self):
        self.files["."]["taxocommerce-core/taxocommerce-core.php"] = header("0.4.84")
        with self.assertRaisesRegex(RuntimeError, "No unique"):
            probe.find_verified_root(FakeFTP(self.files), self.expected)
    def test_missing_file_is_not_authorized(self):
        del self.files["./wp-content/plugins"]["taxocommerce-core/taxocommerce-core.php"]
        with self.assertRaisesRegex(RuntimeError, "No unique"):
            probe.find_verified_root(FakeFTP(self.files), self.expected)


if __name__ == "__main__":
    unittest.main()
