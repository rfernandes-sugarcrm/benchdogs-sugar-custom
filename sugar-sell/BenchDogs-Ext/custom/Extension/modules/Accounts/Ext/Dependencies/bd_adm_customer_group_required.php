<?php

/** On an ADM account not yet in the ERP, of any type, Cust. Group is required on save: ADM refuses an account without a group. */
$dependencies['Accounts']['bd_adm_customer_group_required'] = array(
    'hooks' => array('edit'),
    'trigger' => 'true',
    'triggerFields' => array(
        'erp_display_sync_key',
        'erp_sync_key',
        'erp_companies_accounts_name',
        'erp_companies_accountserp_companies_ida',
        // The link: the company record finishing its load is announced only as a change of it.
        'erp_companies_accounts',
    ),
    'onload' => true,
    'actions' => array(
        array(
            'name' => 'SetRequired',
            'params' => array(
                'target' => 'bd_customer_group_code',
                'label' => 'bd_customer_group_code_label',
                'value' => 'and(equal($erp_display_sync_key,""),equal($erp_sync_key,""),'
                    . 'equal(related($erp_companies_accounts,"erp_sync_key"),"ADM"))',
            ),
        ),
    ),
);
