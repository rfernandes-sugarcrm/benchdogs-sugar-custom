<?php
$module_name = 'bd01_ERP_Quote';
$viewdefs[$module_name]['base']['view']['subpanel-list'] = [
    'panels' => [
        [
            'name' => 'panel_header',
            'label' => 'LBL_PANEL_1',
            'fields' => [
                [
                    'label' => 'LBL_NAME',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'name',
                    'link' => true,
                ],
                [
                    'label' => 'LBL_QUOTE_NUM',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'quote_num',
                ],
                [
                    'label' => 'LBL_CURRENT_STAGE',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'current_stage',
                ],
                [
                    'label' => 'LBL_QUOTE_TOTAL',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'quote_total',
                ],
                [
                    'label' => 'LBL_QUOTE_CLOSED',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'quote_closed',
                ],
                [
                    'label' => 'LBL_DATE_MODIFIED',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'date_modified',
                ],
            ],
        ],
    ],
    'orderBy' => [
        'field' => 'date_modified',
        'direction' => 'desc',
    ],
];
