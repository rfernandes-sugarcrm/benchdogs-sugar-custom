#!/usr/bin/env python3
"""Re-pin the shared Sugar files this repo's tests require.

WHY THIS EXISTS
---------------
Eight test classes here exercise the SEAM between Bench Dogs' own hooks and
files that ship from the sibling `erp-integration-sugar` repository (ERP-Core
and ERP-Epicor-PartialFulfillment). Until 2026-09-19 those tests simply did
not run in CI: the workflow executed exactly one file, and the rest only ever
ran on a laptop that happened to have both checkouts side by side.

When CI started running the whole suite they failed outright --
`Failed opening required '.../QuoteOpportunityAmount.php'`, returncode 255.
Skipping them would have been the cheap fix and the wrong one: a test that is
collected and then skipped is documentation, not a guard.

`erp-integration-sugar` is private and in a different GitHub organisation, so
CI cannot check it out without a cross-org token. Instead the files are PINNED
here, under scripts/tests/ -- which the package builder does NOT pack, so none
of this reaches an instance (pack.php walks sugar-sell/BenchDogs-Ext/scripts/,
a different directory).

THE HONEST LIMITATION, STATED
-----------------------------
CI therefore tests against a SNAPSHOT of core, not against core's HEAD. Both
files moved within two days of pinning, so the snapshot WILL go stale. That is
why test_shared_fixture_drift.py exists: on any machine that has both
checkouts -- the owner's, and every local run these tests have ever had -- it
compares the pin against the real file and fails the moment they diverge,
naming this script as the fix. Staleness is loud, never silent.

THE PIN IS ONE COMMIT, NAMED (0.9.42-rc83)
-----------------------------------------
Every file is read from ONE commit's object (`git show <commit>:<path>`), never
from the sibling's working tree and never from a branch name: a branch moves,
and a dirty tree pins bytes no commit holds. The commit is recorded in full in
PINNED.json. Each pin is written at the SAME relative path under
fixtures/shared-sugar/ as in the sibling (see shared_sugar.py for why), and a
file left there that is no longer pinned is removed, so the tree holds exactly
the manifest.

The list of files is shared_sugar.SOURCES - one list, which the tests and this
script both read, so the two cannot disagree.

Run:  python3 scripts/refresh_shared_fixtures.py <commit>
      (the sibling checkout must have that commit; fetch it first)
"""
from __future__ import annotations

import hashlib
import json
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts/tests"))
import shared_sugar  # noqa: E402  (resolves the sibling even from a worktree)

SIBLING = shared_sugar.SIBLING
FIXTURES = shared_sugar.FIXTURES
MANIFEST = FIXTURES / "PINNED.json"


def git(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(["git", "-C", str(SIBLING), *args], capture_output=True)


def main(argv: list[str]) -> int:
    if len(argv) != 1:
        print(__doc__.split("Run:")[-1].strip(), file=sys.stderr)
        return 2
    if not SIBLING.is_dir():
        print(f"error: sibling checkout not found at {SIBLING}", file=sys.stderr)
        print("This script can only run where erp-integration-sugar is present.",
              file=sys.stderr)
        return 1
    found = git("rev-parse", "--verify", "--quiet", f"{argv[0]}^{{commit}}")
    commit = found.stdout.decode().strip()
    if found.returncode != 0 or not re.fullmatch(r"[0-9a-f]{40}", commit):
        print(f"error: {argv[0]} is not a commit in {SIBLING}; fetch it first",
              file=sys.stderr)
        return 1

    manifest = {"upstream_commit": commit, "files": {}}
    for name, rel in shared_sugar.SOURCES.items():
        shown = git("show", f"{commit}:{rel}")
        if shown.returncode != 0:
            print(f"error: {rel} is not in {commit}", file=sys.stderr)
            return 1
        data = shown.stdout
        dest = shared_sugar.pinned_path(name)
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(data)
        manifest["files"][name] = {
            "source": rel,
            "sha256": hashlib.sha256(data).hexdigest(),
            "bytes": len(data),
        }
        print(f"pinned {rel}  ({len(data)} bytes)")

    keep = {shared_sugar.pinned_path(n).resolve() for n in shared_sugar.SOURCES}
    keep.add(MANIFEST.resolve())
    for stale in sorted(p for p in FIXTURES.rglob("*") if p.is_file()):
        if stale.resolve() not in keep:
            stale.unlink()
            print(f"removed {stale.relative_to(FIXTURES)} (no longer pinned)")
    for d in sorted((p for p in FIXTURES.rglob("*") if p.is_dir()), reverse=True):
        if not any(d.iterdir()):
            d.rmdir()

    MANIFEST.write_text(json.dumps(manifest, indent=2) + "\n")
    print(f"\nupstream commit: {commit}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
