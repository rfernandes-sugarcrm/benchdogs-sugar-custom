<?php

if (function_exists('post_install') === false) {
    function post_install()
    {
        require_once 'custom/modules/Quotes/BdQuotesLayoutExtensions.php';
        BdQuotesLayoutExtensions::write();

        SugarAutoLoader::load('modules/Administration/QuickRepairAndRebuild.php');
        $modules = ['Quotes', 'Opportunities', 'bd01_ERP_Quote', 'bd01_ERP_Quote_Line', 'bd01_ERP_Quote_Cost'];
        $rac = new RepairAndClear();
        $rac->show_output = false;
        $rac->module_list = $modules;
        $rac->clearVardefs();
        $rac->rebuildExtensions($modules);
        MetaDataManager::refreshModulesCache($modules);
    }
}
