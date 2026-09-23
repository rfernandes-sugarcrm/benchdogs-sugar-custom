<?php

/**
 * G116: bd_governing_origin IS TAKEN OFF THE OPPORTUNITY RECORD VIEW ON
 * INSTALL, AND NEVER PUT BACK.
 *
 * Run:  php scripts/tests/bench_governing_origin_retired_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * THE CASE THAT REGRESSED. 🔒 1044 retired `bd_governing_origin` on
 * Opportunities: the vardef and the en_us label were both overwritten with
 * stubs that declare NOTHING. What it did not touch was
 * scripts/post_execute.php, which went on calling
 * BdOpportunitiesLayoutExtensions::writeGoverningOriginField() on EVERY
 * install — appending the field with label `LBL_BD_GOVERNING_ORIGIN`. On a
 * live tenant that is a row whose header is the raw LBL_ key and whose value
 * is "No data": exactly the G96/G99 shape that had just been declared closed
 * on Quotes. The census that declared it closed read QUOTES module metadata
 * only, so it could not have seen an OPPORTUNITIES placement.
 *
 * So the check that matters is not "is there a placement in the source" — it
 * is "AFTER A RE-INSTALL, is the field on the view". A tenant that installed
 * rc26..rc56 already has it, and deployed metadata is covered by no
 * installdef: only something that RUNS can take it back off.
 *
 * 🔁 0.9.42-rc69 (G280 / 🔒 1567, 🔒 1521): the removal is SPENT in the shipped
 * package. The one-off ONEOFF-RetireBdResidue carries a verbatim copy of this
 * class (its K-3), ran it on every QA tenant, and deletes both 🔒 1044 stubs.
 * So BenchDogs-Ext no longer ships the class, calls it, or ships the stubs. This
 * suite now runs the ONE-OFF'S copy - the only implementation that still
 * reaches a tenant - and asserts the package carries none of it.
 *
 * 🚩 THIS TEST RUNS remove(). It does not scan it. The defect that produced
 * the Quotes half of this change was a handler bound to the wrong EVENT,
 * invisible to every source scan in its package, so a scan is no longer
 * accepted here as evidence that a layout mutation does what it says.
 *
 * HONESTLY, ABOUT WHICH CHECKS DID THE WORK. Checks 1-9 run the removal, and
 * they PASS against rc56 as well: remove() has always been correct, it was
 * simply not what the installer called. The checks that go red on rc56 are
 * 10-13 - the writer is gone, and post_install.php calls the removal instead.
 * The behavioural half is here so that a future edit to remove() cannot
 * quietly stop removing while 10-13 stay green.
 */

// ---------------------------------------------------------------------------
// Stubs, declared before the class under test is included.
// ---------------------------------------------------------------------------
namespace Sugarcrm\Sugarcrm\MetaData {
    class ViewdefManager
    {
        public static $loaded = null;
        public static $saved = null;
        public static $saveCount = 0;

        public function loadViewdef($client, $module, $view)
        {
            return self::$loaded;
        }

        public function saveViewdef($defs, $module, $client, $view): void
        {
            self::$saved = $defs;
            self::$saveCount++;
        }
    }
}

namespace {
    class MetaDataFiles
    {
        public static function clearModuleClientCache($module, $type): void
        {
        }
    }

    class TemplateHandler
    {
        public static function clearCache($module): void
        {
        }
    }

    use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

    // TemplateHandler.php is include_once'd by deployRecordView; give the
    // include path a real file so the run is warning-free.
    $tmp = sys_get_temp_dir() . '/bd_governing_test_' . getmypid();
    @mkdir($tmp . '/include/TemplateHandler', 0777, true);
    file_put_contents($tmp . '/include/TemplateHandler/TemplateHandler.php', "<?php\n");
    set_include_path($tmp . PATH_SEPARATOR . get_include_path());

    $root = __DIR__ . '/../../sugar-sell/BenchDogs-Ext/';
    $oneoff = __DIR__ . '/../../sugar-sell/ONEOFF-RetireBdResidue/';
    $oneoffLib = $oneoff . 'lib/BdOpportunitiesLayoutExtensions.php';
    require $oneoffLib;

    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $checks[] = [$name, $expected === $actual, $expected, $actual];
    };

    $field = 'bd_governing_origin';

    $reset = function (?array $panels) {
        ViewdefManager::$loaded = $panels === null ? null : ['panels' => $panels];
        ViewdefManager::$saved = null;
        ViewdefManager::$saveCount = 0;
    };
    $savedFieldNames = function (): array {
        $names = [];
        foreach (ViewdefManager::$saved['panels'] ?? [] as $panel) {
            foreach ($panel['fields'] ?? [] as $f) {
                $names[] = is_array($f) ? ($f['name'] ?? '') : (string) $f;
            }
        }
        return $names;
    };

    // What the one-off's post_execute calls (K-3). Named once, so this file
    // cannot drift from it by testing a method nothing runs.
    $install = function (): void {
        \BdOpportunitiesLayoutExtensions::remove();
    };

    // 1. 🛑 THE RE-INSTALL CASE. This is the state rc26..rc56 left on every
    //    tenant: the field appended to panel_body by the previous install.
    $reset([
        ['name' => 'panel_header', 'fields' => [['name' => 'name']]],
        ['name' => 'panel_body', 'fields' => [
            ['name' => 'amount'],
            ['name' => $field, 'label' => 'LBL_BD_GOVERNING_ORIGIN', 'readonly' => true],
            ['name' => 'sales_stage'],
        ]],
    ]);
    $install();
    $check('a field left by a previous install is removed on re-install',
        false, in_array($field, $savedFieldNames(), true));
    $check('and every other field on the view survives untouched',
        ['name', 'amount', 'sales_stage'], $savedFieldNames());

    // 2. An admin may have MOVED it. remove() sweeps every panel, so a moved
    //    field is cleaned up rather than left as the orphan the sweep exists
    //    to prevent.
    $reset([
        ['name' => 'panel_header', 'fields' => [['name' => 'name'], $field]],
        ['name' => 'panel_body', 'fields' => [['name' => 'amount']]],
    ]);
    $install();
    $check('a field an admin moved to another panel is removed too',
        false, in_array($field, $savedFieldNames(), true));
    $check('including when it is a BARE STRING entry, which Sugar allows',
        ['name', 'amount'], $savedFieldNames());

    // 3. It is NEVER ADDED to a view that does not have it. This is the whole
    //    defect: the old writeGoverningOriginField() added it here.
    $reset([['name' => 'panel_body', 'fields' => [['name' => 'amount']]]]);
    $install();
    $check('it is never added to a view without it', 0, ViewdefManager::$saveCount);

    // 4. Idempotent: the install after the one that cleaned up writes nothing.
    $reset([['name' => 'panel_body', 'fields' => [['name' => 'amount'], ['name' => $field]]]]);
    $install();
    $first = ViewdefManager::$saveCount;
    $reset(ViewdefManager::$saved['panels'] ?? []);
    $install();
    $check('the first install writes once', 1, $first);
    $check('and a second install writes nothing', 0, ViewdefManager::$saveCount);

    // 5. A view it cannot read is left alone rather than overwritten with a
    //    nearly empty custom file.
    $reset(null);
    $install();
    $check('an unreadable record view is never written over', 0, ViewdefManager::$saveCount);
    $reset([]);
    $install();
    $check('nor is one with no panels at all', 0, ViewdefManager::$saveCount);

    // 6. 🚩 THE WRITER WENT WITH THE PLACEMENT. A member that can never run
    //    reads as live machinery, and this package has already paid three
    //    install cycles for code that looked active and was not.
    $src = file_get_contents($oneoffLib);
    $check('writeGoverningOriginField() is gone', false,
        str_contains($src, 'function writeGoverningOriginField'));
    $check('and so is the panel-picking helper it used', false,
        str_contains($src, 'function indexOf'));

    // 7. 🛑 THE SHIPPED PACKAGE NEITHER WRITES NOR REMOVES IT ANY MORE (rc69),
    //    and the one-off is what calls the removal. Comments are stripped
    //    first: post_execute.php NAMES what it no longer does, and a blunt
    //    substring match would read that explanation as the defect.
    $strip = fn(string $f) => preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', file_get_contents($f));
    $postCode = $strip($root . 'scripts/post_execute.php');
    $check('post_execute.php does not call the writer', false,
        str_contains($postCode, 'writeGoverningOriginField'));
    $check('post_execute.php no longer carries the spent removal either', false,
        str_contains($postCode, 'BdOpportunitiesLayoutExtensions'));
    $check('the one-off calls the removal', true,
        str_contains($strip($oneoff . 'scripts/post_execute.php'), 'BdOpportunitiesLayoutExtensions::remove();'));
    $check('and the package ships no copy of the class', false,
        is_file($root . 'custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php'));

    // 8. 🔒 1044's two stubs. Through rc68 they SHIPPED, declaring nothing,
    //    because only overwriting a copied custom/Extension file retired it
    //    (§CW / G37). From rc69 the one-off DELETES both paths on the tenant,
    //    so they no longer ship - and the one-off must still name both.
    $oneoffSrc = file_get_contents($oneoff . 'scripts/post_execute.php');
    foreach ([
        'the vardef stub' => ['Vardefs/bd_governing_origin.php',
            "array('to_module' => 'Opportunities', 'name' => 'bd_governing_origin')"],
        'the label stub' => ['Language/en_us.bd_governing_origin.php',
            "array('to_module' => 'Opportunities', 'name' => 'en_us.bd_governing_origin')"],
    ] as $what => [$rel, $entry]) {
        $check("{$what} no longer ships", false,
            is_file($root . 'custom/Extension/modules/Opportunities/Ext/' . $rel));
        $check("{$what} is on the one-off's worklist", true, str_contains($oneoffSrc, $entry));
    }

    $failed = 0;
    foreach ($checks as $n => [$name, $ok, $expected, $actual]) {
        printf("%s  %2d. %s\n", $ok ? 'PASS' : 'FAIL', $n + 1, $name);
        if (!$ok) {
            $failed++;
            printf("       expected: %s\n       actual:   %s\n",
                var_export($expected, true), var_export($actual, true));
        }
    }
    printf("\n%d checks, %d failed\n", count($checks), $failed);
    @unlink($tmp . '/include/TemplateHandler/TemplateHandler.php');
    exit($failed ? 1 : 0);
}
