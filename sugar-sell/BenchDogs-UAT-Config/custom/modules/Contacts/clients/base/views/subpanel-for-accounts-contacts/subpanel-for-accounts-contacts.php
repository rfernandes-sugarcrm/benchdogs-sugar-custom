<?php
// created: 2026-10-18 20:10:49
$viewdefs['Contacts']['base']['view']['subpanel-for-accounts-contacts'] = array (
  'type' => 'subpanel-list',
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
          'name' => 'primary_address_city',
          'label' => 'LBL_LIST_CITY',
          'enabled' => true,
          'default' => true,
        ),
        2 => 
        array (
          'name' => 'primary_address_state',
          'label' => 'LBL_LIST_STATE',
          'enabled' => true,
          'default' => true,
        ),
        3 => 
        array (
          'name' => 'email',
          'label' => 'LBL_LIST_EMAIL',
          'enabled' => true,
          'default' => true,
        ),
        4 => 
        array (
          'name' => 'phone_work',
          'label' => 'LBL_LIST_PHONE',
          'enabled' => true,
          'default' => true,
        ),
        5 => 
        array (
          'name' => 'epicor_function_c',
          'label' => 'LBL_EPICOR_FUNCTION_C',
          'enabled' => true,
          'default' => true,
        ),
        6 => 
        array (
          'name' => 'role_c',
          'label' => 'LBL_ROLE_C',
          'enabled' => true,
          'default' => true,
        ),
        7 => 
        array (
          'name' => 'primary_billing_c',
          'label' => 'LBL_PRIMARY_BILLING_C',
          'enabled' => true,
          'default' => true,
        ),
        8 => 
        array (
          'name' => 'primary_purchasing_c',
          'label' => 'LBL_PRIMARY_PURCHASING_C',
          'enabled' => true,
          'default' => true,
        ),
        9 => 
        array (
          'name' => 'primary_shipping_c',
          'label' => 'LBL_PRIMARY_SHIPPING_C',
          'enabled' => true,
          'default' => true,
        ),
      ),
    ),
  ),
);