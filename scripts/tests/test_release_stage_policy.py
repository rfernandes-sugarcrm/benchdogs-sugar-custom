#!/usr/bin/env python3
"""The Bench release-stage provider is RETIRED, in the one shape that works.

🛑 WHAT CHANGED (0.9.42-rc65, G280 / 🔒 1507, on top of G278 / 🔒 1506). This
package used to answer the Opportunity release stage itself: count the Quote's
ordered lines, return `['sales_stage' => 'Partial Production Ordered',
'probability' => 90]`. Partial Fulfillment does the same thing generically, from
tenant config, so Bench stops deciding — *"Donthave any logic on bench that is
not on core"*.

🚩 AND "STOPS DECIDING" IS NOT "STOPS SHIPPING", which is the whole reason this
file still exists. Read out of PF's source rather than assumed
(`ERP-Epicor-PartialFulfillment/custom/modules/Quotes/ErpOpportunityValuation.php`):

*   `:288` finds the provider by a HARDCODED PATH with `file_exists()`. Module
    Loader never deletes a file a later build stops shipping (§CW / G37) and
    `unlink()` is denied to package code (MLP002), so DELETING the file would
    leave the old provider running on every tenant that has it, still
    outranking the config.
*   `:297-301` — if the file exists but does NOT define
    `ErpOpportunityReleaseStagePolicy`, PF returns `policy_provider_invalid` and
    PRESERVES the stage, never reading the config. So an EMPTY stub is worse
    than doing nothing: the stage would silently stop being written.
*   `:312` + `:319` — a provider that exists and returns `null` is
    `policy_provider_null`, which falls through to
    `erp_integration.partial_order_sales_stage`. That is the only shape that
    hands the decision over, and it is what this package now ships.

So the cases below EXECUTE the shipped file: define the class, call `resolve()`,
and assert it answers null while writing nothing. The config half is asserted in
test_post_install_stage_languages.py.

MUTATION-VERIFIED: empty the file -> defines_the_class fails; make resolve()
return an array -> answers_null fails; delete the file -> still_ships fails.
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PKG = Path(os.environ.get("BD_PKG", ROOT / "sugar-sell/BenchDogs-Ext"))
POLICY = PKG / "custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php"

#: PF loads the provider exactly like this: require the path, check the class,
#: call resolve($quote). The stub must survive being handed a real-ish bean.
HARNESS = r"""
class SugarBean {
    public $id = 'quote-1';
    public $products = null;
    public $saved = 0;
    public function save($check = true) { $this->saved++; }
    public function load_relationship($name) { $GLOBALS['touched'][] = 'load_relationship'; return true; }
}
class BeanFactory {
    public static function retrieveBean($module, $id, $params = []) { $GLOBALS['touched'][] = 'retrieveBean'; return null; }
    public static function newBean($module) { $GLOBALS['touched'][] = 'newBean'; return new SugarBean(); }
}
class TestLog { public function __call($m, $a) { $GLOBALS['logged'][] = $m; } }
$GLOBALS['log'] = new TestLog();
$GLOBALS['touched'] = [];
$GLOBALS['logged'] = [];

require getenv('BD_POLICY');
$defined = class_exists('ErpOpportunityReleaseStagePolicy', false);
$answer = 'not-called';
$threw = null;
if ($defined) {
    $quote = new SugarBean();
    try { $answer = (new ErpOpportunityReleaseStagePolicy())->resolve($quote); }
    catch (Throwable $e) { $threw = get_class($e) . ': ' . $e->getMessage(); }
    $saved = $quote->saved;
} else {
    $saved = 0;
}
echo json_encode([
    'defined' => $defined,
    'answer' => $answer,
    'threw' => $threw,
    'saved' => $saved,
    'touched' => $GLOBALS['touched'],
]);
"""


@unittest.skipUnless(shutil.which("php"), "requires php")
class RetiredProviderContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        out = subprocess.run(["php", "-r", HARNESS], capture_output=True, text=True,
                             env={**os.environ, "BD_POLICY": str(POLICY)})
        if "{" not in out.stdout:
            raise AssertionError(f"harness failed: {out.stdout[-400:]} {out.stderr[-400:]}")
        cls.observed = json.loads(out.stdout[out.stdout.index("{"):])

    def test_the_file_still_ships(self):
        """Deleting it is a no-op on any tenant that has it: PF finds the
        provider by path, and Module Loader deletes nothing."""
        self.assertTrue(POLICY.is_file(),
                        "the provider path stopped shipping; the OLD provider then keeps running")

    def test_it_still_defines_the_class_pf_looks_for(self):
        """An empty stub would be `policy_provider_invalid` — PF would preserve
        the stage and never read the config, i.e. the stage stops being written
        at all. This is the case that catches that mistake."""
        self.assertTrue(self.observed["defined"],
                        "the stub no longer defines ErpOpportunityReleaseStagePolicy")

    def test_resolve_answers_null_and_writes_nothing(self):
        self.assertIsNone(self.observed["threw"], f"resolve() threw: {self.observed['threw']}")
        self.assertIsNone(self.observed["answer"], "the provider is deciding a stage again")
        self.assertEqual(self.observed["saved"], 0)

    def test_it_reads_no_lines_at_all(self):
        """The old provider loaded the products link and re-read every line with
        use_cache=false. A retired provider must not keep doing the work whose
        answer it throws away."""
        self.assertEqual(self.observed["touched"], [],
                         f"the stub still touches the bean layer: {self.observed['touched']}")


if __name__ == "__main__":
    unittest.main()
