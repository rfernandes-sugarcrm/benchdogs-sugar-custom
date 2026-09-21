"""The Opportunity headline amount is the primary quote's own total (🔒 708).

Owner, verbatim 2026-09-20: *"now opprtuntiy rollup is simple no need for
cgoveringing line its what ever is in the quote right???/ no more selected
wired logic...."* and *"add taht as a gap to fix on how to simply calaualted
oppetunity roll up now form the primairy quote."*

WHAT THESE SCENARIOS USED TO ASSERT, AND WHY THEY WERE INVERTED
---------------------------------------------------------------
``ErpQuoteOpportunityContribution`` used to sum ``subtotal`` over the lines
whose ``erp_total_role`` is ``counts`` and add ``$quote->tax`` and
``$quote->shipping`` on top — and, before any of that, refuse a quote carrying
more than one ``erp_governing`` line.

🚩 **G97, measured live on Ophir 2026-09-19: that refusal cost −$1,848.00.**
Opportunity ``3e9ef3f8`` / quote ``1250`` has TWO ladder groups
(``EPIC06__1250_1_1`` and ``EPIC06__1250_2_1``), each with its own legitimately
governing rung, 2,016.00 + 69.30 = **2,085.30** = the quote total. Two
governing lines on a quote with two price-break parts is the NORMAL shape, not
a selection bug. The per-quote count hit ``2 > 1``, threw,
``QuoteOpportunityAmount::refresh()`` caught and logged, and ``amount`` stayed
frozen at 237.30 while the PF rollup wrote ``erp_open_amount = 2,085.30``
beside it.

The quote total was correct the whole time, so 🔒 708 deletes the check rather
than making it per-ladder-group. ``test_the_provider_no_longer_reads_the_line_level_money``
below is the anti-resurrection pin: it fails if ``erp_governing`` or a
``subtotal`` sum comes back into the file.

THE SAFETY PREMISE, verified in the vardef rather than assumed
---------------------------------------------------------------
``ERP-Epicor-QuantityAlternatives`` sets
``$dictionary['Product']['fields']['subtotal']['formula']`` to
``ifElse(equal($erp_total_role, "alternative"), 0, …)`` — calculated and
enforced — and ``Quotes.total`` is an equally enforced
``currencyAdd(rollupCurrencySum($product_bundles,"new_sub"), $tax, $shipping)``.
So an unselected rung contributes ZERO to the quote total by the platform's own
arithmetic: "sum the quote" does not sum the ladder.

THE ONE REFUSAL THAT SURVIVES
------------------------------
A quote whose every ERP line is still ``alternative`` totals 0.00 *by
construction*. Quote 1049 is the live control — 4 lines, all alternative, and
an admin typed ``amount = 23.00`` on 09-18. A naive "amount = total" overwrites
that with 0.00, so the provider throws instead, which is what makes ERP-Core
preserve the existing figure. See ``test_every_rung_still_alternative_refuses_rather_than_publishing_zero``.
"""

import json
from pathlib import Path
import shutil
import subprocess
import unittest
import zipfile

from test_headline_valuation_owner import FIXTURE, REPO, SHARED_HOOK, WORKSPACE


PROVIDER = (
    f"{REPO}/sugar-sell/BenchDogs-Ext/custom/modules/Quotes/"
    "ErpQuoteHooks/OpportunityContribution.php"
)

# The provider seeds a Products bean and issues a real SugarQuery. This double
# APPLIES the two conditions rather than returning every row, because a double
# that ignores the filter would pass whether or not the provider scoped its read
# at all - the scenarios below include a line on a different quote and a line
# with no role precisely to prove the filter runs.
QUERY_DOUBLE = r'''
$GLOBALS['new_bean_modules'] = ['Products'];
class SugarQueryWhere {
    public $equals = [];
    public $not_equals = [];
    public function equals($field, $value) { $this->equals[$field] = $value; return $this; }
    public function notEquals($field, $value) { $this->not_equals[$field] = $value; return $this; }
}
class SugarQuery {
    private $where;
    public function __construct() { $this->where = new SugarQueryWhere(); }
    public function select($fields) { return $this; }
    public function from($bean) { return $this; }
    public function where() { return $this->where; }
    public function execute() {
        $rows = [];
        foreach ($GLOBALS['products'] as $product) {
            foreach ($this->where->equals as $field => $value) {
                if (($product->$field ?? null) != $value) { continue 2; }
            }
            foreach ($this->where->not_equals as $field => $value) {
                if (($product->$field ?? null) == $value) { continue 2; }
            }
            $rows[] = ['id' => $product->id];
        }
        $GLOBALS['queried'] = count($rows);
        return $rows;
    }
}
// $make builds a native quote line. A line that "counts" is in the quote's
// money; an unselected rung is 'alternative' and the enforced subtotal formula
// has already zeroed it, so the QUOTE TOTAL already excludes it.
//
// `$subtotal` is still carried on the double even though the provider no
// longer reads it: the scenarios state what Sugar rolled up, so a reader can
// check the `$quote->total` each one sets against the lines that produced it.
$make = function ($id, $subtotal, $governing = false, $role = 'counts',
                  $quote_id = 'owned-quote') {
    $row = new SugarBean();
    $row->id = $id;
    $row->quote_id = $quote_id;
    $row->subtotal = $subtotal;
    $row->erp_governing = $governing;
    $row->erp_total_role = $role;
    return $row;
};
$publish = function (array $lines) {
    $GLOBALS['products'] = $lines;
    $beans = BeanFactory::$beans;
    $beans['Products'] = [];
    foreach ($lines as $row) { $beans['Products'][$row->id] = $row; }
    BeanFactory::$beans = $beans;
};
$GLOBALS['products'] = [];
'''


# The provider under test require()s core's QuoteOpportunityAmount.php out of
# the sibling erp-integration-sugar checkout. That file is resolved through
# shared_sugar, which falls back to a pinned copy, so these scenarios RUN in
# CI instead of skipping - they died `Failed opening required
# ...QuoteOpportunityAmount.php`, returncode 255, the moment CI started
# running the whole suite instead of one file.
@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class GoverningContributionTest(unittest.TestCase):
    def execute(self, scenario):
        result = subprocess.run(
            ["php", "-r", FIXTURE + QUERY_DOUBLE
             + "require '" + PROVIDER + "';" + scenario + r'''
echo json_encode(['value' => $value ?? null, 'error' => $error ?? null,
    'line_count' => $GLOBALS['queried'] ?? null,
    'quote_total' => $quote->total ?? null]);
'''], cwd=WORKSPACE, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    # ── 🔒 708: the answer is the quote's own total ───────────────────────
    def test_the_headline_is_the_quotes_own_stored_total(self):
        """The 641e586c shape: a prototype that counts, a governing rung, and
        an unselected rung the enforced formula has already zeroed. Sugar rolled
        that up to 4,530.00 and that figure - not a re-derived line sum - is
        what the provider hands back."""
        observed = self.execute(r'''
$publish([$make('selected', 4000, true),
          $make('alternative', 0, false, 'alternative'),
          $make('prototype', 500)]);
$quote->total = 4530;   // 4000 + 500 lines, + 20 tax + 10 shipping
$quote->tax = 20;
$quote->shipping = 10;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["value"], 4530)
        self.assertEqual(observed["line_count"], 3)
        self.assertEqual(observed["quote_total"], 4530)

    def test_g97_two_ladder_groups_each_with_their_own_governing_line(self):
        """🚩 THE DEFECT, AS A TEST. Quote 1250, measured live: two ladder
        groups, each governing its own rung, 2,016.00 + 69.30 = 2,085.30. The
        old per-quote `governingCount > 1` threw here and froze `amount` at
        237.30 - a −$1,848.00 error on a quote whose total was right.

        Two governing lines is what a quote with two price-break parts LOOKS
        like. Nothing counts them any more, so this publishes."""
        observed = self.execute(r'''
$publish([$make('epic06-1250-1', 2016, true),
          $make('epic06-1250-1-rung2', 0, false, 'alternative'),
          $make('epic06-1250-2', 69.30, true),
          $make('epic06-1250-2-rung2', 0, false, 'alternative')]);
$quote->total = 2085.30;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["value"], 2085.30, observed)
        self.assertEqual(observed["line_count"], 4, observed)

    def test_a_bundle_discount_is_honoured_because_the_total_already_carries_it(self):
        """The stored total is BELOW the sum of the line subtotals, because
        `ProductBundles.new_sub` is `subtotal - deal_tot`. The old per-line sum
        could not see that and over-stated every discounted quote; taking the
        quote's own figure cannot."""
        observed = self.execute(r'''
$publish([$make('a', 3600.25, true), $make('b', 1000)]);
$quote->total = 4100.25;   // 4600.25 of line money, less a 500.00 bundle discount
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["value"], 4100.25, observed)

    def test_the_read_is_scoped_to_this_quote_and_to_lines_carrying_a_role(self):
        """Negative control for the query itself. A line belonging to another
        quote and a line with no ERP role must both be invisible here - if the
        provider ever drops a condition this is what notices. The population
        read still decides applicable / not-yet-priced even though it no longer
        decides the money."""
        observed = self.execute(r'''
$publish([$make('mine', 4000, true),
          $make('other-quote', 9999, true, 'counts', 'someone-elses-quote'),
          $make('no-role', 8888, false, '')]);
$quote->total = 4000;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["line_count"], 1, observed)
        self.assertEqual(observed["value"], 4000, observed)

    def test_switching_selection_moves_the_total_and_the_provider_follows(self):
        observed = self.execute(r'''
$publish([$make('first', 0, false, 'alternative'), $make('second', 5250, true)]);
$quote->total = 5250;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["value"], 5250)
        self.assertEqual(observed["line_count"], 2)

    # ── the two states that are not a number ──────────────────────────────
    def test_native_quote_with_no_erp_lines_is_not_applicable(self):
        """A native/estimating draft. ERP-Core must leave the Opportunity alone
        rather than write a zero over a human's number."""
        observed = self.execute(r'''
$publish([]);
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertIsNone(observed["value"])

    def test_every_rung_still_alternative_refuses_rather_than_publishing_zero(self):
        """🛑 QUOTE 1049, THE LIVE CONTROL. 4 lines, all `alternative`, so the
        enforced formula zeroes every one and the quote total is 0.00 BY
        CONSTRUCTION - the absence of a price, not a measurement of one. An
        admin set `amount = 23.00` by hand on 09-18.

        The provider must THROW, not return null and not return 0.0: throwing
        is what makes `QuoteOpportunityAmount::refresh()` catch, log and write
        nothing, so the 23.00 survives. Returning null would fall through to
        `(float) $quote->total` and destroy it.

        The refusal must also not mention governing lines - nothing counts them
        after 🔒 708."""
        observed = self.execute(r'''
$publish([$make('a', 0, false, 'alternative'), $make('b', 0, false, 'alternative'),
          $make('c', 0, false, 'alternative'), $make('d', 0, false, 'alternative')]);
$quote->total = 0;
try { $value = (new ErpQuoteOpportunityContribution())->resolve($quote); }
catch (UnexpectedValueException $e) { $error = $e->getMessage(); }
''')
        self.assertIsNone(observed["value"], observed)
        self.assertIn("not yet priced", observed["error"] or "", observed)
        self.assertNotIn("governing", (observed["error"] or "").lower(), observed)

    def test_a_genuinely_zero_priced_quote_still_publishes_zero(self):
        """The counterpart the refusal above must not swallow. A line that
        COUNTS at 0.00 is a measurement - a free-of-charge item - and 0.00 is
        the right answer for it. Only an all-`alternative` quote is unpriced."""
        observed = self.execute(r'''
$publish([$make('freebie', 0, true)]);
$quote->total = 0;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["value"], 0, observed)

    def test_an_unavailable_quote_total_is_not_fabricated_as_zero(self):
        """A sparse or stale Quote read is not evidence of a zero-value deal.
        Throwing preserves the prior Opportunity figure through the shared
        failure boundary; casting null to 0.0 would publish a lost deal."""
        for mutation in ("unset($quote->total);", "$quote->total = null;",
                         "$quote->total = 'n/a';"):
            with self.subTest(mutation=mutation):
                observed = self.execute(r'''
$publish([$make('selected', 4000, true), $make('prototype', 500)]);
''' + mutation + r'''
try { $value = (new ErpQuoteOpportunityContribution())->resolve($quote); }
catch (UnexpectedValueException $e) { $error = $e->getMessage(); }
''')
                self.assertIsNone(observed["value"], observed)
                self.assertTrue(observed["error"], observed)

    # ── anti-resurrection ─────────────────────────────────────────────────
    def test_the_provider_no_longer_reads_the_line_level_money(self):
        """🔒 708 / §DG. The G97 defect was a CHECK, not an arithmetic, and the
        cheapest way for it to come back is for someone to reinstate the flag
        it counted. `erp_governing` and the per-line `subtotal` sum are both
        gone from the file, and this fails the moment either returns.

        Asserted on the SOURCE rather than on behaviour because a reinstated
        count is invisible until a quote with two price-break parts exists on
        the tenant - which is exactly how it survived to cost $1,848."""
        source = (WORKSPACE / PROVIDER).read_text()
        code = "\n".join(
            line for line in source.splitlines()
            if not line.lstrip().startswith(("*", "/*", "//", "*/"))
        )
        self.assertNotIn("erp_governing", code, "the governing flag is read again")
        self.assertNotIn("governingCount", code)
        self.assertNotIn("->subtotal", code, "the per-line money sum is back")
        self.assertIn("$quote->total", code, "the quote total is the rule")
        # Anti-vacuity: the stripper must leave real code behind, or the three
        # assertions above pass against an empty string forever.
        self.assertIn("class ErpQuoteOpportunityContribution", code)
        self.assertGreater(len(code.splitlines()), 40, code)

    def test_built_package_contains_provider_and_shared_dependency(self):
        package = WORKSPACE / REPO / "sugar-sell/BenchDogs-Ext"
        version = (package / "version").read_text().strip()
        archive = package / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        with zipfile.ZipFile(archive) as zipped:
            path = "custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php"
            self.assertEqual(zipped.read(path), (package / path).read_bytes())
            manifest = zipped.read("manifest.php").decode()
            self.assertIn(path, manifest)
            self.assertRegex(manifest, r"'id_name'\s*=>\s*'sugarai_erp_epicor'")
            self.assertRegex(manifest, r"'version'\s*=>\s*'1\.1\.24-rc9'")
            self.assertRegex(
                manifest,
                r"'id_name'\s*=>\s*'sugarai_erp_epicor_partialfulfillment'",
            )
            self.assertRegex(manifest, r"'version'\s*=>\s*'1\.0\.13'")


if __name__ == "__main__":
    unittest.main()
