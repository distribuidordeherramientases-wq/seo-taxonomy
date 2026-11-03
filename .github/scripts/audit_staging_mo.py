#!/usr/bin/env python3
"""Read-only FTPS audit of WordPress translation catalogs on STAGING.

Reuses the FTPS account already configured for the incremental deploy.
Never edits server files or logs credentials, file contents, or translations.
"""
from __future__ import annotations

import ftplib
import hashlib
import os
from pathlib import Path, PurePosixPath
import sys

from verify_mo_catalogs import InvalidMO, ROOT, validate_mo_bytes

SITE = "staging.distribuidordeherramientas.es"
REMOTE_ROOT = "./" + SITE + "/wp-content"
MAX_CATALOG_BYTES = 32 * 1024 * 1024


def audit(ftp: ftplib.FTP_TLS) -> int:
    directories = [
        "languages",
        "languages/plugins",
        "languages/themes",
    ]
    directories.extend(
        f"plugins/{folder.name}/languages"
        for folder in sorted(ROOT.glob("taxocommerce-*"))
        if (folder / (folder.name + ".php")).is_file()
    )
    scanned = 0
    broken = 0
    differences = 0
    unavailable = 0
    login_root = ftp.pwd()

    for relative_dir in directories:
        try:
            ftp.cwd(login_root)
            ftp.cwd(REMOTE_ROOT + "/" + relative_dir)
            candidates = ftp.nlst()
        except ftplib.error_perm as exc:
            if str(exc).startswith("550"):
                unavailable += 1
                continue
            raise

        for listing in sorted(candidates):
            filename = PurePosixPath(listing.rstrip("/")).name
            if not filename.lower().endswith(".mo"):
                continue
            if filename in ("..", "."):
                continue

            payload = bytearray()

            def receive(chunk: bytes) -> None:
                payload.extend(chunk)
                if len(payload) > MAX_CATALOG_BYTES:
                    raise InvalidMO("catalog exceeds 32 MiB audit limit")

            remote_path = relative_dir + "/" + filename
            try:
                ftp.retrbinary("RETR " + filename, receive)
            except ftplib.error_perm as exc:
                if str(exc).startswith("550"):
                    continue
                raise
            except InvalidMO as exc:
                print(f"BROKEN {remote_path}: {exc}")
                broken += 1
                continue
            scanned += 1
            try:
                count = validate_mo_bytes(bytes(payload))
            except (InvalidMO, ValueError) as exc:
                print(f"BROKEN {remote_path}: {exc}; bytes={len(payload)}")
                broken += 1
                continue

            if relative_dir.startswith("plugins/taxocommerce-"):
                local = ROOT / relative_dir[len("plugins/"):] / filename
                if local.is_file():
                    remote_hash = hashlib.sha256(payload).hexdigest()
                    local_hash = hashlib.sha256(local.read_bytes()).hexdigest()
                    if remote_hash != local_hash:
                        differences += 1
                        print(f"DIFFERENT {remote_path}: {count} entries (repo/local SHA-256 mismatch)")

    print(
        f"STAGING MO FTPS audit: {scanned} scanned; {broken} BROKEN; "
        f"{differences} different from checked-out plugin catalog; "
        f"{unavailable} optional directories absent"
    )
    return int(bool(broken))


def main() -> int:
    required = ("STAGING_FTP_SERVER", "STAGING_FTP_USERNAME", "STAGING_FTP_PASSWORD")
    if any(not os.environ.get(item) for item in required):
        print("::error::FTPS credentials missing; no files were inspected.")
        return 2
    ftp = ftplib.FTP_TLS(timeout=50)
    try:
        ftp.connect(os.environ["STAGING_FTP_SERVER"], 21)
        ftp.login(os.environ["STAGING_FTP_USERNAME"], os.environ["STAGING_FTP_PASSWORD"])
        ftp.prot_p()
        return audit(ftp)
    except (*ftplib.all_errors, OSError) as exc:
        print(f"::error::Read-only FTPS MO inspection failed: {type(exc).__name__}")
        return 2
    finally:
        try:
            ftp.quit()
        except (*ftplib.all_errors, OSError):
            ftp.close()


if __name__ == "__main__":
    sys.exit(main())
