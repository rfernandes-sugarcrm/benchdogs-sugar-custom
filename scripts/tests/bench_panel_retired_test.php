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
 * package's side: no shipped code reads or re-implements core's estimating
 * notification seam, so a second notifier cannot come back under another file
 * name.
 */

namespace {
    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $ok = $expected === $actual;
        $checks[] = [$name, $ok, $expected, $actual];
    };

    // rc98: the checks that held the panel's long-retired files out of the package
    // (its three vardefs, the stage list, the api stub, the remover, the duplicate
    // notification hook and its registration) are gone: the package's kept list
    // (test_g280_minimal_footprint.py) pins every file it ships, source and zip.
    $root = __DIR__ . '/../../sugar-sell/BenchDogs-Ext/';
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
