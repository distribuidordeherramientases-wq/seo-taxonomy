#!/usr/bin/env python3
"""Deploy changed files of existing TaxoCommerce plugins to permitted WordPress sites.

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
    slugs = list(changes)
    core = "taxocommerce-core" in changes
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
        output.write(f"base={base}\n")
        output.write("slugs=" + json.dumps(slugs, separators=(",", ":")) + "\n")
        output.write("count=" + str(len(slugs)) + "\n")
        output.write("core=" + str(core).lower() + "\n")
    print(f"Verified baseline {base}, target {head}")
    for slug, files in changes.items():
        print(f"{slug}: {len(files)} changed distributable file(s)")
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


def upload_plugin(ftp, slug, files, required):
    try:
        ftp.cwd("./wp-content/plugins/" + slug + "/")
    except ftplib.error_perm as exc:
        if not required and str(exc).startswith("550"):
            print(f"SKIP {slug}: plugin directory absent on this WordPress")
            return
        raise RuntimeError(f"{slug}: installed plugin directory is inaccessible") from exc
    root = ftp.pwd()
    # Check entrypoint before writes: never install a plugin automatically.
    if existing_file_hash(ftp, slug + ".php") is None:
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


def deploy(base, target, slug):
    head = git("rev-parse", "HEAD").decode().strip()
    changes = changed_files(base, head, selected_slug=slug)
    if slug not in changes:
        raise ValueError(f"No distributable changes selected for {slug}")
    if target == "focazul" and slug != "taxocommerce-core":
        raise ValueError("Only Core is authorized for Focazul")
    env_prefix = "STAGING" if target == "staging" else "FOCAZUL"
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
            upload_plugin(ftp, slug, changes[slug], required=(slug == "taxocommerce-core"))
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
    parser.add_argument("command", choices=["plan", "deploy"])
    parser.add_argument("--base")
    parser.add_argument("--target", choices=["staging", "focazul"])
    parser.add_argument("--slug")
    args = parser.parse_args()
    if args.command == "plan":
        plan()
    elif not args.base or not args.target or not args.slug:
        parser.error("deploy requires --base, --target and --slug")
    else:
        deploy(args.base, args.target, args.slug)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, RuntimeError, OSError, subprocess.CalledProcessError, *ftplib.all_errors) as exc:
        print("::error::Staging delta deployment failed: " + str(exc), file=sys.stderr)
        sys.exit(1)
