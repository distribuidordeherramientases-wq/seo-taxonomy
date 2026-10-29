#!/usr/bin/env python3
"""Build a versioned, integrity-checked TaxoCommerce uploads repository.

The manifest is uploaded LAST. ZIP basenames and internal plugin folders
are canonical and cannot be supplied by the manifest or a WordPress user.
"""
import argparse
import datetime as dt
import hashlib
import json
import pathlib
import re
import sys
import zipfile

PLUGIN = re.compile(r"^taxocommerce-[a-z0-9-]+$")
VERSION = re.compile(r"^\s*\*\s*Version:\s*(\S+)", re.M)
REQUIRES_PHP = re.compile(r"^\s*\*\s*Requires PHP:\s*(\S+)", re.M)
UPDATE_URI = re.compile(r"^\s*\*\s*Update URI:\s*(\S+)", re.M)
URI_PREFIX = "https://www.distribuidordeherramientas.es/taxocommerce-updates/"
SKIP_DIRECTORIES = {"tests", "node_modules", "__pycache__", ".git"}
SKIP_SUFFIXES = (".bak", ".pot", ".log", ".pyc", ".md")


def include(path: pathlib.Path, module: pathlib.Path) -> bool:
    relative = path.relative_to(module)
    if any(part in SKIP_DIRECTORIES or part.startswith(".") for part in relative.parts):
        return False
    if path.is_symlink() or not path.is_file():
        return False
    if path.name.endswith(SKIP_SUFFIXES):
        return False
    return True


def build(root: pathlib.Path, out: pathlib.Path) -> dict:
    out.mkdir(parents=True, exist_ok=True)
    plugins = {}
    folders = sorted(path for path in root.iterdir() if path.is_dir() and PLUGIN.fullmatch(path.name))
    for module in folders:
        slug = module.name
        main = module / (slug + ".php")
        if not main.is_file():
            continue
        header = main.read_text(encoding="utf-8")[:6000]
        match = VERSION.search(header)
        update = UPDATE_URI.search(header)
        if not match or not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+(?:[-.][A-Za-z0-9.]+)?", match.group(1)):
            raise ValueError(f"{slug}: missing/invalid version")
        if not update or update.group(1) != URI_PREFIX + slug + "/":
            raise ValueError(f"{slug}: Update URI must identify the temporary TaxoCommerce provider")
        files = [(p, p.relative_to(root).as_posix()) for p in sorted(module.rglob("*")) if include(p, module)]
        if not any(p == main for p, _ in files):
            raise ValueError(f"{slug}: plugin entrypoint not included")
        # Billing retains its historical Dompdf dependency during transition.
        if slug == "taxocommerce-facturacion":
            dompdf = root / "includes" / "facturas" / "dompdf"
            if dompdf.is_dir():
                for path in sorted(dompdf.rglob("*")):
                    if include(path, dompdf):
                        arc = (pathlib.Path(slug) / "includes" / "dompdf" / path.relative_to(dompdf)).as_posix()
                        files.append((path, arc))
        target = out / (slug + ".zip")
        with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as zipped:
            names = set()
            for path, arcname in files:
                if arcname in names:
                    raise ValueError(f"duplicate ZIP entry: {arcname}")
                names.add(arcname)
                zipped.write(path, arcname=arcname)
            if slug + "/" + slug + ".php" not in names:
                raise ValueError(f"{slug}: ZIP entrypoint missing")
        with zipfile.ZipFile(target, "r") as zipped:
            bad = zipped.testzip()
            if bad:
                raise ValueError(f"{slug}: corrupt ZIP member {bad}")
        sha = hashlib.file_digest(target.open("rb"), "sha256").hexdigest()
        min_php = REQUIRES_PHP.search(header)
        plugins[slug] = {
            "version": match.group(1),
            "sha256": sha,
            "size": target.stat().st_size,
            "requires_php": min_php.group(1) if min_php else "7.4",
        }
    if not plugins:
        raise ValueError("no TaxoCommerce plugins found")
    manifest = {
        "schema": 1,
        "generated_utc": dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "plugins": plugins,
    }
    (out / "manifest.json").write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return manifest


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=pathlib.Path, default=pathlib.Path("."))
    parser.add_argument("--output", type=pathlib.Path, required=True)
    args = parser.parse_args()
    try:
        info = build(args.root.resolve(), args.output.resolve())
    except (OSError, ValueError, zipfile.BadZipFile) as exc:
        print(f"Update repository build failed: {exc}", file=sys.stderr)
        return 1
    print("Validated TaxoCommerce ZIPs:", len(info["plugins"]))
    for slug, details in info["plugins"].items():
        print(f"{slug} {details['version']} sha256:{details['sha256'][:12]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
