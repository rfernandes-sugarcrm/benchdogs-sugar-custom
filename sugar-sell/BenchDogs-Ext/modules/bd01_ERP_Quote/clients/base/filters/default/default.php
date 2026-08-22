<?php

$module_name = 'bd01_ERP_Quote';
$viewdefs[$module_name]['base']['filter']['default'] = [
    'default_filter' => 'all_records',
    'fields' => [
        'name' => [],
        'quote_num' => [],
        'current_stage' => [],
        'quote_amt' => [],
        'quote_total' => [],
        'quote_closed' => [],
        'reason_code' => [],
        'due_date' => [],
        'sugar_quote_id' => [],
        'engineered' => [],
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
