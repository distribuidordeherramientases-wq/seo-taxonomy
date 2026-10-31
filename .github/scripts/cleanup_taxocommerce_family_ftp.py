#!/usr/bin/env python3
"""Inventory, back up, remove and verify ONLY obsolete modular TaxoCommerce files.

Never enters seo-taxonomy or deletes arbitrary source/runtime files. Candidates
are exactly module-root .ftp-deploy-sync-state.json and files below tests/.
An inaccessible or ambiguous inventory fails closed before any deletion.
"""
import ftplib
import hashlib
import io
import json
import os
from pathlib import Path
import posixpath
import re
import sys
import zipfile

ROOT = "./wp-content/plugins"
OUT = Path(".family-module-cleanup")
MODULE = re.compile(r"^taxocommerce-[a-z0-9-]+$")
SEGMENT = re.compile(r"^[A-Za-z0-9._+@-]+$")
STATE = ".ftp-deploy-sync-state.json"
MAX_FILES = 750
MAX_FILE_BYTES = 4 * 1024 * 1024
MAX_TOTAL_BYTES = 48 * 1024 * 1024


def allowed_modules(source=Path(".")):
    """Only standalone modules with an actual matching entrypoint in GitHub."""
    return {
        p.name for p in source.iterdir()
        if p.is_dir() and MODULE.fullmatch(p.name)
        and (p / (p.name + ".php")).is_file()
        and (p / "readme.txt").is_file()
    }


def candidate(module, relative):
    """Strict path allowlist; never accept monolith, traversal or runtime paths."""
    if not MODULE.fullmatch(module):
        return False
    parts = relative.split("/")
    if not parts or not all(SEGMENT.fullmatch(x) and x not in (".", "..") for x in parts):
        return False
    return relative == STATE or (len(parts) >= 2 and parts[0] == "tests")


def connect():
    ftp = ftplib.FTP_TLS(timeout=50)
    ftp.connect(os.environ["TC_FAMILY_FTP_HOST"], 21)
    ftp.login(os.environ["TC_FAMILY_FTP_USER"], os.environ["TC_FAMILY_FTP_PASS"])
    ftp.prot_p()
    ftp.cwd(ROOT)
    return ftp, ftp.pwd()


def entries(ftp):
    rows = list(ftp.mlsd())
    result = {}
    for name, facts in rows:
        if name in (".", ".."):
            continue
        if not SEGMENT.fullmatch(name) or name in result:
            raise RuntimeError("Unsafe or duplicate FTPS entry; no deletion")
        result[name] = facts
    return result


def inventory(ftp, root, modules, capture):
    candidates, folders, backup = [], set(), {}
    total = 0
    ftp.cwd(root)
    remote = entries(ftp)
    installed = sorted(name for name, facts in remote.items()
                       if name in modules and facts.get("type", "").lower() == "dir")
    if not installed:
        raise RuntimeError("No recognised modular TaxoCommerce directories; abort")

    for module in installed:
        base = root + "/" + module
        ftp.cwd(base)
        top = entries(ftp)
        if STATE in top:
            if top[STATE].get("type", "").lower() != "file":
                raise RuntimeError("Unexpected FTPS state type in " + module)
            file_list = [(STATE, top[STATE])]
        else:
            file_list = []
        if "tests" in top:
            if top["tests"].get("type", "").lower() != "dir":
                raise RuntimeError("Unexpected tests entry type in " + module)
            folders.add((module, "tests"))

            def walk(prefix, depth):
                if depth > 9:
                    raise RuntimeError("Exceeded tests tree depth: " + module)
                ftp.cwd(base + "/" + prefix)
                for name, facts in entries(ftp).items():
                    relative = prefix + "/" + name
                    kind = facts.get("type", "").lower()
                    if kind == "dir":
                        folders.add((module, relative))
                        walk(relative, depth + 1)
                    elif kind == "file":
                        file_list.append((relative, facts))
                    else:
                        raise RuntimeError("Unknown FTPS item type: " + module + "/" + relative)
            walk("tests", 0)

        for relative, facts in file_list:
            if not candidate(module, relative):
                raise RuntimeError("Not a safe cleanup path: " + module + "/" + relative)
            try:
                size = int(facts.get("size", "-1"))
            except (ValueError, TypeError) as error:
                raise RuntimeError("Unknown FTPS file size") from error
            if size < 0 or size > MAX_FILE_BYTES or len(candidates) >= MAX_FILES or total + size > MAX_TOTAL_BYTES:
                raise RuntimeError("Cleanup size/file budget exceeded; no deletions")
            total += size
            parent, name = posixpath.split(relative)
            ftp.cwd(base + ("/" + parent if parent else ""))
            buf = bytearray()
            ftp.retrbinary("RETR " + name, buf.extend)
            if len(buf) != size:
                raise RuntimeError("FTPS size changed while reading: " + module + "/" + relative)
            data = bytes(buf)
            row = {"module": module, "path": relative, "size": size,
                   "sha256": hashlib.sha256(data).hexdigest()}
            candidates.append(row)
            if capture:
                backup[module + "/" + relative] = data
    candidates.sort(key=lambda x: (x["module"], x["path"]))
    return {"modules": installed, "candidates": candidates,
            "folders": [list(x) for x in sorted(folders)]}, backup


def read_exact(ftp, root, entry):
    module, relative = entry["module"], entry["path"]
    if not candidate(module, relative):
        raise RuntimeError("Unsafe deletion requested")
    parent, name = posixpath.split(relative)
    ftp.cwd(root + "/" + module + ("/" + parent if parent else ""))
    facts = entries(ftp).get(name)
    if not facts or facts.get("type", "").lower() != "file":
        raise RuntimeError("File disappeared or changed type: " + module + "/" + relative)
    size = int(facts.get("size", "-1"))
    if size != entry["size"] or size < 0 or size > MAX_FILE_BYTES:
        raise RuntimeError("File size changed: " + module + "/" + relative)
    buf = bytearray()
    ftp.retrbinary("RETR " + name, buf.extend)
    data = bytes(buf)
    if len(data) != size or hashlib.sha256(data).hexdigest() != entry["sha256"]:
        raise RuntimeError("File hash changed: " + module + "/" + relative)
    return data


def perform(mode):
    modules = allowed_modules()
    if not modules or "seo-taxonomy" in modules:
        raise RuntimeError("No valid modular source allowlist")
    ftp, root = connect()
    try:
        if mode == "plan":
            plan, contents = inventory(ftp, root, modules, True)
            OUT.mkdir(exist_ok=True)
            with zipfile.ZipFile(OUT / "backup.zip", "w", compression=zipfile.ZIP_DEFLATED) as z:
                for key, data in sorted(contents.items()):
                    z.writestr(key, data)
                z.writestr("manifest.json", json.dumps(plan, indent=2))
            (OUT / "plan.json").write_text(json.dumps(plan, indent=2) + "\n", encoding="utf-8")
            print("PLAN", json.dumps({"modules": plan["modules"], "files": len(plan["candidates"])}))
        elif mode == "apply":
            plan = json.loads((OUT / "plan.json").read_text(encoding="utf-8"))
            with zipfile.ZipFile(OUT / "backup.zip") as z:
                if json.loads(z.read("manifest.json")) != plan:
                    raise RuntimeError("Backup manifest does not match plan")
                for item in plan["candidates"]:
                    key = item["module"] + "/" + item["path"]
                    if hashlib.sha256(z.read(key)).hexdigest() != item["sha256"]:
                        raise RuntimeError("Backup corrupted: " + key)
            current, _ = inventory(ftp, root, modules, False)
            if current != plan:
                raise RuntimeError("Remote inventory changed since backup; no deletion")
            for item in plan["candidates"]:
                read_exact(ftp, root, item)
            for item in plan["candidates"]:
                read_exact(ftp, root, item)
                parent, name = posixpath.split(item["path"])
                ftp.cwd(root + "/" + item["module"] + ("/" + parent if parent else ""))
                ftp.delete(name)
                print("REMOVED", item["module"] + "/" + item["path"])
            for module, relative in sorted((tuple(x) for x in plan["folders"]),
                                           key=lambda x: (-x[1].count("/"), -len(x[1]))):
                if not relative.startswith("tests") or not (relative == "tests" or relative.startswith("tests/")):
                    raise RuntimeError("Unsafe directory path")
                ftp.cwd(root + "/" + module)
                ftp.rmd(relative)
            print("APPLIED", len(plan["candidates"]))
        elif mode == "verify":
            current, _ = inventory(ftp, root, modules, False)
            if current["candidates"] or current["folders"]:
                raise RuntimeError("Cleanup incomplete: remaining obsolete artifacts " +
                                   json.dumps(current))
            print("VERIFIED", json.dumps({"modules_checked": current["modules"],
                                          "remaining_obsolete_files": 0}))
        else:
            raise ValueError("Expected plan, apply, or verify")
    finally:
        ftp.quit()


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit("Usage: cleanup_taxocommerce_family_ftp.py plan|apply|verify")
    perform(sys.argv[1])
