#!/usr/bin/env python3
"""The install hands the stage vocabulary to core - and, since rc69, all of it.

0.9.42-rc65, G278 / 🔒 1506 + G280 / 🔒 1507. Owner: *"this hsoudl happen in the
core"*, *"Donthave any logic on bench that is not on core"*. 0.9.42-rc69,
G280 / 🔒 1567: *"Benchdog MLP shoudl be mininal with mininal foot print of
overide"*.

WHAT THE INSTALL DOES NOW - LESS, and each "no longer" is executed below against
the REAL scripts/post_execute.php (top-level code, `require`d exactly as
ModuleInstaller::post_execute() does):

1.  NO CONFIG WRITE. rc65-rc68 wrote `erp_integration.partial_order_sales_stage`
    = 'Partial Production Ordered' when absent, because it was TENANT DATA with
    no other writer and Partial Fulfillment read it. PF >= 1.0.43 carries that
    exact value as a READER-side default (ErpOpportunityValuation::
    DEFAULT_PARTIAL_STAGE, G305 / 🔒 1519), so a tenant without the row already
    behaves as if it had been written; one with the row keeps it. The manifest's
    PF floor is 1.0.43 for exactly this. And the install does not even READ core's
    erp_integration category any more.

2.  NO FRAGMENT REMOVAL. Deleting the accumulated
    en_us.zz_bd_stage_doms.php through `uninstall_languages()` was a one-shot;
    it moved to the one-off ONEOFF-RetireBdResidue (K-5), which ran on every QA
    tenant, and was withdrawn with that one-off's code by 🔒2173b.

3.  NEVER INSTALL A LANGUAGE FRAGMENT AGAIN. `install_languages()` must not be
    called at all: PF owns the keys.

4.  NO LANGUAGE REBUILD AND NO READ OF PF'S VOCABULARY. The rebuild existed to
    recompile the EMPTIED stage fragments, and rc69 ships none;
    ModuleInstaller::install() already rebuilds the languages before
    post_execute. The "release stages served by core" read reported on another
    package's vocabulary.

MUTATION-VERIFIED (each applied, suite re-run, listed failure observed):
  restore the config block          -> writes_no_partial_stage_config fails
  restore the uninstall_languages call -> no_longer_removes_the_fragment_itself fails
  call install_languages again      -> declares_no_language_fragment fails
  wrap the body in a function       -> body_is_top_level_code fails
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PACKAGE = Path(os.environ.get("BD_PKG", ROOT / "sugar-sell/BenchDogs-Ext"))

HARNESS = r'''<?php
$scenario = SCENARIO;
$events = [];
$errors = [];
$settings = ['erp_integration' => []];
if ($scenario === 'config_present') {
    $settings['erp_integration']['partial_order_sales_stage'] = 'Someone Elses Stage';
}
$served = $scenario !== 'stages_missing';
class TestLog {
    public function error($s) { $GLOBALS['errors'][] = $s; }
    public function fatal($s) { $GLOBALS['errors'][] = $s; }
    public function warn($s) { $GLOBALS['errors'][] = $s; }
}
$GLOBALS['log'] = new TestLog();
class TestAdmin {
    public function getConfigForModule($category) {
        $GLOBALS['events'][] = ['read_config', $category];
        return $GLOBALS['settings'][$category] ?? [];
    }
    public function saveSetting($category, $key, $value) {
        $GLOBALS['events'][] = ['write_config', $category, $key, $value];
        $GLOBALS['settings'][$category][$key] = $value;
    }
}
class BeanFactory {
    public static function newBean($module) {
        if ($module === 'Administration') { return new TestAdmin(); }
        throw new Exception('no bean writes allowed: ' . $module);
    }
    public static function getBean() { throw new Exception('No bean reads allowed'); }
}
class SugarAutoLoader { public static function load($p) {} }
class RepairAndClear {
    public $show_output; public $module_list;
    public function clearVardefs() {}
    public function rebuildExtensions($modules) {
        if (in_array('RevenueLineItems', $modules, true)) { $GLOBALS['events'][] = 'RLI_REPAIR'; }
        $GLOBALS['events'][] = 'rebuild_extensions';
    }
}
class MetaDataManager {
    public static function refreshModulesCache($modules) {
        if (in_array('RevenueLineItems', $modules, true)) { $GLOBALS['events'][] = 'RLI_REPAIR'; }
    }
    public static function refreshLanguagesCache($languages) { $GLOBALS['events'][] = ['refresh_languages', $languages]; }
}
class VardefManager { public static function clearVardef($module, $bean = null) {} }
function return_app_list_strings_language($language, $useCache = true) {
    $GLOBALS['events'][] = ['verify', $language, $useCache];
    if (!$GLOBALS['served']) { return ['sales_stage_dom' => []]; }
    return ['sales_stage_dom' => [
        'Prototype Ordered' => 'Prototype Ordered',
        'Partial Production Ordered' => 'Partial Production Ordered',
    ]];
}
$failure = null;
try {
    require 'scripts/post_execute.php';
} catch (Throwable $e) { $failure = $e->getMessage(); }
echo json_encode(['failure' => $failure, 'events' => $events, 'errors' => $errors,
                  'settings' => $GLOBALS['settings']]);
'''

INSTALLER_STUB = r'''<?php
class ModuleInstaller {
    public $silent; public $id_name; public $base_dir; public $installdefs;
    public function install_languages() {
        $GLOBALS['events'][] = ['install_languages', $this->id_name];
    }
    public function uninstall_languages() {
        $from = $this->installdefs['language'][0]['from'] ?? '';
        $GLOBALS['events'][] = ['uninstall_languages', $this->id_name, $from];
    }
    public function rebuild_tabledictionary() { $GLOBALS['events'][] = 'rebuild_tabledictionary'; }
    public function rebuild_languages($languages = [], $modules = []) {
        $GLOBALS['events'][] = ['rebuild_languages', $languages, $modules];
    }
}
'''


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class PostInstallStageLanguagesTest(unittest.TestCase):
    def execute(self, scenario="success"):
        with tempfile.TemporaryDirectory(prefix="bench-stage-installer-") as tmp:
            target = Path(tmp)
            for relative in (
                "scripts/post_execute.php",
            ):
                path = target / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(PACKAGE / relative, path)
            (target / "ModuleInstall").mkdir()
            (target / "ModuleInstall/ModuleInstaller.php").write_text(INSTALLER_STUB)
            code = HARNESS.replace("SCENARIO", json.dumps(scenario))
            result = subprocess.run(["php"], input=code, text=True, cwd=target,
                                    capture_output=True, check=True)
            return json.loads(result.stdout)

    # ---- 1. no config: PF >= 1.0.43 answers on the read side -----------------

    def test_writes_no_partial_stage_config(self):
        observed = self.execute()
        self.assertIsNone(observed["failure"])
        writes = [e for e in observed["events"]
                  if isinstance(e, list) and e[0] == "write_config" and e[1] == "erp_integration"]
        self.assertEqual(writes, [], "the install writes core's erp_integration config again")
        # PHP encodes an empty array as [] - either empty shape is "untouched".
        self.assertFalse(observed["settings"]["erp_integration"])

    def test_reads_no_erp_integration_config_either(self):
        reads = [e for e in self.execute()["events"]
                 if isinstance(e, list) and e[0] == "read_config"]
        self.assertEqual(reads, [])

    def test_respects_an_existing_choice(self):
        """A config row is tenant data. A tenant (Bench) that already holds a
        value - written by rc65-rc68 - keeps it: nothing here deletes or
        rewrites it."""
        observed = self.execute("config_present")
        self.assertEqual(observed["settings"]["erp_integration"]["partial_order_sales_stage"],
                         "Someone Elses Stage")

    # ---- 2/3. no fragment removal, and no new declaration -------------------

    def test_no_longer_removes_the_fragment_itself(self):
        """Spent: the one-off's K-5 did the removal and ran on every QA tenant
        (withdrawn since, 🔒2173b)."""
        observed = self.execute()
        calls = [e for e in observed["events"]
                 if isinstance(e, list) and e[0] == "uninstall_languages"]
        self.assertEqual(calls, [])

    def test_declares_no_language_fragment(self):
        observed = self.execute()
        installs = [e for e in observed["events"]
                    if isinstance(e, list) and e[0] == "install_languages"]
        self.assertEqual(installs, [], "this package is declaring stage keys again; PF owns them")

    # ---- 4. the one rebuild left, and no read of another package ------------

    def test_rebuilds_accounts_and_nothing_else(self):
        observed = self.execute()
        names = [e[0] if isinstance(e, list) else e for e in observed["events"]]
        self.assertIn("rebuild_extensions", names)
        for gone in ("rebuild_languages", "refresh_languages", "rebuild_tabledictionary"):
            self.assertNotIn(gone, names, f"post_install still does {gone}")

    def test_does_not_read_another_packages_vocabulary(self):
        observed = self.execute("stages_missing")
        self.assertNotIn("verify", [e[0] for e in observed["events"] if isinstance(e, list)])
        self.assertFalse([e for e in observed["errors"] if "release stages served" in e])
        self.assertIsNone(observed["failure"])

    # ---- the structural properties the rc26 defect taught -------------------

    def test_body_is_top_level_code_that_cannot_kill_an_install(self):
        # Comments stripped: this file DOCUMENTS the rc25 defect ("the whole body
        # sat inside function post_execute() and nothing ever called it"), and a
        # blunt substring match reads that explanation as the defect itself.
        raw = (PACKAGE / "scripts/post_execute.php").read_text()
        source = re.sub(r"/\*.*?\*/", "", raw, flags=re.S)
        source = re.sub(r"(^|\s)//[^\n]*", r"\1", source)
        self.assertNotIn("function post_execute", source,
                         "ModuleInstaller only require_once's this file; a function is never called")
        self.assertNotIn("throw new", source,
                         "an uncaught throw here force-uninstalls the package")

    def test_proof_of_life_is_logged_at_fatal(self):
        observed = self.execute()
        # G295: the running line names the version it is installing; a bare
        # top-level require has no $manifest, so here it reads "(unknown)".
        self.assertIn("BenchDogs-Ext: post_install running (unknown) - writing deployed metadata",
                      observed["errors"])
        self.assertIn("BenchDogs-Ext: post_install finished", observed["errors"])

    def test_installer_does_not_repair_revenue_line_items(self):
        self.assertNotIn("RLI_REPAIR", self.execute()["events"])

    def test_built_package_contains_the_same_installer(self):
        version = (PACKAGE / "version").read_text().strip()
        with zipfile.ZipFile(PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip") as archive:
            path = "scripts/post_execute.php"
            self.assertEqual(archive.read(path), (PACKAGE / path).read_bytes())
            self.assertIn("<basepath>/scripts/post_execute.php", archive.read("manifest.php").decode())

    def test_the_stage_template_no_longer_ships(self):
        """The template was install_languages()' input. Nothing installs it now,
        and Sugar never loaded it by path, so it goes rather than being emptied."""
        self.assertFalse((PACKAGE / "custom/dropdowntemplates/bd_stage_doms.append.php").exists())


class OpportunitiesOnlyRepairContractTest(unittest.TestCase):
    """rc65-rc68 pinned what the admin repair route did NOT do. rc69 (G280 /
    🔒 1567) retires the route: the api file ships empty."""

    def test_the_repair_endpoint_is_gone(self):
        # rc87: the emptied endpoint file no longer ships at all (the one-off that deleted the tenant's copy is withdrawn).
        self.assertFalse((PACKAGE / "custom/clients/base/api/BdBenchDogsActionsApi.php").exists())


if __name__ == "__main__":
    unittest.main()
