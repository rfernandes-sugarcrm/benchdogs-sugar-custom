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
 * 🔁 0.9.42-rc69 (G280 / 🔒 1567, 🔒 1521): the removal is SPENT in the shipped
 * package. The one-off ONEOFF-RetireBdResidue carries a verbatim copy of this
 * class (its K-2) and ran it on every QA tenant, so BenchDogs-Ext no longer
 * ships the class or calls it. This suite now runs the ONE-OFF'S copy - the
 * only implementation that still reaches a tenant - and asserts the package
 * ships none of it.
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

    $oneoffLib = __DIR__ . '/../../sugar-sell/ONEOFF-RetireBdResidue/lib/BdQuotesLayoutExtensions.php';
    require $oneoffLib;

    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $ok = $expected === $actual;
        $checks[] = [$name, $ok, $expected, $actual];
    };

    $check('the shipped package carries no copy of the remover any more (rc69)', false,
        is_file(__DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/modules/Quotes/BdQuotesLayoutExtensions.php'));

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
    $src = file_get_contents($oneoffLib);
    $check('benchDogsPanel() is gone', false, str_contains($src, 'function benchDogsPanel'));
    $check('dropRetiredPanelFields() is gone', false, str_contains($src, 'function dropRetiredPanelFields'));

    // 6. 🛑 THE FIELDS ARE GONE, NOT MERELY HIDDEN.
    //
    // An earlier pass removed the panel and LEFT the fields and their writer
    // in place, reasoning that "nothing shows it" and "nothing writes it" are
    // different claims. True, but not what was asked: the owner's words were
    // "I asked to remove bd_erp_stage". Hiding a duplicate leaves it in
    // Studio, the report builder and every column picker, which is how the
    // retired bd_sent_to_estimating_at survived to reach a live quote screen.
    //
    // The three fields were private copies of core-owned facts (🔒 1045), and
    // each call site moved to the core field it duplicated:
    //   bd_erp_stage   -> native quote_stage = 'In Estimating'
    //   bd_erp_total   -> erp_estimate_total
    //   bd_reason_code -> erp_reason_code (no live reader remained)
    $root = __DIR__ . '/../../sugar-sell/BenchDogs-Ext/';
    $api = file_get_contents($root . 'custom/clients/base/api/BdBenchDogsActionsApi.php');
    // Comments stripped: the file NAMES bd_erp_stage in the note explaining
    // why it no longer writes it, and a blunt substring match would read that
    // explanation as the defect it documents.
    $apiCode = preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $api);
    $check('the bd_erp_stage writer is GONE (executable code)',
        false, str_contains($apiCode, 'bd_erp_stage'));
    // 0.9.42-rc65 (G280 / 🔒 1507): the stamp itself is CORE's now. This package
    // no longer ships bd-send-to-estimating, so there is no Bench writer to
    // check - what must hold is that it writes NEITHER stage field.
    $check('and the API writes no quote stage at all any more',
        false, str_contains($apiCode, '->quote_stage ='));
    $check('the retired estimating route is unregistered', false,
        str_contains($apiCode, 'bd-send-to-estimating'));
    $check('and so is the duplicate opportunity-quote route', false,
        str_contains($apiCode, 'bd-create-opp-quote'));
    // 0.9.42-rc69 (G280 / 🔒 1567): the admin repair route went too - it re-ran
    // K-2/K-3, both spent. The file ships EMPTY, so no route survives at all.
    $check('and so does the admin repair route (rc69)', false,
        str_contains($apiCode, "'bd-tools', 'repair-ui'"));
    $check('the api file declares no class at all (rc69)', false,
        str_contains($apiCode, 'class '));
    foreach (['bd_erp_stage', 'bd_erp_total', 'bd_reason_code'] as $f) {
        $check("the {$f} vardef is GONE", false,
            is_file($root . 'custom/Extension/modules/Quotes/Ext/Vardefs/' . $f . '.php'));
    }
    // 🛑 INVERTED IN rc65, AND AGAIN IN rc69. rc65 made the path SHIP, empty,
    // because dropping a custom/Extension file from the build leaves the copy a
    // previous install made live on the tenant (§CW / G37). rc69 stops shipping
    // it because the one-off DELETES that path on the tenant - so what must hold
    // is that the one-off still names it.
    $stageList = $root . 'custom/Extension/application/Ext/Language/en_us.bd_erp_stage_list.php';
    $check('the bd_erp_stage_list path no longer ships (rc69)', false, is_file($stageList));
    $check('and the one-off still deletes it on the tenant', true,
        str_contains(file_get_contents(__DIR__ . '/../../sugar-sell/ONEOFF-RetireBdResidue/scripts/post_execute.php'),
                     "array('to_module' => 'application', 'name' => 'en_us.bd_erp_stage_list')"));

    // 7. 🚩 AND THE DUPLICATE NOTIFICATION HOOK WENT WITH THE FIELD IT WATCHED.
    //
    // BdEstimatingNotificationHook was a second implementation of ERP-Core's
    // ErpEstimatingNotificationHook, differing only in watching bd_erp_stage
    // rather than the native quote_stage. Core registers BOTH legs
    // (notifyEstimating, notifyPricingReturned) on after_save and de-dupes by
    // sync key. Repointing the Bench copy at the same field would have
    // DOUBLE-NOTIFIED and broken the exactly-once rule this journey is graded
    // on -- so it is retired, not repointed. This check is what stops it
    // coming back.
    $check('the duplicate Bench notification hook is GONE', false,
        is_file($root . 'custom/modules/Quotes/BdEstimatingNotificationHook.php'));
    $check('and so is its logic-hook registration', false,
        is_file($root . 'custom/Extension/modules/Quotes/Ext/LogicHooks/bd_estimating_notification.php'));
    // 🛑 INVERTED IN rc65. This asserted the Bench estimating route CONSUMED
    // core's notification outcome. rc65 deleted that route (G280 / 🔒 1507):
    // core's 'Send to Estimation' both stamps the stage and notifies, so there
    // is no Bench reader left to keep honest. What must hold is that this
    // package never grows a second notifier or a second reader of that seam.
    $check('no Bench code touches the estimating notification seam', false,
        str_contains($apiCode, 'ErpEstimatingNotificationHook'));

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
