"""FTPS repair regression tests for issue #2094; no network used."""
from pathlib import Path
import ftplib
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from repair_staging_mo_2094 import LOCALES, PLUGIN, REMOTE, repair_catalogs
from test_verify_mo_catalogs import catalog


class FakeFTP:
    def __init__(self, files):
        self.files = dict(files)
        self.uploads = []
        self.dirs = []

    def pwd(self):
        return "/root"

    def cwd(self, path):
        self.dirs.append(path)

    def retrbinary(self, cmd, callback):
        name = cmd.partition(" ")[2]
        if name not in self.files:
            raise ftplib.error_perm("550 file not found")
        callback(self.files[name])

    def storbinary(self, cmd, stream):
        name = cmd.partition(" ")[2]
        self.uploads.append(name)
        self.files[name] = stream.read()

    def rename(self, src, dest):
        self.files[dest] = self.files.pop(src)

    def delete(self, name):
        if name not in self.files:
            raise ftplib.error_perm("550 file not found")
        del self.files[name]


class StagingMORepairTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        location = self.root / PLUGIN / "languages"
        location.mkdir(parents=True)
        self.expected = {}
        for locale in LOCALES:
            name = PLUGIN + "-" + locale + ".mo"
            self.expected[name] = catalog("<", 2)
            (location / name).write_bytes(self.expected[name])

    def test_only_malformed_files_are_replaced(self):
        ftp = FakeFTP({name: b"corrupt" for name in self.expected})
        repaired, valid = repair_catalogs(ftp, self.root)
        self.assertEqual((repaired, valid), (5, 0))
        self.assertEqual({k: v for k, v in ftp.files.items()}, self.expected)
        self.assertEqual(len(ftp.uploads), 5)
        self.assertIn(REMOTE, ftp.dirs)

    def test_valid_files_are_not_overwritten(self):
        ftp = FakeFTP(self.expected)
        repaired, valid = repair_catalogs(ftp, self.root)
        self.assertEqual((repaired, valid), (0, 5))
        self.assertFalse(ftp.uploads)

    def test_valid_but_different_is_never_overwritten(self):
        different = catalog(">", 1)
        ftp = FakeFTP({name: different for name in self.expected})
        repaired, valid = repair_catalogs(ftp, self.root)
        self.assertEqual((repaired, valid), (0, 5))
        self.assertFalse(ftp.uploads)
        self.assertTrue(all(blob == different for blob in ftp.files.values()))

    def test_invalid_repo_catalog_prevents_any_remote_change(self):
        first = next(iter(self.expected))
        (self.root / PLUGIN / "languages" / first).write_bytes(b"invalid")
        ftp = FakeFTP({name: b"broken" for name in self.expected})
        with self.assertRaises(ValueError):
            repair_catalogs(ftp, self.root)
        self.assertFalse(ftp.uploads)


if __name__ == "__main__":
    unittest.main()
