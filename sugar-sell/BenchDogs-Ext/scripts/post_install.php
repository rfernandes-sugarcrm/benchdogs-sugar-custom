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
 * appends its own button and the REQ-19 customer group fields (see
 * BdAccountsLayoutExtensions), which never touch those panels.
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


// Bench Dogs action buttons (Quotes: Send to Estimating / Order
// Winning Line; Accounts: Create Opportunity & Quote) - same
// DeployedMetaDataImplementation mechanism as the panel above,
// idempotent by button name, so safe to re-run on every install.
try {
    if (class_exists('BdQuotesLayoutExtensions')) {
        BdQuotesLayoutExtensions::writeButtons();
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: Quotes buttons failed: ' . $e->getMessage());
}
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
try {
    $bdAccountsHelper = 'custom/modules/Accounts/BdAccountsLayoutExtensions.php';
    if (file_exists($bdAccountsHelper)) {
        require_once $bdAccountsHelper;
        if (class_exists('BdAccountsLayoutExtensions')) {
            BdAccountsLayoutExtensions::writeButtons();
        }
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: Accounts button failed: ' . $e->getMessage());
}

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

// DECISION 72 - the auto-selected marker on the Opportunity record view.
//
// This placement is only deliverable because the block you are reading now
// RUNS. Until rc25 this whole file sat inside an uncalled post_execute()
// function, so a field placed from here would have been written by nothing and
// rendered nowhere - a second invisible state rather than observability, which
// is why no earlier version shipped it. It goes through the same path that
// first proved out at 21:22:24Z on 2026-09-14.
//
// Append-only and skip-if-present, like the Accounts fields above: an admin who
// has moved the field keeps their placement.
//
// This block SELECTS NOTHING and VALUES NOTHING. It writes deployed metadata
// only. There is no automatic selection left to run: BdGoverningAutoSelect and
// the one-off bd_governing_backfill.php script went with the retired quote
// mirror, so bd_governing_origin is only ever set by a person.
try {
    $bdOppsHelper = 'custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php';
    if (file_exists($bdOppsHelper)) {
        if (!class_exists('BdOpportunitiesLayoutExtensions', false)) {
            require_once $bdOppsHelper;
        }
        if (class_exists('BdOpportunitiesLayoutExtensions')) {
            BdOpportunitiesLayoutExtensions::writeGoverningOriginField();
        }
    } else {
        $GLOBALS['log']->fatal("BenchDogs-Ext: {$bdOppsHelper} missing, skipping value-source placement");
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: value-source placement failed: ' . $e->getMessage());
}

// DECISION 72 item 3 - the saved report, which is the piece that actually
// surfaces unreviewed deals in bulk. Created only if no report of that name
// exists, so an admin's edits survive a re-install.
try {
    $bdReportHelper = 'custom/modules/Opportunities/BdAutoSelectedReport.php';
    if (file_exists($bdReportHelper)) {
        if (!class_exists('BdAutoSelectedReport', false)) {
            require_once $bdReportHelper;
        }
        if (class_exists('BdAutoSelectedReport')) {
            (new BdAutoSelectedReport())->install();
        }
    } else {
        $GLOBALS['log']->fatal("BenchDogs-Ext: {$bdReportHelper} missing, skipping the review report");
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: review report install failed: ' . $e->getMessage());
}

// Stage dropdown keys (quote_stage_dom 'Partially Fulfilled',
// sales_stage_dom 'Prototype Ordered'/'Partial Production Ordered') via
// ModuleInstaller::install_languages() - the scanner-safe route
// ERP-Core's BaseErpDropdown documents. Append-only, idempotent.
try {
    $bdTpl = 'custom/dropdowntemplates/bd_stage_doms.append.php';
    if (file_exists($bdTpl)) {
        require_once 'ModuleInstall/ModuleInstaller.php';
        $bdMi = new ModuleInstaller();
        $bdMi->silent = true;
        $bdMi->id_name = 'zz_bd_stage_doms';
        $bdMi->base_dir = getcwd();
        $bdMi->installdefs = array(
            'language' => array(
                array(
                    'from' => $bdTpl,
                    'to_module' => 'application',
                    'language' => 'en_us',
                ),
            ),
        );
        $bdMi->install_languages();
    } else {
        $GLOBALS['log']->fatal('BenchDogs-Ext: stage dom template missing, skipping dropdown install');
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: stage dropdowns failed: ' . $e->getMessage());
}

// Pin the Accounts focus drawer and the Home dashboard. Loaded via
// __DIR__ rather than a fixed custom/include/bd_scripts/ path: at
// this point in the install, the separate copy installdef entries
// (which land the permanent copy there for later manual re-runs)
// are not guaranteed to have run yet, but addTree('scripts') always
// extracts this file into the same temp directory as post_install.php
// itself, so __DIR__ is the one location guaranteed present already.
//
// GUARD THE CLASS, NOT THE PATH. This file reaches for __DIR__ and
// pre_uninstall.php falls back to custom/include/bd_scripts/, so
// BdDemoDashboards.php is reachable at two paths on an installed
// instance. require_once dedupes by RESOLVED PATH, not by class name,
// and an uninstall_before_upgrade run executes pre_uninstall.php and
// this file in ONE request - so both copies would load and PHP would
// fatal with "Cannot redeclare class BdDemoDashboards", killing the
// install outright. The sibling repository lost a demo instance to
// exactly this defect on 2026-09-11; scripts/mlp_lint.py MLP001 exists
// because of it, and flagged this line.
try {
    if (!class_exists('BdDemoDashboards', false)) {
        require_once __DIR__ . '/BdDemoDashboards.php';
    }
    (new BdDemoDashboards())->install();
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: demo dashboards failed: ' . $e->getMessage());
}


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

// A copied language fragment is not yet a usable stage domain. The
// admin repair route explicitly compiles application languages and
// refreshes their metadata; fresh installs and upgrades need that
// same lifecycle before they can report this capability as ready.
// Run after the other extension rebuilds, and verify uncached lists.
//
// This block used to rethrow a RuntimeException on a failed verification. It
// must not: see "NOTHING IN THIS FILE MAY THROW PAST ITS OWN CATCH" above. The
// verification is kept, and its verdict is logged at fatal under a fixed
// message that leaks no exception detail, so an operator can still tell a
// half-compiled language set from a good one without the package deleting
// itself to say so.
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
        if (!isset($bdDoms['quote_stage_dom']['Partially Fulfilled'])
            || !isset($bdDoms['sales_stage_dom']['Prototype Ordered'])
            || !isset($bdDoms['sales_stage_dom']['Partial Production Ordered'])
            || (string) ($bdDoms['sales_probability_dom']['Prototype Ordered'] ?? '') !== '80'
            || (string) ($bdDoms['sales_probability_dom']['Partial Production Ordered'] ?? '') !== '90') {
            $GLOBALS['log']->fatal('BenchDogs-Ext: required stage language verification failed');
            break;
        }
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: required stage language verification failed');
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
