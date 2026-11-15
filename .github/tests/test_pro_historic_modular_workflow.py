"""Offline regression tests for manual full-family STAGING -> PRO publication.

These tests never contact production FTP or WordPress.
"""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/despliege_de_main_a_pro.yml"
SCRIPT = ROOT / ".github/scripts/deploy_pro_simple.py"
SPEC = importlib.util.spec_from_file_location("deploy_pro_simple", SCRIPT)
PRO = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PRO)


class ProFullFamilyTests(unittest.TestCase):
    def test_manual_only_and_uses_existing_credentials(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("name: STAGING a PRODUCCION", workflow)
        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn("workflow_call:", workflow)
        self.assertNotIn("\n  push:", workflow)
        self.assertIn("ref: staging", workflow)
        self.assertIn("deploy_pro_simple.py", workflow)
        self.assertIn('test "$GITHUB_REF_NAME" = "staging"', workflow)
        for key in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD"):
            self.assertIn("secrets." + key, workflow)
        for forbidden in (
            "fetch_pro_installed_modules.py",
            "INSTALLED_MODULES_JSON",
            "get_plugins()",
            "active_plugins",
            "deploy_pro_ftps_native.py",
        ):
            self.assertNotIn(forbidden, workflow)

    def test_one_time_receiver_uses_wordpress_real_directory_without_inventory(self):
        php = PRO.receiver_php("a" * 64, 9999999999, "run-offline")
        self.assertIn("REQUEST_METHOD", php)
        self.assertIn("!== 'GET'", php)
        self.assertIn("$_GET['token']", php)
        self.assertIn("hash_equals(", php)
        self.assertIn("dirname(__DIR__, 3) . '/wp-load.php'", php)
        self.assertIn("WP_PLUGIN_DIR . '/' . $slug", php)
        self.assertIn("wp_mkdir_p($destination)", php)
        self.assertIn("manifest_sha256", php)
        self.assertNotIn("get_plugins(", php)
        self.assertNotIn("active_plugins", php)

    def test_reconcile_every_tracked_plugin_file_except_gitlab(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            subprocess.check_call(["git", "init", "-q"], cwd=root)
            paths = {
                "taxocommerce-core/taxocommerce-core.php": "<?php // entry",
                "taxocommerce-core/readme.txt": "Stable tag: 1.0",
                "taxocommerce-core/CHANGELOG.md": "history",
                "taxocommerce-core/languages/core.pot": "messages",
                "taxocommerce-core/tests/smoke.php": "<?php // test",
                "taxocommerce-core/.gitlab/config.yml": "secret ci config",
                "taxocommerce-customers/taxocommerce-customers.php": "<?php // customer",
                "taxocommerce-customers/readme.txt": "Stable tag: 1.0",
            }
            for name, content in paths.items():
                file = root / name
                file.parent.mkdir(parents=True, exist_ok=True)
                file.write_text(content, encoding="utf-8")
            subprocess.check_call(["git", "add", "-A"], cwd=root)
            previous = Path.cwd()
            try:
                os.chdir(root)
                modules = PRO.deliverables()
                packages = PRO.create_packages(modules)
            finally:
                os.chdir(previous)
            self.assertEqual(set(modules), {"taxocommerce-core", "taxocommerce-customers"})
            self.assertEqual(len(modules["taxocommerce-core"]), 5)
            archive, digest, count = packages["taxocommerce-core"]
            with zipfile.ZipFile(io.BytesIO(archive)) as z:
                entries = z.namelist()
                self.assertIn("tests/smoke.php", entries)
                self.assertIn("languages/core.pot", entries)
                self.assertIn("CHANGELOG.md", entries)
                self.assertFalse(any(".gitlab" in x for x in entries))
                manifest = {name: hashlib.sha256(z.read(name)).hexdigest() for name in entries}
            self.assertEqual(count, 5)
            expected = hashlib.sha256(
                json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode("ascii")
            ).hexdigest()
            self.assertEqual(digest, expected)

    def test_actual_staging_plugins_can_be_packaged_offline(self):
        """Exercise all tracked source files, never open FTP or HTTP."""
        modules = PRO.deliverables()
        self.assertGreaterEqual(len(modules), 19)
        packages = PRO.create_packages(modules)
        self.assertEqual(set(modules), set(packages))
        self.assertGreater(sum(item[2] for item in packages.values()), 300)
        for slug, (archive, digest, count) in packages.items():
            self.assertEqual(len(digest), 64)
            self.assertGreater(count, 0)
            with zipfile.ZipFile(io.BytesIO(archive)) as content:
                self.assertEqual(content.testzip(), None, slug)
                self.assertEqual(len(content.namelist()), count)
                self.assertFalse(any(".gitlab" in name for name in content.namelist()))

    def test_bad_plugin_source_rejected_before_any_ftp_connection(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            subprocess.check_call(["git", "init", "-q"], cwd=root)
            file = root / "taxocommerce-core" / "other.php"
            file.parent.mkdir(parents=True)
            file.write_text("test", encoding="utf-8")
            subprocess.check_call(["git", "add", "-A"], cwd=root)
            previous = Path.cwd()
            try:
                os.chdir(root)
                with self.assertRaisesRegex(RuntimeError, "Missing local plugin main file"):
                    PRO.deliverables()
            finally:
                os.chdir(previous)


if __name__ == "__main__":
    unittest.main()
