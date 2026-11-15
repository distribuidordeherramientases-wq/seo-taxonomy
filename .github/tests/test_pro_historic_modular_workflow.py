"""PRO release contract: Git changed files -> existing FTPS ZIP handoff -> WordPress.

No preflight inventories of activated or installed plugins, no new FTP users,
and no FTP-based guessing of the real WordPress plugin directory.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class PRODeployTests(unittest.TestCase):
    def setUp(self):
        self.workflow = (ROOT / ".github/workflows/despliege_de_main_a_pro.yml").read_text()
        self.selector = (ROOT / ".github/scripts/select_pro_release_delta.py").read_text()

    def test_git_delta_without_site_plugin_inventory(self):
        self.assertIn("select_pro_release_delta.py --all-changed", self.workflow)
        self.assertIn("installed = None", self.selector)
        self.assertIn("allowed = None", self.selector)
        for forbidden in (
            "fetch_pro_installed_modules.py", "INSTALLED_MODULES_JSON",
            "ALL_MODULES_JSON", "steps.installed", "steps.family",
            "installed-json", "allowed-json", "deploy_pro_ftps_native.py",
            "PRO_CORE_EXPECTED_VERSION", "PRO_WOO_EXPECTED_VERSION",
        ):
            self.assertNotIn(forbidden, self.workflow)

    def test_same_ftp_account_and_working_wordpress_delivery_path(self):
        for name in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD"):
            self.assertIn("secrets." + name, self.workflow)
        self.assertNotIn("secrets.STAGING_FTP_", self.workflow)
        self.assertIn('handoff=".taxocommerce-deploy/$GITHUB_RUN_ID"', self.workflow)
        self.assertIn('put "$package" -o "$handoff/$module.zip"', self.workflow)
        self.assertIn('put /tmp/taxocommerce-deploy-marker -o ".token-$token_hash"', self.workflow)
        self.assertIn('wp-json/taxocommerce/v1/deploy-active-modules', self.workflow)
        self.assertNotIn('mkdir -p "wp-content/plugins"', self.workflow)
        self.assertNotIn("ftp.cwd(PLUGINS_DIR)", self.workflow)

    def test_only_modified_files_with_wordpress_sha256_ack(self):
        self.assertIn("changed_count != '0'", self.workflow)
        self.assertIn("changed_count == '0'", self.workflow)
        self.assertIn('jq -j --arg module "$module"', self.workflow)
        self.assertIn('zip -q "$package" "${changed_files[@]}"', self.workflow)
        self.assertIn('unzip -t "$package"', self.workflow)
        self.assertIn('hashlib.sha256', self.workflow)
        self.assertIn("manifest_sha256", self.workflow)
        self.assertIn("confirmed_count != len(manifest)", self.workflow)
        self.assertIn('if [[ "$http_code" != 2* ]]', self.workflow)

    def test_manual_staging_source_without_plugin_filter(self):
        self.assertIn("ref: staging", self.workflow)
        self.assertIn('test "$GITHUB_REF_NAME" = "staging"', self.workflow)
        self.assertIn("EXPECTED_SHA: " + "$" + "{{ inputs.source_sha }}", self.workflow)
        self.assertNotIn("active_plugins", self.workflow)
        self.assertNotIn("get_plugins()", self.workflow)
        manual = self.workflow.split("  workflow_dispatch:", 1)[1].split("\npermissions:", 1)[0]
        self.assertNotIn("inputs:", manual)
        self.assertNotIn("target_plugin", manual)


if __name__ == "__main__":
    unittest.main()
