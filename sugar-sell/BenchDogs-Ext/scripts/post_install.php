<?php

/**
 * Append-only build (existing tenants).
 *
 * Registered under installdefs['post_execute'], so Sugar calls post_execute().
 * Everything here is best-effort: an exception raised during a Module Loader
 * step aborts the install and leaves the package half-applied, so each stage is
 * guarded and failures are logged rather than thrown.
 *
 * The Quotes record view is only ever EXTENDED here - if the Bench Dogs panel is
 * already deployed it is left exactly as the admin has it. The replace-layouts
 * build (post_install_replace.php) is the one that makes the packaged definition
 * authoritative; see that file.
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
        // idempotent by button name, so safe in both builds.
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
        // ERP-Core's BaseErpDropdown documents. Append-only either build.
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

        // Pin the Accounts focus drawer and the Home dashboard. Runs in BOTH
        // builds, unlike the record view above: these are default TEMPLATES, and
        // a user who has rearranged their own drawer has a separate per-user
        // Dashboards row that this never touches - so there is no customisation
        // for the append-only build to protect here.
        //
        // Loaded via __DIR__ rather than the custom/include/bd_scripts/ copy.
        // That copy only exists once install_copy has run, and uninstall_copy
        // removes it again on every uninstall, so a path-based load would skip
        // this on the ordinary uninstall/reinstall cycle. This file ships inside
        // the same package zip right next to it.
        try {
            require_once __DIR__ . '/BdDemoDashboards.php';
            (new BdDemoDashboards())->install();
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: demo dashboards failed: ' . $e->getMessage());
        }

        try {
            SugarAutoLoader::load('modules/Administration/QuickRepairAndRebuild.php');
            $modules = ['Quotes', 'Products', 'Opportunities', 'RevenueLineItems', 'Accounts', 'bd01_ERP_Quote', 'bd01_ERP_Quote_Line', 'bd01_ERP_Quote_Cost'];
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
            VardefManager::clearVardef('Products', 'Product');
            VardefManager::clearVardef('ERP_Orders', 'ERP_Order');
            MetaDataManager::refreshModulesCache(array('Quotes', 'Products', 'ERP_Orders'));
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: relationship rebuild failed: ' . $e->getMessage());
        }

    }
}
