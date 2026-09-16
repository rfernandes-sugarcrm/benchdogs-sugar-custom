"""Execute the real installer against an isolated Sugar API lifecycle harness."""

import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"

HARNESS = r'''<?php
$scenario = SCENARIO;
$events = [];
$errors = [];
$compiled = [];
$refreshed = false;
$current_language = 'en_us';
$sugar_config = ['default_language' => 'en_us'];
if ($scenario === 'languages' || $scenario === 'missing_current_language') {
    $current_language = 'fr_FR';
    $sugar_config['default_language'] = 'de_DE';
}
$app_list_strings = ['sales_stage_dom' => ['Customer Stage' => 'Keep me']];
class TestLog {
    public function error($s) { $GLOBALS['errors'][] = $s; }
    public function fatal($s) { $GLOBALS['errors'][] = $s; }
}
$GLOBALS['log'] = new TestLog();
class BdDemoDashboards { public function install() {} }
class SugarAutoLoader { public static function load($p) {} }
class BeanFactory { public static function getBean() { throw new Exception('No bean writes allowed'); } }
class RepairAndClear {
    public $show_output; public $module_list;
    public function clearVardefs() {}
    public function rebuildExtensions($modules) {
        if (in_array('RevenueLineItems', $modules, true)) {
            $GLOBALS['events'][] = 'RLI_REPAIR';
        }
        $GLOBALS['events'][] = 'rebuild_extensions';
    }
}
class MetaDataManager {
    public static function refreshModulesCache($modules) {
        if (in_array('RevenueLineItems', $modules, true)) {
            $GLOBALS['events'][] = 'RLI_REPAIR';
        }
    }
    public static function refreshLanguagesCache($languages) {
        $GLOBALS['events'][] = ['refresh_languages', $languages];
        if ($GLOBALS['scenario'] === 'refresh_exception') throw new Exception('PRIVATE DETAILS');
        $GLOBALS['refreshed'] = true;
    }
}
class VardefManager { public static function clearVardef($module, $bean) {} }
function return_app_list_strings_language($language, $useCache = true) {
    $GLOBALS['events'][] = ['verify', $language, $useCache];
    if (!$GLOBALS['refreshed'] || $useCache) return [];
    $doms = $GLOBALS['compiled'];
    if ($GLOBALS['scenario'] === 'missing_quote') unset($doms['quote_stage_dom']['Partially Fulfilled']);
    if ($GLOBALS['scenario'] === 'missing_sales') unset($doms['sales_stage_dom']['Prototype Ordered']);
    if ($GLOBALS['scenario'] === 'missing_production') unset($doms['sales_stage_dom']['Partial Production Ordered']);
    if ($GLOBALS['scenario'] === 'missing_probability') unset($doms['sales_probability_dom']['Partial Production Ordered']);
    if ($GLOBALS['scenario'] === 'missing_prototype_probability') unset($doms['sales_probability_dom']['Prototype Ordered']);
    if ($GLOBALS['scenario'] === 'wrong_probability') $doms['sales_probability_dom']['Prototype Ordered'] = 5;
    if ($GLOBALS['scenario'] === 'missing_current_language' && $language === 'fr_FR') return [];
    return $doms;
}
// The installer script is TOP-LEVEL CODE (0.9.42-rc26): requiring it IS running
// it, exactly as ModuleInstaller::post_execute() does. Nothing calls a function
// named post_execute, here or on a tenant - that was the defect. `require`
// rather than `require_once` so the repeat scenario models a second install.
$failure = null;
try {
    require 'scripts/post_install.php';
    if ($scenario === 'repeat') require 'scripts/post_install.php';
} catch (Throwable $e) { $failure = $e->getMessage(); }
echo json_encode(['failure' => $failure, 'events' => $events, 'errors' => $errors, 'compiled' => $compiled]);
'''

INSTALLER_STUB = r'''<?php
class ModuleInstaller {
    public $silent; public $id_name; public $base_dir; public $installdefs;
    public function install_languages() { $GLOBALS['events'][] = 'install_languages'; }
    public function rebuild_tabledictionary() { $GLOBALS['events'][] = 'rebuild_tabledictionary'; }
    public function rebuild_languages($languages = [], $modules = []) {
        $GLOBALS['events'][] = ['rebuild_languages', $languages, $modules];
        if ($GLOBALS['scenario'] === 'rebuild_exception') throw new Exception('PRIVATE DETAILS');
        $app_list_strings = $GLOBALS['app_list_strings'];
        require 'custom/Extension/application/Ext/Language/en_us.bd_stage_doms.php';
        $GLOBALS['compiled'] = $app_list_strings;
    }
}
'''


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class PostInstallStageLanguagesTest(unittest.TestCase):
    def execute(self, scenario="success"):
        with tempfile.TemporaryDirectory(prefix="bench-stage-installer-") as tmp:
            target = Path(tmp)
            for relative in (
                "scripts/post_install.php",
                "custom/dropdowntemplates/bd_stage_doms.append.php",
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

    def test_installer_compiles_refreshes_and_verifies_without_manual_endpoint(self):
        observed = self.execute()
        self.assertIsNone(observed["failure"])
        events = observed["events"]
        rebuild = ["rebuild_languages", {"en_us": "en_us"}, []]
        refresh = ["refresh_languages", ["en_us"]]
        verify = ["verify", "en_us", False]
        self.assertLess(events.index("install_languages"), events.index(rebuild))
        self.assertLess(events.index(rebuild), events.index(refresh))
        self.assertLess(events.index(refresh), events.index(verify))
        self.assertEqual(observed["compiled"]["sales_stage_dom"]["Customer Stage"], "Keep me")

    def test_repeated_upgrade_is_append_only_and_verifies_each_run(self):
        observed = self.execute("repeat")
        self.assertIsNone(observed["failure"])
        self.assertEqual(observed["events"].count(["verify", "en_us", False]), 2)
        self.assertEqual(observed["compiled"]["sales_stage_dom"], {
            "Customer Stage": "Keep me", "Prototype Ordered": "Prototype Ordered",
            "Partial Production Ordered": "Partial Production Ordered",
        })

    def test_missing_or_wrong_required_domains_are_reported_without_failing_the_install(self):
        # Reported, never thrown. On the post_execute path an uncaught throw is a
        # failed install AND a force-uninstall, so a missing stage domain used to
        # be punished by deleting the package and its deployed metadata.
        for scenario in ("missing_quote", "missing_sales", "missing_production",
                         "missing_probability", "missing_prototype_probability",
                         "wrong_probability", "missing_current_language"):
            with self.subTest(scenario=scenario):
                observed = self.execute(scenario)
                self.assertIsNone(observed["failure"])
                self.assertIn("BenchDogs-Ext: required stage language verification failed",
                              observed["errors"])
                self.assertIn("BenchDogs-Ext: post_install finished", observed["errors"])

    def test_rebuild_or_refresh_exception_is_neutral_and_does_not_fail_the_install(self):
        for scenario in ("rebuild_exception", "refresh_exception"):
            with self.subTest(scenario=scenario):
                observed = self.execute(scenario)
                self.assertIsNone(observed["failure"])
                self.assertIn("BenchDogs-Ext: required stage language verification failed",
                              observed["errors"])
                self.assertNotIn("PRIVATE DETAILS", json.dumps(observed))

    def test_the_installer_body_is_top_level_code_that_cannot_kill_an_install(self):
        # The rc26 fix, asserted statically. Until rc25 the whole body sat inside
        # `if (function_exists('post_execute') === false) { function post_execute() {...} }`
        # and ModuleInstaller::post_execute() only require_once's the file - it
        # never calls a function named for the installdef key - so none of it had
        # ever run on a tenant. Re-wrapping it would be silent and invisible
        # again, and a re-introduced throw would force-uninstall the package.
        source = (PACKAGE / "scripts/post_install.php").read_text()
        code = "\n".join(
            line for line in source.splitlines()
            if not line.lstrip().startswith(("*", "/*", "//", "*/"))
        )
        self.assertNotIn("function post_execute", code)
        self.assertNotIn("function_exists('post_execute')", code)
        self.assertNotRegex(code, r"\bthrow\b")
        self.assertIn("BenchDogs-Ext: post_install running", code)

    def test_proof_of_life_is_logged_at_fatal_so_it_survives_the_log_level(self):
        observed = self.execute()
        self.assertEqual(observed["errors"][0],
                         "BenchDogs-Ext: post_install running - writing deployed metadata")
        self.assertEqual(observed["errors"][-1], "BenchDogs-Ext: post_install finished")

    def test_installer_does_not_repair_revenue_line_items(self):
        self.assertNotIn("RLI_REPAIR", self.execute()["events"])
        self.assertNotIn("RevenueLineItems", (PACKAGE / "scripts/post_install.php").read_text())

    def test_current_and_default_languages_are_refreshed_and_verified(self):
        observed = self.execute("languages")
        self.assertIsNone(observed["failure"])
        self.assertIn(["rebuild_languages", {"en_us": "en_us", "de_DE": "de_DE", "fr_FR": "fr_FR"}, []], observed["events"])
        self.assertIn(["refresh_languages", ["en_us", "de_DE", "fr_FR"]], observed["events"])
        for language in ("en_us", "de_DE", "fr_FR"):
            self.assertIn(["verify", language, False], observed["events"])

    def test_built_package_contains_verified_install_lifecycle(self):
        version = (PACKAGE / "version").read_text().strip()
        with zipfile.ZipFile(PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip") as archive:
            path = "scripts/post_install.php"
            self.assertEqual(archive.read(path), (PACKAGE / path).read_bytes())
            self.assertIn("<basepath>/scripts/post_install.php", archive.read("manifest.php").decode())


class OpportunitiesOnlyRepairContractTest(unittest.TestCase):
    def test_manual_repair_endpoint_does_not_repair_revenue_line_items(self):
        endpoint = PACKAGE / "custom/clients/base/api/BdBenchDogsActionsApi.php"
        source = endpoint.read_text()
        repair_body = source.split("public function repairUi", 1)[1].split(
            "private function erpPartNumFromTemplate", 1
        )[0]
        self.assertNotRegex(repair_body, r"['\"]RevenueLineItems['\"]")


if __name__ == "__main__":
    unittest.main()
