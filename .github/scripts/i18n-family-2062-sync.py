#!/usr/bin/env python3
"""Sync WordPress POT -> PO/MO without discarding already translated messages."""
from pathlib import Path
import polib
import sys

root = Path(sys.argv[1]).resolve()
locales = ("es_ES", "en_US", "fr_FR", "de_DE", "nl_NL")
changed = 0
for plugin in sorted(root.glob("taxocommerce-*")):
    if not plugin.is_dir() or not (plugin / (plugin.name + ".php")).exists():
        continue
    directory = plugin / "languages"
    pot = polib.pofile(str(directory / (plugin.name + ".pot")))
    for locale in locales:
        target = directory / (plugin.name + "-" + locale + ".po")
        existing = polib.pofile(str(target)) if target.exists() else polib.POFile()
        old_entries = {
            (entry.msgctxt, entry.msgid): entry for entry in existing
            if not entry.obsolete and entry.msgid
        }
        updated = polib.POFile()
        updated.metadata = dict(existing.metadata)
        updated.metadata["Language"] = locale
        updated.metadata.setdefault("Content-Type", "text/plain; charset=UTF-8")
        for entry in pot:
            if not entry.msgid:
                continue
            prior = old_entries.get((entry.msgctxt, entry.msgid))
            if prior and "fuzzy" not in prior.flags:
                entry.msgstr = prior.msgstr
                entry.msgstr_plural = {
                    str(index): value for index, value in prior.msgstr_plural.items()
                }
            updated.append(entry)
        updated.save(str(target))
        updated.save_as_mofile(str(directory / (plugin.name + "-" + locale + ".mo")))
        changed += 1
print("Refreshed PO/MO files preserving prior translations:", changed)
