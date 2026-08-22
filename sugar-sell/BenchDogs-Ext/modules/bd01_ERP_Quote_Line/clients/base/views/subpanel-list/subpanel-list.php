<?php
$module_name = 'bd01_ERP_Quote_Line';
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
                    'label' => 'LBL_LINE_NUM',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'line_num',
                ],
                [
                    'label' => 'LBL_PART_NUM',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'part_num',
                ],
                [
                    'label' => 'LBL_SELLING_QTY',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'selling_qty',
                ],
                [
                    'label' => 'LBL_DOC_EXT_PRICE',
                    'enabled' => true,
                    'default' => true,
                    'name' => 'doc_ext_price',
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
