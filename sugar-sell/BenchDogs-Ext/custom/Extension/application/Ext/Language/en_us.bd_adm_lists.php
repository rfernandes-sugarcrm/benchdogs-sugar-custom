<?php

/** G380 / G381 (🔒 1705b, 🔒 1724b), G460, G804: the lookup-type labels for ADM's six pick lists. */

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
// Published by the Bench connector extension (erp_adm_sales_categories, ADM connection only).
$app_list_strings['erp_lookup_type_list']['BdSalesCategories'] = 'Sales Category (ADM)';
// Published by the Bench connector extension (erp_adm_product_categories, ADM connection only).
$app_list_strings['erp_lookup_type_list']['BdProductCategories'] = 'Product Category (ADM)';

// NO company list (🔒 1724b): ADM's companies are the active BdAdmCompanies rows the connector extension publishes (BdAdmRules::isAdmCompany).
