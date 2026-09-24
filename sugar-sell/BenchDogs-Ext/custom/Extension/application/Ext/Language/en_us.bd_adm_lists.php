<?php

/**
 * G380 / G381 (🔒 1705b, 🔒 1724b): the lookup-type labels for ADM's three pick
 * lists, and the one piece of TENANT DATA the Bench Dogs ADM rules read.
 *
 * 🛑 NEVER A WHOLE-ARRAY ASSIGNMENT HERE. Sugar merges Ext language fragments
 * in mtime order (ModuleInstaller's extension order map), so every reinstall
 * of this package puts this file LAST:
 *
 *  - erp_lookup_type_list is ERP-Core's list, extended key by key (the
 *    contract ERP-Core's own en_us.lang.php documents); assigning the array
 *    would wipe core's types after every upgrade.
 *  - bd_adm_project_by_group_list is tenant data an admin edits in Admin ->
 *    Dropdown Editor, which saves to its OWN Ext fragment
 *    (en_us.sugar_<list>.php). Both fragments land in one merged file and one
 *    scope, so the guard below yields to the admin's list whichever file is
 *    newer. An unguarded default would silently put the shipped values back
 *    on every reinstall.
 */

// The discriminators core publishes for the ADM connection (its configured
// lookup_code_lists: LEADSRC -> BdLeadSources, LEADTYPE -> BdLeadTypes, projects
// -> BdProjects; 🔒 1724b). A row whose type is not in this list renders with a
// blank type label in Sugar.
$app_list_strings['erp_lookup_type_list']['BdLeadSources'] = 'Lead Source (ADM)';
$app_list_strings['erp_lookup_type_list']['BdLeadTypes'] = 'Lead Type (ADM)';
$app_list_strings['erp_lookup_type_list']['BdProjects'] = 'Project (ADM)';

// NO company list (🔒 1724b: "ADM" from one source). A quote is an ADM quote
// when its ERP company has published BdLeadSources rows - which only the ADM
// connection's own code-list config makes happen (BdAdmRules::isAdmCompany).

// Product group (Epicor ProdCode) -> the ADM project pre-filled on a quote whose
// lines are all in that group. Key = ProdCode, label = ProjectID.
//
// The owner's rule (register 🔒 1712b, confirming 🔒 1710b): pre-fill only where
// ADM's own order history is >= 95% one project. Today that is CMI -> 20065
// alone (1,281 of 1,299 CMI lines in ADM's last 400 orders). DISPLAYS, RET BRD,
// PROTO and MISC have no dominant project (they are job-specific), so they have
// no entry and the seller picks from ADM's ACTIVE projects. Bench Dogs extends
// this list in Dropdown Editor; no code change needed.
if (!isset($app_list_strings['bd_adm_project_by_group_list'])) {
    $app_list_strings['bd_adm_project_by_group_list'] = array(
        'CMI' => '20065',
    );
}
