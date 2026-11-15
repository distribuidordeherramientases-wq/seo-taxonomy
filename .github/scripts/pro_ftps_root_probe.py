#!/usr/bin/env python3
"""Read-only FTPS proof that the configured account reaches the actual PRO plugin root.

This probe cannot deploy, install, delete or edit any file. A location is accepted
only when TWO independent installed plugin headers match live WordPress inventory.
"""
from __future__ import annotations

import ftplib
import io
import os
import re
import ssl
import sys

CANDIDATES = (
    ".",
    "./wp-content/plugins",
    "./public_html/wp-content/plugins",
    "./httpdocs/wp-content/plugins",
    "..",
)
TARGETS = (
    ("taxocommerce-core/taxocommerce-core.php", "PRO_CORE_EXPECTED_VERSION"),
    ("woocommerce/woocommerce.php", "PRO_WOO_EXPECTED_VERSION"),
)
MAX_HEADER = 65536


def read_plugin_version(ftp: ftplib.FTP_TLS, relative: str) -> str:
    received = bytearray()

    def append(chunk: bytes) -> None:
        if len(received) + len(chunk) > MAX_HEADER:
            raise ValueError("plugin header exceeds read limit")
        received.extend(chunk)

    ftp.retrbinary("RETR " + relative, append)
    header = received.decode("utf-8", errors="replace")[:8192]
    match = re.search(r"(?mi)^\s*\*\s*Version:\s*(\d+\.\d+(?:\.\d+){0,2})\s*$", header)
    if not match:
        raise ValueError("main plugin file has no parseable version header")
    return match.group(1)


def find_verified_root(ftp: ftplib.FTP_TLS, expected: dict[str, str]):
    """Return one verified canonical FTP directory, else fail closed."""
    matches = {}
    for candidate in CANDIDATES:
        try:
            ftp.cwd(candidate)
            canonical = ftp.pwd()
            versions = {}
            for relative, var in TARGETS:
                versions[var] = read_plugin_version(ftp, relative)
            if all(versions[key] == expected[key] for key in versions):
                matches[canonical] = candidate
            ftp.cwd("/")  # The next attempt starts from a known FTP root.
        except (ftplib.Error, OSError, ValueError, UnicodeError):
            try:
                ftp.cwd("/")
            except ftplib.Error:
                raise RuntimeError("FTPS cannot reset to its login root") from None
    if len(matches) != 1:
        raise RuntimeError(
            "No unique plugin root with verified Core and WooCommerce versions; "
            "blocked without writing anything"
        )
    return next(iter(matches.values())), next(iter(matches.keys()))


def main() -> int:
    required = ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD",
                "PRO_CORE_EXPECTED_VERSION", "PRO_WOO_EXPECTED_VERSION")
    if any(not os.environ.get(key, "").strip() for key in required):
        raise RuntimeError("FTPS credentials or expected site versions missing; read-only probe aborted")

    expected = {
        "PRO_CORE_EXPECTED_VERSION": os.environ["PRO_CORE_EXPECTED_VERSION"],
        "PRO_WOO_EXPECTED_VERSION": os.environ["PRO_WOO_EXPECTED_VERSION"],
    }
    for v in expected.values():
        if not re.fullmatch(r"\d+\.\d+(?:\.\d+){0,2}", v):
            raise RuntimeError("Invalid expected plugin version")

    context = ssl.create_default_context()
    with ftplib.FTP_TLS(context=context, timeout=25) as ftp:
        ftp.connect(os.environ["FTP_SERVER"], 21)
        ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
        ftp.prot_p()
        # This is a read-only diagnostic. No FTP upload/remove/mkdir is used.
        _candidate, _canonical = find_verified_root(ftp, expected)
        print("PRO FTPS read-only root verified using installed Core "
              + expected["PRO_CORE_EXPECTED_VERSION"] + " and WooCommerce "
              + expected["PRO_WOO_EXPECTED_VERSION"] + ".")
        print("No production file was modified. Exact FTP paths deliberately suppressed.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (ftplib.Error, OSError, RuntimeError, ValueError) as exc:
        # Never expose server names, FTP usernames, passwords or filesystem paths.
        print("::error::PRO FTPS read-only verification failed: "
              + (str(exc) if isinstance(exc, (RuntimeError, ValueError)) else type(exc).__name__),
              file=sys.stderr)
        sys.exit(1)
