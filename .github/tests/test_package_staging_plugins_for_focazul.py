"""Offline contracts for staging-plugin ZIP packaging; no network/WordPress writes."""
import hashlib
import importlib.util
import io
import posixpath
import unittest
import zipfile
from pathlib import Path

FILE = Path(__file__).resolve().parents[1] / "scripts" / "package_staging_plugins_for_focazul.py"
spec = importlib.util.spec_from_file_location("tc_plugin_packager", FILE)
packager = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packager)


class MemoryFTP:
    """Simulate an isolated account containing exactly two WordPress roots."""
    def __init__(self):
        self.cwd_path = "/home/public_html"
        self.directories = {
            "/home/public_html",
            "/home/public_html/staging.distribuidordeherramientas.es",
            "/home/public_html/staging.distribuidordeherramientas.es/wp-content",
            "/home/public_html/staging.distribuidordeherramientas.es/wp-content/plugins",
            "/home/public_html/staging.distribuidordeherramientas.es/wp-content/plugins/taxocommerce-templates",
            "/home/public_html/staging.distribuidordeherramientas.es/wp-content/plugins/taxocommerce-templates/includes",
            "/home/public_html/staging.distribuidordeherramientas.es/wp-content/plugins/taxocommerce-templates/tests",
            "/home/public_html/staging.distribuidordeherramientas.es/wp-content/plugins/taxocommerce-distribution",
            "/home/public_html/asociacionfocazul",
            "/home/public_html/asociacionfocazul/wp-content",
            "/home/public_html/asociacionfocazul/wp-content/uploads",
        }
        source = "/home/public_html/staging.distribuidordeherramientas.es/wp-content/plugins/"
        self.files = {
            source + "taxocommerce-templates/taxocommerce-templates.php": b"<?php\n/**\n * Version: 0.4.11\n */\n",
            source + "taxocommerce-templates/includes/class-tct-manual.php": b"<?php // manual\n",
            source + "taxocommerce-templates/tests/smoke.php": b"<?php // test\n",
            source + "taxocommerce-templates/.ftp-deploy-sync-state.json": b"secret",
            source + "taxocommerce-distribution/taxocommerce-distribution.php": b"<?php\n/**\n * Version: 0.1.6\n */\n",
        }
        self.operations = []
    def _norm(self, path):
        if path.startswith("/"):
            return posixpath.normpath(path)
        return posixpath.normpath(posixpath.join(self.cwd_path, path))
    def pwd(self):
        return self.cwd_path
    def cwd(self, path):
        target = self._norm(path)
        if target not in self.directories:
            raise packager.ftplib.error_perm("550 directory unavailable: " + target)
        self.cwd_path = target
    def mlsd(self):
        base = self.cwd_path.rstrip("/") + "/"
        names = {}
        for directory in self.directories:
            if directory.startswith(base):
                rest = directory[len(base):]
                if rest and "/" not in rest:
                    names[rest] = "dir"
        for file in self.files:
            if file.startswith(base):
                rest = file[len(base):]
                if rest and "/" not in rest:
                    names[rest] = "file"
        return iter((name, {"type": kind}) for name, kind in names.items())
    def retrbinary(self, command, callback, blocksize=65536):
        assert command.startswith("RETR ")
        name = self._norm(command[5:])
        if name not in self.files:
            raise packager.ftplib.error_perm("550 file unavailable")
        callback(self.files[name])
    def storbinary(self, command, stream, blocksize=65536):
        assert command.startswith("STOR ")
        name = self._norm(command[5:])
        self.files[name] = stream.read()
        self.operations.append(("STOR", name))
    def rename(self, a, b):
        name_a = self._norm(a)
        name_b = self._norm(b)
        self.files[name_b] = self.files.pop(name_a)
        self.operations.append(("RENAME", name_b))
    def delete(self, path):
        name = self._norm(path)
        if name not in self.files:
            raise packager.ftplib.error_perm("550 missing")
        del self.files[name]
    def mkd(self, path):
        target = self._norm(path)
        self.directories.add(target)
        self.operations.append(("MKD", target))


class PackagingTests(unittest.TestCase):
    def setUp(self):
        self.ftp = MemoryFTP()
        self.source = "/home/public_html/staging.distribuidordeherramientas.es/wp-content/plugins"

    def test_only_installed_taxocommerce_roots_are_selected(self):
        plugins = packager.inventory(self.ftp, self.source)
        self.assertEqual(plugins, ["taxocommerce-distribution", "taxocommerce-templates"])

    def test_zip_is_installable_and_excludes_tests_hidden(self):
        slug = "taxocommerce-templates"
        root = self.source + "/" + slug
        files = packager.plugin_files(self.ftp, root)
        self.assertEqual(files, ["includes/class-tct-manual.php", "taxocommerce-templates.php"])
        stream, report = packager.build_package(self.ftp, root, slug, files)
        try:
            self.assertEqual(report["version"], "0.4.11")
            with zipfile.ZipFile(stream) as archive:
                self.assertEqual(archive.namelist(), [
                    "taxocommerce-templates/includes/class-tct-manual.php",
                    "taxocommerce-templates/taxocommerce-templates.php",
                ])
                self.assertIsNone(archive.testzip())
            stream.seek(0)
            digest = hashlib.sha256(stream.read()).hexdigest()
            self.assertEqual(digest, report["sha256"])
        finally:
            stream.close()

    def test_focazul_upload_path_and_verified_copy(self):
        folder = packager.destination_folder(self.ftp, "/home/public_html")
        self.assertEqual(folder, "/home/public_html/asociacionfocazul/wp-content/uploads/taxocommerce-plugin-zips")
        root = self.source + "/taxocommerce-distribution"
        files = packager.plugin_files(self.ftp, root)
        stream, report = packager.build_package(self.ftp, root, "taxocommerce-distribution", files)
        try:
            filename = "taxocommerce-distribution-" + report["sha256"][:16] + ".zip"
            self.assertEqual(packager.store_verified(self.ftp, folder, filename, stream, report["sha256"]), "UPDATED")
            self.assertEqual(packager.store_verified(self.ftp, folder, filename, stream, report["sha256"]), "UNCHANGED")
            self.assertEqual(packager.remote_hash(self.ftp, filename), report["sha256"])
            for action, path in self.ftp.operations:
                if action in ("STOR", "RENAME"):
                    self.assertIn("/asociacionfocazul/wp-content/uploads/taxocommerce-plugin-zips/", path)
        finally:
            stream.close()

    def test_protect_paths_and_do_not_copy_misleading_files(self):
        for name in (".ftp-deploy-sync-state.json", "../wp-config.php", "bad\nfile", "x\\evil.php"):
            self.assertFalse(packager.allowed_component(name))
        self.assertFalse(packager.allowed_component("tests", directory=True))
        self.assertFalse(packager.allowed_component("package.log"))
        self.assertTrue(packager.allowed_component("taxocommerce-templates.php"))
        self.assertTrue(packager.allowed_component("languages", directory=True))
        self.assertTrue(packager.allowed_component("taxocommerce-templates-es_ES.mo"))
        with self.assertRaises(ValueError):
            packager.build_package(self.ftp, self.source + "/taxocommerce-templates",
                                   "taxocommerce-templates", ["taxocommerce-templates.php", "../wp-config.php"])


if __name__ == "__main__":
    unittest.main()
