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

Run:  python3 scripts/refresh_shared_fixtures.py
"""
from __future__ import annotations

import hashlib
import json
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
WORKSPACE = ROOT.parent
SIBLING = WORKSPACE / "erp-integration-sugar"
FIXTURES = ROOT / "scripts/tests/fixtures/shared-sugar"

# Every file this repo's tests require out of the sibling checkout.
PINNED = {
    "QuoteOpportunityAmount.php":
        "sugar-sell/ERP-Core/src/custom/modules/Quotes/QuoteOpportunityAmount.php",
    "ErpOpportunityValuation.php":
        "sugar-sell/ERP-Epicor-PartialFulfillment/custom/modules/Quotes/"
        "ErpOpportunityValuation.php",
    "ErpQuoteLineRollup.php":
        "sugar-sell/ERP-Epicor-PartialFulfillment/custom/modules/Quotes/"
        "ErpQuoteLineRollup.php",
    "QuotePrimaryQuoteSoleEnforcer.php":
        "sugar-sell/ERP-Core/src/custom/modules/Quotes/QuotePrimaryQuoteSoleEnforcer.php",
    "BaseErpLayout.php":
        "sugar-sell/ERP-Core/scripts/BaseErpLayout.php",
    "QuotesLayout.php":
        "sugar-sell/ERP-Epicor/scripts/Modules/QuotesLayout.php",
}


def upstream_commit() -> str:
    try:
        return subprocess.run(
            ["git", "-C", str(SIBLING), "rev-parse", "HEAD"],
            capture_output=True, text=True, check=True,
        ).stdout.strip()
    except Exception:
        return "unknown"


def main() -> int:
    if not SIBLING.is_dir():
        print(f"error: sibling checkout not found at {SIBLING}", file=sys.stderr)
        print("This script can only run where erp-integration-sugar is present.",
              file=sys.stderr)
        return 1

    FIXTURES.mkdir(parents=True, exist_ok=True)
    manifest = {"upstream_commit": upstream_commit(), "files": {}}

    for name, rel in PINNED.items():
        src = SIBLING / rel
        if not src.is_file():
            print(f"error: {rel} missing from the sibling checkout", file=sys.stderr)
            return 1
        data = src.read_bytes()
        (FIXTURES / name).write_bytes(data)
        manifest["files"][name] = {
            "source": rel,
            "sha256": hashlib.sha256(data).hexdigest(),
            "bytes": len(data),
        }
        print(f"pinned {name}  ({len(data)} bytes)")

    (FIXTURES / "PINNED.json").write_text(json.dumps(manifest, indent=2) + "\n")
    print(f"\nupstream commit: {manifest['upstream_commit']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
