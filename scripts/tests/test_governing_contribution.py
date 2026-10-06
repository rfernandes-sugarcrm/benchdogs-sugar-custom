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

WHICH BODY THIS RUNS, SINCE 0.9.42-rc69 (G280 / 🔒 1567)
---------------------------------------------------------
Bench Dogs no longer ships ``OpportunityContribution.php``. Partial Fulfillment
ships the SAME path and the SAME class, and since PF 1.0.41 (``ddf2796``, G282 /
🔒 1511) it carries the all-alternative preserve gate above - so Bench's copy was
a duplicate, and "whichever installed last wins" was the only thing deciding
between two bodies. rc69 leaves PF's as the only one.

So every scenario now runs through BOTH bodies, pinned under fixtures/g280
(PROVENANCE.json): PF 1.0.43's - the one on disk from now on - and rc68's Bench
body - the one being retired. Each scenario asserts PF's answer, and
``DeletingBenchsCopyChangesNoAmount`` asserts that the amount ERP-Core WRITES
(``QuoteOpportunityAmount::contribution()``: a number is written, ``null`` means
``(float) $quote->total``, a throw means preserve) is identical under both, for
every scenario. That is G280's "broken looks like: an Opportunity total changing
when the contribution provider is deleted", measured rather than argued.

ONE RECORDED DIVERGENCE, pinned so it cannot move silently: when the line-role
READ itself fails, rc68 threw (preserve) and PF answers from the quote total
(``BENCHDOGS-PORT-TO-CORE-2026-09-22.md`` §4.1 - a question for PF's owner, not a
defect this package can fix by keeping a duplicate).
"""


import json
import hashlib
from pathlib import Path
import shutil
import subprocess
import unittest
import zipfile

import shared_sugar
from test_headline_valuation_owner import FIXTURE, REPO, WORKSPACE


FIX = Path(__file__).resolve().parent / "fixtures" / "g280"
PROVENANCE = json.loads((FIX / "PROVENANCE.json").read_text())
PF_BODY = FIX / "pf_opportunity_contribution.php"
RC68_BODY = FIX / "bench_opportunity_contribution.rc68.php"
BODIES = {"pf": PF_BODY, "rc68": RC68_BODY}
SHIPPED_PATH = "custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php"

# The provider seeds a Products bean and issues a real SugarQuery. This double
# APPLIES the two conditions rather than returning every row, because a double
# that ignores the filter would pass whether or not the provider scoped its read
# at all - the scenarios below include a line on a different quote and a line
# with no role precisely to prove the filter runs. Rows carry the columns BOTH
# bodies read: rc68 re-reads each line by `id`, PF selects `erp_total_role`.
QUERY_DOUBLE = r"""
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
        if (!empty($GLOBALS['query_fails'])) { throw new RuntimeException('database went away'); }
        $rows = [];
        foreach ($GLOBALS['products'] as $product) {
            foreach ($this->where->equals as $field => $value) {
                if (($product->$field ?? null) != $value) { continue 2; }
            }
            foreach ($this->where->not_equals as $field => $value) {
                if (($product->$field ?? null) == $value) { continue 2; }
            }
            $rows[] = ['id' => $product->id, 'erp_total_role' => $product->erp_total_role];
        }
        $GLOBALS['queried'] = count($rows);
        return $rows;
    }
}
// $make builds a native quote line. A line that "counts" is in the quote's
// money; an unselected rung is 'alternative' and the enforced subtotal formula
// has already zeroed it, so the QUOTE TOTAL already excludes it.
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
"""

# Throwable, not only the provider's own UnexpectedValueException: core's
# refresh() catches Throwable around contribution(), so ANY escape is a
# preserve - and a body that lets a database error out must be observable here
# rather than killing the harness.
RESOLVE = r"""
try { $value = (new ErpQuoteOpportunityContribution())->resolve($quote); }
catch (UnexpectedValueException $e) { $error = $e->getMessage(); }
catch (Throwable $e) { $error = get_class($e) . ': ' . $e->getMessage(); }
"""

#: Every scenario, by name. Each is a quote state ERP-Core can actually hand the
#: provider: core's refreshPrimary() returns BEFORE asking when the total is not
#: numeric (QuoteOpportunityAmount.php:181-183), so the unavailable-total ones
#: are kept for the provider's own contract, not because core reaches them.
SCENARIOS = {
    "headline": r"""
$publish([$make('selected', 4000, true),
          $make('alternative', 0, false, 'alternative'),
          $make('prototype', 500)]);
$quote->total = 4530;   // 4000 + 500 lines, + 20 tax + 10 shipping
""",
    "g97_two_ladders": r"""
$publish([$make('epic06-1250-1', 2016, true),
          $make('epic06-1250-1-rung2', 0, false, 'alternative'),
          $make('epic06-1250-2', 69.30, true),
          $make('epic06-1250-2-rung2', 0, false, 'alternative')]);
$quote->total = 2085.30;
""",
    "bundle_discount": r"""
$publish([$make('a', 3600.25, true), $make('b', 1000)]);
$quote->total = 4100.25;   // 4600.25 of line money, less a 500.00 bundle discount
""",
    "scoped_read": r"""
$publish([$make('mine', 4000, true),
          $make('other-quote', 9999, true, 'counts', 'someone-elses-quote'),
          $make('no-role', 8888, false, '')]);
$quote->total = 4000;
""",
    "switched_selection": r"""
$publish([$make('first', 0, false, 'alternative'), $make('second', 5250, true)]);
$quote->total = 5250;
""",
    "native_no_erp_lines": r"""
$publish([]);
$quote->total = 1234.50;
""",
    "all_alternative_1049": r"""
$publish([$make('a', 0, false, 'alternative'), $make('b', 0, false, 'alternative'),
          $make('c', 0, false, 'alternative'), $make('d', 0, false, 'alternative')]);
$quote->total = 0;
""",
    "genuine_zero": r"""
$publish([$make('freebie', 0, true)]);
$quote->total = 0;
""",
    "total_unset": "$publish([$make('selected', 4000, true), $make('prototype', 500)]);\nunset($quote->total);\n",
    "total_null": "$publish([$make('selected', 4000, true), $make('prototype', 500)]);\n$quote->total = null;\n",
    "total_text": "$publish([$make('selected', 4000, true), $make('prototype', 500)]);\n$quote->total = 'n/a';\n",
}

#: The one scenario where the two bodies are KNOWN to answer differently.
DIVERGENT = {
    "read_fails": r"""
$publish([$make('selected', 4000, true), $make('prototype', 500)]);
$quote->total = 4500;
$GLOBALS['query_fails'] = true;
""",
}


def execute(body: Path, scenario: str) -> dict:
    result = subprocess.run(
        ["php", "-r", FIXTURE + QUERY_DOUBLE + "require '" + str(body) + "';"
         + scenario + RESOLVE + r"""
echo json_encode(['value' => $value ?? null, 'error' => $error ?? null,
    'line_count' => $GLOBALS['queried'] ?? null,
    'quote_total' => $quote->total ?? null]);
"""], cwd=WORKSPACE, capture_output=True, text=True,
    )
    if result.returncode != 0 or result.stderr:
        raise AssertionError(f"{body.name}: {result.stderr}{result.stdout}")
    return json.loads(result.stdout)


def written_by_core(observed: dict):
    """What QuoteOpportunityAmount::contribution() does with the answer:
    a throw is caught at refresh() and PRESERVES; null falls back to
    (float) $quote->total; a number is written as-is."""
    if observed["error"]:
        return ("preserve",)
    value = observed["value"] if observed["value"] is not None else observed["quote_total"]
    return ("write", round(float(value), 2))


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class GoverningContributionTest(unittest.TestCase):
    """🔒 708's scenarios, run through the body that owns the path from rc69 on:
    Partial Fulfillment's."""

    def pf(self, name):
        return execute(PF_BODY, SCENARIOS[name])

    # ── 🔒 708: the answer is the quote's own total ───────────────────────
    def test_the_headline_is_the_quotes_own_stored_total(self):
        """The 641e586c shape: a prototype that counts, a governing rung, and
        an unselected rung the enforced formula has already zeroed. Sugar rolled
        that up to 4,530.00 and that figure - not a re-derived line sum - is
        what the provider hands back."""
        observed = self.pf("headline")
        self.assertEqual(observed["value"], 4530)
        self.assertEqual(observed["line_count"], 3)

    def test_g97_two_ladder_groups_each_with_their_own_governing_line(self):
        """🚩 THE DEFECT, AS A TEST. Quote 1250, measured live: two ladder
        groups, each governing its own rung, 2,016.00 + 69.30 = 2,085.30. The
        old per-quote `governingCount > 1` threw here and froze `amount` at
        237.30 - a −$1,848.00 error on a quote whose total was right."""
        observed = self.pf("g97_two_ladders")
        self.assertEqual(observed["value"], 2085.30, observed)
        self.assertEqual(observed["line_count"], 4, observed)

    def test_a_bundle_discount_is_honoured_because_the_total_already_carries_it(self):
        self.assertEqual(self.pf("bundle_discount")["value"], 4100.25)

    def test_the_read_is_scoped_to_this_quote_and_to_lines_carrying_a_role(self):
        """Negative control for the query itself: a line on another quote and a
        line with no ERP role must both be invisible."""
        observed = self.pf("scoped_read")
        self.assertEqual(observed["line_count"], 1, observed)
        self.assertEqual(observed["value"], 4000, observed)

    def test_switching_selection_moves_the_total_and_the_provider_follows(self):
        observed = self.pf("switched_selection")
        self.assertEqual(observed["value"], 5250)
        self.assertEqual(observed["line_count"], 2)

    # ── the states that are not a number ──────────────────────────────────
    def test_native_quote_with_no_erp_lines_takes_the_native_total(self):
        """rc68 answered null here ("not applicable") and core then wrote
        `(float) $quote->total`. PF answers that same total itself. Same number
        on the Opportunity - see DeletingBenchsCopyChangesNoAmount."""
        observed = self.pf("native_no_erp_lines")
        self.assertIsNone(observed["error"])
        self.assertEqual(observed["value"], 1234.50)

    def test_every_rung_still_alternative_refuses_rather_than_publishing_zero(self):
        """🛑 QUOTE 1049, THE LIVE CONTROL - and G282's bar: "a test that pins
        the all-alternative case". 4 lines, all `alternative`, so the enforced
        formula zeroes every one and the quote total is 0.00 BY CONSTRUCTION.
        An admin set `amount = 23.00` by hand on 09-18. The provider must THROW:
        that is what makes `QuoteOpportunityAmount::refresh()` catch, log and
        write nothing, so the 23.00 survives."""
        observed = self.pf("all_alternative_1049")
        self.assertIsNone(observed["value"], observed)
        self.assertIn("not yet priced", observed["error"] or "", observed)
        self.assertNotIn("governing", (observed["error"] or "").lower(), observed)

    def test_a_genuinely_zero_priced_quote_still_publishes_zero(self):
        """A line that COUNTS at 0.00 is a measurement. Only an all-`alternative`
        quote is unpriced (G199: a real 0.00 is published, never blanked)."""
        self.assertEqual(self.pf("genuine_zero")["value"], 0)

    def test_an_unavailable_quote_total_is_not_fabricated_as_zero(self):
        for name in ("total_unset", "total_null", "total_text"):
            with self.subTest(scenario=name):
                observed = self.pf(name)
                self.assertIsNone(observed["value"], observed)
                self.assertTrue(observed["error"], observed)

    # ── anti-resurrection ─────────────────────────────────────────────────
    def test_the_surviving_body_does_not_read_the_line_level_money(self):
        """🔒 708 / §DG, on the body that now owns the path. The G97 defect was
        a CHECK, not an arithmetic; `erp_governing` and a per-line `subtotal`
        sum must not be what decides the number."""
        code = "\n".join(
            line for line in PF_BODY.read_text().splitlines()
            if not line.lstrip().startswith(("*", "/*", "//", "*/"))
        )
        self.assertNotIn("erp_governing", code)
        self.assertNotIn("governingCount", code)
        self.assertNotIn("->subtotal", code)
        self.assertIn("$quote->total", code)
        self.assertIn("class ErpQuoteOpportunityContribution", code)
        self.assertGreater(len(code.splitlines()), 40, code)


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class DeletingBenchsCopyChangesNoAmount(unittest.TestCase):
    """G280's "broken looks like", measured: for every scenario, the amount
    ERP-Core writes is the same under rc68's Bench body and PF's."""

    def test_every_scenario_writes_the_same_amount_under_both_bodies(self):
        for name, scenario in SCENARIOS.items():
            with self.subTest(scenario=name):
                rc68 = written_by_core(execute(RC68_BODY, scenario))
                pf = written_by_core(execute(PF_BODY, scenario))
                self.assertEqual(pf, rc68, f"{name}: deleting Bench's copy changes the amount")

    def test_the_comparison_can_see_a_difference(self):
        """Anti-vacuity: the comparator must distinguish preserve from write and
        one number from another, or the case above passes on anything."""
        self.assertNotEqual(written_by_core({"error": "x", "value": None, "quote_total": 5}),
                            written_by_core({"error": None, "value": 5, "quote_total": 5}))
        self.assertNotEqual(written_by_core({"error": None, "value": 5, "quote_total": 5}),
                            written_by_core({"error": None, "value": 6, "quote_total": 5}))
        self.assertEqual(written_by_core({"error": None, "value": None, "quote_total": 5}),
                         written_by_core({"error": None, "value": 5, "quote_total": 99}))

    def test_the_one_recorded_divergence_is_still_exactly_that(self):
        """A failed line-role READ: rc68 let the error out (preserve until the
        next save); PF logs it and answers from the quote total. Pinned so it
        cannot move silently - if PF ever changes this, this goes red and the
        port review's §4.1 question is answered."""
        rc68 = execute(RC68_BODY, DIVERGENT["read_fails"])
        pf = execute(PF_BODY, DIVERGENT["read_fails"])
        self.assertEqual(written_by_core(pf), ("write", 4500.0))
        self.assertEqual(written_by_core(rc68), ("preserve",))
        self.assertEqual(rc68["error"], "RuntimeException: database went away",
                         "rc68 let the read error escape; core's refresh() catch preserved")


class ThePinsAreTheRealBodies(unittest.TestCase):
    def test_each_pin_matches_its_provenance(self):
        for name, meta in PROVENANCE["files"].items():
            with self.subTest(file=name):
                data = (FIX / name).read_bytes()
                self.assertEqual(hashlib.sha256(data).hexdigest(), meta["sha256"])
                self.assertEqual(len(data), meta["bytes"])

    def test_each_pin_matches_its_source_commit_where_reachable(self):
        """Staleness is loud where the history is reachable (every local run);
        in CI (one commit, no sibling) this passes and the hash above is what
        holds the pin still."""
        # shared_sugar.SIBLING, not WORKSPACE / name: WORKSPACE is ROOT.parent,
        # which from a git worktree is not where the sibling checkout lives, and
        # this case would then `continue` silently on every local run.
        repos = {"benchdogs-sugar-custom": REPO_ROOT, "erp-integration-sugar": shared_sugar.SIBLING}
        compared = 0
        git = shutil.which("git")
        if git is None:
            # CI's php:8.2-cli image ships no git binary at all (measured: PR #27's
            # first run died FileNotFoundError here). Same outcome as an
            # unreachable commit: the sha256 case above is what holds the pin.
            return
        for name, meta in PROVENANCE["files"].items():
            repo = repos[meta["repository"]]
            shown = subprocess.run([git, "-C", str(repo), "show", f"{meta['commit']}:{meta['source']}"],
                                   capture_output=True)
            if shown.returncode != 0:
                continue
            compared += 1
            with self.subTest(file=name):
                self.assertEqual(shown.stdout, (FIX / name).read_bytes(), f"{name} drifted from its commit")
        if shared_sugar.SIBLING.is_dir():
            # Where the sibling exists the PF pin MUST have been compared.
            self.assertGreaterEqual(compared, 1, "no pin was compared although the sibling is present")


class TheBuiltPackageNoLongerShipsIt(unittest.TestCase):
    def test_built_package_leaves_the_path_to_partial_fulfillment(self):
        package = REPO_ROOT / "sugar-sell/BenchDogs-Ext"
        version = (package / "version").read_text().strip()
        archive = package / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        self.assertFalse((package / SHIPPED_PATH).exists())
        with zipfile.ZipFile(archive) as zipped:
            self.assertNotIn(SHIPPED_PATH, zipped.namelist())
            manifest = zipped.read("manifest.php").decode()
        # A copy entry would put Bench's body back over PF's - and hand Module
        # Loader a path to DELETE on a Bench Dogs uninstall, taking PF's body
        # with it (G282's uninstall hazard).
        self.assertNotIn(SHIPPED_PATH, manifest)
        # >= 1.0.43 (the G282 preserve gate); 1.0.50 since rc70 (🔒 1724b pairs
        # it with ERP-Epicor 1.1.125).
        self.assertRegex(manifest, r"'id_name'\s*=>\s*'sugarai_erp_epicor_partialfulfillment',\s*"
                                   r"'version'\s*=>\s*'1\.0\.(4[3-9]|[5-9]\d)'")


REPO_ROOT = WORKSPACE / REPO


if __name__ == "__main__":
    unittest.main()
