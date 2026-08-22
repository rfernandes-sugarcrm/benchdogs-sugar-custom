<?php

$module_name = 'bd01_ERP_Quote';
$viewdefs[$module_name]['base']['view']['list'] = [
    'panels' => [
        [
            'label' => 'LBL_PANEL_1',
            'fields' => [
            [
                'name' => 'name',
                'label' => 'LBL_NAME',
                'default' => true,
                'enabled' => true,
                'link' => true,
            ],
            [
                'name' => 'quote_num',
                'label' => 'LBL_QUOTE_NUM',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'current_stage',
                'label' => 'LBL_CURRENT_STAGE',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'quote_total',
                'label' => 'LBL_QUOTE_TOTAL',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'quote_closed',
                'label' => 'LBL_QUOTE_CLOSED',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'due_date',
                'label' => 'LBL_DUE_DATE',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'date_modified',
                'label' => 'LBL_DATE_MODIFIED',
                'default' => true,
                'enabled' => true,
            ],
            ],
        ],
    ],
];
