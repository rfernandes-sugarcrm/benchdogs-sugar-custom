#!/usr/bin/env python3
"""The UAT config package: its navigation step, its language files, and what its built zip installs.

WHAT IT CHECKS, and what broken looks like:
  * the post-install step stores exactly the 13 tabs in order and the 14 hidden subpanels
    -> a tab dropped or moved, or the subpanel list handed over as a plain list (Sugar stores the KEYS,
       so the tenant would hide subpanels named 0..13 and show all 14)
  * a tab for a module the Sugar lacks, and a setting write that throws, are reported on the install's
    status line and never escape (a throw makes Module Loader uninstall the package)
  * the stand-ins the step is run against behave as Sugar's own TabController and SubPanelDefinitions
    (SugarEnt-tree only, named in mlp-lint.yml)
  * the language files rename Meetings without wiping the other entries of the renamed lists, and carry
    the three dropdown lists and the Interaction Type label
  * the Quick Create menu files show Opportunities, Documents, Emails, Tasks and Notes in that order and
    hide Calls, Cases, Contacts, Leads and Meetings
    -> an entry flipped, reordered or missing, or a file that sets another module's menu
  * the built zip declares the Interaction Type field with its list, one language file per module, the
    post-install step and the three dependencies, and carries the ten Quick Create files byte for byte at
    their custom paths under the package id an upgrade matches on (needs the CI build, like the other
    built-package tests)
"""
from __future__ import annotations

import json
import shutil
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-UAT-Config"
POST_EXECUTE = PACKAGE / "scripts/post_execute.php"

SUGAR_TREES = {
    "25.2.0": Path.home() / "Documents/Code/SugarEnt-Full-25.2.0",
    "26.1.0": Path.home() / "Documents/Code/SugarEnt-Full-26.1.0",
}

# The owner-approved navigation. A change here is a change to what every install sets; make it on purpose.
TABS = [
    "Home", "Accounts", "Contacts", "Opportunities", "Quotes", "Reports", "ERP_Orders", "ERP_Invoices",
    "pmse_Inbox", "pmse_Project", "pmse_Business_Rules", "pmse_Emails_Templates", "ERP_SalesTargets",
]
HIDDEN = [
    "calls", "leads", "project", "bugs", "products", "contracts", "dataprivacy", "cj_forms", "dri_workflows",
    "dri_workflow_task_templates", "cj_webhooks", "externalusers", "changetimers", "revenuelineitems",
]

# The view and filter files that differ from the target Sugar (measured with comment-only lines ignored).
LAYOUTS = [
    f"modules/{module}/clients/base/{kind}"
    for module, kinds in {
        "Accounts": ["views/list/list.php", "views/preview/preview.php", "views/record/record.php",
                     "views/recorddashlet/recorddashlet.php", "views/selection-list/selection-list.php",
                     "filters/default/default.php"],
        "Contacts": ["views/list/list.php", "views/preview/preview.php", "views/record/record.php",
                     "views/recorddashlet/recorddashlet.php", "views/selection-list/selection-list.php",
                     "views/subpanel-for-accounts-contacts/subpanel-for-accounts-contacts.php",
                     "filters/default/default.php"],
        "Opportunities": ["views/list/list.php", "filters/default/default.php"],
        "Quotes": ["views/list/list.php", "views/preview/preview.php", "views/record/record.php",
                   "views/recorddashlet/recorddashlet.php", "views/selection-list/selection-list.php",
                   "filters/default/default.php"],
        "Products": ["views/list/list.php", "views/preview/preview.php", "views/record/record.php",
                     "views/recorddashlet/recorddashlet.php", "views/selection-list/selection-list.php"],
        "ERP_Orders": ["views/record/record.php"],
        "Meetings": ["views/list/list.php", "views/preview/preview.php", "views/record/record.php",
                     "views/recorddashlet/recorddashlet.php", "views/selection-list/selection-list.php",
                     "filters/default/default.php"],
        "Users": ["views/list/list.php", "filters/default/default.php"],
    }.items()
    for kind in kinds
]

# The Quick Create menu as the owner approved it: module -> its place in the menu, or None when hidden.
# Accounts and RevenueLineItems are not here: Accounts keeps Sugar's own entry (shown, first) and
# RevenueLineItems was already hidden the same way on both Sugars, so neither ships.
QUICK_CREATE_MENU = {
    "Opportunities": 1, "Documents": 2, "Emails": 3, "Tasks": 4, "Notes": 5,
    "Calls": None, "Cases": None, "Contacts": None, "Leads": None, "Meetings": None,
}
QUICK_CREATE = [f"modules/{module}/clients/base/menus/quickcreate/quickcreate.php" for module in QUICK_CREATE_MENU]

# ---------------------------------------------------------------------------
# The platform the step runs on. Administration keeps config rows the way Sugar does (an array is stored as
# JSON and decoded on read); everything else the two classes touch is the smallest thing that answers.
# ---------------------------------------------------------------------------
PLATFORM = r'''<?php
$GLOBALS['rows'] = [];
$GLOBALS['events'] = [];
$GLOBALS['logged'] = [];
class TestLog {
    public function fatal($s) { $GLOBALS['logged'][] = $s; }
    public function error($s) { $GLOBALS['logged'][] = $s; }
    public function warn($s) { $GLOBALS['logged'][] = $s; }
    public function info($s) { $GLOBALS['logged'][] = $s; }
    public function debug($s) {}
}
$GLOBALS['log'] = new TestLog();
class LoggerManager { public static function getLogger() { return $GLOBALS['log']; } }
function safeInArray($needle, $haystack, bool $strict = false): bool {
    return is_array($haystack) && in_array($needle, $haystack, $strict);
}
class Administration {
    public $settings = [];
    public static function getSettings($category = false, $clean = false) {
        $admin = new Administration();
        $admin->retrieveSettings($category, $clean);
        return $admin;
    }
    public function retrieveSettings($category = false, $clean = false) {
        foreach ($GLOBALS['rows'] as $key => $value) {
            if ($category && strpos($key, $category . '_') !== 0) { continue; }
            $decoded = (is_string($value) && $value !== '' && ($value[0] === '[' || $value[0] === '{'))
                ? json_decode($value, true) : $value;
            $this->settings[$key] = $decoded;
        }
        $this->settings[$category] = true;
        return $this;
    }
    public function saveSetting($category, $key, $value, $platform = '') {
        if (in_array($category . '_' . $key, $GLOBALS['refuse'], true)) {
            throw new RuntimeException('config write refused: ' . $category . '_' . $key);
        }
        $GLOBALS['rows'][$category . '_' . $key] = is_array($value) ? json_encode($value) : $value;
    }
}
class BeanFactory {
    public static function newBean($module) {
        if ($module === 'Administration') { return new Administration(); }
        throw new Exception('no other bean: ' . $module);
    }
}
class SugarACL {
    public static function checkAccess($module, $action, $context = []) { return true; }
    public static function filterModuleList($list, $action = 'access', $use_value = false) { return $list; }
}
class ModuleInstaller {
    public function post_execute() {
        // extract($data) in the real method puts $manifest in scope before the include.
        $manifest = ['version' => '9.9.9-test'];
        require_once 'scripts/post_execute.php';
    }
    public function setInstallationError(string $error): void { $GLOBALS['events'][] = $error; }
}
'''

RUN = r'''
$GLOBALS['moduleList'] = MODULE_LIST;
$GLOBALS['refuse'] = REFUSE;
$failure = null;
try {
    (new ModuleInstaller())->post_execute();
} catch (Throwable $e) {
    $failure = get_class($e) . ': ' . $e->getMessage();
}
echo json_encode(['failure' => $failure, 'rows' => $GLOBALS['rows'], 'status' => $GLOBALS['events'],
                  'logged' => $GLOBALS['logged']]);
'''

# Sugar's two classes as far as this step uses them: set_system_tabs stores the list's values, get_system_tabs
# drops a tab whose module is not in $moduleList, set_hidden_subpanels stores the KEYS it is given.
STANDIN_TAB_CONTROLLER = r'''<?php
class TabController {
    public function get_system_tabs(bool $filter = true, bool $requireInModuleList = true): array {
        global $moduleList;
        $administration = Administration::getSettings('MySettings', true);
        $tabs = $administration->settings['MySettings_tab'] ?? null;
        if (empty($tabs) || !is_array($tabs)) { return static::get_key_array($moduleList); }
        if ($requireInModuleList) {
            foreach ($tabs as $id => $tab) { if (!safeInArray($tab, $moduleList)) { unset($tabs[$id]); } }
        }
        return static::get_key_array($tabs);
    }
    public function set_system_tabs($tabs) {
        BeanFactory::newBean('Administration')->saveSetting('MySettings', 'tab', json_encode(array_values($tabs)));
    }
    public static function get_key_array($arr) {
        $new = [];
        foreach ((array) $arr as $val) { $new[$val] = $val; }
        return $new;
    }
}
'''
STANDIN_SUBPANEL_DEFINITIONS = r'''<?php
class SubPanelDefinitions {
    public static function set_hidden_subpanels($panels) {
        BeanFactory::newBean('Administration')->saveSetting('MySettings', 'hide_subpanels',
            json_encode(array_keys($panels)));
    }
    public static function get_hidden_subpanels() {
        $administration = Administration::getSettings('MySettings');
        return $administration->settings['MySettings_hide_subpanels'] ?? [];
    }
}
'''

SCENARIOS = {
    "every module present": (TABS + ["Calls", "Meetings", "Leads"], []),
    "a tab module missing": ([t for t in TABS if t != "ERP_SalesTargets"], []),
    "the tab write refused": (TABS, ["MySettings_tab"]),
}


def run_post_execute(tab_controller: str, subpanel_definitions: str, module_list: list[str],
                     refuse: list[str]) -> dict:
    """Run the shipped step inside a ModuleInstaller stub with the given two classes on Sugar's paths."""
    with tempfile.TemporaryDirectory() as tmp:
        base = Path(tmp)
        (base / "scripts").mkdir()
        shutil.copy(POST_EXECUTE, base / "scripts/post_execute.php")
        (base / "modules/MySettings").mkdir(parents=True)
        (base / "modules/MySettings/TabController.php").write_text(tab_controller)
        (base / "include/SubPanel").mkdir(parents=True)
        (base / "include/SubPanel/SubPanelDefinitions.php").write_text(subpanel_definitions)
        run = RUN.replace("MODULE_LIST", "json_decode('" + json.dumps(module_list) + "', true)")
        run = run.replace("REFUSE", "json_decode('" + json.dumps(refuse) + "', true)")
        (base / "harness.php").write_text(PLATFORM + run)
        done = subprocess.run(["php", "-d", "display_errors=stderr", "harness.php"], cwd=base,
                              capture_output=True, text=True, timeout=60)
        assert done.returncode == 0 and done.stdout.strip(), done.stdout[-2000:] + done.stderr[-2000:]
        return json.loads(done.stdout)


# The setters called directly, with inputs whose keys and values differ, so "stores the keys" and "stores the
# values" give different rows; post_execute's own inputs cannot tell them apart.
DIRECT = r'''
$GLOBALS['moduleList'] = ['Home', 'Accounts'];
$GLOBALS['refuse'] = [];
require_once 'modules/MySettings/TabController.php';
require_once 'include/SubPanel/SubPanelDefinitions.php';
SubPanelDefinitions::set_hidden_subpanels(['calls', 'leads']);
$hiddenFromList = $GLOBALS['rows']['MySettings_hide_subpanels'];
SubPanelDefinitions::set_hidden_subpanels(['calls' => 'x', 'leads' => 'y']);
$controller = new TabController();
$controller->set_system_tabs(['first' => 'Home', 'second' => 'Accounts', 'third' => 'Gone']);
echo json_encode(['hidden_from_list' => $hiddenFromList, 'rows' => $GLOBALS['rows'],
                  'read_back' => $controller->get_system_tabs(false, true),
                  'read_back_all' => $controller->get_system_tabs(false, false)]);
'''


def run_direct(tab_controller: str, subpanel_definitions: str) -> dict:
    with tempfile.TemporaryDirectory() as tmp:
        base = Path(tmp)
        (base / "modules/MySettings").mkdir(parents=True)
        (base / "modules/MySettings/TabController.php").write_text(tab_controller)
        (base / "include/SubPanel").mkdir(parents=True)
        (base / "include/SubPanel/SubPanelDefinitions.php").write_text(subpanel_definitions)
        (base / "harness.php").write_text(PLATFORM + DIRECT)
        done = subprocess.run(["php", "-d", "display_errors=stderr", "harness.php"], cwd=base,
                              capture_output=True, text=True, timeout=60)
        assert done.returncode == 0 and done.stdout.strip(), done.stdout[-2000:] + done.stderr[-2000:]
        return json.loads(done.stdout)


def stored(rows: dict, key: str) -> list:
    return json.loads(rows[key]) if key in rows else None


class PostInstallSetsTheNavigation(unittest.TestCase):
    def setUp(self):
        self.assertTrue(shutil.which("php"), "php is needed to run the post-install step")

    def run_scenario(self, name: str) -> dict:
        module_list, refuse = SCENARIOS[name]
        return run_post_execute(STANDIN_TAB_CONTROLLER, STANDIN_SUBPANEL_DEFINITIONS, module_list, refuse)

    def test_the_tabs_are_stored_in_order_and_the_subpanel_names_hidden(self):
        out = self.run_scenario("every module present")
        self.assertIsNone(out["failure"])
        self.assertEqual(stored(out["rows"], "MySettings_tab"), TABS)
        self.assertEqual(stored(out["rows"], "MySettings_hide_subpanels"), HIDDEN)
        self.assertEqual(out["status"], [], "a clean install wrote to the install's status line")
        self.assertTrue(any("2/2 applied" in line for line in out["logged"]), out["logged"])

    def test_a_tab_whose_module_is_missing_is_reported_not_hidden(self):
        out = self.run_scenario("a tab module missing")
        self.assertIsNone(out["failure"])
        self.assertEqual(stored(out["rows"], "MySettings_tab"), TABS)
        self.assertEqual(len(out["status"]), 1, out["status"])
        self.assertIn("IS INSTALLED", out["status"][0])
        self.assertIn("tabs -> NOT APPLIED", out["status"][0])
        self.assertNotIn("ERP_SalesTargets", out["status"][0].split("read back as", 1)[1])
        self.assertNotIn("hidden_subpanels ->", out["status"][0])

    def test_a_refused_write_is_reported_and_the_next_step_still_runs(self):
        out = self.run_scenario("the tab write refused")
        self.assertIsNone(out["failure"], "an exception escaped post_execute, which uninstalls the package")
        self.assertNotIn("MySettings_tab", out["rows"])
        self.assertEqual(stored(out["rows"], "MySettings_hide_subpanels"), HIDDEN)
        self.assertEqual(len(out["status"]), 1, out["status"])
        self.assertIn("tabs -> FAILED: RuntimeException", out["status"][0])


class TheStandInsAreSugars(unittest.TestCase):
    """The same scenarios against Sugar's own TabController.php and SubPanelDefinitions.php."""

    requires_sugarent_tree = True

    def test_every_scenario_stores_what_sugars_own_classes_store(self):
        trees = {v: p for v, p in SUGAR_TREES.items() if p.is_dir()}
        if not trees:
            self.skipTest("no SugarEnt-Full tree available")
        for version, tree in trees.items():
            real_tabs = (tree / "modules/MySettings/TabController.php").read_text()
            real_subpanels = (tree / "include/SubPanel/SubPanelDefinitions.php").read_text()
            for name, (module_list, refuse) in SCENARIOS.items():
                with self.subTest(sugar=version, scenario=name):
                    sugars = run_post_execute(real_tabs, real_subpanels, module_list, refuse)
                    ours = run_post_execute(STANDIN_TAB_CONTROLLER, STANDIN_SUBPANEL_DEFINITIONS,
                                            module_list, refuse)
                    self.assertIsNone(sugars["failure"])
                    self.assertEqual(sugars["rows"], ours["rows"])
                    self.assertEqual(sugars["status"], ours["status"])
            with self.subTest(sugar=version, scenario="the setters called directly"):
                sugars = run_direct(real_tabs, real_subpanels)
                self.assertEqual(sugars, run_direct(STANDIN_TAB_CONTROLLER, STANDIN_SUBPANEL_DEFINITIONS))
                # The premise the shipped step is built on: a plain list would hide subpanels named 0 and 1.
                self.assertEqual(json.loads(sugars["hidden_from_list"]), [0, 1])


LANGUAGE_SEED = r'''<?php
$app_strings = ['LBL_MEETINGS' => 'Meetings', 'LBL_ACCOUNTS' => 'Accounts'];
$app_list_strings = [
    'moduleList' => ['Accounts' => 'Accounts', 'Meetings' => 'Meetings'],
    'moduleListSingular' => ['Accounts' => 'Account', 'Meetings' => 'Meeting'],
    'record_type_display_notes' => ['Accounts' => 'Account', 'Meetings' => 'Meeting', 'ERP_Orders' => 'ERP Order'],
    'industry_dom' => ['' => '', 'Biotechnology' => 'Biotechnology'],
];
$mod_strings = ['LBL_MODULE_NAME' => 'Meetings'];
include $argv[1];
echo json_encode(['app_strings' => $app_strings, 'app_list_strings' => $app_list_strings,
                  'mod_strings' => $mod_strings]);
'''


def include_language(path: Path) -> dict:
    with tempfile.TemporaryDirectory() as tmp:
        seed = Path(tmp) / "seed.php"
        seed.write_text(LANGUAGE_SEED)
        done = subprocess.run(["php", "-d", "display_errors=stderr", str(seed), str(path)],
                              capture_output=True, text=True, timeout=60)
        assert done.returncode == 0 and done.stdout.strip(), done.stdout[-2000:] + done.stderr[-2000:]
        return json.loads(done.stdout)


class TheLanguageFiles(unittest.TestCase):
    def setUp(self):
        self.assertTrue(shutil.which("php"), "php is needed to include the language files")

    def test_meetings_is_renamed_and_the_other_entries_are_kept(self):
        out = include_language(PACKAGE / "language/application/en_us.lang.php")
        lists = out["app_list_strings"]
        self.assertEqual(lists["moduleList"], {"Accounts": "Accounts", "Meetings": "Interactions"})
        self.assertEqual(lists["moduleListSingular"], {"Accounts": "Account", "Meetings": "Interaction"})
        self.assertEqual(lists["record_type_display_notes"],
                         {"Accounts": "Account", "Meetings": "Interaction", "ERP_Orders": "ERP Order"})
        self.assertEqual(out["app_strings"]["LBL_MEETINGS"], "Interactions")
        self.assertEqual(out["app_strings"]["LBL_ACCOUNTS"], "Accounts")

    def test_the_three_dropdown_lists(self):
        lists = include_language(PACKAGE / "language/application/en_us.lang.php")["app_list_strings"]
        self.assertEqual(list(lists["interaction_type_list"]),
                         ["", "Event", "In Person Mtg", "Lunch", "Phone", "Virtual"])
        self.assertNotIn("Biotechnology", lists["industry_dom"])
        self.assertEqual(list(lists["industry_dom"])[:3], ["", "Apparel", "Automotive"])
        self.assertIn("Seamless AI", lists["lead_source_dom"])
        self.assertIn("Word of mouth", lists["lead_source_dom"])
        self.assertNotIn("account_health_list", lists, "the Health list is the field package's, unchanged")

    def test_the_meetings_labels_and_the_new_field_label(self):
        mod = include_language(PACKAGE / "language/Meetings/en_us.lang.php")["mod_strings"]
        self.assertEqual(mod["LBL_MODULE_NAME"], "Interactions")
        self.assertEqual(mod["LBL_INTERACTION_TYPE"], "Interaction Type")

    def test_a_module_file_sets_only_its_module_strings(self):
        for path in sorted((PACKAGE / "language").glob("*/en_us.lang.php")):
            if path.parent.name == "application":
                continue
            with self.subTest(module=path.parent.name):
                text = path.read_text()
                self.assertIn("$mod_strings[", text)
                self.assertNotIn("$app_list_strings", text)
                self.assertNotIn("$app_strings", text)


QUICK_CREATE_READ = r'''<?php
$viewdefs = [];
include $argv[1];
echo json_encode($viewdefs);
'''


def include_quick_create(path: Path) -> dict:
    with tempfile.TemporaryDirectory() as tmp:
        reader = Path(tmp) / "read.php"
        reader.write_text(QUICK_CREATE_READ)
        done = subprocess.run(["php", "-d", "display_errors=stderr", str(reader), str(path)],
                              capture_output=True, text=True, timeout=60)
        assert done.returncode == 0 and done.stdout.strip(), done.stdout[-2000:] + done.stderr[-2000:]
        return json.loads(done.stdout)


class ThePackageFiles(unittest.TestCase):
    def test_every_layout_and_quick_create_file_ships_at_its_custom_path(self):
        shipped = sorted(p.relative_to(PACKAGE / "custom").as_posix()
                         for p in (PACKAGE / "custom/modules").rglob("*.php"))
        self.assertEqual(shipped, sorted(LAYOUTS + QUICK_CREATE))

    def test_the_quick_create_menu_shows_and_hides_the_approved_modules(self):
        self.assertTrue(shutil.which("php"), "php is needed to include the Quick Create files")
        for module, place in QUICK_CREATE_MENU.items():
            with self.subTest(module=module):
                viewdefs = include_quick_create(
                    PACKAGE / "custom/modules" / module / "clients/base/menus/quickcreate/quickcreate.php")
                # Only this module's menu entry, so a file copied under the wrong module cannot pass.
                self.assertEqual(list(viewdefs), [module])
                menu = viewdefs[module]["base"]["menu"]["quickcreate"]
                self.assertIs(menu["visible"], place is not None)
                self.assertEqual(menu.get("order"), place)

    def test_the_contacts_subpanel_view_ships_with_the_pointer_that_uses_it(self):
        pointer = (PACKAGE / "custom/Extension/modules/Accounts/Ext/clients/base/layouts/subpanels/"
                   "_overridesubpanel-for-accounts-contacts.php").read_text()
        self.assertIn("'view' => 'subpanel-for-accounts-contacts'", pointer)
        self.assertIn("modules/Contacts/clients/base/views/subpanel-for-accounts-contacts/"
                      "subpanel-for-accounts-contacts.php", LAYOUTS)

    def test_the_interaction_type_field_is_on_the_meetings_views(self):
        for view in ("views/record/record.php", "views/list/list.php", "filters/default/default.php"):
            with self.subTest(view=view):
                text = (PACKAGE / "custom/modules/Meetings/clients/base" / view).read_text()
                self.assertIn("interaction_type_c", text)
        self.assertTrue((PACKAGE / "custom/Extension/modules/Meetings/Ext/Vardefs/"
                         "sugarfield_interaction_type_c.php").is_file())

    def test_nothing_ships_under_a_name_studio_or_rename_modules_writes(self):
        # Those files are rewritten by an administrator's later edit, and an uninstall would delete that edit.
        for path in (PACKAGE / "custom").rglob("*.php"):
            rel = path.relative_to(PACKAGE / "custom").as_posix()
            with self.subTest(path=rel):
                self.assertNotRegex(rel, r"/Ext/Language/|^include/language/|DropdownsStyle/[a-z_]+_style\.php$")


PHP_INSTALLDEFS = (
    "$z = new ZipArchive(); if ($z->open($argv[1]) !== true) { exit(3); }"
    " $src = $z->getFromName('manifest.php'); $z->close();"
    " $manifest = null; $installdefs = null; eval('?>' . $src);"
    " echo json_encode(['manifest' => $manifest, 'installdefs' => $installdefs]);"
)


class TheBuiltZip(unittest.TestCase):
    """Reads the zip CI's build step wrote; fails, rather than skips, when there is none."""

    @classmethod
    def setUpClass(cls):
        zips = sorted((PACKAGE / "releases").glob("sugarai_benchdogs_uat_config-*.zip"))
        version = (PACKAGE / "version").read_text().strip()
        cls.zip = PACKAGE / "releases" / f"sugarai_benchdogs_uat_config-{version}.zip"
        assert cls.zip in zips, f"no built zip for {version} in {PACKAGE / 'releases'} (run pack.php first)"
        done = subprocess.run(["php", "-r", PHP_INSTALLDEFS, "--", str(cls.zip)],
                              capture_output=True, text=True, timeout=60)
        assert done.returncode == 0, done.stderr[-2000:]
        loaded = json.loads(done.stdout)
        cls.manifest, cls.installdefs = loaded["manifest"], loaded["installdefs"]
        with zipfile.ZipFile(cls.zip) as z:
            cls.names = z.namelist()

    def test_the_interaction_type_field_is_declared_with_its_list(self):
        fields = self.installdefs["custom_fields"]
        self.assertEqual(len(fields), 1)
        field = fields[0]
        self.assertEqual((field["name"], field["module"], field["type"], field["ext1"], field["label"],
                          field["max_size"]),
                         ("interaction_type_c", "Meetings", "enum", "interaction_type_list",
                          "LBL_INTERACTION_TYPE", "100"))

    def test_one_language_file_per_module(self):
        self.assertEqual(
            sorted((e["to_module"], e["language"]) for e in self.installdefs["language"]),
            sorted((m, "en_us") for m in ("application", "Accounts", "Calls", "Contacts", "Meetings",
                                          "Reports", "DRI_Workflows", "DRI_SubWorkflows")))

    def test_the_navigation_step_and_the_dependencies(self):
        self.assertEqual(self.installdefs["post_execute"], ["<basepath>/scripts/post_execute.php"])
        self.assertEqual({d["id_name"] for d in self.manifest["dependencies"]},
                         {"sugarai_erp_epicor", "sugarai_benchdogs_ext", "Bench_Dogs_Account_Contact_Fields"})
        self.assertEqual(self.installdefs["beans"], [])

    def test_every_custom_file_is_copied_to_its_own_path(self):
        custom = sorted(n for n in self.names if n.startswith("custom/"))
        self.assertEqual(sorted(e["to"] for e in self.installdefs["copy"]), custom)
        self.assertEqual(len(custom), len(LAYOUTS) + len(QUICK_CREATE) + 9)

    def test_the_quick_create_files_are_in_the_zip_as_shipped(self):
        copied = {e["to"]: e["from"] for e in self.installdefs["copy"]}
        with zipfile.ZipFile(self.zip) as z:
            for rel in QUICK_CREATE:
                with self.subTest(path=rel):
                    name = f"custom/{rel}"
                    self.assertIn(name, self.names)
                    self.assertEqual(copied.get(name), f"<basepath>/{name}")
                    self.assertEqual(z.read(name), (PACKAGE / "custom" / rel).read_bytes())

    def test_the_package_id_an_upgrade_matches_on(self):
        # Module Loader treats a package as an upgrade of an installed one when installdefs id is the same and
        # the version is higher; a new id would install beside the old version instead of replacing it.
        self.assertEqual(self.installdefs["id"], "sugarai_benchdogs_uat_config")
        self.assertEqual(self.manifest["version"], (PACKAGE / "version").read_text().strip())


if __name__ == "__main__":
    unittest.main()
