#!/usr/bin/env python3
"""ONEOFF-RetireBdResidue 1.0.2 - it never takes what rc69 ships, and it takes
Bench Dogs' orphaned order adapter off the tenant.

TWO THINGS ARE PINNED HERE, AND BOTH ARE ABOUT THE TENANT, NOT THE REPO.

1. THE ONE-OFF NEVER REMOVES WHAT BenchDogs-Ext 0.9.42-rc69 SHIPS. rc69 (G280 /
   🔒 1567) installs exactly four files. The one-off is re-run after rc69 on every
   tenant that took rc67/rc68 after the one-off's first run, so a worklist entry
   that named one of those four would take the customer category off the very
   tenant it was just installed on. Its "files it leaves alone" report names the
   four as "KEPT BY rc69", and that set is held equal to rc69's copy list here,
   so the report cannot drift from the package it describes.

2. THE ORPHANED ORDER ADAPTER. Measured on Ophir (quote 368, 2026-09-23
   02:55Z/02:57Z): Submit Order refused before reaching Epicor with "The Bench
   Dogs order planner is not installed, so this quote cannot be adjudicated and
   nothing was sent." 1.0.0/1.0.1 blank the planner
   custom/modules/Quotes/BdSubmitOrderPlan.php but kept its adapter
   custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php, which ERP-Epicor
   finds and which then refuses. 1.0.2 deletes it. The behaviour is proven by the
   one-off's own harness (Sugar's ModuleInstaller methods copied verbatim, run over
   synthetic tenants), which never ran in CI before this file; it runs here.

MUTATION-VERIFIED (each applied, this file re-run, the named case observed red):
  1.0.1's post_execute.php (ef5963b) in place of 1.0.2's
      -> test_the_harness_passes (7 adapter checks red, incl. the Ophir state)
  delete any adapter, whatever its body              -> test_the_harness_passes
  keep the adapter when its planner still has a body -> test_the_harness_passes
  blank the adapter instead of deleting it           -> test_the_harness_passes
  type "1.0.1" back into the Display Log header      -> test_the_harness_passes
  add BdAccountsLayoutExtensions.php to $bdOrphanClasses
      -> test_no_worklist_names_a_file_rc69_ships
  drop one "KEPT BY rc69" entry from $bdNotOurs
      -> test_the_kept_by_rc69_report_is_exactly_rc69s_copy_list
"""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
import zipfile

import hashlib

from bd_retirement import ONEOFF_POST_EXECUTE, PKG, built_zip, oneoff_worklist, zip_names
from test_g280_minimal_footprint import KEPT

ONEOFF = ONEOFF_POST_EXECUTE.parents[1]
ADAPTER = "custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php"
PLANNER = "custom/modules/Quotes/BdSubmitOrderPlan.php"
#: rc69's copy list, FROZEN. The one-off (spent, 1.0.2) was written against
#: rc69 and its "KEPT BY rc69" report names exactly these four; it is not
#: re-cut per package build. Until G380/G381 this was read off the package's
#: live KEPT list, which silently assumed the package would never grow again.
RC69_ON_TENANT = {
    "custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php",
    "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php",
    "custom/modules/Accounts/BdAccountsLayoutExtensions.php",
    "custom/clients/base/api/BdBenchDogsActionsApi.php",
}
#: What THIS build installs on a tenant - rc69's four plus G380/G381's.
BUILD_ON_TENANT = {k for k in KEPT if k.startswith("custom/")}


def _not_ours_block() -> str:
    source = ONEOFF_POST_EXECUTE.read_text(encoding="utf-8")
    start = source.index("$bdNotOurs = array(")
    return source[start:source.index("\n);", start)]


def kept_by_rc69_in_the_report() -> set[str]:
    """Paths the one-off's own report says rc69 keeps. Entries are PHP string
    concatenations; the path is the first literal of each entry."""
    block = _not_ours_block()
    entries = re.split(r"\n    '", block)
    kept = set()
    for entry in entries:
        path = re.match(r"(custom/[^' ]+)'", entry)
        if path and "KEPT BY rc69" in entry:
            kept.add(path.group(1))
    return kept


class TheOneOffNeverTakesWhatRc69Ships(unittest.TestCase):
    def test_no_worklist_names_a_file_rc69_ships(self):
        self.assertEqual(set(oneoff_worklist()) & RC69_ON_TENANT, set(),
                         "the one-off would delete or blank a file rc69 installs")

    def test_the_adapter_step_does_not_target_a_file_rc69_ships(self):
        self.assertNotIn(ADAPTER, RC69_ON_TENANT)
        self.assertNotIn(PLANNER, RC69_ON_TENANT)

    def test_the_kept_by_rc69_report_is_exactly_rc69s_copy_list(self):
        self.assertEqual(kept_by_rc69_in_the_report(), RC69_ON_TENANT)

    def test_the_report_matches_the_built_rc69_manifest_too(self):
        """The one-off's rc69 list is still ALL shipped: this build dropped none
        of rc69's four (it only added G380/G381's), which is what Module Loader
        is handed."""
        with zipfile.ZipFile(built_zip()) as zipped:
            manifest = zipped.read("manifest.php").decode()
        copied = set(re.findall(r"'to'\s*=>\s*'([^']+)'", manifest))
        self.assertLessEqual(kept_by_rc69_in_the_report(), copied)
        self.assertEqual(copied, BUILD_ON_TENANT)


class TheOneOffNeverTakesWhatThisBuildShips(unittest.TestCase):
    """G380/G381 grew the package past rc69. The one-off may be re-run after
    this build (its README asks for that on any tenant that took a Bench build
    after its last run), so it must not remove anything this build installs."""

    def test_no_worklist_names_a_file_this_build_ships(self):
        self.assertEqual(set(oneoff_worklist()) & BUILD_ON_TENANT, set())

    def test_the_new_adapter_is_not_the_body_the_one_off_deletes(self):
        """The one-off deletes ResolveOrderableLines.php ONLY when its md5 is
        the rc37-rc40 Bench body, and reports any other body under SKIPPED.
        This build's G381 adapter must therefore never match that md5."""
        source = ONEOFF_POST_EXECUTE.read_text(encoding="utf-8")
        start = source.index("$bdAdapterBenchMd5 = array(")
        targeted = set(re.findall(r"'([0-9a-f]{32})'", source[start:source.index(");", start)]))
        self.assertTrue(targeted, "the one-off names no adapter md5 - the parse is wrong")
        ours = hashlib.md5((PKG / ADAPTER).read_bytes()).hexdigest()
        self.assertNotIn(ours, targeted)

    def test_the_report_no_longer_claims_an_owner_keep_for_the_repair_route(self):
        """🔒 1573: no such ruling exists; rc69 ships the file EMPTY."""
        self.assertNotIn("owner answer", _not_ours_block())


class Rc69NeitherShipsNorRestoresTheAdapter(unittest.TestCase):
    """rc69's uninstall_copy() restores only its own copy list's backups, so a
    path it never copies is one it can never put back.

    🔁 RE-POINTED for G380/G381. This build DOES copy ResolveOrderableLines.php
    again - a new, self-contained body (the ADM non-part block, 🔒 1705b), not
    the rc37 planner adapter. What stays true, and is pinned below: the PLANNER
    never ships, and the new adapter never reaches for it, so it cannot refuse
    "planner not installed" the way the orphan did on Ophir (quote 368).

    ⚠️ THE ONE HAZARD COPYING THE PATH REOPENS: on a tenant that still holds the
    rc37 body, install_copy backs it up and a later Bench uninstall RESTORES it.
    So the one-off 1.0.2 must have run on a tenant with rc37-rc40 history BEFORE
    this build is installed there (BenchDogs-Ext README, G380/G381 section)."""

    def test_the_planner_never_ships_and_the_adapter_never_names_it(self):
        names = zip_names()
        self.assertFalse((PKG / PLANNER).exists())
        self.assertNotIn(PLANNER, names)
        with zipfile.ZipFile(built_zip()) as zipped:
            manifest = zipped.read("manifest.php").decode()
            adapter = zipped.read(ADAPTER).decode()
        self.assertNotIn("BdSubmitOrderPlan", manifest)
        self.assertNotIn("BdSubmitOrderPlan", adapter)
        self.assertIn("BdAdmRules::nonPartRefusal", adapter,
                      "the shipped adapter is not the G381 body")


class TheLogNamesTheVersionThatRan(unittest.TestCase):
    def test_no_version_is_typed_into_the_report(self):
        code = ONEOFF_POST_EXECUTE.read_text(encoding="utf-8")
        self.assertNotRegex(code, r"ONEOFF-RetireBdResidue \d+\.\d+\.\d+ - Bench Dogs retirement sweep")
        self.assertNotRegex(code, r"'ONEOFF-RetireBdResidue \d+\.\d+\.\d+:")
        self.assertIn("$bdOneoffVersion = isset($manifest['version'])", code)


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheHarness(unittest.TestCase):
    def test_the_harness_passes(self):
        out = subprocess.run(["php", "tests/harness.php"], cwd=ONEOFF,
                             capture_output=True, text=True)
        failed = [l for l in out.stdout.splitlines() if l.startswith("  FAIL")]
        self.assertEqual(out.returncode, 0, "\n".join(failed) or out.stderr[-2000:])
        self.assertIn("ALL CHECKS PASSED", out.stdout)
        # The Ophir case is in this run, by name - not merely "something passed".
        self.assertIn("PASS  Ophir state (adapter + planner ALREADY blank): the adapter is DELETED",
                      out.stdout)


if __name__ == "__main__":
    unittest.main()
