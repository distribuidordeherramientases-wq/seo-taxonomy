"""Regression tests for independent STAGING -> PRO FTPS deployment.

Source inspection only; never connects to WordPress or FTP.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/despliege_de_main_a_pro.yml"
UPLOADER = ROOT / ".github/scripts/deploy_pro_ftps_native.py"
PREFLIGHT = ROOT / ".github/scripts/pro_deploy_preflight.py"
SELECTOR = ROOT / ".github/scripts/select_pro_release_delta.py"
MODULE = re.compile(r"^taxocommerce-[a-z0-9-]+$")


class HistoricModularDeployTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.uploader = UPLOADER.read_text(encoding="utf-8")
        cls.preflight = PREFLIGHT.read_text(encoding="utf-8")
        cls.selector = SELECTOR.read_text(encoding="utf-8")

    def test_manual_staging_source(self):
        text = self.workflow
        self.assertIn("workflow_dispatch:", text)
        self.assertIn("ref: staging", text)
        self.assertIn('test "$GITHUB_REF_NAME" = "staging"', text)
        self.assertIn("Reject untested source revisions before any production write", text)
        self.assertIn("concurrency:", text)
        self.assertIn("group: taxocommerce-production-deploy", text)

    def test_production_uses_dedicated_ftp_secrets_only(self):
        for name in ("PRO_FTPS_HOST", "PRO_FTPS_USER", "PRO_FTPS_PASSWORD"):
            self.assertIn(name, self.workflow)
        for old in ("secrets.FTP_SERVER", "secrets.FTP_USERNAME",
                    "secrets.FTP_PASSWORD", "secrets.TC_FTP_HOST"):
            self.assertNotIn(old, self.workflow)

    def test_manual_release_has_no_plugin_input_and_targets_staging(self):
        text = self.workflow
        manual = text.split("  workflow_dispatch:", 1)[1].split("\npermissions:", 1)[0]
        self.assertNotIn("target_plugin", manual)
        self.assertNotIn("inputs:", manual)
        self.assertIn("  workflow_call:", text)
        # The automatic gated workflow still supports its existing scoped input.
        self.assertIn("      target_plugin:", text.split("  workflow_dispatch:", 1)[0])
        # Manual runs have no target_plugin input, so the selection is always all.
        self.assertIn("TARGET_PLUGIN: ${{ inputs.target_plugin || '' }}", text)
        self.assertIn('test "$GITHUB_REF_NAME" = "staging"', text)
        self.assertIn("ref: staging", text)

    def test_no_legacy_rest_service_or_monolith_sync(self):
        text = self.workflow
        for forbidden in (
            "seo-taxonomy/", "seo_taxocommerce_", "/deploy-active-modules",
            "fetch_pro_installed_modules.py >>", "wp-json/taxocommerce/v1/",
            'handoff=".taxocommerce-deploy/', "put \"$package\"",
            "FTP-Deploy-Action", "local-dir: ./", "server-dir: ./",
            "reconcile_taxocommerce_pro.py", "wp-content/plugins/seo-taxonomy",
        ):
            self.assertNotIn(forbidden, text)
        self.assertIn("deploy_pro_ftps_native.py inventory", text)
        self.assertIn("deploy_pro_ftps_native.py apply", text)

    def test_verified_root_and_installed_inventory_before_writes(self):
        uploader = self.uploader
        self.assertIn("find_verified_root(ftps, expected)", uploader)
        self.assertIn("ftp.mlsd()", uploader)
        self.assertIn("read_plugin_version(ftp, name +", uploader)
        self.assertIn("load_delta(sys.argv[2], installed)", uploader)
        self.assertIn("if slug not in installed", uploader)
        self.assertIn("ftplib.FTP_TLS(context=ssl.create_default_context()", uploader)
        self.assertIn("ftps.prot_p()", uploader)
        self.assertIn("PRO_CORE_EXPECTED_VERSION", self.workflow)
        self.assertIn("PRO_WOO_EXPECTED_VERSION", self.workflow)

    def test_changed_files_only_checksums_and_rollback(self):
        uploader = self.uploader
        for contract in (
            "target_is_safe(slug, name)", "hashlib.sha256",
            "ftp.storbinary", "ftp.rename", "journal.append((path, backup))",
            "for path, backup in reversed(journal)",
            "Remote plugin file digest changed during final check",
        ):
            self.assertIn(contract, uploader)
        self.assertIn("selected_count != '0'", self.workflow)
        self.assertIn("taxocommerce-pro-changed.json", self.workflow)
        self.assertIn("selected_delta", self.selector)
        self.assertIn("last_successful_source", self.selector)
        self.assertNotIn("actions/jobs/", self.selector)
        for excluded in ("tests", "uploads", "node_modules", ".bak", ".log", ".pot"):
            self.assertIn(excluded, self.selector)

    def test_no_changes_succeeds_without_ftp(self):
        self.assertIn("pro_deploy_preflight.py", self.workflow)
        self.assertIn("steps.preflight.outputs.has_changes == 'false'", self.workflow)
        self.assertIn("Sin archivos modificados instalados para publicar", self.workflow)
        self.assertIn("release.installable(name)", self.preflight)
        self.assertIn("release.last_successful_source(repo, token)", self.preflight)
        self.assertIn("steps.preflight.outputs.has_changes == 'true'", self.workflow)

    def test_all_source_modules_have_standard_headers(self):
        modules = sorted(d for d in ROOT.glob("taxocommerce-*")
                         if d.is_dir() and MODULE.fullmatch(d.name))
        self.assertGreaterEqual(len(modules), 19)
        errors = []
        for module in modules:
            main = module / (module.name + ".php")
            readme = module / "readme.txt"
            changelog = module / "CHANGELOG.md"
            if not (main.is_file() and readme.is_file() and changelog.is_file()):
                errors.append(module.name + ": missing plugin files")
                continue
            version = re.search(r"(?mi)^\s*\*\s*Version:\s*(\S+)", main.read_text(errors="replace"))
            stable = re.search(r"(?mi)^Stable tag:\s*(\S+)", readme.read_text(errors="replace"))
            if not version or not stable:
                errors.append(module.name + ": missing version or stable tag")
        self.assertEqual([], errors)


if __name__ == "__main__":
    unittest.main()
