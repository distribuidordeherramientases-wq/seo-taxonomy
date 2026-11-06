#!/usr/bin/env python3
"""Generate only the existing TaxoCommerce Auditor WordPress PO/MO dictionaries.

Development-time only. Reuses i18n-family-2062.py's translation and
placeholder protection, does not run in WordPress and is not packaged.
"""
import concurrent.futures
import json
import re
import runpy
from pathlib import Path
import polib

ROOT = Path(__file__).resolve().parents[2]
DOMAIN = "taxocommerce-auditor"
LANG = ROOT / DOMAIN / "languages"
LOCALES = {"es_ES": "es", "en_US": "en", "fr_FR": "fr", "de_DE": "de", "nl_NL": "nl"}
HELPERS = runpy.run_path(str(Path(__file__).with_name("i18n-family-2062.py")))
translate_many = HELPERS["translate_many"]
translate_one = HELPERS["translate_one"]
placeholder = HELPERS["PLH_RE"]

def check(source, target):
    # Translation must preserve every sprintf placeholder, shortcode and HTML tag.
    return sorted(placeholder.findall(source)) == sorted(placeholder.findall(target))

def main():
    pot = polib.pofile(str(LANG / (DOMAIN + ".pot")))
    strings = [e.msgid for e in pot if e.msgid]
    total = len(strings)
    report = {}
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as executor:
        for locale, target in LOCALES.items():
            path = LANG / (DOMAIN + "-" + locale + ".po")
            old = polib.pofile(str(path))
            previous = {
                (e.msgctxt, e.msgid): e.msgstr
                for e in old if e.msgid and not e.obsolete and e.msgstr
                and "fuzzy" not in e.flags and check(e.msgid, e.msgstr)
            }
            values = {}
            todo = []
            for e in pot:
                if not e.msgid:
                    continue
                value = previous.get((e.msgctxt, e.msgid), "")
                if value:
                    values[e.msgid] = value
                else:
                    todo.append(e.msgid)
            jobs = [executor.submit(translate_many, target, todo[i:i + 12])
                    for i in range(0, len(todo), 12)]
            for job in jobs:
                for key, value in job.result().items():
                    if value and check(key, value):
                        values[key] = value
            missing = [s for s in strings if not values.get(s)]
            # Retry a small number of failed strings one at a time.
            for key in missing[:30]:
                value = translate_one(target, key)
                if value and check(key, value):
                    values[key] = value
            po = polib.POFile()
            po.metadata = {
                "Project-Id-Version": "TaxoCommerce Auditor 0.3.6",
                "Language": locale,
                "MIME-Version": "1.0",
                "Content-Type": "text/plain; charset=UTF-8",
                "Content-Transfer-Encoding": "8bit",
                "Plural-Forms": "nplurals=2; plural=(n != 1);",
                "X-Domain": DOMAIN,
            }
            for e in pot:
                if not e.msgid:
                    continue
                clone = polib.POEntry(
                    msgid=e.msgid,
                    msgctxt=e.msgctxt,
                    occurrences=e.occurrences,
                    msgstr=values.get(e.msgid, ""),
                )
                po.append(clone)
            po.save(str(path))
            mo = LANG / (DOMAIN + "-" + locale + ".mo")
            po.save_as_mofile(str(mo))
            empty = [e.msgid for e in po if not e.msgstr]
            report[locale] = {"total": total, "translated": total-len(empty),
                              "missing": len(empty), "examples": empty[:8]}
            print("AUDITOR I18N", locale, json.dumps(report[locale], ensure_ascii=False), flush=True)
    print("AUDITOR-I18N-REPORT " + json.dumps(report, ensure_ascii=False), flush=True)
    if any(v["missing"] for v in report.values()):
        print("WARNING: incomplete Auditor translations; must fix before merge.", flush=True)

if __name__ == "__main__":
    main()
