#!/usr/bin/env python3
"""Independent repository i18n QA; exit nonzero for missing native catalogs.

The text-candidate audit is deliberately informational: it inventories UI
strings still outside gettext and prevents declaring completion based only
on 100% of a partial POT catalog.
"""
from __future__ import annotations
import gettext
import json
import re
import sys
from pathlib import Path
import polib

ROOT = Path(__file__).resolve().parents[2]
LOCALES = ("es_ES", "en_US", "fr_FR", "de_DE", "nl_NL")
PLUGINS = (
    "auditor", "campaigns", "core", "database", "dependiente",
    "distribution", "facturacion", "faqs", "google-bing", "images",
    "import-export", "logistica", "redirects", "scrappers", "social",
    "suppliers", "system-check", "templates", "vocabulary",
)
UI_CANDIDATES = re.compile(
    r"""\becho\s+(['"])(?:<[^>]*>)*([A-Za-zÀ-ÿ][^;'"]{3,})\1\s*;|"""
    r"""['"](?:label|placeholder|heading|button_text|aria-label|menu_title|page_title)['"]\s*=>\s*(['"])([^'"]{3,})\3""",
    re.IGNORECASE
)
PLACEHOLDERS = re.compile(r"%(?:\d+\$)?[-+0-9.]*[a-zA-Z]|\{\{[^{}]+\}\}|\{[a-zA-Z_][\w.-]*\}")

def main():
    report, errors = {}, []
    for name in PLUGINS:
        domain = "taxocommerce-" + name
        directory = ROOT / domain
        main_file = directory / (domain + ".php")
        header = main_file.read_text(encoding="utf-8")[:1400]
        if "Text Domain: " + domain not in header:
            errors.append(domain + " text domain header missing/mismatched")
        if "Domain Path: /languages" not in header:
            errors.append(domain + " domain path missing")
        langdir = directory / "languages"
        potfile = langdir / (domain + ".pot")
        if not potfile.exists():
            errors.append(domain + " POT missing")
            continue
        pot = polib.pofile(str(potfile))
        source_keys = {(e.msgctxt, e.msgid) for e in pot if e.msgid}
        per_locale = {}
        for locale in LOCALES:
            pofile = langdir / (domain + "-" + locale + ".po")
            mofile = langdir / (domain + "-" + locale + ".mo")
            if not (pofile.exists() and mofile.exists()):
                errors.append(domain + " " + locale + " PO/MO missing")
                continue
            po = polib.pofile(str(pofile), check_for_duplicates=True)
            have = {(e.msgctxt, e.msgid) for e in po if e.msgid and not e.obsolete}
            if have != source_keys:
                errors.append(domain + " " + locale + " stale catalog (" + str(len(have ^ source_keys)) + " keys)")
            untranslated = [e.msgid for e in po if not e.obsolete and e.msgid
                            and (not (e.msgstr or e.msgstr_plural) or
                                 (e.msgstr_plural and not all(e.msgstr_plural.values())))]
            for e in po:
                if e.obsolete or not e.msgid or not e.msgstr:
                    continue
                if sorted(PLACEHOLDERS.findall(e.msgid)) != sorted(PLACEHOLDERS.findall(e.msgstr)):
                    errors.append(domain + " " + locale + " placeholder mismatch: " + e.msgid[:45])
            try:
                with mofile.open("rb") as handle:
                    catalog = gettext.GNUTranslations(handle)
                if not catalog.info():
                    errors.append(domain + " " + locale + " malformed/empty compiled MO")
            except Exception as error:
                errors.append(domain + " " + locale + " invalid MO: " + str(error))
            per_locale[locale] = {"keys": len(have), "untranslated": len(untranslated)}
        candidates = []
        for path in directory.rglob("*.php"):
            if "tests" in path.parts or "languages" in path.parts:
                continue
            text = path.read_text(encoding="utf-8", errors="replace")
            for m in UI_CANDIDATES.finditer(text):
                value = (m.group(2) or m.group(4) or "").strip()
                if value and not value.startswith("taxocommerce-"):
                    line = text.count("\n", 0, m.start()) + 1
                    candidates.append({"file": str(path.relative_to(ROOT)), "line": line, "text": value[:70]})
        report[domain] = {"pot_keys": len(source_keys), "locale": per_locale,
                          "possible_unwrapped_ui_count": len(candidates),
                          "sample_unwrapped": candidates[:15]}
    dest = ROOT / ".github/i18n-family-2062-qa.json"
    dest.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({
        "plugins": len(report),
        "catalog_errors": errors[:60],
        "possible_unwrapped_ui_total": sum(r["possible_unwrapped_ui_count"] for r in report.values()),
        "missing_translation_total": sum(v["untranslated"] for r in report.values()
                                         for v in r["locale"].values()),
    }, ensure_ascii=False, indent=2))
    if errors:
        sys.exit(1)

if __name__ == "__main__":
    main()
