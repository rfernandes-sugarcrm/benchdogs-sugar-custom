#!/usr/bin/env python3
"""Each lifecycle script runs ONCE per install or uninstall. G294 (2nd grade) and G295.

MEASURED ON rc68, Ophir, 2026-09-23 (GAPS.md G294/G295, graded ~02:1xZ).
Sugar ran scripts/post_install.php TWICE in one install, same PID 2438880:
  pass 1, 01:30:27-01:30:49, as the post_execute installdef, inside
      ModuleInstaller::post_execute(): $this is the installer and $manifest is in
      scope, so it logged "post_install step report (0.9.42-rc68): 8/8 applied"
      and could reach installation-status;
  pass 2, 01:31:54-01:32:18, OUTSIDE the installer: no $manifest, $this not a
      ModuleInstaller. It logged "post_install step report (unknown): 8/8
      applied", OVERWROTE the config row benchdogs_ext.install_report with
      version "unknown", and skipped installation-status.
So a failure in pass 1 only was wiped from the durable row by pass 2's 8/8, and a
failure in pass 2 only never reached installation-status.

WHY. `scripts/post_install.php` is a RESERVED path. PackageZipFile::
PACKAGE_SCRIPT_LIST names four (pre_install, post_install, pre_uninstall,
post_uninstall under scripts/), and runPackageScript() plain-`include`s whichever
of them the extracted package has. PackageManager::installPackage() calls it for
POST_INSTALL_FILE right AFTER ModuleInstaller::install() has already
require_once'd the same file as the post_execute installdef; `include` ignores the
require_once table, so the body runs again. uninstallPackage() does the same with
PRE_UNINSTALL_FILE BEFORE ModuleInstaller::uninstall() runs the pre_uninstall
installdef, so pre_uninstall.php ran twice too. POST_UNINSTALL_FILE runs only for
a `patch` package; this one is `module`. (SugarEnt 25.2.0 and 26.1.0;
PackageZipFile.php is byte-identical in both. PlatformReservedScriptContractTest
below reads it; nothing here assumes it.)

THE FIX (0.9.42-rc69) moves both scripts OFF the reserved paths, the way
ERP-Epicor ships scripts/post_execute.php: the installdef is then the ONLY route,
runPackageScript() finds no file and returns, and each script runs once, inside
the installer, with the version in scope.

HOW THIS SUITE AVOIDS ENCODING ITS OWN BELIEF
  - It runs the REAL built artifact: pack.php builds the zip in a scratch copy,
    the zip is extracted, and both passes are driven off the extracted
    manifest.php and the files actually in it. Pass 1 takes its path from the
    manifest's post_execute installdef, not from a string written here. Pass 2
    is Sugar's runPackageScript(): look for the reserved path, include it if
    present. So the fix can only turn this green by changing what ships.
  - The stub's one belief - that Sugar includes the reserved name after the
    installer - is read out of SugarEnt's own source by
    PlatformReservedScriptContractTest, where a tree exists. CI has none (see
    conftest.py) and leaves that class out BY NAME (mlp-lint.yml).

FAILS ON 0d2cacb (rc68's tree), each for the measured reason:
  test_one_verdict_line_and_it_names_the_version         two verdict lines, one "(unknown)"
  test_the_running_line_is_emitted_once_with_the_version two running lines, no version
  test_the_config_row_keeps_the_installed_version        row rewritten as "unknown"
  test_a_pass_one_failure_survives                       pass 2's 8/8 overwrites the FAILED step
  test_a_failure_anywhere_reaches_installation_status    a pass-2 failure has no channel 3
  test_pre_uninstall_runs_once_per_uninstall             two running lines, helpers called twice
  test_no_lifecycle_script_ships_at_a_path_sugar_also_runs  post_install.php + pre_uninstall.php
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
VERSION = "0.9.42-rcSINGLEPASS"

SUGAR_TREES = {
    "25.2.0": Path.home() / "Documents/Code/SugarEnt-Full-25.2.0",
    "26.1.0": Path.home() / "Documents/Code/SugarEnt-Full-26.1.0",
}

# PackageZipFile::PACKAGE_SCRIPT_LIST and PACKAGE_SCRIPTS_FUNCTION, 25.2.0/26.1.0.
RESERVED = {
    "scripts/pre_install.php": "pre_install",
    "scripts/post_install.php": "post_install",
    "scripts/pre_uninstall.php": "pre_uninstall",
    "scripts/post_uninstall.php": "post_uninstall",
}
# Which of them PackageManager actually runs for a `module` package: all but
# post_uninstall, whose call sits behind `=== PACKAGE_TYPE_PATCH`.
RESERVED_RUN_FOR_A_MODULE = (
    "scripts/pre_install.php",
    "scripts/post_install.php",
    "scripts/pre_uninstall.php",
)


def build_and_extract(scratch: Path) -> Path:
    """pack.php over a scratch copy of the package, then unzip the result."""
    src = scratch / "src"
    shutil.copytree(PACKAGE, src, ignore=shutil.ignore_patterns("releases"))
    subprocess.run(["php", "pack.php", VERSION], cwd=src, check=True,
                   capture_output=True, text=True)
    zips = list((src / "releases").glob("*.zip"))
    assert len(zips) == 1, zips
    pkg = scratch / "pkg"
    with zipfile.ZipFile(zips[0]) as archive:
        archive.extractall(pkg)
    return pkg


# ---------------------------------------------------------------------------
# The platform, reduced to the two routes that matter, and what the package's
# scripts reach for. Everything a script does to the instance lands in $events
# or $log so the test can count it.
# ---------------------------------------------------------------------------
SUGAR_ROOT_INSTALLER = r'''<?php
class ModuleInstaller {
    public $silent; public $id_name; public $base_dir; public $installdefs;

    // ModuleInstaller::readManifest(): includes <base_dir>/manifest.php.
    public function readManifest() {
        include $this->base_dir . '/manifest.php';
        return ['manifest' => $manifest, 'installdefs' => $installdefs];
    }

    // ModuleInstaller::post_execute() / pre_uninstall(): extract($data), then
    // require_once every installdef path from INSIDE the method, so $this is
    // the installer and $manifest is in scope.
    public function post_execute() {
        $data = $this->readManifest();
        extract($data);
        if (isset($installdefs['post_execute']) && is_array($installdefs['post_execute'])) {
            foreach ($installdefs['post_execute'] as $includefile) {
                require_once str_replace('<basepath>', $this->base_dir, $includefile);
            }
        }
    }
    public function pre_uninstall() {
        $data = $this->readManifest();
        extract($data);
        if (isset($installdefs['pre_uninstall']) && is_array($installdefs['pre_uninstall'])) {
            foreach ($installdefs['pre_uninstall'] as $includefile) {
                require_once str_replace('<basepath>', $this->base_dir, $includefile);
            }
        }
    }
    // install()/uninstall() run many tasks; post_execute and pre_uninstall are
    // the ones that execute package code.
    public function install($base_dir) { $this->base_dir = $base_dir; $this->post_execute(); }
    public function uninstall($base_dir) { $this->base_dir = $base_dir; $this->pre_uninstall(); }

    public function setInstallationError(string $error): void {
        $GLOBALS['events'][] = ['installation_error', $error];
    }
    public function uninstall_languages() {}
    public function rebuild_tabledictionary() {}
    public function rebuild_languages($languages = [], $modules = []) {}
}
'''

HARNESS = r'''<?php
$MODE = MODE_JSON;
$PKG = PKG_JSON;
$FAIL_ON_CALL = FAIL_JSON;          // [helper, nth call] or null
$events = [];
$logLines = [];
$settings = ['erp_integration' => [], 'benchdogs_ext' => []];
class TestLog {
    public function __call($level, $args) { $GLOBALS['logLines'][] = (string) $args[0]; }
}
$GLOBALS['log'] = new TestLog();
class TestAdmin {
    public function getConfigForModule($category) { return $GLOBALS['settings'][$category] ?? []; }
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
class SugarRelationshipFactory { public static function deleteCache() {} public static function rebuildCache() {} }
function return_app_list_strings_language($language, $useCache = true) {
    return ['sales_stage_dom' => ['Prototype Ordered' => 1, 'Partial Production Ordered' => 1]];
}
// Every helper a lifecycle script calls records the call, and can be told to
// throw on its Nth call - same process, same PID, exactly as the two passes
// shared PID 2438880 on Ophir.
function bd_helper_called($name) {
    $GLOBALS['calls'][$name] = ($GLOBALS['calls'][$name] ?? 0) + 1;
    $GLOBALS['events'][] = ['helper', $name];
    $fail = $GLOBALS['FAIL_ON_CALL'];
    if ($fail !== null && $fail[0] === $name && $GLOBALS['calls'][$name] === $fail[1]) {
        throw new RuntimeException("$name failed on call {$fail[1]}");
    }
}

// PackageZipFile::runPackageScript(), 25.2.0/26.1.0: only the four reserved
// paths; return if the extracted package lacks the file; otherwise a PLAIN
// include, then call the function named for the script if one now exists.
class PackageZipFile {
    const PACKAGE_SCRIPTS_FUNCTION = RESERVED_JSON;
    public $packageDir;
    public function runPackageScript(string $script): void {
        if (!isset(self::PACKAGE_SCRIPTS_FUNCTION[$script])) { return; }
        $scriptFile = $this->packageDir . DIRECTORY_SEPARATOR . $script;
        if (!file_exists($scriptFile)) { return; }
        include $scriptFile;
        $funcName = self::PACKAGE_SCRIPTS_FUNCTION[$script];
        if (function_exists($funcName)) { $funcName(); }
    }
}

require_once 'ModuleInstall/ModuleInstaller.php';
$zip = new PackageZipFile();
$zip->packageDir = $PKG;
$mi = new ModuleInstaller();
$manifest_type = (function ($dir) { include $dir . '/manifest.php'; return $manifest['type']; })($PKG);
$failure = null;
try {
    if ($MODE === 'install') {
        // PackageManager::installPackage(), 25.2.0/26.1.0 :729-752.
        $zip->runPackageScript('scripts/pre_install.php');
        $mi->install($PKG);
        $zip->runPackageScript('scripts/post_install.php');
    } else {
        // PackageManager::uninstallPackage(), 25.2.0/26.1.0 :824-836.
        $zip->runPackageScript('scripts/pre_uninstall.php');
        $mi->uninstall($PKG);
        if ($manifest_type === 'patch') {
            $zip->runPackageScript('scripts/post_uninstall.php');
        }
    }
} catch (Throwable $e) { $failure = get_class($e) . ': ' . $e->getMessage(); }
echo json_encode(['failure' => $failure, 'events' => $events, 'log' => $logLines,
                  'settings' => $GLOBALS['settings'], 'calls' => $GLOBALS['calls'] ?? []]);
'''

# Stand-ins for the helpers under custom/ that the scripts require from the
# instance root. Each method named by a script records its call.
HELPER_FILES = {
    "custom/modules/Quotes/BdQuotesLayoutExtensions.php": "BdQuotesLayoutExtensions",
    "custom/modules/Accounts/BdAccountsLayoutExtensions.php": "BdAccountsLayoutExtensions",
    "custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php": "BdOpportunitiesLayoutExtensions",
    # G380/G381: the Quotes ADM field placement (place on install, remove on uninstall).
    "custom/modules/Quotes/BdAdmQuoteFieldsLayout.php": "BdAdmQuoteFieldsLayout",
}
HELPER_METHODS = ("write", "writeCustomerGroupField", "remove", "place")


def helper_source(cls: str) -> str:
    methods = "".join(
        f" public static function {m}($r = false) {{ bd_helper_called('{cls}::{m}'); }}"
        for m in HELPER_METHODS)
    return f"<?php class {cls} {{{methods} }}"


@unittest.skipUnless(shutil.which("php"), "requires a PHP CLI")
class SinglePassTest(unittest.TestCase):
    """The whole install / uninstall sequence, over the real built artifact."""

    @classmethod
    def setUpClass(cls):
        cls._scratch = tempfile.TemporaryDirectory(prefix="bd-single-pass-")
        cls.pkg = build_and_extract(Path(cls._scratch.name))
        with zipfile.ZipFile(next(Path(cls._scratch.name, "src/releases").glob("*.zip"))) as z:
            cls.shipped = set(z.namelist())

    @classmethod
    def tearDownClass(cls):
        cls._scratch.cleanup()

    def run_lifecycle(self, mode: str, fail_on_call=None) -> dict:
        with tempfile.TemporaryDirectory(prefix="bd-sugar-root-") as tmp:
            root = Path(tmp)
            (root / "ModuleInstall").mkdir()
            (root / "ModuleInstall/ModuleInstaller.php").write_text(SUGAR_ROOT_INSTALLER)
            for relative, cls in HELPER_FILES.items():
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(helper_source(cls))
            code = (HARNESS
                    .replace("MODE_JSON", json.dumps(mode))
                    .replace("PKG_JSON", json.dumps(str(self.pkg)))
                    .replace("FAIL_JSON", "null" if fail_on_call is None
                             else f"[{json.dumps(fail_on_call[0])}, {int(fail_on_call[1])}]")
                    .replace("RESERVED_JSON", "[" + ", ".join(
                        f"{json.dumps(k)} => {json.dumps(v)}" for k, v in RESERVED.items()) + "]"))
            result = subprocess.run(["php"], input=code, text=True, cwd=root,
                                    capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
            observed = json.loads(result.stdout)
            self.assertIsNone(observed["failure"], "a lifecycle script threw out of its own catch")
            return observed

    # -- observations ------------------------------------------------------

    @staticmethod
    def lines(observed, needle):
        return [line for line in observed["log"] if needle in line]

    @staticmethod
    def report_writes(observed):
        return [json.loads(e[3]) for e in observed["events"]
                if isinstance(e, list) and e[0] == "write_config"
                and e[1] == "benchdogs_ext" and e[2] == "install_report"]

    def final_row(self, observed):
        raw = observed["settings"].get("benchdogs_ext", {}).get("install_report")
        self.assertIsNotNone(raw, "the durable config row was never written")
        return json.loads(raw)

    @staticmethod
    def installation_errors(observed):
        return [e[1] for e in observed["events"]
                if isinstance(e, list) and e[0] == "installation_error"]

    # -- install -------------------------------------------------------------

    def test_one_verdict_line_and_it_names_the_version(self):
        """Grade item 1: ONE report line, carrying the installed version.
        rc68 logged two, the second "(unknown)"."""
        verdicts = self.lines(self.run_lifecycle("install"), "post_install step report (")
        self.assertEqual(len(verdicts), 1, verdicts)
        self.assertIn(f"post_install step report ({VERSION}):", verdicts[0])

    def test_the_running_line_is_emitted_once_with_the_version(self):
        """G295 (a) and (b). The header tells an operator to take the PID off
        the `post_install running` line "for the version you installed" and says
        it is emitted exactly once; on rc68 it carried no version and was
        emitted twice."""
        running = self.lines(self.run_lifecycle("install"), "BenchDogs-Ext: post_install running")
        self.assertEqual(len(running), 1, running)
        self.assertIn(VERSION, running[0])

    def test_the_config_row_keeps_the_installed_version(self):
        """Grade item 2. Written once, by the pass that knows the version."""
        observed = self.run_lifecycle("install")
        writes = self.report_writes(observed)
        self.assertEqual([w["version"] for w in writes], [VERSION],
                         "the durable row was written by a pass that did not know the version")
        self.assertEqual(self.final_row(observed)["version"], VERSION)

    def test_a_pass_one_failure_survives(self):
        """Grade item 3. A step fails on its FIRST call - the pass inside the
        installer - and the durable row must still say so after the whole
        install has finished. On rc68's tree pass 2 re-ran the step, it
        succeeded, and the row was rewritten as `(unknown): N/N applied`."""
        observed = self.run_lifecycle(
            "install", fail_on_call=("BdAccountsLayoutExtensions::writeCustomerGroupField", 1))
        row = self.final_row(observed)
        self.assertEqual(row["version"], VERSION)
        self.assertTrue(row["steps"]["accounts_customer_group_field"].startswith("FAILED: "),
                        row["steps"])
        self.assertEqual(row["applied"], row["total"] - 1)
        verdicts = self.lines(observed, "post_install step report (")
        self.assertEqual(len(verdicts), 1, verdicts)
        self.assertIn("NOT APPLIED: accounts_customer_group_field", verdicts[0])
        errors = self.installation_errors(observed)
        self.assertEqual(len(errors), 1, errors)
        self.assertIn("accounts_customer_group_field", errors[0])
        self.assertIn(VERSION, errors[0])

    def test_a_failure_anywhere_reaches_installation_status(self):
        """The other half of the measured defect: a failure in a pass OUTSIDE
        the installer landed in the row but never reached installation-status,
        because only the installer pass has a ModuleInstaller in $this. The
        step here fails on its SECOND call. With one pass there is no second
        call; with two, the row names a failure channel 3 never heard of."""
        observed = self.run_lifecycle(
            "install", fail_on_call=("BdAccountsLayoutExtensions::writeCustomerGroupField", 2))
        row = self.final_row(observed)
        failed = [name for name, outcome in row["steps"].items() if outcome != "ok"]
        errors = self.installation_errors(observed)
        for name in failed:
            self.assertTrue(any(name in e for e in errors),
                            f"{name} failed but installation-status never said so: {row}")
        self.assertEqual(row["version"], VERSION)

    def test_every_step_runs_once(self):
        """Behavioural, not textual: each helper the install calls is called
        exactly once over the whole install."""
        calls = self.run_lifecycle("install")["calls"]
        self.assertTrue(calls, "the install called no helper at all")
        self.assertEqual({k: v for k, v in calls.items() if v != 1}, {})

    # -- uninstall -----------------------------------------------------------

    def test_pre_uninstall_runs_once_per_uninstall(self):
        """G295 (b) for uninstall, and the same reserved-name clash: Sugar's
        uninstallPackage() includes scripts/pre_uninstall.php BEFORE the
        installer runs the pre_uninstall installdef."""
        observed = self.run_lifecycle("uninstall")
        running = self.lines(observed, "BenchDogs-Ext: pre_uninstall running")
        self.assertEqual(len(running), 1, running)
        self.assertIn(VERSION, running[0])
        self.assertTrue(observed["calls"], "the uninstall called no helper at all")
        self.assertEqual({k: v for k, v in observed["calls"].items() if v != 1}, {})

    # -- the artifact ----------------------------------------------------------

    def test_no_lifecycle_script_ships_at_a_path_sugar_also_runs(self):
        """Structural twin of the behaviour above, on the real zip."""
        self.assertEqual(sorted(p for p in RESERVED_RUN_FOR_A_MODULE if p in self.shipped), [])

    def test_post_uninstall_is_single_pass_only_because_this_is_a_module(self):
        """scripts/post_uninstall.php keeps its reserved name. That is safe only
        because PackageManager runs POST_UNINSTALL_FILE for `patch` packages
        alone. Change the type and this goes red before the double run ships."""
        manifest = (self.pkg / "manifest.php").read_text()
        self.assertRegex(manifest, r"'type' => 'module'")

    def test_the_installdefs_point_at_files_that_ship(self):
        manifest = (self.pkg / "manifest.php").read_text()
        for key in ("post_execute", "pre_uninstall", "post_uninstall"):
            with self.subTest(installdef=key):
                block = re.search(rf"'{key}' =>\s*array \((.*?)\)", manifest, re.S)
                self.assertIsNotNone(block, f"no {key} installdef")
                paths = re.findall(r"'<basepath>/([^']+)'", block.group(1))
                self.assertEqual(len(paths), 1, paths)
                self.assertIn(paths[0], self.shipped)

    def test_the_lifecycle_scripts_are_not_also_copied_under_custom(self):
        """pack.php excludes them from copy; a renamed script must be excluded
        by its new name, or it lands in custom/include/bd_scripts/ where nothing
        runs it and an uninstall deletes it."""
        manifest = (self.pkg / "manifest.php").read_text()
        copied = re.findall(r"'to' => 'custom/include/bd_scripts/([^']+)'", manifest)
        for key in ("post_execute", "pre_uninstall", "post_uninstall"):
            block = re.search(rf"'{key}' =>\s*array \((.*?)\)", manifest, re.S)
            for path in re.findall(r"'<basepath>/scripts/([^']+)'", block.group(1)):
                with self.subTest(script=path):
                    self.assertNotIn(path, copied)


class PlatformReservedScriptContractTest(unittest.TestCase):
    """The stub above encodes one belief: that Sugar includes a reserved script
    path a second time. Read it out of Sugar's own source instead."""

    # SugarCRM's source, which CI can never have; CI leaves this class out BY
    # NAME (mlp-lint.yml), it does not skip it.
    requires_sugarent_tree = True

    def trees(self):
        found = {v: p for v, p in SUGAR_TREES.items() if p.is_dir()}
        if not found:
            self.skipTest("no SugarEnt-Full tree available")
        return found

    def test_sugar_includes_the_reserved_paths_after_the_installer(self):
        for version, root in self.trees().items():
            zipfile_src = (root / "src/PackageManager/File/PackageZipFile.php").read_text()
            manager = (root / "src/PackageManager/PackageManager.php").read_text()
            with self.subTest(version=version):
                for path, function in RESERVED.items():
                    self.assertIn(f"'{path}'", zipfile_src)
                    self.assertRegex(zipfile_src, rf"_FILE => '{function}'")
                run = zipfile_src.split("public function runPackageScript(", 1)[1].split("\n    }\n", 1)[0]
                self.assertIn("include $scriptFile;", run)
                self.assertIn("if (!file_exists($scriptFile))", run)

                install = manager.split("public function installPackage(", 1)[1]
                install = install.split("public function uninstallPackage(", 1)[0]
                self.assertLess(install.index("$moduleInstaller->install("),
                                install.index("runPackageScript(PackageZipFile::POST_INSTALL_FILE"))

                uninstall = manager.split("public function uninstallPackage(", 1)[1]
                uninstall = uninstall.split("\n    }\n", 1)[0]
                self.assertLess(uninstall.index("runPackageScript(PackageZipFile::PRE_UNINSTALL_FILE"),
                                uninstall.index("$moduleInstaller->uninstall("))
                self.assertRegex(
                    uninstall,
                    r"PACKAGE_TYPE_PATCH\) \{\s*\$zipFile->runPackageScript\(PackageZipFile::POST_UNINSTALL_FILE")

    def test_the_installer_requires_the_installdef_inside_its_own_method(self):
        for version, root in self.trees().items():
            source = (root / "ModuleInstall/ModuleInstaller.php").read_text()
            with self.subTest(version=version):
                for method, key in (("post_execute", "post_execute"), ("pre_uninstall", "pre_uninstall")):
                    body = source.split(f"public function {method}()", 1)[1].split("\n    }\n", 1)[0]
                    self.assertIn("extract($data);", body)
                    self.assertIn(f"installdefs['{key}']", body)
                    self.assertIn("require_once", body)


if __name__ == "__main__":
    unittest.main()
