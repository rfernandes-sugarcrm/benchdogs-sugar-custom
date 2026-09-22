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
 * So before this script existed, removing the package left deployed-metadata
 * wreckage behind, and an admin had to know it was there to fix it:
 *
 *   1. The "Bench Dogs ERP" panel stayed on the Quotes record view, pointing at
 *      five bd_* fields whose vardefs had just been deleted.
 *   2. The two customer group fields stayed on Accounts.
 *
 * 🛑 G276 / 🔒 1504 - NO BUTTON LOGIC HERE EITHER. Until rc64 step 1 below also
 * dropped this package's retired bd_* buttons and put back the ERP-Epicor
 * buttons decision 91 had stripped and stashed, then blanked the stash. The
 * package no longer strips anything, so there is nothing of ours to hand back,
 * and the owner's ruling removes every add/remove/stash of a record-view
 * button from this package. The buttons array is left exactly as deployed.
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
 * first legitimately has no BaseErpLayout for a layout helper to extend.
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

// 1. Quotes record view: drop our retired panel. Buttons are not touched
// (G276 / 🔒 1504); see the note at the top of this file.
try {
    $bdQuotesHelper = 'custom/modules/Quotes/BdQuotesLayoutExtensions.php';
    if (file_exists($bdQuotesHelper)) {
        if (!class_exists('BdQuotesLayoutExtensions', false)) {
            require_once $bdQuotesHelper;
        }
        if (class_exists('BdQuotesLayoutExtensions')) {
            BdQuotesLayoutExtensions::remove();
        }
    } else {
        $GLOBALS['log']->error("BenchDogs-Ext: {$bdQuotesHelper} missing; Quotes record view not cleaned up");
    }
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: Quotes layout cleanup failed: ' . $e->getMessage());
}

// 2. Accounts record view: the two REQ-19 fields.
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
// 🛑 THE SAVED-REPORT REMOVAL IS GONE FROM THE UNINSTALL TOO (0.9.42-rc66,
// G280 / 🔒 1508), and for the same reason it left post_install: the row is
// already gone. rc65's install ran remove() and logged neither a deletion nor
// a missing helper (tenant package_install.log, PID 2069085, 17:16:59-17:17:35),
// so there was nothing to find - and SugarQuery cannot re-find a row it has
// already soft-deleted. See post_install.php for the full reading.

// 3. The quoted-line-items grid: NOTHING TO DO, and deliberately no call here.
// BdQliColumnsLayout is deleted (0.9.42-rc66). Its uninstall() took CORE's
// `erp_quote_line_num` back out of the Quotes product_bundle_items allowlist,
// which this package had injected on core's behalf while core's own
// ProductsLayout did not draw it. Nothing draws it in any package, so the entry
// renders nothing and removing it was never a seller-visible act; what it WOULD
// have been is this package continuing to edit a core field's fetch list on the
// way out. It stays where it is: core's field, in core's collection.

// 4. Dashboard tiles: NOTHING TO DO, and deliberately no call here.
//
// 0.9.42-rc65 deleted BdDemoDashboards.php and the install-side call with it
// (G280 / 🔒 1507), so there is no longer even a class to ask. What an earlier
// install baked into a tenant's dashboards stays; see below.
//
// Until the quote mirror was retired (decisions 901/903/904/905) this step ran
// BdDemoDashboards::uninstall() to strip the tiles that listed a module the
// uninstall was about to delete. This package now deletes no module of its
// own, so every tile it pins lists a stock module that outlives the uninstall -
// the case BdDemoDashboards' own class comment says needs no cleanup. That
// method is gone rather than emptied, so nothing here pretends to clean.
//
// An instance that ran 0.9.42-rc44 or earlier still has three Home tiles
// ("ERP Quotes", "ERP Quote Lines", "ERP Quote Costs") pointing at the retired
// modules. This package can no longer remove them; they have to be deleted
// from the dashboard by hand.

// 5. Stage dropdown keys: NOT OURS TO REMOVE ANY MORE, and that is the fix.
//
// G234 built this step for a real defect: post_install had appended the stage
// vocabulary through ModuleInstaller::install_languages() under id_name
// 'zz_bd_stage_doms', neither uninstall route could reach that file, and a
// clean 16/16 uninstall left stock serving "Prototype Ordered" and "Partial
// Production Ordered" on a tenant where ZERO opportunities held either.
//
// 🔒 1506 removed the CAUSE instead of teaching this file to clean up after it:
// Partial Fulfillment now owns both sales stages (and has always owned
// quote_stage_dom's 'Partially Fulfilled'), 0.9.42-rc65 declares none of them,
// and post_install.php deletes the accumulated zz_bd_stage_doms fragment once,
// on install, through uninstall_languages(). Removing the KEYS here would now
// delete ANOTHER package's vocabulary from under records that hold it - the
// same damage G234 existed to prevent, pointed the other way. The owner's
// instruction is explicit: a Bench Dogs uninstall must stop removing them.
//
// Nothing replaces this step. Uninstalling this package leaves PF's stages,
// their probabilities and their styles exactly where they are.

// The *_cstm columns behind the bd_* fields are left in the database. Removing
// a vardef does not drop its column, and dropping them here would make the
// uninstall destructive in a way `remove_tables => prompt` never asked about:
// an admin who uninstalls to try a rebuild would lose the synced values with no
// way back. With the vardefs gone Sugar neither reads nor displays them, so
// they cost nothing but disk.
//
// The stage dropdown keys are left alone because they are not this package's
// any more (step 5). No uninstall of Bench Dogs edits a record to free a key
// either: restaging an opportunity to make a package's own cleanup possible
// would be destructive in a way `remove_tables => prompt` never asked about.
