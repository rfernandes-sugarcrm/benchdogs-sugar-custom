#!/usr/bin/env python3
"""The install hands the stage vocabulary to core, and takes Bench's copy back.

0.9.42-rc65, G278 / 🔒 1506 + G280 / 🔒 1507. Owner: *"this hsoudl happen in the
core"*, *"Donthave any logic on bench that is not on core"*.

WHAT THE INSTALL MUST DO NOW, and each is executed below against the REAL
scripts/post_install.php (top-level code, `require`d exactly as
ModuleInstaller::post_execute() does):

1.  WRITE THE CONFIG FIRST. The release-stage provider this package shipped is
    now a stub that returns null, so Partial Fulfillment decides the stage from
    `erp_integration.partial_order_sales_stage`. That key is TENANT DATA - it
    does not arrive with the package - so if the provider is neutered and the
    key is never written, the Opportunity stage silently stops being written at
    all. It runs before any layout work, in its own try/catch, and it does NOT
    overwrite a value someone already chose.

2.  DELETE THE ACCUMULATED FRAGMENT, ONCE. Until rc64 post_install APPENDED the
    stage template to custom/Extension/application/Ext/Language/
    en_us.zz_bd_stage_doms.php through `install_languages()`, which
    CONCATENATES rather than overwrites (SugarEnt 26.1.0
    ModuleInstall/ModuleInstaller.php:1227-1235). Every past version's keys are
    still in that file on every Bench Dogs tenant - including decision 314's
    retired '...Closed' pair (G268). `uninstall_languages()` is the exact
    mirror of that install and the only removal a package is allowed
    (`unlink()` is denied by the cloud scanner), so the install calls it with
    the same installer class, the same id_name and the same template path.

3.  NEVER INSTALL A LANGUAGE FRAGMENT AGAIN. `install_languages()` must not be
    called at all: PF owns the keys, and a second declaration is exactly the
    duplication the ruling removes.

4.  REBUILD THE LANGUAGES. This install EMPTIES fragments it used to declare
    stages and styles in; until the application strings are recompiled, the
    tenant keeps serving what those files said.

MUTATION-VERIFIED (each applied, suite re-run, listed failure observed):
  drop the config block            -> writes_the_partial_stage_config fails
  overwrite an existing config     -> respects_an_existing_choice fails
  drop the uninstall_languages call-> removes_the_accumulated_fragment fails
  call install_languages again     -> declares_no_language_fragment fails
  wrap the body in a function      -> body_is_top_level_code fails
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
                "custom/Extension/application/Ext/Language/en_us.bd_stage_doms.php",
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

    # ---- 1. the config, which is the half that cannot be redone later --------

    def test_writes_the_partial_stage_config(self):
        observed = self.execute()
        self.assertIsNone(observed["failure"])
        self.assertIn(["write_config", "erp_integration", "partial_order_sales_stage",
                       "Partial Production Ordered"], observed["events"])
        self.assertEqual(observed["settings"]["erp_integration"]["partial_order_sales_stage"],
                         "Partial Production Ordered")

    def test_respects_an_existing_choice(self):
        """A config row is tenant data. An admin (or a later decision) that set
        another stage keeps it; this package does not re-decide on every
        install.

        🛑 NARROWED IN 0.9.42-rc67, AND THE NARROWING IS THE POINT. This used to
        assert NO config write at all. G294's step report now writes exactly one
        more row - benchdogs_ext.install_report, on every install, pass or fail -
        so a blanket "no writes" assertion would fail for a reason that has
        nothing to do with what this test is about. It is scoped to the stage
        key instead of deleted or relaxed to a substring: an overwrite of
        partial_order_sales_stage still fails it, which is the defect it exists
        for. Any OTHER unexpected category/key would slip past this, which is
        why test_post_install_step_report.py asserts the report row's category,
        key and contents explicitly rather than leaving it uncovered here."""
        observed = self.execute("config_present")
        writes = [e for e in observed["events"]
                  if isinstance(e, list) and e[0] == "write_config"
                  and e[2] == "partial_order_sales_stage"]
        self.assertEqual(writes, [], "the install overwrote a stage someone already chose")
        self.assertEqual(observed["settings"]["erp_integration"]["partial_order_sales_stage"],
                         "Someone Elses Stage")

    def test_the_config_is_written_before_any_other_step(self):
        """If a later block throws, the key must already be there - otherwise the
        provider is neutered and nothing writes the stage."""
        observed = self.execute()
        names = [e[0] if isinstance(e, list) else e for e in observed["events"]]
        self.assertLess(names.index("write_config"), names.index("uninstall_languages"))

    # ---- 2/3. the fragment removal, and no new declaration ------------------

    def test_removes_the_accumulated_fragment(self):
        observed = self.execute()
        calls = [e for e in observed["events"]
                 if isinstance(e, list) and e[0] == "uninstall_languages"]
        self.assertEqual(len(calls), 1, "the accumulated zz fragment is not removed exactly once")
        self.assertEqual(calls[0][1], "zz_bd_stage_doms",
                         "a different id_name removes a different file, i.e. nothing")

    def test_declares_no_language_fragment(self):
        observed = self.execute()
        installs = [e for e in observed["events"]
                    if isinstance(e, list) and e[0] == "install_languages"]
        self.assertEqual(installs, [], "this package is declaring stage keys again; PF owns them")

    # ---- 4. the rebuild, and the read it logs -------------------------------

    def test_rebuilds_and_refreshes_the_languages(self):
        observed = self.execute()
        names = [e[0] if isinstance(e, list) else e for e in observed["events"]]
        self.assertIn("rebuild_languages", names)
        self.assertIn("refresh_languages", names)
        self.assertLess(names.index("uninstall_languages"), names.index("refresh_languages"))

    def test_reports_whether_core_serves_the_stages(self):
        served = self.execute()
        self.assertIn("BenchDogs-Ext: release stages served by core after this install: yes",
                      served["errors"])
        missing = self.execute("stages_missing")
        self.assertIn(
            "BenchDogs-Ext: release stages served by core after this install: "
            "NO - install Partial Fulfillment >= 1.0.40", missing["errors"])
        self.assertIsNone(missing["failure"], "a missing core stage must never fail the install")

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
    def test_manual_repair_endpoint_does_not_repair_revenue_line_items(self):
        endpoint = PACKAGE / "custom/clients/base/api/BdBenchDogsActionsApi.php"
        source = endpoint.read_text()
        repair_body = source.split("public function repairUi", 1)[1]
        self.assertNotRegex(repair_body, r"['\"]RevenueLineItems['\"]")

    def test_the_repair_endpoint_installs_no_stage_vocabulary(self):
        endpoint = PACKAGE / "custom/clients/base/api/BdBenchDogsActionsApi.php"
        body = endpoint.read_text().split("public function repairUi", 1)[1]
        code = "\n".join(line.split("//")[0] for line in body.splitlines())
        self.assertNotIn("install_languages", code,
                         "the admin repair route is re-declaring stage keys core owns")


if __name__ == "__main__":
    unittest.main()
