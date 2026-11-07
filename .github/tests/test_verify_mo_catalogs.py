"""Regression fixtures for MO index bounds used by the WordPress MO reader."""
from pathlib import Path
import struct
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from verify_mo_catalogs import InvalidMO, validate_mo_bytes


def catalog(order="<", count=1):
    orig = 28
    trans = orig + count * 8
    hashaddr = trans + count * 8
    entries = b""
    original_indexes = b""
    translated_indexes = b""
    for _ in range(count):
        original_indexes += struct.pack(order + "II", 0, hashaddr + len(entries))
        entries += b"\0"
    translated_start = hashaddr + len(entries)
    translated_values = b""
    for i in range(count):
        value = f"Language: es_ES {i}\n".encode()
        translated_indexes += struct.pack(order + "II", len(value), translated_start + len(translated_values))
        translated_values += value + b"\0"
    header = struct.pack(
        order + "7I", 0x950412DE, 0, count, orig, trans, 0, hashaddr
    )
    return header + original_indexes + translated_indexes + entries + translated_values


class MOReaderBoundsTests(unittest.TestCase):
    def test_good_little_endian(self):
        self.assertEqual(validate_mo_bytes(catalog("<", 2)), 2)

    def test_good_big_endian(self):
        self.assertEqual(validate_mo_bytes(catalog(">", 2)), 2)

    def test_truncated_table_fails(self):
        with self.assertRaises(InvalidMO):
            validate_mo_bytes(catalog()[:36])

    def test_missing_translation_table_address_fails(self):
        value = bytearray(catalog())
        struct.pack_into("<I", value, 16, 0)
        with self.assertRaises(InvalidMO):
            validate_mo_bytes(value)

    def test_valid_mo_without_optional_hash_table(self):
        value = bytearray(catalog("<", 2))
        # GNU MO permits both optional hash fields to be zero.
        struct.pack_into("<II", value, 20, 0, 0)
        self.assertEqual(validate_mo_bytes(value), 2)

    def test_out_of_bounds_optional_hash_table_fails(self):
        value = bytearray(catalog())
        struct.pack_into("<II", value, 20, 3, len(value) + 8)
        with self.assertRaises(InvalidMO):
            validate_mo_bytes(value)

    def test_nonzero_invalid_empty_hash_offset_fails(self):
        value = bytearray(catalog())
        struct.pack_into("<II", value, 20, 0, len(value) + 8)
        with self.assertRaises(InvalidMO):
            validate_mo_bytes(value)

    def test_invalid_translation_pointer_fails(self):
        value = bytearray(catalog())
        struct.pack_into("<I", value, 40, len(value) + 10)
        with self.assertRaises(InvalidMO):
            validate_mo_bytes(value)

    def test_unknown_magic_fails(self):
        with self.assertRaises(InvalidMO):
            validate_mo_bytes(b"bad!" + catalog()[4:])


if __name__ == "__main__":
    unittest.main()
