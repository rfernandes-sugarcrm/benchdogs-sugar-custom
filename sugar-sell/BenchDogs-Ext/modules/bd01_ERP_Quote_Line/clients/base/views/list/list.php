<?php

$module_name = 'bd01_ERP_Quote_Line';
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
                'name' => 'part_num',
                'label' => 'LBL_PART_NUM',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'selling_qty',
                'label' => 'LBL_SELLING_QTY',
                'default' => true,
                'enabled' => true,
            ],
            [
                'name' => 'doc_ext_price',
                'label' => 'LBL_DOC_EXT_PRICE',
                'default' => true,
                'enabled' => true,
            ],
            [
                // Editable in place (REQ-5/REQ-6): marking a line governing
                // fires BdGoverningLineHook, which un-marks its siblings and
                // refreshes the Opportunity amount rollup.
                'name' => 'governing',
                'label' => 'LBL_GOVERNING',
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
