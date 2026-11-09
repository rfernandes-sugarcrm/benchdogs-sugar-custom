<?php
// created: 2026-10-23 04:39:43
$viewdefs['Contacts']['base']['view']['selection-list'] = array (
  'panels' => 
  array (
    0 => 
    array (
      'label' => 'LBL_PANEL_1',
      'fields' => 
      array (
        0 => 
        array (
          'name' => 'name',
          'type' => 'fullname',
          'fields' => 
          array (
            0 => 'salutation',
            1 => 'first_name',
            2 => 'last_name',
          ),
          'link' => true,
          'label' => 'LBL_LIST_NAME',
          'enabled' => true,
          'default' => true,
        ),
        1 => 
        array (
          'name' => 'title',
          'enabled' => true,
          'default' => true,
        ),
        2 => 
        array (
          'name' => 'account_name',
          'enabled' => true,
          'default' => true,
        ),
        3 => 
        array (
          'name' => 'epicor_function_c',
          'label' => 'LBL_EPICOR_FUNCTION_C',
          'enabled' => true,
          'default' => true,
        ),
        4 => 
        array (
          'name' => 'role_c',
          'label' => 'LBL_ROLE_C',
          'enabled' => true,
          'default' => true,
        ),
        5 => 
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
        6 => 
        array (
          'name' => 'phone_work',
          'enabled' => true,
          'default' => true,
        ),
        7 => 
        array (
          'name' => 'email',
          'enabled' => true,
          'default' => true,
        ),
        8 => 
        array (
          'name' => 'assigned_user_name',
          'label' => 'LBL_LIST_ASSIGNED_USER',
          'id' => 'ASSIGNED_USER_ID',
          'enabled' => true,
          'default' => true,
        ),
        9 => 
        array (
          'name' => 'date_modified',
          'enabled' => true,
          'default' => true,
        ),
        10 => 
        array (
          'name' => 'department',
          'label' => 'LBL_DEPARTMENT',
          'enabled' => true,
          'default' => false,
        ),
        11 => 
        array (
          'name' => 'phone_mobile',
          'label' => 'LBL_MOBILE_PHONE',
          'enabled' => true,
          'default' => false,
        ),
        12 => 
        array (
          'name' => 'phone_other',
          'label' => 'LBL_OTHER_PHONE',
          'enabled' => true,
          'default' => false,
        ),
        13 => 
        array (
          'name' => 'phone_fax',
          'label' => 'LBL_FAX_PHONE',
          'enabled' => true,
          'default' => false,
        ),
        14 => 
        array (
          'name' => 'primary_address_street',
          'label' => 'LBL_PRIMARY_ADDRESS_STREET',
          'enabled' => true,
          'default' => false,
        ),
        15 => 
        array (
          'name' => 'primary_address_city',
          'label' => 'LBL_PRIMARY_ADDRESS_CITY',
          'enabled' => true,
          'default' => false,
        ),
        16 => 
        array (
          'name' => 'primary_address_state',
          'label' => 'LBL_PRIMARY_ADDRESS_STATE',
          'enabled' => true,
          'default' => false,
        ),
        17 => 
        array (
          'name' => 'primary_address_postalcode',
          'label' => 'LBL_PRIMARY_ADDRESS_POSTALCODE',
          'enabled' => true,
          'default' => false,
        ),
        18 => 
        array (
          'name' => 'primary_address_country',
          'label' => 'LBL_PRIMARY_ADDRESS_COUNTRY',
          'enabled' => true,
          'default' => false,
        ),
        19 => 
        array (
          'name' => 'alt_address_street',
          'label' => 'LBL_ALT_ADDRESS_STREET',
          'enabled' => true,
          'default' => false,
        ),
        20 => 
        array (
          'name' => 'alt_address_city',
          'label' => 'LBL_ALT_ADDRESS_CITY',
          'enabled' => true,
          'default' => false,
        ),
        21 => 
        array (
          'name' => 'alt_address_state',
          'label' => 'LBL_ALT_ADDRESS_STATE',
          'enabled' => true,
          'default' => false,
        ),
        22 => 
        array (
          'name' => 'alt_address_postalcode',
          'label' => 'LBL_ALT_ADDRESS_POSTALCODE',
          'enabled' => true,
          'default' => false,
        ),
        23 => 
        array (
          'name' => 'alt_address_country',
          'label' => 'LBL_ALT_ADDRESS_COUNTRY',
          'enabled' => true,
          'default' => false,
        ),
        24 => 
        array (
          'name' => 'primary_billing_c',
          'label' => 'LBL_PRIMARY_BILLING_C',
          'enabled' => true,
          'default' => false,
        ),
        25 => 
        array (
          'name' => 'primary_purchasing_c',
          'label' => 'LBL_PRIMARY_PURCHASING_C',
          'enabled' => true,
          'default' => false,
        ),
        26 => 
        array (
          'name' => 'primary_shipping_c',
          'label' => 'LBL_PRIMARY_SHIPPING_C',
          'enabled' => true,
          'default' => false,
        ),
        27 => 
        array (
          'name' => 'tag',
          'label' => 'LBL_TAGS',
          'enabled' => true,
          'default' => false,
        ),
        28 => 
        array (
          'name' => 'team_name',
          'label' => 'LBL_LIST_TEAM',
          'enabled' => true,
          'default' => false,
        ),
        29 => 
        array (
          'name' => 'date_entered',
          'enabled' => true,
          'default' => false,
          'readonly' => true,
        ),
        30 => 
        array (
          'name' => 'created_by_name',
          'label' => 'LBL_CREATED',
          'enabled' => true,
          'default' => false,
        ),
        31 => 
        array (
          'name' => 'modified_by_name',
          'label' => 'LBL_MODIFIED',
          'enabled' => true,
          'default' => false,
        ),
        32 => 
        array (
          'name' => 'first_name',
          'label' => 'LBL_FIRST_NAME',
          'enabled' => true,
          'default' => false,
        ),
        33 => 
        array (
          'name' => 'last_name',
          'label' => 'LBL_LAST_NAME',
          'enabled' => true,
          'default' => false,
        ),
        34 => 
        array (
          'name' => 'erp_display_sync_key',
          'label' => 'LBL_ERP_DISPLAY_SYNC_KEY',
          'type' => 'erp-contact-key',
          'related_fields' => 
          array (
            0 => 'erp_sync_key',
          ),
          'enabled' => true,
          'readonly' => true,
          'sortable' => false,
          'default' => false,
        ),
      ),
    ),
  ),
);