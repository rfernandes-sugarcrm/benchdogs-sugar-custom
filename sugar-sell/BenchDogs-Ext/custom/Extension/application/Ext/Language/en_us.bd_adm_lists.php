<?php

/**
 * G380 / G381 (🔒 1705b): the lookup-type labels for ADM's three pick lists,
 * and the two pieces of TENANT DATA the Bench Dogs ADM rules read.
 *
 * 🛑 NEVER A WHOLE-ARRAY ASSIGNMENT HERE. Sugar merges Ext language fragments
 * in mtime order (ModuleInstaller's extension order map), so every reinstall
 * of this package puts this file LAST:
 *
 *  - erp_lookup_type_list is ERP-Core's list, extended key by key (the
 *    contract ERP-Core's own en_us.lang.php documents); assigning the array
 *    would wipe core's types after every upgrade.
 *  - the two bd_adm_* lists are tenant data an admin edits in Admin ->
 *    Dropdown Editor, which saves to its OWN Ext fragment
 *    (en_us.sugar_<list>.php). Both fragments land in one merged file and one
 *    scope, so the guard below yields to the admin's list whichever file is
 *    newer. An unguarded default would silently put the shipped values back
 *    on every reinstall.
 */

// The discriminators the Bench Dogs connector extension publishes
// (connector_ext_benchdogs.models.crm.sell.lookup_value). A row whose type is
// not in this list renders with a blank type label in Sugar.
$app_list_strings['erp_lookup_type_list']['BdLeadSources'] = 'Lead Source (ADM)';
$app_list_strings['erp_lookup_type_list']['BdLeadTypes'] = 'Lead Type (ADM)';
$app_list_strings['erp_lookup_type_list']['BdProjects'] = 'Project (ADM)';

// The ERP companies whose quotes carry the ADM rules. Key = the company code
// (ERP_Companies.erp_sync_key). Any other company's quote is left untouched.
if (!isset($app_list_strings['bd_adm_companies_list'])) {
    $app_list_strings['bd_adm_companies_list'] = array(
        'ADM' => 'ADM',
    );
}

// Product group (Epicor ProdCode) -> the ADM project pre-filled on a quote whose
// lines are all in that group. Key = ProdCode, label = ProjectID.
//
// The starting entry is the coordinator's ruling pending Bench Dogs'
// confirmation (register 🔒 1710b): pre-fill only where ADM's own order history
// is dominant (>= 95%). CMI -> 20065 is 1,281 of 1,299 CMI lines in ADM's last
// 400 orders. DISPLAYS, RET BRD, PROTO and MISC have no dominant project (they
// are job-specific), so they have no entry and the seller picks. Bench Dogs
// extends or corrects this list in Dropdown Editor; no code change needed.
if (!isset($app_list_strings['bd_adm_project_by_group_list'])) {
    $app_list_strings['bd_adm_project_by_group_list'] = array(
        'CMI' => '20065',
    );
}
