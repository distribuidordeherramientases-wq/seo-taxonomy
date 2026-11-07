"""Offline regression tests; no WordPress, network or FTPS side effects."""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "audit_core_dual_sites.py"
sys.path.insert(0, str(SCRIPT.parent))
spec = importlib.util.spec_from_file_location("audit_core_dual_sites", SCRIPT)
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class FakeFTP:
    data = {}

    def __init__(self, **kwargs):
        self.cwd_path = "/home"
        self.writes = []

    def connect(self, *args):
        return None

    def login(self, *args):
        return None

    def prot_p(self):
        return None

    def pwd(self):
        return self.cwd_path

    def cwd(self, path):
        if path.startswith("/"):
            self.cwd_path = path.rstrip("/")
        elif path.startswith("./"):
            self.cwd_path = "/home/" + path[2:].rstrip("/")
        else:
            self.cwd_path += "/" + path

    def retrbinary(self, cmd, callback):
        assert cmd.startswith("RETR ")
        key = self.cwd_path + "/" + cmd[5:]
        if key not in self.data:
            raise audit.ftplib.error_perm("550 Not found")
        callback(self.data[key])

    def storbinary(self, *args):
        self.writes.append(args)
        raise AssertionError("Audit must never upload")

    def quit(self):
        pass

    def close(self):
        pass


class DualAuditTests(unittest.TestCase):
    def setUp(self):
        self.report = {"commit": "test", "checks": []}

    def test_provider_head_does_not_claim_live_api_success(self):
        html = ('<head><script src="https://www.googletagmanager.com/gtag/js?id=G-TEST123"></script>'
                '<meta name="google-site-verification" content="token">'
                '<meta content="pin" name="p:domain_verify"></head><body>ready</body>')
        audit.public_provider_head(self.report, "staging", html)
        states = {row["name"]: row["status"] for row in self.report["checks"]}
        self.assertEqual(states["Google Analytics: tag único"], "PASS")
        self.assertEqual(states["Google Search Console: meta de cabecera"], "PASS")
        self.assertEqual(states["Pinterest Merchant: meta de cabecera"], "PASS")
        self.assertEqual(states["Microsoft Bing: meta de cabecera"], "NOT_CHECKED")

        self.report["checks"].clear()
        audit.public_provider_head(self.report, "staging", html + html)
        states = {row["name"]: row["status"] for row in self.report["checks"]}
        self.assertEqual(states["Google Analytics: tag único"], "PASS")  # Only first head is inspected.

        self.report["checks"].clear()
        doubled = html.replace("</head>", '<script src="https://www.googletagmanager.com/gtag/js?id=G-TEST123"></script></head>')
        audit.public_provider_head(self.report, "staging", doubled)
        self.assertEqual(self.report["checks"][0]["status"], "FAIL")

        self.report["checks"].clear()
        audit.public_provider_head(self.report, "staging", "<head></head>")
        self.assertTrue(all(row["status"] == "NOT_CHECKED" for row in self.report["checks"]))

        self.report["checks"].clear()
        audit.public_provider_head(self.report, "focazul", html)
        self.assertEqual(self.report["checks"], [])

    def test_html_parser_excludes_non_navigation_links(self):
        p = audit.MenuHTML()
        p.feed('<a href="/outside">No</a><nav class="header"><a href="/yes">Yes</a>'
               '<a href="/other"><span>Other</span></a></nav>')
        self.assertEqual(p.nav_count, 1)
        self.assertEqual(len(p.nav_links), 2)
        self.assertEqual(p.nav_links[0]["text"], "Yes")
        self.assertEqual(p.nav_links[1]["text"], "Other")
        self.assertEqual(p.classes["header"], 1)

    def test_static_code_scan_marks_client_leaks_only_as_warning(self):
        with tempfile.TemporaryDirectory() as directory:
            original = os.getcwd()
            try:
                os.chdir(directory)
                Path("taxocommerce-core").mkdir()
                Path("taxocommerce-core/module.php").write_text("<?php // makita\n", encoding="utf-8")
                Path("taxocommerce-core/clean.js").write_text("var a = 1;", encoding="utf-8")
                Path("taxocommerce-core/CHANGELOG.md").write_text("Focazul\n", encoding="utf-8")
                audit.local_source_audit(self.report, ["module.php", "clean.js", "CHANGELOG.md"])
            finally:
                os.chdir(original)
        self.assertEqual(self.report["checks"][0]["status"], "WARNING")
        self.assertIn("module.php:1", self.report["checks"][0]["detail"])
        self.assertNotIn("CHANGELOG", self.report["checks"][0]["detail"])

    def test_legacy_country_fallback_is_not_mistaken_for_global(self):
        with tempfile.TemporaryDirectory() as directory:
            original = os.getcwd()
            try:
                os.chdir(directory)
                path = Path("taxocommerce-core/includes/class-taxocommerce-core-country.php")
                path.parent.mkdir(parents=True)
                path.write_text("<?php function country() { return 'ES'; }", encoding="utf-8")
                audit.global_country_audit(self.report)
                self.assertEqual(self.report["checks"][-1]["status"], "WARNING")
                self.assertIn("1 retornos ES", self.report["checks"][-1]["detail"])
                path.write_text("<?php function country() { return ''; }", encoding="utf-8")
                audit.global_country_audit(self.report)
                self.assertEqual(self.report["checks"][-1]["status"], "PASS")
            finally:
                os.chdir(original)

    def test_http_server_errors_are_fail_not_unchecked(self):
        base = audit.SITES["staging"]["url"]
        exc = audit.urllib.error.HTTPError(base, 500, "error", {}, None)
        with patch.object(audit, "read_html", side_effect=exc):
            audit.public_navigation(self.report, "staging", base)
        self.assertEqual(self.report["checks"][-1]["status"], "FAIL")

    def test_ftps_hashes_both_sites_against_single_checkout_and_never_writes(self):
        with tempfile.TemporaryDirectory() as directory:
            original = os.getcwd()
            try:
                os.chdir(directory)
                Path("taxocommerce-core").mkdir()
                Path("taxocommerce-core/taxocommerce-core.php").write_bytes(b"<?php // generic")
                files = ["taxocommerce-core.php"]
                FakeFTP.data = {
                    "/home/staging.distribuidordeherramientas.es/wp-content/plugins/taxocommerce-core/taxocommerce-core.php": b"<?php // generic",
                    "/home/asociacionfocazul/wp-content/plugins/taxocommerce-core/taxocommerce-core.php": b"<?php // generic",
                }
                env = {"STAGING_FTP_SERVER": "test", "STAGING_FTP_USERNAME": "test",
                       "STAGING_FTP_PASSWORD": "secret"}
                with patch.dict(os.environ, env), patch.object(audit.ftplib, "FTP_TLS", FakeFTP):
                    audit.ftps_parity(self.report, files)
            finally:
                os.chdir(original)
        self.assertEqual([x["status"] for x in self.report["checks"]], ["PASS", "PASS"])
        self.assertTrue(all("1/1" in x["detail"] for x in self.report["checks"]))

    def test_ftps_fails_a_modified_focazul_file_only(self):
        with tempfile.TemporaryDirectory() as directory:
            original = os.getcwd()
            try:
                os.chdir(directory)
                Path("taxocommerce-core").mkdir()
                Path("taxocommerce-core/taxocommerce-core.php").write_bytes(b"source")
                FakeFTP.data = {
                    "/home/staging.distribuidordeherramientas.es/wp-content/plugins/taxocommerce-core/taxocommerce-core.php": b"source",
                    "/home/asociacionfocazul/wp-content/plugins/taxocommerce-core/taxocommerce-core.php": b"customer-specific-edit",
                }
                env = {"STAGING_FTP_SERVER": "test", "STAGING_FTP_USERNAME": "test",
                       "STAGING_FTP_PASSWORD": "secret"}
                with patch.dict(os.environ, env), patch.object(audit.ftplib, "FTP_TLS", FakeFTP):
                    audit.ftps_parity(self.report, ["taxocommerce-core.php"])
            finally:
                os.chdir(original)
        self.assertEqual([x["status"] for x in self.report["checks"]], ["PASS", "FAIL"])
        self.assertIn("diferentes=['taxocommerce-core.php']", self.report["checks"][1]["detail"])

    def test_public_navigation_detects_cross_installation_link(self):
        html = ('<html><nav><a href="https://staging.distribuidordeherramientas.es/">STAGING</a>'
                '<a href="/asociacionfocazul/quienes-somos/">Nosotros</a></nav></html>')
        base = audit.SITES["focazul"]["url"]
        with patch.object(audit, "read_html", return_value=(base, html, 200)):
            audit.public_navigation(self.report, "focazul", base)
        self.assertEqual([x["status"] for x in self.report["checks"]], ["PASS", "WARNING"])
        self.assertIn("referencias cruzadas=1", self.report["checks"][1]["detail"])

    def test_focazul_root_without_trailing_slash_is_not_a_foreign_link(self):
        base = audit.SITES["focazul"]["url"]
        html = '<nav><a href="/asociacionfocazul">Inicio</a><a href="/asociacionfocazul/quienes-somos/">Quiénes somos</a></nav>'
        with patch.object(audit, "read_html", return_value=(base, html, 200)):
            audit.public_navigation(self.report, "focazul", base)
        self.assertEqual(self.report["checks"][-1]["status"], "PASS")

    def test_public_php_warnings_are_a_real_failure(self):
        base = audit.SITES["staging"]["url"]
        with patch.object(audit, "read_html", return_value=(base, '<nav><a href="/">Home</a></nav>Warning: errors wp-includes/test.php', 200)):
            audit.public_navigation(self.report, "staging", base)
        self.assertEqual(self.report["checks"][0]["status"], "FAIL")

    def test_admin_audit_must_not_claim_ui_pass_without_test_login(self):
        cfg = audit.SITES["staging"]
        with patch.dict(os.environ, {cfg["user_env"]: "", cfg["password_env"]: ""}):
            audit.authenticated_ui(self.report, "staging", cfg)
        self.assertEqual(self.report["checks"][0]["status"], "NOT_CHECKED")

    def test_admin_ui_real_html_contract_with_login_mock(self):
        cfg = audit.SITES["staging"]
        doc = '<div class="tc-menu-load tc-menu-editor tc-menu-structure tc-menu-box tc-menu-controls">Menu</div>'
        with patch.dict(os.environ, {cfg["user_env"]: "test", cfg["password_env"]: "fake"}):
            with patch.object(audit, "read_html", side_effect=[("", "", 200), (cfg["url"], doc, 200)]):
                audit.authenticated_ui(self.report, "staging", cfg)
        self.assertEqual(self.report["checks"][0]["status"], "PASS")
        self.assertIn("2 columnas", self.report["checks"][0]["detail"])

    def test_admin_ui_wrong_markup_reports_failure(self):
        cfg = audit.SITES["focazul"]
        doc = '<section class="tc-menu-load"><div class="tc-menu-editor"></div></section>'
        with patch.dict(os.environ, {cfg["user_env"]: "test", cfg["password_env"]: "fake"}):
            with patch.object(audit, "read_html", side_effect=[("", "", 200), (cfg["url"], doc, 200)]):
                audit.authenticated_ui(self.report, "focazul", cfg)
        self.assertEqual(self.report["checks"][0]["status"], "FAIL")

    def test_report_retains_not_checked_and_failed_outcome(self):
        with tempfile.TemporaryDirectory() as tmp:
            audit.check(self.report, "focazul", "Admin", "NOT_CHECKED", "Requires login")
            audit.check(self.report, "staging", "Files", "FAIL", "Different")
            code = audit.save_report(self.report, str(Path(tmp) / "report"))
            self.assertEqual(code, 1)
            doc = json.loads((Path(tmp) / "report.json").read_text(encoding="utf-8"))
            self.assertEqual(doc["totals"]["NOT_CHECKED"], 1)
            self.assertEqual(doc["totals"]["FAIL"], 1)
            self.assertIn("No se comprueban bases de datos", (Path(tmp) / "report.md").read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
