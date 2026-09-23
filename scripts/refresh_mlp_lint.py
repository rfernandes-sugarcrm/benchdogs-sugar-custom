#!/usr/bin/env python3
"""Re-vendor the MLP linter from erp-integration-sugar and re-pin it.

WHY THIS EXISTS (G318)
----------------------
Through 0.9.42-rc67 this repository carried a hand-synced copy of
`scripts/mlp_lint.py`, taken from sugarcrm/erp-integration-sugar at 844ba49
and patched here twice since. By 2026-09-22 it had 17 rules while upstream had
19, so CI passed pull requests without ever checking MLP018 or MLP019. MLP019
is the rule whose violation (naming `DeployedMetaDataImplementation`) makes
SugarCloud refuse the whole package mid-install. The copy drifted because
nothing noticed that it had.

WHY VENDORED AND NOT FETCHED
----------------------------
Both repositories are private and belong to different GitHub owners
(sugarcrm/... and ophirsw76/...). CI here cannot check upstream out or fetch a
file from it without a cross-org token, the same constraint
refresh_shared_fixtures.py documents. So the linter is VENDORED, byte for byte,
and pinned by sha256 to one upstream commit in scripts/mlp_lint.PINNED.json.

WHY IT CANNOT DRIFT SILENTLY
----------------------------
scripts/tests/test_mlp_lint_pin.py has two halves:

  integrity - runs everywhere, CI included, and FIRST in the `rules` job:
              every vendored file must hash to the upstream file at the pinned
              commit. A hand edit, a partial sync or a stale copy fails CI.
              It also floors the rule set at MLP001-MLP019, so re-pinning to
              an older upstream commit cannot quietly drop a rule.
  drift     - runs wherever the erp-integration-sugar checkout is present:
              the pin must still match upstream's branch head. Upstream adding
              or changing a rule fails that run, naming this script as the fix.

ONE DECLARED LOCAL DELTA
------------------------
`test_mlp_lint.py`'s real-scanner agreement test floors the number of PHP
files it checked at 100, which is erp-integration-sugar's size, not this
repository's (61 at rc67, every one of which the real ModuleScanner and MLP002
agree on). Vendored unchanged, that test is red on any machine with a SugarEnt
tree. The one-line replacement is recorded in the pin; the integrity check
reverses it before hashing, so the vendored file is still proven to be the
upstream file plus exactly that line and nothing else.

Run (from anywhere; needs the sibling checkout, fetched):
    git -C ../erp-integration-sugar fetch origin fix/order-selected-lines-hide-once-submitted
    python3 scripts/refresh_mlp_lint.py              # branch head
    python3 scripts/refresh_mlp_lint.py --ref <sha>  # an exact commit
then run the whole suite and commit the refreshed files with the pin.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts/tests"))
import shared_sugar  # noqa: E402  (resolves the sibling even from a worktree)

PIN = ROOT / "scripts/mlp_lint.PINNED.json"
REPOSITORY = "sugarcrm/erp-integration-sugar"
BRANCH = "fix/order-selected-lines-hide-once-submitted"

# Vendored as a set. The tests are what make the linter trustworthy, so a rule
# is never taken without its test, and a test never without its fixtures.
FILES = (
    "scripts/mlp_lint.py",
    "scripts/check_built_packages.py",
    "scripts/regen_denylist.py",
    "scripts/tests/test_mlp_lint.py",
    "scripts/tests/mutate_mlp_lint.py",
    "scripts/tests/scanner_oracle.php",
)
# Every file upstream keeps here is vendored, not a hand-picked subset.
FIXTURE_DIR = "scripts/tests/fixtures/mlp002"

DELTAS = {
    "scripts/tests/test_mlp_lint.py": {
        "reason": (
            "test_mlp002_agrees_with_the_scanner_on_every_php_file_here floors "
            "the files it checked at erp-integration-sugar's size (100). This "
            "repository has 61 PHP files at rc67, BenchDogs-Ext alone 51, so the "
            "floor is 40: still proves the oracle walked the real tree."
        ),
        "upstream": "        self.assertGreater(checked, 100)\n",
        "local": (
            "        self.assertGreater(checked, 40)  "
            "# Bench Dogs delta, declared in scripts/mlp_lint.PINNED.json\n"
        ),
    },
}


def git(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["git", "-C", str(shared_sugar.SIBLING), *args],
        capture_output=True, check=False,
    )


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--ref", default=f"origin/{BRANCH}",
                    help=f"upstream ref to vendor (default origin/{BRANCH})")
    args = ap.parse_args(argv)

    if not shared_sugar.SIBLING.is_dir():
        print(f"error: no erp-integration-sugar checkout at {shared_sugar.SIBLING}",
              file=sys.stderr)
        return 1
    r = git("rev-parse", "--verify", "-q", f"{args.ref}^{{commit}}")
    if r.returncode != 0:
        print(f"error: {args.ref} not found in {shared_sugar.SIBLING}; fetch it first",
              file=sys.stderr)
        return 1
    commit = r.stdout.decode().strip()

    listing = git("ls-tree", "--name-only", f"{commit}:{FIXTURE_DIR}")
    if listing.returncode != 0:
        print(f"error: {FIXTURE_DIR} missing at {commit}", file=sys.stderr)
        return 1
    fixtures = sorted(f"{FIXTURE_DIR}/{n}" for n in listing.stdout.decode().split())
    wanted = list(FILES) + fixtures

    pinned: dict[str, dict] = {}
    for path in wanted:
        blob = git("show", f"{commit}:{path}")
        if blob.returncode != 0:
            print(f"error: {path} missing at {commit}", file=sys.stderr)
            return 1
        data = blob.stdout
        entry = {
            "sha256": hashlib.sha256(data).hexdigest(),
            "git_blob": git("rev-parse", f"{commit}:{path}").stdout.decode().strip(),
            "bytes": len(data),
        }
        out = data
        delta = DELTAS.get(path)
        if delta:
            old, new = delta["upstream"].encode(), delta["local"].encode()
            if data.count(old) != 1:
                print(f"error: {path}: the line the local delta replaces now occurs "
                      f"{data.count(old)} times upstream; re-derive DELTAS by hand",
                      file=sys.stderr)
                return 1
            out = data.replace(old, new, 1)
            entry["local_delta"] = delta
        dest = ROOT / path
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(out)
        pinned[path] = entry
        print(f"vendored {path}  ({len(data)} bytes{', +delta' if delta else ''})")

    # Fixtures upstream dropped must not linger here as untracked evidence.
    for stale in sorted((ROOT / FIXTURE_DIR).glob("*")):
        rel = stale.relative_to(ROOT).as_posix()
        if rel not in pinned:
            stale.unlink()
            print(f"removed  {rel}  (no longer upstream)")

    PIN.write_text(json.dumps({
        "note": (
            "The MLP linter and its tests are VENDORED from upstream, never "
            "edited here. scripts/tests/test_mlp_lint_pin.py fails CI if any "
            "file below differs from upstream at `commit`. Refresh with "
            "scripts/refresh_mlp_lint.py; do not edit this file by hand."
        ),
        "upstream": {"repository": REPOSITORY, "branch": BRANCH, "commit": commit},
        "files": pinned,
    }, indent=2) + "\n")
    print(f"\npinned {len(pinned)} file(s) to {REPOSITORY}@{commit}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
