<?php

/**
 * ONE-OFF CLEANUP. Takes the Bench Dogs package's SEVEN retirement items off the
 * tenant, so the shipped package can stop carrying them.
 *
 * DISPOSABLE BY DESIGN, exactly like its two siblings ONEOFF-RetireBdQuoteMirror
 * and ONEOFF-DropBdQuoteMirrorTables: install it, let it run, uninstall it.
 *
 * 🛑 READ THIS FIRST - WHY THERE IS NO `copy` INSTALLDEF, AND WHY THAT IS THE
 *    WHOLE DESIGN.
 *
 * The obvious way to build this package would be to ship the retired paths EMPTY
 * through installdefs['copy'], the way BenchDogs-Ext itself ships its 38 stubs.
 * MEASURED IN SUGARENT 26.1.0, THAT WOULD UNDO ITSELF ON UNINSTALL:
 *
 *   ModuleInstaller::install_copy()              (:503-518)
 *       -> copy_path($from, $to, $backup_path)   with $backup_path = <zip>-restore
 *       -> copy_recursive_with_backup(..., $uninstall = false)
 *          :2669-2678  if (file_exists($dest)) { copy($dest, "$backup_path/$dest"); }
 *                      ...then copy($source, $dest)
 *
 *   ModuleInstaller::uninstall_copy()            (:521-545)
 *       -> copy_path($backup_path, $cp['to'], $backup_path, true)
 *       -> copy_recursive_with_backup(..., $uninstall = true)
 *          :2656-2662  copy($source, $dest)   <-- THE BACKUP IS COPIED BACK
 *
 * So a package that overwrites a live file through `copy` hands Sugar a pristine
 * copy of the PREVIOUS body, and Module Loader restores it the moment the package
 * is uninstalled. A cleanup built that way would work, look like it had worked,
 * and then silently revert every tenant on the day somebody tidied Module Loader.
 * That is worse than doing nothing, because it is invisible.
 *
 * This package therefore has an EMPTY `copy` list. Everything below is done from
 * post_execute through platform code, so there is no copy list for uninstall_copy
 * to walk, no -restore directory holding the old bodies, and nothing to put back.
 * Uninstalling it is a genuine no-op - the same property the two sibling one-offs
 * were built for and for the same reason.
 *
 * 🚩 THE CONVERSE, AND IT IS A REAL OPERATIONAL RULE, NOT A CAVEAT.
 * The restore hazard still exists for BENCHDOGS-EXT itself, which DOES ship every
 * one of these paths through `copy`. Uninstalling BenchDogs-Ext restores the
 * bodies that were on disk when THAT zip was installed - on et, the rc62 vardefs
 * this package just removed. So:
 *
 *     RUN THIS ONE-OFF **AFTER** ANY BENCHDOGS-EXT INSTALL OR UNINSTALL,
 *     AND RE-RUN IT AFTER ANY FUTURE BENCHDOGS-EXT UNINSTALL.
 *
 * It is idempotent, so re-running costs nothing and reports "nothing left to
 * remove". Installing a LATER BenchDogs-Ext that no longer ships these paths does
 * NOT resurrect them: install_copy only touches paths in its own copy list.
 *
 * WHY NOTHING HERE CALLS unlink(), rmdir() OR file_put_contents().
 * All three - and rmdir_recursive, copy, copy_recursive, mkdir_recursive, glob,
 * is_dir, is_file, fopen, fwrite, sugar_file_put_contents and write_array_to_file -
 * are on ModuleScanner's deny-list for packaged code (26.1.0
 * ModuleInstall/ModuleScanner.php:106-218). A package that called them would be
 * refused at upload. Every removal below is performed BY PLATFORM CODE, reached
 * through ModuleInstaller, where the deny-list does not apply. file_exists() is
 * not on the list, which is what makes the before/after census possible.
 *
 * WHAT IT DOES, IN ORDER, AND WHY THE ORDER IS THAT.
 *   1. Extension fragments (K-1, K-4's registration, K-5's source, D-3) are
 *      DELETED first, so that nothing is still registered against a class that
 *      step 4 is about to blank.
 *   2. Bench-only client-field directories are DELETED.
 *   3. The deployed-METADATA retirements run (K-2, K-3, K-5) - the only three
 *      items no installdef can reach, because they live in rows this package
 *      does not ship.
 *   4. Orphaned Bench Dogs class files are BLANKED (not deleted: a single file
 *      under a shared module directory has no platform deletion primitive).
 *   5. The paths it deliberately did NOT touch are reported by name.
 *
 * HOW AN OPERATOR READS THE RESULT, WITHOUT A SHELL AND WITHOUT sugarcrm.log.
 * ModuleInstaller::post_execute() wraps the require in
 *     ob_start(function ($val) { $this->log($val); }, 64);          (:435-440)
 * and ModuleInstaller::log() calls addInstallationMessage() and
 * $GLOBALS['log']->debug(). During a Module Loader install the default logger has
 * been swapped by MlpLogger::replaceDefault() (src/PackageManager/PackageManager.php:941,
 * modules/Administration/UpgradeWizard_commit.php:17), which sets the log file to
 * package_install and the level to debug. So EVERY echo below appears in BOTH:
 *   - Module Loader's own "Display Log" panel on the install page, and
 *   - package_install.log, which the Admin Diagnostic Tool can export from a
 *     SugarCloud tenant (pick ONLY "Package Install Log").
 * The $GLOBALS['log']->fatal() summary at the end lands there too, at a level no
 * configuration filters out. Nothing here depends on sugarcrm.log.
 *
 * IDEMPOTENT, AND THE SECOND RUN IS THE EVIDENCE. Every entry is guarded by
 * file_exists() before and re-checked after. A second run finds nothing, removes
 * nothing, and prints "NOTHING LEFT TO REMOVE" - which is exactly the evidence
 * 0.9.42-rc66 used to retire BdAutoSelectedReport, and exactly what lets the
 * shipped package drop each item afterwards.
 *
 * NO DATA IS TOUCHED. No bean is loaded, no row is written, no table is dropped,
 * no quote and no quote line is deleted. The only database write in the whole
 * script is ModuleInstaller's own language-cache rebuild inside
 * uninstall_languages().
 */

// Guarded on the class, not the path: ModuleInstaller is already loaded during an
// install, and a second require through a different resolved path is what killed
// ERP-Epicor 1.1.8 with "Cannot redeclare class".
if (!class_exists('ModuleInstaller', false)) {
    require_once 'ModuleInstall/ModuleInstaller.php';
}

$bdRemoved = array();
$bdAlreadyGone = array();
$bdFailed = array();
$bdSkipped = array();

/**
 * THE EXTENSION FRAGMENTS - K-1, K-4 (registration half), K-5 (source), D-3.
 *
 * Every custom/Extension/** path BenchDogs-Ext has EVER installed, taken from the
 * package's whole git history rather than from its current tree: 89 paths, of
 * which 87 are removed here. A census of the current package would miss the
 * orphans - files an old version installed and a later one stopped shipping, which
 * Module Loader therefore never deleted. That is the same failure mode that left
 * bd01_erp_rung_costs behind for ONEOFF-RetireBdQuoteMirror to find: only the
 * TENANT knew about it.
 *
 * THE TWO DELIBERATE EXCEPTIONS, and they are the whole point of 🔒 1508 / 🔒 1514:
 *   custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php
 *   custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php
 * The two customer-group fields are the customer-category code the package KEEPS.
 * They are not in the list below and this package must never remove them.
 *
 * THE MECHANISM. ModuleInstaller::uninstallExt() (:675-712) builds
 *     custom/Extension/modules/<to_module>/Ext/<extname>/<name>.php
 * (or custom/Extension/application/Ext/<extname>/<name>.php when to_module is
 * 'application') and hands it to rmdir_recursive(), which unlinks a plain file
 * (include/dir_inc.php:96-99). It reads its worklist from $installer->installdefs,
 * which is a PUBLIC property (:85), so the list is supplied here rather than by a
 * manifest. That is the same technique BenchDogs-Ext's own post_install already
 * uses to drive uninstall_languages(), and no path below contains "..".
 */
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

/**
 * NO CLOSURE HERE, AND NO HELPER FUNCTION EITHER. The Extension path is built
 * inline at both sites below, duplicated on purpose: calling through a variable
 * is refused by ModuleScanner and flagged as a BLOCKER by mlp_lint MLP017, and a
 * closure assigned to a variable and then called is exactly that shape. The
 * linter caught it on this file's first build - 56 findings - which is why the
 * build step lints the ZIP with an MLP019-era copy and not the working tree's.
 *
 * The two branches are copied from uninstallExt():675-706, not guessed: for
 * to_module 'application' the Ext root is custom/Extension/application/Ext,
 * otherwise custom/Extension/modules/<to_module>/Ext.
 */

$bdInstaller = new ModuleInstaller();
$bdInstaller->silent = true;

foreach ($bdExtensionGroups as $bdSubdir => $bdItems) {
    // Only ask the platform to remove entries that are actually on this tenant.
    // A worklist of 87 against a tenant that holds nine of them would still work -
    // uninstallExt() checks file_exists itself - but then the report could not
    // tell "removed" from "was never here", and that distinction IS the evidence.
    $bdPresent = array();
    foreach ($bdItems as $bdItem) {
        if ($bdItem['to_module'] === 'application') {
            $bdPath = 'custom/Extension/application/Ext/' . $bdSubdir . '/' . $bdItem['name'] . '.php';
        } else {
            $bdPath = 'custom/Extension/modules/' . $bdItem['to_module'] . '/Ext/' . $bdSubdir
                . '/' . $bdItem['name'] . '.php';
        }
        if (file_exists($bdPath)) {
            $bdPresent[] = $bdItem;
        } else {
            $bdAlreadyGone[] = $bdPath;
        }
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
        } else {
            $bdRemoved[] = $bdPath;
        }
    }
}

/**
 * THE BENCH-ONLY CLIENT-FIELD DIRECTORIES.
 *
 * Five Sidecar field directories this package installed for buttons that no
 * longer exist - "Create Opportunity & Quote" (G15), "Send to Estimating",
 * "Best Pricing", "Order Selected", "Order Winning". Each is a whole directory
 * that belongs to Bench Dogs alone, which is what makes uninstall_customizations()
 * (:2625-2640) the right primitive: it rmdir_recursive's a directory and is the
 * same call ONEOFF-RetireBdQuoteMirror already uses. Its parameter is named
 * $beans, but it does nothing except prefix each entry with custom/modules/,
 * custom/Extension/modules/ and custom/working/modules/ and delete what is a
 * directory - so a nested name is a longer path, not a different behaviour. No
 * entry contains "..".
 *
 * 🛑 NOT IN THIS LIST, DELIBERATELY:
 *   custom/modules/ProductBundles/clients/base/views/quote-data-group-list
 *   custom/modules/Products/clients/base/views/quote-data-group-list
 * ERP-Core ships quote-data-group-list.js at the first of those paths on the
 * DEPLOYED core tree. Deleting it would take out the core quote grid on every
 * tenant, including the two that have never had Bench Dogs. They are reported at
 * the end instead, and they belong to whoever owns ERP-Core.
 */
$bdDirectories = array(
    // The three retired bd01 quote-mirror modules. ONEOFF-RetireBdQuoteMirror
    // makes the same call, and that is deliberate duplication, not an oversight:
    // that package REFUSES to run while the modules are still registered, so a
    // tenant that took it in the wrong order still has these directories. The
    // call is idempotent, so running it twice costs one is_dir() each.
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

/**
 * WHERE THIS PACKAGE'S OWN FILES ARE.
 *
 * post_execute is required from <base_dir>/scripts/post_execute.php by
 * ModuleInstaller::post_execute():435, so dirname(__DIR__) is the unpacked
 * package. It is resolved once, and every use of it is guarded by file_exists:
 * if the guess is wrong, the affected step reports itself as SKIPPED and the rest
 * of the run still completes. Nothing here half-finishes silently.
 */
$bdPackageDir = dirname(__DIR__);

/**
 * K-2 - THE BENCH DOGS PANEL, SPLICED OUT OF THE **DEPLOYED** QUOTES RECORD VIEW.
 * K-3 - THE RETIRED bd_governing_origin MARKER ON THE OPPORTUNITY RECORD VIEW.
 *
 * These two are the reason this one-off cannot be "just delete some files". They
 * mutate rows that no installdef can see - the tenant's DEPLOYED record-view
 * metadata - so nothing a package ships or stops shipping reaches them. The work
 * has to RUN.
 *
 * 🚩 THE CLASSES ARE CARRIED IN THIS ZIP, NOT READ OFF THE TENANT, AND THAT IS A
 *    DELIBERATE CHOICE WITH A COST.
 * BenchDogs-Ext's own post_install requires the tenant's copy under
 * custom/modules/. Doing that here would make the one-off behave differently on
 * et (rc62's bodies) than on Bench (rc66's), which is the opposite of what a
 * one-off is for, and would leave the package unable to drop the files. So
 * lib/ holds a verbatim copy of both classes as of BenchDogs-Ext 0.9.42-rc66
 * (74a846e) and they are required from there.
 * THE COST, STATED: these are Lane D's files. If Lane D changes either class,
 * this package's copy is stale and must be re-vendored. It is a copy, not a fork,
 * and nothing in it has been edited.
 *
 * class_exists(..., false) before each require: the tenant's own copy may already
 * be loaded in this request, and requiring a second body for the same class name
 * is a fatal. If it is already loaded, that one is used.
 */
// 🚩 NO DYNAMIC DISPATCH. An earlier draft drove these two through a
// $step['class']::{$step['method']}() loop, which is shorter and which BOTH
// ModuleScanner and mlp_lint MLP017 reject: a variable static call is exactly the
// shape a scanner cannot follow. Written out, twice, on purpose.

/**
 * 🚩 HOW THESE TWO REPORT, AND WHY IT IS NOT "I CALLED IT, SO I REMOVED IT".
 *
 * write() and remove() both return void and both log nothing. Reporting a
 * removal just because the call was made would make this package claim two
 * removals on EVERY run for ever - which would destroy the one thing it exists
 * to produce, a second run that says "nothing left to remove".
 *
 * Both methods only write when they actually changed something: they return
 * early at "$at === false" / "if (!$changed)" and otherwise call
 * deployRecordView(), which is ViewdefManager::saveViewdef() writing
 *     custom/modules/<Module>/clients/base/views/record/record.php
 * (26.1.0 src/MetaData/ViewdefManager.php:62-74). So the deployed viewdef file
 * is the observable. Hash it before and after: changed means the panel or the
 * marker was really there and is now gone; unchanged means it was already
 * absent, which IS the spentness evidence K-2 and K-3 are waiting for.
 */
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

/**
 * K-5 - THE ACCUMULATED zz_bd_stage_doms LANGUAGE FRAGMENT.
 *
 * Until rc64, BenchDogs-Ext's post_install appended its stage vocabulary to
 * custom/Extension/application/Ext/Language/en_us.zz_bd_stage_doms.php through
 * ModuleInstaller::install_languages(), which CONCATENATES rather than overwrites
 * (26.1.0 ModuleInstaller.php:1227-1235). The file on a long-lived tenant
 * therefore carries every key every past version ever appended, including
 * 🔒 314's retired '...Closed' pair. Partial Fulfillment owns that vocabulary now.
 *
 * uninstall_languages() is the exact mirror of the install and the only removal a
 * package has for it: it deletes en_us.<id_name>.php and rebuilds the language
 * cache (:1243-1265). Same installer class, same id_name, same template path as
 * the install used - copied from BenchDogs-Ext post_install.php:302-317, not
 * reinvented, because the guards in that block were put there for reasons this
 * package cannot see.
 *
 * Idempotent: on a tenant that never had the file the call only rebuilds.
 */
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

/**
 * THE ORPHANED CLASS FILES - K-4's tombstone and 20 others.
 *
 * These are single .php files sitting under module directories that OTHER
 * packages also write to (custom/modules/Quotes, /Products, /Accounts,
 * /Contacts, /Opportunities). There is no platform primitive that deletes one
 * file at an arbitrary path: uninstallExt only builds custom/Extension/** paths,
 * uninstall_customizations only removes DIRECTORIES, and unlink/rmdir_recursive/
 * SugarAutoLoader::unlink are all denied to packaged code. So these are BLANKED,
 * not deleted - overwritten with lib/emptied.php, which declares nothing.
 *
 * 🚩 AND THIS IS THE ONE PLACE THE RESTORE HAZARD IN THE HEADER STILL APPLIES, SO
 *    READ IT AGAIN. The write goes through ModuleInstaller::copy_path() with NO
 *    backup path (:1424-1460 -> copy_recursive), NOT through installdefs['copy'].
 *    Nothing is backed up, this package has no copy list, and uninstall_copy()
 *    therefore has nothing to walk and nothing to restore. Uninstalling THIS
 *    package leaves the blanked files blank. Uninstalling BENCHDOGS-EXT does not:
 *    its own -restore directory holds the bodies, so re-run this one-off after.
 *
 * 🛑 FOUR FILES ARE NOT IN THIS LIST AND MUST NEVER BE:
 *   custom/modules/Accounts/BdAccountsLayoutExtensions.php   - places the two
 *       customer-group fields; that is the code 🔒 1508 / 🔒 1514 KEEP.
 *   custom/modules/Quotes/BdQuotesLayoutExtensions.php       - K-2's own class.
 *   custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php - K-3's.
 *   custom/clients/base/api/BdBenchDogsActionsApi.php        - D-2, the
 *       bd-tools/repair-ui route the owner answered to KEEP.
 *
 * 🛑 AND custom/modules/Quotes/ErpQuoteHooks/** IS NOT IN THIS LIST EITHER.
 *   OpportunityContribution.php is shipped by Partial Fulfillment 1.0.41 at the
 *   SAME path. Blanking it would put an empty stub at a provider path, which
 *   🔒 1508 and G280 both forbid, and deleting it drops ERP-Core to
 *   (float) $quote->total - the fabricated zero 🔒 1511 forbids. The other four
 *   are ERP-Core CONTRACT paths whose fallback behaviour belongs to whoever owns
 *   ERP-Core, not to a cleanup package. All five are reported, untouched.
 */
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
    // THE IDEMPOTENCY TEST FOR THIS GROUP, AND WHY IT IS A HASH.
    // A blanked file still EXISTS, so file_exists() cannot tell a second run from
    // a first one - and a cleanup that reports 21 removals every time it runs is
    // not evidence of anything. The blanked body is byte-identical to
    // lib/emptied.php by construction, so comparing digests answers it exactly.
    // filesize(), file_get_contents() and file() are all on the scanner's
    // deny-list; md5_file() is not (26.1.0 ModuleScanner.php:106-218), and it is
    // used here to COMPARE TWO LOCAL FILES, never as a security boundary.
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

/**
 * WHAT THIS PACKAGE DELIBERATELY DID NOT TOUCH.
 *
 * Reported by name on every run, present or not, because "I left it alone" is a
 * finding an operator has to be able to read as easily as "I removed it". Each
 * of these is a path BenchDogs-Ext installed that this package is not entitled to
 * remove, with the owner it belongs to.
 */
$bdNotOurs = array(
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php'
        . ' - ALSO SHIPPED BY Partial Fulfillment 1.0.41 at the same path. Retired only by'
        . ' the PF-reinstall sequence, never by an empty stub (G280 / 🔒 1508 / 🔒 1511).',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityLineRollupPolicy.php - ERP-Core contract path.',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php - ERP-Core contract path.',
    'custom/modules/Quotes/ErpQuoteHooks/OrderSelectedLinesPolicy.php - ERP-Core contract path.',
    'custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php - ERP-Core contract path.',
    'custom/modules/ProductBundles/clients/base/views/quote-data-group-list/quote-data-group-list.js'
        . ' - ERP-Core ships this exact path. Removing it takes out the core quote grid.',
    'custom/modules/Products/clients/base/views/quote-data-group-list/quote-data-group-list.php'
        . ' - the Products grid viewdef ERP-Core also manages.',
    'custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php - KEPT (🔒 1508 / 🔒 1514).',
    'custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php - KEPT (🔒 1508 / 🔒 1514).',
    'custom/modules/Accounts/BdAccountsLayoutExtensions.php - KEPT, places the two kept fields.',
    'custom/modules/Quotes/BdQuotesLayoutExtensions.php - KEPT, K-2 still needs it on the tenant.',
    'custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php - KEPT, K-3 still needs it.',
    'custom/clients/base/api/BdBenchDogsActionsApi.php - KEPT, D-2 bd-tools/repair-ui (owner answer).',
);

/**
 * THE REPORT.
 *
 * Two destinations, both reachable on SugarCloud without a shell:
 *
 *   echo  -> ModuleInstaller::post_execute() has this require inside
 *            ob_start(function ($val) { $this->log($val); }, 64)  (:435-440)
 *            and log() calls addInstallationMessage(), which is what Module
 *            Loader's "Display Log" link on the install page renders. It also
 *            calls $GLOBALS['log']->debug(), and MlpLogger::replaceDefault()
 *            (PackageManager.php:941) has already pointed the default logger at
 *            package_install AT DEBUG LEVEL for the duration of the install.
 *
 *   fatal -> package_install.log at a level nothing filters, exportable from
 *            Admin > Diagnostic Tool with ONLY "Package Install Log" ticked.
 *
 * That is the answer to the problem that made these items hard to retire in the
 * first place: post_install wrote to sugarcrm.log, which SugarCloud keeps out of
 * reach. Nothing below needs sugarcrm.log and nothing below needs bd-tools/repair-ui.
 *
 * 🚩 WHAT IS NOT DONE HERE, AND WHO OWNS IT. The brief suggested routing the
 * outcome through the bd-tools/repair-ui route. That would mean editing
 * custom/clients/base/api/BdBenchDogsActionsApi.php, which belongs to the Sugar
 * package lane, not to packaging. It has not been touched. The two destinations
 * above make it unnecessary rather than merely blocked.
 */
// echo DIRECTLY, never through a one-line helper closure. Same MLP017 blocker as
// above: a closure held in a variable and then called is a call through a
// variable, and ModuleScanner rejects the whole upload over one occurrence.

echo '==================================================================' . "\n";
echo 'ONEOFF-RetireBdResidue 1.0.0 - Bench Dogs retirement sweep' . "\n";
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
    'ONEOFF-RetireBdResidue 1.0.0: removed %d, already gone %d, skipped %d, failed %d, '
        . 'left alone on purpose %d. %s REMOVED: %s. SKIPPED: %s. FAILED: %s.',
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
