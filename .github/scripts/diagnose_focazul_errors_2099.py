#!/usr/bin/env python3
"""Read-only FTPS inspection of PHP fatal error logs on Focazul WordPress.

The audit reads only whitelisted diagnostic filenames. It does not write to the
server, inspect wp-config.php, or print raw log contents or FTP credentials.
"""
from __future__ import annotations

import ftplib
import io
import os
import posixpath
import re
import sys

SITE_ROOT = "./asociacionfocazul"
DIRECTORIES = (
    "",
    "wp-content",
    "wp-admin",
    "wp-content/plugins",
    "wp-content/plugins/taxocommerce-core",
    "errors",
    "errores",
    "logs",
    "wp-content/logs",
)
LOG_NAMES = {
    "error_log", "error.log", "debug.log", "php_error.log",
    "php_errors.log", "php-error.log", "php_errorlog", "error.txt",
}
MAX_TAIL = 512 * 1024
MAX_VERSION_FILE = 512 * 1024
MAX_EVENTS = 12

DETAIL_PATTERNS = (
    (r"Call to undefined function\s+([\w\\:]+)", "Undefined function"),
    (r"Call to undefined method\s+([\w\\:]+)", "Undefined method"),
    (r"Class\s+['\"]?([\w\\]+)['\"]?\s+not found", "Missing class"),
    (r"Cannot redeclare\s+([\w\\:]+)", "Duplicate declaration"),
    (r"Too few arguments to function\s+([\w\\:]+)", "Insufficient arguments"),
    (r"syntax error,?\s+unexpected\s+([A-Za-z0-9_]+)", "Parse error"),
    (r"Failed opening required", "Required file missing"),
    (r"Uncaught\s+([\w\\]+)", "Uncaught exception"),
    (r"Allowed memory size of", "Memory limit exceeded"),
    (r"Maximum execution time of", "Execution time exceeded"),
)
ERROR_MARKERS = (
    "PHP Fatal error", "PHP Parse error", "PHP Compile Error",
    "Uncaught Error", "Uncaught TypeError", "Uncaught ParseError",
)


def classify_error(line: str) -> str | None:
    """Return a strictly limited diagnostic string; never echo a raw log line."""
    if not any(marker in line for marker in ERROR_MARKERS):
        return None
    kind = next((mark for mark in ERROR_MARKERS if mark in line), "PHP error")
    why = "Unclassified PHP fatal/parse error"
    for regex, label in DETAIL_PATTERNS:
        match = re.search(regex, line, re.IGNORECASE)
        if match:
            token = match.group(1) if match.lastindex else ""
            why = label + (": " + token[:120] if token else "")
            break
    at = re.search(r"\bin\s+(/[^\s]+?)(?::(\d+)|\s+on\s+line\s+(\d+)|$)", line)
    place = "location not reported"
    if at:
        filename = at.group(1)
        relative = next(
            (filename[filename.index(suffix):] for suffix in
             ("wp-content/", "wp-includes/", "wp-admin/") if suffix in filename),
            "outside WordPress directory",
        )
        lineno = at.group(2) or at.group(3) or "?"
        place = relative[:180] + ":" + lineno
    when = re.search(r"\[(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2}(?:\s+[A-Z]{1,6})?)\]", line)
    stamp = when.group(1) if when else "time unavailable"
    return stamp + " | " + kind + " | " + why + " | " + place


def safe_cwd(ftp: ftplib.FTP_TLS, origin: str, relative: str) -> bool:
    try:
        ftp.cwd(origin)
        ftp.cwd(SITE_ROOT + ("/" + relative if relative else ""))
        return True
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return False
        raise


def fetch_tail(ftp: ftplib.FTP_TLS, filename: str) -> bytes | None:
    """Fetch at most 512 KiB, using FTP REST when file is large."""
    ftp.voidcmd("TYPE I")
    try:
        size = ftp.size(filename)
    except ftplib.all_errors:
        return None
    if size is None:
        return None
    chunks = io.BytesIO()
    offset = max(0, size - MAX_TAIL)
    try:
        ftp.retrbinary("RETR " + filename, chunks.write, rest=offset or None)
    except ftplib.all_errors:
        return None
    payload = chunks.getvalue()
    if len(payload) > MAX_TAIL + 2048:
        return None
    return payload


def inspect(ftp: ftplib.FTP_TLS) -> int:
    origin = ftp.pwd()
    if not safe_cwd(ftp, origin, ""):
        print("ERROR: The expected Focazul WordPress directory is not accessible.")
        return 2
    inspected = 0
    fatal_count = 0
    for directory in DIRECTORIES:
        if not safe_cwd(ftp, origin, directory):
            continue
        try:
            entries = ftp.nlst()
        except ftplib.error_perm as exc:
            if str(exc).startswith("550"):
                continue
            raise
        candidates = sorted({
            posixpath.basename(entry.rstrip("/"))
            for entry in entries
            if posixpath.basename(entry.rstrip("/")).lower() in LOG_NAMES
        })
        for filename in candidates:
            data = fetch_tail(ftp, filename)
            if data is None:
                print("LOG_NOT_READABLE " + (directory + "/" if directory else "") + filename)
                continue
            inspected += 1
            rel = (directory + "/" if directory else "") + filename
            text = data.decode("utf-8", errors="replace")
            events = [x for line in text.splitlines() if (x := classify_error(line))]
            fatal_count += len(events)
            print("LOG " + rel + ": " + str(len(events)) + " PHP fatal/parse events in tail")
            for item in events[-MAX_EVENTS:]:
                print("  " + item)
    if safe_cwd(ftp, origin, "wp-content/plugins/taxocommerce-core/includes"):
        try:
            filenames = {
                posixpath.basename(name.rstrip("/"))
                for name in ftp.nlst()
            }
            for required in (
                "class-taxocommerce-core-menu.php",
                "class-taxocommerce-core-menu-editor.php",
                "class-taxocommerce-core-block-navigation.php",
            ):
                print("CORE_INCLUDED_FILE " + required + ": " +
                      ("PRESENT" if required in filenames else "ABSENT"))
        except ftplib.error_perm:
            print("CORE_INCLUDED_FILES: DIRECTORY LISTING NOT ALLOWED")
    if safe_cwd(ftp, origin, "wp-content/plugins/taxocommerce-core"):
        content = fetch_tail(ftp, "taxocommerce-core.php")
        # The main plugin file is small; only the plugin version is output.
        if content and len(content) < MAX_VERSION_FILE:
            header = content[:4096].decode("utf-8", errors="replace")
            match = re.search(r"(?im)^\s*\*\s*Version:\s*([0-9][0-9.]*)", header)
            print("FOCAZUL_CORE_VERSION " + (match.group(1) if match else "unknown"))
    print(f"RESULT: {inspected} accessible log files; {fatal_count} PHP fatal/parse events in inspected tails.")
    if inspected == 0:
        print("NO_READABLE_LOGS: Consult Webempresa PHP error logs or wp-content/debug.log; the cause cannot be established from FTP.")
    return 0


def main() -> int:
    names = ("STAGING_FTP_SERVER", "STAGING_FTP_USERNAME", "STAGING_FTP_PASSWORD")
    if not all(os.getenv(name) for name in names):
        print("ERROR: Existing FTPS deployment credentials are not configured.")
        return 2
    ftp = ftplib.FTP_TLS(timeout=45)
    try:
        ftp.connect(os.environ[names[0]], 21)
        ftp.login(os.environ[names[1]], os.environ[names[2]])
        ftp.prot_p()
        return inspect(ftp)
    except (*ftplib.all_errors, OSError, EOFError) as exc:
        print("ERROR: Read-only FTPS inspection failed: " + type(exc).__name__)
        return 2
    finally:
        try:
            ftp.quit()
        except (*ftplib.all_errors, OSError):
            ftp.close()


if __name__ == "__main__":
    sys.exit(main())
