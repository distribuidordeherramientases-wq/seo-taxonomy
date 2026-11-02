#!/usr/bin/env python3
"""Translate literal WordPress control/error text without altering technical IDs."""
from pathlib import Path
import re
import sys

ROOT = Path(sys.argv[1]).resolve()
CALLS = ("submit_button", "wp_die", "esc_html", "esc_attr", "esc_textarea", "esc_js")
PATTERN = re.compile(r"(?<!\w)(" + "|".join(CALLS) + r")\s*\(\s*(['\"])([^'\"\r\n]{2,}?)\2(?=\s*[,)]|\s*\))")
count = 0
for file in ROOT.glob("taxocommerce-*/**/*.php"):
    if "tests" in file.parts:
        continue
    domain = file.relative_to(ROOT).parts[0]
    old = file.read_text(encoding="utf-8")
    def translate(m):
        global count
        word = m.group(3)
        if not re.search(r"[A-Za-zÀ-ÿ]", word):
            return m.group(0)
        if re.search(r"^\$|^[A-Z_]+$|^[-_.a-z0-9]+$|https?://|<|>|[\[\]{}]", word):
            return m.group(0)
        count += 1
        return f"{m.group(1)}( __( {m.group(2)}{word}{m.group(2)}, '{domain}' )"
    updated = PATTERN.sub(translate, old)
    if updated != old:
        file.write_text(updated, encoding="utf-8")
print(f"Localized WordPress control and error texts: {count}")
