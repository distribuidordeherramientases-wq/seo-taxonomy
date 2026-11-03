#!/usr/bin/env python3
"""Compile native gettext MO binaries from existing TaxoCommerce PO catalogs.

The PO translation corpus is authoritative. No translations are invented.
A structural check matching the WordPress MO parser guards each output.
"""
from __future__ import annotations

import argparse
from pathlib import Path
import tempfile

import polib
from verify_mo_catalogs import ROOT, validate_mo_bytes

LOCALES = ("es_ES", "en_US", "fr_FR", "de_DE", "nl_NL")


def compile_catalog(plugin: str, locale: str, check: bool) -> bool:
    domain = "taxocommerce-" + plugin
    source = ROOT / domain / "languages" / (domain + "-" + locale + ".po")
    output = source.with_suffix(".mo")
    if not source.is_file() or not output.is_file():
        raise FileNotFoundError(f"missing PO or MO: {domain}-{locale}")
    po = polib.pofile(str(source), check_for_duplicates=True)
    with tempfile.TemporaryDirectory() as temp:
        candidate = Path(temp) / output.name
        po.save_as_mofile(str(candidate))
        blob = candidate.read_bytes()
        entries = validate_mo_bytes(blob)
        if entries <= 0:
            raise ValueError("compiled MO has no metadata entry")
        changed = blob != output.read_bytes()
        if changed and not check:
            output.write_bytes(blob)
    print(f"{domain}-{locale}: entries={entries}; {'CHANGED' if changed else 'unchanged'}")
    return changed


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plugin", choices=("core",), required=True)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    changed = [compile_catalog(args.plugin, locale, args.check) for locale in LOCALES]
    if args.check and any(changed):
        print("MO catalogs are out of sync with their PO sources")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
