"""Bench release-stage policy: identity-safe classification, no writes."""

from pathlib import Path
import json
import shutil
import subprocess
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
PROVIDER = (
    ROOT / "sugar-sell/BenchDogs-Ext/custom/modules/Quotes/ErpQuoteHooks"
    / "OpportunityReleaseStagePolicy.php"
)

FIXTURE = r'''
#[AllowDynamicProperties]
class SugarBean {
    public $saves = 0;
    public function load_relationship($name) { return isset($this->$name); }
    public function save() { $this->saves++; }
}
class BeanFactory {
    public static $beans = [];
    public static $retrievals = [];
    public static function retrieveBean($module, $id, $options = []) {
        self::$retrievals[] = [$module, $id, $options];
        return self::$beans[$id] ?? null;
    }
}
class TestLink {
    public function __construct(public $beans = []) {
        foreach ($beans as $bean) {
            if (!empty($bean->id)) { BeanFactory::$beans[$bean->id] = $bean; }
        }
    }
    public function getBeans() { return $this->beans; }
    public function get() {
        return array_values(array_map(function ($bean) { return $bean->id; }, $this->beans));
    }
}
require '__PROVIDER__';
$make = function ($id, $lineNum, $prototype = false, $ordered = false) {
    $row = new SugarBean();
    $row->id = $id;
    $row->line_num = $lineNum;
    $row->bd_erp_line_num = $lineNum;
    $row->prototype = $prototype;
    $row->erp_ordered = $ordered;
    return $row;
};
$quote = new SugarBean();
$erp = new SugarBean();
$erp->id = 'erp-quote';
'''.replace("__PROVIDER__", PROVIDER.as_posix())


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class ReleaseStagePolicyTest(unittest.TestCase):
    def execute(self, scenario):
        result = subprocess.run(
            ["php", "-r", FIXTURE + scenario + r'''
try { $decision = (new ErpOpportunityReleaseStagePolicy())->resolve($quote); }
catch (UnexpectedValueException $e) { $error = $e->getMessage(); }
echo json_encode(['decision' => $decision ?? null, 'error' => $error ?? null,
    'quote_saves' => $quote->saves, 'retrievals' => BeanFactory::$retrievals]);
'''], cwd=ROOT, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def test_prototype_only_release(self):
        observed = self.execute(r'''
$erp->bd01_erp_quote_lines = new TestLink([$make('erp-proto', 1, true)]);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
$quote->products = new TestLink([$make('qli-proto', 1, false, true)]);
''')
        self.assertEqual(observed["decision"], {
            "sales_stage": "Prototype Closed", "probability": 80,
        })
        self.assertEqual(observed["retrievals"], [
            ["bd01_ERP_Quote", "erp-quote", {"use_cache": False}],
            ["bd01_ERP_Quote_Line", "erp-proto", {"use_cache": False}],
            ["Products", "qli-proto", {"use_cache": False}],
        ])
        self.assertEqual(observed["quote_saves"], 0)

    def test_any_ordered_production_outranks_prototype(self):
        observed = self.execute(r'''
$erp->bd01_erp_quote_lines = new TestLink([
    $make('erp-proto', 1, true), $make('erp-production', 2)
]);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
$quote->products = new TestLink([
    $make('qli-proto', 1, false, true), $make('qli-production', 2, false, true)
]);
''')
        self.assertEqual(observed["decision"], {
            "sales_stage": "Partial Production Closed", "probability": 90,
        })

    def test_linked_bench_quote_with_no_visible_release_refuses_and_logs_upstream(self):
        observed = self.execute(r'''
$erp->bd01_erp_quote_lines = new TestLink([$make('erp-production', 2)]);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
$quote->products = new TestLink([$make('qli-production', 2)]);
''')
        self.assertIsNone(observed["decision"])
        self.assertIn("No committed Quote line", observed["error"])

    def test_preloaded_relationship_snapshot_cannot_hide_committed_release(self):
        observed = self.execute(r'''
$erp->bd01_erp_quote_lines = new TestLink([$make('erp-proto', 1, true)]);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
$stale = $make('qli-proto', 1, false, false);
$quote->products = new TestLink([$stale]);
$fresh = clone $stale;
$fresh->erp_ordered = true;
BeanFactory::$beans[$fresh->id] = $fresh;
''')
        self.assertEqual(observed["decision"], {
            "sales_stage": "Prototype Closed", "probability": 80,
        })
        self.assertEqual(observed["retrievals"], [
            ["bd01_ERP_Quote", "erp-quote", {"use_cache": False}],
            ["bd01_ERP_Quote_Line", "erp-proto", {"use_cache": False}],
            ["Products", "qli-proto", {"use_cache": False}],
        ])

    def test_preloaded_erp_graph_cannot_hide_line_identity_or_prototype_role(self):
        observed = self.execute(r'''
$staleLine = $make('erp-proto', 0, false);
$staleErp = clone $erp;
$staleErp->bd01_erp_quote_lines = new TestLink([$staleLine]);
$quote->bd01_erp_quote_quotes = new TestLink([$staleErp]);
$quote->products = new TestLink([$make('qli-proto', 1, false, true)]);

$freshLine = $make('erp-proto', 1, true);
$freshErp = clone $erp;
$freshErp->bd01_erp_quote_lines = new TestLink([$freshLine]);
BeanFactory::$beans[$freshErp->id] = $freshErp;
''')
        self.assertEqual(observed["decision"], {
            "sales_stage": "Prototype Closed", "probability": 80,
        })
        self.assertEqual(observed["retrievals"], [
            ["bd01_ERP_Quote", "erp-quote", {"use_cache": False}],
            ["bd01_ERP_Quote_Line", "erp-proto", {"use_cache": False}],
            ["Products", "qli-proto", {"use_cache": False}],
        ])

    def test_ambiguous_or_missing_identity_refuses(self):
        scenarios = [
            r'''
$erp->bd01_erp_quote_lines = new TestLink([$make('erp', 1)]);
$quote->bd01_erp_quote_quotes = new TestLink([$erp, clone $erp]);
$quote->products = new TestLink([$make('qli', 1, false, true)]);
''',
            r'''
$erp->bd01_erp_quote_lines = new TestLink([$make('erp-a', 1), $make('erp-b', 1)]);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
$quote->products = new TestLink([$make('qli', 1, false, true)]);
''',
            r'''
$erp->bd01_erp_quote_lines = new TestLink([$make('erp', 1)]);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
$quote->products = new TestLink([$make('qli', 0, false, true)]);
''',
            r'''
$erp->bd01_erp_quote_lines = new TestLink([
    $make('proto-a', 1, true), $make('proto-b', 2, true)
]);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
$quote->products = new TestLink([$make('qli', 1, false, true)]);
''',
        ]
        for scenario in scenarios:
            with self.subTest(scenario=scenario):
                observed = self.execute(scenario)
                self.assertTrue(observed["error"], observed)
                self.assertIsNone(observed["decision"])

    def test_built_package_contains_policy_and_partial_dependency(self):
        package = ROOT / "sugar-sell/BenchDogs-Ext"
        version = (package / "version").read_text().strip()
        archive = package / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        with zipfile.ZipFile(archive) as zipped:
            path = "custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php"
            self.assertEqual(zipped.read(path), (package / path).read_bytes())
            manifest = zipped.read("manifest.php").decode()
            self.assertIn("sugarai_erp_epicor_partialfulfillment", manifest)
            self.assertRegex(manifest, r"'version'\s*=>\s*'1\.0\.11'")


if __name__ == "__main__":
    unittest.main()
