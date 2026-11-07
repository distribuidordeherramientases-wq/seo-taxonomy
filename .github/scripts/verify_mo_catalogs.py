#!/usr/bin/env python3
"""Verify GNU .mo catalogs using the bounds assumed by the WordPress MO reader.

No polib dependency: detects truncated tables and malformed offsets that
WordPress would otherwise repeat as unpack()/undefined-array-key warnings.
"""
from __future__ import annotations

import argparse
from pathlib import Path
import struct
import sys

ROOT = Path(__file__).resolve().parents[2]
MAGIC_LITTLE = b"\xde\x12\x04\x95"
MAGIC_BIG = b"\x95\x04\x12\xde"


class InvalidMO(ValueError):
    """An MO file cannot be safely read by WordPress."""


def validate_mo_bytes(data: bytes) -> int:
    """Return count of entries; raise InvalidMO for malformed table metadata."""
    if len(data) < 28:
        raise InvalidMO("file shorter than 28-byte MO header")
    if data[:4] == MAGIC_LITTLE:
        order = "<"
    elif data[:4] == MAGIC_BIG:
        order = ">"
    else:
        raise InvalidMO("wrong MO magic")
    _, revision, count, original_table, translation_table, hash_count, hash_table = (
        struct.unpack_from(order + "7I", data)
    )
    if revision != 0:
        raise InvalidMO("unsupported MO revision")
    if count > (len(data) - 28) // 16:
        raise InvalidMO("declared entries exceed available index tables")
    # The GNU MO hash table is OPTIONAL. msgfmt may write hash_count=0 and
    # hash_table=0; that is valid and must not invalidate index tables.
    if not (
        28 <= original_table
        and original_table + count * 8 <= translation_table
        and translation_table + count * 8 <= len(data)
    ):
        raise InvalidMO("missing/misordered original or translation index table")
    if hash_count:
        if hash_table < translation_table + count * 8:
            raise InvalidMO("hash table overlaps the translation index table")
        if hash_table + hash_count * 4 > len(data):
            raise InvalidMO("hash table extends beyond file")
    for index in range(count):
        for label, table in (("original", original_table), ("translation", translation_table)):
            length, position = struct.unpack_from(order + "2I", data, table + index * 8)
            if position > len(data) or length > len(data) - position:
                raise InvalidMO(f"{label} entry {index} points outside file")
    return count


def scan_repository(root: Path) -> tuple[int, list[str]]:
    catalogues = sorted(root.glob("taxocommerce-*/languages/*.mo"))
    if not catalogues:
        raise InvalidMO("no TaxoCommerce catalogs found")
    broken = []
    for path in catalogues:
        try:
            validate_mo_bytes(path.read_bytes())
        except (OSError, InvalidMO, struct.error) as exc:
            broken.append(f"{path.relative_to(root)}: {exc}")
    return len(catalogues), broken


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", action="store_true", help="validate checked-out .mo catalogs")
    args = parser.parse_args()
    if not args.repo:
        parser.error("--repo required")
    total, errors = scan_repository(ROOT)
    print(f"MO repository audit: {total} catalogs, {len(errors)} structurally invalid")
    for error in errors:
        print("INVALID " + error)
    return int(bool(errors))


if __name__ == "__main__":
    sys.exit(main())
