<?php

$module_name = 'bd01_ERP_Quote_Cost';
$viewdefs[$module_name]['base']['filter']['default'] = [
    'default_filter' => 'all_records',
    'fields' => [
        'name' => [],
        'quote_num' => [],
        'line_num' => [],
        'qty_num' => [],
        'quantity' => [],
        'material_cost' => [],
        'labor_cost' => [],
        'material_burden' => [],
        'labor_burden' => [],
        'subcontract_cost' => [],
        'misc_cost' => [],
        'profit' => [],
        'gross_margin_pct' => [],
        'hours' => [],
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
