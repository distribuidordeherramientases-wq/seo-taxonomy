"""Offline safety/functional regressions for manual PRO family reconciliation."""
import ftplib
import importlib.util
import os
from pathlib import Path
import posixpath
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("deploy_pro_all", Path(__file__).resolve().parents[1] / "scripts" / "deploy_pro_all.py")
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)


class FakeFTP:
    def __init__(self):
        self.dirs = {"/", "/site", "/site/wp-content", "/site/wp-content/plugins",
                     "/site/wp-content/plugins/taxocommerce-core",
                     "/site/wp-content/plugins/taxocommerce-foo"}
        self.files = {
            "/site/wp-content/plugins/taxocommerce-core/taxocommerce-core.php":
                b"<?php /* Plugin Name: TaxoCommerce Core */",
            "/site/wp-content/plugins/taxocommerce-foo/taxocommerce-foo.php":
                b"<?php /* Plugin Name: TaxoCommerce Foo */",
        }
        self.here = "/site/wp-content/plugins"
        self.transfers = []
        self.tamper = False
        self.blocked_dirs = set()

    def pwd(self):
        return self.here

    def absolute(self, name):
        return posixpath.normpath(posixpath.join(self.here, name))

    def cwd(self, path):
        dest = self.absolute(path)
        if dest in self.blocked_dirs:
            raise ftplib.error_perm("550 Permission denied")
        if dest not in self.dirs:
            raise ftplib.error_perm("550 directory not found")
        self.here = dest

    def nlst(self):
        prefix = self.here.rstrip("/") + "/"
        children = set()
        for item in self.dirs | set(self.files):
            if item.startswith(prefix):
                child = item[len(prefix):].split("/", 1)[0]
                if child:
                    children.add(child)
        return sorted(children)

    def mkd(self, path):
        dest = self.absolute(path)
        if posixpath.dirname(dest) not in self.dirs:
            raise ftplib.error_perm("550 parent not found")
        self.dirs.add(dest)

    def retrbinary(self, cmd, callback):
        path = self.absolute(cmd.removeprefix("RETR "))
        if path not in self.files:
            raise ftplib.error_perm("550 file not found")
        callback(self.files[path])

    def storbinary(self, cmd, stream):
        path = self.absolute(cmd.removeprefix("STOR "))
        self.files[path] = stream.read()
        self.transfers.append(path)

    def rename(self, src, dst):
        src, dst = self.absolute(src), self.absolute(dst)
        self.files[dst] = b"tampered" if self.tamper else self.files[src]
        del self.files[src]

    def delete(self, filename):
        path = self.absolute(filename)
        if path not in self.files:
            raise ftplib.error_perm("550 not found")
        del self.files[path]


class DeployProTests(unittest.TestCase):
    def test_excludes_only_non_release_files(self):
        rejected = ["taxocommerce-core/tests/test.php", "taxocommerce-core/.env", "taxocommerce-core/foo/.gitignore",
                    "taxocommerce-core/languages/taxocommerce-core.pot", "taxocommerce-core/CHANGELOG.md",
                    "taxocommerce-core/uploads/test.jpg", "taxocommerce-core/assets/test.pyc", "seo-taxonomy/index.php",
                    "taxocommerce-core/../file.php", "taxocommerce-core/src/.hidden/file.php"]
        for path in rejected:
            self.assertIsNone(mod.distributable(path), path)
        for path in ("taxocommerce-core/taxocommerce-core.php", "taxocommerce-core/readme.txt",
                     "taxocommerce-core/changelog.txt", "taxocommerce-core/languages/taxocommerce-core-es_ES.mo"):
            self.assertIsNotNone(mod.distributable(path), path)

    def test_inventory_all_slugs_without_installed_filter(self):
        with tempfile.TemporaryDirectory() as temp:
            cwd = os.getcwd()
            try:
                os.chdir(temp)
                for slug in ("taxocommerce-core", "taxocommerce-foo"):
                    Path(slug).mkdir()
                    (Path(slug) / (slug + ".php")).write_text("<?php")
                    (Path(slug) / "readme.txt").write_text("release")
                    (Path(slug) / "tests").mkdir()
                    (Path(slug) / "tests" / "test.php").write_text("<?php")
                files = b"\0".join(p.encode() for p in [
                    "taxocommerce-core/taxocommerce-core.php", "taxocommerce-core/readme.txt", "taxocommerce-core/tests/test.php",
                    "taxocommerce-foo/taxocommerce-foo.php", "taxocommerce-foo/readme.txt"]) + b"\0"
                with patch.object(mod.subprocess, "check_output", return_value=files):
                    got = mod.release_inventory()
                self.assertEqual(set(got), {"taxocommerce-core", "taxocommerce-foo"})
                self.assertNotIn("tests/test.php", got["taxocommerce-core"])
                self.assertEqual(got["taxocommerce-core"][-1], "taxocommerce-core.php")
            finally:
                os.chdir(cwd)

    def test_ftp_login_guard(self):
        for login in ("/site", "/site/wp-content/plugins", "/site/wp-content/plugins/taxocommerce-core"):
            ftp = FakeFTP()
            ftp.here = login
            root, visible = mod.enter_wp_plugins_root(ftp)
            self.assertEqual(root, "/site/wp-content/plugins")
            self.assertEqual(ftp.here, root)
        bad = FakeFTP()
        bad.files.clear()
        with self.assertRaisesRegex(RuntimeError, "FTPS cannot locate"):
            mod.enter_wp_plugins_root(bad)
        self.assertFalse(bad.transfers)

    def test_reconcile_all_creates_missing_plugin_without_deleting(self):
        ftp = FakeFTP()
        root, visible = mod.enter_wp_plugins_root(ftp)
        with tempfile.TemporaryDirectory() as temp:
            cwd = os.getcwd()
            try:
                os.chdir(temp)
                slug = "taxocommerce-core"
                (Path(slug) / "languages").mkdir(parents=True)
                (Path(slug) / "languages" / "core-es.mo").write_bytes(b"MO")
                (Path(slug) / "readme.txt").write_text("release")
                (Path(slug) / (slug + ".php")).write_text("<?php /* Version: 1.0 */")
                files = ["languages/core-es.mo", "readme.txt", slug + ".php"]
                report = []
                mod.reconcile_one(ftp, root, slug, files, report)
                self.assertEqual(report[0]["updated"], 3)
                self.assertEqual(len(ftp.transfers), 3)
                self.assertFalse(any(".taxocommerce-upload-" in path for path in ftp.files))
                self.assertFalse(any("seo-taxonomy" in name for name in ftp.files))
                self.assertTrue(all(name.startswith("/site/wp-content/plugins/taxocommerce-core/")
                                    for name in ftp.transfers))
                mod.reconcile_one(ftp, root, slug, files, report)
                self.assertEqual(report[1]["unchanged"], 3)
                self.assertEqual(len(ftp.transfers), 3)
            finally:
                os.chdir(cwd)

    def test_rejects_extra_repository_files_and_unsafe_paths(self):
        ftp = FakeFTP()
        root, visible = mod.enter_wp_plugins_root(ftp)
        with self.assertRaisesRegex(ValueError, "non-release"):
            mod.reconcile_one(ftp, root, "taxocommerce-core", [".git/config"], [])
        with self.assertRaisesRegex(ValueError, "non-release"):
            mod.reconcile_one(ftp, root, "taxocommerce-core", ["../seo-taxonomy.php"], [])
        self.assertFalse(ftp.transfers)

    def test_missing_plugin_is_skipped_without_installing_it(self):
        ftp = FakeFTP()
        root, visible = mod.enter_wp_plugins_root(ftp)
        present, why = mod.probe_installed_plugin(ftp, root, "taxocommerce-core", visible)
        self.assertTrue(present)
        self.assertIsNone(why)
        present, why = mod.probe_installed_plugin(ftp, root, "taxocommerce-auditor", visible)
        self.assertFalse(present)
        self.assertEqual(why, "plugin_not_present_in_selected_root")
        self.assertFalse(ftp.transfers)
        self.assertNotIn("/site/wp-content/plugins/taxocommerce-auditor", ftp.dirs)

    def test_missing_main_file_is_skipped(self):
        ftp = FakeFTP()
        ftp.files.pop("/site/wp-content/plugins/taxocommerce-foo/taxocommerce-foo.php")
        root, visible = mod.enter_wp_plugins_root(ftp)
        with self.assertRaisesRegex(RuntimeError, "cannot read existing plugin entrypoint"):
            mod.probe_installed_plugin(ftp, root, "taxocommerce-foo", visible)
        self.assertFalse(ftp.transfers)

    def test_full_workflow_updates_existing_plugins_skips_absent_auditor(self):
        ftp = FakeFTP()
        ftp.connect = lambda *args: None
        ftp.login = lambda *args: None
        ftp.prot_p = lambda: None
        ftp.quit = lambda: None
        ftp.close = lambda: None
        commit = "a" * 40
        names = ("taxocommerce-core", "taxocommerce-auditor", "taxocommerce-foo")
        tracked = b"\0".join(
            (slug + "/" + filename).encode()
            for slug in names for filename in ("readme.txt", slug + ".php")
        ) + b"\0"

        def git_response(args, **kwargs):
            return commit if args[1] == "rev-parse" else tracked

        with tempfile.TemporaryDirectory() as temp:
            cwd = os.getcwd()
            try:
                os.chdir(temp)
                for slug in names:
                    root = Path(slug)
                    root.mkdir()
                    (root / (slug + ".php")).write_text("<?php /* Plugin Name: " + slug + " */")
                    (root / "readme.txt").write_text("Release file")
                    (root / "tests").mkdir()
                    (root / "tests" / "test.php").write_text("Development-only")
                env = {
                    "GITHUB_REF_NAME": "staging",
                    "GITHUB_SHA": commit,
                    "FTP_SERVER": "example.test",
                    "FTP_USERNAME": "example",
                    "FTP_PASSWORD": "fake",
                    "GITHUB_STEP_SUMMARY": str(Path(temp) / "summary.txt"),
                }
                with (patch.dict(os.environ, env, clear=True),
                      patch.object(mod.subprocess, "check_output", side_effect=git_response),
                      patch.object(mod.ftplib, "FTP_TLS", return_value=ftp)):
                    mod.main()
                report = __import__("json").loads(Path("pro-release-manifest.json").read_text())
                self.assertEqual(report["source_modules"], 3)
                self.assertEqual(report["modules_verified"], 2)
                self.assertEqual(report["modules_skipped"], 1)
                self.assertEqual(report["release_status"], "updated_available_plugins_with_skips")
                self.assertEqual(report["skipped_plugins"][0]["slug"], "taxocommerce-auditor")
                self.assertEqual({row["slug"] for row in report["plugins"]},
                                 {"taxocommerce-core", "taxocommerce-foo"})
                self.assertNotIn("/site/wp-content/plugins/taxocommerce-auditor", ftp.dirs)
                self.assertTrue(all(path.startswith((
                    "/site/wp-content/plugins/taxocommerce-core/",
                    "/site/wp-content/plugins/taxocommerce-foo/"
                )) for path in ftp.transfers))
                self.assertFalse(any("tests" in path or ".git" in path for path in ftp.transfers))
                summary = Path("summary.txt").read_text()
                self.assertIn("SKIPPED `taxocommerce-auditor`", summary)
                self.assertIn("Modules not available on PRO: 1", summary)
            finally:
                os.chdir(cwd)

    def test_choose_correct_wepanel_site_instead_of_three_plugin_root(self):
        """The user's PRO site shows 19 modules; do not pick a 3-module site."""
        ftp = FakeFTP()
        correct = "/site/hosting160568eu-279554.webempresa.site/wp-content/plugins"
        ftp.dirs.update({
            "/site/hosting160568eu-279554.webempresa.site",
            "/site/hosting160568eu-279554.webempresa.site/wp-content",
            correct,
        })
        expected = {"taxocommerce-core", "taxocommerce-images", "taxocommerce-import-export"}
        expected.update("taxocommerce-module-" + str(i) for i in range(1, 18))
        for slug in expected - {"taxocommerce-module-17"}:
            ftp.dirs.add(correct + "/" + slug)
            ftp.files[correct + "/" + slug + "/" + slug + ".php"] = (
                b"<?php /* Plugin Name: TaxoCommerce Core */" if slug == "taxocommerce-core"
                else b"<?php /* Plugin Name: Example */"
            )
        ftp.here = "/site"
        selected, names = mod.enter_wp_plugins_root(ftp, expected)
        self.assertEqual(selected, correct)
        self.assertEqual(len(expected & names), 19)
        ready, skipped = mod.preflight_plugins(ftp, selected, names, sorted(expected))
        self.assertEqual(len(ready), 19)
        self.assertEqual(len(skipped), 1)
        self.assertEqual(skipped[0]["slug"], "taxocommerce-module-17")
        self.assertFalse(ftp.transfers)

    def test_only_three_of_twenty_blocks_before_upload(self):
        ftp = FakeFTP()
        expected = {"taxocommerce-core", "taxocommerce-images", "taxocommerce-import-export"}
        expected.update("taxocommerce-module-" + str(i) for i in range(1, 18))
        with self.assertRaisesRegex(RuntimeError, "root mismatch or insufficient FTP visibility"):
            mod.enter_wp_plugins_root(ftp, expected)
        self.assertFalse(ftp.transfers)

    def test_listed_but_inaccessible_ftp_plugin_blocks_before_upload(self):
        ftp = FakeFTP()
        root, visible = mod.enter_wp_plugins_root(ftp)
        ftp.blocked_dirs.add(root + "/taxocommerce-foo")
        with self.assertRaisesRegex(RuntimeError, "is listed.*CWD returned 550 Permission denied"):
            mod.preflight_plugins(ftp, root, visible, ["taxocommerce-core", "taxocommerce-foo"])
        self.assertFalse(ftp.transfers)

    def test_explicit_plugins_path_is_restricted(self):
        ftp = FakeFTP()
        with patch.dict(os.environ, {"PRO_FTP_PLUGINS_DIR": "/site/wp-content/plugins"}):
            self.assertEqual(mod.enter_wp_plugins_root(ftp)[0], "/site/wp-content/plugins")
        with patch.dict(os.environ, {"PRO_FTP_PLUGINS_DIR": "/site/../.git"}):
            with self.assertRaisesRegex(RuntimeError, "Unsafe"):
                mod.enter_wp_plugins_root(FakeFTP())

    def test_integrity_mismatch_aborts(self):
        ftp = FakeFTP()
        root, visible = mod.enter_wp_plugins_root(ftp)
        with tempfile.TemporaryDirectory() as temp:
            cwd = os.getcwd()
            try:
                os.chdir(temp)
                slug = "taxocommerce-core"
                Path(slug).mkdir()
                (Path(slug) / (slug + ".php")).write_text("content")
                ftp.tamper = True
                with self.assertRaisesRegex(RuntimeError, "Final remote SHA-256 mismatch"):
                    mod.reconcile_one(ftp, root, slug, [slug + ".php"], [])
            finally:
                os.chdir(cwd)


if __name__ == "__main__":
    unittest.main()
