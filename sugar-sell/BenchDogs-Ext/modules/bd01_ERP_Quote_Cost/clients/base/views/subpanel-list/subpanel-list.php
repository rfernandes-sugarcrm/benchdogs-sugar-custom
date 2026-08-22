<?php
$module_name = 'bd01_ERP_Quote_Cost';
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
                    'label' => 'LBL_QTY_NUM',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'qty_num',
                ],
                [
                    'label' => 'LBL_QUANTITY',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'quantity',
                ],
                [
                    'label' => 'LBL_PROFIT',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'profit',
                ],
                [
                    'label' => 'LBL_GROSS_MARGIN_PCT',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'gross_margin_pct',
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
