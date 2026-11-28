"""Regression: isolated Import-Export Pro ZIP, native Core discovery, test sites only."""
import hashlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".github/scripts"))
import publish_import_export_pro_package as pack


class ImportExportProPackageTests(unittest.TestCase):
    def test_package_is_canonical_complete_and_deterministic(self):
        data, info = pack.package_import_export_pro(ROOT)
        self.assertEqual((data, info), pack.package_import_export_pro(ROOT))
        self.assertEqual(info["sha256"], hashlib.sha256(data).hexdigest())
        self.assertEqual(info["size"], len(data))
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            names = set(archive.namelist())
            self.assertIsNone(archive.testzip())
            for path in ("taxocommerce-import-export-pro.php", "CHANGELOG.md", "readme.txt",
                         "includes/class-admin.php", "includes/class-batch.php", "assets/admin.js"):
                self.assertIn(pack.SLUG + "/" + path, names)
                self.assertEqual(archive.read(pack.SLUG + "/" + path), (ROOT / pack.SLUG / path).read_bytes())
            self.assertTrue(all(name.startswith(pack.SLUG + "/") for name in names))
            self.assertFalse(any("/tests/" in name or "/.git" in name for name in names))
        self.assertIn("Stable tag: " + info["version"], (ROOT / pack.SLUG / "readme.txt").read_text())

    def test_manifest_preserves_other_plugins_and_avoids_rewrites(self):
        raw = json.dumps({"schema": 1, "plugins": {"taxocommerce-mail-pro": {"version": "0.1.1"}}}).encode()
        info = {"version": "0.1.2", "sha256": "test", "size": 123}
        updated = pack.updated_manifest(raw, info)
        result = json.loads(updated)
        self.assertEqual(result["plugins"]["taxocommerce-mail-pro"], {"version": "0.1.1"})
        self.assertEqual(result["plugins"][pack.SLUG], info)
        self.assertEqual(pack.updated_manifest(updated, info), updated)
        for invalid in (b"{}", b"[]", b"not json"):
            with self.assertRaises(ValueError):
                pack.updated_manifest(invalid, info)

    def test_publish_only_authorized_sites_and_canonical_files(self):
        data, info = pack.package_import_export_pro(ROOT)
        self.assertEqual(pack.SITES, ("staging.distribuidordeherramientas.es", "asociacionfocazul"))
        original = b'{"schema":1,"plugins":{"other":{"version":"1.0"}}}'
        folders = ["/test/staging/wp-content/uploads/taxocommerce-core/plugins",
                   "/test/focazul/wp-content/uploads/taxocommerce-core/plugins"]
        ftp = mock.Mock()
        with mock.patch.object(pack, "ftp_root", side_effect=folders) as root, \
             mock.patch.object(pack, "read_file", return_value=original), \
             mock.patch.object(pack, "write_atomic", return_value="UPDATED") as write:
            result = pack.publish(ftp, "/test", data, info)
        self.assertEqual(list(result), list(pack.SITES))
        self.assertEqual([c.args[2] for c in root.call_args_list], list(pack.SITES))
        self.assertEqual([c.args[2] for c in write.call_args_list],
                         [pack.SLUG + ".zip", "manifest.json"] * 2)
        for call in write.call_args_list:
            self.assertIn(call.args[1], folders)
            self.assertEqual(hashlib.sha256(call.args[3]).hexdigest(), call.args[4])

    def test_invalid_manifest_prevents_zip_replacement(self):
        data, info = pack.package_import_export_pro(ROOT)
        with mock.patch.object(pack, "ftp_root", return_value="/test"), \
             mock.patch.object(pack, "read_file", return_value=b"{}"), \
             mock.patch.object(pack, "write_atomic") as write:
            with self.assertRaises(ValueError):
                pack.publish(mock.Mock(), "/test", data, info)
            write.assert_not_called()

    def test_real_core_discovery_before_install_and_after_install(self):
        data, _ = pack.package_import_export_pro(ROOT)
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "taxocommerce-core/plugins"
            path.mkdir(parents=True)
            (path / (pack.SLUG + ".zip")).write_bytes(data)
            (path / "taxocommerce-import-export-lite.zip").write_bytes(b"not a family package")
            result = subprocess.run(["php", str(ROOT / ".github/tests/import-export-pro-core-discovery-smoke.php"), folder],
                                    capture_output=True, text=True, check=True)
            self.assertIn("PASS: Import-Export Pro", result.stdout)

    def test_workflow_uses_verified_delta_and_no_production(self):
        workflow = (ROOT / ".github/workflows/deploy-staging.yml").read_text()
        block = workflow.split("\n  import-export-pro-package:", 1)[1]
        self.assertIn("needs: [plan, verify, mail-pro-package]", block)
        self.assertIn("github.event_name == 'push'", block)
        self.assertIn("needs.plan.outputs.base", block)
        self.assertIn("git diff --name-only", block)
        self.assertIn("publish_import_export_pro_package.py", block)
        self.assertNotIn("PRO_FTP_", block)


if __name__ == "__main__":
    unittest.main()
