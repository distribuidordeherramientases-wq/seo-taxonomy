#!/usr/bin/env python3
"""Manually reconcile the installed TaxoCommerce plugins from STAGING to PRO.

The Git checkout is only an inventory source: FTPS receives release files from
taxocommerce-*/ into the corresponding existing wp-content/plugins/ directory.
Only plugins proven absent from the verified PRO installation may be
skipped. Listed but inaccessible plugins and ambiguous roots are blocking
preflight errors BEFORE the first upload. No Git metadata, tests, FTP state
or other repository folders are ever uploaded.
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


def visible_names(ftp):
    """Read a directory listing and normalize bare or absolute NLST entries."""
    names = set()
    for entry in ftp.nlst():
        name = posixpath.basename(str(entry).rstrip("/"))
        if name not in ("", ".", ".."):
            names.add(name)
    return names


def candidate_plugin_roots(ftp):
    """Discover WordPress paths without assuming the FTPS account's login root."""
    origin = ftp.pwd()
    configured = os.environ.get("PRO_FTP_PLUGINS_DIR", "").strip()
    if configured:
        if "\\x00" in configured or "\\" in configured or ".." in configured.split("/"):
            raise RuntimeError("Unsafe PRO_FTP_PLUGINS_DIR")
        return [configured]

    anchors = []
    cursor = origin
    for _ in range(6):
        if cursor in anchors:
            break
        anchors.append(cursor)
        parent = posixpath.dirname(cursor.rstrip("/")) or "/"
        if parent == cursor:
            break
        cursor = parent

    candidates = []
    for anchor in anchors:
        candidates.extend([
            posixpath.join(anchor, "wp-content", "plugins"),
            posixpath.join(anchor, "public_html", "wp-content", "plugins"),
            posixpath.join(anchor, "plugins"),
            anchor,
        ])

        # A Webempresa FTP account may see multiple domain roots. Resolve
        # actual site directories rather than trusting the first Core found.
        try:
            ftp.cwd(anchor)
            children = visible_names(ftp)
        except ftplib.all_errors:
            continue
        for child in sorted(children):
            if (child == "public_html" or child.startswith("hosting")
                    or any(child.endswith(suffix) for suffix in (".es", ".com", ".site", ".net"))):
                if child.startswith(".") or "/" in child or "\\" in child:
                    continue
                candidates.append(posixpath.join(anchor, child, "wp-content", "plugins"))

    return list(dict.fromkeys(candidates))


def enter_wp_plugins_root(ftp, modules=None):
    """Select a root only after listing remote directories and verifying Core.

    Return (root, visible plugin directory names). When multiple WordPress
    installations are reachable choose the one with the most matching
    TaxoCommerce directories. Never mistake a three-plugin installation for
    the full production installation.
    """
    expected = set(modules or {"taxocommerce-core"})
    options = []
    candidates = candidate_plugin_roots(ftp)
    for candidate in candidates:
        try:
            ftp.cwd(candidate)
            names = visible_names(ftp)
            if "taxocommerce-core" not in names:
                continue
            ftp.cwd("taxocommerce-core")
            header = bytearray()
            ftp.retrbinary("RETR taxocommerce-core.php", header.extend)
            if b"Plugin Name:" not in header or b"TaxoCommerce Core" not in header:
                continue
            ftp.cwd(candidate)
            actual = ftp.pwd()
            options.append((len(expected & names), actual, names))
        except ftplib.all_errors:
            continue

    if not options:
        raise RuntimeError(
            "FTPS cannot locate a WordPress plugins directory containing a "
            "readable TaxoCommerce Core. No files uploaded. Verify FTP account "
            "root and permissions, or set PRO_FTP_PLUGINS_DIR."
        )

    # This site's PRO WordPress folder was confirmed in the Webempresa
    # file-manager screenshot. If that folder is visible in the FTP account,
    # never accidentally deploy to another WordPress instance with more
    # TaxoCommerce modules (for example, STAGING).
    target_site = os.environ.get(
        "PRO_FTP_SITE_DIR", "hosting160568eu-279554.webempresa.site"
    ).strip()
    if target_site and (
        "/" in target_site or "\\" in target_site or target_site.startswith(".")
    ):
        raise RuntimeError("Unsafe PRO_FTP_SITE_DIR")
    if target_site and not os.environ.get("PRO_FTP_PLUGINS_DIR"):
        marker = "/" + target_site + "/"
        target_was_listed = any(marker in candidate for candidate in candidates)
        target_options = [option for option in options if marker in option[1]]
        if target_was_listed and not target_options:
            raise RuntimeError(
                "The expected PRO WordPress directory " + target_site
                + " appears in the FTP directory listing, but cannot be "
                "accessed. Refusing to deploy to a different installation."
            )
        if target_options:
            options = target_options
        elif len({option[1] for option in options}) > 1:
            raise RuntimeError(
                "Several WordPress installations are reachable by FTP but "
                "the PRO site was not identified. Set PRO_FTP_PLUGINS_DIR; "
                "no files uploaded."
            )

    options.sort(key=lambda item: (-item[0], item[1]))
    score, root, names = options[0]
    if len(expected) >= 10 and score < (len(expected) * 4 + 4) // 5:
        raise RuntimeError(
            "PRO FTPS root mismatch or insufficient FTP visibility: "
            + root + " lists " + str(score) + "/" + str(len(expected))
            + " requested TaxoCommerce directories. Visible: "
            + ", ".join(sorted(expected & names))
            + ". No files uploaded. Compare with the Webempresa PRO site root "
            "or configure PRO_FTP_PLUGINS_DIR."
        )
    ties = {path for matched, path, _ in options if matched == score}
    if len(ties) > 1:
        raise RuntimeError(
            "Several WordPress plugin directories match equally: "
            + ", ".join(sorted(ties))
            + ". Set PRO_FTP_PLUGINS_DIR to the correct PRO location; "
            "no files uploaded."
        )
    print("PRO FTPS selected root: " + root + " ("
          + str(score) + "/" + str(len(expected))
          + " TaxoCommerce plugin folders visible)", flush=True)
    return root, names


def probe_installed_plugin(ftp, plugins_root, slug, visible):
    """Distinguish an absent plugin from a listed-but-inaccessible directory."""
    if not SLUG.fullmatch(slug):
        raise ValueError("Unexpected plugin name: " + slug)
    if slug not in visible:
        return False, "plugin_not_present_in_selected_root"

    ftp.cwd(plugins_root)
    try:
        ftp.cwd(slug)
    except ftplib.error_perm as exc:
        raise RuntimeError(
            "FTP permission/path failure: " + slug + " is listed in "
            + plugins_root + " but CWD returned " + str(exc)
            + ". No plugin transfer should start."
        ) from exc
    if sha256_remote(ftp, slug + ".php") is None:
        raise RuntimeError(
            "FTP cannot read existing plugin entrypoint " + slug + "/"
            + slug + ".php in " + plugins_root
        )
    return True, None


def preflight_plugins(ftp, plugins_root, visible, modules):
    """Complete read-only preflight before uploading the first plugin."""
    ready, skipped = [], []
    for slug in modules:
        available, reason = probe_installed_plugin(ftp, plugins_root, slug, visible)
        if available:
            ready.append(slug)
        else:
            skipped.append({"slug": slug, "reason": reason})
    if len(modules) >= 10 and len(ready) < (len(modules) * 4 + 4) // 5:
        raise RuntimeError(
            "Cannot access enough PRO TaxoCommerce plugins (" + str(len(ready))
            + "/" + str(len(modules))
            + "); wrong FTP account, directory or permissions. "
            "No files uploaded."
        )
    print("PRO FTPS preflight: " + str(len(ready)) + " accessible, "
          + str(len(skipped)) + " genuinely absent", flush=True)
    return ready, skipped



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


def write_report(report, source, error=None, skipped=None, source_count=None, root=None, visible=None):
    """Clearly distinguish successful uploads, non-applicable modules and failures."""
    skipped = [] if skipped is None else skipped
    release_status = ("failed" if error else
                      "updated_available_plugins_with_skips" if skipped else "complete")
    result = {
        "source_branch": "staging",
        "source_sha": source,
        "scope": "existing_taxocommerce_plugins_only",
        "remote_plugins_root": root,
        "remote_visible_taxocommerce": sorted(name for name in (visible or []) if SLUG.fullmatch(name)),
        "source_modules": source_count,
        "modules_verified": len(report),
        "modules_skipped": len(skipped),
        "release_status": release_status,
        "plugins": report,
        "skipped_plugins": skipped,
        "error": type(error).__name__ if error else None,
        "error_detail": str(error) if error else None,
    }
    Path("pro-release-manifest.json").write_text(
        json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    summary_path = os.environ.get("GITHUB_STEP_SUMMARY", "")
    if summary_path:
        lines = ["## TaxoCommerce STAGING -> PRO", "", "Commit: `" + source + "`",
                 "", "Source plugins: " + str(source_count),
                 "Modules verified: " + str(len(report)),
                 "Modules not available on PRO: " + str(len(skipped)),
                 "FTPS plugin root: " + str(root or "NOT VERIFIED"),
                 "FTPS visible TaxoCommerce folders: " +
                 str(sum(bool(SLUG.fullmatch(s)) for s in (visible or []))), ""]
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
                          ": " + str(error) + " (publication incomplete)"])
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
    root = None
    visible = set()
    try:
        ftp.connect(os.environ["FTP_SERVER"], 21)
        ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
        ftp.prot_p()
        root, visible = enter_wp_plugins_root(ftp, modules)
        ready, skipped = preflight_plugins(ftp, root, visible, modules)
        # All checks above are read-only. Only begin uploads once the PRO
        # destination and every visible plugin have been verified.
        for item in skipped:
            print("::warning::PRO plugin not installed in selected root: "
                  + item["slug"], flush=True)
        for slug in ready:
            reconcile_one(ftp, root, slug, modules[slug], report)
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
        write_report(report, source, error, skipped, len(modules), root, visible)
    if error:
        raise RuntimeError("PRO transfer incomplete: " + str(error)) from error


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.CalledProcessError, *ftplib.all_errors) as exc:
        print("::error::" + str(exc), file=sys.stderr)
        sys.exit(1)
