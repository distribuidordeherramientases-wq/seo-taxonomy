"""Keep the Insights head tag manager wired after independent feature merges."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MODULE = ROOT / "taxocommerce-google-bing"
MAIN = MODULE / "taxocommerce-google-bing.php"
HEAD = MODULE / "includes/class-taxocommerce-insights-head-tags.php"
README = MODULE / "readme.txt"


class HeadTagsBootstrapRegression(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.main = MAIN.read_text(encoding="utf-8")

    def test_head_tags_module_is_loaded_and_registered(self):
        self.assertTrue(HEAD.is_file())
        self.assertIn(
            "require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-insights-head-tags.php';",
            self.main,
        )
        self.assertIn("TaxoCommerce_Insights_Head_Tags::register();", self.main)

    def test_head_tags_tab_is_routed_and_rendered(self):
        self.assertIn("'head_tags', 'user_guide'", self.main)
        self.assertIn("'head_tags'        =>", self.main)
        self.assertIn("if ( 'head_tags' === $tab )", self.main)
        self.assertIn("TaxoCommerce_Insights_Head_Tags::render();", self.main)

    def test_indexnow_and_head_tags_coexist(self):
        self.assertIn("TaxoCommerce_Insights_IndexNow::register();", self.main)
        self.assertIn("TaxoCommerce_Insights_Head_Tags::register();", self.main)

    def test_version_header_and_readme_match(self):
        self.assertIn("* Version: 1.1.18", self.main)
        self.assertIn("Stable tag: 1.1.18", README.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
