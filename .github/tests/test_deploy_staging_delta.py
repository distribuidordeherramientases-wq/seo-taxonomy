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
        for target, slug in (
            ("focazul", "taxocommerce-vocabulary"),
            ("production", "taxocommerce-core"),
            ("staging", "../wp-config.php"),
        ):
            with self.assertRaises(ValueError):
                module.remote_plugin_dir(target, slug)

    def test_upload_core_checks_correct_site_without_writing_other_site(self):
        ftp = mock.Mock()
        ftp.pwd.return_value = "/asociacionfocazul/wp-content/plugins/taxocommerce-core"
        with mock.patch.object(module, "existing_file_hash", return_value="present"):
            module.upload_plugin(ftp, "focazul", "taxocommerce-core", [], required=True)
        ftp.cwd.assert_called_once_with(
            "./asociacionfocazul/wp-content/plugins/taxocommerce-core/"
        )
        ftp.storbinary.assert_not_called()

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
