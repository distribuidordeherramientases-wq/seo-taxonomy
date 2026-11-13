"""Regression for the WordPress.org-assigned TaxoCommerce Core slug."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / ".github/scripts/package_core_wordpress_org.py"
SPEC = importlib.util.spec_from_file_location("package_core_wporg", MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class CoreDistributionTests(unittest.TestCase):
    def test_slug_is_final_short_wordpress_org_identifier(self):
        self.assertEqual("taxocommerce-core", MODULE.SLUG)

    def test_public_package_preserves_main_file_and_gettext_domain(self):
        original = ROOT / MODULE.SLUG
        with tempfile.TemporaryDirectory() as tmp:
            package = Path(tmp) / MODULE.SLUG
            shutil.copytree(original, package, ignore=shutil.ignore_patterns("tests"))
            # The source repository still has legacy language files for
            # historical build compatibility. Never ship them in the ZIP.
            for legacy in (package / "languages").glob(MODULE.OLD_SLUG + "-*"):
                legacy.unlink()
            expected = (original / (MODULE.SLUG + ".php")).read_bytes()
            outcome = MODULE.prepare(package)
            self.assertGreater(outcome["php_files"], 15)
            self.assertEqual(outcome["locales"], 5)
            self.assertEqual(expected, (package / (MODULE.SLUG + ".php")).read_bytes())
            self.assertFalse((package / (MODULE.OLD_SLUG + ".php")).exists())
            for locale in MODULE.LOCALES:
                for extension in (".po", ".mo"):
                    self.assertTrue((package / "languages" / (MODULE.SLUG + "-" + locale + extension)).is_file())

    def test_wrong_slug_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(ValueError, "taxocommerce-core"):
                MODULE.prepare(Path(tmp) / MODULE.OLD_SLUG)

    def test_obsolete_translation_is_not_permitted(self):
        original = ROOT / MODULE.SLUG
        with tempfile.TemporaryDirectory() as tmp:
            package = Path(tmp) / MODULE.SLUG
            shutil.copytree(original, package, ignore=shutil.ignore_patterns("tests"))
            with self.assertRaisesRegex(ValueError, "Obsolete long-slug"):
                MODULE.prepare(package)

    def test_workflow_uses_single_official_slug(self):
        workflow = (ROOT / ".github/workflows/taxocommerce-plugin-check.yml").read_text(encoding="utf-8")
        self.assertIn('TARGET_SLUG="$PLUGIN"', workflow)
        self.assertNotIn('TARGET_SLUG="' + MODULE.OLD_SLUG + '"', workflow)
        self.assertIn("package_core_wordpress_org.py", workflow)


if __name__ == "__main__":
    unittest.main()
