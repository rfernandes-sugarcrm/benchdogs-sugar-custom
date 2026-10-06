<?php

/** REQ-19: which customer group Epicor puts this account in (G804, 🔒 2081b, 🔒 1810b, G380 (f)). */

$dictionary['Account']['fields']['bd_customer_group'] = array(
    'name' => 'bd_customer_group',
    'vname' => 'LBL_BD_CUSTOMER_GROUP',
    'type' => 'varchar',
    'len' => 60,
    'comment' => 'Epicor CustGrup.GroupDesc for bd_customer_group_code - the human-readable group name',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    'inline_edit' => false,
    'readonly' => true,
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'panel_overview',
        'after' => 'industry',
    ),
);

$dictionary['Account']['fields']['bd_customer_group_code'] = array(
    'name' => 'bd_customer_group_code',
    'vname' => 'LBL_BD_CUSTOMER_GROUP_CODE',
    // G804: a picker over ADM's customer groups (see the docblock); the column
    // is unchanged (an enum is stored as varchar(len)).
    'type' => 'enum',
    'len' => 10,
    'function' => array(
        'name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::customerGroupOptions',
    ),
    // Never pre-picked by the browser (G574's rule for every Bench picker).
    'defaultToBlank' => true,
    'comment' => 'Epicor Customer.GroupCode verbatim - the stable key to group and filter on',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    'inline_edit' => false,
    'readonly' => true,
    // G804: read-only once the account is in the ERP (either key set).
    'readonly_formula' => 'not(and(equal($erp_display_sync_key,""),equal($erp_sync_key,"")))',
    // G817 (🔒 2086b): the create-in-ERP prompt reads this key through ERP-Core's erp-customer-create-requirements, so an ADM account with no group is told to set Cust. Group first.
    'erp_customer_create_required_formula' => 'equal(related($erp_companies_accounts,"erp_sync_key"),"ADM")',
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'panel_overview',
        'after' => 'bd_customer_group',
    ),
);
