"""Bench provider arithmetic/identity tests with the actual PHP implementation.

THE PROVIDER NOW READS NATIVE QUOTE LINES. Decision 901/903 retired the ``bd01_*``
quote mirror, and ``ErpQuoteOpportunityContribution`` was rewritten to walk
``Products`` rows pointed at the quote by ``quote_id`` instead of the mirror's
``bd01_erp_quote_quotes -> bd01_erp_quote_lines`` chain. These scenarios were
rebuilt onto that contract; the old ones fed a mirror graph the class no longer
looks at, so they exercised nothing and returned a silent "not applicable".

The line vocabulary changed with it:

    mirror (retired)            native (live)
    ------------------------    ---------------------------------------
    $line->governing            $line->erp_governing
    $line->prototype            (no writer since D16 - see the policy's
                                own header; a prototype is simply another
                                line whose erp_total_role is 'counts')
    $line->doc_ext_price        $line->subtotal
    walk the mirror link        SugarQuery on Products.quote_id

``prototype`` is deliberately absent below rather than renamed: the field has had
no writer since D16, so a scenario turning on it would assert a belief nothing
implements.
"""

import json
from pathlib import Path
import shutil
import subprocess
import unittest
import zipfile

from test_headline_valuation_owner import FIXTURE, REPO, WORKSPACE


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
// $make builds a native quote line. A line that "counts" is in the Opportunity's
// money; an unselected rung is 'alternative' and Sugar has already zeroed it.
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


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class GoverningContributionTest(unittest.TestCase):
    def execute(self, scenario):
        result = subprocess.run(
            ["php", "-r", FIXTURE + QUERY_DOUBLE
             + "require '" + PROVIDER + "';" + scenario + r'''
echo json_encode(['value' => $value ?? null, 'error' => $error ?? null,
    'line_count' => $GLOBALS['queried'] ?? null,
    'quote_total' => $quote->total]);
'''], cwd=WORKSPACE, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def test_selected_option_prototype_tax_and_shipping_all_contribute(self):
        """4000 governing + 500 that also counts + 20 tax + 10 shipping. The
        unselected rung is excluded even though it carries the largest number,
        and the quote's own display total is not touched."""
        observed = self.execute(r'''
$publish([$make('selected', 4000, true),
          $make('alternative', 5250, false, 'alternative'),
          $make('prototype', 500)]);
$quote->total = 9750;
$quote->tax = 20;
$quote->shipping = 10;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["value"], 4530)
        self.assertEqual(observed["line_count"], 3)
        self.assertEqual(observed["quote_total"], 9750)

    def test_the_read_is_scoped_to_this_quote_and_to_lines_carrying_a_role(self):
        """Negative control for the query itself. A line belonging to another
        quote and a line with no ERP role must both be invisible here - if the
        provider ever drops a condition this is what notices."""
        observed = self.execute(r'''
$publish([$make('mine', 4000, true),
          $make('other-quote', 9999, true, 'counts', 'someone-elses-quote'),
          $make('no-role', 8888, false, '')]);
$quote->tax = 0;
$quote->shipping = 0;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["line_count"], 1, observed)
        self.assertEqual(observed["value"], 4000, observed)

    def test_switching_selection_changes_only_contribution(self):
        observed = self.execute(r'''
$publish([$make('first', 0, false, 'alternative'), $make('second', 5250, true)]);
$quote->tax = 0;
$quote->shipping = 0;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["value"], 5250)
        self.assertEqual(observed["line_count"], 2)

    def test_unavailable_native_charges_are_not_fabricated_as_zero(self):
        for field in ("tax", "shipping"):
            for mutation in (f"unset($quote->{field});", f"$quote->{field} = null;"):
                with self.subTest(field=field, mutation=mutation):
                    observed = self.execute(r'''
$publish([$make('selected', 4000, true), $make('prototype', 500)]);
''' + mutation + r'''
try { $value = (new ErpQuoteOpportunityContribution())->resolve($quote); }
catch (UnexpectedValueException $e) { $error = $e->getMessage(); }
''')
                    self.assertIsNone(observed["value"], observed)
                    self.assertTrue(observed["error"], observed)

    def test_zero_charges_and_discounted_extended_price_remain_valid(self):
        """`subtotal` is the native carrier of the accepted per-line money, so a
        discounted line is taken as stated and never re-multiplied."""
        observed = self.execute(r'''
$publish([$make('selected', '3600.25', true),
          $make('alternative', 5250, false, 'alternative'),
          $make('prototype', 0)]);
$quote->tax = '0';
$quote->shipping = 0;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["value"], 3600.25)
        self.assertEqual(observed["line_count"], 3)

    def test_native_quote_with_no_erp_lines_is_not_applicable(self):
        """A native/estimating draft. ERP-Core must leave the Opportunity alone
        rather than write a zero over a human's number."""
        observed = self.execute(r'''
$publish([]);
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertIsNone(observed["value"])

    def test_ambiguous_or_unselected_ladder_refuses(self):
        scenarios = {
            # Two governing rungs: a selection bug. Guessing a winner would put
            # a wrong number on a forecast.
            "two governing": "$publish([$make('a', 1, true), $make('b', 2, true)]);",
            # Every rung still alternative: a ladder nobody has picked from, not
            # zero pounds of business.
            "none counting": "$publish([$make('a', 0, false, 'alternative'), "
                             "$make('b', 0, false, 'alternative')]);",
        }
        for name, lines in scenarios.items():
            with self.subTest(scenario=name):
                observed = self.execute(lines + r'''
try { $value = (new ErpQuoteOpportunityContribution())->resolve($quote); }
catch (UnexpectedValueException $e) { $error = $e->getMessage(); }
''')
                self.assertIsNone(observed["value"], observed)
                self.assertTrue(observed["error"], observed)

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
