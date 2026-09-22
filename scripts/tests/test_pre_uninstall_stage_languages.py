"""Execute the real pre_uninstall against an isolated Sugar lifecycle harness.

G234: after a clean 16/16 uninstall of rc60 from stock, Opportunities'
sales_stage was still serving "Prototype Ordered" and "Partial Production
Ordered" on a tenant where zero opportunities held either. Two layers caused
it and both are asserted here:

  1. the script declined to remove the keys unconditionally, on a rationale
     that only holds when records actually hold them; and
  2. it could not have removed them anyway, because post_install writes them
     through a hand-built ModuleInstaller with id_name 'zz_bd_stage_doms' and
     nothing in installdefs['copy'] or installdefs['language'] names that file.

So the tests below check a behaviour AND a mechanism: that the removal runs
only when no record holds a key, and that when it runs it is the exact mirror
of the install - same installer class, same id_name, same template - because a
removal that used any other id_name would delete a file that does not exist
and report success. The named control is Partial Fulfillment's
quote_stage_dom['Partially Fulfilled'], which PF ships in its own right and
which this package must never take away.
"""

import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"

PF_OVERRIDE = (
    "custom/Extension/application/Ext/Language/"
    "_override_en_us.partial_fulfillment_quote_stage.php"
)

HARNESS = r'''<?php
$scenario = SCENARIO;
$events = [];
$errors = [];
$removed = false;
$holders = json_decode('HOLDERS', true);
$current_language = 'en_us';
$sugar_config = ['default_language' => 'en_us'];
if ($scenario === 'languages') {
    $current_language = 'fr_FR';
    $sugar_config['default_language'] = 'de_DE';
}
class TestLog {
    public function error($s) { $GLOBALS['errors'][] = $s; }
    public function info($s) { $GLOBALS['errors'][] = $s; }
    public function fatal($s) { $GLOBALS['errors'][] = $s; }
}
$GLOBALS['log'] = new TestLog();

class TestBean { public $module; public function __construct($m) { $this->module = $m; } }
class BeanFactory { public static function newBean($m) { return new TestBean($m); } }
class TestWhere {
    private $q;
    public function __construct($q) { $this->q = $q; }
    public function in($field, $set) { $this->q->field = $field; $this->q->set = $set; return $this; }
}
class SugarQuery {
    public $module; public $options = []; public $field; public $set; public $rows;
    public function select($f) { return $this; }
    public function from($bean, $options = []) { $this->module = $bean->module; $this->options = $options; return $this; }
    public function where() { return new TestWhere($this); }
    public function limit($n) { $this->rows = $n; return $this; }
    public function execute() {
        $GLOBALS['events'][] = ['query', $this->module, $this->field, $this->set,
                                $this->options['team_security'] ?? 'DEFAULT', $this->rows];
        if ($GLOBALS['scenario'] === 'query_exception') throw new Exception('PRIVATE QUERY DETAILS');
        return empty($GLOBALS['holders'][$this->module]) ? [] : [['id' => 'a-record']];
    }
}
class MetaDataManager {
    public static function refreshLanguagesCache($languages) {
        $GLOBALS['events'][] = ['refresh_languages', $languages];
    }
}
function return_app_list_strings_language($language, $useCache = true) {
    $GLOBALS['events'][] = ['verify', $language, $useCache];
    $doms = ['quote_stage_dom' => [], 'sales_stage_dom' => [], 'sales_probability_dom' => []];
    // PF ships its key through its OWN installdefs['copy'], so it is present
    // exactly when PF's override file is on disk - never because of us.
    if (file_exists('PF_OVERRIDE_PATH') && $GLOBALS['scenario'] !== 'pf_key_lost') {
        $doms['quote_stage_dom']['Partially Fulfilled'] = 'Partially Fulfilled';
    }
    if (!$GLOBALS['removed'] || $GLOBALS['scenario'] === 'residue') {
        $doms['sales_stage_dom']['Prototype Ordered'] = 'Prototype Ordered';
        $doms['sales_stage_dom']['Partial Production Ordered'] = 'Partial Production Ordered';
        $doms['sales_probability_dom']['Prototype Ordered'] = 80;
        $doms['sales_probability_dom']['Partial Production Ordered'] = 90;
    }
    return $doms;
}
// pre_uninstall.php is TOP-LEVEL CODE: requiring it IS running it, exactly as
// ModuleInstaller does. Nothing calls a function named pre_uninstall.
$failure = null;
try {
    require 'scripts/pre_uninstall.php';
} catch (Throwable $e) { $failure = $e->getMessage(); }
echo json_encode(['failure' => $failure, 'events' => $events, 'errors' => $errors,
                  'removed' => $removed]);
'''

INSTALLER_STUB = r'''<?php
class ModuleInstaller {
    public $silent; public $id_name; public $base_dir; public $installdefs;
    public function uninstall_languages() {
        $GLOBALS['events'][] = ['uninstall_languages', $this->id_name, $this->installdefs];
        if ($GLOBALS['scenario'] === 'removal_exception') throw new Exception('PRIVATE REMOVAL DETAILS');
        $GLOBALS['removed'] = true;
    }
    public function rebuild_languages($languages = [], $modules = []) {
        $GLOBALS['events'][] = ['rebuild_languages', $languages, $modules];
    }
}
'''

KEPT = 'BenchDogs-Ext: stage dropdown keys KEPT'
REMOVED = 'BenchDogs-Ext: stage dropdown keys removed - no record held them'
RESIDUE = 'BenchDogs-Ext: stage dropdown key removal verification failed'
TOOK_PF = 'BenchDogs-Ext: stage dropdown key removal took Partial Fulfillment quote stage'


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class PreUninstallStageLanguagesTest(unittest.TestCase):
    def execute(self, scenario="clean", holders=None, pf_installed=True,
                template=True):
        holders = holders or {}
        with tempfile.TemporaryDirectory(prefix="bench-stage-uninstaller-") as tmp:
            target = Path(tmp)
            copies = ["scripts/pre_uninstall.php"]
            if template:
                copies.append("custom/dropdowntemplates/bd_stage_doms.append.php")
            for relative in copies:
                path = target / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(PACKAGE / relative, path)
            if pf_installed:
                override = target / PF_OVERRIDE
                override.parent.mkdir(parents=True, exist_ok=True)
                override.write_text(
                    "<?php\n$app_list_strings['quote_stage_dom']"
                    "['Partially Fulfilled'] = 'Partially Fulfilled';\n"
                )
            (target / "ModuleInstall").mkdir()
            (target / "ModuleInstall/ModuleInstaller.php").write_text(INSTALLER_STUB)
            code = (HARNESS
                    .replace("SCENARIO", json.dumps(scenario))
                    .replace("HOLDERS", json.dumps(holders))
                    .replace("PF_OVERRIDE_PATH", PF_OVERRIDE))
            result = subprocess.run(["php"], input=code, text=True, cwd=target,
                                    capture_output=True, check=True)
            return json.loads(result.stdout)

    # --- the mechanism -----------------------------------------------------

    def test_removal_is_the_exact_mirror_of_the_install(self):
        # A removal that used any other id_name would delete a file that does
        # not exist and report a clean uninstall - which is G234 all over again,
        # only now with a log line claiming success. Assert the id and the
        # template, not just that something was called.
        observed = self.execute()
        self.assertIsNone(observed["failure"])
        self.assertTrue(observed["removed"])
        call = next(e for e in observed["events"] if e[0] == "uninstall_languages")
        self.assertEqual(call[1], "zz_bd_stage_doms")
        self.assertEqual(call[2], {"language": [{
            "from": "custom/dropdowntemplates/bd_stage_doms.append.php",
            "to_module": "application",
            "language": "en_us",
        }]})

    def test_install_and_uninstall_agree_on_the_id_and_the_template(self):
        # Statically, across the two files. These two constants are the whole
        # contract: install_languages writes
        # custom/Extension/application/Ext/Language/<lang>.<id_name>.php and
        # uninstall_languages deletes that same name. Drift in either file
        # silently reopens G234.
        install = (PACKAGE / "scripts/post_install.php").read_text()
        uninstall = (PACKAGE / "scripts/pre_uninstall.php").read_text()
        for token in ("'zz_bd_stage_doms'",
                      "'custom/dropdowntemplates/bd_stage_doms.append.php'"):
            self.assertIn(token, install, token)
            self.assertIn(token, uninstall, token)
        self.assertIn("$bdMi->install_languages();", install)
        self.assertIn("$bdMi->uninstall_languages();", uninstall)

    def test_the_file_written_is_not_reachable_by_the_manifest(self):
        # The reason this script has to do the removal at all, asserted on the
        # generator rather than on a built zip. installdefs['copy'] names no
        # zz_bd_stage_doms path and the manifest declares no 'language' key at
        # all, so neither uninstall_copy() nor uninstall_languages() can reach
        # the file install_languages() writes. If a later change adds either,
        # Sugar would remove it itself and this script's step 5 should be
        # revisited deliberately rather than left to double-delete.
        pack = (PACKAGE / "pack.php").read_text()
        self.assertNotIn("zz_bd_stage_doms", pack)
        self.assertNotIn("installdefs['language']", pack)

    # --- the guard ---------------------------------------------------------

    def test_an_opportunity_on_a_bench_stage_keeps_every_key(self):
        observed = self.execute(holders={"Opportunities": 1})
        self.assertFalse(observed["removed"])
        self.assertFalse(any(e[0] == "uninstall_languages" for e in observed["events"]))
        kept = [e for e in observed["errors"] if e.startswith(KEPT)]
        self.assertEqual(len(kept), 1)
        self.assertIn("Opportunities.sales_stage", kept[0])

    def test_records_are_counted_with_team_security_off(self):
        # An uninstall that only sees the admin's own teams would read "no
        # holders" on a tenant full of them and remove a key out from under
        # every record it could not see.
        observed = self.execute()
        queries = [e for e in observed["events"] if e[0] == "query"]
        self.assertTrue(queries)
        for query in queries:
            self.assertIs(query[4], False, query)

    def test_a_partially_fulfilled_quote_does_not_block_while_pf_is_installed(self):
        # PF 1.0.36 ships that key through its own installdefs['copy'], so it
        # survives our removal. Blocking on it would let one PF quote pin
        # Bench's two sales stages on the instance forever.
        observed = self.execute(holders={"Quotes": 1}, pf_installed=True)
        self.assertTrue(observed["removed"])
        self.assertFalse(any(e[0] == "query" and e[1] == "Quotes"
                             for e in observed["events"]))

    def test_a_partially_fulfilled_quote_blocks_when_pf_is_not_installed(self):
        observed = self.execute(holders={"Quotes": 1}, pf_installed=False)
        self.assertFalse(observed["removed"])
        kept = [e for e in observed["errors"] if e.startswith(KEPT)]
        self.assertEqual(len(kept), 1)
        self.assertIn("Quotes.quote_stage", kept[0])

    def test_pf_quote_stage_survives_the_removal(self):
        # The named control for G234. If this ever fails, the fix has started
        # removing another package's vocabulary.
        observed = self.execute()
        self.assertNotIn(TOOK_PF, observed["errors"])

    def test_losing_pf_quote_stage_is_reported_loudly(self):
        # Mutation of the control: prove the check above can actually fail.
        observed = self.execute("pf_key_lost")
        self.assertIn(TOOK_PF, observed["errors"])

    # --- failure behaviour -------------------------------------------------

    def test_a_failed_count_keeps_the_keys_and_never_fails_the_uninstall(self):
        observed = self.execute("query_exception")
        self.assertIsNone(observed["failure"])
        self.assertFalse(observed["removed"])
        self.assertTrue(any(e.startswith(KEPT) for e in observed["errors"]))

    def test_a_failed_removal_keeps_the_keys_and_never_fails_the_uninstall(self):
        observed = self.execute("removal_exception")
        self.assertIsNone(observed["failure"])
        self.assertTrue(any(e.startswith(KEPT) for e in observed["errors"]))

    def test_a_missing_template_is_reported_rather_than_guessed_at(self):
        observed = self.execute(template=False)
        self.assertFalse(observed["removed"])
        self.assertIn(
            "BenchDogs-Ext: stage dom template missing, stage dropdown keys left behind",
            observed["errors"])

    def test_residue_after_the_rebuild_is_reported(self):
        # The uninstall reporting 16/16 with error "" is exactly what happened
        # in G234, so "the call returned" is not evidence. Read the uncached
        # list back and say so when the key is still served.
        observed = self.execute("residue")
        self.assertIn(RESIDUE, observed["errors"])

    def test_a_clean_removal_reports_no_residue(self):
        self.assertNotIn(RESIDUE, self.execute()["errors"])

    # --- lifecycle ---------------------------------------------------------

    def test_removal_rebuilds_and_refreshes_before_it_verifies(self):
        observed = self.execute()
        events = observed["events"]
        removal = events.index(next(e for e in events if e[0] == "uninstall_languages"))
        rebuild = events.index(["rebuild_languages", {"en_us": "en_us"}, []])
        refresh = events.index(["refresh_languages", ["en_us"]])
        verify = events.index(["verify", "en_us", False])
        self.assertLess(removal, rebuild)
        self.assertLess(rebuild, refresh)
        self.assertLess(refresh, verify)

    def test_verification_reads_the_uncached_lists(self):
        for event in self.execute()["events"]:
            if event[0] == "verify":
                self.assertIs(event[2], False, event)

    def test_current_and_default_languages_are_rebuilt_and_verified(self):
        # install_languages only ever wrote en_us, but the install compiles the
        # instance's default and current languages too. A removal that rebuilt
        # only en_us would leave a de_DE tenant serving the deleted keys.
        observed = self.execute("languages")
        self.assertIn(["rebuild_languages",
                       {"en_us": "en_us", "de_DE": "de_DE", "fr_FR": "fr_FR"}, []],
                      observed["events"])
        self.assertIn(["refresh_languages", ["en_us", "de_DE", "fr_FR"]],
                      observed["events"])
        for language in ("en_us", "de_DE", "fr_FR"):
            self.assertIn(["verify", language, False], observed["events"])

    def test_the_script_body_stays_top_level_code_that_cannot_kill_an_uninstall(self):
        source = (PACKAGE / "scripts/pre_uninstall.php").read_text()
        code = "\n".join(
            line for line in source.splitlines()
            if not line.lstrip().startswith(("*", "/*", "//", "*/"))
        )
        self.assertNotIn("function pre_uninstall", code)
        self.assertNotRegex(code, r"\bthrow\b")
        self.assertIn("BenchDogs-Ext: pre_uninstall running", code)

    def test_removal_uses_no_filesystem_function_of_its_own(self):
        # The scanner-safe route BaseErpDropdown documents: ModuleScanner
        # denylists direct filesystem writes in uploaded package code, so the
        # deletion has to happen inside Sugar's own installer class.
        source = (PACKAGE / "scripts/pre_uninstall.php").read_text()
        code = "\n".join(
            line for line in source.splitlines()
            if not line.lstrip().startswith(("*", "/*", "//", "*/"))
        )
        for banned in ("unlink(", "rmdir(", "rmdir_recursive(", "file_put_contents(",
                       "sugar_file_put_contents", "fopen(", "sugar_rename("):
            self.assertNotIn(banned, code, banned)


if __name__ == "__main__":
    unittest.main()
