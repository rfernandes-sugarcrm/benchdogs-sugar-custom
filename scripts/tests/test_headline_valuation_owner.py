"""Cross-package headline ownership regression using real PHP hook methods.

Run with PHP 8.2 and the sibling erp-integration-sugar checkout present. The
beans/relationships are isolated doubles: this never loads Sugar, connects to
an API/database, or creates Revenue Line Items. The public Bench refresh path
is real, including its mode/primary guards and deliverable computation.

These assertions express the accepted single-owner contract, not the current
bug. A single matching ERP line avoids deciding REQ-5 alternative-break policy.
Native tax/shipping materialization is explicitly pending: an earlier fake
save omitted SugarLogic and incorrectly suggested deployed corruption. Sugar
26.2 SugarBean::save calls updateCalculatedFields, so effective vardefs and
native model integration must be validated before changing recalculation.
"""

import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = ROOT.parent
SHARED_HOOK = (
    WORKSPACE / "erp-integration-sugar/sugar-sell/ERP-Core/src/custom/modules"
    / "Quotes/QuoteOpportunityAmount.php"
)
FIXTURE = r'''
#[AllowDynamicProperties]
class SugarBean {
    public $id = 'owned';
    public $saves = 0;
    public $field_defs = [];
    public $currency_id = '-99';
    public $base_rate = 1;
    public function load_relationship($name) {
        $GLOBALS['relationship_reads'][] = $name;
        return isset($this->$name);
    }
    public function save() {
        $this->saves++;
        // Simulate the registered shared after_save hook, not native
        // SugarLogic calculations (which real SugarBean::save does invoke).
        if ($this->id === 'owned-quote' && !empty($GLOBALS['quote_save_hook'])) {
            (new QuoteOpportunityAmount())->refresh($this, 'after_save');
        }
    }
}
class Opportunity extends SugarBean {
    // Shared ERP-Core owns this mode check. The Bench implementation is
    // independently scanned to prove that it never calls this method.
    public static function usingRevenueLineItems() { return false; }
}
class SugarConfig {
    public static function getInstance() { return new self(); }
    public function get($key, $default = null) {
        return $key === 'opps.view_by' ? 'Opportunities' : $default;
    }
}
class SugarCurrency {
    public static function convertAmount($amount, $from, $to) { return $amount * 2; }
}
class TestLink {
    public function __construct(public $beans = [], public $ids = []) {}
    public function get() { return $this->ids; }
    public function getBeans() { return $this->beans; }
}
class BeanFactory {
    public static $beans = [];
    public static function retrieveBean($module, $id, $options = []) {
        return self::$beans[$module][$id]
            ?? throw new Exception('Unexpected record read: ' . $module . '/' . $id);
    }
    public static function newBean($module) {
        throw new Exception('Unexpected record creation: ' . $module);
    }
}
$GLOBALS['log'] = new class {
    public $errors = [];
    public function info($message) {}
    public function warn($message) {}
    public function error($message) { $this->errors[] = $message; }
};
$GLOBALS['relationship_reads'] = [];
require 'benchdogs-sugar-custom/sugar-sell/BenchDogs-Ext/custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php';
require 'erp-integration-sugar/sugar-sell/ERP-Core/src/custom/modules/Quotes/QuoteOpportunityAmount.php';
$opp = new Opportunity();
$opp->id = 'owned-opportunity';
$opp->amount = 0;
$opp->currency_id = '-99';
$opp->sales_stage = 'Proposal/Price Quote';
$opp->date_closed = '2026-12-01';
$opp->field_defs = [];
$quote = new SugarBean();
$quote->id = 'owned-quote';
$quote->total = 280; // Stored native total: 250 line + 20 tax + 10 shipping.
$quote->tax = 20;
$quote->shipping = 10;
$quote->currency_id = '-99';
$quote->erp_is_primary_quote = true;
$quote->opportunities = new TestLink([], [$opp->id]);
$product = new SugarBean();
$product->id = 'owned-product';
$product->erp_quote_line_num = 1;
$product->quantity = 2;
$product->discount_price = 125;
$quote->products = new TestLink([$product]);
$erp = new SugarBean();
$erp->id = 'owned-erp-quote';
$erp->quote_num = 42;
$erp->sugar_quote_id = $quote->id;
$line = new SugarBean();
$line->id = 'owned-erp-line';
$line->line_num = 1;
$line->doc_ext_price = 250;
$line->selling_qty = 2;
$line->part_num = 'OWNED-PART';
$erp->bd01_erp_quote_lines = new TestLink([$line]);
BeanFactory::$beans = [
    'Opportunities' => [$opp->id => $opp],
    'Quotes' => [$quote->id => $quote],
];
$shared = new QuoteOpportunityAmount();
$bench = new BdQuoteReflectionHook();
$trace = [];
'''

MATERIALIZED = r'''
$GLOBALS['quote_save_hook'] = true;
$erp->sugar_quote_id = '';
$erp->bd_materialized_quote_id = $quote->id;
$erp->bd_materialize_status = 'materialized';  // legacy quote this package built
$line->name = 'Owned ERP line';
$line->doc_unit_price = 125;
$product->name = $line->name;
$bundle = new SugarBean();
$bundle->id = 'owned-bundle';
$bundle->products = new TestLink([$product]);
$quote->product_bundles = new TestLink([$bundle]);
'''


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
@unittest.skipUnless(SHARED_HOOK.is_file(), "requires sibling shared Sugar checkout")
class HeadlineValuationOwnerTest(unittest.TestCase):
    def execute(self, scenario, expected_errors=0):
        result = subprocess.run(
            ["php", "-r", FIXTURE + scenario + r'''
echo json_encode([
    'amount' => $opp->amount,
    'saves' => $opp->saves,
    'trace' => $trace,
    'quote_total' => $quote->total,
    'quote_saves' => $quote->saves,
    'stage' => $opp->sales_stage,
    'best_case' => $opp->best_case ?? null,
    'worst_case' => $opp->worst_case ?? null,
    'managed_value' => $opp->bd_forecast_managed_value ?? null,
    'best_origin' => $opp->bd_best_case_origin ?? null,
    'worst_origin' => $opp->bd_worst_case_origin ?? null,
    'relationship_reads' => $GLOBALS['relationship_reads'],
    'errors' => $GLOBALS['log']->errors,
]);
'''],
            cwd=WORKSPACE, capture_output=True, text=True, check=True,
        )
        self.assertEqual(result.stderr, "", result.stderr)
        observed = json.loads(result.stdout)
        self.assertEqual(len(observed["errors"]), expected_errors, observed)
        return observed

    def test_shared_owner_control_preserves_native_tax_and_shipping(self):
        observed = self.execute("$shared->refresh($quote);")
        self.assertEqual(observed["amount"], 280)
        self.assertEqual(observed["saves"], 1)

    def test_equal_subtotal_control_cannot_expose_the_competing_writer(self):
        observed = self.execute(r'''
$quote->total = 250;
$quote->tax = 0;
$quote->shipping = 0;
$shared->refresh($quote);
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 250)
        self.assertEqual(observed["saves"], 1)

    def test_nonprimary_control_does_not_take_headline_ownership(self):
        observed = self.execute(r'''
$quote->erp_is_primary_quote = false;
$opp->amount = 99;
$shared->refresh($quote);
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 99)
        self.assertEqual(observed["saves"], 0)

    def test_bench_refresh_cannot_replace_shared_native_total_with_subtotal(self):
        observed = self.execute(r'''
$shared->refresh($quote);
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 280, observed)

    def test_alternating_public_triggers_converge_without_headline_oscillation(self):
        observed = self.execute(r'''
$shared->refresh($quote);
$trace[] = $opp->amount;
$bench->refreshOpportunityAmount($erp);
$trace[] = $opp->amount;
$shared->refresh($quote);
$trace[] = $opp->amount;
''')
        self.assertEqual(observed["trace"], [280, 280, 280], observed)
        self.assertEqual(observed["saves"], 1, observed)

    def test_repriced_quote_cannot_be_reverted_by_old_erp_line_observation(self):
        observed = self.execute(r'''
$quote->total = 530;
$product->discount_price = 250;
$shared->refresh($quote);
// ERP reflection still holds 250 until its next sync; it is not the Quote total.
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 530, observed)

    def test_closed_lost_guard_is_not_undone_by_bench_refresh(self):
        observed = self.execute(r'''
$opp->sales_stage = 'Closed Lost';
$opp->amount = 99;
$shared->refresh($quote);
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 99, observed)
        self.assertEqual(observed["saves"], 0, observed)

    def test_opportunities_only_refresh_never_accesses_revenue_line_items(self):
        observed = self.execute("$bench->refreshOpportunityAmount($erp);")
        self.assertNotIn("revenuelineitems", observed["relationship_reads"])

    def test_ordered_release_does_not_give_bench_a_second_stage_writer(self):
        observed = self.execute(r'''
$product->erp_ordered = true;
$product->erp_quote_line_num = 1;
$line->prototype = true;
$opp->sales_stage = 'Proposal/Price Quote';
$opp->probability = 65;
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["stage"], "Proposal/Price Quote", observed)
        self.assertEqual(observed["amount"], 280, observed)

    def test_currency_converted_headline_is_not_replaced_by_unconverted_sum(self):
        observed = self.execute(r'''
$quote->currency_id = 'owned-other-currency';
$shared->refresh($quote);
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 560, observed)

    def test_bench_stage_and_independent_human_forecast_override_remain(self):
        observed = self.execute(r'''
$opp->sales_stage = 'Prospecting';
$opp->field_defs = ['best_case' => [], 'worst_case' => []];
$opp->best_case = 0;
$opp->worst_case = 75; // Human value: never infer permission to overwrite it.
$shared->refresh($quote);
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 280, observed)
        self.assertEqual(observed["stage"], "Proposal/Price Quote", observed)
        self.assertEqual(observed["best_case"], 280, observed)
        self.assertEqual(observed["worst_case"], 75, observed)
        self.assertEqual(observed["managed_value"], 280, observed)
        self.assertEqual(observed["best_origin"], "system", observed)
        self.assertEqual(observed["worst_origin"], "human", observed)

    def test_system_forecasts_follow_repriced_shared_headline_in_one_pass(self):
        observed = self.execute(r'''
$opp->field_defs = ['best_case' => [], 'worst_case' => []];
$opp->best_case = 0;
$opp->worst_case = 0;
$shared->refresh($quote);
$bench->refreshOpportunityAmount($erp);
$quote->total = 530;
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 530, observed)
        self.assertEqual(observed["best_case"], 530, observed)
        self.assertEqual(observed["worst_case"], 530, observed)
        self.assertEqual(observed["managed_value"], 530, observed)
        self.assertEqual(observed["best_origin"], "system", observed)
        self.assertEqual(observed["worst_origin"], "system", observed)

    def test_system_forecasts_reuse_shared_currency_conversion(self):
        observed = self.execute(r'''
$opp->field_defs = ['best_case' => [], 'worst_case' => []];
$opp->best_case = 0;
$opp->worst_case = 0;
$quote->currency_id = 'owned-other-currency';
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 560, observed)
        self.assertEqual(observed["best_case"], 560, observed)
        self.assertEqual(observed["worst_case"], 560, observed)
        self.assertEqual(observed["managed_value"], 560, observed)

    def test_human_takeover_of_one_case_does_not_freeze_the_other(self):
        observed = self.execute(r'''
$opp->field_defs = ['best_case' => [], 'worst_case' => []];
$opp->best_case = 0;
$opp->worst_case = 0;
$shared->refresh($quote);
$bench->refreshOpportunityAmount($erp);
// A person changes only Best after the system recorded exact provenance.
$opp->best_case = 999;
$quote->total = 530;
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 530, observed)
        self.assertEqual(observed["best_case"], 999, observed)
        self.assertEqual(observed["worst_case"], 530, observed)
        self.assertEqual(observed["managed_value"], 530, observed)
        self.assertEqual(observed["best_origin"], "human", observed)
        self.assertEqual(observed["worst_origin"], "system", observed)

    def test_unknown_provenance_never_authorizes_forecast_overwrite(self):
        observed = self.execute(r'''
$opp->field_defs = ['best_case' => [], 'worst_case' => []];
$opp->best_case = 999;
$opp->worst_case = 0;
$opp->bd_best_case_origin = 'foreign';
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 280, observed)
        self.assertEqual(observed["best_case"], 999, observed)
        self.assertEqual(observed["worst_case"], 280, observed)
        self.assertEqual(observed["best_origin"], "human", observed)
        self.assertEqual(observed["worst_origin"], "system", observed)

    def test_upgrade_adopts_legacy_all_options_value_then_converges(self):
        observed = self.execute(r'''
$opp->field_defs = ['best_case' => [], 'worst_case' => []];
$opp->amount = 7100.63;
$opp->best_case = 250;
$opp->worst_case = 250;
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["amount"], 280, observed)
        self.assertEqual(observed["best_case"], 280, observed)
        self.assertEqual(observed["worst_case"], 280, observed)
        self.assertEqual(observed["best_origin"], "system", observed)
        self.assertEqual(observed["worst_origin"], "system", observed)

    def test_materialization_public_trigger_refreshes_shared_headline(self):
        observed = self.execute(MATERIALIZED + r'''
$quote->total = 0;
$quote->tax = 0;
$quote->shipping = 0;
$bench->refreshOpportunityAmount($erp);
''')
        self.assertEqual(observed["quote_total"], 250, observed)
        self.assertEqual(observed["amount"], 250, observed)
        self.assertGreater(observed["quote_saves"], 0, observed)

    @unittest.skip(
        "UNVERIFIED: native materialization tax/shipping needs real SugarLogic "
        "and effective vardefs; the isolated save double cannot prove it"
    )
    def test_native_materialization_preserves_calculated_tax_and_shipping(self):
        # Required integration obligation, not a passed assertion or a known
        # product failure. Exercise the public custom refresh on real native
        # beans, re-read Quote/bundle/Opportunity and check native tax, shipping,
        # discounts and currency. Do not replace SugarLogic with a guessed sum.
        pass


if __name__ == "__main__":
    unittest.main()
