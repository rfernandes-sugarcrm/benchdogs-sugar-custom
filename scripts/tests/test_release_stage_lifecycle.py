"""Composed shared-writer / Bench-policy release staging.

🛑 THIS FILE USED TO BE A PROTOTYPE-TO-PRODUCTION LIFECYCLE, AND THAT LIFECYCLE
NO LONGER EXISTS. It drove PF's `ErpOpportunityValuation::afterLinesOrdered`
twice - first with only the prototype line ordered (expecting `Prototype Ordered`
/ 80), then with production ordered too (`Partial Production Ordered` / 90) - and
Bench's policy supplied the prototype/production roles from the `bd01_*` quote
mirror. Decisions 901/903 retired the mirror, and the policy's own header records
that its prototype lookup had been dead since D16 anyway. The shipped policy now
has ONE outcome, so there is no progression left to compose.

What is still worth composing, and is all this file now asserts, is that the
SHARED writer and the BENCH policy agree on the Opportunity: one opportunity,
staged from native quote lines, with revenue line items never read.

ALSO DELETED: `test_kinetic_dispatch_is_edge_triggered_and_uses_neutral_hook`,
which read `BdQuoteReflectionHook.php` to prove Bench fired the neutral hook only
on an edge. Bench no longer dispatches at all - `ErpQuoteHooks::fireAfterLinesOrdered`
is called by ERP-Epicor's own `QuotesErpActionsApi`, so that guard belongs in
`erp-integration-sugar`, not here, and keeping a copy pointed at a deleted file
would have been coverage of nothing.

🚩 REPORTED, NOT FIXED HERE: Bench still ships `Prototype Ordered` (probability
80) in `bd_stage_doms.append.php` and `en_us.bd_stage_doms.php`, a stage no code
can now produce. Removing it is a decision about `custom/`.
"""

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
    def test_composed_writer_and_policy_stage_one_opportunity_without_rlis(self):
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
    public static function getBean($module) { return new TestAdmin(); }
    public static function retrieveBean($module, $id, $options = []) {
        if ($module === 'Products') { return self::$products[$id] ?? null; }
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
        'Prototype Ordered' => 'Prototype Ordered',
        'Partial Production Ordered' => 'Partial Production Ordered',
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
$protoQli = new SugarBean();
$protoQli->id = 'proto-qli'; $protoQli->erp_quote_line_num = 1; $protoQli->erp_ordered = true;
$productionQli = new SugarBean();
$productionQli->id = 'production-qli'; $productionQli->erp_quote_line_num = 2;
$productionQli->erp_ordered = false;
$quote->products = new TestLink([$protoQli, $productionQli], [$protoQli->id, $productionQli->id]);
BeanFactory::$opp = $opp;
BeanFactory::$products = [$protoQli->id => $protoQli, $productionQli->id => $productionQli];
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
            staged = ["same-opportunity", "Partial Production Ordered", 90]
            # One committed line already stages it; committing the second does
            # not move it again, because there is one outcome to reach.
            self.assertEqual(observed["first"], staged)
            self.assertEqual(observed["second"], staged)
            self.assertEqual(observed["rli_reads"], 0)


if __name__ == "__main__":
    unittest.main()
