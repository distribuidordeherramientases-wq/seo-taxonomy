#!/usr/bin/env python3
"""One-time consolidation of GitHub repository labels. Issue #2054.

Does NOT create labels, change issue/PR content or state, or touch WordPress.
Requires a GitHub Actions token with issues:write to apply.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ALLOWED = frozenset({"Diagnosticar", "Corregir", "Implementar", "Validar", "Desplegar"})
API = "https://api.github.com"


def request(repo, method, path, payload=None):
    token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
    if not token:
        raise RuntimeError("GITHUB_TOKEN is required")
    url = API + "/repos/" + repo + path
    body = None if payload is None else json.dumps(payload, ensure_ascii=False).encode("utf-8")
    headers = {
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "taxocommerce-five-labels-cleanup",
    }
    if body is not None:
        headers["Content-Type"] = "application/json"
    for attempt in range(7):
        req = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=45) as response:
                data = response.read()
                return json.loads(data) if data else None
        except urllib.error.HTTPError as exc:
            content = exc.read().decode("utf-8", errors="replace")[:350]
            # Respect transient server and GitHub secondary rate limits.
            if exc.code not in (403, 429, 500, 502, 503, 504) or attempt == 6:
                raise RuntimeError(f"GitHub API {method} {path}: HTTP {exc.code}: {content}") from exc
            pause = min(90, 4 * (attempt + 1) ** 2)
            print(f"Retry HTTP {exc.code} after {pause}s ({method} {path})", flush=True)
            time.sleep(pause)
    raise RuntimeError("Unreachable retry state")


def all_pages(repo, subpath):
    values = []
    page = 1
    while True:
        sep = "&" if "?" in subpath else "?"
        batch = request(repo, "GET", f"{subpath}{sep}per_page=100&page={page}")
        if not isinstance(batch, list):
            raise RuntimeError("Unexpected GitHub API pagination response")
        values.extend(batch)
        if len(batch) < 100:
            break
        page += 1
    return values


def classify(old_name, title, body):
    """Map a legacy category onto an existing approved category."""
    if old_name == "bug":
        return "Corregir"
    if old_name in ("enhancement", "Nueva funcionalidad", "Documentacion"):
        return "Implementar"
    if old_name != "Corregir/ampliar funcionalidad":
        raise ValueError(f"Unknown legacy label: {old_name}")
    t = (title or "").lower()
    # Titles are more indicative than large bodies mixing diagnosis and plans.
    if re.search(r"\b(error|errores|bug|fallo|fallos|regresi[oó]n|crash|fatal|"
                 r"arreglar|corregir|reparar|restaurar|recuperar|resolver|bloqueado|"
                 r"no funciona|evitar|impedir|solucionar|incidencia|atascad[oa]|"
                 r"rot[oa]|duplicad[oa])\b", t):
        return "Corregir"
    if re.search(r"\b(diagn[oó]stico|diagnosticar|investigar|auditor[ií]a|"
                 r"revisi[oó]n|an[aá]lisis|inventario|índice de trabajo)\b", t):
        return "Diagnosticar"
    if re.search(r"\b(despliegue|desplegar|publicar en pro|release|"
                 r"instalar en pro)\b", t):
        return "Desplegar"
    if re.search(r"\b(validar|verificar|pruebas|comprobar|qa)\b", t):
        return "Validar"
    return "Implementar"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="Changes repository; default is dry-run")
    args = parser.parse_args()
    repo = os.environ.get("GITHUB_REPOSITORY", "")
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo):
        raise RuntimeError("Expected GITHUB_REPOSITORY=owner/name")
    if args.apply and os.environ.get("GITHUB_REF") != "refs/heads/staging":
        raise RuntimeError("Mutations permitted only from the staging branch")

    definitions = all_pages(repo, "/labels")
    existing = {label["name"] for label in definitions}
    missing = ALLOWED - existing
    if missing:
        # Never create labels: explicitly request a human decision instead.
        raise RuntimeError(f"Approved labels missing; no changes made: {sorted(missing)}")

    legacy = sorted(existing - ALLOWED)
    issues = all_pages(repo, "/issues?state=all")
    plans = []
    for issue in issues:
        originals = {label["name"] for label in issue.get("labels", [])}
        unexpected = originals - ALLOWED
        if not unexpected:
            continue
        targets = set(originals & ALLOWED)
        for name in unexpected:
            targets.add(classify(name, issue.get("title", ""), issue.get("body", "")))
        plans.append({
            "number": issue["number"],
            "kind": "PR" if issue.get("pull_request") else "Issue",
            "from": sorted(originals),
            "to": sorted(targets),
        })

    print(f"Repository: {repo}", flush=True)
    print(f"Approved labels ({len(ALLOWED)}): {', '.join(sorted(ALLOWED))}", flush=True)
    print(f"Legacy labels ({len(legacy)}): {legacy}", flush=True)
    print(f"Issues and PRs: {len(issues)}; to migrate: {len(plans)}", flush=True)
    report = Path(os.environ.get("RUNNER_TEMP", "/tmp")) / "taxocommerce-labels-migration.json"
    report.write_text(json.dumps({
        "repository": repo, "approved": sorted(ALLOWED),
        "obsolete_definitions": legacy, "migrations": plans,
    }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Backup report: {report}", flush=True)
    if not args.apply:
        print("DRY RUN: no issues or labels were modified.", flush=True)
        return

    migrated = 0
    for plan in plans:
        request(repo, "PATCH", f"/issues/{plan['number']}", {"labels": plan["to"]})
        migrated += 1
        if migrated % 50 == 0 or migrated == len(plans):
            print(f"Reclassified {migrated}/{len(plans)} issues and PRs", flush=True)
        # Respect GitHub secondary rate limiting for mutating issue labels.
        time.sleep(0.8)

    # Verify migration before deleting a single repository label definition.
    residual = []
    for issue in all_pages(repo, "/issues?state=all"):
        names = {label["name"] for label in issue.get("labels", [])}
        if names - ALLOWED:
            residual.append(issue["number"])
    if residual:
        raise RuntimeError(f"Historical labels still assigned to issues: {residual[:30]}")

    for label in legacy:
        encoded = urllib.parse.quote(label, safe="")
        request(repo, "DELETE", f"/labels/{encoded}")
        print(f"Deleted repository label definition: {label}", flush=True)

    after = {label["name"] for label in all_pages(repo, "/labels")}
    if after != ALLOWED:
        raise RuntimeError(f"Repository labels do not match exact allowlist: {sorted(after)}")
    print(f"SUCCESS: exactly five approved labels remain; {migrated} issue/PR label sets updated.", flush=True)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as output:
            output.write(f"### Label consolidation verified\n\n")
            output.write(f"- Existing approved labels: **5/5**\n")
            output.write(f"- Issues and PRs reclassified: **{migrated}**\n")
            output.write(f"- Legacy label definitions deleted: **{len(legacy)}**\n")
            output.write(f"- Remaining unapproved labels: **0**\n")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, RuntimeError, urllib.error.URLError) as exc:
        print("ERROR: " + str(exc), file=sys.stderr)
        sys.exit(1)
