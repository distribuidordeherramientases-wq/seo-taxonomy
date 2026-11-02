#!/usr/bin/env python3
"""Offline replacement for missing native WordPress translations (#2062).

Uses open-source Argos language models to avoid public translation API quotas.
Preserves existing human PO entries, and leaves untranslatable strings empty
rather than treating a source-language copy as a completed translation.
"""
from __future__ import annotations
import collections
import os
import re
import sys
from pathlib import Path

import polib
import argostranslate.package as packages
import argostranslate.translate as translate
from langdetect import detect, DetectorFactory

DetectorFactory.seed = 0
ROOT = Path(__file__).resolve().parents[2]
LANGUAGES = {"es_ES":"es", "en_US":"en", "fr_FR":"fr", "de_DE":"de", "nl_NL":"nl"}
SLUGS = (
    "auditor", "campaigns", "connections", "core", "database", "dependiente",
    "distribution", "facturacion", "faqs", "google-bing", "images",
    "import-export", "logistica", "redirects", "scrappers", "social",
    "suppliers", "system-check", "templates", "vocabulary",
)
TOKEN = re.compile(r"%(?:\d+\$)?[-+0-9.]*[a-zA-Z]|\{\{[^{}]+\}\}|\{[A-Za-z_][\w.-]*\}|<[^<>]+>|\[[A-Za-z_/][^\]]*\]")
SPANISH = re.compile(r"[áéíóúñ¿¡]|\b(?:para|con|los|las|del|una|tienes|guardar|configuración|opciones|mostrar|errores|página|datos|usuario|productos|plantillas)\b", re.I)
ENGLISH = re.compile(r"\b(?:the|with|you|your|from|this|that|save|settings|options|display|errors|page|data|user|products|templates|download|upload)\b", re.I)
_CACHE = {}
STATS = collections.Counter()

def install_models(target):
    needed = [("en","es")] if target=="es" else ([("es","en")] if target=="en" else [("es","en"),("en",target)])
    packages.update_package_index()
    available = packages.get_available_packages()
    for pair in needed:
        entry = next((p for p in available if (p.from_code,p.to_code)==pair and p.type=="translate"),None)
        if entry is None:
            raise RuntimeError("Offline translation model not available: "+str(pair))
        packages.install_from_path(entry.download())
        print("Installed offline model:",pair,flush=True)

def source_language(text):
    text=TOKEN.sub(" ",text).strip()
    if SPANISH.search(text) and not ENGLISH.search(text):
        return "es"
    if ENGLISH.search(text) and not SPANISH.search(text):
        return "en"
    if len(text) < 4:
        return "en"
    try:
        guess=detect(text)
        return guess if guess in ("en","es") else "en"
    except Exception:
        return "en"

def translate_segment(segment,target,source):
    if source==target or not segment.strip():
        return segment
    key=(segment,target,source)
    if key in _CACHE:
        return _CACHE[key]
    try:
        if source=="es" and target not in ("es","en"):
            value=translate.translate(translate.translate(segment,"es","en"),"en",target)
        else:
            value=translate.translate(segment,source,target)
    except Exception as exc:
        STATS["model_errors"]+=1
        return ""
    if not isinstance(value,str) or not value.strip():
        return ""
    _CACHE[key]=value
    return value

def translate_string(text,target):
    if not text:
        return ""
    source=source_language(text)
    if source==target:
        return text
    parts=TOKEN.split(text)
    markers=TOKEN.findall(text)
    result=[]
    for i,part in enumerate(parts):
        rendered=translate_segment(part,target,source)
        if not rendered and part.strip():
            return ""
        result.append(rendered)
        if i<len(markers):
            result.append(markers[i])
    out="".join(result)
    if sorted(TOKEN.findall(text))!=sorted(TOKEN.findall(out)):
        return ""
    return out

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in LANGUAGES:
        raise SystemExit("Usage: i18n-family-2062-offline.py <locale>")
    locale=sys.argv[1]
    target=LANGUAGES[locale]
    install_models(target)
    destroot=Path(os.environ.get("TC_I18N_OUTPUT","/tmp/argosi18n-out"))
    for slug in SLUGS:
        domain="taxocommerce-"+slug
        langdir=ROOT/domain/"languages"
        po=polib.pofile(str(langdir/(domain+"-"+locale+".po")))
        filled=0
        for e in po:
            if e.obsolete or not e.msgid:
                continue
            if e.msgid_plural:
                for index, source in (("0",e.msgid),("1",e.msgid_plural)):
                    if not e.msgstr_plural.get(index):
                        candidate=translate_string(source,target)
                        if candidate:
                            e.msgstr_plural[index]=candidate
                            filled+=1
            elif not e.msgstr or "fuzzy" in e.flags:
                candidate=translate_string(e.msgid,target)
                if candidate:
                    e.msgstr=candidate
                    e.flags=[x for x in e.flags if x!="fuzzy"]
                    filled+=1
        output=destroot/domain/"languages"
        output.mkdir(parents=True,exist_ok=True)
        po.save(str(output/(domain+"-"+locale+".po")))
        po.save_as_mofile(str(output/(domain+"-"+locale+".mo")))
        remaining=sum(1 for e in po if e.msgid and not e.obsolete and
                      (not(e.msgstr or e.msgstr_plural) or
                       (e.msgstr_plural and not all(e.msgstr_plural.values()))))
        print(locale,domain,"filled:",filled,"remaining:",remaining,flush=True)
        STATS["added"]+=filled
        STATS["remaining"]+=remaining
    print("Summary",locale,dict(STATS),flush=True)

if __name__=="__main__":
    main()
