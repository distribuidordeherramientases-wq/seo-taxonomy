"""Regression tests for a consolidated 20-module WordPress Plugin Check report."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

PATH = Path(__file__).resolve().parents[1] / "taxocommerce_family_report.py"
SPEC = importlib.util.spec_from_file_location("tc_family_report", PATH)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class ReportTests(unittest.TestCase):
    def test_json_findings_and_empty_clean_scan(self):
        body = ('FILE: includes/example.php\n'
                '[{"type":"ERROR","code":"bad","message":"not escaped"},'
                '{"type":"WARNING","code":"notice","message":"needs nonce"}]\n'
                'FILE: includes/other.php\n'
                '{"type":"WARNING","code":"notice"}\n')
        entries = MODULE.scan_findings(body)
        self.assertEqual(len(entries), 3)
        self.assertEqual(entries[0]["file"], "includes/example.php")
        self.assertEqual(entries[1]["type"], "WARNING")
        self.assertEqual(MODULE.scan_findings(""), [])
        self.assertEqual(MODULE.scan_findings("Success: Checks complete. No errors found.\n"), [])
        with self.assertRaisesRegex(ValueError, "Unrecognized"):
            MODULE.scan_findings("Success: Checks complete. No errors found.\nUNEXPECTED")
        with self.assertRaisesRegex(ValueError, "Unrecognized"):
            MODULE.scan_findings("broken scanner output")
        with self.assertRaisesRegex(ValueError, "Incomplete"):
            MODULE.scan_findings('{"type":"WARNING"')

    def test_scan_records_counts_without_suppressing_warnings(self):
        with tempfile.TemporaryDirectory() as t:
            root = Path(t)
            report = root / "results.txt"
            report.write_text('{"type":"WARNING","code":"nonce"}\n'
                              '{"type":"ERROR","code":"security"}\n')
            out = root / "quality-state.json"
            result = MODULE.scan("taxocommerce-core", report, "success", out)
            self.assertEqual((result["errors"], result["warnings"]), (1, 1))
            self.assertEqual(result["codes"]["ERROR: security"], 1)
            self.assertTrue(result["complete"])
            self.assertEqual(json.loads(out.read_text()), result)
            result = MODULE.scan("taxocommerce-core", report, "failure", out)
            self.assertFalse(result["complete"])
            result = MODULE.scan("taxocommerce-core", root / "absent", "success", out)
            self.assertFalse(result["complete"])

    def test_clean_wordpress_plugin_check_message_is_complete(self):
        with tempfile.TemporaryDirectory() as t:
            root = Path(t)
            report = root / "plugin-check-results.txt"
            report.write_text("Success: Checks complete. No errors found.\n")
            output = root / "quality-state.json"
            record = MODULE.scan("taxocommerce-images", report, "success", output)
            self.assertTrue(record["complete"])
            self.assertEqual((record["errors"], record["warnings"]), (0, 0))
            self.assertEqual(MODULE.summarize(root / "reports", '[]', root / "summary.md"), [0, 0])

    def test_full_summary_and_incomplete_artifact_protection(self):
        with tempfile.TemporaryDirectory() as t:
            root = Path(t)
            slugs = ["taxocommerce-core", "taxocommerce-images"]
            for slug in slugs:
                d = root / ("wordpress-readiness-" + slug)
                d.mkdir()
                (d / "quality-state.json").write_text(json.dumps({
                    "slug": slug, "errors": 0, "warnings": 0,
                    "check_outcome": "success", "complete": True,
                }))
            output = root / "summary.md"
            self.assertEqual(MODULE.summarize(root, json.dumps(slugs), output), [0, 0])
            self.assertIn("Módulos analizados:** 2", output.read_text())
            image = root / "wordpress-readiness-taxocommerce-images" / "quality-state.json"
            image.unlink()
            with self.assertRaisesRegex(RuntimeError, "taxocommerce-images"):
                MODULE.summarize(root, json.dumps(slugs), output)
            self.assertIn("Informe ausente", output.read_text())
            with self.assertRaisesRegex(ValueError, "Invalid expected"):
                MODULE.summarize(root, '["seo-taxonomy"]', output)

    def test_warning_is_a_failed_quality_gate(self):
        with tempfile.TemporaryDirectory() as t:
            root = Path(t)
            d = root / "wordpress-readiness-taxocommerce-core"
            d.mkdir()
            (d / "quality-state.json").write_text(json.dumps({
                "slug": "taxocommerce-core", "errors": 0, "warnings": 2,
                "check_outcome": "success", "complete": True,
            }))
            with self.assertRaisesRegex(RuntimeError, "taxocommerce-core"):
                MODULE.summarize(root, '["taxocommerce-core"]', root / "summary.md")
            self.assertIn("Con incidencias", (root / "summary.md").read_text())


if __name__ == "__main__":
    unittest.main()
