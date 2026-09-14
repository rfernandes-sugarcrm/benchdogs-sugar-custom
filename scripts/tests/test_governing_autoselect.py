"""Decision 72: when NOTHING is governing, auto-select the LOWEST TOTAL break.

Decision 72 (user, 2026-09-14, verbatim: *"1. if nothing is selcted take the
lowest amount 2. just fix it to select one"*, then *"and mark it as selected so
you can have an esmiation of the opertunity dont leave it blank to begin with"*)
AMENDS decision 29. Decision 29 required the valuation to REFUSE while zero
lines were governing; decision 72 replaces that refusal with an auto-selection
that genuinely sets `governing = 1` on one line and marks the result as
machine-chosen.

WHAT DECISION 72 DID **NOT** CHANGE, and what this file therefore still pins:

  * TWO OR MORE governing selections STILL FAIL CLOSED. `applyToQuote()` must
    refuse to "simplify" an ambiguous quote down to one, and
    `ErpQuoteOpportunityContribution::resolve()` must still throw on it. The
    D29-R1 atomic-selection fix (0577bc0) is not touched by this change and
    `test_governing_concurrency.py` remains its regression proof.
  * A HUMAN selection is never overridden, never re-pointed, never demoted by
    the machine - only by another person.
  * An unreadable amount is still not a zero. Decision 59's 994 fabricated
    `0.00` rows came from treating an absent number as a number.

"LOWEST" == LOWEST TOTAL, USER-CONFIRMED
----------------------------------------
On Kinetic quote 1193 the breaks are 6,400 / 8,400 / 9,600 for 50 / 75 / 100
units - so the unit prices run the other way: 128.00 / 112.00 / 96.00. The two
readings of "lowest" therefore select OPPOSITE ENDS of the same ladder:

    lowest TOTAL      -> the 50-unit break,  6,400   <-- implemented, confirmed
    lowest UNIT PRICE -> the 100-unit break, 9,600       exists, NOT implemented

Every fixture below keeps that inversion, so a lowest-unit-price implementation
does not merely fail these tests, it fails them by landing on 9,600. That is
what makes the assertions non-vacuous: they discriminate between the two
readings rather than merely between "some number" and "no number".
"""

import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = ROOT.parent
PACKAGE = "benchdogs-sugar-custom/sugar-sell/BenchDogs-Ext"
SELECTOR = PACKAGE + "/custom/modules/bd01_ERP_Quote_Line/BdGoverningAutoSelect.php"
PROVIDER = PACKAGE + "/custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php"

FIXTURE = r'''
#[AllowDynamicProperties]
class SugarBean {
    public $id = '';
    public $deleted = 0;
    public $saves = 0;
    public function load_relationship($name) { return isset($this->$name); }
    public function save() {
        $this->saves++;
        $GLOBALS['row_writes'][] = [
            'id' => $this->id,
            'governing' => (int) !empty($this->governing),
            'origin' => (string) ($this->bd_governing_origin ?? ''),
        ];
    }
}
class TestLink {
    public function __construct(public $beans = []) {}
    public function get() { return array_map(fn($b) => $b->id, $this->beans); }
    public function getBeans() { return $this->beans; }
}
$GLOBALS['row_writes'] = [];
$GLOBALS['log'] = new class {
    public $errors = [];
    public $warns = [];
    public $infos = [];
    public function info($m) { $this->infos[] = $m; }
    public function warn($m) { $this->warns[] = $m; }
    public function error($m) { $this->errors[] = $m; }
    public function fatal($m) {}
};

// Kinetic quote 1193. `total` is the extended price of the break; `unit` runs
// the OTHER WAY, which is the whole point - see this module's docstring.
$make = function ($id, $total, $qty, $unit, $governing = false, $prototype = false,
                  $origin = '', $lineNum = 0) {
    $row = new SugarBean();
    $row->id = $id;
    $row->doc_ext_price = $total;
    $row->selling_qty = $qty;
    $row->doc_unit_price = $unit;
    $row->governing = $governing;
    $row->prototype = $prototype;
    $row->bd_governing_origin = $origin;
    $row->line_num = $lineNum;
    return $row;
};
$breaks = function () use ($make) {
    return [
        $make('break100', 9600, 100, 96,  false, false, '', 3),
        $make('break50',  6400, 50,  128, false, false, '', 1),
        $make('break75',  8400, 75,  112, false, false, '', 2),
    ];
};
$erpQuote = function ($lines) {
    $erp = new SugarBean();
    $erp->id = 'erp-1193';
    $erp->quote_num = 1193;
    $erp->bd01_erp_quote_lines = new TestLink($lines);
    return $erp;
};
$sugarQuote = function ($erp) {
    $q = new SugarBean();
    $q->id = 'sugar-quote';
    $q->tax = 0;
    $q->shipping = 0;
    $q->total = 24400;
    $q->bd01_erp_quote_quotes = new TestLink([$erp]);
    return $q;
};

// The change under test may not exist yet. Load it if it does, so the RED run
// reports the CURRENT product behaviour instead of a missing-file fatal.
$selector = 'SELECTOR_PATH';
$haveSelector = file_exists($selector);
if ($haveSelector) { require $selector; }
require 'PROVIDER_PATH';

// One call site for the whole suite: apply the amendment if it is present,
// otherwise leave the quote exactly as rc26 left it.
$autoSelect = function ($erp) use ($haveSelector) {
    if (!$haveSelector) { return ['action' => 'not-implemented']; }
    return (new BdGoverningAutoSelect())->applyToQuote($erp);
};
$valuation = function ($quote) {
    try {
        return ['value' => (new ErpQuoteOpportunityContribution())->resolve($quote),
                'error' => null];
    } catch (Throwable $e) {
        return ['value' => null, 'error' => $e->getMessage()];
    }
};
$selectedIds = function ($lines) {
    $out = [];
    foreach ($lines as $l) { if (!empty($l->governing)) { $out[] = $l->id; } }
    sort($out);
    return $out;
};
$originOf = function ($lines, $id) {
    foreach ($lines as $l) { if ($l->id === $id) { return (string) ($l->bd_governing_origin ?? ''); } }
    return null;
};
'''


@unittest.skipUnless(shutil.which("php"), "requires a PHP CLI")
class GoverningAutoSelectTest(unittest.TestCase):
    def execute(self, scenario):
        fixture = FIXTURE.replace("SELECTOR_PATH", SELECTOR).replace(
            "PROVIDER_PATH", PROVIDER
        )
        result = subprocess.run(
            ["php", "-d", "error_reporting=E_ALL & ~E_DEPRECATED",
             "-r", fixture + scenario + r'''
echo json_encode([
    'action' => $result['action'] ?? null,
    'line_id' => $result['line_id'] ?? null,
    'amount' => $result['amount'] ?? null,
    'from_line_id' => $result['from_line_id'] ?? null,
    'selected' => $selectedIds($lines),
    'origin_of_selected' => count($selectedIds($lines)) === 1
        ? $originOf($lines, $selectedIds($lines)[0]) : null,
    'value' => $valued['value'] ?? null,
    'error' => $valued['error'] ?? null,
    'classified' => $classified ?? null,
    'row_writes' => $GLOBALS['row_writes'],
    'log_errors' => $GLOBALS['log']->errors,
]);
'''],
            cwd=WORKSPACE, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "", result.stderr)
        return json.loads(result.stdout)

    # ---------------------------------------------------------------- the rule

    def test_zero_selected_auto_selects_the_lowest_total_break_and_values_it(self):
        """THE decision-72 case. rc26 refuses here; the amendment selects 6,400.

        6,400 is the lowest TOTAL. 9,600 is the lowest UNIT price. An
        implementation that read "lowest" the other way lands on 9,600 and
        fails this test with a number, not with an exception - which is what
        makes it a discriminating assertion.
        """
        observed = self.execute(r'''
$lines = $breaks();
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$result = $autoSelect($erp);
$valued = $valuation($quote);
''')
        self.assertEqual(observed["action"], "selected", observed)
        self.assertEqual(observed["line_id"], "break50", observed)
        self.assertEqual(observed["selected"], ["break50"], observed)
        self.assertEqual(observed["value"], 6400, observed)
        self.assertIsNone(observed["error"], observed)

    def test_the_selection_is_marked_as_machine_chosen_not_left_blank(self):
        observed = self.execute(r'''
$lines = $breaks();
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$result = $autoSelect($erp);
$valued = $valuation($quote);
$classified = BdGoverningAutoSelect::classify($erp);
''')
        self.assertEqual(observed["origin_of_selected"], "auto", observed)
        self.assertEqual(observed["classified"], "auto", observed)

    def test_lowest_total_is_one_named_function_and_it_picks_the_50_unit_break(self):
        """The reading lives in exactly one place, so flipping it is one line."""
        observed = self.execute(r'''
$lines = $breaks();
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$picked = BdGoverningAutoSelect::lowestTotalLine($lines);
$result = ['action' => 'direct', 'line_id' => $picked->id,
           'amount' => $picked->doc_ext_price];
$valued = ['value' => null, 'error' => null];
''')
        self.assertEqual(observed["line_id"], "break50", observed)
        self.assertEqual(observed["amount"], 6400, observed)

    def test_equal_totals_break_the_tie_deterministically_by_line_number(self):
        """Two refreshes must not oscillate between equally cheap breaks."""
        observed = self.execute(r'''
$lines = [$make('second', 6400, 50, 128, false, false, '', 7),
          $make('first',  6400, 50, 128, false, false, '', 4)];
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$result = $autoSelect($erp);
$valued = $valuation($quote);
''')
        self.assertEqual(observed["line_id"], "first", observed)

    # -------------------------------------------- decision 29 clauses that bind

    def test_two_governing_selections_still_fail_closed(self):
        """D29's other half. The machine must NOT resolve an ambiguity."""
        observed = self.execute(r'''
$lines = $breaks();
$lines[0]->governing = true;   // break100
$lines[1]->governing = true;   // break50
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$result = $autoSelect($erp);
$valued = $valuation($quote);
''')
        self.assertEqual(observed["action"], "ambiguous", observed)
        self.assertEqual(observed["selected"], ["break100", "break50"], observed)
        self.assertIsNone(observed["value"], observed)
        self.assertEqual(
            observed["error"],
            "Exactly one governing production option is required",
            observed,
        )
        self.assertEqual(observed["row_writes"], [], observed)

    def test_a_human_selection_is_never_re_pointed_at_a_cheaper_break(self):
        observed = self.execute(r'''
$lines = $breaks();
$lines[0]->governing = true;            // break100, chosen by a person
$lines[0]->bd_governing_origin = '';    // no marker: a person put it there
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$result = $autoSelect($erp);
$valued = $valuation($quote);
$classified = BdGoverningAutoSelect::classify($erp);
''')
        self.assertEqual(observed["action"], "human", observed)
        self.assertEqual(observed["selected"], ["break100"], observed)
        self.assertEqual(observed["value"], 9600, observed)
        self.assertEqual(observed["classified"], "human", observed)
        self.assertEqual(observed["row_writes"], [], observed)

    def test_an_unreadable_amount_is_refused_not_treated_as_the_cheapest(self):
        """Decision 59: an absent number is not a number. It must not win."""
        for missing in ("null", "''", "'n/a'"):
            with self.subTest(missing=missing):
                observed = self.execute(r'''
$lines = $breaks();
$lines[0]->doc_ext_price = ''' + missing + r''';
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
try { $result = $autoSelect($erp); }
catch (Throwable $e) { $result = ['action' => 'refused:' . $e->getMessage()]; }
$valued = $valuation($quote);
''')
                self.assertEqual(observed["selected"], [], observed)
                self.assertEqual(observed["row_writes"], [], observed)
                self.assertNotEqual(observed["action"], "selected", observed)

    def test_a_prototype_line_is_never_the_auto_selected_production_option(self):
        observed = self.execute(r'''
$lines = [$make('proto', 500, 1, 500, false, true, '', 0),
          $make('break50', 6400, 50, 128, false, false, '', 1)];
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$result = $autoSelect($erp);
$valued = $valuation($quote);
''')
        self.assertEqual(observed["line_id"], "break50", observed)
        self.assertEqual(observed["selected"], ["break50"], observed)
        self.assertEqual(observed["value"], 6900, observed)

    def test_a_prototype_only_quote_selects_nothing_and_stays_refused(self):
        observed = self.execute(r'''
$lines = [$make('proto', 500, 1, 500, false, true, '', 0)];
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$result = $autoSelect($erp);
$valued = $valuation($quote);
$classified = BdGoverningAutoSelect::classify($erp);
''')
        self.assertEqual(observed["action"], "no-candidates", observed)
        self.assertEqual(observed["selected"], [], observed)
        self.assertEqual(observed["row_writes"], [], observed)
        self.assertEqual(observed["classified"], "", observed)

    # --------------------------------------------------------- convergence

    def test_an_auto_selection_follows_a_cheaper_break_that_arrives_later(self):
        """The connector creates lines ONE AT A TIME, so the first line to
        arrive is not the cheapest. An auto-selection must therefore re-point
        itself as siblings appear - and only an auto-selection may."""
        observed = self.execute(r'''
$lines = $breaks();
$lines[0]->governing = true;                 // break100 arrived first
$lines[0]->bd_governing_origin = 'auto';
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$result = $autoSelect($erp);
$valued = $valuation($quote);
''')
        self.assertEqual(observed["action"], "reselected", observed)
        self.assertEqual(observed["from_line_id"], "break100", observed)
        self.assertEqual(observed["line_id"], "break50", observed)
        self.assertEqual(observed["selected"], ["break50"], observed)
        self.assertEqual(observed["value"], 6400, observed)

    def test_re_running_on_a_settled_auto_selection_writes_nothing(self):
        """Idempotence. Every trigger converges to the same rows, so a resave
        storm cannot churn commercial data."""
        observed = self.execute(r'''
$lines = $breaks();
$lines[1]->governing = true;                 // break50 already chosen
$lines[1]->bd_governing_origin = 'auto';
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$autoSelect($erp);
$result = $autoSelect($erp);
$valued = $valuation($quote);
''')
        self.assertEqual(observed["action"], "unchanged", observed)
        self.assertEqual(observed["selected"], ["break50"], observed)
        self.assertEqual(observed["row_writes"], [], observed)

    # ------------------------------------------------------------ the kill switch

    def test_the_operator_switch_restores_rc26_behaviour_exactly(self):
        observed = self.execute(r'''
BdGoverningAutoSelect::$enabledOverride = false;
$lines = $breaks();
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$result = $autoSelect($erp);
$valued = $valuation($quote);
''')
        self.assertEqual(observed["action"], "disabled", observed)
        self.assertEqual(observed["selected"], [], observed)
        self.assertEqual(observed["row_writes"], [], observed)
        self.assertEqual(
            observed["error"],
            "Exactly one governing production option is required",
            observed,
        )

    def test_a_dry_run_reports_the_selection_without_writing_a_row(self):
        """The backfill's default mode. Nothing is written; the verdict is
        identical to what the armed run would do."""
        observed = self.execute(r'''
$lines = $breaks();
$erp = $erpQuote($lines);
$quote = $sugarQuote($erp);
$result = (new BdGoverningAutoSelect())->applyToQuote($erp, true);
$valued = $valuation($quote);
''')
        self.assertEqual(observed["action"], "selected", observed)
        self.assertEqual(observed["line_id"], "break50", observed)
        self.assertEqual(observed["amount"], 6400, observed)
        self.assertEqual(observed["row_writes"], [], observed)
        self.assertEqual(observed["selected"], [], observed)
        self.assertEqual(
            observed["error"],
            "Exactly one governing production option is required",
            observed,
        )


if __name__ == "__main__":
    unittest.main()
