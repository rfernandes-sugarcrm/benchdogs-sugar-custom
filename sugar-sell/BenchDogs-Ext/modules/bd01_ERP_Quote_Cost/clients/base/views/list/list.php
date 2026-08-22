<?php

$module_name = 'bd01_ERP_Quote_Cost';
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
                'name' => 'line_num',
                'label' => 'LBL_LINE_NUM',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'qty_num',
                'label' => 'LBL_QTY_NUM',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'quantity',
                'label' => 'LBL_QUANTITY',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'profit',
                'label' => 'LBL_PROFIT',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'gross_margin_pct',
                'label' => 'LBL_GROSS_MARGIN_PCT',
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
