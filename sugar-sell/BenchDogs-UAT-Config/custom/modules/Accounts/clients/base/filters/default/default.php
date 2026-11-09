<?php
// created: 2026-10-18 19:55:04
$viewdefs['Accounts']['base']['filter']['default'] = array (
  'default_filter' => 'all_records',
  'fields' => 
  array (
    'name' => 
    array (
    ),
    'parent_name' => 
    array (
    ),
    'account_type' => 
    array (
    ),
    'industry' => 
    array (
    ),
    'last_interaction_date' => 
    array (
    ),
    'days_since_last_interaction_c' => 
    array (
    ),
    'last_opportunity_date' => 
    array (
    ),
    'days_since_last_sale_c' => 
    array (
    ),
    'phone_office' => 
    array (
    ),
    'phone_alternate' => 
    array (
    ),
    'email' => 
    array (
    ),
    '$distance' => 
    array (
      'name' => '$distance',
      'vname' => 'LBL_MAPS_DISTANCE',
      'type' => 'maps-distance',
      'source' => 'non-db',
      'merge_filter' => 'enabled',
      'licenseFilter' => 
      array (
        0 => 'MAPS',
      ),
    ),
    'address_street' => 
    array (
      'dbFields' => 
      array (
        0 => 'billing_address_street',
        1 => 'shipping_address_street',
      ),
      'vname' => 'LBL_STREET',
      'type' => 'text',
    ),
    'address_city' => 
    array (
      'dbFields' => 
      array (
        0 => 'billing_address_city',
        1 => 'shipping_address_city',
      ),
      'vname' => 'LBL_CITY',
      'type' => 'text',
    ),
    'address_state' => 
    array (
      'dbFields' => 
      array (
        0 => 'billing_address_state',
        1 => 'shipping_address_state',
      ),
      'vname' => 'LBL_STATE',
      'type' => 'text',
    ),
    'address_postalcode' => 
    array (
      'dbFields' => 
      array (
        0 => 'billing_address_postalcode',
        1 => 'shipping_address_postalcode',
      ),
      'vname' => 'LBL_POSTAL_CODE',
      'type' => 'text',
    ),
    'address_country' => 
    array (
      'dbFields' => 
      array (
        0 => 'billing_address_country',
        1 => 'shipping_address_country',
      ),
      'vname' => 'LBL_COUNTRY',
      'type' => 'text',
    ),
    'bd_customer_group' => 
    array (
    ),
    'bd_customer_group_code' => 
    array (
    ),
    'health_c' => 
    array (
    ),
    'tag' => 
    array (
    ),
    '$record_owner_name' => 
    array (
      'name' => '$record_owner_name',
      'vname' => 'LBL_RECORD_OWNER',
      'type' => 'relate',
      'id_name' => '$record_owner',
      'rname' => 'full_name',
      'module' => 'Users',
      'source' => 'non-db',
    ),
    '$owner' => 
    array (
      'predefined_filter' => true,
      'vname' => 'LBL_CURRENT_USER_FILTER',
    ),
    '$favorite' => 
    array (
      'predefined_filter' => true,
      'vname' => 'LBL_FAVORITES_FILTER',
    ),
    'assigned_user_name' => 
    array (
    ),
    'team_name' => 
    array (
    ),
    'date_entered' => 
    array (
    ),
    'created_by_name' => 
    array (
    ),
    'date_modified' => 
    array (
    ),
    'modified_by_name' => 
    array (
    ),
  ),
);