<?php

/**
 * Replace build (fresh installs / demo instances).
 *
 * Identical to post_install.php except for one line: the Quotes record view
 * panel is rewritten from the packaged definition even when it is already
 * deployed. That is the seam the layout plan asks for - baking makes the package
 * authoritative for a view, which on an existing tenant that has customised it
 * would discard their work, so the authoritative rewrite belongs in this build
 * and NOT in the append-only one.
 *
 * The Accounts record view is deliberately not touched by either build. Its ERP
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
                    BdQuotesLayoutExtensions::write(true);
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

        // Pin the Accounts focus drawer and the Home dashboard - see the note in
        // post_install.php for why this runs in both builds and why it loads via
        // __DIR__.
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
