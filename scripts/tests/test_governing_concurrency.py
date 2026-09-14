"""D29-R1: two simultaneous governing selections must not mutually demote.

Live evidence, journal sections D29-LIVE-1 (pre-declaration) and D29-LIVE-2
(results), 2026-09-14 20:17:06-20:28:51Z on the Bench tenant `ophirsx177`
(Bench Dogs 0.9.42-rc25 / ERP-Epicor 1.1.24-rc23): two PUTs fired as
`Promise.all` on different lines of ERP quote 1193 landed the quote on ZERO
governing lines in 2 of 3 clean trials. Both PUTs returned `governing:false`
to their callers while the Opportunity kept a number belonging to a line that
was no longer selected. Decision 29 requires the selection to be fail-closed;
a zero-selected quote reached by an ordinary two-estimator gesture is not a
refusal anyone asked for.

WHAT THIS TEST DOES AND DOES NOT PROVE
--------------------------------------
It reproduces the interleaving deterministically inside ONE PHP process. Two
things are modelled, and they are the two things that make the live defect
possible:

  1. Sugar commits the row in `SugarBean::save()` BEFORE `after_save` fires,
     and each request then holds its own in-memory copy of that row. The
     second process therefore still believes `governing` is 1 on its own line
     no matter what the first process did to the stored row. That is modelled
     by handing each simulated hook a `clone` of the row it wrote.
  2. A lock makes a second writer wait. That is modelled by a stub of Sugar's
     `SystemProcessLock\\DbImplementation` plus a blocked-writer queue.

It does NOT run two operating-system processes and therefore does NOT prove
that Sugar's `system_process_lock` table really excludes concurrent PHP
workers. What it proves is that the enforcement code takes the lock before it
writes anything, and that the algorithm inside the lock converges on exactly
one selection under both interleavings measured live.

The PUT response is not simulated because there is nothing custom to simulate:
selection goes through stock `ModuleApi::updateRecord`, which ends in
`getLoadedAndFormattedBean` -> `reloadBean($api, $args, 'view',
['use_cache' => false])`. The `governing` a caller is told is a fresh database
read of the row, so the stored flags asserted here ARE what each caller is
told.
"""

import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = ROOT.parent
PACKAGE = "benchdogs-sugar-custom/sugar-sell/BenchDogs-Ext"
SHARED_HOOK = (
    WORKSPACE / "erp-integration-sugar/sugar-sell/ERP-Core/src/custom/modules"
    / "Quotes/QuoteOpportunityAmount.php"
)

# Sugar platform doubles that have to exist under their real namespace. eval()
# is used because a namespace declaration cannot be mixed with the global code
# below it in a single `php -r` script.
PLATFORM = r'''
eval('namespace Sugarcrm\\Sugarcrm\\Util\\Files;'
    . ' class FileLoader { public static function validateFilePath($path) { return $path; } }');

// system_process_lock stub. It records every acquire/refuse/release/reap so a
// test can prove the enforcement actually holds the lock while it writes, and
// it releases blocked writers in order, which is how "the second estimator
// waits" is modelled without a second OS process.
eval('namespace Sugarcrm\\Sugarcrm\\SystemProcessLock;'
    . ' class DbImplementation {'
    . '   public $isAvailable = true;'
    . '   public function lock(string $uniqueId, string $additionalKey, int $timeout): bool {'
    . '     $key = $uniqueId . "|" . $additionalKey;'
    . '     if (!empty($GLOBALS["lock_held"][$key])) {'
    . '       $GLOBALS["lock_events"][] = "refused " . $key; return false;'
    . '     }'
    . '     $GLOBALS["lock_held"][$key] = true;'
    . '     $GLOBALS["lock_events"][] = "acquire " . $key;'
    . '     return true;'
    . '   }'
    . '   public function unlock(string $uniqueId, string $additionalKey): void {'
    . '     $key = $uniqueId . "|" . $additionalKey;'
    . '     unset($GLOBALS["lock_held"][$key]);'
    . '     $GLOBALS["lock_events"][] = "release " . $key;'
    . '     while (!empty($GLOBALS["lock_blocked"])) {'
    . '       $blocked = array_shift($GLOBALS["lock_blocked"]); $blocked();'
    . '     }'
    . '   }'
    . '   public function processTimedOutLocks(): void { $GLOBALS["lock_events"][] = "reap"; }'
    . ' }');
'''

FIXTURE = PLATFORM + r'''
$GLOBALS['lock_held'] = [];
$GLOBALS['lock_events'] = [];
$GLOBALS['lock_blocked'] = [];
$GLOBALS['row_writes'] = [];
$GLOBALS['unlocked_writes'] = [];
$GLOBALS['on_row_save'] = [];
$GLOBALS['bean_reads'] = [];
$GLOBALS['log'] = new class {
    public $errors = [];
    public $warns = [];
    public $infos = [];
    public function info($message) { $this->infos[] = $message; }
    public function warn($message) { $this->warns[] = $message; }
    public function error($message) { $this->errors[] = $message; }
    public function fatal($message) {}
    public function debug($message) {}
};

#[AllowDynamicProperties]
class SugarBean {
    public $id = '';
    public $deleted = 0;
    public $saves = 0;
    public $field_defs = [];
    public $currency_id = '-99';
    public $base_rate = 1;
    public function load_relationship($name) { return isset($this->$name); }
    public function save() {
        $this->saves++;
        if (!isset($this->doc_ext_price)) {
            return;
        }
        // Stored rows only: this is the visible history of the race.
        $write = $this->id . '=' . (empty($this->governing) ? '0' : '1');
        $GLOBALS['row_writes'][] = $write;
        if (empty($GLOBALS['lock_held'])) {
            // A selection row written while no lock is held is a write that
            // another request can cross. That is the D29-R1 mechanism.
            $GLOBALS['unlocked_writes'][] = $write;
        }
        if (isset($GLOBALS['on_row_save'][$this->id])) {
            $work = $GLOBALS['on_row_save'][$this->id];
            unset($GLOBALS['on_row_save'][$this->id]); // one shot
            $work();
        }
    }
}
class Opportunity extends SugarBean {
    public static function usingRevenueLineItems() { return false; }
}
class SugarCurrency {
    public static function convertAmount($amount, $from, $to) { return $amount; }
}
class TestLink {
    public function __construct(public $beans = [], public $ids = []) {}
    public function get() { return $this->ids; }
    public function getBeans() { return $this->beans; }
}
class BeanFactory {
    public static $beans = [];
    public static function retrieveBean($module, $id = null, $options = []) {
        $GLOBALS['bean_reads'][] = $module . '/' . $id;
        return self::$beans[$module][$id] ?? null;
    }
    public static function newBean($module) {
        throw new Exception('Unexpected record creation: ' . $module);
    }
}

require 'benchdogs-sugar-custom/sugar-sell/BenchDogs-Ext/custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php';
require 'benchdogs-sugar-custom/sugar-sell/BenchDogs-Ext/custom/modules/bd01_ERP_Quote_Line/BdGoverningLineHook.php';
require 'erp-integration-sugar/sugar-sell/ERP-Core/src/custom/modules/Quotes/QuoteOpportunityAmount.php';

// From here on the process behaves like a Sugar instance: relative requires
// and the shared writer's file_exists() probe resolve against the package
// root, exactly as they do from a Sugar web root.
chdir('benchdogs-sugar-custom/sugar-sell/BenchDogs-Ext');

// ---------------------------------------------------------------------------
// The live fixture: Kinetic 1193, the demo video's own quote. Prototype 450
// plus the 50/75/100 ladder; 450 + 6400 + 8400 + 9600 = 24,850 native total.
// Shipping 17.50 is the charge the live window proved contributes; tax stayed
// 0 because Sugar recalculates it (journal D29-LIVE-2, A4).
// ---------------------------------------------------------------------------
$erp = new SugarBean();
$erp->id = 'owned-erp-quote';
$erp->quote_num = 1193;
$erp->sugar_quote_id = 'owned-quote';
$erp->bd_materialize_status = 'adopted';

$rows = [];
foreach ([['proto', 450.00, true], ['brk50', 6400.00, false],
          ['brk75', 8400.00, false], ['brk100', 9600.00, false]] as $index => $spec) {
    $row = new SugarBean();
    $row->id = $spec[0];
    $row->name = 'ERP line ' . $spec[0];
    $row->line_num = $index + 1;
    $row->part_num = 'BD-' . $spec[0];
    $row->selling_qty = 1;
    $row->doc_unit_price = $spec[1];
    $row->doc_ext_price = $spec[1];
    $row->prototype = $spec[2];
    $row->governing = 0;
    // The line side of the same relationship: one parent quote per line.
    $row->bd01_erp_quote_lines = new TestLink([], [$erp->id]);
    $rows[$row->id] = $row;
}
$erp->bd01_erp_quote_lines = new TestLink(array_values($rows));

$quote = new SugarBean();
$quote->id = 'owned-quote';
$quote->total = 24850.00;
$quote->tax = 0;
$quote->shipping = 17.50;
$quote->erp_is_primary_quote = true;
$quote->opportunities = new TestLink([], ['owned-opportunity']);
$quote->products = new TestLink([]);
// Exactly one ERP quote on the link, so the provider's OTHER refusal
// (count($erpQuotes) !== 1) cannot confound a result - the same control the
// live window applied to Kinetic 1193.
$quote->bd01_erp_quote_quotes = new TestLink([$erp]);

$opp = new Opportunity();
$opp->id = 'owned-opportunity';
$opp->amount = 33.33; // the live window's sentinel
$opp->sales_stage = 'Prospecting';
$opp->date_closed = '2026-12-31';

BeanFactory::$beans = [
    'Opportunities' => ['owned-opportunity' => $opp],
    'Quotes' => ['owned-quote' => $quote],
    'bd01_ERP_Quote' => ['owned-erp-quote' => $erp],
    'bd01_ERP_Quote_Line' => $rows,
];

// One PUT: Sugar commits the row, then hands after_save a bean whose in-memory
// governing is 1 - which stays 1 in THIS request no matter what another
// request does to the stored row. Returning a clone is what makes the two
// simulated requests independent.
$put = function (string $lineId) use ($rows) {
    $rows[$lineId]->governing = 1;
    $GLOBALS['row_writes'][] = $lineId . '=1';
    return clone $rows[$lineId];
};
$fire = function (SugarBean $local) {
    (new BdGoverningLineHook())->enforceSingleGoverning($local, 'after_save', [
        'dataChanges' => [['field_name' => 'governing', 'before' => 0, 'after' => 1]],
    ]);
};
// Two simultaneous PUTs are two PHP processes, so the hook's per-process
// re-entrancy static is not shared between them. setAccessible() is not
// called: it has been a no-op since PHP 8.1 and is deprecated in 8.5.
$newProcess = function (callable $work) {
    $flag = new ReflectionProperty('BdGoverningLineHook', 'inProgress');
    $was = $flag->getValue();
    $flag->setValue(null, false);
    try { $work(); } finally { $flag->setValue(null, $was); }
};
'''

REPORT = r'''
$flags = [];
$selected = [];
foreach ($rows as $id => $row) {
    $flags[$id] = empty($row->governing) ? 0 : 1;
    if (!empty($row->governing)) { $selected[] = $id; }
}
echo json_encode([
    'flags' => $flags,
    'selected' => $selected,
    'amount' => $opp->amount,
    'opportunity_saves' => $opp->saves,
    'row_writes' => $GLOBALS['row_writes'],
    'unlocked_writes' => $GLOBALS['unlocked_writes'],
    'lock_events' => $GLOBALS['lock_events'],
    'bean_reads' => $GLOBALS['bean_reads'],
    'errors' => $GLOBALS['log']->errors,
    'warns' => $GLOBALS['log']->warns,
]);
'''

# Contribution for a single selection: prototype 450 + the line + tax 0 +
# shipping 17.50. These are the numbers the live window read back.
BRK50_SELECTED = 6867.50
BRK75_SELECTED = 8867.50
SENTINEL = 33.33


@unittest.skipUnless(shutil.which("php"), "requires a PHP 8.2+ build-test image")
@unittest.skipUnless(SHARED_HOOK.is_file(), "requires sibling shared Sugar checkout")
class GoverningConcurrencyTest(unittest.TestCase):
    maxDiff = None

    def execute(self, scenario):
        result = subprocess.run(
            ["php", "-r", FIXTURE + scenario + REPORT],
            cwd=WORKSPACE, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "", result.stderr)
        return json.loads(result.stdout)

    def test_sequential_switch_is_unchanged_by_the_fix(self):
        """Control. A5/A6a live: switching demotes the previous line and the
        headline follows. If the concurrency fix changed this, it would be a
        regression, not a fix."""
        observed = self.execute(r'''
$newProcess(fn() => $fire($put('brk50')));
$first = $opp->amount;
$newProcess(fn() => $fire($put('brk75')));
''')
        self.assertEqual(observed["selected"], ["brk75"], observed)
        self.assertEqual(observed["amount"], BRK75_SELECTED, observed)
        self.assertEqual(observed["errors"], [], observed)

    def test_simultaneous_selections_do_not_both_lose(self):
        """D29-R1 as measured: both rows are committed before either after_save
        runs, so each hook demotes the other's line. 2 of 3 live trials.

        On the untouched parent this lands on ZERO selected with the
        Opportunity holding brk50's number - a number for a line that is not
        selected. That is the defect, in one assertion."""
        observed = self.execute(r'''
$a = $put('brk50');
$b = $put('brk75');
$newProcess(fn() => $fire($a));
$newProcess(fn() => $fire($b));
''')
        self.assertEqual(
            len(observed["selected"]), 1,
            "a concurrent double-select must leave exactly one governing line, "
            "not zero: " + json.dumps(observed),
        )
        # Last writer wins: brk75's hook ran second, so brk75 is the selection.
        self.assertEqual(observed["selected"], ["brk75"], observed)
        # The stored flags ARE what each caller is told (stock reloadBean), so
        # the caller told false is telling the truth only if the other caller's
        # line really is the selection - and the headline must agree with it.
        self.assertEqual(observed["amount"], BRK75_SELECTED, observed)
        self.assertNotEqual(
            observed["amount"], SENTINEL,
            "the headline must be the winner's number, not a preserved stale "
            "value: " + json.dumps(observed),
        )

    def test_a_writer_never_demotes_without_holding_the_lock(self):
        """D29-R1's finer interleaving: the second request arrives while the
        first is between its own claim and its demotion. Without mutual
        exclusion the two demotions cross and the quote lands on zero with the
        headline never written at all."""
        observed = self.execute(r'''
$a = $put('brk50');
$b = $put('brk75');
// The second estimator's request arrives exactly as the first one demotes
// their line. If a lock is held it waits; if there is no lock it proceeds.
$GLOBALS['on_row_save']['brk75'] = function () use ($b, $fire, $newProcess) {
    $start = function () use ($b, $fire, $newProcess) { $newProcess(fn() => $fire($b)); };
    if (!empty($GLOBALS['lock_held'])) { $GLOBALS['lock_blocked'][] = $start; return; }
    $start();
};
$newProcess(fn() => $fire($a));
''')
        self.assertEqual(
            len(observed["selected"]), 1,
            "an interleaved double-select must leave exactly one governing "
            "line: " + json.dumps(observed),
        )
        self.assertEqual(observed["selected"], ["brk75"], observed)
        self.assertEqual(observed["amount"], BRK75_SELECTED, observed)
        self.assertIn(
            "acquire bd_governing_line|owned-erp-quote", observed["lock_events"],
            "the enforcement must take the quote's lock before it writes: "
            + json.dumps(observed),
        )
        self.assertEqual(
            observed["unlocked_writes"], [],
            "every selection row this hook writes must be written while it "
            "holds the quote's lock: " + json.dumps(observed),
        )

    def test_the_claim_is_re_read_from_the_database_inside_the_lock(self):
        """The lock alone cannot fix D29-R1. Both rows are already committed
        when the hooks run, so the second hook must notice that its own row was
        demoted while it waited and re-assert it. If it trusts the bean it was
        handed, it demotes the winner and the pair still cancels out."""
        observed = self.execute(r'''
$a = $put('brk50');
$b = $put('brk75');
$newProcess(fn() => $fire($a));
$newProcess(fn() => $fire($b));
''')
        self.assertIn("bd01_ERP_Quote_Line/brk75", observed["bean_reads"], observed)
        # brk75 is demoted by the first hook and written back by the second.
        self.assertIn("brk75=0", observed["row_writes"], observed)
        self.assertEqual(
            observed["row_writes"][-1].split("=")[0], "brk50",
            "the winner's re-assertion must precede the loser's demotion so "
            "the quote never passes through zero selected: "
            + json.dumps(observed["row_writes"]),
        )

    def test_a_refused_lock_never_produces_a_zero_selected_quote(self):
        """If the lock cannot be taken at all, demoting anyway is what lands
        the quote on zero. Refusing to demote can leave two lines selected,
        which the provider already refuses (fail-closed) and which a person can
        see in the picker. Zero, which nobody can see, must be unreachable."""
        observed = self.execute(r'''
$GLOBALS['lock_held']['bd_governing_line|owned-erp-quote'] = true; // held elsewhere
$a = $put('brk50');
$b = $put('brk75');
$newProcess(fn() => $fire($a));
$newProcess(fn() => $fire($b));
''')
        self.assertGreaterEqual(
            len(observed["selected"]), 1,
            "no enforcement path may leave zero governing lines: "
            + json.dumps(observed),
        )
        self.assertNotIn("proto", observed["selected"], observed)


if __name__ == "__main__":
    unittest.main()
