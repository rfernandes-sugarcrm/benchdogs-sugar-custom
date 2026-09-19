<?php

/**
 * Undo the things the uninstaller cannot see.
 *
 * WHY THIS FILE HAS TO EXIST
 *
 * The manifest has always declared this package uninstallable, and Sugar's own
 * uninstall does a competent job of the parts it knows about: it deletes every
 * file listed in installdefs['copy'], drops the beans, removes the
 * relationships, and prompts about the module tables. What it knows nothing
 * about is DEPLOYED METADATA - the record-view definitions post_install.php
 * writes through DeployedMetaDataImplementation. Those writes land in
 * custom/modules/<M>/clients/base/views/record/record.php, a file this package
 * does not ship and the uninstaller therefore never touches.
 *
 * So before this script existed, removing the package left three kinds of
 * wreckage behind, and an admin had to know they were there to fix them:
 *
 *   1. The "Bench Dogs ERP" panel stayed on the Quotes record view, pointing at
 *      five bd_* fields whose vardefs had just been deleted.
 *   2. The Bench Dogs buttons stayed on the Quotes and Accounts record views,
 *      each pointing at a custom field type whose JavaScript had just been
 *      deleted, and the two customer group fields stayed on Accounts.
 *   3. Worst, because it damaged a DIFFERENT package: ERP-Epicor's
 *      advanced_quote_button, create_erp_order_button and
 *      refresh_price_availability_button are deleted from the deployed Quotes
 *      view at install time, and nothing ever put them back. Uninstalling Bench
 *      Dogs left ERP-Epicor three buttons short, with reinstalling ERP-Epicor
 *      the only way to recover them.
 *
 * PRE, NOT POST
 *
 * Every repair below needs a class that lives under custom/ - which is exactly
 * what the uninstall is about to delete. pre_uninstall runs while those files
 * are still on disk; post_uninstall would run after they are gone. The cache
 * and metadata rebuild is the opposite case and lives in post_uninstall.php
 * beside this file, because it has to see the instance WITHOUT our extensions.
 *
 * TOP-LEVEL CODE, NOT A FUNCTION
 *
 * This file's logic runs at the top level, with no wrapping function, because
 * that is the one shape that works whatever Module Loader does with the file.
 * ERP-Epicor's own pre_execute.php, post_execute.php and pre_uninstall.php in
 * the sibling repository are all plain top-level code and all work, which
 * proves the loader does not REQUIRE a function named after the installdef key.
 * If it merely requires the file, top-level code runs and a function would
 * never have been called - a SILENT no-op, leaving exactly the mess this script
 * exists to prevent, and indistinguishable from a clean uninstall. If it does
 * also call such a function when one exists, defining none simply means nothing
 * extra happens. Top-level is correct under both; a function is correct under
 * only one. An earlier draft of this file used a function and would have been a
 * coin flip.
 *
 * EVERY STEP IS INDEPENDENT
 *
 * Each block has its own try/catch and its own file_exists/class_exists guard.
 * A missing helper or a failed repair logs and moves on; it never aborts the
 * uninstall. A half-removed package is worse than a fully removed one with one
 * layout still to tidy by hand, and an instance that has ERP-Epicor uninstalled
 * first legitimately has no BaseErpLayout for BdQliColumnsLayout to extend.
 *
 * Removing what is not there is a no-op throughout, so this is also safe on an
 * instance that never received some of what it undoes.
 */

// Proof of life. Module Loader reporting a successful uninstall is not evidence
// that this file ran - if the installdef key were wrong the uninstall would
// complete looking perfectly clean while doing none of the cleanup. After an
// uninstall, grep sugarcrm.log for this line: present means the cleanup ran,
// absent means treat the wiring as broken and follow the manual runbook
// (docs/runbooks/remove-benchdogs-sugar-package.md in the connector extension
// repo). Logged at fatal so it survives whatever log level the instance is set
// to; it is emitted exactly once in the life of an installation.
$GLOBALS['log']->fatal('BenchDogs-Ext: pre_uninstall running - cleaning up deployed metadata');

// 1. Quotes record view: drop our panel and buttons, and hand ERP-Epicor
// back the three buttons writeButtons() took. One deploy cycle, inside
// BdQuotesLayoutExtensions::remove().
try {
    $bdQuotesHelper = 'custom/modules/Quotes/BdQuotesLayoutExtensions.php';
    if (file_exists($bdQuotesHelper)) {
        if (!class_exists('BdQuotesLayoutExtensions', false)) {
            require_once $bdQuotesHelper;
        }
        if (class_exists('BdQuotesLayoutExtensions')) {
            BdQuotesLayoutExtensions::remove();
            // Only after the restore has actually run, so a failure above
            // leaves the stash intact for a manual re-run.
            BdQuotesLayoutExtensions::clearStash();
        }
    } else {
        $GLOBALS['log']->error("BenchDogs-Ext: {$bdQuotesHelper} missing; Quotes record view not cleaned up");
    }
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: Quotes layout cleanup failed: ' . $e->getMessage());
}

// 2. Accounts record view: our button and the two REQ-19 fields.
try {
    $bdAccountsHelper = 'custom/modules/Accounts/BdAccountsLayoutExtensions.php';
    if (file_exists($bdAccountsHelper)) {
        if (!class_exists('BdAccountsLayoutExtensions', false)) {
            require_once $bdAccountsHelper;
        }
        if (class_exists('BdAccountsLayoutExtensions')) {
            BdAccountsLayoutExtensions::remove();
        }
    } else {
        $GLOBALS['log']->error("BenchDogs-Ext: {$bdAccountsHelper} missing; Accounts record view not cleaned up");
    }
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: Accounts layout cleanup failed: ' . $e->getMessage());
}

// 2b. Opportunities record view: decision 72's value-source marker, and the
// saved report that filters on it. Both have to go with the vardef.
//
// The report matters MORE than the placement here. A saved report whose filter
// column no longer has a vardef does not error - it silently returns nothing,
// which reads as "no unreviewed deals" rather than as a broken report. That is
// exactly the trap release-0.9.42-rc24's operator notes describe for
// bd_shipped_value, and it is why removing it is not optional tidying.
try {
    $bdOppsHelper = 'custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php';
    if (file_exists($bdOppsHelper)) {
        if (!class_exists('BdOpportunitiesLayoutExtensions', false)) {
            require_once $bdOppsHelper;
        }
        if (class_exists('BdOpportunitiesLayoutExtensions')) {
            BdOpportunitiesLayoutExtensions::remove();
        }
    } else {
        $GLOBALS['log']->error("BenchDogs-Ext: {$bdOppsHelper} missing; Opportunities record view not cleaned up");
    }
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: Opportunities layout cleanup failed: ' . $e->getMessage());
}
try {
    $bdReportHelper = 'custom/modules/Opportunities/BdAutoSelectedReport.php';
    if (file_exists($bdReportHelper)) {
        if (!class_exists('BdAutoSelectedReport', false)) {
            require_once $bdReportHelper;
        }
        if (class_exists('BdAutoSelectedReport')) {
            (new BdAutoSelectedReport())->uninstall();
        }
    } else {
        $GLOBALS['log']->error("BenchDogs-Ext: {$bdReportHelper} missing; review report left behind");
    }
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: review report cleanup failed: ' . $e->getMessage());
}

// 3. The quoted-line-items grid: take the injected line-number column back
// out of the Quotes product_bundle_items allowlist. That column is now CORE'S
// `erp_quote_line_num` rather than Bench's retired `bd_erp_line_num` (🔒 1032)
// — this still removes it on uninstall, because Bench is what injected it;
// ERP-Core's own ProductsLayout does not draw it. This one had an uninstall() method
// all along and nothing ever called it. The class only defines itself when
// ERP-Epicor is still installed (it extends BaseErpLayout), which is what
// the class_exists guard after the require is for.
try {
    $bdQliHelper = 'custom/modules/Quotes/BdQliColumnsLayout.php';
    if (file_exists($bdQliHelper)) {
        if (!class_exists('BdQliColumnsLayout', false)) {
            require_once $bdQliHelper;
        }
        if (class_exists('BdQliColumnsLayout')) {
            (new BdQliColumnsLayout())->uninstall();
        } else {
            $GLOBALS['log']->info('BenchDogs-Ext: BdQliColumnsLayout not defined (ERP-Core absent); nothing to undo on the line items grid');
        }
    }
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: QLI columns cleanup failed: ' . $e->getMessage());
}

// 4. Dashboard tiles that list a module this uninstall deletes.
//
// GUARD THE CLASS, NOT THE PATH. BdDemoDashboards.php exists at two paths on an
// installed instance - beside this script in the extracted package, and at
// custom/include/bd_scripts/ where the copy installdef put it - and
// post_install.php reaches for the first. require_once dedupes by RESOLVED
// PATH, not by class name, so on an uninstall_before_upgrade run, which
// executes this file and post_install.php in ONE request, both copies would
// load and PHP would fatal with "Cannot redeclare class BdDemoDashboards". That
// kills the run outright, and Module Loader's own failure handling makes it
// look like anything but what it is. The sibling repository lost a demo
// instance to exactly this defect on 2026-09-11; scripts/mlp_lint.py MLP001
// exists because of it and flagged this file before it ever shipped.
try {
    if (!class_exists('BdDemoDashboards', false)) {
        $bdDashboards = __DIR__ . '/BdDemoDashboards.php';
        if (!file_exists($bdDashboards)) {
            $bdDashboards = 'custom/include/bd_scripts/BdDemoDashboards.php';
        }
        if (file_exists($bdDashboards)) {
            require_once $bdDashboards;
        }
    }
    if (class_exists('BdDemoDashboards')) {
        (new BdDemoDashboards())->uninstall();
    } else {
        $GLOBALS['log']->error('BenchDogs-Ext: BdDemoDashboards not available; dashboard tiles not cleaned up');
    }
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: dashboard cleanup failed: ' . $e->getMessage());
}

// WHAT THIS DELIBERATELY DOES NOT DO
//
// The *_cstm columns behind the bd_* fields are left in the database. Removing
// a vardef does not drop its column, and dropping them here would make the
// uninstall destructive in a way `remove_tables => prompt` never asked about:
// an admin who uninstalls to try a rebuild would lose the synced values with no
// way back. With the vardefs gone Sugar neither reads nor displays them, so
// they cost nothing but disk.
//
// The stage dropdown keys post_install.php appends (quote_stage_dom's
// 'Partially Fulfilled', sales_stage_dom's 'Prototype Ordered' and 'Partial
// Production Closed') are also left alone, for a stronger reason: quotes and
// opportunities on this instance HOLD those values. Removing the key would
// leave those records displaying a raw string with no label, which is worse
// than an unused dropdown entry.
