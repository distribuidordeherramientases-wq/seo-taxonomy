#!/usr/bin/env python3
"""Build reproducible per-module FTPS deltas from the last verified PRO revision.

Files in tests, hidden metadata and runtime data are never distributed.
Never infer a baseline from a failed deployment or from GitHub's current HEAD.
"""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import zipfile

SLUG = re.compile(r"taxocommerce-[a-z0-9-]+\Z")
EXCLUDED_PARTS = {"tests", "uploads", "node_modules", "__pycache__"}
EXCLUDED_EXTENSIONS = (".md", ".pot", ".bak", ".pyc")


def git_files(base, head, diff_filter):
    raw = subprocess.check_output([
        "git", "diff", "--name-only", "-z", "--diff-filter=" + diff_filter,
        base, head, "--", "taxocommerce-*",
    ])
    return [p.decode("utf-8") for p in raw.split(b"\x00") if p]


def plugin_path(path):
    parts = Path(path).parts
    if len(parts) < 2 or not SLUG.fullmatch(parts[0]):
        return None
    rel = parts[1:]
    if any(p in EXCLUDED_PARTS or p.startswith(".") for p in rel):
        return None
    if rel[-1].lower().endswith(EXCLUDED_EXTENSIONS):
        return None
    return parts[0], "/".join(rel)


def build(base, head, installed, output):
    if not re.fullmatch(r"[0-9a-f]{40}", base) or not re.fullmatch(r"[0-9a-f]{40}", head):
        raise ValueError("Verified base and checked-out head must be 40-char Git hashes")
    subprocess.run(["git", "merge-base", "--is-ancestor", base, head], check=True)
    if len(installed) != len(set(installed)) or not all(SLUG.fullmatch(x) for x in installed):
        raise ValueError("Invalid installed module inventory")
    installed = set(installed)
    modules = {}
    for name in git_files(base, head, "ACMRT"):
        parsed = plugin_path(name)
        if parsed and Path(name).is_file():
            modules.setdefault(parsed[0], set()).add(parsed[1])
    deleted = [p for p in git_files(base, head, "D") if plugin_path(p)]
    if deleted:
        raise ValueError("Runtime files removed since verified PRO baseline; explicit reviewed removal required: " + ", ".join(deleted))
    output.mkdir(parents=True, exist_ok=True)
    selected, uninstalled = [], []
    for slug in sorted(modules):
        files = sorted(modules[slug])
        if not (Path(slug) / (slug + ".php")).is_file() or not (Path(slug) / "readme.txt").is_file():
            raise ValueError("Missing canonical plugin entrypoint or readme: " + slug)
        if slug not in installed:
            uninstalled.append(slug)
            continue
        archive = output / (slug + ".zip")
        with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED) as z:
            for name in files:
                file = Path(slug) / name
                if not file.is_file():
                    raise ValueError("Changed file missing from checkout: " + str(file))
                z.write(file, arcname=name)
        with zipfile.ZipFile(archive) as z:
            if z.namelist() != files or not z.testzip() is None:
                raise ValueError("Invalid delta ZIP for " + slug)
        selected.append({"slug": slug, "files": files})
    return {"verified_base": base, "source": head, "installed": selected, "uninstalled": uninstalled}


def main():
    baseline = os.environ["TC_PRO_VERIFIED_BASE"]
    head = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()
    installed = json.loads(os.environ["INSTALLED_MODULES_JSON"])
    if not isinstance(installed, list):
        raise ValueError("Expected installed modules list")
    output = Path(os.environ.get("TC_PRO_DELTA_DIR", "/tmp/taxocommerce-pro-deltas"))
    manifest = build(baseline, head, installed, output)
    path = output / "manifest.json"
    path.write_text(json.dumps(manifest, ensure_ascii=False, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"base": baseline, "source": head,
                      "modules_with_changes": [x["slug"] for x in manifest["installed"]],
                      "changed_files": sum(len(x["files"]) for x in manifest["installed"]),
                      "uninstalled_with_changes": manifest["uninstalled"]}))


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError) as exc:
        print("::error::Unsafe or unverified PRO delta: " + str(exc), file=sys.stderr)
        sys.exit(1)
