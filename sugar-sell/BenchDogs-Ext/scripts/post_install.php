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
 * The Accounts record view is deliberately not touched here. Its ERP
 * panels (LBL_RECORDVIEW_PANEL_ERP, _BILLING_DETAIL, _CREDIT_DETAIL,
 * _SYNC_STATUS) are built by ERP-Epicor's AccountsLayout, which already runs in
 * replace mode and owns every field in them; this package ships no Accounts
 * fields at all, so writing that layout from here would place fields it does not
 * declare and fight the package that does.
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
        // route. Every Bench Dogs quote is an advanced_quote (mirrored
        // from Kinetic), which that route refuses server-side by default -
        // this deployment opts in. Button-side visibility needs nothing
        // from this package anymore: the button shows for every quote
        // type once its own package is installed, with no per-deployment
        // flag to set on it.
        try {
            $admin = BeanFactory::getBean('Administration');
            $admin->saveSetting('erp_integration', 'advanced_quotes_orderable', '1', 'base');
            $GLOBALS['log']->info('BenchDogs-Ext: erp_integration.advanced_quotes_orderable = 1');
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: advanced-quote opt-in failed: ' . $e->getMessage());
        }
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
        try {
            require_once __DIR__ . '/BdDemoDashboards.php';
            (new BdDemoDashboards())->install();
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: demo dashboards failed: ' . $e->getMessage());
        }


        try {
            SugarAutoLoader::load('modules/Administration/QuickRepairAndRebuild.php');
            $modules = ['Quotes', 'Opportunities', 'RevenueLineItems', 'Accounts', 'bd01_ERP_Quote', 'bd01_ERP_Quote_Line', 'bd01_ERP_Quote_Cost'];
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

    }
}
