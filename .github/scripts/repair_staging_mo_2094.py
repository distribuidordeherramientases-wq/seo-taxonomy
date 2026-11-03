#!/usr/bin/env python3
"""One-time, narrowly-scoped repair of malformed Scrappers MO files on STAGING.

Issue #2094. Only replaces five proven malformed MO catalogs with repository
copies after validating local MO structure. Refuses other paths or sites.
Does not touch wp-includes, PRO, other plugins or translations stored globally.
"""
from __future__ import annotations

import argparse
import ftplib
import hashlib
import io
import os
from pathlib import Path
import sys

from verify_mo_catalogs import InvalidMO, ROOT, validate_mo_bytes

PLUGIN = "taxocommerce-scrappers"
LOCALES = ("de_DE", "en_US", "es_ES", "fr_FR", "nl_NL")
REMOTE = "./staging.distribuidordeherramientas.es/wp-content/plugins/" + PLUGIN + "/languages"
MAX_BYTES = 32 * 1024 * 1024


def read_remote(ftp, name: str) -> bytes:
    buffer = bytearray()

    def collect(chunk: bytes) -> None:
        buffer.extend(chunk)
        if len(buffer) > MAX_BYTES:
            raise InvalidMO("remote MO larger than safety limit")

    ftp.retrbinary("RETR " + name, collect)
    return bytes(buffer)


def repair_catalogs(ftp, root: Path = ROOT) -> tuple[int, int]:
    source = root / PLUGIN / "languages"
    prepared = {}
    for locale in LOCALES:
        name = PLUGIN + "-" + locale + ".mo"
        data = (source / name).read_bytes()
        validate_mo_bytes(data)
        prepared[name] = data

    start = ftp.pwd()
    ftp.cwd(start)
    ftp.cwd(REMOTE)  # Strictly restricted to the existing STAGING directory.
    fixed = 0
    already_valid = 0
    for filename, expected in prepared.items():
        try:
            remote = read_remote(ftp, filename)
        except ftplib.error_perm as exc:
            raise RuntimeError(f"{filename}: installed file inaccessible; refusing to create it") from exc

        try:
            validate_mo_bytes(remote)
            if remote != expected:
                print(f"SKIP VALID DIFFERENT {filename}: not overwriting site-specific translations")
            else:
                print(f"UNCHANGED {filename}: remote already matches repo")
            already_valid += 1
            continue
        except InvalidMO as exc:
            print(f"REPAIR {filename}: malformed MO ({exc})")

        # Idempotent temporary name; atomic rename preserves the previous file
        # until a fully transferred replacement has been received by the server.
        temp = ".taxocommerce-mo-repair-2094-" + filename + ".tmp"
        try:
            with io.BytesIO(expected) as stream:
                ftp.storbinary("STOR " + temp, stream)
            ftp.rename(temp, filename)
            saved = read_remote(ftp, filename)
            if saved != expected:
                raise RuntimeError(f"{filename}: remote verification mismatch")
            validate_mo_bytes(saved)
            fixed += 1
            print(f"VERIFIED {filename} sha256:{hashlib.sha256(saved).hexdigest()[:12]}")
        finally:
            try:
                ftp.delete(temp)
            except ftplib.all_errors:
                pass
    return fixed, already_valid


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repair-staging", action="store_true")
    args = parser.parse_args()
    if not args.repair_staging:
        parser.error("--repair-staging must be explicit; refuses silent server writes")
    required = ("STAGING_FTP_SERVER", "STAGING_FTP_USERNAME", "STAGING_FTP_PASSWORD")
    if any(not os.environ.get(key) for key in required):
        print("::error::STAGING FTPS credentials are missing")
        return 2

    ftp = ftplib.FTP_TLS(timeout=50)
    try:
        ftp.connect(os.environ["STAGING_FTP_SERVER"], 21)
        ftp.login(os.environ["STAGING_FTP_USERNAME"], os.environ["STAGING_FTP_PASSWORD"])
        ftp.prot_p()
        fixed, valid = repair_catalogs(ftp)
        print(f"STAGING Scrappers MO repair: {fixed} repaired, {valid} already structurally valid")
        return 0
    except (*ftplib.all_errors, InvalidMO, RuntimeError, OSError) as exc:
        print(f"::error::STAGING MO repair failed: {type(exc).__name__} ({exc})")
        return 2
    finally:
        try:
            ftp.quit()
        except (*ftplib.all_errors, OSError):
            ftp.close()


if __name__ == "__main__":
    sys.exit(main())
