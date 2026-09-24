#!/usr/bin/env python3
"""G276 / 🔒 1503 + 🔒 1504 - Bench Dogs carries NO record-view button logic.

🛑 THE DEFECT, AS REPORTED. The owner, live on Bench quote
``afab6288-b697-11f1-931e-02f01c37da67``: an accepted quote showed Order Selected
Lines and no Submit Order. Bench's deployed Quotes record view listed only
``advanced_quote_button`` and ``erp_order_selected_button``. Stock quote
``df86be2c`` showed both order buttons.

WHY. Decision 91 (rc26) had Bench Dogs strip ERP-Epicor's
``create_erp_order_button`` (Submit Order) and
``refresh_price_availability_button`` off the deployed Quotes view on EVERY
install, stashing their definitions in config ``benchdogs`` /
``removed_quote_buttons`` for pre_uninstall to put back. Since ERP-Epicor re-adds
them on its own install, the result depended on install order - and G273
requires Bench Dogs to be installed LAST, so they were always stripped.

THE RULING, verbatim. 🔒 1503: *"a seller should have both selected line and
submited order use core dont use anything from bench dog extension logic for
buttons!"* / *"Donthave any logic on bench that is not on core"*. 🔒 1504:
*"remove now all button logic from bench and make sure any new logic you added
to bench exist in core"*.

WHAT THIS SUITE PINS.
1.  Running the REAL post_install.php, with the REAL layout helpers, over a
    Quotes view carrying core's full button set leaves that buttons array
    byte-identical, and writes no stash. Same for Accounts. Red on rc61: the
    Quotes view loses both ERP buttons and the stash is written.
2.  The Bench shape (both stripped) is RESTORED BY CORE, NOT BY US, and stays
    restored: core's real ``BaseErpLayout::addButtonsToRecordView()`` fed core's
    real ``QuotesLayout::erpActionButtons()`` (the call every ERP-Epicor install
    makes) followed by the Bench Dogs install leaves both order buttons in
    place. Red on rc61: Submit Order is gone again. Needs the sibling
    erp-integration-sugar checkout; skipped without it, and case 1 carries the
    red/green on its own.
3.  CONTROL: Bench Dogs' other layout work still runs - the REQ-19 customer
    group fields are still placed. (Through rc68 the retired Bench Dogs panel
    was also removed here; from rc69 that removal is the one-off's K-2, spent
    on every QA tenant, and the install does not touch the Quotes view at all.)
4.  No shipped PHP or JS reads or writes a record-view ``buttons`` array, calls
    a button writer, or touches the stash (comments stripped), and neither
    layout class still has a button method.

NOT PINNED, ON PURPOSE: that Bench Dogs restores the stripped buttons itself.
It must not (🔒 1504), and the stash holds rc26-era definitions core has since
changed (188b8de, 34ed435) - replaying them would be Bench writing a stale copy
of a core button.

Run against another tree with BD_PKG=<path to sugar-sell/BenchDogs-Ext>; that
is how the rc61 red run was taken.
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

import shared_sugar

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
PKG = Path(os.environ.get("BD_PKG", ROOT / "sugar-sell/BenchDogs-Ext"))


#: Core's layout installer and the Quotes layout that drives it. PINNED under
#: fixtures/shared-sugar (scripts/refresh_shared_fixtures.py), so the proof that
#: CORE restores the buttons runs in CI too - not only on a laptop that happens
#: to have both checkouts. test_shared_fixture_drift.py fails the moment the pin
#: and the real file diverge.
CORE_BASE = shared_sugar.resolve("BaseErpLayout.php")
CORE_QUOTES = shared_sugar.resolve("QuotesLayout.php")

COPIED = [
    "scripts/post_execute.php",
    "custom/modules/Quotes/BdQuotesLayoutExtensions.php",
    "custom/modules/Accounts/BdAccountsLayoutExtensions.php",
    "custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php",
    "custom/dropdowntemplates/bd_stage_doms.append.php",
]

B = lambda name, **kw: {"name": name, "type": "button", **kw}
CORE_QUOTES_BUTTONS = [
    B("cancel_button"), B("save_button"),
    B("refresh_price_availability_button", type="refresh-price-availability",
      label="LBL_REFRESH_PRICE_AVAILABILITY_BUTTON"),
    B("create_erp_order_button", type="create-erp-order", label="LBL_CREATE_ERP_ORDER_BUTTON"),
    B("advanced_quote_button", type="advanced-quote", label="LBL_ADVANCED_QUOTE_BUTTON"),
    B("erp_order_selected_button", type="erp-order-selected"),
    {"name": "main_dropdown", "type": "actiondropdown", "buttons": [B("edit_button")]},
    {"name": "sidebar_toggle", "type": "sidebartoggle"},
]
BENCH_STRIPPED = [b for b in CORE_QUOTES_BUTTONS
                  if b["name"] not in ("create_erp_order_button", "refresh_price_availability_button")]
QUOTES_PANELS = [
    {"name": "panel_header", "fields": [{"name": "name"}]},
    {"name": "LBL_RECORDVIEW_PANEL_BENCHDOGS", "fields": [{"name": "bd_erp_stage"}]},
    {"name": "panel_body", "fields": [{"name": "quote_stage"}]},
]
ACCOUNTS = {
    "buttons": [B("cancel_button"), B("save_button"),
                B("erp_create_opp_quote_button", label="LBL_ERP_CREATE_OPP_QUOTE_BUTTON"),
                {"name": "main_dropdown", "type": "actiondropdown", "buttons": []}],
    "panels": [{"name": "panel_header", "fields": [{"name": "name"}]},
               {"name": "panel_body", "fields": [{"name": "website"}]}],
}

HARNESS = r'''<?php
namespace Sugarcrm\Sugarcrm\MetaData {
    class ViewdefManager {
        public static $defs = [];
        public static $saves = [];
        public function loadViewdef($platform, $module, $view, $loadBase = false, $isLayout = false) {
            return self::$defs[$module] ?? [];
        }
        public function saveViewdef($viewdef, $module, $platform, $view, $isLayout = false) {
            self::$saves[] = $module;
            self::$defs[$module] = $viewdef;
        }
    }
}
namespace {
    use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;
    $GLOBALS['logged'] = [];
    class TestLog { public function __call($m, $a) { $GLOBALS['logged'][] = (string) ($a[0] ?? ''); } }
    $GLOBALS['log'] = new TestLog();
    $GLOBALS['settings'] = [];
    class Administration {
        public $settings = [];
        public function saveSetting($category, $key, $value) { $GLOBALS['settings'][] = [$category, $key, 'write']; }
        public function retrieveSettings($category = false) { $GLOBALS['settings'][] = [$category, '', 'read']; }
    }
    class BeanFactory {
        public static function newBean($m) { if ($m === 'Administration') return new Administration(); throw new Exception("bean $m"); }
        public static function getBean() { throw new Exception('No bean reads allowed'); }
    }
    class MetaDataFiles { public static function clearModuleClientCache($m = [], $t = '', $p = []) {} }
    class BdDemoDashboards { public function install() {} }
    class SugarAutoLoader { public static function load($p) {} }
    class RepairAndClear {
        public $show_output; public $module_list;
        public function clearVardefs() {}
        public function rebuildExtensions($modules) {}
    }
    class MetaDataManager {
        public static function refreshModulesCache($m) {}
        public static function refreshLanguagesCache($l) {}
    }
    class VardefManager { public static function clearVardef($m, $b) {} }
    function return_app_list_strings_language($language, $useCache = true) { return []; }

    $plan = json_decode(file_get_contents('plan.json'), true);
    ViewdefManager::$defs = $plan['defs'];
    $trace = [];
    foreach ($plan['steps'] as $step) {
        if ($step === 'core') {
            require_once 'custom/include/scripts/Modules/QuotesLayout.php';
            // Exactly QuotesLayout::install()'s button line, with core's own
            // definitions, through core's own add-if-absent.
            $probe = new class(true) extends BaseErpLayout {
                public function install(): void {
                    $m = new ReflectionMethod(QuotesLayout::class, 'erpActionButtons');
                    $m->setAccessible(true);
                    $this->addButtonsToRecordView('Quotes', $m->invoke(new QuotesLayout(true)), 'main_dropdown');
                }
                public function uninstall(): void {}
            };
            $probe->install();
        } else {
            require 'scripts/post_execute.php';
        }
        $trace[] = [$step, array_column(ViewdefManager::$defs['Quotes']['buttons'] ?? [], 'name')];
    }
    echo "\n@@JSON@@" . json_encode([
        'defs' => ViewdefManager::$defs,
        'saves' => ViewdefManager::$saves,
        'settings' => $GLOBALS['settings'],
        'trace' => $trace,
        'logged' => $GLOBALS['logged'],
        'synced' => $GLOBALS['synced'] ?? [],
    ]);
}
'''


def run(defs, steps=("bench",)):
    with tempfile.TemporaryDirectory(prefix="g276-") as tmp:
        t = Path(tmp)
        for rel in COPIED:
            src = PKG / rel
            if src.is_file():
                (t / rel).parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(src, t / rel)
        (t / "ModuleInstall").mkdir()
        (t / "ModuleInstall/ModuleInstaller.php").write_text(
            "<?php class ModuleInstaller { public $silent; public $id_name; public $base_dir;"
            " public $installdefs; public function install_languages() {}"
            " public function rebuild_tabledictionary() {}"
            " public function rebuild_languages($l = [], $m = []) {} }")
        # rc70 (🔒 1724b): the placement is ERP-Core's ErpLayoutExtraFields. A
        # recording stand-in: it proves the install ASKS for placement, and -
        # touching no view itself - leaves every button check below about
        # this package's own code.
        (t / "custom/include").mkdir(parents=True, exist_ok=True)
        (t / "custom/include/ErpLayoutExtraFields.php").write_text(
            "<?php class ErpLayoutExtraFields { public static function sync($m) {"
            " $GLOBALS['synced'][] = $m; return ['added' => [], 'removed' => []]; } }")
        (t / "include/TemplateHandler").mkdir(parents=True)
        (t / "include/TemplateHandler/TemplateHandler.php").write_text(
            "<?php class TemplateHandler { public static function clearCache($m = null, $v = null) {} }")
        if "core" in steps:
            (t / "custom/include/scripts/Modules").mkdir(parents=True)
            shutil.copy2(CORE_BASE, t / "custom/include/scripts/BaseErpLayout.php")
            shutil.copy2(CORE_QUOTES, t / "custom/include/scripts/Modules/QuotesLayout.php")
        (t / "plan.json").write_text(json.dumps({"defs": defs, "steps": list(steps)}))
        out = subprocess.run(["php", "-d", "error_reporting=E_ALL & ~E_DEPRECATED"],
                             input=HARNESS, text=True, cwd=t, capture_output=True)
        if "@@JSON@@" not in out.stdout:
            raise AssertionError(f"harness failed rc={out.returncode}: {out.stdout[-800:]} {out.stderr[-800:]}")
        return json.loads(out.stdout.split("@@JSON@@", 1)[1])


def names(buttons):
    return [b.get("name") for b in buttons]


@unittest.skipUnless(shutil.which("php"), "requires php")
class BenchDogsInstallLeavesButtonsAlone(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.full = run({"Quotes": {"buttons": CORE_QUOTES_BUTTONS, "panels": QUOTES_PANELS},
                        "Accounts": ACCOUNTS})

    def test_quotes_buttons_are_byte_identical_after_a_bench_dogs_install(self):
        after = self.full["defs"]["Quotes"]["buttons"]
        self.assertIn("create_erp_order_button", names(after), "Submit Order was stripped")
        self.assertIn("erp_order_selected_button", names(after))
        self.assertEqual(json.dumps(after), json.dumps(CORE_QUOTES_BUTTONS),
                         "the Bench Dogs install changed the Quotes buttons array")

    def test_accounts_buttons_are_byte_identical_after_a_bench_dogs_install(self):
        self.assertEqual(json.dumps(self.full["defs"]["Accounts"]["buttons"]),
                         json.dumps(ACCOUNTS["buttons"]))

    def test_no_button_stash_is_read_or_written(self):
        touched = [s for s in self.full["settings"] if s[0] == "benchdogs"]
        self.assertEqual(touched, [], "the install still reads or writes the decision-91 stash")

    def test_the_install_no_longer_touches_the_quotes_view(self):
        """rc69 (G280 / 🔒 1567): the Bench Dogs panel removal moved to the
        one-off's K-2, which ran on every QA tenant, so the install writes
        nothing to Quotes - not even a removal. The panel given here is left
        exactly as it came; its route off a tenant is the one-off."""
        self.assertNotIn("Quotes", self.full["saves"])
        panels = [p["name"] for p in self.full["defs"]["Quotes"]["panels"]]
        self.assertEqual(panels, [p["name"] for p in QUOTES_PANELS])
        oneoff = (ROOT / "sugar-sell/ONEOFF-RetireBdResidue/scripts/post_execute.php").read_text()
        self.assertIn("BdQuotesLayoutExtensions::write();", oneoff,
                      "nothing removes the retired Bench Dogs panel any more")

    def test_control_the_install_asks_erp_core_to_place_the_fields(self):
        """ANTI-VACUITY: the install really ran. Since rc70 (🔒 1724b) it places
        nothing itself - it asks ERP-Core's ErpLayoutExtraFields, once per
        module, and writes no view of its own."""
        self.assertEqual(self.full["synced"], ["Accounts", "Quotes"])
        self.assertEqual(self.full["saves"], [])


@unittest.skipUnless(shutil.which("php"), "requires php")
class CoreRestoresAndBenchDogsKeeps(unittest.TestCase):

    def test_a_stripped_bench_view_is_restored_by_core_and_stays_restored(self):
        """Bench today -> ERP-Epicor install (core's add-if-absent) -> Bench Dogs
        install. The last step must not take Submit Order away again."""
        out = run({"Quotes": {"buttons": BENCH_STRIPPED, "panels": QUOTES_PANELS},
                   "Accounts": ACCOUNTS}, steps=("core", "bench"))
        after_core, after_bench = out["trace"][0][1], out["trace"][1][1]
        for name in ("create_erp_order_button", "refresh_price_availability_button"):
            self.assertIn(name, after_core, f"core did not restore {name}")
        self.assertEqual(after_bench, after_core,
                         "the Bench Dogs install changed the buttons core had just restored")
        self.assertIn("create_erp_order_button", after_bench)
        self.assertIn("erp_order_selected_button", after_bench)


class NoButtonLogicShipped(unittest.TestCase):

    @staticmethod
    def _code(path: Path) -> str:
        text = path.read_text(encoding="utf-8", errors="replace")
        text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
        return re.sub(r"(^|\s)(//|#)[^\n]*", r"\1", text)

    def test_no_shipped_file_touches_a_record_view_buttons_array(self):
        offenders = []
        pattern = re.compile(
            r"\[['\"]buttons['\"]\]|writeButtons|removed_quote_buttons|clearStash|stashedButtons"
            r"|stashRemoved|addButtonsToRecordView|removeButtonsFromRecordView"
            r"|create_erp_order_button|refresh_price_availability_button|erp_order_selected_button"
            r"|advanced_quote_button|erp_create_opp_quote_button|bd_[a-z_]+_button")
        for path in sorted(list(PKG.glob("custom/**/*.php")) + list(PKG.glob("custom/**/*.js"))
                           + list(PKG.glob("scripts/*.php"))):
            for m in pattern.finditer(self._code(path)):
                offenders.append(f"{path.relative_to(PKG)}: {m.group(0)}")
        self.assertEqual(offenders, [], "record-view button logic is still shipped")

    def test_the_button_label_files_are_retired_off_the_tenant(self):
        """A label with no action behind it keeps a retired button reading as
        supported in Studio, the report builder and column pickers. Through
        rc68 both files shipped EMPTY so they overwrote the tenant's copy; from
        rc69 the one-off deletes them (bd_retirement)."""
        from bd_retirement import assert_retired_by_oneoff
        for rel in ("custom/Extension/modules/Quotes/Ext/Language/en_us.bd_action_buttons.php",
                    "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_action_buttons.php"):
            with self.subTest(file=rel):
                assert_retired_by_oneoff(self, rel, "labels for retired buttons")

    def test_neither_layout_class_ships(self):
        # The Quotes class no longer ships at all (rc69); the one-off carries
        # its own copy for K-2. The Accounts class went at rc70 (🔒 1724b):
        # ERP-Core's ErpLayoutExtraFields places the fields from their marker.
        self.assertFalse((PKG / "custom/modules/Quotes/BdQuotesLayoutExtensions.php").exists())
        self.assertFalse((PKG / "custom/modules/Accounts/BdAccountsLayoutExtensions.php").exists())
        for php in sorted((PKG / "custom").rglob("*.php")) + sorted((PKG / "scripts").rglob("*.php")):
            code = self._code(php)
            for method in ("writeButtons", "nextSurvivor", "stashRemoved", "stashedButtons", "clearStash"):
                self.assertNotRegex(code, rf"function\s+{method}\b", f"{php.name} defines {method}()")


if __name__ == "__main__":
    unittest.main()
