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
            $modules = ['Quotes', 'Opportunities', 'bd01_ERP_Quote', 'bd01_ERP_Quote_Line', 'bd01_ERP_Quote_Cost'];
            $rac = new RepairAndClear();
            $rac->show_output = false;
            $rac->module_list = $modules;
            $rac->clearVardefs();
            $rac->rebuildExtensions($modules);
            MetaDataManager::refreshModulesCache($modules);
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: repair/rebuild failed: ' . $e->getMessage());
        }
    }
}
