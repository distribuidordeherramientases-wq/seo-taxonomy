"""Offline regressions for Focazul read-only error-log classifier (#2099)."""
import sys
from pathlib import Path
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from diagnose_focazul_errors_2099 import classify_error


class ErrorSummaryTests(unittest.TestCase):
    def test_skips_nonfatal_and_secret(self):
        self.assertIsNone(classify_error("[03-Nov-2026 08:00:00 UTC] PHP Notice: hello"))
        self.assertIsNone(classify_error("DB_PASSWORD=secret"))

    def test_undefined_function(self):
        line = (
            "[03-Nov-2026 08:12:34 UTC] PHP Fatal error: Uncaught Error: "
            "Call to undefined function TaxoCommerce\\render() in "
            "/home/account/public_html/asociacionfocazul/wp-content/plugins/"
            "taxocommerce-core/includes/class-menu.php:123"
        )
        answer = classify_error(line)
        self.assertIn("Undefined function: TaxoCommerce\\render", answer)
        self.assertIn("wp-content/plugins/taxocommerce-core/includes/class-menu.php:123", answer)
        self.assertNotIn("/home/account", answer)

    def test_parse_error(self):
        line = (
            "[03-Nov-2026 08:13:05 UTC] PHP Parse error: "
            "syntax error, unexpected T_VARIABLE in "
            "/home/account/public_html/asociacionfocazul/wp-content/plugins/"
            "taxocommerce-core/taxocommerce-core.php on line 54"
        )
        answer = classify_error(line)
        self.assertIn("Parse error: T_VARIABLE", answer)
        self.assertIn("taxocommerce-core.php:54", answer)

    def test_missing_required_file_is_classified_before_uncaught_error(self):
        line = (
            "[03-Nov-2026 08:15:00 UTC] PHP Fatal error: Uncaught Error: "
            "Failed opening required '/home/account/public_html/asociacionfocazul/"
            "wp-content/plugins/taxocommerce-core/includes/class-taxocommerce-core-block-navigation.php' "
            "in /home/account/public_html/asociacionfocazul/wp-content/plugins/"
            "taxocommerce-core/includes/class-taxocommerce-core-menu.php:13"
        )
        summary = classify_error(line)
        self.assertIn("Required file missing", summary)
        self.assertNotIn("/home/account", summary)

    def test_unknown_error_does_not_echo_sensitive_values(self):
        line = (
            "[03-Nov-2026 08:14:00 UTC] PHP Fatal error: "
            "Unknown problem for secret@example.test in /tmp/private.php:5"
        )
        answer = classify_error(line)
        self.assertNotIn("secret@example", answer)
        self.assertNotIn("/tmp/private.php", answer)


if __name__ == "__main__":
    unittest.main()
