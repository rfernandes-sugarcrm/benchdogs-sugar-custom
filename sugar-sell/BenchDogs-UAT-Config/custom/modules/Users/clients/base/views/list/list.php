<?php
// created: 2026-10-23 04:39:43
$viewdefs['Users']['base']['view']['list'] = array (
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
          'label' => 'LBL_LIST_NAME',
          'type' => 'fullname',
          'fields' => 
          array (
            0 => 'first_name',
            1 => 'last_name',
          ),
          'enabled' => true,
          'default' => true,
          'sortable' => true,
          'link' => true,
        ),
        1 => 
        array (
          'name' => 'user_name',
          'sortable' => true,
          'default' => true,
          'enabled' => true,
        ),
        2 => 
        array (
          'name' => 'title',
          'enabled' => true,
          'default' => true,
          'sortable' => true,
        ),
        3 => 
        array (
          'name' => 'department',
          'enabled' => true,
          'default' => true,
          'sortable' => true,
        ),
        4 => 
        array (
          'name' => 'email',
          'label' => 'LBL_EMAIL',
          'enabled' => true,
          'default' => true,
          'sortable' => true,
        ),
        5 => 
        array (
          'name' => 'phone_work',
          'default' => true,
          'enabled' => true,
          'sortable' => true,
        ),
        6 => 
        array (
          'name' => 'status',
          'enabled' => true,
          'default' => true,
          'sortable' => true,
        ),
        7 => 
        array (
          'name' => 'license_type',
          'type' => 'enum',
          'readonly' => true,
          'enabled' => true,
          'default' => true,
          'sortable' => true,
        ),
        8 => 
        array (
          'name' => 'is_admin',
          'enabled' => true,
          'default' => true,
          'sortable' => true,
        ),
        9 => 
        array (
          'name' => 'erp_display_sync_key',
          'label' => 'LBL_ERP_DISPLAY_SYNC_KEY',
          'enabled' => true,
          'default' => true,
        ),
        10 => 
        array (
          'name' => 'erp_companies_users_name',
          'label' => 'LBL_ERP_COMPANIES_USERS_FROM_ERP_COMPANIES_TITLE',
          'enabled' => true,
          'id' => 'ERP_COMPANIES_USERSERP_COMPANIES_IDA',
          'link' => true,
          'sortable' => false,
          'default' => true,
          'readonly' => true,
        ),
        11 => 
        array (
          'name' => 'is_group',
          'label' => 'LBL_GROUP_USER',
          'enabled' => true,
          'default' => false,
        ),
      ),
    ),
  ),
);