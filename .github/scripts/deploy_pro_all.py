#!/usr/bin/env python3
"""Manually update ONLY installed TaxoCommerce plugins in PRO.

The local Git checkout is for selecting published plugin files; nothing outside
taxocommerce-*/ is ever sent over FTPS. The remote target is wp-content/plugins,
never the Git repository, its .git metadata or the legacy seo-taxonomy plugin.
No REST inventory, deletions or FTP sync-state files.
"""
import ftplib
import hashlib
import json
import os
import posixpath
from pathlib import Path
import re
import ssl
import subprocess
import sys

SLUG = re.compile(r"taxocommerce-[a-z0-9-]+\Z")
EXCLUDED_DIRS = frozenset({"tests", "uploads", "node_modules", "__pycache__"})
EXCLUDED_SUFFIXES = (".bak", ".md", ".pot", ".log", ".pyc", ".orig", ".rej", ".tmp")


def distributable(path):
    """Return (plugin slug, safe relative path) for a tracked release file."""
    parts = path.split("/")
    if len(parts) < 2 or not SLUG.fullmatch(parts[0]):
        return None
    if any(not part or part.startswith(".") or part in EXCLUDED_DIRS for part in parts[1:]):
        return None
    if parts[-1].lower().endswith(EXCLUDED_SUFFIXES):
        return None
    return parts[0], "/".join(parts[1:])


def release_inventory():
    """Every independent plugin in the checked-out staging commit, not a site inventory."""
    raw = subprocess.check_output(["git", "ls-files", "-z", "--", "taxocommerce-*"])
    modules = {}
    for raw_path in raw.split(b"\0"):
        if not raw_path:
            continue
        path = raw_path.decode("utf-8", "strict")
        parsed = distributable(path)
        if not parsed:
            continue
        slug, relative = parsed
        source = Path(path)
        if not source.is_file() or source.is_symlink():
            raise ValueError("Missing or unsafe tracked source: " + path)
        modules.setdefault(slug, []).append(relative)
    if not modules:
        raise ValueError("No independent TaxoCommerce plugin found; refuse deployment")
    for slug, files in modules.items():
        if slug + ".php" not in files or "readme.txt" not in files:
            raise ValueError("Plugin lacks its tracked entrypoint or readme.txt: " + slug)
        # Main entrypoint last, after the dependencies it may include.
        modules[slug] = sorted(files, key=lambda f: (f == slug + ".php", f))
    return dict(sorted(modules.items()))


def sha256_remote(ftp, filename):
    digest = hashlib.sha256()
    try:
        ftp.retrbinary("RETR " + filename, digest.update)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return None
        raise
    return digest.hexdigest()


def enter_wp_plugins_root(ftp):
    """Locate the actual WordPress plugins folder; never depend on seo-taxonomy.

    FTPS accounts can start in the WordPress root, public_html, plugins itself
    or an individual plugin folder. Fail closed if the installed Core main file
    is not present. PRO_FTP_PLUGINS_DIR can override ambiguous FTP roots.
    """
    origin = ftp.pwd()
    explicit = os.environ.get("PRO_FTP_PLUGINS_DIR", "").strip()
    if explicit:
        if "\\x00" in explicit or "\\" in explicit or ".." in explicit.split("/"):
            raise RuntimeError("Unsafe PRO_FTP_PLUGINS_DIR")
        candidates = [explicit]
    else:
        parent = posixpath.dirname(origin.rstrip("/")) or "/"
        candidates = [
            posixpath.join(origin, "wp-content", "plugins"),
            posixpath.join(origin, "public_html", "wp-content", "plugins"),
            posixpath.join(origin, "plugins"),
            origin,
            parent,
            posixpath.join(parent, "wp-content", "plugins"),
            posixpath.join(parent, "public_html", "wp-content", "plugins"),
        ]

    visited = set()
    for candidate in candidates:
        if candidate in visited:
            continue
        visited.add(candidate)
        try:
            ftp.cwd(candidate)
            ftp.cwd("taxocommerce-core")
            header = bytearray()
            ftp.retrbinary("RETR taxocommerce-core.php", header.extend)
            if b"Plugin Name:" not in header or b"TaxoCommerce Core" not in header:
                continue
            ftp.cwd(candidate)
            return ftp.pwd()
        except ftplib.error_perm:
            continue
    raise RuntimeError(
        "No se localiza el directorio de plugins de PRO con TaxoCommerce Core "
        "(comprueba el directorio FTP o PRO_FTP_PLUGINS_DIR). "
        "No se ha subido ningun archivo."
    )


def verify_installed_plugins(ftp, plugins_root, modules):
    """Preflight all release targets BEFORE uploading the first file.

    Prevent accidental installation of an absent plugin or a partial release.
    Every requested taxocommerce-* folder must contain its own main PHP file.
    """
    for slug in modules:
        if not SLUG.fullmatch(slug):
            raise ValueError("Unexpected plugin name: " + slug)
        ftp.cwd(plugins_root)
        try:
            ftp.cwd(slug)
        except ftplib.error_perm as exc:
            raise RuntimeError("Plugin is absent on PRO: " + slug) from exc
        if sha256_remote(ftp, slug + ".php") is None:
            raise RuntimeError("Main plugin file is absent on PRO: " + slug)
    ftp.cwd(plugins_root)


def enter_or_create(ftp, directory):
    try:
        ftp.cwd(directory)
    except ftplib.error_perm as exc:
        if not str(exc).startswith("550"):
            raise
        ftp.mkd(directory)
        ftp.cwd(directory)


def reconcile_one(ftp, plugins_root, slug, files, report):
    if not SLUG.fullmatch(slug):
        raise ValueError("Unexpected plugin name: " + slug)
    if any(distributable(slug + "/" + relative) != (slug, relative) for relative in files):
        raise ValueError("Refusing to upload a non-release file for " + slug)
    ftp.cwd(plugins_root)
    ftp.cwd(slug)
    plugin_root = ftp.pwd()
    rows = {"slug": slug, "tracked_files": len(files), "updated": 0, "unchanged": 0, "files": []}
    for relative in files:
        path = Path(slug) / relative
        expected = hashlib.sha256(path.read_bytes()).hexdigest()
        ftp.cwd(plugin_root)
        components = relative.split("/")
        for directory in components[:-1]:
            enter_or_create(ftp, directory)
        name = components[-1]
        if sha256_remote(ftp, name) == expected:
            rows["unchanged"] += 1
            rows["files"].append({"path": relative, "sha256": expected, "status": "unchanged"})
            continue
        temporary = ".taxocommerce-upload-" + hashlib.sha256((slug + "/" + relative).encode()).hexdigest()[:20] + ".tmp"
        try:
            with path.open("rb") as source:
                ftp.storbinary("STOR " + temporary, source)
            if sha256_remote(ftp, temporary) != expected:
                raise RuntimeError("Temporary upload SHA-256 mismatch: " + slug + "/" + relative)
            ftp.rename(temporary, name)
            if sha256_remote(ftp, name) != expected:
                raise RuntimeError("Final remote SHA-256 mismatch: " + slug + "/" + relative)
        finally:
            try:
                ftp.delete(temporary)
            except ftplib.all_errors:
                pass
        rows["updated"] += 1
        rows["files"].append({"path": relative, "sha256": expected, "status": "updated"})
    report.append(rows)
    print(slug + ": " + str(rows["updated"]) + " updated, " + str(rows["unchanged"]) + " unchanged", flush=True)


def write_report(report, source, error=None):
    result = {"source_branch": "staging", "source_sha": source,
              "modules_verified": len(report), "plugins": report, "error": type(error).__name__ if error else None}
    Path("pro-release-manifest.json").write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    summary_path = os.environ.get("GITHUB_STEP_SUMMARY", "")
    if summary_path:
        lines = ["## TaxoCommerce STAGING -> PRO", "", "Commit: `" + source + "`",
                 "", "Modules verified: " + str(len(report)), ""]
        for row in report:
            lines.append("- `" + row["slug"] + "`: " + str(row["updated"]) + " updated, " + str(row["unchanged"]) + " unchanged")
        if error:
            lines.extend(["", "**FAILURE:** " + type(error).__name__ + " (see job log; publication incomplete)"])
        with open(summary_path, "a", encoding="utf-8") as out:
            out.write("\n".join(lines) + "\n")


def main():
    source = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()
    if not re.fullmatch(r"[0-9a-f]{40}", source):
        raise ValueError("Not a checked-out Git commit")
    if os.environ.get("GITHUB_REF_NAME") != "staging":
        raise RuntimeError("Production deployment must be dispatched from staging")
    if os.environ.get("GITHUB_SHA") != source:
        raise RuntimeError("Checked-out commit differs from the dispatched staging SHA")
    modules = release_inventory()
    if "taxocommerce-core" not in modules:
        raise RuntimeError("Core is absent from the verified staging release")
    print("Preparing " + str(len(modules)) + " independent plugins from " + source, flush=True)
    for key in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD"):
        if not os.environ.get(key):
            raise RuntimeError("Missing PRO FTPS configuration: " + key)
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=90)
    report = []
    error = None
    try:
        ftp.connect(os.environ["FTP_SERVER"], 21)
        ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
        ftp.prot_p()
        root = enter_wp_plugins_root(ftp)
        verify_installed_plugins(ftp, root, modules)
        for slug, files in modules.items():
            reconcile_one(ftp, root, slug, files, report)
        if set(row["slug"] for row in report) != set(modules):
            raise RuntimeError("Some plugins were not verified")
        ftp.quit()
        print("All tracked TaxoCommerce release files verified in PRO FTPS", flush=True)
    except Exception as exc:
        error = exc
        try:
            ftp.close()
        except Exception:
            pass
    finally:
        write_report(report, source, error)
    if error:
        raise RuntimeError("PRO transfer incomplete: " + str(error)) from error


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.CalledProcessError, *ftplib.all_errors) as exc:
        print("::error::" + str(exc), file=sys.stderr)
        sys.exit(1)
