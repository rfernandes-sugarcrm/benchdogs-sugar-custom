<?php
/**
 * Registers the Bench Dogs reflection modules in the application module list.
 * Without these entries Sugar resolves no route for the modules and Studio
 * hides them, even though the beans and tables install cleanly.
 */

$app_list_strings['moduleList']['bd01_ERP_Quote']      = 'ERP Quotes';
$app_list_strings['moduleList']['bd01_ERP_Quote_Line'] = 'ERP Quote Lines';
$app_list_strings['moduleList']['bd01_ERP_Quote_Cost'] = 'ERP Quote Costs';

$app_list_strings['moduleListSingular']['bd01_ERP_Quote']      = 'ERP Quote';
$app_list_strings['moduleListSingular']['bd01_ERP_Quote_Line'] = 'ERP Quote Line';
$app_list_strings['moduleListSingular']['bd01_ERP_Quote_Cost'] = 'ERP Quote Cost';
