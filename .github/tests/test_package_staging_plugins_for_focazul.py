"""Offline tests for canonical STAGING -> Focazul TaxoCommerce uploads mirror."""
import hashlib
import importlib.util
import io
import json
import posixpath
import unittest
import zipfile
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "package_staging_plugins_for_focazul.py"
spec = importlib.util.spec_from_file_location("tc_package_mirror", SCRIPT)
mirror = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mirror)


def archive(slug, version):
    data = io.BytesIO()
    with zipfile.ZipFile(data, "w", zipfile.ZIP_DEFLATED) as zipped:
        zipped.writestr(slug + "/" + slug + ".php", "<?php\n/**\n * Version: " + version + "\n */\n")
        zipped.writestr(slug + "/includes/file.php", "<?php // code\n")
    return data.getvalue()


class FakeFTP:
    def __init__(self):
        self.path = "/home/public_html"
        self.dirs = set()
        for site in (mirror.SOURCE_SITE, mirror.DESTINATION_SITE):
            root = "/home/public_html/" + site
            for part in ("", "/wp-content", "/wp-content/uploads"):
                self.dirs.add(root + part)
        for part in ("/taxocommerce-core", "/taxocommerce-core/plugins"):
            self.dirs.add("/home/public_html/" + mirror.SOURCE_SITE + "/wp-content/uploads" + part)
        self.dirs.add("/home/public_html")
        self.files = {}
        src = self.source()
        self.files[src + "/taxocommerce-templates.zip"] = archive("taxocommerce-templates", "0.4.11")
        self.files[src + "/taxocommerce-distribution.zip"] = archive("taxocommerce-distribution", "0.1.6")
        self.calls = []

    def source(self):
        return "/home/public_html/" + mirror.SOURCE_SITE + "/" + mirror.REPOSITORY

    def destination(self):
        return "/home/public_html/" + mirror.DESTINATION_SITE + "/" + mirror.REPOSITORY

    def absolute(self, value):
        return posixpath.normpath(value if value.startswith("/") else posixpath.join(self.path, value))

    def pwd(self):
        return self.path

    def cwd(self, value):
        dest = self.absolute(value)
        if dest not in self.dirs:
            raise mirror.ftplib.error_perm("550 missing")
        self.path = dest

    def mkd(self, value):
        dest = self.absolute(value)
        self.dirs.add(dest)
        self.calls.append(("MKD", dest))

    def mlsd(self):
        # The real shared FTPS service may expose "." and ".." as cdir/pdir.
        yield ".", {"type": "cdir"}
        yield "..", {"type": "pdir"}
        prefix = self.path.rstrip("/") + "/"
        for name in sorted(self.files):
            if name.startswith(prefix):
                relative = name[len(prefix):]
                if "/" not in relative:
                    yield relative, {"type": "file"}
        for name in sorted(self.dirs):
            if name.startswith(prefix):
                relative = name[len(prefix):]
                if relative and "/" not in relative:
                    yield relative, {"type": "dir"}

    def retrbinary(self, command, callback, blocksize=65536):
        name = self.absolute(command[5:])
        if name not in self.files:
            raise mirror.ftplib.error_perm("550 absent")
        for i in range(0, len(self.files[name]), 16384):
            callback(self.files[name][i:i + 16384])

    def storbinary(self, command, stream, blocksize=65536):
        name = self.absolute(command[5:])
        self.files[name] = stream.read()
        self.calls.append(("STOR", name))

    def rename(self, source, dest):
        a, b = self.absolute(source), self.absolute(dest)
        if a not in self.files or b in self.files:
            raise mirror.ftplib.error_perm("550 invalid rename")
        self.files[b] = self.files.pop(a)
        self.calls.append(("RENAME", b))

    def delete(self, name):
        fullname = self.absolute(name)
        if fullname not in self.files:
            raise mirror.ftplib.error_perm("550 absent")
        del self.files[fullname]


class MirrorTests(unittest.TestCase):
    def setUp(self):
        self.ftp = FakeFTP()

    def test_both_sites_use_one_wordpress_relative_folder(self):
        src = mirror.ftp_root(self.ftp, "/home/public_html", mirror.SOURCE_SITE)
        dst = mirror.ftp_root(self.ftp, "/home/public_html", mirror.DESTINATION_SITE)
        self.assertEqual(src, self.ftp.source())
        self.assertEqual(dst, self.ftp.destination())
        self.assertTrue(dst.endswith("/wp-content/uploads/taxocommerce-core/plugins"))

    def test_copy_standard_names_and_manifest_last(self):
        src = mirror.ftp_root(self.ftp, "/home/public_html", mirror.SOURCE_SITE)
        dst = mirror.ftp_root(self.ftp, "/home/public_html", mirror.DESTINATION_SITE)
        result = mirror.mirror(self.ftp, src, dst)
        self.assertEqual(result["count"], 2)
        self.assertEqual(sorted(f.split("/")[-1] for f in self.ftp.files if f.startswith(dst + "/")),
                         ["manifest.json", "taxocommerce-distribution.zip", "taxocommerce-templates.zip"])
        summary = json.loads(self.ftp.files[dst + "/manifest.json"])
        self.assertEqual(summary["schema"], 1)
        self.assertEqual(set(summary["plugins"]), {"taxocommerce-templates", "taxocommerce-distribution"})
        self.assertEqual(summary["plugins"]["taxocommerce-templates"]["version"], "0.4.11")
        self.assertEqual(summary["plugins"]["taxocommerce-templates"]["sha256"],
                         hashlib.sha256(self.ftp.files[dst + "/taxocommerce-templates.zip"]).hexdigest())
        self.assertEqual(self.ftp.calls[-1], ("RENAME", dst + "/manifest.json"))
        self.assertTrue(all("/asociacionfocazul/" in path for action, path in self.ftp.calls
                            if action in ("STOR", "RENAME")))

    def test_idempotent_and_safe_update_of_existing_zip(self):
        src = mirror.ftp_root(self.ftp, "/home/public_html", mirror.SOURCE_SITE)
        dst = mirror.ftp_root(self.ftp, "/home/public_html", mirror.DESTINATION_SITE)
        self.ftp.files[dst + "/taxocommerce-templates.zip"] = archive("taxocommerce-templates", "0.4.10")
        first = mirror.mirror(self.ftp, src, dst)
        self.assertEqual(first["plugins"]["taxocommerce-templates"]["status"], "UPDATED")
        self.assertNotIn(".tc-package-backup-", " ".join(self.ftp.files))
        second = mirror.mirror(self.ftp, src, dst)
        self.assertEqual(second["plugins"]["taxocommerce-templates"]["status"], "UNCHANGED")

    def test_unverified_staging_manifest_fails_before_upload(self):
        src = mirror.ftp_root(self.ftp, "/home/public_html", mirror.SOURCE_SITE)
        dst = mirror.ftp_root(self.ftp, "/home/public_html", mirror.DESTINATION_SITE)
        self.ftp.files[src + "/manifest.json"] = b'{"schema":1,"plugins":{"taxocommerce-templates":{"version":"0.0.0"}}}'
        with self.assertRaises(ValueError):
            mirror.mirror(self.ftp, src, dst)
        self.assertNotIn(dst + "/taxocommerce-templates.zip", self.ftp.files)

    def test_traversal_and_missing_entrypoint_rejected(self):
        data = io.BytesIO()
        with zipfile.ZipFile(data, "w") as output:
            output.writestr("taxocommerce-templates/../wp-config.php", "bad")
            output.writestr("taxocommerce-templates/taxocommerce-templates.php",
                            "<?php\n/**\n * Version: 0.4.11\n */\n")
        with self.assertRaises(ValueError):
            mirror.verify_zip("taxocommerce-templates", data.getvalue())
        with self.assertRaises(ValueError):
            mirror.verify_zip("../outside", b"fake")


if __name__ == "__main__":
    unittest.main()
