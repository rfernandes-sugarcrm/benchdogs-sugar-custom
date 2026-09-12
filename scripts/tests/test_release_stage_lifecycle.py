"""Composed shared-writer/Bench-policy prototype-to-production lifecycle."""

from pathlib import Path
import json
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = ROOT.parent
SHARED = (
    WORKSPACE / "erp-integration-sugar/sugar-sell/ERP-Epicor-PartialFulfillment"
    / "custom/modules/Quotes/ErpOpportunityValuation.php"
)
ROLLUP = SHARED.with_name("ErpQuoteLineRollup.php")
PROVIDER = (
    ROOT / "sugar-sell/BenchDogs-Ext/custom/modules/Quotes/ErpQuoteHooks"
    / "OpportunityReleaseStagePolicy.php"
)


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
@unittest.skipUnless(SHARED.is_file(), "requires sibling shared Sugar checkout")
class ReleaseStageLifecycleTest(unittest.TestCase):
    def test_kinetic_dispatch_is_edge_triggered_and_uses_neutral_hook(self):
        source = (
            ROOT / "sugar-sell/BenchDogs-Ext/custom/modules/bd01_ERP_Quote"
            / "BdQuoteReflectionHook.php"
        ).read_text(encoding="utf-8")
        self.assertIn("if ($reconciledReleaseLines > 0)", source)
        self.assertIn("ErpQuoteHooks::fireAfterLinesOrdered", source)
        self.assertIn("if (!empty($product->erp_ordered))", source)

    def test_same_opportunity_moves_prototype_then_production_without_rlis(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            quote_dir = root / "custom/modules/Quotes"
            policy_dir = quote_dir / "ErpQuoteHooks"
            policy_dir.mkdir(parents=True)
            shutil.copy2(SHARED, quote_dir / SHARED.name)
            shutil.copy2(ROLLUP, quote_dir / ROLLUP.name)
            shutil.copy2(PROVIDER, policy_dir / PROVIDER.name)
            script = r'''
namespace Sugarcrm\Sugarcrm\Util\Files {
class FileLoader { public static function validateFilePath($path) { return $path; } }
}
namespace {
#[\AllowDynamicProperties]
class SugarBean {
    public $saves = 0;
    public function load_relationship($name) {
        $GLOBALS['reads'][] = $name;
        return isset($this->$name);
    }
    public function save() { $this->saves++; }
}
class TestLink {
    public function __construct(public $beans = [], public $ids = null) {
        if ($this->ids === null) {
            $this->ids = array_values(array_map(
                function ($bean) { return $bean->id; },
                $this->beans
            ));
        }
    }
    public function getBeans() { return $this->beans; }
    public function get() { return $this->ids; }
}
class TestAdmin { public function getConfigForModule($category) { return []; } }
class BeanFactory {
    public static $opp;
    public static $products = [];
    public static $erpQuotes = [];
    public static $erpLines = [];
    public static function getBean($module) { return new TestAdmin(); }
    public static function retrieveBean($module, $id, $options = []) {
        if ($module === 'Products') { return self::$products[$id] ?? null; }
        if ($module === 'bd01_ERP_Quote') { return self::$erpQuotes[$id] ?? null; }
        if ($module === 'bd01_ERP_Quote_Line') { return self::$erpLines[$id] ?? null; }
        return self::$opp;
    }
}
$GLOBALS['reads'] = [];
$GLOBALS['log'] = new class {
    public function info($m) {}
    public function warn($m) {}
    public function error($m) { throw new \RuntimeException($m); }
};
$GLOBALS['app_list_strings'] = [
    'sales_stage_dom' => [
        'Proposal/Price Quote' => 'Proposal/Price Quote',
        'Prototype Closed' => 'Prototype Closed',
        'Partial Production Closed' => 'Partial Production Closed',
        'Closed Won' => 'Closed Won',
        'Closed Lost' => 'Closed Lost',
    ],
];
$opp = new SugarBean();
$opp->id = 'same-opportunity';
$opp->sales_stage = 'Proposal/Price Quote';
$opp->probability = 65;
$quote = new SugarBean();
$quote->id = 'same-quote';
$quote->erp_is_primary_quote = false;
$quote->opportunities = new TestLink([], [$opp->id]);
$erp = new SugarBean();
$erp->id = 'erp-quote';
$proto = new SugarBean(); $proto->id = 'erp-proto'; $proto->line_num = 1; $proto->prototype = true;
$production = new SugarBean(); $production->id = 'erp-production'; $production->line_num = 2; $production->prototype = false;
$erp->bd01_erp_quote_lines = new TestLink([$proto, $production]);
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);
$protoQli = new SugarBean();
$protoQli->id = 'proto-qli'; $protoQli->bd_erp_line_num = 1; $protoQli->erp_ordered = true;
$productionQli = new SugarBean();
$productionQli->id = 'production-qli'; $productionQli->bd_erp_line_num = 2;
$productionQli->erp_ordered = false;
$quote->products = new TestLink([$protoQli, $productionQli], [$protoQli->id, $productionQli->id]);
BeanFactory::$opp = $opp;
BeanFactory::$products = [$protoQli->id => $protoQli, $productionQli->id => $productionQli];
BeanFactory::$erpQuotes = [$erp->id => $erp];
BeanFactory::$erpLines = [$proto->id => $proto, $production->id => $production];
require 'custom/modules/Quotes/ErpOpportunityValuation.php';
$writer = new ErpOpportunityValuation();
$writer->afterLinesOrdered($quote, true);
$first = [$opp->id, $opp->sales_stage, $opp->probability];
$productionQli->erp_ordered = true;
$writer->afterLinesOrdered($quote, false);
$second = [$opp->id, $opp->sales_stage, $opp->probability];
echo json_encode(['first' => $first, 'second' => $second,
    'rli_reads' => count(array_filter($GLOBALS['reads'],
        function ($name) { return $name === 'revenuelineitems'; }))]);
}
'''
            result = subprocess.run(
                ["php", "-r", script], cwd=root, capture_output=True, text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
            observed = json.loads(result.stdout)
            self.assertEqual(observed["first"], ["same-opportunity", "Prototype Closed", 80])
            self.assertEqual(
                observed["second"],
                ["same-opportunity", "Partial Production Closed", 90],
            )
            self.assertEqual(observed["rli_reads"], 0)


if __name__ == "__main__":
    unittest.main()
