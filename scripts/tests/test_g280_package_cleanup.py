#!/usr/bin/env python3
"""G280 / 🔒 1507 — the package stops shipping what core owns, and RETIRES it properly.

Owner: *"dont forget the clean up of benchdog MLP and connecotr there is a lot of
code that should not be there!!!"*, on top of 🔒 1503/1504's *"Donthave any logic
on bench that is not on core"*.

🛑 THE RULE THAT DECIDES delete-vs-empty-stub, and it is not a style choice.
Module Loader COPIES a package's files and never deletes the previous version's
(§CW / G37, rc24's lesson, re-proved by G243 on a logic hook). So:

*   A file the platform LOADS BY PATH — a `custom/Extension/...` fragment, a
    vardef, a language file — must keep shipping, EMPTIED. Dropping it from the
    build leaves the installed copy live on every hosted tenant, and the
    retirement never happens.
*   A file that is only ever loaded BY NAME from code this package also ships —
    a helper class behind a `require_once` — can be deleted outright, because
    once the only caller is gone nothing can reach it.

Each case below says which of the two it is asserting.

🔁 0.9.42-rc69 (G280 / 🔒 1567, 🔒 1521) MOVED THE FIRST HALF. The emptied
stubs have done their job - every QA tenant took a build that overwrote them -
and the one-off ONEOFF-RetireBdResidue now DELETES those paths on the tenant
(it ran on all three), so the package stops shipping them. What stays true is
the lesson: a dropped path retires nothing by itself, so each former stub is
asserted as retired OFF THE TENANT (bd_retirement.assert_retired_by_oneoff),
never merely "absent". The one path the one-off does not cover and the platform
loads by path is the REST api file - see NoRouteIsRegistered.

MUTATION-VERIFIED (each applied, suite re-run, listed failure observed):
  restore any deleted class file        -> the matching "no longer ships" case fails
  put a label back in a stub            -> the matching "declares nothing" case fails
  drop a stub from the build            -> "ships an empty stub" fails (not "declares")
  delete bd_to_order from bdLegacyColumnNames -> the legacy-sweep control fails

Run against another tree with BD_PKG=<path to sugar-sell/BenchDogs-Ext>; that is
how the rc64 red run was taken.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

from bd_retirement import assert_retired_by_oneoff, oneoff_worklist

ROOT = Path(__file__).resolve().parents[2]
PKG = Path(os.environ.get("BD_PKG", ROOT / "sugar-sell/BenchDogs-Ext"))

#: Helper classes with no caller left. Deleted, not emptied: each was reachable
#: only through a require_once this package also ships, and that call is gone.
DELETED_CLASSES = {
    "custom/modules/Accounts/BdAccountCountryGuard.php":
        "ERP-Core's ErpAccountCountryGuard owns the billing-country check; this "
        "package's hook fragment registers nothing, so the class was unreachable",
    "custom/modules/Contacts/BdContactSyncHook.php":
        "the contact-sync hook fragment is an emptied stub, so nothing loads it",
    "scripts/BdDemoDashboards.php":
        "the baked demo dashboards were composed from other packages' dashlets",
}

#: Paths the platform loads BY PATH. Through rc68 they SHIPPED and declared
#: NOTHING; from rc69 they do not ship and the one-off deletes them.
EMPTY_STUBS = {
    "custom/Extension/modules/Products/Ext/Language/en_us.bd_line_order.php":
        "LBL_BD_TO_ORDER / LBL_BD_ORDERED over stubbed vardefs whose columns the "
        "install removes",
    "custom/Extension/application/Ext/Language/en_us.bd_erp_stage_list.php":
        "bd_erp_stage_list, dropped from the build by 0c17913 without a stub",
    "custom/Extension/modules/RevenueLineItems/Ext/Vardefs/bd_deliverable_key.php":
        "RevenueLineItem.bd_deliverable_key, dropped from the build by fc5ec59 "
        "without a stub",
    "custom/Extension/modules/Accounts/Ext/LogicHooks/bd_account_country_guard.php":
        "the hook registration that used to name the deleted guard class",
    "custom/Extension/modules/Contacts/Ext/LogicHooks/bd_contact_sync.php":
        "the hook registration that used to name the deleted contact-sync class",
    "custom/Extension/modules/Quotes/Ext/Language/en_us.bd_action_buttons.php":
        "ten labels for retired Bench quote actions (rc64)",
    "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_action_buttons.php":
        "two labels for the retired Bench Create Opportunity & Quote button (rc64)",
}



class DeletedBecauseNothingCanLoadThem(unittest.TestCase):
    def test_the_orphaned_classes_no_longer_ship(self):
        for rel, why in DELETED_CLASSES.items():
            with self.subTest(file=rel):
                self.assertFalse((PKG / rel).exists(), f"{rel} is back — {why}")

    def test_nothing_shipped_still_names_them(self):
        """Deleting a file that something still requires is an install-time fatal,
        which is the one way this cleanup could break a tenant."""
        names = ("BdAccountCountryGuard", "BdContactSyncHook", "BdDemoDashboards")
        offenders = []
        for path in sorted(list(PKG.glob("custom/**/*.php")) + list(PKG.glob("scripts/*.php"))
                           + list(PKG.glob("custom/**/*.js"))):
            code = re.sub(r"/\*.*?\*/", "", path.read_text(encoding="utf-8", errors="replace"), flags=re.S)
            code = re.sub(r"(^|\s)(//|#)[^\n]*", r"\1", code)
            for name in names:
                if name in code:
                    offenders.append(f"{path.relative_to(PKG)}: {name}")
        self.assertEqual(offenders, [], "a deleted class is still named in shipped code")


class RetiredOffTheTenantBecauseThePlatformLoadsThemByPath(unittest.TestCase):
    def test_every_former_stub_is_retired_off_the_tenant(self):
        """The half that actually retires anything. A dropped file leaves the
        tenant's copy in place and the retirement never happens - so each path
        must still be on the one-off's worklist."""
        for rel, why in EMPTY_STUBS.items():
            with self.subTest(file=rel):
                assert_retired_by_oneoff(self, rel, why)

    def test_the_one_off_deletes_each_one_rather_than_blanking_it(self):
        """A fragment is compiled by path: the only complete removal is the
        file going, which is uninstallExt()'s route, not a blank body."""
        worklist = oneoff_worklist()
        for rel in EMPTY_STUBS:
            with self.subTest(file=rel):
                self.assertEqual(worklist.get(rel), "deleted")

    def test_control_the_grid_logic_that_backed_this_stub_is_gone(self):
        """🛑 REPLACES rc65's "the legacy column sweep still names its columns".

        rc65 kept bd_to_order / bd_ordered inside
        `BdQliColumnsLayout::bdLegacyColumnNames()` because that list was the
        REMOVAL's input: emptying the LABEL file was the retirement, and
        deleting the names would have made the deployed-metadata sweep a silent
        no-op. 0.9.42-rc66 (G280 / 🔒 1508) deletes the whole grid class, so
        there is no sweep and no input left — which is a state that has to be
        ASSERTED rather than left implied, or the emptied label above starts
        reading as the second half of a mechanism that no longer has a first.

        Why the sweep could go: it writes to DEPLOYED METADATA, which persists
        once written, and it has run on every install since 0.9.21 — through
        rc65 on the only tenant that carries this package (stock has no Bench
        Dogs by design, RELEASE-CONTROL.md:757). It was armed for instances that
        ran 0.9.17/0.9.19; it has fired on the one that did."""
        self.assertFalse((PKG / "custom/modules/Quotes/BdQliColumnsLayout.php").exists())
        self.assertFalse((PKG / "custom/modules/Quotes/BdQliColumnTemplate.php").exists())
        # CODE only. The emptied label file's retirement note NAMES the sweep it
        # used to pair with, and a scan that counted comments would make writing
        # down why something was removed the thing that fails the build.
        offenders = []
        for php in PKG.rglob("*.php"):
            body = re.sub(r"/\*.*?\*/", "", php.read_text(encoding="utf-8"), flags=re.S)
            body = re.sub(r"(?m)^\s*//.*$", "", body)
            if "bdLegacyColumnNames" in body or "removeFieldsFromDataGroupListView" in body:
                offenders.append(str(php.relative_to(PKG)))
        self.assertEqual(offenders, [], f"the grid sweep is back: {offenders}")


class NoRouteIsRegistered(unittest.TestCase):
    """rc87 (Rafael's review of #41, item 2): the emptied REST stub no longer ships. A tenant keeps its copy
    (Module Loader never deletes a file a later build stops shipping), whatever body it holds - the empty one
    rc69-rc86 installed, or rc68's with bd-tools/repair-ui - until the one-off deletes the path; the one-off
    requires rc87 or newer, so it runs after this build."""

    def test_the_file_no_longer_ships(self):
        self.assertFalse((PKG / "custom/clients/base/api/BdBenchDogsActionsApi.php").exists())

    def test_the_one_off_deletes_it_whatever_its_body(self):
        self.assertEqual(oneoff_worklist().get("custom/clients/base/api/BdBenchDogsActionsApi.php"), "deleted")

    def test_the_client_halves_are_gone(self):
        for rel in ("custom/modules/Quotes/clients/base/fields/bd-best-pricing",
                    "custom/modules/Quotes/clients/base/fields/bd-send-estimating",
                    "custom/modules/Accounts/clients/base/fields/bd-create-opp-quote"):
            with self.subTest(dir=rel):
                self.assertFalse((PKG / rel).exists(), f"{rel} is back")


class DemoDashboardsAreGone(unittest.TestCase):
    def test_post_install_neither_requires_nor_composes_them(self):
        post = (PKG / "scripts/post_execute.php").read_text(encoding="utf-8")
        code = re.sub(r"/\*.*?\*/", "", post, flags=re.S)
        code = re.sub(r"(^|\s)(//|#)[^\n]*", r"\1", code)
        self.assertNotIn("BdDemoDashboards", code)
        self.assertNotIn("dashlets", code, "post_install still composes dashboard tiles")

    def test_the_class_cannot_reach_a_tenant_through_the_scripts_copy(self):
        """pack.php copies scripts/*.php to custom/include/bd_scripts/. With the
        file gone there is no entry, so no new tenant gets the composer at all."""
        self.assertFalse((PKG / "scripts/BdDemoDashboards.php").exists())


if __name__ == "__main__":
    unittest.main()
