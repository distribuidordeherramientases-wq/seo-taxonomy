#!/usr/bin/env python3
"""Aggregate the existing WordPress Plugin Check family run into one report.

The scanner runs against each standalone plugin's clean installable ZIP;
no WordPress administrator credentials or production changes are needed.
"""
import argparse
import json
import os
from pathlib import Path
import re
import sys

SLUG = re.compile(r"^taxocommerce-[a-z0-9-]+$")
STATUS = frozenset({"success", "failure", "cancelled", "skipped", "timed_out"})


def scan_findings(payload):
    """Parse the JSON output that wordpress/plugin-check-action produces."""
    # A clean Plugin Check run can emit only this success line instead of JSON.
    # Accept exactly the canonical message; never treat arbitrary text as clean.
    if payload.strip() == "Success: Checks complete. No errors found.":
        return []
    rows = []
    pending = ""
    current_file = ""
    parsed = False
    for original in payload.splitlines():
        line = original.strip()
        if not pending and line.startswith(("FILE:", "PLUGIN:")):
            current_file = line.partition(":")[2].strip()
            continue
        if not pending and not line.startswith(("{", "[")):
            continue
        pending += line
        try:
            value = json.loads(pending)
        except json.JSONDecodeError:
            continue
        parsed = True
        pending = ""
        candidates = value if isinstance(value, list) else [value]
        for item in candidates:
            if not isinstance(item, dict):
                continue
            kind = str(item.get("type", "")).upper()
            if kind in ("ERROR", "WARNING"):
                rows.append({
                    "type": kind,
                    "file": str(item.get("file") or current_file or "(plugin)"),
                    "code": str(item.get("code", "")),
                    "message": str(item.get("message", "")),
                })
    if pending:
        raise ValueError("Incomplete Plugin Check JSON result")
    if payload.strip() and not parsed:
        raise ValueError("Unrecognized non-empty Plugin Check result")
    return rows


def scan(slug, result_file, outcome, target):
    if not SLUG.fullmatch(slug) or outcome not in STATUS:
        raise ValueError("Invalid module slug or scanner outcome")
    result_path = Path(result_file)
    if result_path.is_file():
        raw = result_path.read_text(encoding="utf-8-sig")
        try:
            rows = scan_findings(raw)
            parse_error = None
        except (ValueError, UnicodeDecodeError) as exc:
            rows = []
            parse_error = str(exc)
    else:
        rows = []
        parse_error = "Plugin Check report missing"
    counts = {"ERROR": 0, "WARNING": 0}
    by_code = {}
    for item in rows:
        counts[item["type"]] += 1
        key = item["type"] + ": " + (item["code"] or "(unknown)")
        by_code[key] = by_code.get(key, 0) + 1
    # A failed action can indicate PHP startup, WP environment or scanner errors
    # even when the plugin-check JSON contains no findings.
    complete = outcome == "success" and parse_error is None
    record = {
        "slug": slug,
        "errors": counts["ERROR"],
        "warnings": counts["WARNING"],
        "check_outcome": outcome,
        "complete": complete,
        "failure_reason": parse_error or ("" if complete else "Plugin Check action did not pass"),
        "codes": dict(sorted(by_code.items(), key=lambda pair: (-pair[1], pair[0]))[:15]),
    }
    Path(target).write_text(json.dumps(record, indent=2) + "\n", encoding="utf-8")
    return record


def summarize(report_dir, expected, output):
    slugs = json.loads(expected)
    if (not isinstance(slugs, list) or len(set(slugs)) != len(slugs)
            or not all(isinstance(s, str) and SLUG.fullmatch(s) for s in slugs)):
        raise ValueError("Invalid expected module list")
    totals = [0, 0]
    bad = []
    table = []
    report_dir = Path(report_dir)
    for slug in sorted(slugs):
        file = report_dir / ("wordpress-readiness-" + slug) / "quality-state.json"
        try:
            data = json.loads(file.read_text(encoding="utf-8"))
            if data.get("slug") != slug:
                raise ValueError("Mismatched module in report")
            errors = data["errors"]
            warnings = data["warnings"]
            if type(errors) is not int or type(warnings) is not int or errors < 0 or warnings < 0:
                raise ValueError("Invalid count")
            complete = data.get("complete") is True
            check = data.get("check_outcome")
            if not complete or check != "success":
                status = "Fallido o incompleto"
            elif errors or warnings:
                status = "Con incidencias"
            else:
                status = "Correcto"
            totals[0] += errors
            totals[1] += warnings
            if status != "Correcto":
                bad.append(slug)
            table.append((slug, str(errors), str(warnings), status))
        except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError):
            bad.append(slug)
            table.append((slug, "-", "-", "Informe ausente o inválido"))
    md = [
        "# TaxoCommerce — Resumen de Plugin Check",
        "",
        "Este informe verifica **paquetes independientes de GitHub**, no los archivos instalados en PRODUCCIÓN.",
        "",
        f"**Módulos analizados:** {len(slugs)} | **Errores:** {totals[0]} | **Advertencias:** {totals[1]} | **Módulos pendientes:** {len(bad)}",
        "",
        "| Plugin | Errores | Advertencias | Resultado |",
        "|---|---:|---:|---|",
    ]
    md.extend("| " + " | ".join(row) + " |" for row in table)
    md += [
        "",
        "Los JSON y ZIP completos permanecen en los artefactos `wordpress-readiness-<plugin>` de esta ejecución.",
        "No se ha modificado PRO ni el plugin antiguo `seo-taxonomy`.",
        "",
    ]
    path = Path(output)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(md), encoding="utf-8")
    print("\n".join(md))
    if bad:
        raise RuntimeError(f"Plugin Check pending in {len(bad)} modules: {', '.join(bad)}")
    return totals


def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="cmd", required=True)
    one = sub.add_parser("scan")
    one.add_argument("slug")
    one.add_argument("report")
    one.add_argument("outcome")
    one.add_argument("output")
    all_ = sub.add_parser("summary")
    all_.add_argument("directory")
    all_.add_argument("slugs_json")
    all_.add_argument("output")
    args = parser.parse_args()
    if args.cmd == "scan":
        scan(args.slug, args.report, args.outcome, args.output)
    else:
        summarize(args.directory, args.slugs_json, args.output)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, RuntimeError) as error:
        print("Quality gate failed: " + str(error), file=sys.stderr)
        sys.exit(1)
