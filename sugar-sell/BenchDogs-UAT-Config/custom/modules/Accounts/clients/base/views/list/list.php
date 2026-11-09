<?php
// created: 2026-10-23 04:39:41
$viewdefs['Accounts']['base']['view']['list'] = array (
  'panels' => 
  array (
    0 => 
    array (
      'name' => 'panel_header',
      'label' => 'LBL_PANEL_1',
      'fields' => 
      array (
        0 => 
        array (
          'name' => 'account_type',
          'label' => 'LBL_TYPE',
          'enabled' => true,
          'default' => false,
        ),
        1 => 
        array (
          'name' => 'billing_address_state',
          'label' => 'LBL_BILLING_ADDRESS_STATE',
          'enabled' => true,
          'default' => false,
        ),
        2 => 
        array (
          'name' => 'bd_customer_group',
          'label' => 'LBL_BD_CUSTOMER_GROUP',
          'enabled' => true,
          'default' => false,
        ),
        3 => 
        array (
          'name' => 'days_since_last_interaction_c',
          'label' => 'LBL_DAYS_SINCE_LAST_INTERACTION_C',
          'enabled' => true,
          'default' => false,
        ),
        4 => 
        array (
          'name' => 'days_since_last_sale_c',
          'label' => 'LBL_DAYS_SINCE_LAST_SALE_C',
          'enabled' => true,
          'default' => false,
        ),
        5 => 
        array (
          'name' => 'epicor_deeplink_url',
          'label' => 'LBL_EPICOR_DEEPLINK_URL',
          'enabled' => true,
          'default' => false,
        ),
        6 => 
        array (
          'name' => 'bd_customer_group_code',
          'label' => 'LBL_BD_CUSTOMER_GROUP_CODE',
          'enabled' => true,
          'default' => false,
        ),
        7 => 
        array (
          'name' => 'last_opportunity_date',
          'label' => 'LBL_LAST_OPPORTUNITY_DATE',
          'enabled' => true,
          'default' => false,
        ),
        8 => 
        array (
          'name' => 'parent_name',
          'label' => 'LBL_MEMBER_OF',
          'enabled' => true,
          'default' => false,
        ),
        9 => 
        array (
          'name' => 'industry',
          'label' => 'LBL_INDUSTRY',
          'enabled' => true,
          'default' => false,
        ),
        10 => 
        array (
          'name' => 'phone_alternate',
          'label' => 'LBL_OTHER_PHONE',
          'enabled' => true,
          'default' => false,
        ),
        11 => 
        array (
          'name' => 'phone_fax',
          'label' => 'LBL_FAX',
          'enabled' => true,
          'default' => false,
        ),
        12 => 
        array (
          'name' => 'billing_address_street',
          'label' => 'LBL_BILLING_ADDRESS_STREET',
          'enabled' => true,
          'default' => false,
        ),
        13 => 
        array (
          'name' => 'billing_address_postalcode',
          'label' => 'LBL_BILLING_ADDRESS_POSTALCODE',
          'enabled' => true,
          'default' => false,
        ),
        14 => 
        array (
          'name' => 'tag',
          'label' => 'LBL_TAGS',
          'enabled' => true,
          'default' => false,
        ),
        15 => 
        array (
          'name' => 'team_name',
          'label' => 'LBL_LIST_TEAM',
          'enabled' => true,
          'default' => false,
        ),
        16 => 
        array (
          'name' => 'created_by_name',
          'label' => 'LBL_CREATED',
          'enabled' => true,
          'default' => false,
        ),
        17 => 
        array (
          'name' => 'modified_by_name',
          'label' => 'LBL_MODIFIED',
          'enabled' => true,
          'default' => false,
        ),
        18 => 
        array (
          'name' => 'erp_writeback_status',
          'label' => 'LBL_ERP_WRITEBACK_STATUS',
          'enabled' => true,
          'readonly' => true,
          'default' => false,
        ),
        19 => 
        array (
          'name' => 'name',
          'link' => true,
          'label' => 'LBL_LIST_ACCOUNT_NAME',
          'enabled' => true,
          'default' => true,
          'width' => 'xlarge',
        ),
        20 => 
        array (
          'name' => 'billing_address_city',
          'label' => 'LBL_LIST_CITY',
          'enabled' => true,
          'default' => true,
        ),
        21 => 
        array (
          'name' => 'billing_address_country',
          'label' => 'LBL_BILLING_ADDRESS_COUNTRY',
          'enabled' => true,
          'default' => true,
        ),
        22 => 
        array (
          'name' => 'phone_office',
          'label' => 'LBL_LIST_PHONE',
          'enabled' => true,
          'default' => true,
        ),
        23 => 
        array (
          'name' => 'assigned_user_name',
          'label' => 'LBL_LIST_ASSIGNED_USER',
          'id' => 'ASSIGNED_USER_ID',
          'enabled' => true,
          'default' => true,
        ),
        24 => 
        array (
          'name' => 'email',
          'label' => 'LBL_EMAIL_ADDRESS',
          'enabled' => true,
          'default' => true,
        ),
        25 => 
        array (
          'name' => 'last_interaction_date',
          'label' => 'LBL_LAST_INTERACTION',
          'type' => 'last_interaction',
          'fields' => 
          array (
            0 => 'last_interaction_parent_type',
            1 => 'last_interaction_parent_id',
            2 => 'last_interaction_parent_name',
          ),
          'readonly' => true,
          'enabled' => true,
          'default' => true,
        ),
        26 => 
        array (
          'name' => 'date_modified',
          'enabled' => true,
          'default' => true,
        ),
        27 => 
        array (
          'name' => 'date_entered',
          'type' => 'datetime',
          'label' => 'LBL_DATE_ENTERED',
          'enabled' => true,
          'default' => true,
          'readonly' => true,
        ),
        28 => 
        array (
          'name' => 'is_escalated',
          'label' => 'LBL_ESCALATED',
          'badge_label' => 'LBL_ESCALATED',
          'warning_level' => 'important',
          'type' => 'badge',
          'enabled' => true,
          'default' => false,
        ),
        29 => 
        array (
          'name' => 'erp_companies_accounts_name',
          'label' => 'LBL_ERP_COMPANIES_ACCOUNTS_FROM_ERP_COMPANIES_TITLE',
          'enabled' => true,
          'id' => 'ERP_COMPANIES_ACCOUNTSERP_COMPANIES_IDA',
          'link' => true,
          'sortable' => false,
          'default' => true,
          'readonly' => true,
        ),
      ),
    ),
  ),
);