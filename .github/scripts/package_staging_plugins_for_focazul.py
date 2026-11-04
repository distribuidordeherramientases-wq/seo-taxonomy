#!/usr/bin/env python3
"""Mirror the STAGING TaxoCommerce ZIP repository into Focazul's SAME uploads path.

Both sites use wp-content/uploads/taxocommerce-core/plugins/ relative to their
own WordPress root. No active plugins, options, or databases are modified.
Only verified canonical ZIPs and (last) manifest.json are copied.
"""
import argparse
import datetime
import ftplib
import hashlib
import io
import json
import os
import re
import sys
import zipfile

SOURCE_SITE = "staging.distribuidordeherramientas.es"
DESTINATION_SITE = "asociacionfocazul"
REPOSITORY = "wp-content/uploads/taxocommerce-core/plugins"
SLUG = re.compile(r"^taxocommerce-[a-z0-9-]+$")
VERSION = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+(?:[-.][A-Za-z0-9.]+)?$")
MAX_PLUGINS = 50
MAX_PACKAGE = 128 * 1024 * 1024
MAX_TOTAL = 1024 * 1024 * 1024
MAX_FILES = 12000
MAX_UNCOMPRESSED = 512 * 1024 * 1024


def safe_name(value):
    name = str(value).rstrip("/").rsplit("/", 1)[-1]
    if not name or name in (".", "..") or any(c in name for c in ("/", "\\", "\r", "\n", "\x00")):
        raise ValueError("Invalid FTP listing entry")
    return name


def listing(ftp, directory):
    """List regular files without following directories or FTP symlinks."""
    ftp.cwd(directory)
    try:
        result = []
        for name, facts in ftp.mlsd():
            # FTP MLSD frequently exposes cdir/pdir records named "." or "..".
            # They are navigation markers, never package files.
            kind = str(facts.get("type", "")).lower()
            if kind in ("cdir", "pdir"):
                continue
            if not isinstance(name, str) or name.rstrip("/") in ("", ".", ".."):
                continue
            name = safe_name(name)
            if kind == "file":
                result.append(name)
        return sorted(set(result))
    except (AttributeError, ftplib.error_perm, ftplib.error_reply):
        ftp.cwd(directory)
        result = []
        for raw in ftp.nlst():
            if not isinstance(raw, str) or raw.rstrip("/") in ("", ".", ".."):
                continue
            name = safe_name(raw)
            try:
                ftp.cwd(directory.rstrip("/") + "/" + name)
            except ftplib.error_perm as exc:
                if not str(exc).startswith("550"):
                    raise
                result.append(name)
            else:
                ftp.cwd(directory)
                # A directory or linked directory is not a regular file.
        return sorted(set(result))


def read_file(ftp, path, limit):
    data = io.BytesIO()
    total = 0

    def collect(chunk):
        nonlocal total
        total += len(chunk)
        if total > limit:
            raise ValueError("FTP file exceeds safe size limit")
        data.write(chunk)

    ftp.retrbinary("RETR " + path, collect, blocksize=65536)
    return data.getvalue()


def hash_remote(ftp, name):
    digest = hashlib.sha256()
    try:
        ftp.retrbinary("RETR " + name, digest.update, blocksize=65536)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return None
        raise
    return digest.hexdigest()


def verify_zip(slug, content):
    if not SLUG.fullmatch(slug):
        raise ValueError("Unexpected plugin ZIP filename")
    main = slug + "/" + slug + ".php"
    names = set()
    unpacked = 0
    try:
        with zipfile.ZipFile(io.BytesIO(content), "r") as archive:
            items = archive.infolist()
            if not items or len(items) > MAX_FILES:
                raise ValueError(slug + ": invalid number of ZIP entries")
            for item in items:
                name = item.filename
                parts = name.split("/")
                if (not name.startswith(slug + "/") or ".." in parts or "\\" in name or
                        name.startswith("/") or any(part.startswith(".") for part in parts if part)):
                    raise ValueError(slug + ": unsafe archive member: " + name[:100])
                mode = (item.external_attr >> 16) & 0o170000
                if mode == 0o120000:
                    raise ValueError(slug + ": ZIP contains a symlink")
                if not item.is_dir():
                    if name in names:
                        raise ValueError(slug + ": duplicate archive member")
                    names.add(name)
                    unpacked += item.file_size
                    if unpacked > MAX_UNCOMPRESSED:
                        raise ValueError(slug + ": oversized archive")
            if main not in names or archive.testzip() is not None:
                raise ValueError(slug + ": invalid ZIP or plugin entrypoint missing")
            header = archive.read(main)[:8192].decode("utf-8", errors="replace")
    except (zipfile.BadZipFile, zipfile.LargeZipFile, RuntimeError) as exc:
        raise ValueError(slug + ": ZIP cannot be verified") from exc
    match = re.search(r"^\s*\*\s*Version:\s*(\S+)", header, re.MULTILINE)
    if not match or not VERSION.fullmatch(match.group(1)):
        raise ValueError(slug + ": missing valid plugin version")
    requires = re.search(r"^\s*\*\s*Requires PHP:\s*(\S+)", header, re.MULTILINE)
    return {
        "version": match.group(1),
        "size": len(content),
        "sha256": hashlib.sha256(content).hexdigest(),
        "requires_php": requires.group(1) if requires else "7.4",
    }


def ftp_root(ftp, home, site, create=False):
    ftp.cwd(home)
    ftp.cwd("./" + site + "/wp-content/uploads")
    uploads = ftp.pwd()
    for part in ("taxocommerce-core", "plugins"):
        try:
            ftp.cwd(part)
        except ftplib.error_perm as exc:
            if not create or not str(exc).startswith("550"):
                raise
            ftp.mkd(part)
            ftp.cwd(part)
    result = ftp.pwd()
    if result != uploads.rstrip("/") + "/taxocommerce-core/plugins":
        raise ValueError("FTP resolved outside canonical WordPress uploads directory")
    return result


def write_atomic(ftp, folder, filename, content, expected_sha):
    """Verify a temporary upload before replacing an existing canonical file."""
    ftp.cwd(folder)
    old = hash_remote(ftp, filename)
    if old == expected_sha:
        return "UNCHANGED"
    tmp = ".tc-package-upload-" + expected_sha[:24] + ".tmp"
    backup = ".tc-package-backup-" + hashlib.sha256(filename.encode()).hexdigest()[:24] + ".bak"
    if hash_remote(ftp, backup) is not None:
        raise RuntimeError("Previous incomplete replacement detected: " + filename)
    ftp.storbinary("STOR " + tmp, io.BytesIO(content), blocksize=65536)
    if hash_remote(ftp, tmp) != expected_sha:
        raise RuntimeError("Unverified FTPS temp upload: " + filename)
    moved_old = False
    try:
        if old is not None:
            ftp.rename(filename, backup)
            moved_old = True
        ftp.rename(tmp, filename)
        if hash_remote(ftp, filename) != expected_sha:
            raise RuntimeError("Remote SHA-256 verification failed: " + filename)
        if moved_old:
            ftp.delete(backup)
    except Exception:
        # Restore the previously available archive if rename or verification fails.
        if moved_old and hash_remote(ftp, backup) is not None:
            try:
                ftp.delete(filename)
            except ftplib.error_perm as err:
                if not str(err).startswith("550"):
                    raise
            ftp.rename(backup, filename)
        raise
    finally:
        try:
            ftp.delete(tmp)
        except ftplib.all_errors:
            pass
    return "UPDATED"


def validate_source_manifest(raw, packages):
    if raw is None:
        return None
    try:
        data = json.loads(raw)
    except (ValueError, UnicodeError) as exc:
        raise ValueError("Invalid STAGING manifest.json") from exc
    if not isinstance(data, dict) or data.get("schema") != 1 or not isinstance(data.get("plugins"), dict):
        raise ValueError("STAGING manifest.json has an incompatible format")
    expected = data["plugins"]
    if set(expected) != set(packages):
        raise ValueError("STAGING repository ZIP inventory and manifest disagree")
    for slug, item in expected.items():
        if not isinstance(item, dict) or any(item.get(key) != packages[slug][key] for key in ("version", "size", "sha256")):
            raise ValueError(slug + ": manifest does not match verified ZIP")
    return data


def mirror(ftp, source, destination):
    """Validate all packages BEFORE publishing any new archive on Focazul."""
    names = listing(ftp, source)
    slugs = sorted(name[:-4] for name in names if name.endswith(".zip") and SLUG.fullmatch(name[:-4]))
    if not slugs or len(slugs) > MAX_PLUGINS:
        raise ValueError("No safe STAGING plugin ZIP inventory or too many packages")
    packages = {}
    data = {}
    total = 0
    for slug in slugs:
        raw = read_file(ftp, source + "/" + slug + ".zip", MAX_PACKAGE)
        total += len(raw)
        if total > MAX_TOTAL:
            raise ValueError("STAGING plugin archives exceed safe size limit")
        packages[slug] = verify_zip(slug, raw)
        data[slug] = raw
    manifest_raw = read_file(ftp, source + "/manifest.json", 512000) if "manifest.json" in names else None
    source_manifest = validate_source_manifest(manifest_raw, packages)
    manifest = source_manifest or {
        "schema": 1,
        "generated_utc": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "plugins": packages,
    }
    report = {"source": SOURCE_SITE + "/" + REPOSITORY,
              "destination": DESTINATION_SITE + "/" + REPOSITORY,
              "plugins": {}, "count": len(slugs)}
    for slug in slugs:
        filename = slug + ".zip"
        status = write_atomic(ftp, destination, filename, data[slug], packages[slug]["sha256"])
        report["plugins"][slug] = dict(packages[slug], status=status)
        print(status, filename, "version=" + packages[slug]["version"], "sha256=" + packages[slug]["sha256"][:12], flush=True)
    # Publish the manifest LAST, so Core and the native update provider never
    # reference a package which has not been verified on the destination.
    encoded = (json.dumps(manifest, indent=2, sort_keys=True, ensure_ascii=False) + "\n").encode("utf-8")
    report["manifest"] = write_atomic(ftp, destination, "manifest.json", encoded, hashlib.sha256(encoded).hexdigest())
    print("SUCCESS: mirrored", len(slugs), "canonical plugin ZIPs to Focazul", flush=True)
    return report


def publish_built_repository(ftp, home, directory):
    """Publish *every* repository plugin ZIP to the same uploads path on both sites.

    The ZIPs are built from the staging Git tree by build_taxocommerce_updates.py
    and validated completely before the first FTPS write.
    """
    manifest_path = os.path.join(directory, "manifest.json")
    with open(manifest_path, "rb") as inp:
        manifest_bytes = inp.read(512001)
    if len(manifest_bytes) > 512000:
        raise ValueError("Local ZIP manifest exceeds safe size")
    manifest = json.loads(manifest_bytes)
    if manifest.get("schema") != 1 or not isinstance(manifest.get("plugins"), dict):
        raise ValueError("Invalid built plugin manifest")
    expected = manifest["plugins"]
    if not 1 <= len(expected) <= MAX_PLUGINS:
        raise ValueError("Unexpected number of plugin packages")
    packages = {}
    total = 0
    for slug in sorted(expected):
        if not SLUG.fullmatch(slug):
            raise ValueError("Invalid plugin slug in manifest")
        info = expected[slug]
        if not isinstance(info, dict):
            raise ValueError(slug + ": invalid manifest entry")
        full_path = os.path.join(directory, slug + ".zip")
        if os.path.islink(full_path):
            raise ValueError(slug + ": package is a symlink")
        with open(full_path, "rb") as archive:
            content = archive.read(MAX_PACKAGE + 1)
        if len(content) > MAX_PACKAGE:
            raise ValueError(slug + ": ZIP exceeds safe size")
        total += len(content)
        if total > MAX_TOTAL:
            raise ValueError("Plugin ZIP collection exceeds safe total")
        verified = verify_zip(slug, content)
        for field in ("version", "size", "sha256"):
            if verified[field] != info.get(field):
                raise ValueError(slug + ": manifest does not match verified ZIP (" + field + ")")
        packages[slug] = (content, verified)
    report = {"source": "staging Git branch plugin packages",
              "directory": REPOSITORY, "count": len(packages), "sites": {}}
    for site in (SOURCE_SITE, DESTINATION_SITE):
        target = ftp_root(ftp, home, site, create=True)
        results = {}
        for slug, (content, info) in packages.items():
            name = slug + ".zip"
            state = write_atomic(ftp, target, name, content, info["sha256"])
            results[slug] = {"version": info["version"], "sha256": info["sha256"], "status": state}
            print(site, state, name, "sha256=" + info["sha256"][:12], flush=True)
        # Publish authoritative update metadata after all ZIPs on this site
        # have been written and independently verified.
        state = write_atomic(ftp, target, "manifest.json", manifest_bytes,
                             hashlib.sha256(manifest_bytes).hexdigest())
        report["sites"][site] = {"plugins": results, "manifest": state}
        print(site, "VERIFIED", len(results), "canonical plugin ZIPs", flush=True)
    return report


def run(report_file, local_packages=None):
    server = os.environ["STAGING_FTP_SERVER"]
    username = os.environ["STAGING_FTP_USERNAME"]
    password = os.environ["STAGING_FTP_PASSWORD"]
    if not all((server, username, password)):
        raise ValueError("Missing shared FTPS credentials")
    ftp = ftplib.FTP_TLS(timeout=60)
    try:
        ftp.connect(server, 21)
        ftp.login(username, password)
        ftp.prot_p()
        home = ftp.pwd()
        if local_packages:
            report = publish_built_repository(ftp, home, local_packages)
        else:
            source = ftp_root(ftp, home, SOURCE_SITE)
            destination = ftp_root(ftp, home, DESTINATION_SITE)
            report = mirror(ftp, source, destination)
        with open(report_file, "w", encoding="utf-8") as file:
            json.dump(report, file, indent=2, ensure_ascii=False)
        ftp.quit()
    finally:
        try:
            ftp.close()
        except OSError:
            pass


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--manifest-file", default="taxocommerce-plugin-packages-report.json")
    parser.add_argument("--local-packages", help="Validated ZIPs built from staging branch")
    args = parser.parse_args()
    try:
        run(args.manifest_file, args.local_packages)
    except (ValueError, OSError, RuntimeError, *ftplib.all_errors) as exc:
        print("::error::STAGING-to-Focazul canonical plugin mirror failed: " + str(exc), file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
