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

// 🛑 FIRST, BECAUSE IT IS THE HALF THAT CANNOT BE REDONE LATER (G280 / 🔒 1507).
//
// rc65 retired this package's Opportunity release-stage PROVIDER: the shipped
// custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php now
// returns null, which hands the decision to Partial Fulfillment's generic path.
// That path reads a TENANT CONFIG key, and a config row is data - it does not
// arrive with the package. If the provider is neutered and this key is not
// written, the Opportunity stage silently stops being written at all, so this
// block runs BEFORE any layout work and has its own guard.
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
// Native-line ordering columns on the quoted-line-items grid.
try {
    $bdQliHelper = 'custom/modules/Quotes/BdQliColumnsLayout.php';
    if (file_exists($bdQliHelper)) {
        require_once $bdQliHelper;
        if (class_exists('BdQliColumnsLayout')) {
            (new BdQliColumnsLayout())->install();
        }
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: QLI columns failed: ' . $e->getMessage());
}
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

// 🛑 G116, SECOND PLACEMENT OF THE SAME RETIRED FIELD. Decision 72 item 3's
// saved report filtered Opportunities on `bd_governing_origin = 'auto'` and
// drew it as a column. With the vardef retired that report cannot error and
// cannot fill: it renders EMPTY, which on a review queue reads as "nothing to
// review" rather than as a broken report - the trap rc24's operator notes
// describe, and the reason pre_uninstall.php has always removed it. Creating
// it on install while the field it filters no longer exists is that trap
// re-armed once per install, so the install now REMOVES it too.
//
// Matched by exact name, so an admin who renamed or copied the report keeps
// theirs; mark_deleted() is a soft delete, so a row is recoverable.
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
        $GLOBALS['log']->fatal("BenchDogs-Ext: {$bdReportHelper} missing, retired review report left behind");
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: review report removal failed: ' . $e->getMessage());
}

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

// DECISION 314 - stage rename data migration. The two release stages were
// renamed from '<slice> Closed' to '<slice> Ordered' because the code that
// writes them branches on $product->erp_ordered: the semantics were always
// "this slice has been ORDERED", and the opportunity deliberately stays OPEN
// (probabilities 80/90; only Closed Won/Closed Lost are terminal). Records
// written before the rename still hold the old literal in sales_stage, and a
// stage whose key is absent from sales_stage_dom renders blank.
//
// DELIBERATELY RAW SQL, NOT BEANS. Loading and save()-ing an Opportunity
// fires the valuation hooks - the same writers that re-pointed a fixture's
// amount to $0.00 on 2026-09-15 (decision 311/313). A migration must not
// recompute anything; it only renames a stored key. Raw SQL fires no hooks.
//
// Idempotent: the WHERE clause matches only the old literals, so a re-install
// is a no-op. The _audit trail is deliberately NOT rewritten - it is history,
// and the old key is what those rows genuinely recorded at the time.
try {
    $bdDb = DBManagerFactory::getInstance();
    $bdStageRenames = array(
        'Prototype Closed' => 'Prototype Ordered',
        'Partial Production Closed' => 'Partial Production Ordered',
    );
    foreach ($bdStageRenames as $bdOldStage => $bdNewStage) {
        $bdSql = 'UPDATE opportunities SET sales_stage = '
            . $bdDb->quoted($bdNewStage)
            . ' WHERE sales_stage = ' . $bdDb->quoted($bdOldStage);
        $bdResult = $bdDb->query($bdSql);
        $bdMoved = (int) $bdDb->getAffectedRowCount($bdResult);
        $GLOBALS['log']->fatal(
            'BenchDogs-Ext: stage migration ' . $bdOldStage . ' -> ' . $bdNewStage
            . ' applied to ' . $bdMoved . ' row(s)'
        );
    }
} catch (Throwable $e) {
    // Never fail the install on the migration: the package is still correct
    // for every record written from now on, and an unmigrated old row is
    // visible and fixable. Loud, not fatal.
    $GLOBALS['log']->fatal('BenchDogs-Ext: stage rename migration failed: ' . $e->getMessage());
}

$GLOBALS['log']->fatal('BenchDogs-Ext: post_install finished');
