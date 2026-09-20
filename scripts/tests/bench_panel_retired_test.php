<?php

/**
 * THE BENCH DOGS PANEL IS REMOVED FROM THE QUOTES RECORD VIEW, AND NEVER ADDED.
 *
 * Run:  php scripts/tests/bench_panel_retired_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * Owner, on Bench 2026-09-20, looking at quote 273:
 *   "shoudl not have BD here why we have bd"
 *   "wh i see this copy this should be form core"   (against a header that
 *                                                    rendered as
 *                                                    LBL_RECORDVIEW_PANEL_BENCHDOGS)
 *
 * WHY REMOVAL AND NOT A LABEL. Every field the panel carried is a private
 * Bench copy of something core already owns. The ruling is recorded in
 * connector_ext_benchdogs/models/crm/sell/quote_kpi.py under 🔒 1045: "only
 * bd_customer_group / bd_customer_group_code stay in the Bench layer;
 * everything else belongs to core and its MLPs". Measured across the
 * connector lanes: bd_erp_total -> core quote_total (identical Epicor
 * DocTotalQuote), bd_reason_code -> core erp_reason_code, bd_erp_stage ->
 * core owns the lifecycle on native quote_stage, and bd_sent_to_estimating_at
 * is written by ZERO python files anywhere. Adding the missing label would
 * only have made a dead field look supported in Studio and the report builder.
 *
 * 🚩 THIS TEST RUNS write(). It does not scan it. The defect that produced
 * this whole change was a handler bound to the wrong EVENT, invisible to
 * every source scan in its package, so a scan is no longer accepted here as
 * evidence that a layout mutation does what it says.
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
    $tmp = sys_get_temp_dir() . '/bd_panel_test_' . getmypid();
    @mkdir($tmp . '/include/TemplateHandler', 0777, true);
    file_put_contents($tmp . '/include/TemplateHandler/TemplateHandler.php', "<?php\n");
    set_include_path($tmp . PATH_SEPARATOR . get_include_path());

    require __DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/modules/Quotes/BdQuotesLayoutExtensions.php';

    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $ok = $expected === $actual;
        $checks[] = [$name, $ok, $expected, $actual];
    };

    $panelName = 'LBL_RECORDVIEW_PANEL_BENCHDOGS';
    $otherPanel = ['name' => 'LBL_RECORDVIEW_PANEL_ERP', 'fields' => [['name' => 'erp_sync_key']]];

    $reset = function (?array $panels) {
        ViewdefManager::$loaded = $panels === null ? null : ['panels' => $panels];
        ViewdefManager::$saved = null;
        ViewdefManager::$saveCount = 0;
    };
    $savedPanelNames = function (): array {
        $p = ViewdefManager::$saved['panels'] ?? [];
        return array_map(fn($x) => is_array($x) ? ($x['name'] ?? '') : (string) $x, $p);
    };

    // 1. A deployed panel is REMOVED.
    $reset([
        $otherPanel,
        ['name' => $panelName, 'fields' => [
            ['name' => 'bd_erp_total'], ['name' => 'bd_erp_stage'], ['name' => 'bd_reason_code'],
        ]],
    ]);
    BdQuotesLayoutExtensions::write();
    $check('a deployed Bench Dogs panel is removed on install',
        false, in_array($panelName, $savedPanelNames(), true));
    $check('and the other packages\' panels survive untouched',
        ['LBL_RECORDVIEW_PANEL_ERP'], $savedPanelNames());

    // 2. It is NEVER ADDED to a view that does not have it.
    $reset([$otherPanel]);
    BdQuotesLayoutExtensions::write();
    $check('it is never added to a view without it', 0, ViewdefManager::$saveCount);
    $reset([$otherPanel]);
    BdQuotesLayoutExtensions::write(true);
    $check('not even with $replace = true (both values mean the same now)',
        0, ViewdefManager::$saveCount);

    // 3. Idempotent: a second install after removal writes nothing.
    $reset([$otherPanel, ['name' => $panelName, 'fields' => []]]);
    BdQuotesLayoutExtensions::write();
    $first = ViewdefManager::$saveCount;
    $reset(ViewdefManager::$saved['panels'] ?? []);
    BdQuotesLayoutExtensions::write();
    $check('the first install writes once', 1, $first);
    $check('and a second install writes nothing', 0, ViewdefManager::$saveCount);

    // 4. A view it cannot read is left alone rather than overwritten.
    $reset(null);
    BdQuotesLayoutExtensions::write();
    // Honestly: deleting the null guard does NOT fail this check, because the
    // "$at === false -> return" path catches the same case a line later. The
    // check pins the OUTCOME, which holds either way; it is not evidence that
    // the null guard specifically is load-bearing.
    $check('an unreadable record view is never written over', 0, ViewdefManager::$saveCount);

    // 5. 🚩 The dead helpers went out WITH the panel. Members that can never
    //    run read as live machinery; this package has already paid three
    //    install cycles for code that looked active and was not.
    $src = file_get_contents(__DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/modules/Quotes/BdQuotesLayoutExtensions.php');
    $check('benchDogsPanel() is gone', false, str_contains($src, 'function benchDogsPanel'));
    $check('dropRetiredPanelFields() is gone', false, str_contains($src, 'function dropRetiredPanelFields'));

    // 6. 🛑 THE FIELDS AND THEIR WRITER ARE NOT TOUCHED. "Nothing shows it"
    //    and "nothing writes it" are different claims. bd_erp_stage still has
    //    a live writer, and removing the panel must not have removed it.
    $api = __DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/clients/base/api/BdBenchDogsActionsApi.php';
    $check('the bd_erp_stage writer still exists',
        true, str_contains(file_get_contents($api), "bd_erp_stage = 'in_estimating'"));
    $check('the bd_erp_total vardef still exists', true,
        is_file(__DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/Extension/modules/Quotes/Ext/Vardefs/bd_erp_total.php'));

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
