"""Decision 72's marker, its placement, its report - and its BLAST RADIUS.

The behavioural half of decision 72 is in test_governing_autoselect.py. This
file pins the two things that decide whether the change is SAFE rather than
merely correct:

  1. WHEN the auto-selection can fire. `BdGoverningAutoSelectHook` reacts only
     to a line being CREATED (`isUpdate === false`) and to that line being
     linked to its ERP quote. SugarBean::isUpdate() is false only for a row
     with no id yet or a `new_with_id` insert (SugarEnt-Full 26.1.0,
     data/SugarBean.php:1849-1857), so a line that ALREADY EXISTS on a tenant
     can never reach the selector through a hook - on an install, on a resync,
     on anything. The tests below hold that gate in place, and a static check
     holds the install scripts free of any call to the selector, because the
     bound is worth nothing if a later edit quietly adds a sweep.

  2. WHETHER the marker can lie. It is DERIVED from the quote's lines on every
     rollup, never remembered, and no vardef gives it a default - so an
     Opportunity nobody evaluated reads EMPTY rather than reading as reviewed.
     That is decision 59's lesson: 994 fabricated `0.00` rows came from a
     column that had never been written reading back as a number.
"""

import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = ROOT.parent
PKG = ROOT / "sugar-sell/BenchDogs-Ext"
REL = "benchdogs-sugar-custom/sugar-sell/BenchDogs-Ext"
SHARED_HOOK = (
    WORKSPACE / "erp-integration-sugar/sugar-sell/ERP-Core/src/custom/modules"
    / "Quotes/QuoteOpportunityAmount.php"
)

PLATFORM = r'''
eval('namespace Sugarcrm\\Sugarcrm\\Util\\Files;'
    . ' class FileLoader { public static function validateFilePath($p) { return $p; } }');
'''

FIXTURE = PLATFORM + r'''
$GLOBALS['saves'] = [];
$GLOBALS['log'] = new class {
    public $errors = [];
    public $warns = [];
    public $infos = [];
    public function info($m) { $this->infos[] = $m; }
    public function warn($m) { $this->warns[] = $m; }
    public function error($m) { $this->errors[] = $m; }
    public function fatal($m) {}
    public function debug($m) {}
};
#[AllowDynamicProperties]
class SugarBean {
    public $id = '';
    public $deleted = 0;
    public $field_defs = [];
    public $currency_id = '-99';
    public $base_rate = 1;
    public function load_relationship($n) { return isset($this->$n); }
    public function save() {
        $GLOBALS['saves'][] = [
            'id' => $this->id,
            'governing' => (int) !empty($this->governing),
            'origin' => (string) ($this->bd_governing_origin ?? ''),
        ];
        if (!empty($this->on_save)) { ($this->on_save)($this); }
    }
}
class Opportunity extends SugarBean {
    public static function usingRevenueLineItems() { return false; }
}
class TestLink {
    public function __construct(public $beans = [], public $ids = []) {}
    public function get() { return $this->ids; }
    public function getBeans() { return $this->beans; }
}
class BeanFactory {
    public static $beans = [];
    public static function retrieveBean($module, $id, $options = []) {
        return self::$beans[$module][$id] ?? null;
    }
    public static function newBean($module) { throw new Exception('no newBean: ' . $module); }
}
class SugarCurrency {
    public static function convertAmount($a, $f, $t) { return $a; }
}
class SugarConfig {
    public static function getInstance() { return new self(); }
    public function get($k, $d = null) { return $k === 'opps.view_by' ? 'Opportunities' : $d; }
}

require 'erp-integration-sugar/sugar-sell/ERP-Core/src/custom/modules/Quotes/QuoteOpportunityAmount.php';
require 'REL/custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php';
chdir('REL');
require 'custom/modules/bd01_ERP_Quote_Line/BdGoverningAutoSelect.php';
require 'custom/modules/bd01_ERP_Quote_Line/BdGoverningAutoSelectHook.php';
require 'custom/modules/bd01_ERP_Quote_Line/BdGoverningLineHook.php';

$mkLine = function ($id, $total, $governing = false, $origin = '', $lineNum = 0) {
    $l = new SugarBean();
    $l->id = $id;
    $l->doc_ext_price = $total;
    $l->doc_unit_price = $total;
    $l->selling_qty = 1;
    $l->part_num = 'PART-' . $id;
    $l->name = 'Line ' . $id;
    $l->governing = $governing;
    $l->prototype = false;
    $l->bd_governing_origin = $origin;
    $l->line_num = $lineNum;
    return $l;
};
'''.replace("REL", REL)


@unittest.skipUnless(shutil.which("php"), "requires a PHP CLI")
class GoverningMarkerTest(unittest.TestCase):
    def php(self, scenario, echo):
        result = subprocess.run(
            ["php", "-d", "error_reporting=E_ALL & ~E_DEPRECATED",
             "-r", FIXTURE + scenario + "\necho json_encode(" + echo + ");"],
            cwd=WORKSPACE, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "", result.stderr)
        return json.loads(result.stdout)

    # ------------------------------------------------- the forward-only gate

    def test_an_update_of_an_existing_line_can_never_auto_select(self):
        """THE bound. Every line already on a tenant is reached only by
        updates, so this single gate is what makes the shipped change unable
        to touch the 230 lines sitting on Bench today."""
        observed = self.php(r'''
$lines = [$mkLine('a', 9600, false, '', 1), $mkLine('b', 6400, false, '', 2)];
$erp = new SugarBean();
$erp->id = 'erp-1';
$erp->bd01_erp_quote_lines = new TestLink($lines);
BeanFactory::$beans['bd01_ERP_Quote']['erp-1'] = $erp;
$line = $lines[0];
$line->bd01_erp_quote_lines = new TestLink([], ['erp-1']);
(new BdGoverningAutoSelectHook())->autoSelectOnNewLine($line, 'after_save', ['isUpdate' => true]);
$selected = [];
foreach ($lines as $l) { if (!empty($l->governing)) { $selected[] = $l->id; } }
''', "['selected' => $selected, 'saves' => $GLOBALS['saves']]")
        self.assertEqual(observed["selected"], [], observed)
        self.assertEqual(observed["saves"], [], observed)

    def test_a_newly_created_line_does_auto_select_its_quote(self):
        observed = self.php(r'''
$lines = [$mkLine('a', 9600, false, '', 1), $mkLine('b', 6400, false, '', 2)];
$erp = new SugarBean();
$erp->id = 'erp-1';
$erp->bd01_erp_quote_lines = new TestLink($lines);
BeanFactory::$beans['bd01_ERP_Quote']['erp-1'] = $erp;
$line = $lines[0];
$line->bd01_erp_quote_lines = new TestLink([], ['erp-1']);
(new BdGoverningAutoSelectHook())->autoSelectOnNewLine($line, 'after_save', ['isUpdate' => false]);
$selected = [];
foreach ($lines as $l) { if (!empty($l->governing)) { $selected[] = $l->id; } }
''', "['selected' => $selected, 'saves' => $GLOBALS['saves']]")
        self.assertEqual(observed["selected"], ["b"], observed)
        self.assertEqual(observed["saves"][0]["origin"], "auto", observed)

    def test_only_the_parent_quote_link_triggers_the_relationship_route(self):
        observed = self.php(r'''
$lines = [$mkLine('a', 9600, false, '', 1)];
$erp = new SugarBean();
$erp->id = 'erp-1';
$erp->bd01_erp_quote_lines = new TestLink($lines);
BeanFactory::$beans['bd01_ERP_Quote']['erp-1'] = $erp;
$line = $lines[0];
$line->bd01_erp_quote_lines = new TestLink([], ['erp-1']);
(new BdGoverningAutoSelectHook())->autoSelectOnLink(
    $line, 'after_relationship_add', ['link' => 'bd01_erp_line_costs']);
$after_other = $GLOBALS['saves'];
(new BdGoverningAutoSelectHook())->autoSelectOnLink(
    $line, 'after_relationship_add', ['link' => 'bd01_erp_quote_lines']);
''', "['after_other' => $after_other, 'after_parent' => $GLOBALS['saves']]")
        self.assertEqual(observed["after_other"], [], observed)
        self.assertEqual(len(observed["after_parent"]), 1, observed)

    def test_a_line_with_no_parent_quote_yet_is_simply_left_for_the_link(self):
        """A connector create arrives before the link. That must be a quiet
        no-op, not a logged failure - it happens on every single line."""
        observed = self.php(r'''
$line = $mkLine('a', 9600, false, '', 1);
$line->bd01_erp_quote_lines = new TestLink([], []);
(new BdGoverningAutoSelectHook())->autoSelectOnNewLine($line, 'after_save', ['isUpdate' => false]);
''', "['saves' => $GLOBALS['saves'], 'errors' => $GLOBALS['log']->errors]")
        self.assertEqual(observed["saves"], [], observed)
        self.assertEqual(observed["errors"], [], observed)

    def test_a_full_resync_of_the_bench_population_writes_not_one_row(self):
        """THE BLAST-RADIUS MEASUREMENT, not an assertion about the design.

        Bench carries 230 ERP quote lines and 0 of them are governing. This
        replays what a full connector resync does to that population - every
        line saved, every save an UPDATE because every one of those rows
        already exists - and counts the writes. The number that matters is the
        one printed by `writes`, and it has to be zero.

        This is the test to re-run if anyone ever moves the auto-selection onto
        another event. A trigger that fires on updates would turn this number
        into 230, and 230 rewritten forecasts is the outcome nobody approved.
        """
        observed = self.php(r'''
$lines = [];
$erp = new SugarBean();
$erp->id = 'erp-bench';
for ($i = 1; $i <= 230; $i++) {
    $line = $mkLine('line-' . $i, 10000 - $i, false, '', $i);
    $line->bd01_erp_quote_lines = new TestLink([], ['erp-bench']);
    $lines[] = $line;
}
$erp->bd01_erp_quote_lines = new TestLink($lines);
BeanFactory::$beans['bd01_ERP_Quote']['erp-bench'] = $erp;
$hook = new BdGoverningAutoSelectHook();
foreach ($lines as $line) {
    // What a resync of an EXISTING row produces, and nothing else.
    $hook->autoSelectOnNewLine($line, 'after_save', ['isUpdate' => true]);
}
$selected = 0;
foreach ($lines as $l) { if (!empty($l->governing)) { $selected++; } }
''', "['lines' => count($lines), 'writes' => count($GLOBALS['saves']), "
     "'selected' => $selected, 'errors' => $GLOBALS['log']->errors]")
        self.assertEqual(observed["lines"], 230, observed)
        self.assertEqual(observed["writes"], 0, observed)
        self.assertEqual(observed["selected"], 0, observed)
        self.assertEqual(observed["errors"], [], observed)

    def test_the_one_remaining_exposure_is_a_relink_and_it_is_recorded_here(self):
        """HONEST LIMIT, written down rather than left for someone to find.

        `isUpdate` bounds the save path completely. The LINK path has no such
        bound: if anything ever removes and re-adds a line's relationship to
        its ERP quote, that line's quote WOULD be auto-selected even though the
        row is old. A normal connector resync does not re-add an existing
        relationship, so this is not reached today - but it is the single
        remaining way an existing quote could be selected without the backfill,
        and `bench_dogs.governing_autoselect = false` is the switch that closes
        it.
        """
        observed = self.php(r'''
$lines = [$mkLine('old-a', 9600, false, '', 1), $mkLine('old-b', 6400, false, '', 2)];
$erp = new SugarBean();
$erp->id = 'erp-old';
$erp->bd01_erp_quote_lines = new TestLink($lines);
BeanFactory::$beans['bd01_ERP_Quote']['erp-old'] = $erp;
$line = $lines[0];
$line->bd01_erp_quote_lines = new TestLink([], ['erp-old']);
$hook = new BdGoverningAutoSelectHook();
$hook->autoSelectOnLink($line, 'after_relationship_add', ['link' => 'bd01_erp_quote_lines']);
$relinked = count($GLOBALS['saves']);
// ...and the operator switch closes it.
BdGoverningAutoSelect::$enabledOverride = false;
foreach ($lines as $l) { $l->governing = false; $l->bd_governing_origin = ''; }
$GLOBALS['saves'] = [];
$hook->autoSelectOnLink($line, 'after_relationship_add', ['link' => 'bd01_erp_quote_lines']);
''', "['relinked' => $relinked, 'with_switch_off' => count($GLOBALS['saves'])]")
        self.assertEqual(observed["relinked"], 1, observed)
        self.assertEqual(observed["with_switch_off"], 0, observed)

    # --------------------------------------------------- the marker clearing

    def test_a_person_choosing_a_line_clears_the_auto_marker(self):
        """Decision 72 item 4, through the hook every selection funnels into."""
        observed = self.php(r'''
$line = $mkLine('a', 6400, true, 'auto', 1);
$erp = new SugarBean();
$erp->id = 'erp-1';
$erp->bd01_erp_quote_lines = new TestLink([$line]);
BeanFactory::$beans['bd01_ERP_Quote']['erp-1'] = $erp;
BeanFactory::$beans['bd01_ERP_Quote_Line']['a'] = $line;
$line->bd01_erp_quote_lines = new TestLink([], ['erp-1']);
(new BdGoverningLineHook())->enforceSingleGoverning($line, 'after_save',
    ['dataChanges' => [['field_name' => 'governing', 'before' => 0, 'after' => 1]]]);
''', "['origin' => (string) $line->bd_governing_origin, "
     "'governing' => (int) !empty($line->governing), 'saves' => $GLOBALS['saves']]")
        self.assertEqual(observed["origin"], "", observed)
        self.assertEqual(observed["governing"], 1, observed)

    def test_our_own_auto_selection_does_not_erase_the_marker_it_just_set(self):
        """The one exception, and the reason BdGoverningAutoSelect publishes
        isApplying(). Without it the selector and the hook would fight inside
        a single request and the marker would never survive."""
        observed = self.php(r'''
$line = $mkLine('a', 6400, false, '', 1);
$erp = new SugarBean();
$erp->id = 'erp-1';
$erp->bd01_erp_quote_lines = new TestLink([$line]);
BeanFactory::$beans['bd01_ERP_Quote']['erp-1'] = $erp;
BeanFactory::$beans['bd01_ERP_Quote_Line']['a'] = $line;
$line->bd01_erp_quote_lines = new TestLink([], ['erp-1']);
// The real cascade: our save fires the enforcement hook mid-write.
$line->on_save = function ($bean) {
    (new BdGoverningLineHook())->enforceSingleGoverning($bean, 'after_save',
        ['dataChanges' => [['field_name' => 'governing', 'before' => 0, 'after' => 1]]]);
};
(new BdGoverningAutoSelect())->applyToQuote($erp);
''', "['origin' => (string) $line->bd_governing_origin, "
     "'governing' => (int) !empty($line->governing)]")
        self.assertEqual(observed["origin"], "auto", observed)
        self.assertEqual(observed["governing"], 1, observed)

    def test_a_line_a_person_chose_costs_no_extra_write(self):
        observed = self.php(r'''
$line = $mkLine('a', 6400, true, '', 1);
$erp = new SugarBean();
$erp->id = 'erp-1';
$erp->bd01_erp_quote_lines = new TestLink([$line]);
BeanFactory::$beans['bd01_ERP_Quote']['erp-1'] = $erp;
BeanFactory::$beans['bd01_ERP_Quote_Line']['a'] = $line;
$line->bd01_erp_quote_lines = new TestLink([], ['erp-1']);
(new BdGoverningLineHook())->enforceSingleGoverning($line, 'after_save',
    ['dataChanges' => [['field_name' => 'governing', 'before' => 0, 'after' => 1]]]);
''', "['saves' => $GLOBALS['saves']]")
        self.assertEqual(observed["saves"], [], observed)

    # ------------------------------------------- the derived Opportunity marker

    @unittest.skipUnless(SHARED_HOOK.is_file(), "requires sibling shared Sugar checkout")
    def test_the_opportunity_marker_is_derived_from_the_lines_every_time(self):
        # (line setup, expected marker, does the valuation still refuse?)
        #
        # The last two rows are decision 29 still doing its job: zero selected
        # and two selected both refuse, and the marker for both is EMPTY - it
        # reports what the rows say, and what they say is "no single chosen
        # line", which is neither auto nor human.
        cases = [
            ("$lines = [$mkLine('a', 6400, true, 'auto', 1)];", "auto", False),
            ("$lines = [$mkLine('a', 6400, true, '', 1)];", "human", False),
            ("$lines = [$mkLine('a', 6400, false, '', 1)];", "", True),
            ("$lines = [$mkLine('a', 6400, true, 'auto', 1), "
             "$mkLine('b', 8400, true, '', 2)];", "", True),
        ]
        for setup, expected, refuses in cases:
            with self.subTest(setup=setup):
                observed = self.php(setup + r'''
$erp = new SugarBean();
$erp->id = 'erp-1';
$erp->sugar_quote_id = 'q-1';
$erp->quote_num = 1193;
$erp->date_quote_expires = '2026-12-01';
$erp->bd01_erp_quote_lines = new TestLink($lines);
$opp = new Opportunity();
$opp->id = 'opp-1';
$opp->amount = 0;
$opp->sales_stage = 'Proposal/Price Quote';
$opp->field_defs = ['bd_governing_origin' => []];
$quote = new SugarBean();
$quote->id = 'q-1';
$quote->total = 6400;
$quote->tax = 0;
$quote->shipping = 0;
$quote->erp_is_primary_quote = true;
$quote->date_quote_expires = '2026-12-01';
$quote->opportunities = new TestLink([], ['opp-1']);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
BeanFactory::$beans['Quotes']['q-1'] = $quote;
BeanFactory::$beans['Opportunities']['opp-1'] = $opp;
(new BdQuoteReflectionHook())->refreshOpportunityAmount($erp);
''', "['marker' => (string) ($opp->bd_governing_origin ?? ''), "
     "'errors' => $GLOBALS['log']->errors]")
                self.assertEqual(observed["marker"], expected, observed)
                if refuses:
                    self.assertEqual(len(observed["errors"]), 1, observed)
                    self.assertIn(
                        "Exactly one governing production option is required",
                        observed["errors"][0],
                    )
                else:
                    self.assertEqual(observed["errors"], [], observed)

    @unittest.skipUnless(SHARED_HOOK.is_file(), "requires sibling shared Sugar checkout")
    def test_no_marker_is_written_where_the_vardef_did_not_compile(self):
        """An in-memory value with no column behind it is a silent half-state,
        and worse than no marker - writeOpportunityDirect keeps the same guard."""
        observed = self.php(r'''
$lines = [$mkLine('a', 6400, true, 'auto', 1)];
$erp = new SugarBean();
$erp->id = 'erp-1';
$erp->sugar_quote_id = 'q-1';
$erp->quote_num = 1193;
$erp->date_quote_expires = '2026-12-01';
$erp->bd01_erp_quote_lines = new TestLink($lines);
$opp = new Opportunity();
$opp->id = 'opp-1';
$opp->amount = 0;
$opp->sales_stage = 'Proposal/Price Quote';
$opp->field_defs = [];   // vardef absent on this instance
$quote = new SugarBean();
$quote->id = 'q-1';
$quote->total = 6400;
$quote->tax = 0;
$quote->shipping = 0;
$quote->erp_is_primary_quote = true;
$quote->date_quote_expires = '2026-12-01';
$quote->opportunities = new TestLink([], ['opp-1']);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
BeanFactory::$beans['Quotes']['q-1'] = $quote;
BeanFactory::$beans['Opportunities']['opp-1'] = $opp;
(new BdQuoteReflectionHook())->refreshOpportunityAmount($erp);
''', "['marker' => $opp->bd_governing_origin ?? null, 'errors' => $GLOBALS['log']->errors]")
        self.assertIsNone(observed["marker"], observed)
        self.assertEqual(observed["errors"], [], observed)

    # ------------------------------------------------------- static guarantees

    def test_neither_marker_vardef_declares_a_default(self):
        """The one constraint decision 72 carried in by name. A vardef default
        is what produced 994 fabricated 0.00 rows; empty must read empty."""
        for rel in (
            "custom/Extension/modules/Opportunities/Ext/Vardefs/bd_governing_origin.php",
            "custom/Extension/modules/bd01_ERP_Quote_Line/Ext/Vardefs/bd_governing_origin.php",
        ):
            with self.subTest(vardef=rel):
                source = (PKG / rel).read_text()
                body = source.split("$dictionary", 1)[1]
                self.assertNotRegex(body, r"['\"]default['\"]\s*=>", body)
                self.assertIn("'reportable' => true", body)

    def test_no_install_path_selects_anything(self):
        """The blast-radius guarantee, held statically so a later edit cannot
        quietly add a sweep to the install."""
        for script in ("post_install.php", "post_uninstall.php"):
            with self.subTest(script=script):
                source = (PKG / "scripts" / script).read_text()
                code = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
                code = re.sub(r"^\s*//.*$", "", code, flags=re.M)
                self.assertNotIn("BdGoverningAutoSelect", code, script)
                self.assertNotIn("bd_governing_backfill", code, script)

    def test_the_backfill_is_wired_to_nothing_and_defaults_to_a_dry_run(self):
        pack = (PKG / "pack.php").read_text()
        self.assertNotIn("bd_governing_backfill", pack)
        script = (PKG / "scripts/bd_governing_backfill.php").read_text()
        # The write is opt-in: the flag must be required, not merely honoured.
        self.assertIn("in_array('--apply', $argv ?? array(), true)", script)
        self.assertIn("DRY RUN", script)
        # And it must dry-run first even on an apply run, so the printed list
        # and the write are one decision rather than two.
        self.assertIn("$bdSelector->applyToQuote($bdErpQuote, true)", script)

    def test_the_hook_registration_gates_after_save_on_is_update(self):
        reg = (PKG / "custom/Extension/modules/bd01_ERP_Quote_Line/Ext/LogicHooks"
                     "/bd_governing_autoselect.php").read_text()
        self.assertIn("'autoSelectOnNewLine'", reg)
        self.assertIn("'autoSelectOnLink'", reg)
        hook = (PKG / "custom/modules/bd01_ERP_Quote_Line"
                      "/BdGoverningAutoSelectHook.php").read_text()
        body = hook.split("public function autoSelectOnNewLine", 1)[1].split("}", 1)[0]
        self.assertIn("if (!empty($arguments['isUpdate'])) {", body)
        # And the rollup funnel stays unwired - that is the path that would
        # have retro-selected every quote on the next connector resync.
        self.assertNotIn("BdGoverningAutoSelect", (
            PKG / "custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php"
        ).read_text().split("private function refreshGoverningOrigin", 1)[0])

    def test_the_reading_of_lowest_lives_in_exactly_one_comparison(self):
        """Flipping to lowest-unit-price must stay a one-line change, so the
        ladder-end choice has to be made in exactly one statement."""
        selector = (PKG / "custom/modules/bd01_ERP_Quote_Line"
                          "/BdGoverningAutoSelect.php").read_text()
        self.assertIn("public static function lowestTotalLine", selector)
        body = selector.split(
            "public static function lowestTotalLine", 1)[1].split(
            "\n    /**", 1)[0]
        code = re.sub(r"//.*$", "", body, flags=re.M)
        self.assertEqual(code.count("doc_ext_price"), 1, code)
        self.assertNotIn("doc_unit_price", code)
        # Nowhere else in the file may decide which end of the ladder wins.
        whole = re.sub(r"/\*.*?\*/", "", selector, flags=re.S)
        whole = re.sub(r"//.*$", "", whole, flags=re.M)
        self.assertNotIn("doc_unit_price", whole)
        # The other reading has to be named where the flip is made, or the
        # next person cannot know there was a choice.
        doc = selector.split("public static function lowestTotalLine", 1)[0]
        self.assertIn("lowest UNIT price", doc)
        self.assertIn("doc_unit_price", doc)


@unittest.skipUnless(shutil.which("php"), "requires a PHP CLI")
class AutoSelectedReportTest(unittest.TestCase):
    def report_def(self):
        result = subprocess.run(
            ["php", "-d", "error_reporting=E_ALL & ~E_DEPRECATED", "-r",
             "class SugarBean {} "
             "require '" + REL + "/custom/modules/Opportunities/BdAutoSelectedReport.php';"
             "echo json_encode((new BdAutoSelectedReport())->reportDef());"],
            cwd=WORKSPACE, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "", result.stderr)
        return json.loads(result.stdout)

    def test_the_report_filters_on_the_auto_marker_and_nothing_wider(self):
        """A report that quietly lost its filter would list the whole pipeline
        and read as a catastrophe rather than as a bug."""
        definition = self.report_def()
        self.assertEqual(definition["module"], "Opportunities")
        self.assertEqual(definition["report_type"], "tabular")
        primary = definition["filters_def"]["Filter_1"]["0"]
        self.assertEqual(primary["name"], "bd_governing_origin")
        self.assertEqual(primary["qualifier_name"], "equals")
        self.assertEqual(primary["input_name0"], "auto")
        self.assertEqual(primary["table_key"], "self")

    def test_the_filter_value_is_the_one_the_writer_actually_writes(self):
        """The report def holds the literal 'auto'; this is what keeps it and
        BdGoverningAutoSelect::ORIGIN_AUTO from drifting apart."""
        result = subprocess.run(
            ["php", "-r",
             "class SugarBean {} require '" + REL
             + "/custom/modules/bd01_ERP_Quote_Line/BdGoverningAutoSelect.php';"
             "echo BdGoverningAutoSelect::ORIGIN_AUTO;"],
            cwd=WORKSPACE, capture_output=True, text=True,
        )
        self.assertEqual(result.stdout, "auto", result.stderr)
        self.assertEqual(
            self.report_def()["filters_def"]["Filter_1"]["0"]["input_name0"],
            result.stdout,
        )

    def test_the_marker_is_a_visible_column_not_only_a_filter(self):
        columns = [c["name"] for c in self.report_def()["display_columns"]]
        self.assertIn("bd_governing_origin", columns)
        self.assertIn("amount", columns)

    def test_closed_deals_are_not_a_review_queue(self):
        closed = self.report_def()["filters_def"]["Filter_1"]["1"]
        self.assertEqual(closed["name"], "sales_stage")
        self.assertEqual(closed["qualifier_name"], "not_one_of")
        self.assertEqual(sorted(closed["input_name0"]), ["Closed Lost", "Closed Won"])

    def test_every_display_column_names_a_table_the_report_joins(self):
        """A column on a table_key absent from full_table_list renders blank."""
        definition = self.report_def()
        known = set(definition["full_table_list"].keys())
        for column in definition["display_columns"]:
            self.assertIn(column["table_key"], known, column)
        for key, row in definition["filters_def"]["Filter_1"].items():
            if key == "operator":
                continue
            self.assertIn(row["table_key"], known, row)


if __name__ == "__main__":
    unittest.main()
