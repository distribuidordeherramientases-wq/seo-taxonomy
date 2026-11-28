#!/usr/bin/env python3
"""Publish only the Import-Export Pro ZIP to the two authorized test sites.

Use Core's existing local package directory and verified FTPS helpers.
Never install or activate plugins and never target production.
"""
import datetime as dt
import ftplib
import hashlib
import io
import json
import os
from pathlib import Path
import sys
import zipfile

from build_taxocommerce_updates import include
from package_staging_plugins_for_focazul import (
    ftp_root, read_file, verify_zip, write_atomic,
)

SLUG = "taxocommerce-import-export-pro"
SITES = ("staging.distribuidordeherramientas.es", "asociacionfocazul")
ROOT = Path(__file__).resolve().parents[2]


def package_import_export_pro(root=ROOT):
    folder = root / SLUG
    entry = folder / (SLUG + ".php")
    if not entry.is_file():
        raise ValueError("Import-Export Pro entrypoint absent")
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w", compression=zipfile.ZIP_DEFLATED) as bundle:
        for path in sorted(folder.rglob("*")):
            if include(path, folder) or (
                path == folder / "CHANGELOG.md" and path.is_file() and not path.is_symlink()
            ):
                # Stable bytes across checkouts: avoid unnecessary replacement.
                member = zipfile.ZipInfo((Path(SLUG) / path.relative_to(folder)).as_posix())
                member.compress_type = zipfile.ZIP_DEFLATED
                member.create_system = 3
                member.external_attr = 0o100644 << 16
                bundle.writestr(member, path.read_bytes())
    data = stream.getvalue()
    return data, verify_zip(SLUG, data)


def updated_manifest(original, info):
    try:
        manifest = json.loads(original)
    except (ValueError, UnicodeDecodeError) as exc:
        raise ValueError("Existing package manifest is invalid") from exc
    if not isinstance(manifest, dict) or manifest.get("schema") != 1 or not isinstance(manifest.get("plugins"), dict):
        raise ValueError("Existing package manifest has an unexpected schema")
    if manifest["plugins"].get(SLUG) == info:
        return original
    manifest["plugins"][SLUG] = info
    manifest["generated_utc"] = dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    return (json.dumps(manifest, indent=2, sort_keys=True) + "\n").encode("utf-8")


def publish(ftp, home, data, info):
    results = {}
    for site in SITES:
        location = ftp_root(ftp, home, site, create=True)
        # Validate the existing manifest before replacing the ZIP.
        try:
            raw = read_file(ftp, location + "/manifest.json", 512000)
        except ftplib.error_perm as exc:
            if not str(exc).startswith("550"):
                raise
            raw = None
        updated = updated_manifest(raw, info) if raw is not None else None
        status = write_atomic(ftp, location, SLUG + ".zip", data, info["sha256"])
        manifest_status = "ABSENT (local ZIP discovery remains available)"
        if updated is not None:
            manifest_status = write_atomic(
                ftp, location, "manifest.json", updated, hashlib.sha256(updated).hexdigest()
            )
        results[site] = {"zip": status, "manifest": manifest_status, "sha256": info["sha256"]}
        print(f"{site}: verified Import-Export Pro {info['version']} {status} sha256:{info['sha256']}; manifest {manifest_status}", flush=True)
    return results


def main():
    data, info = package_import_export_pro()
    host, user, password = (os.environ[key] for key in (
        "STAGING_FTP_SERVER", "STAGING_FTP_USERNAME", "STAGING_FTP_PASSWORD"
    ))
    if not all((host, user, password)):
        raise RuntimeError("Missing test-site FTPS credentials")
    with ftplib.FTP_TLS(timeout=60) as ftp:
        ftp.connect(host, 21)
        ftp.login(user, password)
        ftp.prot_p()
        publish(ftp, ftp.pwd(), data, info)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, RuntimeError, KeyError, *ftplib.all_errors) as error:
        print("::error::Import-Export Pro package distribution FAILED: " + str(error), file=sys.stderr)
        sys.exit(1)
