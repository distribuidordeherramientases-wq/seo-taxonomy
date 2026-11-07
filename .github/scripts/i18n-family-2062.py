#!/usr/bin/env python3
"""Regenerate native WordPress gettext catalogs for the 19 current standalone plugins.

Only display arguments of known admin menu functions are rewritten. Do not
translate technical identifiers, stored options, URLs, SQL, CSS or data.
Translation failures remain empty in PO and are reported, never disguised as
completed translations.
"""
from __future__ import annotations

import argparse
import collections
import concurrent.futures
import json
import runpy
import os
import re
import subprocess
import sys
import threading
import time
from pathlib import Path

import polib
import requests

ROOT = Path(__file__).resolve().parents[2]
LANGUAGES = {"es_ES": "es", "en_US": "en", "fr_FR": "fr", "de_DE": "de", "nl_NL": "nl"}
PLUGINS = (
    "auditor", "campaigns", "core", "database", "dependiente",
    "distribution", "facturacion", "faqs", "google-bing", "images",
    "import-export", "logistica", "redirects", "scrappers", "social",
    "suppliers", "system-check", "templates", "vocabulary",
)
MENU_FUNCS = {
    "add_menu_page": (0, 1),
    "add_submenu_page": (1, 2),
    "add_options_page": (0, 1),
    "add_management_page": (0, 1),
    "add_dashboard_page": (0, 1),
    "add_theme_page": (0, 1),
    "add_plugins_page": (0, 1),
    "add_users_page": (0, 1),
    "add_posts_page": (0, 1),
    "add_pages_page": (0, 1),
    "add_media_page": (0, 1),
    "add_comments_page": (0, 1),
}
CALL_RE = re.compile(r"\b(" + "|".join(MENU_FUNCS) + r")\s*\(")
PLH_RE = re.compile(
    r"%(?:\d+\$)?[-+0-9.]*[a-zA-Z]|"
    r"\{\{[^{}]+\}\}|\{[a-zA-Z_][\w.-]*\}|"
    r"<[^<>]+>|\[[a-zA-Z_/][^\]]*\]"
)
TRANS_LOCK = threading.Lock()
TRANSLATED = {}
FAILED = set()
REPORT = {}
WRAP_ECHO = runpy.run_path(str(Path(__file__).with_name('i18n-family-2062-extra.py')))['wrap_literal_echo']

def matching_paren(src: str, at: int) -> int | None:
    depth, quote, escaped = 0, None, False
    for i in range(at, len(src)):
        c = src[i]
        if quote is not None:
            if escaped:
                escaped = False
            elif c == "\\":
                escaped = True
            elif c == quote:
                quote = None
        elif c in ("'", '"'):
            quote = c
        elif c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                return i
    return None

def split_args(s: str) -> list[str]:
    out, start, depth, quote, escaped = [], 0, 0, None, False
    for i, c in enumerate(s):
        if quote is not None:
            if escaped:
                escaped = False
            elif c == "\\":
                escaped = True
            elif c == quote:
                quote = None
        elif c in ("'", '"'):
            quote = c
        elif c in "([{":
            depth += 1
        elif c in ")]}":
            depth -= 1
        elif c == "," and depth == 0:
            out.append(s[start:i])
            start = i + 1
    out.append(s[start:])
    return out

def wrap_menu_labels(src: str, domain: str) -> tuple[str, int]:
    patches = []
    for match in CALL_RE.finditer(src):
        open_pos = match.end() - 1
        close_pos = matching_paren(src, open_pos)
        if close_pos is None:
            continue
        body = src[open_pos + 1:close_pos]
        fields = split_args(body)
        changed = 0
        for i in MENU_FUNCS[match.group(1)]:
            if i >= len(fields):
                continue
            original = fields[i].strip()
            quoted = re.fullmatch(r"""(['"])([^\r\n]*?)\1""", original)
            if not quoted:
                continue
            value = quoted.group(2)
            if not value or "\\" in value or "$" in value or not re.search(r"[a-zA-ZÀ-ÿ]", value):
                continue
            fields[i] = fields[i].replace(
                original, "__( " + original + ", '" + domain + "' )", 1
            )
            changed += 1
        if changed:
            patches.append((open_pos + 1, close_pos, ",".join(fields), changed))
    for start, end, value, _ in reversed(patches):
        src = src[:start] + value + src[end:]
    return src, sum(p[3] for p in patches)

def add_loader(src: str, slug: str) -> str:
    domain = "taxocommerce-" + slug
    if "Domain Path:" not in src[:1600]:
        src = src.replace(
            " * Text Domain: " + domain + "\n",
            " * Text Domain: " + domain + "\n * Domain Path: /languages\n",
            1,
        )
    # Core already has a thoroughly tested dedicated local fallback loader.
    if slug == "core" or "taxocommerce_" + slug.replace("-", "_") + "_load_textdomain" in src:
        return src
    marker = "/* Native local gettext catalog fallback (#2062). */"
    if marker in src:
        return src
    snippet = """
%s
add_action( 'init', static function () {
    $domain = '%s';
    if ( is_textdomain_loaded( $domain ) ) {
        return;
    }
    $locale = sanitize_file_name( determine_locale() );
    // Prefer official WordPress language packs when installed.
    if ( is_readable( WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo' ) ) {
        return;
    }
    $catalog = __DIR__ . '/languages/' . $domain . '-' . $locale . '.mo';
    if ( is_readable( $catalog ) ) {
        load_textdomain( $domain, $catalog );
    }
}, 1 );
""" % (marker, domain)
    return src.rstrip() + "\n" + snippet

def run(cmd: list[str]) -> None:
    subprocess.run(cmd, cwd=ROOT, check=True, capture_output=False)

def protect(s: str):
    patterns = []
    def replacer(match):
        patterns.append(match.group(0))
        return "ZXQTOKEN" + str(len(patterns) - 1) + "ENDZX"
    return PLH_RE.sub(replacer, s), patterns

def unprotect(s: str, patterns: list[str]) -> str | None:
    for i, original in enumerate(patterns):
        token = "ZXQTOKEN" + str(i) + "ENDZX"
        if s.count(token) != 1:
            return None
        s = s.replace(token, original)
    if "ZXQTOKEN" in s:
        return None
    return s

def translate_one(target: str, string: str) -> str:
    if not string.strip():
        return string
    key = target, string
    with TRANS_LOCK:
        if key in TRANSLATED:
            return TRANSLATED[key]
        if key in FAILED:
            return ""
    protected, placeholders = protect(string)
    # Public gettext source only; never send site content or credentials.
    result = None
    for attempt in range(2):
        try:
            response = requests.get(
                "https://translate.googleapis.com/translate_a/single",
                params={"client": "gtx", "sl": "auto", "tl": target, "dt": "t", "q": protected},
                timeout=12,
            )
            response.raise_for_status()
            data = response.json()
            rendered = "".join(chunk[0] or "" for chunk in data[0])
            result = unprotect(rendered, placeholders)
            if result is not None and result.strip():
                break
        except (requests.RequestException, ValueError, KeyError, TypeError, IndexError):
            pass
        time.sleep(0.25 + attempt * 0.5)
    if result is None:
        with TRANS_LOCK:
            FAILED.add(key)
        return ""
    with TRANS_LOCK:
        TRANSLATED[key] = result
    return result

def translate_many(target: str, strings: list[str]) -> dict[str, str]:
    """Translate independent gettext messages in one request, verifying boundaries."""
    if not strings:
        return {}
    if len(strings) == 1:
        return {strings[0]: translate_one(target, strings[0])}
    protected = [protect(s) for s in strings]
    marker = "ZXQSEGMENTBREAKZXQ"
    payload = ("\n" + marker + "\n").join(item[0] for item in protected)
    rendered = None
    for attempt in range(2):
        try:
            response = requests.get(
                "https://translate.googleapis.com/translate_a/single",
                params={"client": "gtx", "sl": "auto", "tl": target, "dt": "t", "q": payload},
                timeout=18,
            )
            response.raise_for_status()
            data = response.json()
            rendered = "".join(item[0] or "" for item in data[0])
            break
        except (requests.RequestException, ValueError, KeyError, TypeError, IndexError):
            time.sleep(0.25 + attempt * 0.5)
    if rendered is None:
        with TRANS_LOCK:
            for string in strings:
                FAILED.add((target, string))
        return {string: "" for string in strings}
    parts = rendered.split(marker)
    if len(parts) != len(strings):
        middle = len(strings) // 2
        left = translate_many(target, strings[:middle])
        left.update(translate_many(target, strings[middle:]))
        return left
    result = {}
    for source, (translated, templates) in zip(strings, zip(parts, protected)):
        value = unprotect(translated.strip(), templates[1])
        if not value:
            result[source] = translate_one(target, source)
        else:
            result[source] = value
            with TRANS_LOCK:
                TRANSLATED[target, source] = value
    return result

def gettext_entries(domain_dir: Path, domain: str, locale: str, target: str, executor):
    base = domain_dir / "languages"
    pot = polib.pofile(str(base / (domain + ".pot")))
    dest = base / (domain + "-" + locale + ".po")
    existing = polib.pofile(str(dest)) if dest.exists() else polib.POFile()
    previous = {(e.msgctxt, e.msgid): e for e in existing if not e.obsolete}
    po = polib.POFile()
    po.metadata = dict(existing.metadata)
    po.metadata.update({
        "Project-Id-Version": domain,
        "Language": locale,
        "MIME-Version": "1.0",
        "Content-Type": "text/plain; charset=UTF-8",
        "Content-Transfer-Encoding": "8bit",
        "Plural-Forms": "nplurals=2; plural=(n != 1);",
    })
    translations = {}
    for e in pot:
        if not e.msgid:
            continue
        old = previous.get((e.msgctxt, e.msgid))
        if old and old.msgstr and "fuzzy" not in old.flags:
            translations[e.msgid] = old.msgstr
    missing = {e.msgid for e in pot if e.msgid and e.msgid not in translations}
    sources = sorted(missing)
    groups = [sources[i:i + 18] for i in range(0, len(sources), 18)]
    pending = [executor.submit(translate_many, target, group) for group in groups]
    for future in pending:
        try:
            translations.update(future.result())
        except Exception:
            pass
    blank = []
    for e in pot:
        if not e.msgid:
            continue
        old = previous.get((e.msgctxt, e.msgid))
        copied = polib.POEntry(
            msgid=e.msgid,
            msgid_plural=e.msgid_plural,
            msgctxt=e.msgctxt,
            occurrences=e.occurrences,
            comment=e.comment,
            tcomment=e.tcomment,
        )
        copied.msgstr = translations.get(e.msgid, "")
        if e.msgid_plural:
            # Plural is still separate in gettext; never substitute the singular.
            plural_old = old.msgstr_plural if old else {}
            copied.msgstr_plural = {
                str(i): plural_old.get(str(i), "") or (
                    translations.get(e.msgid, "") if i == 0 else translate_one(target, e.msgid_plural)
                ) for i in (0, 1)
            }
            if not all(copied.msgstr_plural.values()):
                blank.append(e.msgid)
        elif not copied.msgstr:
            blank.append(e.msgid)
        po.append(copied)
    po.save(str(dest))
    po.save_as_mofile(str(base / (domain + "-" + locale + ".mo")))
    return {"strings": len(po), "translated": len(po) - len(blank), "missing": len(blank),
            "examples_missing": blank[:6]}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    plugins = [ROOT / ("taxocommerce-" + slug) for slug in PLUGINS]
    if any(not (p / (p.name + ".php")).exists() for p in plugins):
        raise RuntimeError("Missing standalone plugin; refuse incomplete family generation")
    for slug, directory in zip(PLUGINS, plugins):
        entry = directory / (directory.name + ".php")
        source = entry.read_text(encoding="utf-8")
        new = add_loader(source, slug)
        new, count = wrap_menu_labels(new, "taxocommerce-" + slug)
        new, echo_count = WRAP_ECHO(new, "taxocommerce-" + slug)
        if new != source and args.apply:
            entry.write_text(new, encoding="utf-8")
        menu_count = count
        for file in directory.rglob("*.php"):
            if file == entry or "tests" in file.parts:
                continue
            source = file.read_text(encoding="utf-8")
            new, count = wrap_menu_labels(source, "taxocommerce-" + slug)
            menu_count += count
            new, converted_echo = WRAP_ECHO(new, "taxocommerce-" + slug)
            echo_count += converted_echo
            if file.name == "seo-core-validation-visual.php":
                # Existing trailing space becomes a new patch line when gettext is added.
                new = re.sub(r"(?m)[ \t]+$", "", new)
            if new != source and args.apply:
                file.write_text(new, encoding="utf-8")
        REPORT[slug] = {"menu_labels_newly_internationalized": menu_count, "echo_texts_newly_internationalized": echo_count}
        if args.apply:
            changelog = directory / "CHANGELOG.md"
            if changelog.exists():
                current = changelog.read_text(encoding="utf-8")
                if "Familia i18n #2062" not in current:
                    changelog.write_text(
                        "## Unreleased - Familia i18n #2062\n\n"
                        "- Dominio gettext, carga de catalogos locales, etiquetas de menús y textos HTML de salida estáticos; "
                        "catálogos PO/MO sincronizados con el texto fuente.\n\n" + current,
                        encoding="utf-8",
                    )
            (directory / "languages").mkdir(exist_ok=True)
            run([
                "php", "/tmp/wp-cli.phar", "i18n", "make-pot",
                str(directory), str(directory / "languages" / (directory.name + ".pot")),
                "--domain=" + directory.name,
                "--exclude=tests,uninstall.php",
                "--skip-audit",
            ])
    if not args.apply:
        print(json.dumps(REPORT, indent=2))
        return
    # Share successful identical translations across the twenty plugins.
    with concurrent.futures.ThreadPoolExecutor(max_workers=12) as executor:
        for slug, directory in zip(PLUGINS, plugins):
            domain = directory.name
            REPORT[slug]["locales"] = {
                locale: gettext_entries(directory, domain, locale, target, executor)
                for locale, target in LANGUAGES.items()
            }
            print("Completed", domain, json.dumps(REPORT[slug]["locales"]), flush=True)
    out = ROOT / ".github" / "i18n-family-2062-report.json"
    out.parent.mkdir(exist_ok=True)
    out.write_text(json.dumps(REPORT, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    failures = sum(d["missing"] for plugin in REPORT.values() for d in plugin["locales"].values())
    print("Missing gettext translations:", failures)
    if failures:
        print("WARNING: translation catalogs incomplete; issue #2062 must remain open.")

if __name__ == "__main__":
    main()
