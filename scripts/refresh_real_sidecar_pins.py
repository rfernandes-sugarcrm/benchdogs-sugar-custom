#!/usr/bin/env python3
"""Copy the files the real-Sidecar page tests need from erp-integration-sugar, at one commit.

The page tests run Sugar's own front-end code (Sidecar, the stock record view, the record-decor field) recorded
from a SugarEnt tree, the page harness that loads it, and ERP-Core's erp-lookup field, all of which live in
erp-integration-sugar. CI cannot check out that private repository, so the files are copied here, under
scripts/tests/fixtures/real-sidecar/, at the SAME relative paths, so the harness finds them exactly as it does
there. PINNED.json names the commit and each file's sha256; test_real_sidecar_pins.py holds the copies to it.

    python3 scripts/refresh_real_sidecar_pins.py --sibling ../erp-integration-sugar --commit <sha>

Never edit a copy by hand: change it upstream and run this again.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import pathlib
import shutil
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
PINS = ROOT / "scripts/tests/fixtures/real-sidecar"
MANIFEST = PINS / "PINNED.json"

#: Whole directories copied (every file under them), then single files.
DIRECTORIES = (
    "sugar-sell/ERP-Core/tests/support/sugar-26.1",
    "sugar-sell/ERP-Core/tests/support/sugar-26.2",
)
FILES = (
    "scripts/tests/_real_sugar_page.cjs",
    "scripts/tests/dom/package.json",
    "scripts/tests/dom/package-lock.json",
    "sugar-sell/ERP-Core/src/custom/clients/base/fields/erp-lookup/erp-lookup.js",
    "sugar-sell/ERP-Core/src/custom/clients/base/fields/erp-lookup/detail.hbs",
    "sugar-sell/ERP-Core/src/custom/clients/base/fields/erp-lookup/edit.hbs",
    "sugar-sell/ERP-Core/src/custom/clients/base/fields/erp-lookup/disabled.hbs",
    "sugar-sell/ERP-Core/src/custom/clients/base/fields/erp-lookup/list.hbs",
    "sugar-sell/ERP-Core/src/custom/include/javascript/sugar7/erp-lookup-fields.js",
    "sugar-sell/ERP-Core/src/SugarModules/relationships/vardefs/erp_quotes_fob_Quotes.php",
    "sugar-sell/ERP-Core/src/SugarModules/relationships/vardefs/erp_quotes_ship_via_Quotes.php",
    "sugar-sell/ERP-Core/src/SugarModules/relationships/vardefs/erp_companies_quotes_Quotes.php",
)


def git(sibling: pathlib.Path, *args: str) -> bytes:
    return subprocess.run(["git", "-C", str(sibling), *args], check=True, capture_output=True).stdout


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--sibling", required=True, type=pathlib.Path)
    parser.add_argument("--commit", required=True)
    args = parser.parse_args()
    commit = git(args.sibling, "rev-parse", "--verify", args.commit + "^{commit}").decode().strip()
    paths = list(FILES)
    for directory in DIRECTORIES:
        listed = git(args.sibling, "ls-tree", "-r", "--name-only", commit, "--", directory).decode().split("\n")
        paths.extend(p for p in listed if p)
    if PINS.exists():
        shutil.rmtree(PINS)
    files = {}
    for rel in sorted(set(paths)):
        data = git(args.sibling, "show", f"{commit}:{rel}")
        target = PINS / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        files[rel] = {"sha256": hashlib.sha256(data).hexdigest(), "bytes": len(data)}
    MANIFEST.write_text(json.dumps({"upstream_commit": commit, "files": files}, indent=2) + "\n")
    print(f"pinned {len(files)} files from {commit}")


if __name__ == "__main__":
    main()
