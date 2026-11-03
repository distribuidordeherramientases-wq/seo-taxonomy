#!/usr/bin/env python3
"""One-time STAGING recovery of exactly five corrupted Scrappers MO files (#2096).

Source bytes are already tracked in GitHub and validated as WordPress-compatible.
Use the established delta deployer's atomic FTPS upload and SHA-256 verification.
This script never writes to Focazul, PRO, WordPress Core, or another plugin.
"""
from __future__ import annotations

import ftplib
import hashlib
import os
from pathlib import Path
import sys

from deploy_staging_delta import upload_plugin, remote_plugin_dir
from verify_mo_catalogs import ROOT, validate_mo_bytes

SLUG = "taxocommerce-scrappers"
LOCALES = ("de_DE", "en_US", "es_ES", "fr_FR", "nl_NL")
FILENAMES = tuple("languages/" + SLUG + "-" + loc + ".mo" for loc in LOCALES)


def repair() -> None:
    for relative in FILENAMES:
        source = ROOT / SLUG / relative
        if not source.is_file():
            raise FileNotFoundError("missing tracked MO: " + relative)
        validate_mo_bytes(source.read_bytes())

    ftp = ftplib.FTP_TLS(timeout=50)
    try:
        ftp.connect(os.environ["STAGING_FTP_SERVER"], 21)
        ftp.login(os.environ["STAGING_FTP_USERNAME"], os.environ["STAGING_FTP_PASSWORD"])
        ftp.prot_p()
        ftp_root = ftp.pwd()
        ftp.cwd(ftp_root)

        # Reuses site/slug allowlist, installed-plugin check, atomic STOR+rename,
        # and full SHA-256 verification for each of these five files.
        upload_plugin(ftp, "staging", SLUG, FILENAMES, required=True)

        ftp.cwd(ftp_root)
        ftp.cwd(remote_plugin_dir("staging", SLUG))
        ftp.cwd("languages")
        for relative in FILENAMES:
            filename = Path(relative).name
            data = bytearray()
            ftp.retrbinary("RETR " + filename, data.extend)
            validate_mo_bytes(bytes(data))
            expected = hashlib.sha256((ROOT / SLUG / relative).read_bytes()).hexdigest()
            if hashlib.sha256(data).hexdigest() != expected:
                raise RuntimeError("live MO hash mismatch: " + filename)
            print("VERIFIED STAGING " + relative)
        print("STAGING Scrappers: all 5 MO catalogs restored and validated.")
    finally:
        try:
            ftp.quit()
        except (*ftplib.all_errors, OSError):
            ftp.close()


if __name__ == "__main__":
    required = ("STAGING_FTP_SERVER", "STAGING_FTP_USERNAME", "STAGING_FTP_PASSWORD")
    if any(not os.environ.get(name) for name in required):
        raise SystemExit("Missing existing STAGING FTPS credentials")
    try:
        repair()
    except (*ftplib.all_errors, OSError, ValueError) as exc:
        raise SystemExit("STAGING Scrappers MO recovery failed: " + type(exc).__name__) from None
