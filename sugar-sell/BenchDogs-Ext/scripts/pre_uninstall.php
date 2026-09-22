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
try {
    $bdReportHelper = 'custom/modules/Opportunities/BdAutoSelectedReport.php';
    if (file_exists($bdReportHelper)) {
        if (!class_exists('BdAutoSelectedReport', false)) {
            require_once $bdReportHelper;
        }
        if (class_exists('BdAutoSelectedReport')) {
            (new BdAutoSelectedReport())->remove();
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

// 4. Dashboard tiles: NOTHING TO DO, and deliberately no call here.
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

// 5. Stage dropdown keys: REMOVE what post_install.php installed, unless a
// record still holds one.
//
// 🛑 G234. Until rc60 this file left the keys behind on purpose, and the note
// at the bottom said why: records on this instance hold those values, and a key
// pulled out from under a record renders as a raw string with no label. That is
// sound on a demo instance that USES the stages. It is wrong everywhere else,
// and on 2026-09-22 it left stock (ossugarcube2) still serving "Prototype
// Ordered" and "Partial Production Ordered" after a clean 16/16 uninstall with
// `error: ""` and a QRR offering 0 SQL changes - on a tenant where ZERO
// opportunities held either stage. Stock is this campaign's negative control
// for stage vocabulary, so every row graded "stock does not have this stage"
// was invalidated by keys this package could not take back.
//
// AND IT COULD NOT HAVE TAKEN THEM BACK ANYWAY - the second layer, and the one
// that makes a conditional rewrite of the first layer insufficient on its own.
// post_install.php installs the template through a hand-built ModuleInstaller
// whose id_name is 'zz_bd_stage_doms'.
//
// ⚠️ THE zz_ PREFIX BUYS NOTHING ON MERGE ORDER, whatever it looks like. Sugar
// merges application language extensions `_override` LAST, then by the mtime
// recorded in custom/Extension/application/Ext/Language/orderMapping.php, then
// stably - and that recorded mtime is refreshed only when a file's md5 changes
// (ModuleInstaller.php:2417-2463 and include/utils.php:6648, identical in
// 25.2.0 and 26.1.0). A FILENAME PLAYS NO PART. What actually keeps Bench's
// keys alive after an ERP-Epicor replace install is that install_languages()
// APPENDS to this file on every Bench install, so its md5 moves, so its
// recorded mtime is refreshed and it merges last. That is why the mitigation
// for G220 is "install Bench Dogs LAST" and why a content-identical rc60 could
// not have moved it. The id just has to stay ours and stay stable.
//
// ModuleInstaller::install_languages() writes
//
//     custom/Extension/application/Ext/Language/en_us.zz_bd_stage_doms.php
//
// (Sugar 26.1.0 ModuleInstaller.php:1219 builds the directory, :1227 builds the
// filename as "<language>.<id_name>.php"). Sugar's uninstall cannot reach that
// file by either route it owns:
//
//   * uninstall_copy() (:521) walks installdefs['copy'] and nothing else. None
//     of this package's 54 copy entries names zz_bd_stage_doms - the copy entry
//     that looks like it does, en_us.bd_stage_doms.php, is a DIFFERENT file.
//   * uninstall_languages() (:1243) is gated on isset($this->installdefs
//     ['language']), and this manifest has no 'language' key at all - its
//     installdefs are id, beans, copy, post_execute, pre_uninstall,
//     post_uninstall. It returns having done nothing. Even if the key existed,
//     it deletes "<language>.<MANIFEST id_name>.php", never our zz_ file.
//
// So the file post_install writes is unreachable by the uninstaller by
// construction, and only this script can remove it. Checked against the shipped
// rc60 zip and the 26.1.0 source; not inferred.
//
// DECLARING installdefs['language'] IN THE MANIFEST IS NOT THE FIX - and not
// for the ordering reason, which does not exist (see above). It is not the fix
// because Sugar's uninstall_languages() is UNCONDITIONAL: it would delete the
// keys on every uninstall, including the Bench instance whose opportunities
// hold them, which is precisely the case rc60 was protecting and the half of
// this gap that is a policy question rather than a mechanism. A removal that
// has to consult the data has to be ours.
//
// THE REMOVAL IS THE EXACT MIRROR OF THE INSTALL: same hand-built installer,
// same id_name, same one-entry installdefs - uninstall_languages() where
// post_install calls install_languages(). That keeps this package on the
// scanner-safe route BaseErpDropdown documents - a method call on Sugar's own
// trusted installer class, never a filesystem function written in package code,
// which ModuleScanner denylists for uploaded packages.
//
// THE GUARD IS THE OLD REASONING MADE CONDITIONAL INSTEAD OF UNIVERSAL. Records
// are counted before anything is removed, with team security OFF - an uninstall
// has to see every record, not the ones the admin's teams happen to read - and
// one holder keeps the keys and says so at fatal. Bench, whose opportunities do
// hold these stages, behaves exactly as it does today. Stock, which holds none,
// comes back clean.
//
// 'Partially Fulfilled' IS NOT OURS TO BLOCK ON WHILE PF IS INSTALLED. Partial
// Fulfillment 1.0.36 ships that key in its own right as
// _override_en_us.partial_fulfillment_quote_stage.php, declared in ITS
// installdefs['copy'] - a file this package neither writes nor removes, and an
// _override_ fragment merges last. With that file present the key survives our
// removal and quotes holding it keep their label, so blocking on them would let
// one PF quote pin Bench's sales stages on the instance forever. With it absent
// nothing else ships the key, so those quotes are counted like the
// opportunities. The presence of the file is the test, not the presence of the
// package row, because an uninstall can run in either order.
//
// FAIL CLOSED: any throw leaves every key in place, which is rc60's behaviour.
try {
    $bdStageTpl = 'custom/dropdowntemplates/bd_stage_doms.append.php';
    $bdPfOverride = 'custom/Extension/application/Ext/Language/'
        . '_override_en_us.partial_fulfillment_quote_stage.php';
    $bdHeld = array();

    $bdOppQuery = new SugarQuery();
    $bdOppQuery->select(array('id'));
    $bdOppQuery->from(BeanFactory::newBean('Opportunities'), array('team_security' => false));
    $bdOppQuery->where()->in('sales_stage', array('Prototype Ordered', 'Partial Production Ordered'));
    $bdOppQuery->limit(1);
    if ($bdOppQuery->execute()) {
        $bdHeld[] = 'Opportunities.sales_stage';
    }

    if (!file_exists($bdPfOverride)) {
        $bdQuoteQuery = new SugarQuery();
        $bdQuoteQuery->select(array('id'));
        $bdQuoteQuery->from(BeanFactory::newBean('Quotes'), array('team_security' => false));
        $bdQuoteQuery->where()->in('quote_stage', array('Partially Fulfilled'));
        $bdQuoteQuery->limit(1);
        if ($bdQuoteQuery->execute()) {
            $bdHeld[] = 'Quotes.quote_stage';
        }
    }

    if (!empty($bdHeld)) {
        $GLOBALS['log']->fatal(
            'BenchDogs-Ext: stage dropdown keys KEPT - records still hold them ('
            . implode(', ', $bdHeld)
            . '); restage those records, then remove the keys in Admin > Dropdown Editor'
        );
    } elseif (!file_exists($bdStageTpl)) {
        $GLOBALS['log']->fatal(
            'BenchDogs-Ext: stage dom template missing, stage dropdown keys left behind'
        );
    } else {
        require_once 'ModuleInstall/ModuleInstaller.php';
        $bdMi = new ModuleInstaller();
        $bdMi->silent = true;
        $bdMi->id_name = 'zz_bd_stage_doms';
        $bdMi->base_dir = getcwd();
        $bdMi->installdefs = array(
            'language' => array(
                array(
                    'from' => $bdStageTpl,
                    'to_module' => 'application',
                    'language' => 'en_us',
                ),
            ),
        );
        $bdMi->uninstall_languages();

        // uninstall_languages() rebuilds only the language it was handed
        // (en_us). The install compiles and refreshes this instance's default
        // and current languages as well, so the removal has to match: on a
        // tenant whose default_language is de_DE, rebuilding only en_us leaves
        // the compiled de_DE list still serving the keys we just deleted.
        $bdLanguages = array('en_us' => 'en_us');
        foreach (array(
            $GLOBALS['sugar_config']['default_language'] ?? 'en_us',
            $GLOBALS['current_language'] ?? 'en_us',
        ) as $bdLanguage) {
            if (is_string($bdLanguage) && trim($bdLanguage) !== '') {
                $bdLanguages[trim($bdLanguage)] = trim($bdLanguage);
            }
        }
        $bdMi->rebuild_languages($bdLanguages);
        MetaDataManager::refreshLanguagesCache(array_values($bdLanguages));
        $GLOBALS['log']->fatal(
            'BenchDogs-Ext: stage dropdown keys removed - no record held them'
        );

        // Verify against the UNCACHED lists, the way post_install.php verifies
        // the install. Two separate verdicts, because they fail differently and
        // an operator needs to know which: ours still being served means the
        // removal did not take; PF's key going missing means we removed a key
        // that was never ours - the control this fix must not break.
        foreach ($bdLanguages as $bdLanguage) {
            $bdDoms = return_app_list_strings_language($bdLanguage, false);
            if (isset($bdDoms['sales_stage_dom']['Prototype Ordered'])
                || isset($bdDoms['sales_stage_dom']['Partial Production Ordered'])
                || isset($bdDoms['sales_probability_dom']['Prototype Ordered'])
                || isset($bdDoms['sales_probability_dom']['Partial Production Ordered'])) {
                $GLOBALS['log']->fatal('BenchDogs-Ext: stage dropdown key removal verification failed');
                break;
            }
            if (file_exists($bdPfOverride)
                && !isset($bdDoms['quote_stage_dom']['Partially Fulfilled'])) {
                $GLOBALS['log']->fatal('BenchDogs-Ext: stage dropdown key removal took Partial Fulfillment quote stage');
                break;
            }
        }
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal(
        'BenchDogs-Ext: stage dropdown keys KEPT - removal failed: ' . $e->getMessage()
    );
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
// The stage dropdown keys are no longer left alone unconditionally - see step 5
// above, which removes them when nothing holds them and keeps them (loudly)
// when something does. The old blanket "leave them" was two errors in one: it
// read a demo instance's record population as a universal fact, and it
// described as a decision something the package could not have done anyway.
// What step 5 still does NOT do is edit records to free a key: an uninstall
// that restaged an opportunity to make its own cleanup possible would be
// destructive in a way `remove_tables => prompt` never asked about.
