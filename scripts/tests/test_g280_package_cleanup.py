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

#: Paths the platform loads BY PATH. They must SHIP and declare NOTHING.
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

DECLARE_PROBE = (
    "$mod_strings = ['SEED' => 1]; $app_list_strings = ['SEED' => 1];"
    "$dictionary = ['SEED' => 1]; $hook_array = ['SEED' => 1]; $viewdefs = ['SEED' => 1];"
    "$layout_defs = ['SEED' => 1]; $searchdefs = ['SEED' => 1]; $dashletData = ['SEED' => 1];"
    "include {path};"
    "echo json_encode(['mod' => $mod_strings, 'app' => $app_list_strings,"
    " 'dict' => $dictionary, 'hooks' => $hook_array, 'view' => $viewdefs,"
    " 'layout' => $layout_defs, 'search' => $searchdefs, 'dashlet' => $dashletData]);"
)
SEEDED = {"SEED": 1}


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


@unittest.skipUnless(shutil.which("php"), "requires php")
class EmptiedBecauseThePlatformLoadsThemByPath(unittest.TestCase):
    def test_every_stub_ships(self):
        """The half that actually retires anything. A dropped file leaves the
        tenant's copy in place and the retirement never happens."""
        for rel, why in EMPTY_STUBS.items():
            with self.subTest(file=rel):
                self.assertTrue((PKG / rel).is_file(),
                                f"{rel} must keep shipping to overwrite the tenant's copy — {why}")

    def test_every_stub_declares_nothing(self):
        """EXECUTED, not grepped: each file is included with every def array
        pre-seeded, and must leave all of them untouched."""
        for rel in EMPTY_STUBS:
            path = PKG / rel
            if not path.is_file():
                continue  # reported by test_every_stub_ships
            with self.subTest(file=rel):
                out = subprocess.run(
                    ["php", "-r", DECLARE_PROBE.replace("{path}", json.dumps(str(path)))],
                    capture_output=True, text=True, check=True)
                observed = json.loads(out.stdout)
                for key, value in observed.items():
                    self.assertEqual(value, SEEDED, f"{rel} still declares {key}")

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


@unittest.skipUnless(shutil.which("php"), "requires php")
class OnlyTheAdminRepairRouteIsRegistered(unittest.TestCase):
    """EXECUTED, not grepped: the real file is loaded behind its real ERP-Epicor
    guard and registerApiRest() is CALLED, so what is asserted is the route
    table Sugar would build — not the presence of a string."""

    #: Runs with `php -r`, so no opening tag. The class refuses to define itself
    #: unless ERP-Epicor's parent API file is on disk (uninstalling ERP-Epicor
    #: must not take down every REST endpoint), so the probe writes exactly that
    #: file with a do-nothing parent.
    ROUTES_PROBE = r"""
namespace Sugarcrm\Sugarcrm\Util\Files { class FileLoader { public static function validateFilePath($p) { return $p; } } }
namespace {
    @mkdir('custom/clients/base/api', 0777, true);
    file_put_contents('custom/clients/base/api/BaseErpActionsApi.php', '<?php class BaseErpActionsApi {}');
    require getenv('BD_API_FILE');
    if (!class_exists('BdBenchDogsActionsApi')) { echo json_encode(['error' => 'class not defined']); exit; }
    $routes = (new BdBenchDogsActionsApi())->registerApiRest();
    $paths = [];
    foreach ($routes as $key => $route) { $paths[$key] = implode('/', $route['path']) . ' -> ' . $route['method']; }
    echo json_encode(['routes' => $paths, 'methods' => get_class_methods('BdBenchDogsActionsApi')]);
}
"""

    @classmethod
    def setUpClass(cls):
        api = PKG / "custom/clients/base/api/BdBenchDogsActionsApi.php"
        with tempfile.TemporaryDirectory(prefix="g280-api-") as tmp:
            out = subprocess.run(["php", "-r", cls.ROUTES_PROBE], cwd=tmp,
                                 capture_output=True, text=True,
                                 env={**os.environ, "BD_API_FILE": str(api)})
            if "{" not in out.stdout:
                raise AssertionError(f"probe failed: {out.stdout[-400:]} {out.stderr[-400:]}")
            cls.observed = json.loads(out.stdout[out.stdout.index("{"):])

    def test_the_two_duplicate_routes_are_unregistered(self):
        """Core owns both: ERP-Epicor's AccountsErpActionsApi::createOppQuote is
        the superset (🔒 1044 / G15) and 'Send to Estimation' is ERP-Core's
        (🔒 531). Neither Bench route had a caller left — the three controllers
        that POSTed to them are empty stubs and no viewdef names their types."""
        registered = " ".join(self.observed.get("routes", {}).values())
        self.assertNotIn("bd-create-opp-quote", registered)
        self.assertNotIn("bd-send-to-estimating", registered)

    def test_the_admin_repair_route_survives(self):
        self.assertEqual(list(self.observed.get("routes", {})), ["bdRepairUi"])
        self.assertIn("bd-tools/repair-ui -> repairUi", list(self.observed["routes"].values()))

    def test_the_handlers_and_their_helper_are_gone(self):
        methods = {m.lower() for m in self.observed.get("methods", [])}
        for gone in ("createoppquote", "sendtoestimating", "estimatingstagefailure"):
            self.assertNotIn(gone, methods, f"{gone}() still exists on the class")
        self.assertIn("repairui", methods)

    def test_the_file_still_ships(self):
        """Dropping it would leave all three routes registered on every tenant
        that already has it — overwriting the file is what unregisters them."""
        self.assertTrue((PKG / "custom/clients/base/api/BdBenchDogsActionsApi.php").is_file())

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
