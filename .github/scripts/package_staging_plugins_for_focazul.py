#!/usr/bin/env python3
"""Package *installed STAGING* TaxoCommerce plugins and upload ZIPs to Focazul uploads.

Uses the existing shared FTPS account, reads STAGING/wp-content/plugins, writes
only Focazul/wp-content/uploads/taxocommerce-plugin-zips. Does not install,
activate, delete plugin files, copy options, or modify WordPress databases.
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
import tempfile
import time
import zipfile
from pathlib import PurePosixPath

SOURCE_SITE = "staging.distribuidordeherramientas.es"
DESTINATION_SITE = "asociacionfocazul"
PACKAGE_DIRECTORY = "taxocommerce-plugin-zips"
SLUG = re.compile(r"^taxocommerce-[a-z0-9-]+$")
IGNORE_DIRS = {"tests", "test", "__pycache__", "node_modules", "uploads", "cache", "logs", "tmp", ".git", ".svn"}
IGNORE_SUFFIXES = (".bak", ".tmp", ".log", ".pyc", ".swp", "~")
MAX_MODULES = 50
MAX_LEVELS = 18
MAX_FILES = 3000
MAX_FILE_BYTES = 64 * 1024 * 1024
MAX_MODULE_BYTES = 256 * 1024 * 1024
MAX_TOTAL_BYTES = 1024 * 1024 * 1024


def allowed_component(name, directory=False):
    if not isinstance(name, str) or name in (".", "..") or not name or name.startswith("."):
        return False
    if any(c in name for c in ("/", "\\", "\r", "\n", "\x00")):
        return False
    if directory:
        return name.lower() not in IGNORE_DIRS
    return not name.lower().endswith(IGNORE_SUFFIXES)


def normalize_name(value):
    name = str(value).rstrip("/").rsplit("/", 1)[-1]
    if name in (".", ".."):
        return ""
    if not name or any(c in name for c in ("/", "\\", "\r", "\n", "\x00")):
        raise ValueError("Unexpected FTP listing name")
    return name


def list_entries(ftp, remote):
    """Return name/type tuples; use NLST if the host does not implement MLSD."""
    ftp.cwd(remote)
    try:
        records = list(ftp.mlsd())
        result = []
        for raw_name, facts in records:
            name = normalize_name(raw_name)
            if not name:
                continue
            kind = str(facts.get("type", "")).lower()
            if kind in ("cdir", "pdir"):
                continue
            if kind.startswith("os.unix=slink"):
                continue
            if kind not in ("dir", "file"):
                continue
            result.append((name, kind))
        return sorted(set(result))
    except (AttributeError, ftplib.error_perm, ftplib.error_reply):
        ftp.cwd(remote)
        try:
            names = ftp.nlst()
        except ftplib.error_perm as exc:
            if str(exc).startswith("550"):
                return []
            raise
        result = []
        for value in names:
            name = normalize_name(value)
            if not name:
                continue
            # Disallow links traversing out of the source tree even on NLST hosts.
            kind = "file"
            try:
                ftp.cwd(remote.rstrip("/") + "/" + name)
                if not ftp.pwd().startswith(remote.rstrip("/") + "/"):
                    raise ValueError("FTP link points outside the source directory")
                kind = "dir"
            except ftplib.error_perm as exc:
                if not str(exc).startswith("550"):
                    raise
            finally:
                ftp.cwd(remote)
            result.append((name, kind))
        return sorted(set(result))


def inventory(ftp, plugins_root):
    plugins = []
    for name, kind in list_entries(ftp, plugins_root):
        if kind != "dir" or not SLUG.fullmatch(name):
            continue
        source = plugins_root.rstrip("/") + "/" + name
        if (name + ".php", "file") not in list_entries(ftp, source):
            raise ValueError("STAGING plugin missing main PHP entrypoint: " + name)
        plugins.append(name)
    if not plugins or len(plugins) > MAX_MODULES:
        raise ValueError("Unexpected number of installed TaxoCommerce plugins: " + str(len(plugins)))
    return sorted(plugins)


def plugin_files(ftp, plugin_root):
    """Produce an installable source manifest; hidden and runtime debris are excluded."""
    found = []

    def visit(relative, depth):
        if depth > MAX_LEVELS:
            raise ValueError("Unexpected plugin directory depth")
        folder = plugin_root.rstrip("/") + ("/" + relative if relative else "")
        for name, kind in list_entries(ftp, folder):
            rel = relative + "/" + name if relative else name
            if kind == "dir":
                if allowed_component(name, directory=True):
                    visit(rel, depth + 1)
            elif kind == "file" and allowed_component(name):
                found.append(rel)
                if len(found) > MAX_FILES:
                    raise ValueError("Unexpected number of plugin files")
    visit("", 0)
    return sorted(found)


def retrieve(ftp, remote, callback):
    ftp.retrbinary("RETR " + remote, callback, blocksize=64 * 1024)


def plugin_version(ftp, plugin_root, slug):
    content = io.BytesIO()
    retrieve(ftp, plugin_root.rstrip("/") + "/" + slug + ".php", content.write)
    sample = content.getvalue()[:8192].decode("utf-8", errors="replace")
    value = re.search(r"^\s*\*\s*Version:\s*(.+?)\s*$", sample, re.MULTILINE)
    return value.group(1).strip() if value else "unknown"


def build_package(ftp, plugin_root, slug, files):
    """Stream all files into a reproducible, installable plugin-root ZIP."""
    if slug + ".php" not in files:
        raise ValueError("Missing installable plugin entrypoint in package")
    archive = tempfile.TemporaryFile(mode="w+b")
    file_hashes = {}
    total = 0
    try:
        with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6, allowZip64=True) as zipout:
            for relative in files:
                # Validation protects zip extraction from path traversal.
                parts = PurePosixPath(relative).parts
                if not parts or any(not allowed_component(part, directory=(i != len(parts) - 1))
                                    for i, part in enumerate(parts)):
                    raise ValueError("Unsafe filename in plugin: " + relative)
                entry = zipfile.ZipInfo(slug + "/" + relative, date_time=(2020, 1, 1, 0, 0, 0))
                entry.compress_type = zipfile.ZIP_DEFLATED
                entry.external_attr = 0o644 << 16
                digest = hashlib.sha256()
                nbytes = 0
                with zipout.open(entry, "w", force_zip64=True) as stream:
                    def collect(chunk):
                        nonlocal nbytes, total
                        nbytes += len(chunk)
                        total += len(chunk)
                        if nbytes > MAX_FILE_BYTES or total > MAX_MODULE_BYTES:
                            raise ValueError("Plugin exceeded safe package size limits")
                        stream.write(chunk)
                        digest.update(chunk)
                    retrieve(ftp, plugin_root.rstrip("/") + "/" + relative, collect)
                file_hashes[relative] = digest.hexdigest()
        archive.seek(0)
        with zipfile.ZipFile(archive, "r") as zin:
            if zin.testzip() is not None:
                raise ValueError("Invalid ZIP CRC for " + slug)
            names = zin.namelist()
            if len(names) != len(files) or slug + "/" + slug + ".php" not in names:
                raise ValueError("Incomplete WordPress plugin ZIP")
        archive.seek(0)
        zip_sha = hashlib.sha256()
        while True:
            data = archive.read(1024 * 1024)
            if not data:
                break
            zip_sha.update(data)
        archive.seek(0, 2)
        size = archive.tell()
        archive.seek(0)
        return archive, {"slug": slug, "version": plugin_version(ftp, plugin_root, slug),
                         "files": len(files), "uncompressed_bytes": total,
                         "zip_bytes": size, "sha256": zip_sha.hexdigest(),
                         "source_file_sha256": file_hashes}
    except Exception:
        archive.close()
        raise


def remote_hash(ftp, filename):
    h = hashlib.sha256()
    try:
        retrieve(ftp, filename, h.update)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return None
        raise
    return h.hexdigest()


def store_verified(ftp, folder, filename, stream, digest):
    """Append an immutable checksum-named archive, never overwrite other files."""
    ftp.cwd(folder)
    if remote_hash(ftp, filename) == digest:
        return "UNCHANGED"
    name = ".tc-zips-upload-" + digest[:20] + ".tmp"
    stream.seek(0)
    try:
        ftp.storbinary("STOR " + name, stream, blocksize=64 * 1024)
        if remote_hash(ftp, name) != digest:
            raise RuntimeError("FTPS uploaded ZIP failed SHA-256 verification: " + filename)
        if remote_hash(ftp, filename) is not None:
            raise RuntimeError("Checksum-name collision; refusing to overwrite: " + filename)
        ftp.rename(name, filename)
        if remote_hash(ftp, filename) != digest:
            raise RuntimeError("Remote ZIP failed post-rename SHA-256 check: " + filename)
        return "UPDATED"
    finally:
        try:
            ftp.delete(name)
        except ftplib.all_errors:
            pass


def destination_folder(ftp, home):
    ftp.cwd(home)
    ftp.cwd("./" + DESTINATION_SITE + "/wp-content/uploads")
    parent = ftp.pwd()
    try:
        ftp.cwd(PACKAGE_DIRECTORY)
    except ftplib.error_perm as exc:
        if not str(exc).startswith("550"):
            raise
        ftp.mkd(PACKAGE_DIRECTORY)
        ftp.cwd(PACKAGE_DIRECTORY)
    folder = ftp.pwd()
    if folder != parent.rstrip("/") + "/" + PACKAGE_DIRECTORY:
        raise ValueError("Unexpected destination path; refusing upload")
    return folder


def run(report_file):
    host = os.environ["STAGING_FTP_SERVER"]
    user = os.environ["STAGING_FTP_USERNAME"]
    password = os.environ["STAGING_FTP_PASSWORD"]
    if not all((host, user, password)):
        raise ValueError("Missing shared staging FTPS configuration")
    ftp = ftplib.FTP_TLS(timeout=60)
    started = datetime.datetime.now(datetime.timezone.utc)
    all_total = 0
    report = {"generated_at_utc": started.isoformat(), "source_site": SOURCE_SITE,
              "destination_site": DESTINATION_SITE,
              "directory": "wp-content/uploads/" + PACKAGE_DIRECTORY + "/",
              "packages": []}
    try:
        ftp.connect(host, 21)
        ftp.login(user, password)
        ftp.prot_p()
        home = ftp.pwd()
        ftp.cwd(home)
        ftp.cwd("./" + SOURCE_SITE + "/wp-content/plugins")
        plugin_root = ftp.pwd()
        slugs = inventory(ftp, plugin_root)
        dest = destination_folder(ftp, home)
        # Never create a directory listing of installable source packages.
        if remote_hash(ftp, "index.html") is None:
            marker = b"<!doctype html><html><head><meta charset='utf-8'><title>Packages</title></head><body></body></html>\n"
            ftp.storbinary("STOR index.html", io.BytesIO(marker))
        print("STAGING TaxoCommerce plugin folders found:", len(slugs))
        for slug in slugs:
            root = plugin_root.rstrip("/") + "/" + slug
            files = plugin_files(ftp, root)
            zipstream, details = build_package(ftp, root, slug, files)
            try:
                all_total += details["uncompressed_bytes"]
                if all_total > MAX_TOTAL_BYTES:
                    raise ValueError("All plugin sources exceed safe total size limit")
                zipname = slug + "-" + details["sha256"][:16] + ".zip"
                state = store_verified(ftp, dest, zipname, zipstream, details["sha256"])
                details["filename"] = zipname
                details["result"] = state
                # Avoid exposing complete internal filenames and hashes in the public manifest.
                del details["source_file_sha256"]
                report["packages"].append(details)
                print(state, zipname, "files=", details["files"], "sha256=", details["sha256"])
            finally:
                zipstream.close()
        report["total_packages"] = len(report["packages"])
        data = (json.dumps(report, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
        digest = hashlib.sha256(data).hexdigest()
        # The only mutable artifact is the summary: all packages have been verified first.
        ftp.cwd(dest)
        ftp.storbinary("STOR taxocommerce-packages-manifest.json", io.BytesIO(data))
        if remote_hash(ftp, "taxocommerce-packages-manifest.json") != digest:
            raise RuntimeError("Manifest verification failed")
        with open(report_file, "w", encoding="utf-8") as out:
            json.dump(report, out, indent=2, ensure_ascii=False)
        print("SUCCESS: verified", len(slugs), "STAGING plugin ZIPs in Focazul uploads")
        ftp.quit()
    finally:
        try:
            ftp.close()
        except OSError:
            pass


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--manifest-file", default="taxocommerce-packages-report.json")
    args = parser.parse_args()
    try:
        run(args.manifest_file)
    except (ValueError, KeyError, OSError, RuntimeError, zipfile.BadZipFile, *ftplib.all_errors) as exc:
        print("::error::Staging-to-Focazul plugin ZIP copy failed: " + str(exc), file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
