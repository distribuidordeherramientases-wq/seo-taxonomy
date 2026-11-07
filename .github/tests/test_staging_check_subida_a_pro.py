"""Regression checks for the gated STAGING -> official Plugin Check -> PRO workflow.

These tests are deliberately dependency-free and make no network calls.
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PIPELINE = ROOT / ".github/workflows/staging_check_subida_a_pro.yml"
STAGING = ROOT / ".github/workflows/deploy-staging.yml"
CHECK = ROOT / ".github/workflows/taxocommerce-plugin-check.yml"
PRO = ROOT / ".github/workflows/despliege_de_main_a_pro.yml"


class GatedPipelineContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.pipeline = PIPELINE.read_text(encoding="utf-8")
        cls.staging = STAGING.read_text(encoding="utf-8")
        cls.check = CHECK.read_text(encoding="utf-8")
        cls.pro = PRO.read_text(encoding="utf-8")

    def test_automatic_release_on_staging_merge_and_manual_fallback(self):
        self.assertIn("workflow_dispatch:", self.pipeline)
        self.assertIn("  push:", self.pipeline)
        self.assertIn("refs/heads/staging", self.pipeline)
        self.assertIn("cancel-in-progress: false", self.pipeline)
        self.assertIn("git ls-remote origin refs/heads/staging", self.pipeline)
        self.assertIn("needs.preflight.outputs.current == 'true'", self.pipeline)
        # Only the explicitly authorized Tasks direct-push path is allowed.
        # All other plugins continue using the three-phase gated pipeline.
        self.assertIn("  push:", self.staging)
        push_block = self.staging.split("  push:", 1)[1].split("  pull_request:", 1)[0]
        self.assertIn("- staging", push_block)
        self.assertIn("'taxocommerce-tasks/**'", push_block)
        self.assertNotIn("'taxocommerce-core/**'", push_block)
        self.assertNotIn("'taxocommerce-templates/**'", push_block)
        self.assertIn("github.workflow == 'Immediate deployment to STAGING'", self.staging)
        self.assertIn("TAXOCOMMERCE_TARGET_PLUGIN:", self.staging)

    def test_order_and_strict_family_check(self):
        self.assertIn("uses: ./.github/workflows/deploy-staging.yml", self.pipeline)
        self.assertIn("uses: ./.github/workflows/taxocommerce-plugin-check.yml", self.pipeline)
        self.assertIn("uses: ./.github/workflows/despliege_de_main_a_pro.yml", self.pipeline)
        self.assertIn("plugin: all", self.pipeline)
        self.assertIn("needs: staging", self.pipeline)
        self.assertIn("needs: [staging, plugin-check]", self.pipeline)
        self.assertIn("needs.plugin-check.result == 'success'", self.pipeline)
        self.assertIn("source_sha:", self.pipeline)
        self.assertIn("ignore-warnings: false", self.check)
        self.assertIn("ignore-errors: false", self.check)
        self.assertIn("strict: true", self.check)

    def test_existing_workflows_are_reusable_without_removing_manual_execution(self):
        for workflow in (self.staging, self.check, self.pro):
            self.assertIn("workflow_call:", workflow)
            self.assertIn("workflow_dispatch:", workflow)
        self.assertIn('if os.environ.get("REQUESTED_PLUGIN") == "all":', self.check)
        self.assertIn("max-parallel: 2", self.check)

    def test_pro_rejects_moving_staging_and_preserves_verification(self):
        self.assertIn('test "$GITHUB_REF_NAME" = "staging"', self.pro)
        self.assertIn('ACTUAL_SHA="$(git rev-parse HEAD)"', self.pro)
        self.assertIn('if [ "$ACTUAL_SHA" != "$EXPECTED_SHA" ]; then', self.pro)
        self.assertIn("manifest_sha256", self.pro)
        self.assertIn("verified_files", self.pro)

    def test_failure_opens_issue_and_never_proceeds_to_pro(self):
        self.assertIn("report-failure:", self.pipeline)
        self.assertIn("gh issue create", self.pipeline)
        self.assertIn("issues: write", self.pipeline)
        self.assertIn("repair required", self.pipeline)
        self.assertIn("COPILOT_AGENT_TOKEN", self.pipeline)
        self.assertIn("copilot-swe-agent[bot]", self.pipeline)
        self.assertIn("reruns automatically on push", self.pipeline)
        self.assertIn("for attempt in 1 2 3", self.staging)
        self.assertNotIn("gh pr merge", self.pipeline)
        self.assertNotIn("gh workflow run", self.pipeline)


if __name__ == "__main__":
    unittest.main()
