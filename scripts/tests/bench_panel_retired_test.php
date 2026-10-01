<?php

/**
 * THE BENCH DOGS QUOTES PANEL IS RETIRED: NOTHING IT CARRIED SHIPS AGAIN.
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
 * package. The one-off ONEOFF-RetireBdResidue did it (its K-2) and ran it on
 * every QA tenant, so BenchDogs-Ext no longer ships the class or calls it.
 * Through 2026-09-30 this suite RAN that one-off's K-2.
 *
 * 🔁 🔒2173b (2026-09-30: "remove all the one offs I dont want that code")
 * withdrew the one-off and deleted its code, so the K-2 cases went with it and
 * nothing in this repository takes the panel off a deployed Quotes view any
 * more: a tenant K-2 never ran on keeps it. What is left here is this
 * package's side - it ships none of the panel's fields, the remover, the
 * duplicate notifier, or a reader of core's notification seam.
 */

namespace {
    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $ok = $expected === $actual;
        $checks[] = [$name, $ok, $expected, $actual];
    };

    $check('the shipped package carries no copy of the remover any more (rc69)', false,
        is_file(__DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/modules/Quotes/BdQuotesLayoutExtensions.php'));

    // 1. 🛑 THE FIELDS ARE GONE, NOT MERELY HIDDEN.
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
    // rc87: the emptied REST stub no longer ships at all (the one-off that deleted the tenant's copy is withdrawn, 🔒2173b).
    $check('the api stub no longer ships (rc87)', false, is_file($root . 'custom/clients/base/api/BdBenchDogsActionsApi.php'));
    foreach (['bd_erp_stage', 'bd_erp_total', 'bd_reason_code'] as $f) {
        $check("the {$f} vardef is GONE", false,
            is_file($root . 'custom/Extension/modules/Quotes/Ext/Vardefs/' . $f . '.php'));
    }
    // 🛑 INVERTED IN rc65, AND AGAIN IN rc69. rc65 made the path SHIP, empty,
    // because dropping a custom/Extension file from the build leaves the copy a
    // previous install made live on the tenant (§CW / G37). rc69 stopped shipping
    // it because the one-off DELETED that path on the tenant; 🔒2173b withdrew the
    // one-off, so only the package side is held here.
    $stageList = $root . 'custom/Extension/application/Ext/Language/en_us.bd_erp_stage_list.php';
    $check('the bd_erp_stage_list path no longer ships (rc69)', false, is_file($stageList));

    // 2. 🚩 AND THE DUPLICATE NOTIFICATION HOOK WENT WITH THE FIELD IT WATCHED.
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
    $benchCode = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . 'custom', FilesystemIterator::SKIP_DOTS)) as $f) {
        $benchCode .= preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', (string) file_get_contents((string) $f));
    }
    $check('no Bench code touches the estimating notification seam', false,
        str_contains($benchCode, 'ErpEstimatingNotificationHook'));

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
    exit($failed ? 1 : 0);
}
