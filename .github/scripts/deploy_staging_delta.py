#!/usr/bin/env python3
"""Deploy changed files of already installed TaxoCommerce plugins to both permitted WordPress sites.

Baseline: last successful staging workflow, so a failed run cannot lose its changes.
Deletion of runtime files is blocked until a separately reviewed migration.
"""
import argparse
import ftplib
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import urllib.request

SLUG = re.compile(r"^taxocommerce-[a-z0-9-]+$")
SHA = re.compile(r"^[0-9a-f]{40}$")
EXCLUDE_PARTS = {"tests", "node_modules", "uploads", "__pycache__"}
EXCLUDE_SUFFIXES = (".bak", ".md", ".pot", ".log", ".pyc")

# Both WordPress installations are siblings beneath the FTP account's
# new /home/hosting160568eu/public_html root. Never deploy to the root itself.
SITE_FTP_ROOTS = {
    "staging": "staging.distribuidordeherramientas.es",
    "focazul": "asociacionfocazul",
}


def remote_plugin_dir(target, slug):
    if target not in SITE_FTP_ROOTS:
        raise ValueError(f"Unrecognized FTP destination: {target}")
    if not SLUG.fullmatch(slug):
        raise ValueError(f"Invalid plugin slug: {slug}")
    return "./" + SITE_FTP_ROOTS[target] + "/wp-content/plugins/" + slug + "/"



def git(*args):
    return subprocess.check_output(["git", *args])


def distributable(path):
    parts = Path(path).parts
    if len(parts) < 2 or not SLUG.fullmatch(parts[0]):
        return None
    relative = parts[1:]
    if any(part.startswith(".") or part in EXCLUDE_PARTS for part in relative):
        return None
    if relative[-1].lower().endswith(EXCLUDE_SUFFIXES):
        return None
    return parts[0], "/".join(relative)


def changed_files(base, head, selected_slug=None):
    if not SHA.fullmatch(base) or not SHA.fullmatch(head):
        raise ValueError("Invalid baseline or HEAD commit")
    subprocess.run(["git", "merge-base", "--is-ancestor", base, head], check=True)
    values = git("diff", "--name-status", "-z", "--no-renames", base, head, "--").decode("utf-8").split("\0")
    changes = {}
    for status, path in zip(values[0::2], values[1::2]):
        parsed = distributable(path)
        if parsed is None:
            continue
        slug, relative = parsed
        if status.startswith("D"):
            changes.setdefault(slug, set())
            if selected_slug == slug:
                raise ValueError(f"Deletion requires a separately reviewed migration: {path}")
            continue
        if not status.startswith(("A", "C", "M", "T")):
            raise ValueError(f"Unexpected Git file status: {status} {path}")
        source = Path(path)
        if not source.is_file() or source.is_symlink():
            if selected_slug == slug:
                raise ValueError(f"Missing or unsafe source file: {path}")
            continue
        if not (Path(slug) / (slug + ".php")).is_file():
            if selected_slug == slug:
                raise ValueError(f"Not a valid plugin directory: {slug}")
            continue
        changes.setdefault(slug, set()).add(relative)
    return {slug: sorted(names) for slug, names in sorted(changes.items())}


def last_successful_base(repo, head, token):
    if not token:
        raise ValueError("GITHUB_TOKEN is required to find a verified baseline")
    url = f"https://api.github.com/repos/{repo}/actions/workflows/deploy-staging.yml/runs?branch=staging&status=success&per_page=100"
    request = urllib.request.Request(url, headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.github+json",
        "User-Agent": "TaxoCommerce-Delta-Deploy/1.0",
    })
    with urllib.request.urlopen(request, timeout=25) as response:
        payload = json.load(response)
    for run in payload.get("workflow_runs", []):
        candidate = run.get("head_sha", "")
        if SHA.fullmatch(candidate) and subprocess.run(
            ["git", "merge-base", "--is-ancestor", candidate, head], check=False
        ).returncode == 0:
            return candidate
    raise ValueError("No verified successful STAGING baseline; refusing broad FTP synchronization")


def plan():
    head = git("rev-parse", "HEAD").decode().strip()
    base = last_successful_base(os.environ["GITHUB_REPOSITORY"], head, os.environ.get("GITHUB_TOKEN", ""))
    changes = changed_files(base, head)
    # An ordinary push must deploy only plugin runtime files changed in Git.
    # Updating a workflow, CI test or deployment script must NEVER initiate a
    # family-wide reconciliation without an explicit manual operator request.
    sync = os.environ.get("TAXOCOMMERCE_FULL_SYNC", "0") == "1"
    target = os.environ.get("TAXOCOMMERCE_TARGET_PLUGIN", "")
    if target:
        # Only the explicitly authorized Tasks push may bypass the blocked
        # all-plugin queue. Never permit dynamic or arbitrary plugin selection.
        if target != "taxocommerce-tasks" or sync:
            raise ValueError("Targeted push deployment only permits Tasks without full sync")
        slugs = [target] if changes.get(target) else []
    elif sync:
        slugs = sorted(
            d.name for d in Path(".").glob("taxocommerce-*")
            if d.is_dir() and SLUG.fullmatch(d.name)
            and (d / (d.name + ".php")).is_file()
        )
    else:
        slugs = list(changes)
    core = "taxocommerce-core" in slugs
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
        output.write(f"base={base}\n")
        output.write("slugs=" + json.dumps(slugs, separators=(",", ":")) + "\n")
        output.write("count=" + str(len(slugs)) + "\n")
        output.write("sync=" + str(sync).lower() + "\n")
        output.write("core=" + str(core).lower() + "\n")
    print(f"Verified baseline {base}, target {head}")
    for slug, files in changes.items():
        print(f"{slug}: {len(files)} changed distributable file(s)")
    if sync:
        print(f"Manual full synchronization requested: reconcile {len(slugs)} installed-plugin candidates on both sites")
    if not slugs:
        print("No plugin runtime files changed; no FTPS connection needed")


def existing_file_hash(ftp, name):
    digest = hashlib.sha256()
    try:
        ftp.retrbinary("RETR " + name, digest.update)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return None
        raise
    return digest.hexdigest()


def upload_plugin(ftp, target, slug, files, required):
    # Issue #2290 authorizes the FIRST installation of Tasks on these two sites only.
    # All other absent plugins remain strictly excluded from automatic installation.
    new_tasks_install = False
    try:
        ftp.cwd(remote_plugin_dir(target, slug))
    except ftplib.error_perm as exc:
        if not str(exc).startswith("550"):
            raise
        if slug == "taxocommerce-tasks" and target in ("staging", "focazul"):
            plugin_parent = remote_plugin_dir(target, slug).rsplit(slug + "/", 1)[0]
            ftp.cwd(plugin_parent)
            ftp.mkd(slug)
            ftp.cwd(slug)
            new_tasks_install = True
            print(f"INSTALL {target}/{slug}: explicitly authorized by Issue #2290")
        elif not required:
            print(f"SKIP {slug}: plugin directory absent on this WordPress")
            return
        else:
            raise RuntimeError(f"{slug}: installed plugin directory is inaccessible") from exc
    root = ftp.pwd()
    # Preexisting plugins must have the entrypoint; never repair unknown installs.
    if not new_tasks_install and existing_file_hash(ftp, slug + ".php") is None:
        if not required:
            print(f"SKIP {slug}: plugin entrypoint absent on this WordPress")
            return
        raise RuntimeError(f"{slug}: expected installed plugin entrypoint is missing")
    for relative in files:
        source = Path(slug) / relative
        data_hash = hashlib.sha256(source.read_bytes()).hexdigest()
        ftp.cwd(root)
        parts = relative.split("/")
        for directory in parts[:-1]:
            try:
                ftp.cwd(directory)
            except ftplib.error_perm as exc:
                if not str(exc).startswith("550"):
                    raise
                ftp.mkd(directory)
                ftp.cwd(directory)
        filename = parts[-1]
        if existing_file_hash(ftp, filename) == data_hash:
            print(f"UNCHANGED {slug}/{relative}")
            continue
        tempname = ".taxocommerce-upload-" + hashlib.sha256(relative.encode()).hexdigest()[:16] + ".tmp"
        try:
            with source.open("rb") as stream:
                ftp.storbinary("STOR " + tempname, stream)
            ftp.rename(tempname, filename)
            if existing_file_hash(ftp, filename) != data_hash:
                raise RuntimeError(f"Remote SHA-256 verification failed: {slug}/{relative}")
            print(f"UPDATED {slug}/{relative} sha256:{data_hash[:12]}")
        finally:
            try:
                ftp.delete(tempname)
            except ftplib.all_errors:
                pass


def plugin_source_files(slug):
    """All tracked, installable files of a standalone plugin. Never delete."""
    if not SLUG.fullmatch(slug):
        raise ValueError("Invalid plugin slug")
    names = git("ls-files", "-z", "--", slug).decode("utf-8").split("\0")
    files = set()
    for name in names:
        parsed = distributable(name)
        if not parsed or parsed[0] != slug:
            continue
        source = Path(name)
        if not source.is_file() or source.is_symlink():
            raise ValueError(f"Missing or unsafe tracked plugin source: {name}")
        files.add(parsed[1])
    if slug + ".php" not in files:
        raise ValueError(f"Tracked plugin entrypoint missing: {slug}")
    return sorted(files)


def core_source_files():
    """All tracked, installable Core files from this one shared source tree.

    Only a checksum mismatch triggers an upload. This also heals an old
    installation missing a file that a previous incremental run did not touch.
    """
    names = git("ls-files", "-z", "--", "taxocommerce-core").decode("utf-8").split("\0")
    files = set()
    for name in names:
        parsed = distributable(name)
        if not parsed or parsed[0] != "taxocommerce-core":
            continue
        source = Path(name)
        if not source.is_file() or source.is_symlink():
            raise ValueError(f"Missing or unsafe tracked Core source: {name}")
        files.add(parsed[1])
    if "taxocommerce-core.php" not in files:
        raise ValueError("Tracked Core entrypoint missing; refusing cross-site reconciliation")
    return sorted(files)


def reconcile_core():
    """Enforce identical tracked Core bytes on STAGING and Focazul only.

    Both sites are sourced from this checkout, never copied from one live
    WordPress to another. The existing uploader skips identical files and
    atomically transfers only the differing bytes with SHA-256 readback.
    """
    files = core_source_files()
    server = os.environ["STAGING_FTP_SERVER"]
    user = os.environ["STAGING_FTP_USERNAME"]
    password = os.environ["STAGING_FTP_PASSWORD"]
    if not server or not user or not password:
        raise ValueError("Missing existing shared FTPS credentials")
    ftp = ftplib.FTP_TLS(timeout=50)
    try:
        ftp.connect(server, 21)
        ftp.login(user, password)
        ftp.prot_p()
        home = ftp.pwd()
        for target in ("staging", "focazul"):
            ftp.cwd(home)
            upload_plugin(ftp, target, "taxocommerce-core", files, required=True)
            print(f"{target}: all {len(files)} tracked installable Core files match the same Git commit")
        ftp.quit()
    finally:
        try:
            ftp.close()
        except OSError:
            pass


def deploy(base, target, slug, full=False):
    head = git("rev-parse", "HEAD").decode().strip()
    if full:
        files = plugin_source_files(slug)
    else:
        changes = changed_files(base, head, selected_slug=slug)
        if slug not in changes:
            raise ValueError(f"No distributable changes selected for {slug}")
        files = changes[slug]
    # One FTPS login shared by both clients; each site has an isolated path.
    # Validate the destination before connecting to the server.
    remote_plugin_dir(target, slug)
    env_prefix = "STAGING"
    host = os.environ[env_prefix + "_FTP_SERVER"]
    user = os.environ[env_prefix + "_FTP_USERNAME"]
    password = os.environ[env_prefix + "_FTP_PASSWORD"]
    if not host or not user or not password:
        raise ValueError("Missing " + env_prefix + " FTPS credentials")
    for attempt in range(3):
        ftp = ftplib.FTP_TLS(timeout=45)
        try:
            ftp.connect(host, 21)
            ftp.login(user, password)
            ftp.prot_p()
            upload_plugin(ftp, target, slug, files, required=(slug == "taxocommerce-core"))
            ftp.quit()
            return
        except (OSError, EOFError, ftplib.error_temp) as exc:
            if attempt == 2:
                raise
            print(f"::warning::{target}/{slug}: transient FTPS error, retry {attempt + 2}/3: {exc}")
            time.sleep(2 ** attempt)
        finally:
            try:
                ftp.close()
            except OSError:
                pass


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["plan", "deploy", "reconcile-core"])
    parser.add_argument("--base")
    parser.add_argument("--target", choices=["staging", "focazul"])
    parser.add_argument("--slug")
    parser.add_argument("--full", choices=("true", "false"), default="false")
    args = parser.parse_args()
    if args.command == "plan":
        plan()
    elif args.command == "reconcile-core":
        reconcile_core()
    elif not args.base or not args.target or not args.slug:
        parser.error("deploy requires --base, --target and --slug")
    else:
        deploy(args.base, args.target, args.slug, full=args.full == "true")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, RuntimeError, OSError, subprocess.CalledProcessError, *ftplib.all_errors) as exc:
        print("::error::Staging delta deployment failed: " + str(exc), file=sys.stderr)
        sys.exit(1)
