#!/usr/bin/env python3
"""The Bench release-stage provider no longer ships — AND MAY NEVER COME BACK
AS AN EMPTY FILE.

🛑 THIS FILE IS INVERTED FROM rc65, WHERE IT ASSERTED THE OPPOSITE. That is not
a test being loosened to fit a deletion; the two versions guard the same tenant
against two different states of it, and the sequencing rule (🔒 1508) is what
moves the package from one to the other:

  rc65 — the tenant still held the OLD provider, which decided the stage itself.
         Module Loader deletes nothing (§CW / G37) and `unlink()` is denied to
         package code (MLP002), so the ONLY way to retire that class was to ship
         a file over it. rc65 shipped one that returns null. "Stops deciding" is
         not "stops shipping", and the old assertions were right.
  rc66 — tenant 1 TOOK rc65 (17:17:35Z; stock carries no Bench Dogs by design,
         RELEASE-CONTROL.md:757), so the overwrite has landed everywhere it was
         owed. 🔒 1508: *"a file may only stop shipping once every QA tenant has
         taken the build that emptied it."* It has. The file goes.

WHAT THIS FILE DOES NOT DO, ON PURPOSE. It does not try to prove the removal is
SAFE. An assertion that a deleted file is absent notices deletion and nothing
else, and the thing that has to be true is about Partial Fulfillment, not about
this package. That proof is
`test_release_stage_absent_equals_null.py`, which RUNS PF's own
`releaseStageDecision()` with the file present and absent and compares the
decisions. This file guards the one shape that would be worse than either.

🚩 THE SHAPE THAT MUST STAY UNREACHABLE. PF loads the provider by hardcoded path
(`ErpOpportunityValuation.php:288`). If a file EXISTS there and does not define
`ErpOpportunityReleaseStagePolicy`, PF returns `policy_provider_invalid`, which
PRESERVES the current stage and NEVER reads the config (`:297-301`) — the
Opportunity stage would silently stop being written at all. So an empty stub is
strictly worse than no file. Absent is fine; present-and-empty is not. Every
case below exists to stop a future cleanup "tidying" the deletion into a stub,
which is the same class_exists-shaped off switch this estate has paid for twice.

MUTATION-VERIFIED: drop an empty `<?php` at the provider path -> the first two
cases fail; restore rc65's null stub -> the first case fails and the third
passes, i.e. the suite tells the two apart rather than just noticing a file.
"""

from __future__ import annotations

import os
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PKG = Path(os.environ.get("BD_PKG", ROOT / "sugar-sell/BenchDogs-Ext"))

#: PF's hardcoded lookup path, relative to a Sugar root — and therefore the path
#: inside this package too, because every `custom/` file is copied 1:1.
POLICY_REL = "custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php"
POLICY = PKG / POLICY_REL

CLASS_NAME = "ErpOpportunityReleaseStagePolicy"


class RetiredProviderDoesNotShip(unittest.TestCase):
    def test_the_provider_path_is_not_in_the_build(self):
        """rc66's deletion. On a tenant that took rc65 the null stub simply
        stays on disk and PF keeps answering `policy_provider_null`; on a fresh
        one PF answers `policy_provider_absent`. The companion probe proves
        those two reach the same stage."""
        self.assertFalse(
            POLICY.exists(),
            f"{POLICY_REL} is shipping again — either the deletion was reverted "
            "or something re-created it; if a tenant genuinely still holds the "
            "OLD deciding provider, run ONEOFF-RetireBdResidue >= 1.0.3, which "
            "deletes every body Bench Dogs shipped there (G594) - never ship a "
            "stub or an empty file from this package")

    def test_no_file_anywhere_in_the_package_defines_the_class(self):
        """The off-switch guard. A file at ANY path that declares the class
        without answering would be picked up by PF only at its own hardcoded
        path — but a class of this name defined anywhere in this package means
        somebody is writing a provider again, which 🔒 1508 says is core's job.
        """
        offenders = [
            str(p.relative_to(PKG))
            for p in PKG.rglob("*.php")
            if f"class {CLASS_NAME}" in p.read_text(encoding="utf-8")
        ]
        self.assertEqual(offenders, [], f"this package defines {CLASS_NAME} again: {offenders}")

    def test_the_anti_vacuity_control_is_the_package_itself(self):
        """ANTI-VACUITY, RE-POINTED 0.9.42-rc69. The two cases above would also
        pass if PKG pointed at nothing. Until rc68 the control was the sibling
        `ErpQuoteHooks/OpportunityContribution.php`; rc69 stops shipping that too
        (G280 / 🔒 1567 - Partial Fulfillment >= 1.0.41 ships the same path with
        the G282 preserve gate), so the whole `ErpQuoteHooks/` tree is gone and
        the control is that PKG really is the Bench Dogs package.

        🔁 The unreleased G380/G381 branch (🔒 1705b) brought the directory back
        with two ordering hook adapters; 🔒 1724b moved that rule into
        ERP-Epicor (a per-company switch), so the whole `ErpQuoteHooks/` tree is
        gone again - never this provider path or PF's OpportunityContribution."""
        hooks = PKG / "custom/modules/Quotes/ErpQuoteHooks"
        self.assertEqual(sorted(p.name for p in hooks.iterdir()) if hooks.exists() else [], [])
        self.assertTrue((PKG / "pack.php").is_file(), "PKG is not the package - the cases above prove nothing")
        self.assertIn("sugarai_benchdogs_ext", (PKG / "pack.php").read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
