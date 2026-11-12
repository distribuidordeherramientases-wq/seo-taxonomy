"""Read-only selection regression tests. Git fixtures stay in temporary folders."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "select_pro_release_delta", ROOT / ".github/scripts/select_pro_release_delta.py"
)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class ProChangedFilesTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.git("init", "-q")
        self.git("config", "user.name", "TaxoCommerce Test")
        self.git("config", "user.email", "test@example.invalid")
        self.write("taxocommerce-core/taxocommerce-core.php", "<?php // v1")
        self.write("taxocommerce-core/readme.txt", "stable")
        self.write("taxocommerce-core/includes/main.php", "<?php // before")
        self.write("taxocommerce-core/CHANGELOG.md", "before")
        self.write("taxocommerce-auditor/taxocommerce-auditor.php", "<?php")
        self.write("taxocommerce-auditor/readme.txt", "stable")
        self.git("add", ".")
        self.git("commit", "-qm", "initial")
        self.base = self.git("rev-parse", "HEAD").decode().strip()

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.root)

    def write(self, path, contents):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(contents, encoding="utf-8")

    def commit(self):
        self.git("add", "-A")
        self.git("commit", "-qm", "change")
        return self.git("rev-parse", "HEAD").decode().strip()

    def select(self, head, installed=None, target=""):
        with mock.patch.object(MODULE, "git", side_effect=self.git):
            return MODULE.selected_delta(
                self.base, head,
                installed or ["taxocommerce-core", "taxocommerce-auditor"],
                ["taxocommerce-core", "taxocommerce-auditor"],
                target,
                self.root,
            )

    def test_only_modified_installable_files_are_selected(self):
        self.write("taxocommerce-core/includes/main.php", "<?php // after")
        self.write("taxocommerce-core/CHANGELOG.md", "after")
        self.write("taxocommerce-core/tests/check.php", "<?php // test only")
        self.write("taxocommerce-core/languages/taxocommerce-core.pot", "template")
        self.write("taxocommerce-core/README.md", "dev documentation")
        self.write("taxocommerce-auditor/includes/helper.php", "<?php")
        head = self.commit()
        result = self.select(head, installed=["taxocommerce-core"])
        self.assertEqual(result, {
            "taxocommerce-core": ["CHANGELOG.md", "includes/main.php"],
        })

    def test_no_changed_files_does_not_deploy_any_plugin(self):
        self.assertEqual(self.select(self.base), {})

    def test_manual_target_limits_selection_to_single_installed_plugin(self):
        self.write("taxocommerce-core/includes/main.php", "<?php // after")
        self.write("taxocommerce-auditor/includes/helper.php", "<?php")
        head = self.commit()
        self.assertEqual(self.select(head, target="taxocommerce-core"), {
            "taxocommerce-core": ["includes/main.php"],
        })
        with self.assertRaisesRegex(ValueError, "ausente o no autorizado"):
            self.select(head, target="taxocommerce-unknown")

    def test_deleted_installable_file_aborts_instead_of_leaving_drift(self):
        (self.root / "taxocommerce-core/includes/main.php").unlink()
        head = self.commit()
        with self.assertRaisesRegex(ValueError, "eliminado"):
            self.select(head)

    def test_developer_only_change_is_noop(self):
        self.write("taxocommerce-core/tests/smoke.php", "<?php")
        head = self.commit()
        self.assertEqual(self.select(head), {})

    def test_rejects_symlink_changed_file(self):
        (self.root / "taxocommerce-core/includes/main.php").unlink()
        (self.root / "taxocommerce-core/includes/main.php").symlink_to("../taxocommerce-core.php")
        head = self.commit()
        with self.assertRaisesRegex(ValueError, "symlink"):
            self.select(head)

    def test_valid_and_excluded_paths(self):
        self.assertTrue(MODULE.installable("taxocommerce-core/includes/item.php"))
        self.assertTrue(MODULE.installable("taxocommerce-core/CHANGELOG.md"))
        for path in (
            "taxocommerce-core/tests/smoke.php",
            "taxocommerce-core/uploads/backup.sql",
            "taxocommerce-core/node_modules/pkg/file.js",
            "taxocommerce-core/.hidden",
            "taxocommerce-core/assets/.secret",
            "taxocommerce-core/README.md",
            "taxocommerce-core/languages/core.pot",
            "other-plugin/includes/a.php",
        ):
            self.assertFalse(MODULE.installable(path), path)


if __name__ == "__main__":
    unittest.main()
