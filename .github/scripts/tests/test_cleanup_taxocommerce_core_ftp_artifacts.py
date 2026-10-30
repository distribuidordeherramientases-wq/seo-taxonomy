#!/usr/bin/env python3
"""Regression tests for Core's tightly scoped STAGING FTPS cleanup.

No FTP connections are opened. The real cleanup module is tested against a
fake FTP server with known Core test artifacts and unknown runtime files.
"""

import importlib.util
import os
from pathlib import Path
import ftplib
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "cleanup_taxocommerce_core_ftp_artifacts.py"
SPEC = importlib.util.spec_from_file_location("core_ftp_cleanup", SOURCE)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class FakeFTP:
    def __init__(self, filenames, tests_directory=True):
        self.files = set(filenames)
        self.tests_directory = tests_directory
        self.current_dir = None
        self.deleted = []
        self.closed = False

    def connect(self, host, port):
        assert host == "staging.example.invalid" and port == 21

    def login(self, username, password):
        assert username == "test-user" and password == "test-password"

    def prot_p(self):
        pass

    def cwd(self, path):
        if path == MODULE.PLUGIN_DIR:
            self.current_dir = "plugin"
        elif path == "tests":
            if not self.tests_directory:
                raise ftplib.error_perm("550 No such file or directory")
            self.current_dir = "tests"
        elif path == "..":
            self.current_dir = "plugin"
        else:
            raise AssertionError("Unexpected FTP cwd " + path)

    def delete(self, path):
        if self.current_dir != "plugin":
            raise AssertionError("Delete outside the plugin directory")
        if path not in self.files:
            raise ftplib.error_perm("550 No such file or directory")
        self.files.remove(path)
        self.deleted.append(path)

    def rmd(self, path):
        if path != "tests":
            raise AssertionError("Only the legacy tests directory may be removed")
        if not self.tests_directory:
            raise ftplib.error_perm("550 No such file or directory")
        if any(name.startswith("tests/") for name in self.files):
            raise ftplib.error_perm("550 Directory not empty")
        self.tests_directory = False

    def quit(self):
        self.closed = True


class CoreFtpCleanupTests(unittest.TestCase):
    def invoke(self, fake):
        env = {
            "TC_CORE_FTP_HOST": "staging.example.invalid",
            "TC_CORE_FTP_USER": "test-user",
            "TC_CORE_FTP_PASS": "test-password",
        }
        with patch.dict(os.environ, env):
            with patch.object(MODULE.ftplib, "FTP_TLS", return_value=fake):
                MODULE.main()
        self.assertTrue(fake.closed)

    def test_removes_only_known_development_artifacts(self):
        files = {
            ".ftp-deploy-sync-state.json",
            "includes/class-taxocommerce-core.php",
            "taxocommerce-core.php",
            "readme.txt",
            "assets/core.css",
            "uploads/customer-file.csv",
            "tests/editorial-guide-smoke.php",
            "tests/menu-editor-smoke.php",
            "tests/anomalies-smoke.php",
            "tests/modular-architecture-smoke.php",
        }
        fake = FakeFTP(files)
        self.invoke(fake)
        self.assertEqual(
            fake.files,
            {
                "includes/class-taxocommerce-core.php",
                "taxocommerce-core.php",
                "readme.txt",
                "assets/core.css",
                "uploads/customer-file.csv",
            },
        )
        self.assertFalse(fake.tests_directory)

    def test_idempotent_when_legacy_files_already_absent(self):
        fake = FakeFTP({"taxocommerce-core.php", "readme.txt"}, tests_directory=False)
        self.invoke(fake)
        self.assertEqual(fake.files, {"taxocommerce-core.php", "readme.txt"})
        self.assertEqual(fake.deleted, [])

    def test_unknown_test_file_is_preserved_and_fails_safely(self):
        fake = FakeFTP({"tests/unknown-runtime.php", "taxocommerce-core.php"})
        with self.assertRaisesRegex(RuntimeError, "unknown files"):
            self.invoke(fake)
        self.assertIn("tests/unknown-runtime.php", fake.files)
        self.assertIn("taxocommerce-core.php", fake.files)
        self.assertTrue(fake.closed)


if __name__ == "__main__":
    unittest.main()
