<?php

/**
 * Single build, used for both fresh installs and upgrades of an existing
 * tenant. Previously split into two builds/zips (post_install.php +
 * post_install_replace.php) differing only in this file's write(true) vs.
 * write(false) call - and a real bug: BOTH files passed true, so the build
 * documented as "the safe one for an existing tenant" was actually
 * rewriting the Bench Dogs Quotes panel unconditionally on every install,
 * discarding any Studio customization a tenant had made since. There was
 * never a legitimate case for true in the first place: on a genuinely
 * fresh install the panel does not exist yet, so write()'s default
 * (append-if-missing, skip-if-present) already adds it - see
 * BdQuotesLayoutExtensions::write()'s own docblock.
 *
 * The Accounts ERP panels themselves (LBL_RECORDVIEW_PANEL_ERP,
 * _BILLING_DETAIL, _CREDIT_DETAIL, _SYNC_STATUS) are built by ERP-Epicor's
 * AccountsLayout, which already runs in replace mode and owns every field in
 * them - this package never writes those panels or their fields. It only
 * appends the REQ-19 customer group fields (see BdAccountsLayoutExtensions),
 * which never touch those panels, and it touches no record-view button at all
 * (G276).
 *
 * TOP-LEVEL CODE, NOT A FUNCTION (0.9.42-rc26)
 *
 * Until rc25 this whole body sat inside
 *     if (function_exists('post_execute') === false) { function post_execute() { ... } }
 * and NOTHING EVER CALLED IT. manifest.php registers this file under the
 * post_execute installdef, but ModuleInstaller::post_execute() (read directly
 * in SugarEnt-Full 25.2.0 and 26.1.0, ModuleInstall/ModuleInstaller.php:426-440)
 * only require_once's each registered file; it never calls a global function
 * named after the installdef key. So every line below was dead code on every
 * install of 0.9.41 and the whole 0.9.42 line, and Module Loader reported
 * 19/19 success either way.
 *
 * pre_uninstall.php:40-53 in this same package had already worked this out and
 * says it at length: top-level code is correct whether the loader merely
 * requires the file or also calls a function named for the key; a function is
 * correct under only one of those, and under the other it is a SILENT no-op.
 * ERP-Epicor's scripts/post_execute.php is plain top-level code and its work
 * demonstrably lands on the same tenant, with the same installer, in the same
 * install cycle. Both uninstall scripts here were converted years of rcs ago.
 * This one finally follows them.
 *
 * NOTHING IN THIS FILE MAY THROW PAST ITS OWN CATCH
 *
 * On the post_execute path an uncaught throw is a FAILED INSTALL, and Module
 * Loader's failure path force-uninstalls the package - i.e. a throw here does
 * not leave the previous version in place, it takes Bench Dogs off the tenant
 * altogether. While the body was unreachable that was harmless; the moment it
 * became live it stopped being harmless. So:
 *   - every block keeps its own try/catch (Throwable) and its own
 *     file_exists/class_exists guard, exactly as pre_uninstall.php does;
 *   - the stage-language verification at the bottom, which used to rethrow a
 *     RuntimeException, now LOGS AND RETURNS. A missing stage domain is a real
 *     defect and must be visible, but it is not worth deleting the package and
 *     its deployed metadata over: the five keys it checks are also shipped
 *     declaratively by the copy'd custom/Extension/application/Ext/Language/
 *     en_us.bd_stage_doms.php, which is the route that has actually been
 *     working on SugarCloud all along.
 * scripts/tests/test_post_install_stage_languages.py asserts both properties
 * statically (no function wrapper, no throw) as well as behaviourally.
 *
 * WHY EVERY LINE LOGS AT FATAL
 *
 * Same reason pre_uninstall.php:67-74 gives. A successful Module Loader run is
 * not evidence that this file ran, and ->error() lines do not survive an
 * instance whose log level is fatal. After an install, grep sugarcrm.log for
 * "BenchDogs-Ext: post_install running": present means the wiring is live,
 * absent means treat it as broken again. The per-step failure lines are at the
 * same level so that "it ran but DeployedMetaDataImplementation threw" is
 * distinguishable from "it never ran" - those two produce identical deployed
 * metadata and were confused for a whole release cycle.
 *
 * WHY EVERY LOCAL IS bd-PREFIXED
 *
 * require_once inside ModuleInstaller::post_execute() executes this file in
 * that METHOD's scope, which has already run extract($data) over the manifest.
 * An unprefixed $manifest/$installdefs/$modules here would overwrite the
 * installer's own locals mid-install. pre_uninstall.php prefixes for the same
 * reason.
 */

// Proof of life. See the note above; this is the only thing that can tell an
// operator whether the post_execute wiring is alive, and it is emitted exactly
// once per install request.
$GLOBALS['log']->fatal('BenchDogs-Ext: post_install running - writing deployed metadata');

// 🛑 FIRST, BECAUSE IT IS THE HALF THAT CANNOT BE REDONE LATER (G280 /
// 🔒 1507, 🔒 1508).
//
// rc65 retired this package's Opportunity release-stage PROVIDER to a stub that
// returned null; 0.9.42-rc66 STOPS SHIPPING THAT FILE ENTIRELY. Both shapes
// hand the decision to Partial Fulfillment's generic path and PF reaches the
// SAME decision either way - `policy_provider_null` and `policy_provider_absent`
// fall through to the identical branch (ErpOpportunityValuation.php:310-345),
// which scripts/tests/test_release_stage_absent_equals_null.py proves by RUNNING
// PF's own resolver both ways rather than by asserting the file is gone.
//
// 🚩 THIS BLOCK IS WHY THE DELETION IS SAFE AND MUST OUTLIVE IT. PF's generic
// path reads a TENANT CONFIG key, and a config row is data - it does not arrive
// with the package. With no provider and no key, the Opportunity stage silently
// stops being written at all. So this runs BEFORE any layout work, with its own
// guard, and 🔒 1508's carve-out for "one-shot migrations a tenant may not have
// taken yet" is exactly what keeps it here.
//
// Written only when absent: a tenant (or an admin) that already chose a stage
// keeps it. The probability is deliberately NOT written - PF derives it from
// sales_probability_dom, where G278 / 🔒 1506 ships 90 for this key.
try {
    $bdAdmin = BeanFactory::newBean('Administration');
    $bdErpConfig = $bdAdmin->getConfigForModule('erp_integration');
    $bdCurrentStage = is_array($bdErpConfig)
        ? trim((string) ($bdErpConfig['partial_order_sales_stage'] ?? ''))
        : '';
    if ($bdCurrentStage === '') {
        $bdAdmin->saveSetting('erp_integration', 'partial_order_sales_stage', 'Partial Production Ordered');
        $GLOBALS['log']->fatal(
            'BenchDogs-Ext: erp_integration.partial_order_sales_stage set to Partial Production Ordered'
        );
    } else {
        $GLOBALS['log']->fatal(
            'BenchDogs-Ext: erp_integration.partial_order_sales_stage already set, left as is'
        );
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: partial order stage config failed: ' . $e->getMessage());
}

$bdLayoutHelper = 'custom/modules/Quotes/BdQuotesLayoutExtensions.php';
try {
    if (file_exists($bdLayoutHelper)) {
        require_once $bdLayoutHelper;
        if (class_exists('BdQuotesLayoutExtensions')) {
            BdQuotesLayoutExtensions::write();
        }
    } else {
        $GLOBALS['log']->fatal("BenchDogs-Ext: {$bdLayoutHelper} missing, skipping layout extensions");
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: layout extensions failed: ' . $e->getMessage());
}


// 🛑 G276 / 🔒 1503 + 🔒 1504 - NO BUTTON LOGIC, ON ANY RECORD VIEW.
//
// Until rc64 a block here called BdQuotesLayoutExtensions::writeButtons(),
// which stripped ERP-Epicor's create_erp_order_button (Submit Order) and
// refresh_price_availability_button off the deployed Quotes view on every
// install and stashed them in config (decision 91, rc26), and another called
// BdAccountsLayoutExtensions::writeButtons(). Owner, verbatim: "a seller should
// have both selected line and submited order use core dont use anything from
// bench dog extension logic for buttons!" and "remove now all button logic from
// bench". Both calls and both methods are gone; this package no longer adds,
// removes, hides, reorders or stashes a record-view button anywhere.
//
// The buttons decision 91 stripped come back through CORE: every ERP-Epicor
// install runs QuotesLayout::install() -> addButtonsToRecordView(), which adds
// a missing button and reconciles an existing one to core's current definition.
// Install ERP-Epicor, then this package (the G273 order for the sales stages
// already requires exactly that), and nothing here takes them away again.
// 🛑 THE QUOTED-LINE GRID IS NOT THIS PACKAGE'S ANY MORE (0.9.42-rc66,
// G280 / 🔒 1508: *"from all the non vustomer category code we should not
// ahve other stuff there"*). BdQliColumnsLayout and BdQliColumnTemplate are
// DELETED and nothing replaces this call. Three things went with them, and
// each is recorded here rather than in a release note nobody reads:
//
//  1. THE COLUMN ORDER, which had ALREADY STOPPED WORKING. rc65's own install
//     on Bench logged
//         BenchDogs-Ext: QLI columns failed: Call to private method
//         BaseErpLayout::loadView() from scope BdQliColumnsLayout
//     at 17:16:59Z. `loadView()` is `private` (ERP-Core BaseErpLayout.php:1778),
//     so applyColumnOrder() could not run on this tenant and cannot run on a
//     fresh one either. The grid a seller sees is therefore unchanged by this
//     deletion: it is whatever is already deployed, plus core's own appends.
//     Nothing about the removal reaches a SHIPPED viewdef - decision 803 moved
//     the authored list to a path Sugar does not read as one, precisely so this
//     package could never overwrite another's columns again.
//  2. THE erp_quote_line_num FETCH INJECTION. That is CORE's field, and no
//     viewdef in this package, ERP-Core, ERP-Epicor or Partial Fulfillment
//     draws it - so nothing rendered it and nothing loses it. The column stays
//     in the deployed allowlist on tenants that have it: inert, and core's.
//  3. THE bd_to_order / bd_ordered LEGACY COLUMN SWEEP, which has run on every
//     install since 0.9.21 and on this tenant through rc65. It removes from
//     DEPLOYED METADATA, which persists, so it is spent where it was needed and
//     was never armed anywhere else - stock carries no Bench Dogs by design.
//
// Ordering selected lines is ERP-Epicor-PartialFulfillment's own
// route, and every Bench Dogs quote is an advanced_quote (mirrored
// from Kinetic) - it just works once that package is installed,
// for any quote type, with nothing for this package to opt into.
// erp_integration.advanced_quotes_orderable used to be a real gate
// this had to flip; that gate no longer exists at all.
// Files this package used to ship and no longer does stay on disk
// after an upgrade install (Sugar Cloud's package scanner denylists
// every file-removal call, the SugarAutoLoader wrapper included -
// by name, comments too). They are inert: the zzz_ quotes_erp_orders dictionary now says exactly
// what ERP-Epicor >= 1.0.84 says (one-to-many over the same join
// table), the bd-order-selected / bd-order-winning field JS is no
// longer referenced by any button, and bd_order_requested_at is an
// unread column. A fresh install never gets them at all.
// REQ-19 customer group fields - declared in vardefs since the
// package's first release but never placed on the record view from
// here; only bdRepairUi() called this method, so a fresh install
// never got them without an admin manually hitting that route.
try {
    $bdAccountsHelper = 'custom/modules/Accounts/BdAccountsLayoutExtensions.php';
    if (file_exists($bdAccountsHelper)) {
        require_once $bdAccountsHelper;
        if (class_exists('BdAccountsLayoutExtensions')) {
            BdAccountsLayoutExtensions::writeCustomerGroupField();
        }
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: Accounts customer group field failed: ' . $e->getMessage());
}

// 🛑 G116 - DECISION 72's MARKER IS REMOVED HERE, NOT PLACED.
//
// Until rc56 this block called
// BdOpportunitiesLayoutExtensions::writeGoverningOriginField(), which appended
// `bd_governing_origin` to the Opportunity record view with the label
// `LBL_BD_GOVERNING_ORIGIN`. 🔒 1044 had already retired BOTH halves of that
// field - the vardef and the label are stubs that declare NOTHING - so every
// install re-armed a row rendering the raw key and "No data": the same G96/G99
// shape the Quotes panel retirement had just been declared closed on. It was
// missed because that census read QUOTES module metadata only, and this
// placement is on OPPORTUNITIES.
//
// REMOVING THE CALL WOULD NOT HAVE BEEN ENOUGH. Deployed metadata is covered
// by no installdef, so on every tenant that installed rc26..rc56 the row would
// have stayed there for good. The retirement has to RUN, so this block calls
// remove() - append-only placement replaced by a removal, exactly as
// BdQuotesLayoutExtensions::write() removes the Bench Dogs panel and never
// adds it. remove() sweeps EVERY panel, so an admin who moved the field is
// cleaned up too, and it writes nothing when there is nothing to remove.
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
        $GLOBALS['log']->fatal("BenchDogs-Ext: {$bdOppsHelper} missing, retired value-source marker not removed");
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: value-source marker removal failed: ' . $e->getMessage());
}

// 🛑 G116's SAVED-REPORT REMOVER IS SPENT AND IS GONE (0.9.42-rc66,
// G280 / 🔒 1508). BdAutoSelectedReport took decision 72 item 3's report
// "Opportunities Valued From an Auto-Selected Quote Line" back off the
// instance, because with `bd_governing_origin` retired that report renders
// EMPTY - a review queue that reads as "nothing to review" rather than as a
// broken report.
//
// 🚩 WHY IT IS SAFE TO STOP SHIPPING A REMOVAL, which is the question this
// deletion has to answer and the one a green test cannot. Not "the class looks
// unused" - it was called, from here and from pre_uninstall. The evidence is
// that IT ALREADY RAN AND FOUND NOTHING, read out of the tenant's own
// package_install.log for the rc65 install (PID 2069085, 17:16:59 -> 17:17:35):
// the block above logs `removed retired saved report "..."` ONLY when it
// deletes a row, and logs `missing, retired review report left behind` if the
// file is absent. NEITHER line appears anywhere in that window, while every
// other BenchDogs-Ext line does. So remove() executed and the row was already
// gone. SugarQuery excludes soft-deleted rows, so a row it once deleted can
// never be re-found; there is nothing left for a re-run to do.
//
// Tenant 1 is the only instance carrying this package - stock has no Bench Dogs
// by design (RELEASE-CONTROL.md:757) - so "spent here" is "spent".

// 🛑 THE STAGE VOCABULARY IS CORE'S NOW — AND THIS TAKES BENCH'S COPY BACK.
// G278 / 🔒 1506 ("this hsoudl happen in the core") + G280 / 🔒 1507.
//
// Partial Fulfillment 1.0.40 ships sales_stage_dom['Prototype Ordered'] and
// ['Partial Production Ordered'] byte-exact, their 80/90 probabilities, their
// two Sidecar styles, and has always shipped quote_stage_dom['Partially
// Fulfilled']. It declares them in _override_ fragments, which Sugar merges
// LAST whatever the mtimes say, so they cannot be wiped by a whole-array
// replace (that is G220/G273's whole problem, solved at the source). The
// manifest now requires that version.
//
// So this package stops declaring any of it - and "stops declaring" is not
// enough on a hosted tenant. Until rc64 post_install APPENDED the template to
// custom/Extension/application/Ext/Language/en_us.zz_bd_stage_doms.php through
// ModuleInstaller::install_languages(), which CONCATENATES rather than
// overwrites (26.1.0 ModuleInstaller.php:1227-1235). That accumulated file is
// still on every Bench Dogs tenant, carrying every key every past version ever
// appended - including decision 314's retired '...Closed' pair (G268).
//
// uninstall_languages() is the exact mirror of the install and the one removal
// available to a package: it deletes en_us.<id_name>.php and rebuilds the
// language cache (:1243-1265). unlink() is denied to package code by the cloud
// scanner, so nothing else could take this file back. Same installer class,
// same id_name, same template path as the install used.
//
// Idempotent: on a tenant that never had the file, sugar_is_file() is false and
// the call only rebuilds. rc64's tenants run this once and are done.
try {
    require_once 'ModuleInstall/ModuleInstaller.php';
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
    $bdMi->uninstall_languages();
    $GLOBALS['log']->fatal(
        'BenchDogs-Ext: removed the zz_bd_stage_doms language fragment; the sales '
        . 'and quote stages are Partial Fulfillment\'s'
    );
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: stage fragment removal failed: ' . $e->getMessage());
}

// 🛑 THE BAKED DEMO DASHBOARDS ARE GONE (G280 / 🔒 1507).
//
// This block composed two dashboards - the Accounts focus drawer and the Home
// dashboard - out of OTHER packages' dashlets (ERP-Core's account-card and
// erp-account-snapshot, the BAQ dashlet, Account Hierarchy's saah-*, sales-i's
// recommendations-dashlet and gai-dashlet) plus one Bench-authored stock
// `dashablelist` tile, "ERP Quote Pipeline". The owner: *"Donthave any logic on
// bench that is not on core"* - a demo layout made of other packages' tiles is
// exactly that, and scripts/BdDemoDashboards.php (705 lines) went with the
// call. If the pipeline tile is wanted, it belongs in core's ErpDemoDashboards.
//
// A tenant that already has the dashboards keeps them: they are rows in the
// dashboards table, written once per install, and nothing here rewrites them
// any more. The copy of the class under custom/include/bd_scripts/ that earlier
// installs left behind is unreachable - nothing requires it.

try {
    SugarAutoLoader::load('modules/Administration/QuickRepairAndRebuild.php');
    $bdRepairModules = ['Quotes', 'Opportunities', 'Accounts'];
    $bdRac = new RepairAndClear();
    $bdRac->show_output = false;
    $bdRac->module_list = $bdRepairModules;
    $bdRac->clearVardefs();
    $bdRac->rebuildExtensions($bdRepairModules);
    MetaDataManager::refreshModulesCache($bdRepairModules);
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: repair/rebuild failed: ' . $e->getMessage());
}
// Generic cache and relationship rebuild. The quotes_erp_orders cardinality
// override this block was originally written for is GONE - see the note at the
// top of the Accounts block: the package ships no TableDictionary file and no
// zzz_ file any more, because the shipped dictionary now says exactly what
// ERP-Epicor >= 1.0.84 says. What is left is the generic rebuild, which the
// install runbook's mandated manual Quick Repair also covers.
try {
    require_once 'ModuleInstall/ModuleInstaller.php';
    $bdMi = new ModuleInstaller();
    $bdMi->silent = true;
    $bdMi->rebuild_tabledictionary();
    if (class_exists('SugarRelationshipFactory')) {
        SugarRelationshipFactory::deleteCache();
        SugarRelationshipFactory::rebuildCache();
    }
    VardefManager::clearVardef('Quotes', 'Quote');
    VardefManager::clearVardef('ERP_Orders', 'ERP_Order');
    MetaDataManager::refreshModulesCache(array('Quotes', 'ERP_Orders'));
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: relationship rebuild failed: ' . $e->getMessage());
}

// The language cache still has to be rebuilt, because this install EMPTIED two
// fragments it used to declare stages in (en_us.bd_stage_doms.php and the
// DropdownsStyle file) and deleted a third above. Until the application
// languages are recompiled the tenant keeps serving what those files said.
//
// The verification that follows is a READ, logged and never thrown: it says
// whether core is serving the two stages after this install. It is not this
// package's contract any more - Partial Fulfillment owns the keys - but a
// Bench Opportunity stores those strings, so an operator needs one line in the
// log that says whether they are still there.
try {
    require_once 'ModuleInstall/ModuleInstaller.php';
    $bdLanguages = array('en_us' => 'en_us');
    foreach (array(
        $GLOBALS['sugar_config']['default_language'] ?? 'en_us',
        $GLOBALS['current_language'] ?? 'en_us',
    ) as $bdLanguage) {
        if (is_string($bdLanguage) && trim($bdLanguage) !== '') {
            $bdLanguages[trim($bdLanguage)] = trim($bdLanguage);
        }
    }
    $bdMi = new ModuleInstaller();
    $bdMi->silent = true;
    $bdMi->rebuild_languages($bdLanguages);
    MetaDataManager::refreshLanguagesCache(array_values($bdLanguages));
    foreach ($bdLanguages as $bdLanguage) {
        $bdDoms = return_app_list_strings_language($bdLanguage, false);
        $bdServed = isset($bdDoms['sales_stage_dom']['Prototype Ordered'])
            && isset($bdDoms['sales_stage_dom']['Partial Production Ordered']);
        $GLOBALS['log']->fatal(
            'BenchDogs-Ext: release stages served by core after this install: '
            . ($bdServed ? 'yes' : 'NO - install Partial Fulfillment >= 1.0.40')
        );
        if (isset($bdDoms['sales_stage_dom']['Prototype Closed'])
            || isset($bdDoms['sales_stage_dom']['Partial Production Closed'])) {
            $GLOBALS['log']->fatal('BenchDogs-Ext: retired stage names still served');
        }
        break;
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: language rebuild failed: ' . $e->getMessage());
}

// 🛑 DECISION 314's STAGE-RENAME MIGRATION IS DRAINED AND IS GONE (0.9.42-rc66,
// G280 / 🔒 1508). It renamed the stored `sales_stage` literals
// 'Prototype Closed' -> 'Prototype Ordered' and 'Partial Production Closed' ->
// 'Partial Production Ordered' in raw SQL, because a stage key that is absent
// from sales_stage_dom renders BLANK on the record.
//
// 🔒 1508 keeps "the one-shot migrations and tombstones a tenant may not have
// taken yet" - so this one only qualifies for removal if it has taken. IT HAS,
// and the measurement is the tenant's own package_install.log for the rc65
// install (PID 2069085, the same window that carries post_install's `running`
// and `finished` lines):
//
//     17:17:35  BenchDogs-Ext: stage migration Prototype Closed ->
//               Prototype Ordered applied to 0 row(s)
//     17:17:35  BenchDogs-Ext: stage migration Partial Production Closed ->
//               Partial Production Ordered applied to 0 row(s)
//
// 0 rows on both, on the only instance that carries this package (stock has no
// Bench Dogs by design, RELEASE-CONTROL.md:757). Nothing writes the old
// literals any more either: rc65 took the retired pair out of the served
// vocabulary with uninstall_languages('zz_bd_stage_doms') ([[G268]]), and the
// only writer left is Partial Fulfillment, from the config key post_install
// sets ABOVE - which holds the NEW literal. A migration with no source and no
// rows left to move is not a safeguard; it is an UPDATE that runs on every
// install for nothing.

$GLOBALS['log']->fatal('BenchDogs-Ext: post_install finished');
