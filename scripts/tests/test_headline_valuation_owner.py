"""ERP-Core owns the primary Quote's Opportunity headline amount.

Run with PHP 8.2 and the sibling erp-integration-sugar checkout present. The
beans/relationships are isolated doubles: this never loads Sugar, connects to
an API/database, or creates Revenue Line Items.

WHAT THIS FILE USED TO BE, AND WHY IT SHRANK
--------------------------------------------
It was a CROSS-PACKAGE ownership regression: ERP-Core's ``QuoteOpportunityAmount``
against Bench's competing writer ``BdQuoteReflectionHook::refreshOpportunityAmount``,
proving the shared owner's number survived the Bench one. Decision 901/903 retired
the ``bd01_*`` quote mirror and 🔒 1044 retired the Bench estimating layer with it,
so ``BdQuoteReflectionHook`` no longer exists and Bench has no second writer to
lose to. Every assertion of the form "the Bench refresh cannot replace the shared
total" was deleted rather than repointed: there is no second writer to constrain,
and a test that constrains nothing reads as coverage without being any.

Deleted with it were the forecast-provenance assertions (``best_case`` /
``worst_case`` / ``bd_forecast_managed_value`` / ``bd_*_origin``). Their writer was
the same retired Bench hook. ``bd_forecast_managed_value`` in particular is an
ORPHAN - ERP-Core's ``QuoteOpportunityAmount.php`` contains zero references to any
``bd_`` field - so asserting it equals the headline was asserting behaviour that
nothing implements.

WHAT REMAINS is the half that has a live owner: ERP-Core publishes the headline
from the primary Quote's own native total, converts currency through
``SugarCurrency``, and refuses to touch a closed or non-primary Opportunity.

Native tax/shipping materialization was an obligation of the retired Bench
materialization path and went with it; ERP-Core reads the Quote's stored
``total``, which Sugar has already calculated.

NOTE ON THE CONTRIBUTION CONTRACT: ``QuoteOpportunityAmount::contribution()``
probes ``file_exists('custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php')``
relative to the CWD. These tests run with ``cwd=WORKSPACE``, so that probe misses
and the writer falls back to ``(float) $quote->total`` - which is exactly the
behaviour under test here. The provider itself is exercised directly, with its own
doubles, in ``test_governing_contribution.py``.
"""

import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = ROOT.parent
# This checkout's own directory name. The PHP below runs with cwd=WORKSPACE so it
# can require BOTH this repo and the sibling erp-integration-sugar checkout by
# path. Hardcoding "benchdogs-sugar-custom" made every such test read whatever
# tree happened to carry that name - a git worktree ran its own tests against a
# DIFFERENT commit's source and could not see its own changes.
REPO = ROOT.name
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
    // Shared ERP-Core owns this mode check.
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
    // Creation stays refused by default: the shared writer must never create a
    // record. A consumer that legitimately needs a seed bean (the contribution
    // provider seeds a Products query) opts in per module, so the guard still
    // holds everywhere it is not explicitly waived.
    public static function newBean($module) {
        if (in_array($module, $GLOBALS['new_bean_modules'] ?? [], true)) {
            return new SugarBean();
        }
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
$GLOBALS['new_bean_modules'] = [];
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
BeanFactory::$beans = [
    'Opportunities' => [$opp->id => $opp],
    'Quotes' => [$quote->id => $quote],
];
$shared = new QuoteOpportunityAmount();
$trace = [];
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

    def test_shared_owner_preserves_native_tax_and_shipping(self):
        """The headline is the Quote's stored total, which already carries tax
        and shipping - not a re-derived sum of the lines."""
        observed = self.execute("$shared->refresh($quote);")
        self.assertEqual(observed["amount"], 280)
        self.assertEqual(observed["saves"], 1)

    def test_nonprimary_quote_does_not_take_headline_ownership(self):
        observed = self.execute(r'''
$quote->erp_is_primary_quote = false;
$opp->amount = 99;
$shared->refresh($quote);
''')
        self.assertEqual(observed["amount"], 99)
        self.assertEqual(observed["saves"], 0)

    def test_repriced_quote_publishes_the_new_total(self):
        observed = self.execute(r'''
$quote->total = 530;
$product->discount_price = 250;
$shared->refresh($quote);
''')
        self.assertEqual(observed["amount"], 530, observed)

    def test_closed_lost_opportunity_is_never_revalued(self):
        observed = self.execute(r'''
$opp->sales_stage = 'Closed Lost';
$opp->amount = 99;
$shared->refresh($quote);
''')
        self.assertEqual(observed["amount"], 99, observed)
        self.assertEqual(observed["saves"], 0, observed)

    def test_headline_is_currency_converted_not_published_raw(self):
        observed = self.execute(r'''
$quote->currency_id = 'owned-other-currency';
$shared->refresh($quote);
''')
        self.assertEqual(observed["amount"], 560, observed)


if __name__ == "__main__":
    unittest.main()
