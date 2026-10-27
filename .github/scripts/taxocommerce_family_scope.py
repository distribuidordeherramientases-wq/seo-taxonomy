#!/usr/bin/env python3
"""Choose only changed standalone TaxoCommerce plugins in normal CI.

workflow_dispatch intentionally validates every plugin. The old monolithic
TaxoCommerce SEO plugin is never discovered or selected here.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path


def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], text=True, stderr=subprocess.PIPE).strip()


def modules() -> list[str]:
    result = sorted(
        child.name
        for child in Path(".").glob("taxocommerce-*")
        if child.is_dir() and (child / (child.name + ".php")).is_file()
    )
    # Scrappers is a standalone extension since 2026-10-27.
    # Keep the inventory assertion strict so missing/unexpected packages fail CI.
    if len(result) != 20:
        raise RuntimeError(f"Expected 20 standalone plugins; found {len(result)}: {result}")
    return result


def changed_files(base: str, head: str) -> list[str]:
    if not base or base == "0" * 40:
        raise RuntimeError("Missing or zero diff base; refusing an incomplete plugin selection")
    return git("diff", "--name-only", "--diff-filter=ACMRT", base, head).splitlines()


def main() -> int:
    available = modules()
    event = os.environ.get("EVENT_NAME", "")
    if event == "workflow_dispatch":
        chosen = available
    elif event in {"pull_request", "push"}:
        base = os.environ.get("PR_BASE_SHA" if event == "pull_request" else "PUSH_BEFORE_SHA", "")
        head = os.environ.get("HEAD_SHA", "")
        try:
            changed = changed_files(base, head)
        except (RuntimeError, subprocess.CalledProcessError) as exc:
            print(f"Diff unavailable ({exc}); checking all standalone plugins", file=sys.stderr)
            chosen = available
        else:
            global_checks = {
                ".github/workflows/taxocommerce-family-plugin-check.yml",
                ".github/scripts/taxocommerce_family_readme_check.py",
                ".github/scripts/taxocommerce_family_scope.py",
            }
            if global_checks.intersection(changed):
                chosen = available
            else:
                chosen = sorted({
                    path.split("/", 1)[0]
                    for path in changed
                    if path.split("/", 1)[0] in available
                })
    else:
        raise RuntimeError(f"Unexpected GitHub event {event!r}")
    output = json.dumps(chosen, ensure_ascii=True, separators=(",", ":"))
    print(f"WordPress.org readiness: selected {len(chosen)} / {len(available)}: {output}")
    out_file = os.environ.get("GITHUB_OUTPUT")
    if not out_file:
        raise RuntimeError("GitHub job output environment is missing")
    with open(out_file, "a", encoding="utf-8") as handle:
        handle.write(f"slugs={output}\ncount={len(chosen)}\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
