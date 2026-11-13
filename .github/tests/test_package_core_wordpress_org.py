"""Verify that Core's official ZIP uses the Plugins Team's confirmed short slug."""
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
    def test_confirmed_slug_is_short(self):
        self.assertEqual("taxocommerce-core", MODULE.OFFICIAL)
        self.assertNotEqual(MODULE.OLD_SLUG, MODULE.OFFICIAL)

    def test_distribution_preserves_source_without_rewrites(self):
        original = ROOT / MODULE.OFFICIAL
        with tempfile.TemporaryDirectory() as temp:
            package = Path(temp) / MODULE.OFFICIAL
            shutil.copytree(original, package, ignore=shutil.ignore_patterns("tests"))
            before_main = (package / (MODULE.OFFICIAL + ".php")).read_bytes()
            before_readme = (package / "readme.txt").read_bytes()
            report = MODULE.prepare(package)
            self.assertEqual(before_main, (package / (MODULE.OFFICIAL + ".php")).read_bytes())
            self.assertEqual(before_readme, (package / "readme.txt").read_bytes())
            self.assertFalse((package / (MODULE.OLD_SLUG + ".php")).exists())
            self.assertTrue((package / "languages" / "taxocommerce-core-es_ES.mo").is_file())
            for locale in MODULE.LOCALES:
                for suffix in (".po", ".mo"):
                    self.assertTrue((package / "languages" / ("taxocommerce-core-" + locale + suffix)).is_file())
                    self.assertFalse((package / "languages" / (MODULE.OLD_SLUG + "-" + locale + suffix)).exists())
            self.assertGreaterEqual(report["legacy_catalogs_excluded"], 0)
            self.assertEqual(before_main, (original / (MODULE.OFFICIAL + ".php")).read_bytes())

    def test_wrong_directory_is_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            with self.assertRaisesRegex(ValueError, "distribution directory"):
                MODULE.prepare(Path(temp) / MODULE.OLD_SLUG)

    def test_missing_language_is_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            package = Path(temp) / MODULE.OFFICIAL
            shutil.copytree(ROOT / MODULE.OFFICIAL, package, ignore=shutil.ignore_patterns("tests"))
            (package / "languages" / "taxocommerce-core-es_ES.mo").unlink()
            with self.assertRaisesRegex(ValueError, "Missing Core locale catalog"):
                MODULE.prepare(package)


if __name__ == "__main__":
    unittest.main()
