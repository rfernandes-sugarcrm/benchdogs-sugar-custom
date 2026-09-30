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
 * package. The one-off ONEOFF-RetireBdResidue does it (its K-2) and ran it on
 * every QA tenant, so BenchDogs-Ext no longer ships the class or calls it.
 * From one-off 1.0.6 K-2 is inline in the one-off's post_execute (ViewdefManager,
 * no class); this suite RUNS that script - the only implementation that still
 * reaches a tenant - and asserts the package ships none of it.
 *
 * 🚩 THIS TEST RUNS K-2. It does not scan it. The defect that produced
 * this whole change was a handler bound to the wrong EVENT, invisible to
 * every source scan in its package, so a scan is no longer accepted here as
 * evidence that a layout mutation does what it says.
 */

// ---------------------------------------------------------------------------
// Stubs, declared before the one-off's post_execute runs.
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
    // No MetaDataFiles / TemplateHandler stubs: the one-off makes no cache call (MLP021), so one creeping back fatals here.
    use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

    $oneoff = __DIR__ . '/../../sugar-sell/ONEOFF-RetireBdResidue/';
    $leftovers = require $oneoff . 'scripts/leftovers.php';
    // 1.0.6 (Rafael's one-off layout): K-2 runs inline in the one-off's post_execute through ViewdefManager. Each case
    // below RUNS that script, with Module Loader's file removals stubbed out, so the removal is exercised, not scanned.
    class BdPanelHarnessInstaller
    {
        public $base_dir;
        public function __construct($d) { $this->base_dir = $d; }
        public function uninstall_new_files($cp, $backup) {}
        public function uninstall_customizations($beans) {}
        public function run(): string
        {
            $manifest = array('version' => 'test');
            ob_start();
            require $this->base_dir . '/scripts/post_execute.php';
            return (string) ob_get_clean();
        }
    }
    $GLOBALS['log'] = new class { public function __call($n, $a) {} };
    $install = function () use ($oneoff): string {
        return (new \BdPanelHarnessInstaller(rtrim($oneoff, '/')))->run();
    };

    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $ok = $expected === $actual;
        $checks[] = [$name, $ok, $expected, $actual];
    };

    $check('the shipped package carries no copy of the remover any more (rc69)', false,
        is_file(__DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/modules/Quotes/BdQuotesLayoutExtensions.php'));
    $check('and the one-off ships no class of its own for it any more (1.0.6)', false,
        is_dir($oneoff . 'leftovers') || is_dir($oneoff . 'lib'));

    $panelName = 'LBL_RECORDVIEW_PANEL_BENCHDOGS';
    $otherPanel = ['name' => 'LBL_RECORDVIEW_PANEL_ERP', 'fields' => [['name' => 'erp_sync_key']]];

    // The Opportunities view (K-3) is left null throughout, so every save counted here is the Quotes one.
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
    $report = $install();
    $check('a deployed Bench Dogs panel is removed on install',
        false, in_array($panelName, $savedPanelNames(), true));
    $check('and the other packages\' panels survive untouched',
        ['LBL_RECORDVIEW_PANEL_ERP'], $savedPanelNames());
    $check('with their fields intact', [['name' => 'erp_sync_key']], ViewdefManager::$saved['panels'][0]['fields'] ?? null);
    // K-2 sits in a try/catch, so a step that throws AFTER saving (a cache call to a class Sugar never loads here, say)
    // still saves; only the run's own report shows it.
    $check('and the run reports it removed, with nothing FAILED', [true, false],
        [str_contains($report, 'K-2 Bench Dogs panel spliced out'), str_contains($report, 'FAILED (')]);

    // 2. A view an admin duplicated the panel on loses every copy.
    $reset([['name' => $panelName, 'fields' => []], $otherPanel, ['name' => $panelName, 'fields' => []]]);
    $install();
    $check('every copy of the panel goes, not only the first', ['LBL_RECORDVIEW_PANEL_ERP'], $savedPanelNames());

    // 3. It is NEVER ADDED to a view that does not have it.
    $reset([$otherPanel]);
    $install();
    $check('it is never added to a view without it', 0, ViewdefManager::$saveCount);

    // 4. Idempotent: a second run after removal writes nothing.
    $reset([$otherPanel, ['name' => $panelName, 'fields' => []]]);
    $install();
    $first = ViewdefManager::$saveCount;
    $reset(ViewdefManager::$saved['panels'] ?? []);
    $install();
    $check('the first run writes once', 1, $first);
    $check('and a second run writes nothing', 0, ViewdefManager::$saveCount);

    // 5. A view it cannot read, or one with no panels, is left alone rather than overwritten.
    $reset(null);
    $install();
    $check('an unreadable record view is never written over', 0, ViewdefManager::$saveCount);
    $reset([]);
    $install();
    $check('nor is one with no panels at all', 0, ViewdefManager::$saveCount);

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
    // rc87: the emptied REST stub no longer ships at all; the one-off deletes the tenant's copy.
    $check('the api stub no longer ships (rc87)', false, is_file($root . 'custom/clients/base/api/BdBenchDogsActionsApi.php'));
    $check('and the one-off deletes it on the tenant', true,
        in_array('custom/clients/base/api/BdBenchDogsActionsApi.php', $leftovers['remove'], true));
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
        in_array('custom/Extension/application/Ext/Language/en_us.bd_erp_stage_list.php', $leftovers['remove'], true));

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
