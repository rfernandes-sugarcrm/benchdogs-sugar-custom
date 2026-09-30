#!/usr/bin/env python3
"""ONEOFF-RetireBdResidue 1.0.4 - it never takes what rc69 ships, it takes
Bench Dogs' orphaned order adapter (1.0.2) and orphaned release-stage policy
(1.0.3, G594) off the tenant, and it leaves the tenant's own sales-stage style
alone (1.0.4, G599).

FOUR THINGS ARE PINNED HERE, AND ALL ARE ABOUT THE TENANT, NOT THE REPO.

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

3. THE ORPHANED RELEASE-STAGE POLICY (G594). Measured on benchdogs-dev (quote #8
   ab7ca13c, 2026-09-25 08:52Z): the order that completed the quote left its
   Opportunity at Partial Production Ordered / 90, not Closed Won / 100.
   Partial Fulfillment finds custom/modules/Quotes/ErpQuoteHooks/
   OpportunityReleaseStagePolicy.php by a hardcoded path and obeys it; the body
   BenchDogs-Ext rc45-rc64 shipped answers the partial stage on EVERY release.
   rc65 overwrote it with a null provider and rc66 stopped shipping the path, so
   a tenant that jumped from rc64-or-earlier to rc66+ (dev: rc60 -> rc68) kept it,
   and 1.0.2 left it alone by name. 1.0.3 deletes it when - and only when - its
   body is one of the eight Bench Dogs shipped (every distinct body in the built
   zips, pinned under the one-off's tests/fixtures/release-stage-policy/). What
   Partial Fulfillment then decides is proven in
   test_release_stage_absent_equals_null.py (case E), not here.

4. THE STAGE STYLE IS NOT ONLY BENCH DOGS' (G599). 1.0.3 deleted
   custom/Extension/application/Ext/DropdownsStyle/sales_stage_dom_style.php by
   path on et and Ophir (2026-09-25 10:26Z). On et, where no Bench Dogs package
   was installed after 1.0.2 deleted it on 09-24, it had come back: SugarCRM 26.1
   ITSELF writes "<dropdown>_style.php" (DropdownsManager::buildDropdownStyle ->
   saveContents, from synchronizeDropdownsStyle at the end of every install,
   uninstall and Quick Repair, and from the Dropdown Editor / Studio). 1.0.4
   deletes it only when its md5 is one of the four bodies BenchDogs-Ext shipped
   there (rc11 .. rc68, every distinct body in the built zips, pinned under the
   one-off's tests/fixtures/dropdowns-style/), and reports any other body under
   SKIPPED. The control is the body Sugar wrote on the stock tenant, which Bench
   Dogs was never installed on. Every OTHER by-path entry has a Bench-chosen name
   (bd_*, bd01_*, Bd*), which no platform writer produces; a test below holds
   that, so a future entry with a platform-derived name must be guarded too.

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
  1.0.3 (G594), each run through tests/harness.php (so test_the_harness_passes):
  1.0.2's post_execute.php (origin/main 27997c0) in place of 1.0.3's - RED-BEFORE
      -> 16 release-stage checks red, incl. the benchdogs-dev state
  drop the md5 check (delete any body)       -> the foreign-body CONTROL red
  blank the policy instead of deleting it    -> 12 checks red
  drop the rc45-rc64 allowlist entry         -> 8 red, incl. the benchdogs-dev state
  drop the zip-only rc39-rc40 entry          -> its own body's check red
  drop the rc65 null-stub entry              -> its own body's check red
  add an allowlist entry with no pinned body
      -> test_the_allowlist_is_exactly_the_pinned_bodies
  1.0.4 (G599) - see the lane E5 report for each run:
  1.0.3's post_execute.php (e6485d4) in place of 1.0.4's - RED-BEFORE
      -> 6 harness checks red, incl. the Sugar-written CONTROL (deleted by 1.0.3)
  drop the guard (delete the style by path again) -> the same CONTROL checks red
  add a foreign md5 (the stock Sugar body's) to the style allowlist
      -> test_the_style_allowlist_is_exactly_the_pinned_bench_bodies
         and test_the_sugar_written_control_is_not_on_the_allowlist
  drop one pinned Bench body's md5 from the allowlist -> its own body's check red
"""
from __future__ import annotations

import hashlib
import re
import shutil
import subprocess
import unittest
import zipfile


from bd_retirement import ONEOFF_POST_EXECUTE, PKG, built_zip, oneoff_worklist, zip_names
from test_g280_minimal_footprint import KEPT

ONEOFF = ONEOFF_POST_EXECUTE.parents[1]
ADAPTER = "custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php"
RELEASE_POLICY = "custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php"
RELEASE_POLICY_BODIES = ONEOFF / "tests/fixtures/release-stage-policy"
STAGE_STYLE = "custom/Extension/application/Ext/DropdownsStyle/sales_stage_dom_style.php"
STAGE_STYLE_BODIES = ONEOFF / "tests/fixtures/dropdowns-style"
#: The body SugarCRM itself wrote at STAGE_STYLE on the stock tenant (ossugarcube2,
#: Admin Diagnostic Tool export 2026-09-11; Bench Dogs was never installed there).
SUGAR_WRITTEN_STYLE = STAGE_STYLE_BODIES / "FOREIGN.sugar-written.ossugarcube2-2026-09-11.php.txt"
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
#: What THIS build installs on a tenant - rc69's four less the layout writer
#: 🔒 1724b retired, plus G380/G381's.
BUILD_ON_TENANT = {k for k in KEPT if k.startswith("custom/")}
#: rc69 files this build no longer ships. Module Loader never deletes a file a
#: later build stops shipping (§CW / G37), so an upgraded tenant keeps them -
#: inert, since nothing rc70 ships requires them (test_g280_minimal_footprint
#: routes them INERT). The one-off must not blank them while a tenant may still
#: run rc69, whose install and uninstall call them.
RC69_DROPPED_BY_THIS_BUILD = {"custom/modules/Accounts/BdAccountsLayoutExtensions.php"}


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
        """The one-off's rc69 list is what rc69 shipped. This build keeps all of
        it but ONE file - the Accounts layout writer 🔒 1724b retired (ERP-Core's
        ErpLayoutExtraFields places the fields from their vardef marker now) -
        and that one stays on an upgraded tenant, inert (Module Loader never
        deletes a file a later build stops shipping, §CW / G37); the one-off
        does not touch it."""
        with zipfile.ZipFile(built_zip()) as zipped:
            manifest = zipped.read("manifest.php").decode()
        copied = set(re.findall(r"'to'\s*=>\s*'([^']+)'", manifest))
        self.assertEqual(kept_by_rc69_in_the_report() - copied, RC69_DROPPED_BY_THIS_BUILD)
        self.assertEqual(copied, BUILD_ON_TENANT)
        self.assertEqual(set(oneoff_worklist()) & RC69_DROPPED_BY_THIS_BUILD, set())


class TheOneOffNeverTakesWhatThisBuildShips(unittest.TestCase):
    """G380/G381 grew the package past rc69. The one-off may be re-run after
    this build (its README asks for that on any tenant that took a Bench build
    after its last run), so it must not remove anything this build installs."""

    def test_no_worklist_names_a_file_this_build_ships(self):
        self.assertEqual(set(oneoff_worklist()) & BUILD_ON_TENANT, set())

    def test_this_build_ships_no_adapter_the_one_off_could_meet(self):
        """The unreleased G380/G381 branch shipped a new ResolveOrderableLines.php
        (the ADM non-part block). 🔒 1724b moved that rule into ERP-Epicor (a
        per-company switch), so this build ships no adapter at all: the
        one-off's md5-gated delete can never meet a body of ours."""
        self.assertFalse((PKG / ADAPTER).exists())
        self.assertNotIn(ADAPTER, zip_names())
        source = ONEOFF_POST_EXECUTE.read_text(encoding="utf-8")
        self.assertIn("$bdAdapterBenchMd5 = array(", source)   # anti-vacuity

    def test_the_report_no_longer_claims_an_owner_keep_for_the_repair_route(self):
        """🔒 1573: no such ruling exists; rc69 ships the file EMPTY."""
        self.assertNotIn("owner answer", _not_ours_block())


class Rc69NeitherShipsNorRestoresTheAdapter(unittest.TestCase):
    """rc69's uninstall_copy() restores only its own copy list's backups, so a
    path it never copies is one it can never put back.

    🔁 STILL TRUE AT rc70 (🔒 1724b): the unreleased G380/G381 branch would have
    copied ResolveOrderableLines.php again, reopening the restore-on-uninstall
    hazard over an rc37 body; the part-number rule moved into ERP-Epicor
    instead, so neither the planner nor the adapter ships."""

    def test_the_planner_never_ships_and_neither_does_the_adapter(self):
        names = zip_names()
        self.assertFalse((PKG / PLANNER).exists())
        self.assertNotIn(PLANNER, names)
        self.assertNotIn(ADAPTER, names)
        with zipfile.ZipFile(built_zip()) as zipped:
            manifest = zipped.read("manifest.php").decode()
        self.assertNotIn("BdSubmitOrderPlan", manifest)
        self.assertNotIn("ResolveOrderableLines", manifest)
        self.assertIn("sugarai_benchdogs_ext", manifest)   # anti-vacuity


class TheLogNamesTheVersionThatRan(unittest.TestCase):
    def test_no_version_is_typed_into_the_report(self):
        code = ONEOFF_POST_EXECUTE.read_text(encoding="utf-8")
        self.assertNotRegex(code, r"ONEOFF-RetireBdResidue \d+\.\d+\.\d+ - Bench Dogs retirement sweep")
        self.assertNotRegex(code, r"'ONEOFF-RetireBdResidue \d+\.\d+\.\d+:")
        self.assertIn("$bdOneoffVersion = isset($manifest['version'])", code)


class TheReleaseStagePolicyStep(unittest.TestCase):
    """G594 / 1.0.3 step 4c. The DELETION itself is proven by the harness below
    (Sugar's copy_path() verbatim, one synthetic tenant per body); these pin what
    the harness cannot see: that the allowlist is exactly the pinned bodies, that
    those bodies really are release-stage providers, and that the report no longer
    files the path under "left alone"."""

    @staticmethod
    def allowlist() -> set[str]:
        source = ONEOFF_POST_EXECUTE.read_text(encoding="utf-8")
        start = source.index("$bdReleasePolicyBenchMd5 = array(")
        block = source[start:source.index("\n);", start)]
        return set(re.findall(r"^\s*'([0-9a-f]{32})',\s*$", block, re.M))

    @staticmethod
    def pinned() -> dict[str, str]:
        return {hashlib.md5(p.read_bytes()).hexdigest(): p.name
                for p in sorted(RELEASE_POLICY_BODIES.glob("OpportunityReleaseStagePolicy.*.php.txt"))}

    def test_the_allowlist_is_exactly_the_pinned_bodies(self):
        """Every md5 the one-off deletes has a body the harness deletes, and every
        pinned body is on the list - so no entry is unexercised and no body is
        pinned that the one-off would refuse."""
        self.assertEqual(len(self.pinned()), 8, self.pinned())
        self.assertEqual(self.allowlist(), set(self.pinned()))

    def test_each_pinned_body_is_a_release_stage_provider(self):
        """Anti-vacuity: the fixtures are the provider class Partial Fulfillment
        loads, not arbitrary files that happen to hash."""
        for path in RELEASE_POLICY_BODIES.glob("OpportunityReleaseStagePolicy.*.php.txt"):
            with self.subTest(body=path.name):
                self.assertIn("class ErpOpportunityReleaseStagePolicy", path.read_text(encoding="utf-8"))

    def test_the_g594_body_is_on_the_list(self):
        """The rc45-rc64 body benchdogs-dev holds (from rc60)."""
        self.assertIn("e5e6e3fff432a5dcd6624accf6bb8598", self.allowlist())
        self.assertEqual(self.pinned()["e5e6e3fff432a5dcd6624accf6bb8598"],
                         "OpportunityReleaseStagePolicy.rc45-rc64.php.txt")

    def test_the_policy_is_no_longer_reported_as_left_alone(self):
        self.assertNotIn(RELEASE_POLICY, _not_ours_block())
        self.assertNotIn(RELEASE_POLICY, oneoff_worklist())   # deleted by 4c, not blanked by 4

    def test_the_one_off_is_1_0_5(self):
        # 1.0.5: the pre-rc86 BdAdmRules.php joins the blanked orphans, behind the rc86 guard.
        self.assertEqual((ONEOFF / "version").read_text().strip(), "1.0.5")


class TheStageStyleIsDeletedOnlyWhenItIsABenchBody(unittest.TestCase):
    """G599 / 1.0.4. The deletion and the SKIPPED report are proven by the harness
    (Sugar's uninstallExt() verbatim, one synthetic tenant per body, plus the
    Sugar-written control); these pin what the harness cannot see."""

    @staticmethod
    def guarded() -> dict[str, set[str]]:
        source = ONEOFF_POST_EXECUTE.read_text(encoding="utf-8")
        start = source.index("$bdGuardedExtensionBodies = array(")
        block = source[start:source.index("\n);", start)]
        out: dict[str, set[str]] = {}
        for path, inner in re.findall(r"'(custom/[^']+\.php)'\s*=>\s*array\((.*?)\n    \)", block, re.S):
            out[path] = set(re.findall(r"^\s*'([0-9a-f]{32})',\s*$", inner, re.M))
        return out

    @staticmethod
    def pinned() -> dict[str, str]:
        return {hashlib.md5(p.read_bytes()).hexdigest(): p.name
                for p in sorted(STAGE_STYLE_BODIES.glob("sales_stage_dom_style.rc*.php.txt"))}

    def test_the_only_guarded_path_is_the_stage_style(self):
        self.assertEqual(set(self.guarded()), {STAGE_STYLE})

    def test_the_style_allowlist_is_exactly_the_pinned_bench_bodies(self):
        """Every md5 the one-off deletes has a body the harness deletes, and every
        body BenchDogs-Ext shipped at the path (rc11, rc12-38, rc39-64, rc65-68) is
        on the list."""
        self.assertEqual(len(self.pinned()), 4, self.pinned())
        self.assertEqual(self.guarded()[STAGE_STYLE], set(self.pinned()))

    def test_each_pinned_body_is_a_bench_stage_style(self):
        """Anti-vacuity: the fixtures are Bench Dogs' stage-style fragment, not
        arbitrary files that happen to hash."""
        for path in STAGE_STYLE_BODIES.glob("sales_stage_dom_style.rc*.php.txt"):
            with self.subTest(body=path.name):
                text = path.read_text(encoding="utf-8")
                self.assertIn("sales_stage_dom_style", text)
                self.assertIn("Bench", text)

    def test_the_sugar_written_control_is_not_on_the_allowlist(self):
        """The control really is Sugar's (DropdownsManager::getExtensionContents()
        writes a "// created:" header and assigns the WHOLE array), and the one-off
        would leave it."""
        body = SUGAR_WRITTEN_STYLE.read_text(encoding="utf-8")
        self.assertRegex(body, r"^<\?php\n // created: \d{4}-\d\d-\d\d \d\d:\d\d:\d\d\n")
        self.assertIn("$app_dropdowns_style['sales_stage_dom_style']=array (", body)
        self.assertNotIn(hashlib.md5(SUGAR_WRITTEN_STYLE.read_bytes()).hexdigest(),
                         self.guarded()[STAGE_STYLE])

    def test_the_style_is_still_on_the_worklist(self):
        """The guard narrows the delete; it does not drop the path. A tenant that
        still holds a Bench body keeps a route off it (test_stage_dropdown_style)."""
        self.assertEqual(oneoff_worklist().get(STAGE_STYLE), "deleted")

    def test_every_unguarded_by_path_entry_has_a_bench_chosen_name(self):
        """Why only one path needs the guard: every other name was chosen by Bench
        Dogs (bd_*, bd01_*, Bd*), so no platform writer (Studio's sugarfield_* /
        en_us.sugar_*, DropdownsManager's <dropdown>_style) can have produced it.
        A new entry whose name is not Bench's fails here until it is guarded."""
        guarded = set(self.guarded())
        for path in oneoff_worklist():
            if path in guarded:
                continue
            name = path.rsplit("/", 1)[1]
            with self.subTest(path=path):
                self.assertRegex(name, r"bd_|bd01_|^Bd[A-Z]")


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
        # G594: the benchdogs-dev state, and the foreign-body control, by name.
        self.assertIn("PASS  benchdogs-dev state (rc45-rc64 body): the release-stage policy is DELETED, "
                      "not blanked", out.stdout)
        self.assertIn("PASS  CONTROL: a foreign release-stage policy is LEFT in place", out.stdout)
        # G599: the Sugar-written stage style, by name.
        self.assertIn("PASS  CONTROL: a Sugar-written stage style (not a Bench body) is LEFT in place, "
                      "unmodified", out.stdout)
        self.assertIn("PASS  run 1 reported the stage style under REMOVED, with the rc39-rc64 md5", out.stdout)


if __name__ == "__main__":
    unittest.main()
