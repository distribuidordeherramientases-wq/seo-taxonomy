#!/usr/bin/env python3
"""Wrap literal UI labels but preserve technical CSV schema keys and localized country content."""
from pathlib import Path
import re
import sys

root = Path(sys.argv[1]).resolve()
pattern = re.compile(
    r'''(?P<key>(?P<q>['"])(?:label|placeholder|heading|button_text|aria-label|menu_title|page_title)(?P=q)\s*=>\s*)(?P<quote>['"])(?P<value>[^'"\r\n]{3,})(?P=quote)'''
)
total = 0
for file in root.glob("taxocommerce-*/**/*.php"):
    if ("tests" in file.parts or file.name == "class-tcsup-csv.php"
        or file.name.startswith("template-amazon-category")):
        continue
    original = file.read_text(encoding="utf-8")
    domain = file.relative_to(root).parts[0]
    def replace(m):
        global total
        value = m.group("value")
        if re.search(r"\b(google|bing|facebook|pinterest|instagram|linkedin|merchant)\b", value, re.I) and len(value.split()) < 4:
            return m.group(0)
        if not re.search(r"[A-Za-zÀ-ÿ]", value):
            return m.group(0)
        total += 1
        return m.group("key") + "__( " + m.group("quote") + value + m.group("quote") + ", '" + domain + "' )"
    updated = pattern.sub(replace, original)
    if updated != original:
        file.write_text(updated, encoding="utf-8")
print("Localized visible array labels:", total)
