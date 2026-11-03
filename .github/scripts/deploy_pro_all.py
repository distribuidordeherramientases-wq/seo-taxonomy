#!/usr/bin/env python3
"""Manually reconcile the installed TaxoCommerce plugins from STAGING to PRO.

The Git checkout is only an inventory source: FTPS receives release files from
taxocommerce-*/ into the corresponding existing wp-content/plugins/ directory.
Unavailable plugins are explicitly skipped, not installed and not allowed to
block updates of independent plugins. No Git metadata, tests, FTP state or
other repository folders are ever uploaded.
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


def probe_installed_plugin(ftp, plugins_root, slug):
    """Return (available, reason) without writing or creating any directories.

    A failed CWD or missing main file means this module cannot be safely
    updated. Other independent plugins can still be updated normally.
    """
    if not SLUG.fullmatch(slug):
        raise ValueError("Unexpected plugin name: " + slug)
    ftp.cwd(plugins_root)
    try:
        ftp.cwd(slug)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return False, "plugin_directory_missing_or_inaccessible"
        raise
    if sha256_remote(ftp, slug + ".php") is None:
        return False, "plugin_entrypoint_missing_or_inaccessible"
    return True, None

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


def write_report(report, source, error=None, skipped=None, source_count=None):
    """Clearly distinguish successful uploads, non-applicable modules and failures."""
    skipped = [] if skipped is None else skipped
    release_status = ("failed" if error else
                      "updated_available_plugins_with_skips" if skipped else "complete")
    result = {
        "source_branch": "staging",
        "source_sha": source,
        "scope": "existing_taxocommerce_plugins_only",
        "source_modules": source_count,
        "modules_verified": len(report),
        "modules_skipped": len(skipped),
        "release_status": release_status,
        "plugins": report,
        "skipped_plugins": skipped,
        "error": type(error).__name__ if error else None,
    }
    Path("pro-release-manifest.json").write_text(
        json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    summary_path = os.environ.get("GITHUB_STEP_SUMMARY", "")
    if summary_path:
        lines = ["## TaxoCommerce STAGING -> PRO", "", "Commit: `" + source + "`",
                 "", "Source plugins: " + str(source_count),
                 "Modules verified: " + str(len(report)),
                 "Modules not available on PRO: " + str(len(skipped)), ""]
        for row in report:
            lines.append("- `" + row["slug"] + "`: " + str(row["updated"]) +
                         " updated, " + str(row["unchanged"]) + " unchanged")
        for row in skipped:
            lines.append("- SKIPPED `" + row["slug"] + "`: " + row["reason"])
        if skipped:
            lines.extend(["", "**Partial scope:** only existing accessible plugins were updated. "
                          "No absent plugin was created."])
        if error:
            lines.extend(["", "**FAILURE:** " + type(error).__name__ +
                          " (see job log; publication incomplete)"])
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
    skipped = []
    error = None
    try:
        ftp.connect(os.environ["FTP_SERVER"], 21)
        ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
        ftp.prot_p()
        root = enter_wp_plugins_root(ftp)
        for slug, files in modules.items():
            available, reason = probe_installed_plugin(ftp, root, slug)
            if not available:
                skipped.append({"slug": slug, "reason": reason})
                print("::warning::PRO plugin skipped (absent or inaccessible): " +
                      slug + " [" + reason + "]", flush=True)
                continue
            reconcile_one(ftp, root, slug, files, report)
        covered = {row["slug"] for row in report} | {row["slug"] for row in skipped}
        if covered != set(modules):
            raise RuntimeError("Incomplete PRO release inventory")
        ftp.quit()
        print("Verified " + str(len(report)) + " available TaxoCommerce plugins, " +
              str(len(skipped)) + " skipped; no other folders touched", flush=True)
    except Exception as exc:
        error = exc
        try:
            ftp.close()
        except Exception:
            pass
    finally:
        write_report(report, source, error, skipped, len(modules))
    if error:
        raise RuntimeError("PRO transfer incomplete: " + str(error)) from error


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.CalledProcessError, *ftplib.all_errors) as exc:
        print("::error::" + str(exc), file=sys.stderr)
        sys.exit(1)
