"""Offline regression for short STAGING -> PRO GitHub Actions workflow."""
from pathlib import Path
import importlib.util
import hashlib
import json
import os
import tempfile
import unittest
import zipfile
from unittest.mock import patch
from io import BytesIO

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / ".github/scripts/deploy_pro_simple.py"
spec = importlib.util.spec_from_file_location("pro_simple", SCRIPT)
pro = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pro)


class SimpleDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.workflow = (ROOT / ".github/workflows/despliege_de_main_a_pro.yml").read_text()

    def test_simple_staging_copy_with_existing_secrets(self):
        self.assertLessEqual(len(self.workflow.splitlines()), 50)
        self.assertIn("ref: staging", self.workflow)
        self.assertIn("select_pro_release_delta.py --all-changed", self.workflow)
        self.assertIn("deploy_pro_simple.py", self.workflow)
        self.assertIn("workflow_dispatch:", self.workflow)
        for key in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD"):
            self.assertIn("secrets." + key, self.workflow)
        for forbidden in ("fetch_pro_installed_modules.py", "INSTALLED_MODULES_JSON",
                          "get_plugins()", "active_plugins", "deploy_pro_ftps_native.py"):
            self.assertNotIn(forbidden, self.workflow)

    def test_upload_uses_real_wordpress_plugin_directory_and_auth(self):
        receiver = pro.receiver_php("a" * 64, 9999999999, "run-test")
        self.assertIn("dirname(__DIR__, 3) . '/wp-load.php'", receiver)
        self.assertIn("WP_PLUGIN_DIR . '/' . $slug", receiver)
        self.assertIn("hash_equals(", receiver)
        self.assertIn("seo_taxocommerce_deploy_apply_zip_delta", receiver)
        self.assertIn("manifest_sha256", receiver)
        self.assertNotIn("get_plugins(", receiver)

    def test_changed_files_only_and_sha256_package(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            plugin = base / "taxocommerce-core"
            plugin.mkdir()
            (plugin / "taxocommerce-core.php").write_bytes(b"<?php // version")
            (plugin / "readme.txt").write_text("stable")
            (plugin / "includes").mkdir()
            (plugin / "includes/main.php").write_bytes(b"<?php // changed")
            delta = base / "delta.json"
            delta.write_text(json.dumps({"taxocommerce-core": ["includes/main.php"]}))
            orig = Path.cwd()
            try:
                os.chdir(base)
                with patch.dict(os.environ, {"DELTA_FILE": str(delta)}):
                    modules = pro.deliverables()
                    packages = pro.create_packages(modules)
                archive, digest, count = packages["taxocommerce-core"]
                with zipfile.ZipFile(BytesIO(archive)) as z:
                    self.assertEqual(["includes/main.php"], z.namelist())
                    expected = {"includes/main.php": hashlib.sha256(b"<?php // changed").hexdigest()}
                self.assertEqual(count, 1)
                self.assertEqual(digest, hashlib.sha256(
                    json.dumps(expected, sort_keys=True, separators=(",", ":")).encode("ascii")
                ).hexdigest())
            finally:
                os.chdir(orig)

    def test_missing_or_unsafe_delta_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            plugin = base / "taxocommerce-core"
            plugin.mkdir()
            (plugin / "taxocommerce-core.php").write_text("entrypoint")
            delta = base / "delta.json"
            delta.write_text('{"taxocommerce-core":["../wp-config.php"]}')
            orig = Path.cwd()
            try:
                os.chdir(base)
                with patch.dict(os.environ, {"DELTA_FILE": str(delta)}):
                    with self.assertRaises(RuntimeError):
                        pro.deliverables()
            finally:
                os.chdir(orig)


if __name__ == "__main__":
    unittest.main()
