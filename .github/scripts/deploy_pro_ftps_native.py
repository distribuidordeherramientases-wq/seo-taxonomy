#!/usr/bin/env python3
"""Standalone TaxoCommerce production transport over verified FTPS.

No monolith or WordPress REST custom endpoint is used. Only changed files of
physically installed plugins are eligible. The FTPS root must first match TWO
independently verified production plugin versions.
"""
from __future__ import annotations

import ftplib
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import ssl
import sys

from pro_ftps_root_probe import find_verified_root, read_plugin_version

SLUG = re.compile(r"taxocommerce-[a-z0-9-]+\Z")
SAFE_PART = re.compile(r"[a-zA-Z0-9_.+-]+\Z")
BLOCKED_SEGMENTS = frozenset({"tests", "uploads", "node_modules", ".git", "cache", "logs", "backup"})
MAX_SIZE = 32 * 1024 * 1024


def target_is_safe(slug: str, name: str) -> bool:
    if not isinstance(slug, str) or not SLUG.fullmatch(slug):
        return False
    if not isinstance(name, str) or not name or name.startswith("/") or "\\" in name:
        return False
    parts = PurePosixPath(name).parts
    if not parts or any(not SAFE_PART.fullmatch(p) or p in (".", "..") for p in parts):
        return False
    if any(p.lower() in BLOCKED_SEGMENTS or p.startswith(".") for p in parts):
        return False
    if name.endswith((".bak", ".log", ".pyc", ".pot", ".zip")):
        return False
    return True


def connect():
    required = ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD",
                "PRO_CORE_EXPECTED_VERSION", "PRO_WOO_EXPECTED_VERSION")
    if any(not os.environ.get(k) for k in required):
        raise RuntimeError("FTPS credentials or verified production versions are missing")
    ftps = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=30)
    ftps.connect(os.environ["FTP_SERVER"], 21)
    ftps.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
    ftps.prot_p()
    expected = {
        "PRO_CORE_EXPECTED_VERSION": os.environ["PRO_CORE_EXPECTED_VERSION"],
        "PRO_WOO_EXPECTED_VERSION": os.environ["PRO_WOO_EXPECTED_VERSION"],
    }
    _, root = find_verified_root(ftps, expected)
    ftps.cwd(root)
    return ftps, root


def installed_modules(ftp, root: str):
    """Installed means plugin folder + canonical main PHP file, active or inactive."""
    ftp.cwd(root)
    try:
        entries = list(ftp.mlsd())
    except ftplib.Error as exc:
        raise RuntimeError("Verified WordPress root does not permit plugin inventory") from exc
    modules = []
    for name, props in entries:
        if props.get("type") != "dir" or not SLUG.fullmatch(name):
            continue
        try:
            read_plugin_version(ftp, name + "/" + name + ".php")
        except (ftplib.Error, OSError, ValueError):
            continue
        modules.append(name)
    return sorted(set(modules))


def load_delta(path: str, installed: list[str]):
    source = Path(path)
    if not source.is_file() or source.stat().st_size > 2 * 1024 * 1024:
        raise ValueError("Missing or excessive selected-delta JSON")
    delta = json.loads(source.read_text(encoding="utf-8"))
    if not isinstance(delta, dict) or not delta:
        raise ValueError("Expected a nonempty selected-delta object")
    if len(delta) > 50:
        raise ValueError("Excessive plugin selection")
    output = {}
    for slug, paths in delta.items():
        if slug not in installed or not isinstance(paths, list) or not paths or len(paths) > 1500:
            raise ValueError("Uninstalled plugin or invalid changed-file list")
        if len(set(paths)) != len(paths):
            raise ValueError("Repeated changed path")
        for name in paths:
            if not target_is_safe(slug, name):
                raise ValueError("Unsafe deployable path")
            local = Path(slug, *PurePosixPath(name).parts)
            if not local.is_file() or local.is_symlink() or local.stat().st_size > MAX_SIZE:
                raise ValueError("Missing/invalid local plugin file")
        output[slug] = sorted(paths)
    return output


def read_remote(ftp, path: str):
    buf = bytearray()
    try:
        def append(chunk):
            if len(buf) + len(chunk) > MAX_SIZE:
                raise ValueError("Remote file exceeds size limit")
            buf.extend(chunk)
        ftp.retrbinary("RETR " + path, append, blocksize=65536)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return None
        raise
    return bytes(buf)


def ensure_parent(ftp, root: str, path: str):
    """Create only subdirectories within an already installed plugin folder."""
    parts = path.split("/")[:-1]
    current = root
    for part in parts:
        if not SAFE_PART.fullmatch(part) or part.startswith("."):
            raise ValueError("Invalid destination directory")
        current = current.rstrip("/") + "/" + part
        try:
            ftp.cwd(current)
        except ftplib.error_perm as exc:
            if not str(exc).startswith("550"):
                raise
            ftp.mkd(current)
            ftp.cwd(current)
    ftp.cwd(root)


def deploy_one(ftp, root: str, path: str, content: bytes, suffix: str):
    """Stage+verify, rename current file to reversible backup, commit+verify."""
    ftp.cwd(root)
    existing = read_remote(ftp, path)
    wanted = hashlib.sha256(content).hexdigest()
    if existing is not None and hashlib.sha256(existing).hexdigest() == wanted:
        return "unchanged"
    if len(content) > MAX_SIZE:
        raise ValueError("Oversized local file")
    ensure_parent(ftp, root, path)
    # Temporary paths are inside this exact installed plugin, not web uploads.
    parent, _, base = path.rpartition("/")
    temporary = parent + "/.tc-upload-" + suffix + "-" + base
    backup = parent + "/.tc-backup-" + suffix + "-" + base
    staged = False
    moved_old = False
    committed = False
    try:
        ftp.storbinary("STOR " + temporary, io.BytesIO(content), blocksize=65536)
        staged = True
        uploaded = read_remote(ftp, temporary)
        if uploaded is None or hashlib.sha256(uploaded).hexdigest() != wanted:
            raise RuntimeError("FTPS temporary file checksum mismatch")
        if existing is not None:
            ftp.rename(path, backup)
            moved_old = True
        ftp.rename(temporary, path)
        staged = False
        committed = True
        remote = read_remote(ftp, path)
        if remote is None or hashlib.sha256(remote).hexdigest() != wanted:
            raise RuntimeError("FTPS destination checksum mismatch")
        if moved_old:
            ftp.delete(backup)  # Only our own verified temporary backup.
            moved_old = False
        return "updated"
    except Exception:
        if committed:
            try:
                ftp.delete(path)  # Only newly uploaded content, while rolling back.
            except ftplib.Error:
                pass
        if moved_old:
            try:
                ftp.rename(backup, path)
            except ftplib.Error:
                raise RuntimeError("Rollback could not restore original plugin file") from None
        raise
    finally:
        if staged:
            try:
                ftp.delete(temporary)
            except ftplib.Error:
                pass


def main():
    mode = sys.argv[1] if len(sys.argv) >= 2 else ""
    if mode not in ("inventory", "apply"):
        raise ValueError("Usage: deploy_pro_ftps_native.py inventory|apply [delta.json]")
    ftp, root = connect()
    try:
        installed = installed_modules(ftp, root)
        if not installed:
            raise RuntimeError("No TaxoCommerce plugins found at verified WordPress plugin root")
        if mode == "inventory":
            # GitHub step output, never expose server paths or credentials.
            print("modules=" + json.dumps(installed, separators=(",", ":")))
            print(f"Verified installed-plugin inventory: {len(installed)} modules", file=sys.stderr)
            return
        if len(sys.argv) != 3:
            raise ValueError("Missing delta JSON path")
        delta = load_delta(sys.argv[2], installed)
        suffix = secrets.token_hex(8)
        for slug, paths in delta.items():
            updated = 0
            unchanged = 0
            for name in paths:
                path = slug + "/" + name
                content = Path(slug, *PurePosixPath(name).parts).read_bytes()
                status = deploy_one(ftp, root, path, content, suffix)
                updated += status == "updated"
                unchanged += status == "unchanged"
            expected_header = read_plugin_version(ftp, slug + "/" + slug + ".php")
            local_header = re.search(
                r"(?mi)^\s*\*\s*Version:\s*([^\s]+)",
                Path(slug, slug + ".php").read_text(encoding="utf-8")
            )
            if not local_header or expected_header != local_header.group(1):
                raise RuntimeError("Remote plugin version does not match selected source")
            print(f"{slug}: {updated} updated; {unchanged} unchanged; "
                  f"{len(paths)} checksummed files; version {expected_header}")
        print("PRO deployment completed using standalone, verified FTPS transport.")
    finally:
        try:
            ftp.quit()
        except ftplib.Error:
            ftp.close()


if __name__ == "__main__":
    try:
        main()
    except (ftplib.Error, OSError, RuntimeError, ValueError, KeyError, TypeError) as exc:
        # Avoid leaking host/username/password if an FTP server returns its own path.
        text = str(exc) if isinstance(exc, (RuntimeError, ValueError)) else type(exc).__name__
        print("::error::Standalone PRO deploy blocked: " + text[:300], file=sys.stderr)
        sys.exit(1)
