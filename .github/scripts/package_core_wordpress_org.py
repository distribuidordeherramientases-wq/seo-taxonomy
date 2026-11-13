#!/usr/bin/env python3
"""Validate the TaxoCommerce Core WordPress.org package using its assigned slug.

WordPress.org changed Core's reserved slug to 'taxocommerce-core' on 12 Nov
2026. Its installed and published packages now share one identity, so no
gettext rewrites, PHP entrypoint renames, or duplicate releases are needed.
"""
from __future__ import annotations

import argparse
import re
from pathlib import Path

SLUG = "taxocommerce-core"
OLD_SLUG = "taxocommerce-core-catalog-content-management-for-woocommerce"
LOCALES = ("es_ES", "en_US", "fr_FR", "de_DE", "nl_NL")


def prepare(folder: Path) -> dict[str, int]:
    """Validate the package without mutating the source or staged output."""
    if folder.name != SLUG:
        raise ValueError("WordPress.org distribution directory must be taxocommerce-core")
    entry = folder / (SLUG + ".php")
    readme = folder / "readme.txt"
    if not entry.is_file() or not readme.is_file():
        raise ValueError("Missing taxocommerce-core.php or readme.txt")
    if (folder / (OLD_SLUG + ".php")).exists():
        raise ValueError("Obsolete long-slug plugin entrypoint found")
    header = entry.read_text(encoding="utf-8")
    description = readme.read_text(encoding="utf-8")
    if not re.search(r"(?m)^\s*\*\s*Text Domain:\s*taxocommerce-core\s*$", header):
        raise ValueError("Core text domain must match its assigned slug")
    version = re.search(r"(?m)^\s*\*\s*Version:\s*([0-9.]+)\s*$", header)
    stable = re.search(r"(?m)^Stable tag:\s*([0-9.]+)\s*$", description)
    if not version or not stable or version.group(1) != stable.group(1):
        raise ValueError("WordPress.org main version and stable tag differ")
    files = list(folder.rglob("*.php"))
    if not files:
        raise ValueError("No PHP files in Core package")
    lang = folder / "languages"
    for locale in LOCALES:
        for suffix in (".po", ".mo"):
            if not (lang / (SLUG + "-" + locale + suffix)).is_file():
                raise ValueError("Missing Core gettext catalog: " + locale + suffix)
    for legacy in lang.glob(OLD_SLUG + "-*"):
        raise ValueError("Obsolete long-slug language file in public package: " + legacy.name)
    if any("tests" in p.parts for p in folder.rglob("*")):
        raise ValueError("Tests must not ship in the public plugin ZIP")
    return {"php_files": len(files), "locales": len(LOCALES), "version": version.group(1)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("build_directory", type=Path)
    args = parser.parse_args()
    info = prepare(args.build_directory)
    print(f"Core WordPress.org package: {SLUG}, v{info['version']}, "
          f"{info['php_files']} PHP files, {info['locales']} locales.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
