"""Offline regression tests: only changed plugin runtime files may enter PRO ZIPs."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

SOURCE = Path(__file__).resolve().parents[1] / "build_pro_delta.py"
SPEC = importlib.util.spec_from_file_location("tc_pro_delta", SOURCE)
DELTA = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DELTA)


class ProDeltaTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.previous = Path.cwd()
        os.chdir(self.temp.name)
        self.cmd("git", "init", "-q")
        self.cmd("git", "config", "user.name", "Delta Test")
        self.cmd("git", "config", "user.email", "delta-test@example.invalid")
        for slug in ("taxocommerce-core", "taxocommerce-images", "taxocommerce-database"):
            root = Path(slug)
            (root / "includes").mkdir(parents=True)
            (root / "tests").mkdir()
            (root / (slug + ".php")).write_text("<?php // main", encoding="utf-8")
            (root / "readme.txt").write_text("Stable tag: 1", encoding="utf-8")
            (root / "includes" / "live.php").write_text("<?php // old", encoding="utf-8")
            (root / "tests" / "smoke.php").write_text("<?php // dev", encoding="utf-8")
        self.cmd("git", "add", ".")
        self.cmd("git", "commit", "-qm", "initial deployment")
        self.base = self.sha()

    def tearDown(self):
        os.chdir(self.previous)
        self.temp.cleanup()

    @staticmethod
    def cmd(*args):
        subprocess.run(args, check=True, stdout=subprocess.DEVNULL)

    @staticmethod
    def sha():
        return subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()

    def commit(self):
        self.cmd("git", "add", "-A")
        self.cmd("git", "commit", "-qm", "changed files")
        return self.sha()

    def test_select_only_runtime_changes_from_verified_commit(self):
        Path("taxocommerce-core/includes/live.php").write_text("<?php // new", encoding="utf-8")
        Path("taxocommerce-core/tests/smoke.php").write_text("<?php // test change", encoding="utf-8")
        Path("taxocommerce-core/CHANGELOG.md").write_text("not distributed", encoding="utf-8")
        Path("taxocommerce-images/readme.txt").write_text("Stable tag: 2", encoding="utf-8")
        head = self.commit()
        result = DELTA.build(self.base, head,
                             ["taxocommerce-core", "taxocommerce-images", "taxocommerce-database"],
                             Path("deltas"))
        self.assertEqual([p["slug"] for p in result["installed"]],
                         ["taxocommerce-core", "taxocommerce-images"])
        self.assertEqual(result["installed"][0]["files"], ["includes/live.php"])
        self.assertEqual(result["installed"][1]["files"], ["readme.txt"])
        self.assertEqual(result["uninstalled"], [])
        with zipfile.ZipFile("deltas/taxocommerce-core.zip") as z:
            self.assertEqual(z.namelist(), ["includes/live.php"])
        self.assertFalse(Path("deltas/taxocommerce-database.zip").exists())

    def test_new_not_installed_module_has_no_live_delta(self):
        Path("taxocommerce-images/includes/live.php").write_text("<?php // updated", encoding="utf-8")
        head = self.commit()
        result = DELTA.build(self.base, head, ["taxocommerce-core"], Path("deltas"))
        self.assertEqual(result["installed"], [])
        self.assertEqual(result["uninstalled"], ["taxocommerce-images"])
        self.assertFalse(Path("deltas/taxocommerce-images.zip").exists())

    def test_deleted_live_file_blocks_production(self):
        Path("taxocommerce-core/includes/live.php").unlink()
        head = self.commit()
        with self.assertRaisesRegex(ValueError, "Runtime files removed"):
            DELTA.build(self.base, head, ["taxocommerce-core"], Path("deltas"))

    def test_deleted_development_test_does_not_block_production(self):
        Path("taxocommerce-core/tests/smoke.php").unlink()
        head = self.commit()
        result = DELTA.build(self.base, head, ["taxocommerce-core"], Path("deltas"))
        self.assertEqual(result["installed"], [])

    def test_invalid_baseline_rejected(self):
        with self.assertRaises(ValueError):
            DELTA.build("unverified", self.sha(), ["taxocommerce-core"], Path("deltas"))


if __name__ == "__main__":
    unittest.main()
