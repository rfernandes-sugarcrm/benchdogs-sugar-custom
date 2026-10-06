<?php

/** 🔒 2085b (G804): on a Bench account typed Customer and not yet in the ERP, Cust. Group is required on save, because ADM refuses a customer without a group. */
$dependencies['Accounts']['bd_adm_customer_group_required'] = array(
    'hooks' => array('edit'),
    'trigger' => 'true',
    'triggerFields' => array(
        'account_type',
        'erp_display_sync_key',
        'erp_sync_key',
        'erp_companies_accounts_name',
        'erp_companies_accountserp_companies_ida',
    ),
    'onload' => true,
    'actions' => array(
        array(
            'name' => 'SetRequired',
            'params' => array(
                'target' => 'bd_customer_group_code',
                'label' => 'bd_customer_group_code_label',
                'value' => 'and(equal($account_type,"Customer"),equal($erp_display_sync_key,""),'
                    . 'equal($erp_sync_key,""),equal(related($erp_companies_accounts,"erp_sync_key"),"ADM"))',
            ),
        ),
    ),
);
