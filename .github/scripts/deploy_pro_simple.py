#!/usr/bin/env python3
"""Publish tracked TaxoCommerce plugins from Git STAGING using existing FTP credentials.

FTP is jailed in seo-taxonomy/, not WP_PLUGIN_DIR. A short-lived signed PHP
receiver in that existing directory asks WordPress to apply each ZIP at the real
WP_PLUGIN_DIR. No installed/active plugin inventory or new credentials.
"""
import ftplib
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import ssl
import subprocess
import sys
import time
import urllib.parse
import urllib.request
import zipfile

HOST = "https://www.distribuidordeherramientas.es"
SLUG = re.compile(r"taxocommerce-[a-z0-9-]+$")
PART = re.compile(r"[A-Za-z0-9._+-]+$")
MAX_FILE = 32 * 1024 * 1024
# Only known source formats; never ship tests, caches, private data or symlinks.
EXCLUDE_DIRS = {"tests", "uploads", "node_modules", ".git", ".github", "tmp", "cache", "logs"}
EXCLUDE_SUFFIXES = (".bak", ".log", ".pyc", ".pot")


def deliverables():
    entries = subprocess.check_output(["git", "ls-files", "-z", "--", "taxocommerce-*"])
    selected = {}
    for raw in entries.split(b"\x00"):
        if not raw:
            continue
        path = raw.decode("utf-8")
        parts = PurePosixPath(path).parts
        if len(parts) < 2 or not SLUG.fullmatch(parts[0]):
            continue
        if any(not PART.fullmatch(p) or p.startswith(".") for p in parts):
            continue
        if any(p in EXCLUDE_DIRS for p in parts[1:]):
            continue
        rel = "/".join(parts[1:])
        if rel.endswith(EXCLUDE_SUFFIXES) or (rel.endswith(".md") and rel != "CHANGELOG.md"):
            continue
        local = Path(path)
        if not local.is_file() or local.is_symlink() or local.stat().st_size > MAX_FILE:
            continue
        selected.setdefault(parts[0], []).append((rel, local))
    for slug, files in selected.items():
        if not any(p == slug + ".php" for p, _ in files) or not any(p == "readme.txt" for p, _ in files):
            raise RuntimeError("Missing plugin entrypoint or readme: " + slug)
    if not selected:
        raise RuntimeError("No tracked TaxoCommerce plugin files found in staging")
    return selected


def create_packages(modules):
    out = {}
    for slug, files in sorted(modules.items()):
        buf = io.BytesIO()
        manifest = {}
        with zipfile.ZipFile(buf, mode="w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as z:
            for relative, source in sorted(files):
                data = source.read_bytes()
                z.writestr(relative, data)
                manifest[relative] = hashlib.sha256(data).hexdigest()
        out[slug] = (buf.getvalue(), hashlib.sha256(
            json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode("ascii")
        ).hexdigest(), len(manifest))
    return out


def receiver_php(token_hash, expires, folder):
    # No secrets in source; the caller proves possession via a 256-bit token.
    # The PHP receiver is removed from FTPS in finally, including failed runs.
    return """<?php
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
    time() > %d ||
    !isset($_POST['token']) ||
    !hash_equals('%s', hash('sha256', (string)$_POST['token']))) {
    http_response_code(403); exit;
}
$slug = (string)($_POST['slug'] ?? '');
if (!preg_match('/^taxocommerce-[a-z0-9-]+$/D', $slug)) { http_response_code(400); exit; }
$archive = __DIR__ . '/.taxocommerce-deploy/%s/' . $slug . '.zip';
if (!is_file($archive)) { http_response_code(404); exit; }
$root = dirname(__DIR__, 3) . '/wp-load.php';
if (!is_file($root)) { http_response_code(500); exit; }
require_once $root;
if (!function_exists('seo_taxocommerce_deploy_apply_zip_delta')) {
    require_once __DIR__ . '/includes/seo-deploy-status.php';
}
$destination = WP_PLUGIN_DIR . '/' . $slug;
if (!is_dir($destination) && !wp_mkdir_p($destination)) { http_response_code(500); exit; }
$result = seo_taxocommerce_deploy_apply_zip_delta($archive, $destination);
header('Content-Type: application/json; charset=utf-8');
if (is_wp_error($result)) {
    http_response_code(500);
    echo wp_json_encode(array('error' => $result->get_error_code()));
    exit;
}
echo wp_json_encode(array('slug' => $slug, 'verified_files' => $result['verified_files'],
    'manifest_sha256' => $result['manifest_sha256'],
    'changed' => count($result['changed']), 'unchanged' => $result['unchanged']));
""" % (expires, token_hash, folder)


def mkdir(ftp, name):
    try:
        ftp.mkd(name)
    except ftplib.error_perm as exc:
        if not str(exc).startswith("550"):
            raise
    ftp.cwd(name)


def main():
    if not all(os.environ.get(k) for k in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD")):
        raise RuntimeError("Existing FTP_SERVER/FTP_USERNAME/FTP_PASSWORD secrets required")
    modules = deliverables()
    packages = create_packages(modules)
    token = secrets.token_hex(32)
    ident = secrets.token_hex(12)
    receiver = "tc-pro-apply-" + ident + ".php"
    folder = "run-" + ident
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=50)
    ftp.connect(os.environ["FTP_SERVER"], 21)
    ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
    ftp.prot_p()
    root = ftp.pwd()
    opened = False
    try:
        # The existing FTP account is rooted in the historical plugin, as
        # verified by the previously successful ZIP handoff in Actions.
        mkdir(ftp, ".taxocommerce-deploy")
        mkdir(ftp, folder)
        for slug, (data, _, _) in packages.items():
            ftp.storbinary("STOR " + slug + ".zip", io.BytesIO(data), blocksize=65536)
        ftp.cwd(root)
        php = receiver_php(hashlib.sha256(token.encode("ascii")).hexdigest(),
                           int(time.time()) + 3600, folder)
        ftp.storbinary("STOR " + receiver, io.BytesIO(php.encode("utf-8")), blocksize=65536)
        opened = True
        updated = 0
        for slug, (_, manifest, count) in packages.items():
            request = urllib.request.Request(
                HOST + "/wp-content/plugins/seo-taxonomy/" + receiver,
                data=urllib.parse.urlencode({"token": token, "slug": slug}).encode("ascii"),
                headers={"Content-Type": "application/x-www-form-urlencoded"},
                method="POST",
            )
            with urllib.request.urlopen(request, timeout=100) as response:
                data = json.load(response)
            if (data.get("slug") != slug or data.get("verified_files") != count
                    or data.get("manifest_sha256") != manifest):
                raise RuntimeError("WordPress file checksum verification failed for " + slug)
            updated += int(data["changed"])
            print(slug + ": " + str(data["changed"]) + " files changed, "
                  + str(data["unchanged"]) + " already identical")
        print("SUCCESS: WordPress verified " + str(len(packages))
              + " plugins; " + str(updated) + " files updated in real WP_PLUGIN_DIR")
    finally:
        try:
            ftp.cwd(root)
            if opened:
                ftp.delete(receiver)
            ftp.cwd(".taxocommerce-deploy/" + folder)
            for slug in packages:
                try:
                    ftp.delete(slug + ".zip")
                except ftplib.error_perm:
                    pass
            ftp.cwd(root + "/.taxocommerce-deploy")
            ftp.rmd(folder)
        except ftplib.Error as exc:
            print("WARNING: Temporary handoff cleanup incomplete: " + type(exc).__name__,
                  file=sys.stderr)
        try:
            ftp.quit()
        except (ftplib.Error, OSError):
            ftp.close()


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("ERROR: PRO transfer failed: " + type(exc).__name__ + ": "
              + str(exc).split("\n")[0][:180], file=sys.stderr)
        raise SystemExit(1)
