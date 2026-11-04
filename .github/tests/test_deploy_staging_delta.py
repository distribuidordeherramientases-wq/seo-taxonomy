import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "deploy_staging_delta.py"
spec = importlib.util.spec_from_file_location("deploy_staging_delta", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class DeltaTests(unittest.TestCase):
    def test_distributable_whitelist(self):
        self.assertEqual(module.distributable("taxocommerce-core/includes/example.php"), ("taxocommerce-core", "includes/example.php"))
        for path in ("taxocommerce-core/tests/smoke.php", "taxocommerce-core/CHANGELOG.md",
                     "taxocommerce-core/.secrets", "taxocommerce-core/languages/.hidden.po",
                     "taxocommerce-core/uploads/payload.txt", "random/file.php"):
            self.assertIsNone(module.distributable(path), path)

    def test_shared_ftp_site_roots_are_isolated(self):
        self.assertEqual(
            module.remote_plugin_dir("staging", "taxocommerce-core"),
            "./staging.distribuidordeherramientas.es/wp-content/plugins/taxocommerce-core/",
        )
        self.assertEqual(
            module.remote_plugin_dir("focazul", "taxocommerce-core"),
            "./asociacionfocazul/wp-content/plugins/taxocommerce-core/",
        )
        self.assertEqual(
            module.remote_plugin_dir("staging", "taxocommerce-vocabulary"),
            "./staging.distribuidordeherramientas.es/wp-content/plugins/taxocommerce-vocabulary/",
        )
        self.assertEqual(
            module.remote_plugin_dir("focazul", "taxocommerce-templates"),
            "./asociacionfocazul/wp-content/plugins/taxocommerce-templates/",
        )
        for target, slug in (
            ("production", "taxocommerce-core"),
            ("staging", "../wp-config.php"),
        ):
            with self.assertRaises(ValueError):
                module.remote_plugin_dir(target, slug)

    def test_full_reconciliation_selects_existing_module_sources(self):
        with tempfile.TemporaryDirectory() as root:
            import os
            old = os.getcwd()
            os.chdir(root)
            try:
                Path("taxocommerce-templates/includes").mkdir(parents=True)
                Path("taxocommerce-templates/tests").mkdir()
                Path("taxocommerce-templates/taxocommerce-templates.php").write_text("<?php")
                Path("taxocommerce-templates/includes/class-tct-manual.php").write_text("<?php")
                Path("taxocommerce-templates/tests/smoke.php").write_text("<?php")
                with mock.patch.object(module, "git", return_value=(
                    b"taxocommerce-templates/taxocommerce-templates.php\\0"
                    b"taxocommerce-templates/includes/class-tct-manual.php\\0"
                    b"taxocommerce-templates/tests/smoke.php\\0"
                ).replace(b"\\0", b"\x00")):
                    self.assertEqual(
                        module.plugin_source_files("taxocommerce-templates"),
                        ["includes/class-tct-manual.php", "taxocommerce-templates.php"],
                    )
            finally:
                os.chdir(old)

    def test_optional_focazul_plugin_absent_is_not_created(self):
        ftp = mock.Mock()
        ftp.cwd.side_effect = module.ftplib.error_perm("550 no such directory")
        module.upload_plugin(ftp, "focazul", "taxocommerce-templates", ["taxocommerce-templates.php"], required=False)
        ftp.storbinary.assert_not_called()

    def test_upload_core_checks_correct_site_without_writing_other_site(self):
        ftp = mock.Mock()
        ftp.pwd.return_value = "/asociacionfocazul/wp-content/plugins/taxocommerce-core"
        with mock.patch.object(module, "existing_file_hash", return_value="present"):
            module.upload_plugin(ftp, "focazul", "taxocommerce-core", [], required=True)
        ftp.cwd.assert_called_once_with(
            "./asociacionfocazul/wp-content/plugins/taxocommerce-core/"
        )
        ftp.storbinary.assert_not_called()

    def test_reconcile_core_uses_one_source_tree_for_both_sites(self):
        ftp = mock.Mock()
        ftp.pwd.return_value = "/"
        files = ["taxocommerce-core.php", "includes/menu.php", "languages/core.mo"]
        with mock.patch.dict("os.environ", {
            "STAGING_FTP_SERVER": "ftps.example.test",
            "STAGING_FTP_USERNAME": "test-user",
            "STAGING_FTP_PASSWORD": "test-password",
        }):
            with mock.patch.object(module, "core_source_files", return_value=files):
                with mock.patch.object(module.ftplib, "FTP_TLS", return_value=ftp):
                    with mock.patch.object(module, "upload_plugin") as upload:
                        module.reconcile_core()
        self.assertEqual([x.args[1:] for x in upload.call_args_list], [
            ("staging", "taxocommerce-core", files),
            ("focazul", "taxocommerce-core", files),
        ])
        self.assertTrue(all(x.kwargs["required"] for x in upload.call_args_list))
        self.assertEqual(ftp.cwd.call_args_list, [mock.call("/"), mock.call("/")])
        ftp.prot_p.assert_called_once()

    def test_core_source_manifest_excludes_tests_and_hidden_files(self):
        with tempfile.TemporaryDirectory() as root:
            import os
            old = os.getcwd()
            os.chdir(root)
            try:
                Path("taxocommerce-core/includes").mkdir(parents=True)
                Path("taxocommerce-core/tests").mkdir()
                Path("taxocommerce-core/taxocommerce-core.php").write_text("<?php")
                Path("taxocommerce-core/includes/menu.php").write_text("<?php")
                Path("taxocommerce-core/CHANGELOG.md").write_text("# note")
                Path("taxocommerce-core/tests/test.php").write_text("<?php")
                with mock.patch.object(module, "git", return_value=(
                    b"taxocommerce-core/taxocommerce-core.php\\0"
                    b"taxocommerce-core/includes/menu.php\\0"
                    b"taxocommerce-core/tests/test.php\\0"
                    b"taxocommerce-core/CHANGELOG.md\\0"
                ).replace(b"\\0", b"\x00")):
                    self.assertEqual(module.core_source_files(), [
                        "includes/menu.php", "taxocommerce-core.php"
                    ])
            finally:
                os.chdir(old)

    def test_changes_include_only_real_changed_runtime_files(self):
        with tempfile.TemporaryDirectory() as temp:
            import os
            original = os.getcwd()
            os.chdir(temp)
            try:
                def git(*args):
                    subprocess.run(["git", *args], check=True, capture_output=True)
                git("init", "-q")
                git("config", "user.name", "Test")
                git("config", "user.email", "test@example.com")
                for slug in ("taxocommerce-core", "taxocommerce-vocabulary"):
                    Path(slug).mkdir()
                    (Path(slug) / (slug + ".php")).write_text("<?php\n")
                git("add", ".")
                git("commit", "-qm", "initial")
                base = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()
                (Path("taxocommerce-core") / "taxocommerce-core.php").write_text("<?php // updated\n")
                (Path("taxocommerce-core") / "tests").mkdir()
                (Path("taxocommerce-core") / "tests" / "smoke.php").write_text("<?php\n")
                git("add", ".")
                git("commit", "-qm", "core update")
                head = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()
                self.assertEqual(module.changed_files(base, head), {"taxocommerce-core": ["taxocommerce-core.php"]})
                (Path("taxocommerce-core") / "taxocommerce-core.php").unlink()
                git("add", ".")
                git("commit", "-qm", "delete core")
                deleted_head = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()
                self.assertIn("taxocommerce-core", module.changed_files(head, deleted_head))
                with self.assertRaises(ValueError):
                    module.changed_files(head, deleted_head, selected_slug="taxocommerce-core")
            finally:
                os.chdir(original)


if __name__ == "__main__":
    unittest.main()
