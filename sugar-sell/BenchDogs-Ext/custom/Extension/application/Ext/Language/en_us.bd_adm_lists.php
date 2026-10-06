<?php

/** G380 / G381 (🔒 1705b, 🔒 1724b), G460, G804: the lookup-type labels for ADM's six pick lists, and the one piece of tenant data the ADM rules read. */

// The discriminators core publishes for the ADM connection (its configured lookup_code_lists: LEADSRC -> BdLeadSources, LEADTYPE -> BdLeadTypes, projects -> BdProjects; 🔒 1724b).
$app_list_strings['erp_lookup_type_list']['BdLeadSources'] = 'Lead Source (ADM)';
$app_list_strings['erp_lookup_type_list']['BdLeadTypes'] = 'Lead Type (ADM)';
$app_list_strings['erp_lookup_type_list']['BdProjects'] = 'Project (ADM)';
// G460: published by the Bench connector extension (erp_adm_marketing_campaigns /
// erp_adm_marketing_events, ADM connection only), not by core's code lists.
$app_list_strings['erp_lookup_type_list']['BdMarketingCampaigns'] = 'Marketing Campaign (ADM)';
$app_list_strings['erp_lookup_type_list']['BdMarketingEvents'] = 'Marketing Event (ADM)';
// G804 (🔒 2081b): ADM's customer groups (Epicor CustGrup: GroupCode -> GroupDesc),
// published by the Bench connector extension; the Account's Cust. Group picker.
$app_list_strings['erp_lookup_type_list']['BdCustomerGroups'] = 'Customer Group (ADM)';

// NO company list (🔒 1724b: "ADM" from one source). A quote is an ADM quote
// when its ERP company has published BdLeadSources rows - which only the ADM
// connection's own code-list config makes happen (BdAdmRules::isAdmCompany).

// Product group (Epicor ProdCode) -> the ADM project pre-filled on a quote whose lines are all in that group (🔒 1712b, 🔒 1710b).
if (!isset($app_list_strings['bd_adm_project_by_group_list'])) {
    $app_list_strings['bd_adm_project_by_group_list'] = array(
        'CMI' => '20065',
    );
}
