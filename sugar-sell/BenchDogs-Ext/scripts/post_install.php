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
 */
if (function_exists('post_execute') === false) {
    function post_execute()
    {
        $layoutHelper = 'custom/modules/Quotes/BdQuotesLayoutExtensions.php';
        try {
            if (file_exists($layoutHelper)) {
                require_once $layoutHelper;
                if (class_exists('BdQuotesLayoutExtensions')) {
                    BdQuotesLayoutExtensions::write();
                }
            } else {
                $GLOBALS['log']->error("BenchDogs-Ext: {$layoutHelper} missing, skipping layout extensions");
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: layout extensions failed: ' . $e->getMessage());
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
            $GLOBALS['log']->error('BenchDogs-Ext: Quotes buttons failed: ' . $e->getMessage());
        }
        // Native-line ordering columns on the quoted-line-items grid.
        try {
            $qliHelper = 'custom/modules/Quotes/BdQliColumnsLayout.php';
            if (file_exists($qliHelper)) {
                require_once $qliHelper;
                if (class_exists('BdQliColumnsLayout')) {
                    (new BdQliColumnsLayout())->install();
                }
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: QLI columns failed: ' . $e->getMessage());
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
            $accountsHelper = 'custom/modules/Accounts/BdAccountsLayoutExtensions.php';
            if (file_exists($accountsHelper)) {
                require_once $accountsHelper;
                if (class_exists('BdAccountsLayoutExtensions')) {
                    BdAccountsLayoutExtensions::writeButtons();
                }
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: Accounts button failed: ' . $e->getMessage());
        }

        // REQ-19 customer group fields - declared in vardefs since the
        // package's first release but never placed on the record view from
        // here; only bdRepairUi() called this method, so a fresh install
        // never got them without an admin manually hitting that route.
        try {
            $accountsHelper = 'custom/modules/Accounts/BdAccountsLayoutExtensions.php';
            if (file_exists($accountsHelper)) {
                require_once $accountsHelper;
                if (class_exists('BdAccountsLayoutExtensions')) {
                    BdAccountsLayoutExtensions::writeCustomerGroupField();
                }
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: Accounts customer group field failed: ' . $e->getMessage());
        }

        // Stage dropdown keys (quote_stage_dom 'Partially Fulfilled',
        // sales_stage_dom 'Prototype Closed'/'Partial Production Closed') via
        // ModuleInstaller::install_languages() - the scanner-safe route
        // ERP-Core's BaseErpDropdown documents. Append-only, idempotent.
        try {
            $tpl = 'custom/dropdowntemplates/bd_stage_doms.append.php';
            if (file_exists($tpl)) {
                require_once 'ModuleInstall/ModuleInstaller.php';
                $mi = new ModuleInstaller();
                $mi->silent = true;
                $mi->id_name = 'zz_bd_stage_doms';
                $mi->base_dir = getcwd();
                $mi->installdefs = array(
                    'language' => array(
                        array(
                            'from' => $tpl,
                            'to_module' => 'application',
                            'language' => 'en_us',
                        ),
                    ),
                );
                $mi->install_languages();
            } else {
                $GLOBALS['log']->error('BenchDogs-Ext: stage dom template missing, skipping dropdown install');
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: stage dropdowns failed: ' . $e->getMessage());
        }

        // Pin the Accounts focus drawer and the Home dashboard. Loaded via
        // __DIR__ rather than a fixed custom/include/bd_scripts/ path: at
        // this point in post_execute, the separate copy installdef entries
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
            $GLOBALS['log']->error('BenchDogs-Ext: demo dashboards failed: ' . $e->getMessage());
        }


        try {
            SugarAutoLoader::load('modules/Administration/QuickRepairAndRebuild.php');
            $modules = ['Quotes', 'Opportunities', 'Accounts', 'bd01_ERP_Quote', 'bd01_ERP_Quote_Line', 'bd01_ERP_Quote_Cost'];
            $rac = new RepairAndClear();
            $rac->show_output = false;
            $rac->module_list = $modules;
            $rac->clearVardefs();
            $rac->rebuildExtensions($modules);
            MetaDataManager::refreshModulesCache($modules);
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: repair/rebuild failed: ' . $e->getMessage());
        }
        // quotes_erp_orders cardinality override (see the TableDictionary
        // extension file): the changed definition only takes effect after
        // the TableDictionary ext recompiles and the relationship cache
        // rebuilds - neither is covered by rebuildExtensions($modules)
        // above, which is module-scoped.
        try {
            require_once 'ModuleInstall/ModuleInstaller.php';
            $mi = new ModuleInstaller();
            $mi->silent = true;
            $mi->rebuild_tabledictionary();
            if (class_exists('SugarRelationshipFactory')) {
                SugarRelationshipFactory::deleteCache();
                SugarRelationshipFactory::rebuildCache();
            }
            VardefManager::clearVardef('Quotes', 'Quote');
            VardefManager::clearVardef('ERP_Orders', 'ERP_Order');
            MetaDataManager::refreshModulesCache(array('Quotes', 'ERP_Orders'));
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: relationship rebuild failed: ' . $e->getMessage());
        }

        // A copied language fragment is not yet a usable stage domain. The
        // admin repair route explicitly compiles application languages and
        // refreshes their metadata; fresh installs and upgrades need that
        // same lifecycle before they can report this capability as ready.
        // Run after the other extension rebuilds, and verify uncached lists.
        try {
            require_once 'ModuleInstall/ModuleInstaller.php';
            $languages = array('en_us' => 'en_us');
            foreach (array(
                $GLOBALS['sugar_config']['default_language'] ?? 'en_us',
                $GLOBALS['current_language'] ?? 'en_us',
            ) as $language) {
                if (is_string($language) && trim($language) !== '') {
                    $languages[trim($language)] = trim($language);
                }
            }
            $mi = new ModuleInstaller();
            $mi->silent = true;
            $mi->rebuild_languages($languages);
            MetaDataManager::refreshLanguagesCache(array_values($languages));
            foreach ($languages as $language) {
                $doms = return_app_list_strings_language($language, false);
                if (!isset($doms['quote_stage_dom']['Partially Fulfilled'])
                    || !isset($doms['sales_stage_dom']['Prototype Closed'])
                    || !isset($doms['sales_stage_dom']['Partial Production Closed'])
                    || (string) ($doms['sales_probability_dom']['Prototype Closed'] ?? '') !== '80'
                    || (string) ($doms['sales_probability_dom']['Partial Production Closed'] ?? '') !== '90') {
                    throw new RuntimeException('Required stage domains unavailable');
                }
            }
        } catch (Throwable $e) {
            $message = 'BenchDogs-Ext: required stage language verification failed';
            $GLOBALS['log']->error($message);
            throw new RuntimeException($message);
        }
    }
}
