<?php

$module_name = 'bd01_ERP_Quote_Line';
$viewdefs[$module_name]['base']['filter']['default'] = [
    'default_filter' => 'all_records',
    'fields' => [
        'name' => [],
        'quote_num' => [],
        'line_num' => [],
        'part_num' => [],
        'selling_qty' => [],
        'doc_unit_price' => [],
        'doc_ext_price' => [],
        'governing' => [],
        'prototype' => [],
        'erp_sync_key' => [],
        'assigned_user_name' => [],
        '$owner' => [
            'predefined_filter' => true,
            'vname' => 'LBL_CURRENT_USER_FILTER',
        ],
        '$favorite' => [
            'predefined_filter' => true,
            'vname' => 'LBL_FAVORITES_FILTER',
        ],
        'team_name' => [],
        'date_entered' => [],
        'created_by_name' => [],
        'date_modified' => [],
        'modified_by_name' => [],
        'tag' => [
            'enabled' => true,
            'default' => false,
        ],
    ],
];
