#!/usr/bin/env python3
"""A post_install step that fails must be REPORTED - and must still not throw.

G294, 0.9.42-rc67.

THE DEFECT. Through rc65 every block in scripts/post_install.php caught its own
Throwable and wrote one ->fatal() line, with no aggregation, no marker and no
channel to `installation-status`. `BenchDogs-Ext: QLI columns failed: Call to
private method BaseErpLayout::loadView() from scope BdQliColumnsLayout` was in
the log on EVERY install from 2026-09-17 onward while Module Loader reported
19/19, the scanner verdict was clean and the rendered row said Installed.

WHY THE OBVIOUS FIX IS FORBIDDEN. An uncaught throw in post_execute makes
PackageManager::installPackage() call forceUninstall(), i.e. it DELETES the
package from the tenant rather than leaving the previous version. So the fix
reports; it never raises. test_the_body_still_cannot_throw below is the guard.

🚩 HOW THIS SUITE AVOIDS BEING THE DEFECT IT TESTS. The reason nobody noticed
the rc65 failure for five weeks is that scripts/tests/test_qli_column_merge.py
stubbed `BaseErpLayout::loadView` as PROTECTED when it had been private since
the day it was introduced - the fixture asserted the very thing that was false
and so could never fail. Two deliberate choices here, because this suite also
runs on stubs:

  1. SugarPlatformContractTest reads the REAL SugarEnt-Full 25.2.0 and 26.1.0
     trees and asserts every platform fact the fix depends on - that
     setInstallationError is public and typed, that post_execute require_once's
     inside its own method body (which is the only reason `$this` is bound
     here), that MlpLogger repoints the log, that installPackage force-uninstalls
     on Throwable. If any of those is a belief rather than a fact, that class
     goes red rather than this file quietly encoding it.
  2. The primary fault injection is not a synthetic exception. Scenario
     `quotes_layout_private` declares the helper's method `private` and lets
     PHP raise its own `Error: Call to private method ...` - the exact shape
     that actually happened on Bench - so the test exercises the real failure
     mode rather than one chosen to be convenient.

MUTATION-VERIFIED (each applied to scripts/post_install.php, suite re-run,
listed failure observed - see the lane report for the recorded output):
  delete the `$bdStepReport[...] = 'FAILED: ...'` line from the Quotes catch
      -> test_a_thrown_step_is_named_in_every_channel fails (report says 8/8)
      -> test_every_step_catch_records_an_outcome fails (structural count)
  delete the whole CHANNEL 2 config-row block
      -> test_a_clean_install_produces_a_clean_report fails
  delete the CHANNEL 3 installation-status block
      -> test_a_thrown_step_is_named_in_every_channel fails
  delete the WHOLE isset/instanceof/method_exists guard
      -> test_a_top_level_include_degrades_instead_of_throwing fails (channel 3
         is attempted with no $this) AND
         test_installation_status_is_reached_only_behind_all_three_guards fails
  keep isset($this) ALONE, drop instanceof and method_exists
      -> ONLY the structural test fails. Recorded exactly as observed, because
         this is the interesting result: PHP evaluates `$this instanceof X` as
         false at global scope without raising, so `instanceof` is what
         actually protects the path and isset() is belt-and-braces. Do not
         read the structural test as proof that isset() is load-bearing.
  make the Quotes catch rethrow instead of recording
      -> test_the_body_still_cannot_throw fails
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
PACKAGE = Path(os.environ.get("BD_PKG", ROOT / "sugar-sell/BenchDogs-Ext"))
POST_INSTALL = PACKAGE / "scripts/post_install.php"

SUGAR_TREES = {
    "25.2.0": Path.home() / "Documents/Code/SugarEnt-Full-25.2.0",
    "26.1.0": Path.home() / "Documents/Code/SugarEnt-Full-26.1.0",
}

# ---------------------------------------------------------------------------
# The installer stub. This is the shape that matters: ModuleInstaller::
# post_execute() require_once's the package file from INSIDE its own method
# body (SugarEnt 25.2.0 / 26.1.0 ModuleInstall/ModuleInstaller.php:426), which
# is the only reason $this is bound in post_install.php at all, and it runs
# after extract($data) so $manifest is in scope. Reproducing both is the point.
# ---------------------------------------------------------------------------
INSTALLER_STUB = r'''<?php
class ModuleInstaller {
    public $silent; public $id_name; public $base_dir; public $installdefs;

    public function post_execute() {
        // extract($data) in the real method puts these in scope.
        $manifest = $GLOBALS['fake_manifest'];
        $installdefs = $GLOBALS['fake_installdefs'];
        require_once 'scripts/post_install.php';
    }

    // Public and string-typed in BOTH 25.2.0 and 26.1.0; asserted for real by
    // SugarPlatformContractTest rather than assumed here.
    public function setInstallationError(string $error): void {
        if ($GLOBALS['scenario'] === 'status_write_throws') {
            throw new RuntimeException('process_status write refused');
        }
        $GLOBALS['events'][] = ['installation_error', $error];
    }

    public function install_languages() { $GLOBALS['events'][] = ['install_languages', $this->id_name]; }
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

HARNESS = r'''<?php
$scenario = SCENARIO;
$GLOBALS['scenario'] = $scenario;
$events = [];
$errors = [];
$settings = ['erp_integration' => []];
class TestLog {
    public function error($s) { $GLOBALS['errors'][] = $s; }
    public function fatal($s) { $GLOBALS['errors'][] = $s; }
    public function warn($s) { $GLOBALS['errors'][] = $s; }
}
$GLOBALS['log'] = new TestLog();
class TestAdmin {
    public function getConfigForModule($category) { return $GLOBALS['settings'][$category] ?? []; }
    public function saveSetting($category, $key, $value) {
        if ($GLOBALS['scenario'] === 'config_write_throws' && $category === 'benchdogs_ext') {
            throw new RuntimeException('config table is read only');
        }
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
    public function rebuildExtensions($modules) {}
}
class MetaDataManager {
    public static function refreshModulesCache($modules) {}
    public static function refreshLanguagesCache($languages) {}
}
class VardefManager { public static function clearVardef($module, $bean = null) {} }
function return_app_list_strings_language($language, $useCache = true) {
    return ['sales_stage_dom' => [
        'Prototype Ordered' => 'Prototype Ordered',
        'Partial Production Ordered' => 'Partial Production Ordered',
    ]];
}
$GLOBALS['fake_manifest'] = ['version' => '0.9.42-rcTEST'];
$GLOBALS['fake_installdefs'] = ['post_execute' => ['<basepath>/scripts/post_install.php']];

$failure = null;
try {
    if (strpos($scenario, 'no_object_scope') === 0) {
        // The other include shape: top level, no $this. Channel 3 must be
        // skipped, not fatal.
        require 'scripts/post_install.php';
    } else {
        require_once 'ModuleInstall/ModuleInstaller.php';
        $mi = new ModuleInstaller();
        $mi->post_execute();
    }
} catch (Throwable $e) { $failure = get_class($e) . ': ' . $e->getMessage(); }
echo json_encode(['failure' => $failure, 'events' => $events, 'errors' => $errors,
                  'settings' => $GLOBALS['settings']]);
'''

# The three helper classes post_install.php reaches for. Each scenario may
# replace one of them; absent means the file is not written at all.
HELPERS = {
    "custom/modules/Quotes/BdQuotesLayoutExtensions.php":
        "<?php class BdQuotesLayoutExtensions { public static function write($r = false) {} }",
    "custom/modules/Accounts/BdAccountsLayoutExtensions.php":
        "<?php class BdAccountsLayoutExtensions { public static function writeCustomerGroupField() {} }",
    "custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php":
        "<?php class BdOpportunitiesLayoutExtensions { public static function remove() {} }",
}

QUOTES_HELPER = "custom/modules/Quotes/BdQuotesLayoutExtensions.php"

# 🚩 NOT a synthetic exception. `private` makes PHP itself raise
# "Error: Call to private method BdQuotesLayoutExtensions::write() from global
# scope" - the same shape as rc65's real
# "Call to private method BaseErpLayout::loadView() from scope BdQliColumnsLayout".
HELPER_OVERRIDES = {
    "quotes_layout_private":
        "<?php class BdQuotesLayoutExtensions { private static function write($r = false) {} }",
    "quotes_layout_throws":
        "<?php class BdQuotesLayoutExtensions { public static function write($r = false) {"
        " throw new RuntimeException('DeployedMetaDataImplementation refused'); } }",
    "quotes_layout_not_loaded":
        "<?php // a file that ships but declares nothing, the class_exists off switch",
    # Top level AND a failing step. Without the failure the channel-3 block is
    # never entered at all, so a scenario that only drops $this proves nothing
    # about the guard - which is exactly what the first draft of this suite did.
    "no_object_scope_private":
        "<?php class BdQuotesLayoutExtensions { private static function write($r = false) {} }",
}
HELPER_ABSENT = {"quotes_layout_missing"}

STEP_COUNT = 8


@unittest.skipUnless(shutil.which("php"), "requires a PHP CLI")
class StepReportTest(unittest.TestCase):
    """Runs the REAL scripts/post_install.php the way post_execute() runs it."""

    def execute(self, scenario="clean"):
        with tempfile.TemporaryDirectory(prefix="bench-step-report-") as tmp:
            target = Path(tmp)
            for relative in (
                "scripts/post_install.php",
                "custom/Extension/application/Ext/Language/en_us.bd_stage_doms.php",
            ):
                path = target / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(PACKAGE / relative, path)
            for relative, body in HELPERS.items():
                if relative == QUOTES_HELPER and scenario in HELPER_ABSENT:
                    continue
                path = target / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                if relative == QUOTES_HELPER and scenario in HELPER_OVERRIDES:
                    path.write_text(HELPER_OVERRIDES[scenario])
                else:
                    path.write_text(body)
            (target / "ModuleInstall").mkdir()
            (target / "ModuleInstall/ModuleInstaller.php").write_text(INSTALLER_STUB)
            code = HARNESS.replace("SCENARIO", json.dumps(scenario))
            result = subprocess.run(["php"], input=code, text=True, cwd=target,
                                    capture_output=True, check=True)
            return json.loads(result.stdout)

    # -- helpers on the observation ---------------------------------------

    @staticmethod
    def summary_line(observed):
        lines = [e for e in observed["errors"]
                 if isinstance(e, str) and "post_install step report" in e]
        return lines[0] if len(lines) == 1 else None

    @staticmethod
    def report_row(observed):
        rows = [e for e in observed["events"]
                if isinstance(e, list) and e[0] == "write_config"
                and e[1] == "benchdogs_ext" and e[2] == "install_report"]
        return json.loads(rows[0][3]) if len(rows) == 1 else None

    @staticmethod
    def installation_error(observed):
        rows = [e for e in observed["events"]
                if isinstance(e, list) and e[0] == "installation_error"]
        return rows[0][1] if len(rows) == 1 else None

    # -- direction 1: a clean install produces a clean report --------------

    def test_a_clean_install_produces_a_clean_report(self):
        observed = self.execute("clean")
        self.assertIsNone(observed["failure"])

        summary = self.summary_line(observed)
        self.assertIsNotNone(summary, "no aggregated verdict line was logged")
        self.assertIn(f"{STEP_COUNT}/{STEP_COUNT} applied", summary)
        self.assertNotIn("NOT APPLIED", summary)
        self.assertIn("0.9.42-rcTEST", summary,
                      "the verdict does not say which version it is about")

        row = self.report_row(observed)
        self.assertIsNotNone(row, "the durable config row was not written")
        self.assertEqual(row["applied"], STEP_COUNT)
        self.assertEqual(row["total"], STEP_COUNT)
        self.assertEqual(sorted(set(row["steps"].values())), ["ok"])
        self.assertEqual(row["version"], "0.9.42-rcTEST")
        self.assertIsInstance(row["pid"], int)
        self.assertRegex(row["utc"], r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")

    def test_a_clean_install_writes_nothing_to_installation_status(self):
        """Deliberate asymmetry, and it is stated in the file's own header:
        `installation-status` carries a failure or nothing at all. Silence
        there is NOT the proof of a clean install - silence is exactly what the
        defect looked like. The config row above is the positive proof."""
        self.assertIsNone(self.installation_error(self.execute("clean")))

    # -- direction 2: a broken step is named, in every channel -------------

    def test_a_thrown_step_is_named_in_every_channel(self):
        """The rc65 shape, reproduced: PHP's own 'Call to private method'."""
        observed = self.execute("quotes_layout_private")
        self.assertIsNone(observed["failure"],
                          "a failing step must not escape post_install")

        summary = self.summary_line(observed)
        self.assertIn(f"{STEP_COUNT - 1}/{STEP_COUNT} applied", summary)
        self.assertIn("NOT APPLIED", summary)
        self.assertIn("quotes_layout_extensions", summary)
        self.assertIn("Call to private method", summary)

        row = self.report_row(observed)
        self.assertEqual(row["applied"], STEP_COUNT - 1)
        self.assertTrue(row["steps"]["quotes_layout_extensions"].startswith("FAILED: Error:"),
                        row["steps"]["quotes_layout_extensions"])
        self.assertIn("Call to private method", row["steps"]["quotes_layout_extensions"])

        status = self.installation_error(observed)
        self.assertIsNotNone(status, "installation-status still reads clean over a failed step")
        self.assertIn("quotes_layout_extensions", status)
        self.assertIn("IS INSTALLED", status,
                      "the operator must not read this as 'uninstall me'")

        # Every other step still applied: this is a reporting fix, not a
        # wholesale abort. Same control the gap names.
        self.assertEqual(
            [name for name, outcome in row["steps"].items() if outcome != "ok"],
            ["quotes_layout_extensions"])

    def test_an_ordinary_exception_is_reported_the_same_way(self):
        row = self.report_row(self.execute("quotes_layout_throws"))
        self.assertEqual(row["steps"]["quotes_layout_extensions"],
                         "FAILED: RuntimeException: DeployedMetaDataImplementation refused")

    def test_a_helper_that_never_landed_is_reported(self):
        """install_copy runs before post_execute, so a missing helper means the
        package's own file did not arrive - the step never ran at all. Until
        rc66 that logged one line and counted as success."""
        observed = self.execute("quotes_layout_missing")
        row = self.report_row(observed)
        self.assertEqual(row["steps"]["quotes_layout_extensions"],
                         "MISSING: " + QUOTES_HELPER)
        self.assertIn("quotes_layout_extensions", self.installation_error(observed))

    def test_a_class_that_does_not_define_itself_is_reported(self):
        """The class_exists-without-require off switch, pointed the other way:
        the file ships and loads, the class is still undefined, the guard has no
        else and the step silently does not happen."""
        row = self.report_row(self.execute("quotes_layout_not_loaded"))
        self.assertEqual(row["steps"]["quotes_layout_extensions"],
                         "NOT-LOADED: class BdQuotesLayoutExtensions")

    # -- the reporter itself must not be able to kill an install -----------

    def test_a_broken_config_channel_does_not_lose_the_other_two(self):
        observed = self.execute("config_write_throws")
        self.assertIsNone(observed["failure"])
        self.assertIsNotNone(self.summary_line(observed))
        self.assertIn("BenchDogs-Ext: post_install finished", observed["errors"])

    def test_a_broken_status_channel_does_not_lose_the_other_two(self):
        observed = self.execute("status_write_throws")
        self.assertIsNone(observed["failure"])
        self.assertIsNotNone(self.report_row(observed))
        self.assertIn("BenchDogs-Ext: post_install finished", observed["errors"])

    def test_a_top_level_include_degrades_instead_of_throwing(self):
        """No $this, AND a step that failed - so channel 3 is actually entered.

        🛑 THE FIRST DRAFT OF THIS TEST WAS INERT and it is recorded here rather
        than quietly repaired. It ran the clean scenario at top level, where
        $bdNotApplied is empty and the channel-3 block is skipped before any
        guard is evaluated. Mutation M6 (delete the whole guard) left it GREEN.
        A scenario that cannot reach the code it claims to cover is the same
        failure as a fixture that stubs its own belief. It now forces a failure
        first, so the guard is on the path.

        Channel 3 must be SKIPPED here, not attempted-and-caught: the outer
        try/catch would swallow the Error either way, but a guard that only
        works because an Error is caught is one refactor away from an uncaught
        throw, and an uncaught throw in post_execute force-uninstalls the
        package. The absence of the channel-3 failure line is what separates
        the two."""
        observed = self.execute("no_object_scope_private")
        self.assertIsNone(observed["failure"])

        row = self.report_row(observed)
        self.assertIsNotNone(row, "the durable channel must still work with no $this")
        self.assertIn("Call to private method", row["steps"]["quotes_layout_extensions"])
        self.assertIn("NOT APPLIED", self.summary_line(observed))

        self.assertIsNone(self.installation_error(observed))
        self.assertNotIn(
            "BenchDogs-Ext: step report to installation-status failed: "
            "Using $this when not in object context",
            observed["errors"],
            "channel 3 was ATTEMPTED without $this and only survived because the "
            "catch caught it; the guard is what is supposed to skip it")
        self.assertIn("BenchDogs-Ext: post_install finished", observed["errors"])

    def test_the_per_step_lines_are_still_there(self):
        """The aggregated line is added to the per-step ones, not instead of
        them: the verbatim message is what identifies a novel failure."""
        observed = self.execute("quotes_layout_throws")
        self.assertIn("BenchDogs-Ext: layout extensions failed: "
                      "DeployedMetaDataImplementation refused", observed["errors"])


class StepReportStructureTest(unittest.TestCase):
    """Static guards. These read the shipped file, not a stub."""

    def source(self):
        return POST_INSTALL.read_text()

    def step_region(self):
        """Everything above the report block: the eight steps."""
        source = self.source()
        marker = "THE STEP REPORT"
        self.assertIn(marker, source, "the report block is gone")
        return source.split(marker, 1)[0]

    def test_every_step_catch_records_an_outcome(self):
        """The defect was eight catches that only logged. Counting them against
        the recorded outcomes is what notices a NEW step added with a catch and
        no report entry - which is how this would come back."""
        region = self.step_region()
        catches = len(re.findall(r"\}\s*catch\s*\(Throwable\s+\$e\)\s*\{", region))
        recorded = len(re.findall(r"\$bdStepReport\[[^\]]+\]\s*=\s*'FAILED: '", region))
        self.assertEqual(catches, STEP_COUNT,
                         "the step count changed; update STEP_COUNT deliberately")
        self.assertEqual(catches, recorded,
                         "a step catches its own Throwable and reports nothing")

    def test_every_step_records_success_too(self):
        region = self.step_region()
        self.assertEqual(
            len(re.findall(r"\$bdStepReport\[[^\]]+\]\s*=\s*'ok'", region)), STEP_COUNT)

    def test_the_body_still_cannot_throw(self):
        """Unchanged from the rc26 guard and restated here because this change
        is the one most likely to break it: an uncaught throw in post_execute
        makes Module Loader force-uninstall the package."""
        raw = self.source()
        source = re.sub(r"/\*.*?\*/", "", raw, flags=re.S)
        source = re.sub(r"(^|\s)//[^\n]*", r"\1", source)
        self.assertNotIn("throw new", source)
        self.assertNotIn("function post_execute", source)

    def test_installation_status_is_reached_only_behind_all_three_guards(self):
        source = self.source()
        self.assertRegex(
            source,
            r"isset\(\$this\)\s*\n\s*&&\s*\$this instanceof ModuleInstaller\s*\n"
            r"\s*&&\s*method_exists\(\$this, 'setInstallationError'\)",
            "a missing guard here turns a reporting fix into a force-uninstall")

    def test_the_report_does_not_write_into_cores_config_namespace(self):
        """erp_integration is core's category. A Bench diagnostic in it is
        exactly the leak this release spent two rcs removing."""
        source = self.source()
        self.assertIn("saveSetting('benchdogs_ext', 'install_report'", source)

    def test_the_old_wrong_log_instruction_is_gone(self):
        """G295. The rc66 header told operators to grep sugarcrm.log and treat
        an absent line as broken, while the same file cited package_install.log
        as the evidence twice."""
        source = self.source()
        self.assertNotRegex(source, r"grep sugarcrm\.log")
        self.assertIn("package_install.log", source)
        self.assertIn("MlpLogger::replaceDefault()", source)
        self.assertIn("Package Install Log File", source)
        self.assertIn("PID", source)

    def test_the_uninstall_instruction_names_the_same_log(self):
        source = (PACKAGE / "scripts/pre_uninstall.php").read_text()
        self.assertNotRegex(source, r"grep sugarcrm\.log")
        self.assertIn("package_install.log", source)
        self.assertIn("Package Install Log File", source)
        self.assertIn("PID", source)


class SugarPlatformContractTest(unittest.TestCase):
    """🚩 THE ANTI-FIXTURE CLASS. Every platform fact the fix rests on, read out
    of the real Sugar source rather than out of this suite's own stubs. A
    fixture cannot test the belief it encodes; this can."""

    # Reads SugarCRM's own source, which CI can never have (no public copy), so
    # CI does not collect this class rather than collecting and skipping it.
    # See scripts/tests/conftest.py and the skip ceiling in mlp-lint.yml.
    requires_sugarent_tree = True

    def trees(self):
        found = {v: p for v, p in SUGAR_TREES.items() if p.is_dir()}
        if not found:
            self.skipTest("no SugarEnt-Full tree available")
        return found

    def test_set_installation_error_is_public_and_takes_a_string(self):
        for version, root in self.trees().items():
            source = (root / "ModuleInstall/ModuleInstaller.php").read_text()
            with self.subTest(version=version):
                self.assertIn("public function setInstallationError(string $error): void", source)

    def test_post_execute_require_onces_inside_its_own_method_body(self):
        """This is the whole reason $this is bound in post_install.php. If a
        Sugar release ever moves that include out of the method, channel 3
        silently stops working - and the guards make that a degrade, not a
        crash."""
        for version, root in self.trees().items():
            source = (root / "ModuleInstall/ModuleInstaller.php").read_text()
            body = source.split("public function post_execute()", 1)[1]
            body = body.split("public function pre_uninstall()", 1)[0]
            with self.subTest(version=version):
                self.assertIn("require_once", body)
                self.assertIn("installdefs['post_execute']", body)

    def test_an_uncaught_throw_really_does_force_uninstall(self):
        """The reason this fix reports instead of raising."""
        for version, root in self.trees().items():
            source = (root / "src/PackageManager/PackageManager.php").read_text()
            body = source.split("public function installPackage(", 1)[1]
            body = body.split("public function uninstallPackage(", 1)[0]
            with self.subTest(version=version):
                self.assertIn("catch (Throwable $e)", body)
                self.assertIn("$this->forceUninstall($history);", body)

    def test_module_loader_repoints_the_log_to_package_install(self):
        """G295's root fact: this is why the lines are not in sugarcrm.log."""
        for version, root in self.trees().items():
            mlp = (root / "modules/Administration/MlpLogger.php").read_text()
            commit = (root / "modules/Administration/UpgradeWizard_commit.php").read_text()
            with self.subTest(version=version):
                self.assertIn("['logger']['file']['name'] = 'package_install'", mlp)
                self.assertIn("MlpLogger::replaceDefault();", commit)

    def test_the_diagnostic_tool_can_retrieve_all_three_channels(self):
        """The log item copies package_install.log; the table-dump item carries
        `config` (channel 2) and `upgrade_history` (channel 3's process_status).
        One Diagnostic run, all three."""
        for version, root in self.trees().items():
            source = (root / "modules/Administration/DiagnosticRun.php").read_text()
            with self.subTest(version=version):
                self.assertIn("'/package_install.log'", source)
                self.assertIn("'config' => 'config'", source)
                self.assertIn("'upgrade_history' => 'upgrade_history'", source)

    def test_the_config_value_column_is_text_not_a_short_varchar(self):
        """The JSON report goes in it."""
        for version, root in self.trees().items():
            source = (root / "modules/Administration/vardefs.php").read_text()
            block = source.split("'value' => [", 1)[1].split("]", 1)[0]
            with self.subTest(version=version):
                self.assertIn("'type' => 'text'", block)


if __name__ == "__main__":
    unittest.main()
