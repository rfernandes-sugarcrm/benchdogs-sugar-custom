#!/usr/bin/env python3
"""THE PROBE THAT LICENSES rc66's ONE "core does it now" REMOVAL.

0.9.42-rc66, G280 / 🔒 1508. rc65 left
`custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php` shipping
as a provider that exists and returns `null`, because on a tenant still holding
the OLD deciding provider that overwrite is the only removal Module Loader can
perform. rc66 stops shipping it. 🔒 1508's sequencing rule makes that legal —
*"a file may only stop shipping once every QA tenant has taken the build that
emptied it"* — and tenant 1 took rc65 at 17:17:35Z (stock carries no Bench Dogs
by design, `RELEASE-CONTROL.md:757`).

🛑 BUT "LEGAL" IS NOT "SAFE", AND A TEST AGREEING THE FILE IS GONE WOULD PROVE
NOTHING. A mutation-passing assertion notices deletion; it can never show that
Partial Fulfillment reaches the same decision without the file. So this probe
does not read the package at all on the deciding path. It RUNS PARTIAL
FULFILLMENT'S OWN `ErpOpportunityValuation::releaseStageDecision()` — the real
shipped file, resolved through `shared_sugar` so a developer with both trees
tests against PF's current source — twice over one identical quote:

    A. with the rc65 stub on disk at PF's hardcoded path   -> policy_provider_null
    B. with NOTHING at that path                           -> policy_provider_absent

and asserts the two `decision` payloads are IDENTICAL. That is PF DOING it, not
a fixture agreeing with a belief.

WHY THE TWO CAN DIVERGE AND THE READING IS NOT OBVIOUS. PF's lookup is by
`file_exists()` on a fixed path (`ErpOpportunityValuation.php:288`) and it has
THREE outcomes, not two: a file that exists and answers wins outright; a file
that exists and does NOT define the class returns `policy_provider_invalid`,
which PRESERVES the stage and never reads the config (`:297-301`) — that is why
rc65 refused to ship an empty stub, and why nothing here may ship one either;
and only `null` or `absent` fall through to the generic config path. This probe
pins the third case and case C below pins the first.

C IS THE MUTATION, AND IT IS PART OF THE SUITE RATHER THAN A NOTE. A provider
that returns an array is dropped at the same path and the probe must then see
the decisions DIFFER. Without C, A == B could be two identical ways of failing
to reach PF at all — a typo'd path, a class that never loads, a harness that
answers before PF does. C is what makes A == B mean something.

WHAT WOULD BE SEEN IF THIS REMOVAL WERE UNSAFE: case B's decision would come
back `None` (preserved) while A's named a stage, i.e. dropping the file would
stop the Opportunity stage being written — the exact failure rc65's docblock
warned about for the EMPTY-stub shape.

🔁 G440 (2026-09-24): PF's G346 (Sugar target `e5af5878`, in PF 1.0.50) changed
the FINAL release. It used to preserve; now a whole-quote order moves the
Opportunity to `Closed Won` - when that is a `sales_stage_dom` key - for BOTH the
null and the absent provider (`orderedInFullResolution`), and preserves with
`policy_provider_{null,absent}_config_invalid` only on a tenant whose
sales_stage_dom has no `Closed Won`. This file's harness carried a two-key
sales_stage_dom, so against the new PF it read as that tenant: a FIXTURE
artefact, not a runtime one. At runtime ERP-Core ships `Closed Won`
(`dropdowntemplates/sales_stage_dom.replace.php`), and no Bench Dogs build
ever reassigned or unset `sales_stage_dom` (only key-by-key additions, all
retired), so a tenant running rc69 or later reaches `Closed Won` - PROVIDED
its disk holds rc65's null stub or nothing at that path. And the status is only
REPORTED by ERP-Epicor's AfterLinesOrdered hook, after the order exists
(`ErpQuoteHooks.php` allowlist): it cannot fail Submit Order either way.

So the runs now carry the stock `Closed Won` key, and the one place the null
and absent runs must still differ - PF's own name for the lookup branch - is
read on a tenant WITHOUT it (case D), where G346 surfaces that name.

🔁 G594 (2026-09-25) CORRECTS THE G440 NOTE ABOVE, which read "rc69 and rc70
both reach Closed Won" as a fact about the PACKAGE. It is a fact about the
tenant's DISK. Module Loader never deletes a file a later build stops shipping
(§CW / G37), so a tenant that went from rc64-or-earlier straight to rc66+ -
never taking rc65's overwrite - still holds the rc45-rc64 DECIDING body at PF's
hardcoded path. That body answers `Partial Production Ordered` / 90 whenever any
line is ordered, so on the FINAL release PF's `policy_valid` branch wins and
G346's Closed Won is never reached. benchdogs-dev is that tenant (rc60 -> rc68
kit -> rc69 -> rc72 -> rc74): quote #8's completing order 27600 left its
Opportunity at Partial Production Ordered / 90. Case E below runs PF's real
resolver over that exact body (pinned by the residue one-off, which deletes it
from 1.0.3 on); `ONEOFF-RetireBdResidue` >= 1.0.3 is the fix, not a shipped stub.
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

import shared_sugar

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]

#: rc65's shipped stub, pinned because rc66 stops shipping it. This copy stands
#: for WHAT IS ON THE TENANT'S DISK after the 17:17:35Z install, which is the
#: state case A is about — not for anything the package still carries.
RC65_STUB = HERE / "fixtures/rc65/OpportunityReleaseStagePolicy.rc65.php"

#: G594: the body BenchDogs-Ext rc45-rc64 shipped (md5 e5e6e3ff..., the one
#: benchdogs-dev still holds from rc60). Pinned ONCE, by the residue one-off that
#: deletes it (1.0.3 step 4c), and read from there so the two cannot drift apart.
RC45_RC64_BODY = ROOT / ("sugar-sell/ONEOFF-RetireBdResidue/tests/fixtures/release-stage-policy/"
                         "OpportunityReleaseStagePolicy.rc45-rc64.php.txt")

POLICY_REL = "custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php"

#: A provider that DOES decide — rc64's shape, reduced to the answer it gave.
DECIDING_PROVIDER = """<?php
class ErpOpportunityReleaseStagePolicy
{
    public function resolve($quote)
    {
        return array('sales_stage' => 'Prototype Ordered', 'probability' => 80);
    }
}
"""

#: The Sugar surface PF touches on this path, and nothing else. `stage_dom` and
#: `probability_dom` carry PF 1.0.40's own keys: the config key must be a
#: sales_stage_dom member or PF discards it as a typo (`:424-430`).
HARNESS = r"""<?php
// Both blocks are BRACED on purpose: PHP refuses a file that mixes the braced
// and unbraced forms, and PF's `FileLoader::validateFilePath()` has to exist
// under its real namespace before the valuation file is required.
namespace Sugarcrm\Sugarcrm\Util\Files {
    class FileLoader { public static function validateFilePath($p) { return $p; } }
}

namespace {
class SugarBean {
    public $id = 'quote-1';
    public $deleted = 0;
    public $erp_ordered = 0;
    public $products;
    // G594 case E: the rc45-rc64 body reads the quote's native lines. Nothing PF
    // itself does on this path touches either method.
    public function load_relationship($name) {
        if ($name !== 'products') { return false; }
        $this->products = new LineLink();
        return true;
    }
}
class LineLink { public function get() { return array('line-1', 'line-2'); } }
class AdminStub {
    public function getConfigForModule($c) {
        return array('partial_order_sales_stage' => 'Partial Production Ordered');
    }
}
class BeanFactory {
    public static function getBean($m) { return new AdminStub(); }
    public static function newBean($m) { return new SugarBean(); }
    public static function retrieveBean($m, $id, $p = array()) {
        if ($m !== 'Products') { return null; }
        $line = new SugarBean();
        $line->id = $id;
        // every line ordered: the quote is complete, so a FINAL release is honest
        $line->erp_ordered = getenv('BD_NONE_ORDERED') === '1' ? 0 : 1;
        return $line;
    }
}
class TestLog { public function __call($m, $a) { $GLOBALS['logged'][] = $m; } }
$GLOBALS['log'] = new TestLog();
$GLOBALS['logged'] = array();
$GLOBALS['app_list_strings'] = array(
    'sales_stage_dom' => array(
        'Prototype Ordered' => 'Prototype Ordered',
        'Partial Production Ordered' => 'Partial Production Ordered',
        'Closed Won' => 'Closed Won',
    ),
    'sales_probability_dom' => array(
        'Prototype Ordered' => 80,
        'Partial Production Ordered' => 90,
        'Closed Won' => 100,
    ),
);
// Case D: a tenant whose sales_stage_dom has no 'Closed Won' (G346's refusal).
if (getenv('BD_NO_CLOSED_WON') === '1') {
    unset($GLOBALS['app_list_strings']['sales_stage_dom']['Closed Won']);
}

require getenv('BD_VALUATION');

// Private since PHP 8.1 needs no setAccessible(), and calling it emits a
// deprecation on 8.5 that would land in stderr.
$m = new \ReflectionMethod('ErpOpportunityValuation', 'releaseStageDecision');
$v = new \ErpOpportunityValuation();
$quote = new SugarBean();

$out = array('provider_on_disk' => file_exists(getenv('BD_POLICY_REL')));
foreach (array('partial' => true, 'final' => false) as $label => $partial) {
    try {
        $out[$label] = $m->invoke($v, $quote, $partial);
    } catch (\Throwable $e) {
        $out[$label] = array('threw' => get_class($e) . ': ' . $e->getMessage());
    }
}
echo json_encode($out);
}
"""


def _read_pf(name: str) -> str:
    return shared_sugar.resolve(name).read_text()


@unittest.skipUnless(shutil.which("php"), "requires php")
class ProviderAbsentMatchesProviderNull(unittest.TestCase):
    """PF's real decision, taken three ways over one quote."""

    @classmethod
    def run_pf(cls, provider_source: str | None, closed_won: bool = True,
               none_ordered: bool = False) -> dict:
        """Run PF's releaseStageDecision with cwd where PF looks for the file."""
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            # PF's own `require_once 'custom/modules/Quotes/ErpQuoteLineRollup.php'`
            # is relative too, so the neighbour has to sit where PF expects it.
            (root / "custom/modules/Quotes").mkdir(parents=True)
            (root / "custom/modules/Quotes/ErpQuoteLineRollup.php").write_text(
                _read_pf("ErpQuoteLineRollup.php"))
            valuation = root / "custom/modules/Quotes/ErpOpportunityValuation.php"
            valuation.write_text(_read_pf("ErpOpportunityValuation.php"))

            if provider_source is not None:
                hooks = root / "custom/modules/Quotes/ErpQuoteHooks"
                hooks.mkdir(parents=True)
                (hooks / "OpportunityReleaseStagePolicy.php").write_text(provider_source)

            script = root / "probe.php"
            script.write_text(HARNESS)
            proc = subprocess.run(
                ["php", str(script)], cwd=root, capture_output=True, text=True,
                env={**os.environ,
                     "BD_VALUATION": str(valuation),
                     "BD_POLICY_REL": POLICY_REL,
                     "BD_NO_CLOSED_WON": "" if closed_won else "1",
                     "BD_NONE_ORDERED": "1" if none_ordered else ""},
            )
            if "{" not in proc.stdout:
                raise AssertionError(
                    f"PF harness produced no JSON:\n{proc.stdout[-800:]}\n{proc.stderr[-800:]}")
            return json.loads(proc.stdout[proc.stdout.index("{"):])

    @classmethod
    def setUpClass(cls):
        cls.with_null_stub = cls.run_pf(RC65_STUB.read_text())
        cls.with_no_file = cls.run_pf(None)
        cls.with_decider = cls.run_pf(DECIDING_PROVIDER)
        cls.null_no_closed_won = cls.run_pf(RC65_STUB.read_text(), closed_won=False)
        cls.absent_no_closed_won = cls.run_pf(None, closed_won=False)
        cls.with_rc45_rc64 = cls.run_pf(RC45_RC64_BODY.read_text(encoding="utf-8"))
        cls.rc45_rc64_nothing_ordered = cls.run_pf(RC45_RC64_BODY.read_text(encoding="utf-8"),
                                                   none_ordered=True)

    # ---- the harness reached PF at all -------------------------------------

    def test_the_two_runs_really_differ_on_disk(self):
        self.assertTrue(self.with_null_stub["provider_on_disk"])
        self.assertFalse(self.with_no_file["provider_on_disk"])

    def test_pf_took_the_branch_each_run_claims(self):
        """The status string is PF's own name for the LOOKUP branch it took, and
        it is the only place the two runs are allowed to differ. On a normal
        tenant both answers are overwritten with the generic status - which is
        the point, but would also be what a probe that never reached the file
        at all would report. Case D (no `Closed Won`, G346) is where PF names
        the branch: that is the case that excludes a probe that never reached
        the file."""
        self.assertEqual(self.null_no_closed_won["final"]["status"], "policy_provider_null_config_invalid")
        self.assertEqual(self.absent_no_closed_won["final"]["status"], "policy_provider_absent_config_invalid")
        for run in (self.with_null_stub, self.with_no_file):
            self.assertEqual(run["partial"]["status"], "policy_generic_config_applied")
            self.assertEqual(run["final"]["status"], "policy_generic_ordered_in_full_applied")

    # ---- the removal itself -------------------------------------------------

    def test_absent_and_null_reach_the_same_decision(self):
        """rc66 stops shipping the stub. A tenant that took rc65 keeps the file
        (policy_provider_null); a fresh tenant has none (policy_provider_absent).
        Both must land on the same stage, partial AND final, or dropping the
        stub is a regression on one of them."""
        for release in ("partial", "final"):
            with self.subTest(release=release):
                self.assertEqual(self.with_null_stub[release]["decision"],
                                 self.with_no_file[release]["decision"])

    def test_that_shared_decision_is_the_configured_stage(self):
        """And it is not jointly empty: PF writes the stage post_install
        configured, at PF's own probability. A shared `None` would pass the
        equality above while meaning the stage stopped being written."""
        self.assertEqual(self.with_no_file["partial"]["decision"],
                         {"sales_stage": "Partial Production Ordered", "probability": 90})

    def test_a_final_release_moves_to_closed_won_either_way(self):
        """G346 (PF 1.0.50): a whole-quote order moves the Opportunity to
        `Closed Won` at sales_probability_dom's probability - for the rc65 null
        stub and for no file alike. This is what a tenant running Bench Dogs
        (rc69 or rc70) gets at runtime."""
        for run in (self.with_null_stub, self.with_no_file):
            self.assertEqual(run["final"]["decision"], {"sales_stage": "Closed Won", "probability": 100})

    def test_only_a_tenant_without_closed_won_preserves(self):
        """D. The ONLY route to `policy_provider_*_config_invalid` on a final
        release: sales_stage_dom without `Closed Won`. PF then preserves
        (never writes an unknown key) - for both shapes alike."""
        self.assertIsNone(self.null_no_closed_won["final"]["decision"])
        self.assertIsNone(self.absent_no_closed_won["final"]["decision"])
        self.assertEqual(self.null_no_closed_won["partial"]["decision"],
                         {"sales_stage": "Partial Production Ordered", "probability": 90})

    # ---- the mutation, in the suite rather than in a comment ----------------

    def test_a_provider_that_decides_is_visibly_different(self):
        """C. Without this case, `A == B` could mean the probe never reached PF.
        A deciding provider must outrank the config and the probe must see it."""
        self.assertEqual(self.with_decider["partial"]["decision"],
                         {"sales_stage": "Prototype Ordered", "probability": 80})
        self.assertNotEqual(self.with_decider["partial"]["decision"],
                            self.with_no_file["partial"]["decision"])


    # ---- E. G594: the rc45-rc64 residue a tenant keeps if it skipped rc65 --

    def test_e_the_residue_holds_a_final_release_at_the_partial_stage(self):
        """E. THE DEFECT, READ OUT OF PF. benchdogs-dev, quote #8, order 27600
        completed the quote and the Opportunity stayed Partial Production
        Ordered / 90. With the rc45-rc64 body at PF's hardcoded path, PF's
        `policy_valid` branch answers the partial stage on the FINAL release
        too, so G346's Closed Won is never reached."""
        self.assertTrue(self.with_rc45_rc64["provider_on_disk"])
        for release in ("partial", "final"):
            with self.subTest(release=release):
                self.assertEqual(self.with_rc45_rc64[release],
                                 {"decision": {"sales_stage": "Partial Production Ordered", "probability": 90},
                                  "status": "policy_valid"})

    def test_e_deleting_it_is_what_reaches_closed_won(self):
        """E, against B. The same final release with NOTHING at the path - what
        ONEOFF-RetireBdResidue 1.0.3 leaves behind - reaches Closed Won / 100,
        while the partial release is unchanged. So deleting the residue changes
        exactly the one decision G594 is about."""
        self.assertNotEqual(self.with_rc45_rc64["final"]["decision"], self.with_no_file["final"]["decision"])
        self.assertEqual(self.with_no_file["final"]["decision"], {"sales_stage": "Closed Won", "probability": 100})
        self.assertEqual(self.with_rc45_rc64["partial"]["decision"], self.with_no_file["partial"]["decision"])

    def test_e_the_residue_really_ran_its_own_line_loop(self):
        """Anti-vacuity for E: the body is not a constant. With no line ordered it
        throws ("No committed Quote line is visible"), which PF turns into
        `policy_provider_exception` and a preserved stage - so case E's answer
        came from the body reading the harness's ordered lines."""
        self.assertEqual(self.rc45_rc64_nothing_ordered["final"],
                         {"decision": None, "status": "policy_provider_exception"})


if __name__ == "__main__":
    unittest.main()
