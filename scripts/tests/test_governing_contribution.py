"""Bench provider arithmetic/identity tests with actual PHP implementation."""

import json
from pathlib import Path
import shutil
import subprocess
import unittest
import zipfile

from test_headline_valuation_owner import FIXTURE, WORKSPACE


PROVIDER = (
    "benchdogs-sugar-custom/sugar-sell/BenchDogs-Ext/custom/modules/Quotes/"
    "ErpQuoteHooks/OpportunityContribution.php"
)


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class GoverningContributionTest(unittest.TestCase):
    def execute(self, scenario):
        result = subprocess.run(
            ["php", "-r", FIXTURE + "require '" + PROVIDER + "';" + r'''
$make = function ($id, $amount, $governing = false, $prototype = false) use ($line) {
    $row = clone $line;
    $row->id = $id;
    $row->doc_ext_price = $amount;
    $row->governing = $governing;
    $row->prototype = $prototype;
    return $row;
};
''' + scenario + r'''
echo json_encode(['value' => $value ?? null, 'error' => $error ?? null,
    'line_count' => isset($lines) ? count($lines) : null,
    'quote_total' => $quote->total]);
'''], cwd=WORKSPACE, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def test_selected_option_prototype_tax_and_shipping_all_contribute(self):
        observed = self.execute(r'''
$lines = [$make('selected', 4000, true), $make('alternative', 5250),
          $make('prototype', 500, false, true)];
$erp->bd01_erp_quote_lines = new TestLink($lines);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
$quote->total = 9750;
$quote->tax = 20;
$quote->shipping = 10;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["value"], 4530)
        self.assertEqual(observed["line_count"], 3)
        self.assertEqual(observed["quote_total"], 9750)

    def test_switching_selection_changes_only_contribution(self):
        observed = self.execute(r'''
$lines = [$make('first', 4000), $make('second', 5250, true)];
$erp->bd01_erp_quote_lines = new TestLink($lines);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
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
$lines = [$make('selected', 4000, true), $make('prototype', 500, false, true)];
$erp->bd01_erp_quote_lines = new TestLink($lines);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
''' + mutation + r'''
try { $value = (new ErpQuoteOpportunityContribution())->resolve($quote); }
catch (UnexpectedValueException $e) { $error = $e->getMessage(); }
''')
                    self.assertIsNone(observed["value"], observed)
                    self.assertTrue(observed["error"], observed)

    def test_zero_charges_and_discounted_extended_price_remain_valid(self):
        observed = self.execute(r'''
$selected = $make('selected', '3600.25', true);
$selected->selling_qty = 50;
$selected->doc_unit_price = 80; // Undiscounted multiplication would be wrong.
$lines = [$selected, $make('alternative', 5250), $make('prototype', 0, false, true)];
$erp->bd01_erp_quote_lines = new TestLink($lines);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
$quote->tax = '0';
$quote->shipping = 0;
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertEqual(observed["value"], 3600.25)
        self.assertEqual(observed["line_count"], 3)

    def test_unlinked_native_quote_is_not_applicable(self):
        observed = self.execute(r'''
$quote->bd01_erp_quote_quotes = new TestLink([]);
$value = (new ErpQuoteOpportunityContribution())->resolve($quote);
''')
        self.assertIsNone(observed["value"])

    def test_ambiguous_selection_revision_or_prototype_refuses(self):
        scenarios = [
            "$lines = [$make('a', 1), $make('b', 2)];",
            "$lines = [$make('a', 1, true), $make('b', 2, true)];",
            "$lines = [$make('a', 1, true), $make('p1', 2, false, true), "
            "$make('p2', 3, false, true)];",
        ]
        for lines in scenarios:
            with self.subTest(lines=lines):
                observed = self.execute(lines + r'''
$erp->bd01_erp_quote_lines = new TestLink($lines);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
try { $value = (new ErpQuoteOpportunityContribution())->resolve($quote); }
catch (UnexpectedValueException $e) { $error = $e->getMessage(); }
''')
                self.assertIsNone(observed["value"])
                self.assertTrue(observed["error"])

        observed = self.execute(r'''
$lines = [$make('a', 1, true)];
$erp->bd01_erp_quote_lines = new TestLink($lines);
$other = clone $erp;
$quote->bd01_erp_quote_quotes = new TestLink([$erp, $other]);
try { $value = (new ErpQuoteOpportunityContribution())->resolve($quote); }
catch (UnexpectedValueException $e) { $error = $e->getMessage(); }
''')
        self.assertTrue(observed["error"])

    def test_built_package_contains_provider_and_shared_dependency(self):
        package = WORKSPACE / "benchdogs-sugar-custom/sugar-sell/BenchDogs-Ext"
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
