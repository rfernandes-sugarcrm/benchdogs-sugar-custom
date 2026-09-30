<?php

/** One-off cleanup (G599, G594): takes the Bench Dogs package's retirement items off the tenant; install it, let it run, uninstall it. */

// Guarded on the class, not the path: ModuleInstaller is already loaded during an
// install, and a second require through a different resolved path is what killed
// ERP-Epicor 1.1.8 with "Cannot redeclare class".
if (!class_exists('ModuleInstaller', false)) {
    require_once 'ModuleInstall/ModuleInstaller.php';
}

// The version that is actually running, read from the manifest in scope, never typed.
$bdOneoffVersion = isset($manifest['version']) ? (string) $manifest['version'] : 'unknown';

$bdRemoved = array();
$bdAlreadyGone = array();
$bdFailed = array();
$bdSkipped = array();

/** The extension fragments (K-1, K-4 registration half, K-5 source, D-3): every custom/Extension path BenchDogs-Ext ever installed (🔒 1508, 🔒 1514). */
$bdExtensionGroups = array(
    'Vardefs' => array(
        array('to_module' => 'Contacts', 'name' => 'bd_contact_sync_fields'),
        array('to_module' => 'ERP_OrderLines', 'name' => 'bd_shipped_value'),
        array('to_module' => 'ERP_Orders', 'name' => 'bd_shipped_value_total'),
        array('to_module' => 'Opportunities', 'name' => 'bd_forecast_provenance'),
        array('to_module' => 'Opportunities', 'name' => 'bd_governing_origin'),
        array('to_module' => 'Products', 'name' => 'bd_cost_worksheet'),
        array('to_module' => 'Products', 'name' => 'bd_deleted_erp_sync_key'),
        array('to_module' => 'Products', 'name' => 'bd_governing_line_fields'),
        array('to_module' => 'Products', 'name' => 'bd_line_order_fields'),
        array('to_module' => 'Quotes', 'name' => 'bd01_erp_quote_quotes'),
        array('to_module' => 'Quotes', 'name' => 'bd_comment_pending'),
        array('to_module' => 'Quotes', 'name' => 'bd_comment_requested_at'),
        array('to_module' => 'Quotes', 'name' => 'bd_comment_text'),
        array('to_module' => 'Quotes', 'name' => 'bd_deleted_erp_sync_key'),
        array('to_module' => 'Quotes', 'name' => 'bd_erp_kpi_inputs'),
        array('to_module' => 'Quotes', 'name' => 'bd_erp_stage'),
        array('to_module' => 'Quotes', 'name' => 'bd_erp_stage_code'),
        array('to_module' => 'Quotes', 'name' => 'bd_erp_total'),
        array('to_module' => 'Quotes', 'name' => 'bd_estimating_turnaround'),
        array('to_module' => 'Quotes', 'name' => 'bd_governing_line'),
        array('to_module' => 'Quotes', 'name' => 'bd_order_requested_at'),
        array('to_module' => 'Quotes', 'name' => 'bd_priced_at'),
        array('to_module' => 'Quotes', 'name' => 'bd_print_link'),
        array('to_module' => 'Quotes', 'name' => 'bd_print_requested_at'),
        array('to_module' => 'Quotes', 'name' => 'bd_print_status'),
        array('to_module' => 'Quotes', 'name' => 'bd_quantity_breaks'),
        array('to_module' => 'Quotes', 'name' => 'bd_reason_code'),
        array('to_module' => 'RevenueLineItems', 'name' => 'bd_deliverable_key'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => 'bd01_erp_quote_lines'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => 'bd01_erp_quote_quotes'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => 'bd_estimating_turnaround'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => 'bd_materialize'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => 'bd_quote_completion'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => 'bd_reason_code_erp'),
        array('to_module' => 'bd01_ERP_Quote_Cost', 'name' => 'bd01_erp_line_costs'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => 'bd01_erp_line_costs'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => 'bd01_erp_quote_lines'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => 'bd_governing_origin'),
    ),
    'Language' => array(
        array('to_module' => 'Accounts', 'name' => 'en_us.bd_action_buttons'),
        array('to_module' => 'ERP_OrderLines', 'name' => 'en_us.bd_shipped_value'),
        array('to_module' => 'ERP_Orders', 'name' => 'en_us.bd_shipped_value_total'),
        array('to_module' => 'Opportunities', 'name' => 'en_us.bd_governing_origin'),
        array('to_module' => 'Products', 'name' => 'en_us.bd_cost_worksheet'),
        array('to_module' => 'Products', 'name' => 'en_us.bd_deleted_erp_sync_key'),
        array('to_module' => 'Products', 'name' => 'en_us.bd_governing_line_fields'),
        array('to_module' => 'Products', 'name' => 'en_us.bd_line_order'),
        array('to_module' => 'Quotes', 'name' => 'en_us.bd_action_buttons'),
        array('to_module' => 'Quotes', 'name' => 'en_us.bd_deleted_erp_sync_key'),
        array('to_module' => 'Quotes', 'name' => 'en_us.bd_erp_fields'),
        array('to_module' => 'Quotes', 'name' => 'en_us.bd_quantity_breaks'),
        array('to_module' => 'application', 'name' => '_override_en_us.bd_country_lookup'),
        array('to_module' => 'application', 'name' => 'en_us.bd_country_lookup'),
        array('to_module' => 'application', 'name' => 'en_us.bd_erp_stage_list'),
        array('to_module' => 'application', 'name' => 'en_us.bd_stage_doms'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => 'en_us.bd01_erp_quote_quotes_subpanel'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => 'en_us.bd_turnaround_fields'),
        array('to_module' => 'bd01_ERP_Quote_Cost', 'name' => 'en_us.bd01_erp_line_costs_subpanel'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => 'en_us.bd01_erp_quote_lines_subpanel'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => 'en_us.bd_governing_origin'),
    ),
    'LogicHooks' => array(
        array('to_module' => 'Accounts', 'name' => 'bd_account_country_guard'),
        array('to_module' => 'Contacts', 'name' => 'bd_contact_sync'),
        array('to_module' => 'Products', 'name' => 'bd_governing_autoselect'),
        array('to_module' => 'Products', 'name' => 'bd_governing_line'),
        array('to_module' => 'Quotes', 'name' => 'bd_estimating_notification'),
        array('to_module' => 'Quotes', 'name' => 'bd_estimating_turnaround'),
        array('to_module' => 'Quotes', 'name' => 'bd_kinetic_opportunity'),
        array('to_module' => 'Quotes', 'name' => 'bd_primary_quote'),
        array('to_module' => 'Quotes', 'name' => 'bd_quote_carrier_canonical'),
        array('to_module' => 'Quotes', 'name' => 'bd_quote_kpi'),
        array('to_module' => 'Quotes', 'name' => 'bd_sync_key_release'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => 'bd_quote_reflection'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => 'bd_governing_autoselect'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => 'bd_governing_line'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => 'bd_quote_line_refresh'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => 'bd_rli_refresh'),
    ),
    // 1.0.4 (G599): md5-GUARDED - SugarCRM itself writes this path too. See
    // $bdGuardedExtensionBodies below; a body Bench Dogs never shipped is LEFT.
    'DropdownsStyle' => array(
        array('to_module' => 'application', 'name' => 'sales_stage_dom_style'),
    ),
    'TableDictionary' => array(
        array('to_module' => 'application', 'name' => 'zzz_bd_quotes_erp_orders_history'),
    ),
    'clients/base/layouts/subpanels' => array(
        array('to_module' => 'Accounts', 'name' => 'bd_subpanel_erp_quotes'),
        array('to_module' => 'Products', 'name' => 'bd_subpanel_rung_costs'),
        array('to_module' => 'Quotes', 'name' => '_overridesubpanel-for-quotes-bd01_erp_quote_quotes'),
        array('to_module' => 'Quotes', 'name' => 'bd_subpanel_erp_quotes'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => '_overridesubpanel-for-bd01_erp_quote-bd01_erp_quote_lines'),
        array('to_module' => 'bd01_ERP_Quote', 'name' => 'bd_subpanel_quote_lines'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => '_overridesubpanel-for-bd01_erp_quote_line-bd01_erp_line_costs'),
        array('to_module' => 'bd01_ERP_Quote_Line', 'name' => 'bd_subpanel_line_costs'),
    ),
    'clients/base/views/record' => array(
        array('to_module' => 'ERP_OrderLines', 'name' => 'bd_shipped_value'),
        array('to_module' => 'ERP_Orders', 'name' => 'bd_shipped_value_total'),
    ),
);

/** No closure and no helper: the path is built inline twice, because a variable call is refused by ModuleScanner (MLP017). */

/** 1.0.4 (G599): an extension path another writer shares is deleted only when its body is one Bench Dogs shipped. */
$bdGuardedExtensionBodies = array(
    'custom/Extension/application/Ext/DropdownsStyle/sales_stage_dom_style.php' => array(
        // BenchDogs-Ext 0.9.42-rc11 (zip only; unguarded '... Closed' pair)
        '4f3312f2ea33e251f7e568f624bc4eb9',
        // rc12-rc38 (blob b43dbd42): the '... Closed' pair, append-only
        '4be4aa516991c01d838f4c98ba333fd9',
        // rc39-rc64 (blob bd5753be): the '... Ordered' pair, append-only
        '1c851a6bed5179086ee7cabe3bce6613',
        // rc65-rc68 (blob c4944ae7): the emptied stub
        'b3a90dcc1702b3191bef89b63a245ee0',
    ),
);
$bdGuardedFoundMd5 = array();

$bdInstaller = new ModuleInstaller();
$bdInstaller->silent = true;

foreach ($bdExtensionGroups as $bdSubdir => $bdItems) {
    // Only ask the platform to remove entries that are on this tenant, so the report can tell removed from never here.
    $bdPresent = array();
    foreach ($bdItems as $bdItem) {
        if ($bdItem['to_module'] === 'application') {
            $bdPath = 'custom/Extension/application/Ext/' . $bdSubdir . '/' . $bdItem['name'] . '.php';
        } else {
            $bdPath = 'custom/Extension/modules/' . $bdItem['to_module'] . '/Ext/' . $bdSubdir
                . '/' . $bdItem['name'] . '.php';
        }
        if (!file_exists($bdPath)) {
            $bdAlreadyGone[] = $bdPath;
            continue;
        }
        if (isset($bdGuardedExtensionBodies[$bdPath])) {
            $bdGuardMd5 = md5_file($bdPath);
            if (!in_array($bdGuardMd5, $bdGuardedExtensionBodies[$bdPath], true)) {
                $bdSkipped[] = $bdPath . ' - LEFT: not a Bench body, md5 ' . $bdGuardMd5 . '. SugarCRM'
                    . ' itself writes this path (the Dropdown Editor, Studio, and the dropdown-style sync'
                    . ' at the end of every Module Loader install and every Quick Repair), so it holds'
                    . ' this tenant\'s own sales-stage styling and is not this package\'s to delete (G599).';
                continue;
            }
            $bdGuardedFoundMd5[$bdPath] = $bdGuardMd5;
        }
        $bdPresent[] = $bdItem;
    }

    if (!$bdPresent) {
        continue;
    }

    $bdInstaller->installdefs = array('bd_residue' => $bdPresent);
    try {
        $bdInstaller->uninstallExt('bd_residue', $bdSubdir);
    } catch (Throwable $e) {
        foreach ($bdPresent as $bdItem) {
            $bdFailed[] = 'custom/Extension/... /' . $bdItem['to_module'] . '/Ext/' . $bdSubdir
                . '/' . $bdItem['name'] . '.php (' . $e->getMessage() . ')';
        }
        continue;
    }

    // Re-read the filesystem rather than trusting the call. uninstallExt() returns
    // nothing and swallows a miss, so "it ran" is not "it went".
    foreach ($bdPresent as $bdItem) {
        if ($bdItem['to_module'] === 'application') {
            $bdPath = 'custom/Extension/application/Ext/' . $bdSubdir . '/' . $bdItem['name'] . '.php';
        } else {
            $bdPath = 'custom/Extension/modules/' . $bdItem['to_module'] . '/Ext/' . $bdSubdir
                . '/' . $bdItem['name'] . '.php';
        }
        if (file_exists($bdPath)) {
            $bdFailed[] = $bdPath . ' (still present after uninstallExt)';
        } elseif (isset($bdGuardedFoundMd5[$bdPath])) {
            $bdRemoved[] = $bdPath . ' (a body Bench Dogs shipped, md5 ' . $bdGuardedFoundMd5[$bdPath] . ')';
        } else {
            $bdRemoved[] = $bdPath;
        }
    }
}

/** The Bench-only client-field directories (G15). */
$bdDirectories = array(
    // The three retired bd01 quote-mirror modules; ONEOFF-RetireBdQuoteMirror makes the same idempotent call.
    'bd01_ERP_Quote',
    'bd01_ERP_Quote_Line',
    'bd01_ERP_Quote_Cost',
    'Accounts/clients/base/fields/bd-create-opp-quote',
    'Quotes/clients/base/fields/bd-best-pricing',
    'Quotes/clients/base/fields/bd-order-selected',
    'Quotes/clients/base/fields/bd-order-winning',
    'Quotes/clients/base/fields/bd-send-estimating',
);

$bdDirsPresent = array();
foreach ($bdDirectories as $bdDir) {
    if (file_exists('custom/modules/' . $bdDir)) {
        $bdDirsPresent[] = $bdDir;
    } else {
        $bdAlreadyGone[] = 'custom/modules/' . $bdDir . '/';
    }
}

if ($bdDirsPresent) {
    try {
        $bdInstaller->uninstall_customizations($bdDirsPresent);
        foreach ($bdDirsPresent as $bdDir) {
            if (file_exists('custom/modules/' . $bdDir)) {
                $bdFailed[] = 'custom/modules/' . $bdDir . '/ (still present)';
            } else {
                $bdRemoved[] = 'custom/modules/' . $bdDir . '/';
            }
        }
    } catch (Throwable $e) {
        $bdFailed[] = 'client field directories (' . $e->getMessage() . ')';
    }
}

/** Where this package's own files are: the unpacked package post_execute is required from. */
$bdPackageDir = dirname(__DIR__);

/** K-2 and K-3: the Bench Dogs panel spliced out of the deployed Quotes record view, and the retired bd_governing_origin marker off the Opportunity record view. */
// No dynamic dispatch: a variable static call is refused by ModuleScanner and MLP017, so the two calls are written out.

/** How these two report: a removal is claimed only when the deployed view actually changed. */
$bdQuotesViewdef = 'custom/modules/Quotes/clients/base/views/record/record.php';
$bdQuotesLib = $bdPackageDir . '/lib/BdQuotesLayoutExtensions.php';
try {
    if (!class_exists('BdQuotesLayoutExtensions', false) && file_exists($bdQuotesLib)) {
        require_once $bdQuotesLib;
    }
    if (class_exists('BdQuotesLayoutExtensions', false)) {
        $bdBefore = file_exists($bdQuotesViewdef) ? md5_file($bdQuotesViewdef) : '';
        // write() REMOVES the panel and never adds it - see its own docblock.
        BdQuotesLayoutExtensions::write();
        $bdAfter = file_exists($bdQuotesViewdef) ? md5_file($bdQuotesViewdef) : '';
        if ($bdBefore !== $bdAfter) {
            $bdRemoved[] = 'K-2 Bench Dogs panel spliced out of the deployed Quotes record view';
        } else {
            $bdAlreadyGone[] = 'K-2 Bench Dogs panel - already absent from the deployed Quotes record view';
        }
    } else {
        $bdSkipped[] = 'K-2 Quotes panel - SKIPPED, lib/BdQuotesLayoutExtensions.php did not load from '
            . $bdQuotesLib;
    }
} catch (Throwable $e) {
    $bdFailed[] = 'K-2 Quotes panel (' . $e->getMessage() . ')';
}

$bdOppsViewdef = 'custom/modules/Opportunities/clients/base/views/record/record.php';
$bdOppsLib = $bdPackageDir . '/lib/BdOpportunitiesLayoutExtensions.php';
try {
    if (!class_exists('BdOpportunitiesLayoutExtensions', false) && file_exists($bdOppsLib)) {
        require_once $bdOppsLib;
    }
    if (class_exists('BdOpportunitiesLayoutExtensions', false)) {
        $bdBefore = file_exists($bdOppsViewdef) ? md5_file($bdOppsViewdef) : '';
        // remove() sweeps EVERY panel, so an admin who moved the field is cleaned
        // up too, and it writes nothing when there is nothing to remove.
        BdOpportunitiesLayoutExtensions::remove();
        $bdAfter = file_exists($bdOppsViewdef) ? md5_file($bdOppsViewdef) : '';
        if ($bdBefore !== $bdAfter) {
            $bdRemoved[] = 'K-3 retired bd_governing_origin marker removed from the deployed Opportunities record view';
        } else {
            $bdAlreadyGone[] = 'K-3 retired bd_governing_origin marker - already absent from the deployed Opportunities record view';
        }
    } else {
        $bdSkipped[] = 'K-3 Opportunity marker - SKIPPED, lib/BdOpportunitiesLayoutExtensions.php did not load from '
            . $bdOppsLib;
    }
} catch (Throwable $e) {
    $bdFailed[] = 'K-3 Opportunity marker (' . $e->getMessage() . ')';
}

/** K-5: the accumulated zz_bd_stage_doms language fragment (🔒 314). */
$bdStageFragment = 'custom/Extension/application/Ext/Language/en_us.zz_bd_stage_doms.php';
try {
    $bdMi = new ModuleInstaller();
    $bdMi->silent = true;
    $bdMi->id_name = 'zz_bd_stage_doms';
    $bdMi->base_dir = getcwd();
    $bdMi->installdefs = array(
        'language' => array(
            array(
                'from' => 'custom/dropdowntemplates/bd_stage_doms.append.php',
                'to_module' => 'application',
                'language' => 'en_us',
            ),
        ),
    );
    $bdHadStageFragment = file_exists($bdStageFragment);
    $bdMi->uninstall_languages();
    if ($bdHadStageFragment && !file_exists($bdStageFragment)) {
        $bdRemoved[] = $bdStageFragment . ' (K-5 accumulated stage vocabulary)';
    } elseif ($bdHadStageFragment) {
        $bdFailed[] = $bdStageFragment . ' (still present after uninstall_languages)';
    } else {
        $bdAlreadyGone[] = $bdStageFragment . ' (K-5)';
    }
} catch (Throwable $e) {
    $bdFailed[] = $bdStageFragment . ' (' . $e->getMessage() . ')';
}

/** The orphaned class files (K-4's tombstone and 20 others), blanked with lib/emptied.php (G280, 🔒 1567, 🔒 1508, 🔒 1511). */
$bdOrphanClasses = array(
    'custom/dropdowntemplates/bd_stage_doms.append.php',
    'custom/modules/Accounts/BdAccountCountryGuard.php',
    'custom/modules/Contacts/BdContactSyncHook.php',
    'custom/modules/Opportunities/BdAutoSelectedReport.php',
    'custom/modules/Products/BdGoverningAutoSelect.php',
    'custom/modules/Products/BdGoverningAutoSelectHook.php',
    'custom/modules/Products/BdGoverningLineHook.php',
    'custom/modules/Products/BdGoverningLineLock.php',
    'custom/modules/Products/BdGoverningValuationRefresh.php',
    'custom/modules/Products/BdSyncKeyReleaseHook.php',
    'custom/modules/Quotes/BdEstimatingNotificationHook.php',
    'custom/modules/Quotes/BdEstimatingTurnaround.php',
    // K-4's tombstone. Its registration fragment
    // custom/Extension/modules/Quotes/Ext/LogicHooks/bd_kinetic_opportunity.php
    // was deleted in the first step above, so nothing requires this any more.
    'custom/modules/Quotes/BdKineticOpportunityHook.php',
    'custom/modules/Quotes/BdPrimaryQuoteHook.php',
    'custom/modules/Quotes/BdQliColumnTemplate.php',
    'custom/modules/Quotes/BdQliColumnsLayout.php',
    'custom/modules/Quotes/BdQuantityBreakTab.php',
    'custom/modules/Quotes/BdQuantityBreaksTabLayout.php',
    'custom/modules/Quotes/BdQuoteCarrierCanonicalHook.php',
    'custom/modules/Quotes/BdQuoteKpiHook.php',
    'custom/modules/Quotes/BdSubmitOrderPlan.php',
);

$bdEmptySource = $bdPackageDir . '/lib/emptied.php';
if (!file_exists($bdEmptySource)) {
    // Refuse the whole group rather than blank some of it. A half-done sweep is
    // the one outcome that cannot be told apart from a finished one on the next
    // run, and this package's only product is evidence.
    $bdSkipped[] = 'orphaned class files - SKIPPED ENTIRELY, lib/emptied.php not found at '
        . $bdEmptySource . '; ' . count($bdOrphanClasses) . ' path(s) left exactly as they were';
} else {
    // Idempotency: a blanked file still exists, so its digest against lib/emptied.php tells a second run from a first.
    $bdEmptyHash = md5_file($bdEmptySource);
    foreach ($bdOrphanClasses as $bdOrphan) {
        if (!file_exists($bdOrphan)) {
            $bdAlreadyGone[] = $bdOrphan;
            continue;
        }
        if ($bdEmptyHash !== false && md5_file($bdOrphan) === $bdEmptyHash) {
            $bdAlreadyGone[] = $bdOrphan . ' (already blank)';
            continue;
        }
        try {
            $bdInstaller->copy_path($bdEmptySource, $bdOrphan);
            // Re-read rather than trust the call, the same way the Extension
            // sweep does.
            if (md5_file($bdOrphan) === $bdEmptyHash) {
                $bdRemoved[] = $bdOrphan . ' (blanked)';
            } else {
                $bdFailed[] = $bdOrphan . ' (copy_path returned but the body did not change)';
            }
        } catch (Throwable $e) {
            $bdFailed[] = $bdOrphan . ' (' . $e->getMessage() . ')';
        }
    }
}

/** 4b (1.0.2, G200): the orphaned order adapter ErpQuoteHooks/ResolveOrderableLines.php, undoing a regression 1.0.0 / 1.0.1 caused. */
$bdAdapter = 'custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php';
$bdPlanner = 'custom/modules/Quotes/BdSubmitOrderPlan.php';
$bdAdapterBenchMd5 = array(
    // BenchDogs-Ext 0.9.42-rc37..rc40 (17a87c0, blob 2963abf0908d95a99c69a3b6abf5c0151d7b53a6)
    'f6c3d4747ccbf822e0c6ba27b6360656',
);
$bdNoBackup = $bdPackageDir . '/no-backup/' . $bdAdapter;
$bdAdapterLeft = array();

if (!file_exists($bdAdapter)) {
    $bdAlreadyGone[] = $bdAdapter . ' (the Bench Dogs order adapter)';
} else {
    $bdAdapterMd5 = md5_file($bdAdapter);
    $bdPlannerCannotAnswer = !file_exists($bdPlanner)
        || (isset($bdEmptyHash) && $bdEmptyHash !== false && md5_file($bdPlanner) === $bdEmptyHash);
    if (!in_array($bdAdapterMd5, $bdAdapterBenchMd5, true)) {
        $bdSkipped[] = $bdAdapter . ' - LEFT: its body (md5 ' . $bdAdapterMd5 . ') is not the adapter'
            . ' Bench Dogs shipped, so it is not this package\'s to delete.'
            . ($bdPlannerCannotAnswer
                ? ' The Bench Dogs planner is absent or blank; if Submit Order refuses with'
                    . ' "order planner is not installed", this file is the cause.'
                : '');
    } elseif (!$bdPlannerCannotAnswer) {
        $bdAdapterLeft[] = $bdAdapter . ' - LEFT: Bench Dogs\' adapter, but its planner ' . $bdPlanner
            . ' still has a body, so the pair still answers. Step 4 should have blanked it; see FAILED.';
    } elseif (file_exists($bdNoBackup)) {
        $bdSkipped[] = $bdAdapter . ' - NOT DELETED: the no-backup source ' . $bdNoBackup
            . ' exists, and copy_path would copy it over the adapter instead of deleting it.';
    } else {
        try {
            $bdInstaller->copy_path($bdNoBackup, $bdAdapter, $bdNoBackup, true);
            if (file_exists($bdAdapter)) {
                $bdFailed[] = $bdAdapter . ' (still present after copy_path)';
            } else {
                $bdRemoved[] = $bdAdapter . ' (Bench Dogs\' order adapter, deleted: its planner is '
                    . (file_exists($bdPlanner) ? 'blank' : 'absent') . ', so it refused every Submit Order)';
            }
        } catch (Throwable $e) {
            $bdFailed[] = $bdAdapter . ' (' . $e->getMessage() . ')';
        }
    }
}

/** 4c (1.0.3, G594): the orphaned release-stage policy ErpQuoteHooks/OpportunityReleaseStagePolicy.php (G346, G37). */
$bdReleasePolicy = 'custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php';
$bdReleasePolicyBenchMd5 = array(
    // rc4 (blob 434b0658): reads the bd01 quote mirror, answers '... Closed'
    'faf17dd27859c430cbd26fa4b07b6029',
    // rc5-rc6 (blob b786a4a0)
    '8333e547ebad159117fae54c50d2d4c0',
    // rc7-rc28 (blob 625a75e7)
    '43eecd851e272aa42aed84ded3b83e0d',
    // rc29-rc38 (blob d2844953)
    'cf0969c0c3f76dc50307aed4c4e903f0',
    // rc39-rc40 (zip only: the rc29 body with both stages renamed '... Ordered')
    'b442f750afbf9e96a1f434809ac5bd0a',
    // rc42-rc44 (blob 4dc71dfc): the mirror lookup, answers '... Ordered'
    'da037878d13206aee9124a397de986a4',
    // rc45-rc64 (blob fbb81c48): native lines, ALWAYS 'Partial Production Ordered'
    // once any line is ordered - THE G594 BODY (benchdogs-dev, from rc60)
    'e5e6e3fff432a5dcd6624accf6bb8598',
    // rc65 (blob f83bf852): the null stub
    '32fd2941e268cc8c9153e0f865c0ffc7',
);
$bdReleaseNoBackup = $bdPackageDir . '/no-backup/' . $bdReleasePolicy;

if (!file_exists($bdReleasePolicy)) {
    $bdAlreadyGone[] = $bdReleasePolicy . ' (the Bench Dogs release-stage policy)';
} else {
    $bdReleasePolicyMd5 = md5_file($bdReleasePolicy);
    if (!in_array($bdReleasePolicyMd5, $bdReleasePolicyBenchMd5, true)) {
        $bdSkipped[] = $bdReleasePolicy . ' - LEFT: its body (md5 ' . $bdReleasePolicyMd5 . ') is not a'
            . ' release-stage policy Bench Dogs shipped, so it is not this package\'s to delete. If an'
            . ' Opportunity never reaches Closed Won after the order that completes its quote, this file'
            . ' is the cause: Partial Fulfillment obeys whatever stage it answers (G594).';
    } elseif (file_exists($bdReleaseNoBackup)) {
        $bdSkipped[] = $bdReleasePolicy . ' - NOT DELETED: the no-backup source ' . $bdReleaseNoBackup
            . ' exists, and copy_path would copy it over the policy instead of deleting it.';
    } else {
        try {
            $bdInstaller->copy_path($bdReleaseNoBackup, $bdReleasePolicy, $bdReleaseNoBackup, true);
            if (file_exists($bdReleasePolicy)) {
                $bdFailed[] = $bdReleasePolicy . ' (still present after copy_path)';
            } else {
                $bdRemoved[] = $bdReleasePolicy . ' (Bench Dogs\' release-stage policy, md5 '
                    . $bdReleasePolicyMd5 . ', deleted: Partial Fulfillment now decides the Opportunity'
                    . ' stage itself - Closed Won once an order leaves nothing open, G594)';
            }
        } catch (Throwable $e) {
            $bdFailed[] = $bdReleasePolicy . ' (' . $e->getMessage() . ')';
        }
    }
}

/** What this package deliberately did not touch, reported by name on every run (G280, 🔒 1567). */
$bdNotOurs = array(
    'custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php'
        . ' - KEPT BY rc69: the customer category (🔒 1508 / 🔒 1514 / 🔒 1567).',
    'custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php'
        . ' - KEPT BY rc69: their two labels.',
    'custom/modules/Accounts/BdAccountsLayoutExtensions.php'
        . ' - KEPT BY rc69: places the two kept fields on the Accounts record view.',
    'custom/clients/base/api/BdBenchDogsActionsApi.php'
        . ' - KEPT BY rc69, which ships it EMPTY (🔒 1573): that empty body is what takes the'
        . ' bd-tools/repair-ui route off the tenant.',
    'custom/modules/Quotes/BdQuotesLayoutExtensions.php'
        . ' - not shipped since rc69 and inert: nothing rc69 ships calls it, and K-2 above ran this'
        . ' package\'s own lib/ copy. Left because an rc68-or-earlier Bench Dogs uninstall requires it.',
    'custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php'
        . ' - the same, for K-3.',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php'
        . ' - ALSO SHIPPED BY Partial Fulfillment 1.0.41 at the same path. Retired only by'
        . ' the PF-reinstall sequence, never by an empty stub (G280 / 🔒 1508 / 🔒 1511).',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityLineRollupPolicy.php'
        . ' - hook path; Partial Fulfillment no longer consults it (🔒 1468).',
    'custom/modules/Quotes/ErpQuoteHooks/OrderSelectedLinesPolicy.php'
        . ' - hook path; with the selector blank it answers "no objection", so it cannot block an order.',
    'custom/modules/ProductBundles/clients/base/views/quote-data-group-list/quote-data-group-list.js'
        . ' - ERP-Core ships this exact path. Removing it takes out the core quote grid.',
    'custom/modules/Products/clients/base/views/quote-data-group-list/quote-data-group-list.php'
        . ' - the Products grid viewdef ERP-Core also manages.',
);
foreach ($bdAdapterLeft as $bdItem) {
    $bdNotOurs[] = $bdItem;
}

/** The report: echoed into Module Loader's Display Log, and written as fatal to package_install.log (Admin > Diagnostic Tool). */
// echo DIRECTLY, never through a one-line helper closure. Same MLP017 blocker as
// above: a closure held in a variable and then called is a call through a
// variable, and ModuleScanner rejects the whole upload over one occurrence.

echo '==================================================================' . "\n";
echo 'ONEOFF-RetireBdResidue ' . $bdOneoffVersion . ' - Bench Dogs retirement sweep' . "\n";
echo '==================================================================' . "\n";

if ($bdRemoved) {
    echo 'REMOVED (' . count($bdRemoved) . '):' . "\n";
    foreach ($bdRemoved as $bdItem) {
        echo '  - ' . $bdItem . "\n";
    }
} else {
    echo 'REMOVED (0): NOTHING LEFT TO REMOVE.' . "\n";
    echo '  This tenant is SPENT for every item this package carries.' . "\n";
    echo '  That is the evidence the shipped Bench Dogs package needs in order' . "\n";
    echo '  to drop K-1, K-4 and D-3 outright - the same bar 0.9.42-rc66 used' . "\n";
    echo '  to retire BdAutoSelectedReport.' . "\n";
}

echo 'ALREADY GONE / ALREADY BLANK (' . count($bdAlreadyGone) . ') - nothing to do for these:' . "\n";
foreach ($bdAlreadyGone as $bdItem) {
    echo '  . ' . $bdItem . "\n";
}

if ($bdSkipped) {
    echo 'SKIPPED (' . count($bdSkipped) . ') - READ THESE, the sweep is INCOMPLETE:' . "\n";
    foreach ($bdSkipped as $bdItem) {
        echo '  ? ' . $bdItem . "\n";
    }
}

if ($bdFailed) {
    echo 'FAILED (' . count($bdFailed) . ') - READ THESE, the sweep is INCOMPLETE:' . "\n";
    foreach ($bdFailed as $bdItem) {
        echo '  ! ' . $bdItem . "\n";
    }
}

echo 'NOT TOUCHED ON PURPOSE (' . count($bdNotOurs) . '):' . "\n";
foreach ($bdNotOurs as $bdItem) {
    echo '  = ' . $bdItem . "\n";
}

echo '------------------------------------------------------------------' . "\n";
echo 'This package installed no file, created no table, wrote no record and' . "\n";
echo 'has an EMPTY copy installdef, so uninstall_copy() has nothing to walk' . "\n";
echo 'and nothing to restore. Uninstall it now; the removals above stay.' . "\n";
echo 'RE-RUN IT after any future BenchDogs-Ext UNINSTALL: that uninstall' . "\n";
echo 'restores its own -restore backup of these same paths.' . "\n";
echo '==================================================================' . "\n";

// fatal() so the summary survives any log-level configuration, and so a reviewer
// reading package_install.log months later sees the counts without the detail.
$GLOBALS['log']->fatal(sprintf(
    'ONEOFF-RetireBdResidue %s: removed %d, already gone %d, skipped %d, failed %d, '
        . 'left alone on purpose %d. %s REMOVED: %s. SKIPPED: %s. FAILED: %s.',
    $bdOneoffVersion,
    count($bdRemoved),
    count($bdAlreadyGone),
    count($bdSkipped),
    count($bdFailed),
    count($bdNotOurs),
    $bdRemoved ? '' : 'NOTHING LEFT TO REMOVE - this tenant is spent.',
    $bdRemoved ? implode(' | ', $bdRemoved) : '(none)',
    $bdSkipped ? implode(' | ', $bdSkipped) : '(none)',
    $bdFailed ? implode(' | ', $bdFailed) : '(none)'
));
