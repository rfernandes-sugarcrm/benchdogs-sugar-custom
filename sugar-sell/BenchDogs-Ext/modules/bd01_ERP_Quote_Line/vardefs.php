<?php

$dictionary['bd01_ERP_Quote_Line'] = array(
    'table' => 'bd01_erp_quote_line',
    'audited' => true,
    'activity_enabled' => false,
    'duplicate_merge' => true,
    'fields' => array(
        'erp_sync_key' => array(
            'name'            => 'erp_sync_key',
            'is_sync_key'     => true,
            'vname'           => 'LBL_ERP_SYNC_KEY',
            'type'            => 'varchar',
            'comment'         => 'The id of the record from the ERP',
            'default_value'   => '',
            'max_size'        => 255,
            'required'        => false,
            'reportable'      => true,
            'audited'         => false,
            'importable'      => 'true',
            'duplicate_merge' => false,
        ),
        'quote_num' => array('name' => 'quote_num', 'vname' => 'LBL_QUOTE_NUM', 'type' => 'int', 'len' => 11, 'reportable' => true),
        'line_num' => array('name' => 'line_num', 'vname' => 'LBL_LINE_NUM', 'type' => 'int', 'len' => 11, 'reportable' => true),
        'part_num' => array('name' => 'part_num', 'vname' => 'LBL_PART_NUM', 'type' => 'varchar', 'len' => 100, 'reportable' => true),
        'selling_qty' => array('name' => 'selling_qty', 'vname' => 'LBL_SELLING_QTY', 'type' => 'decimal', 'len' => 18, 'precision' => 4, 'default' => 0, 'reportable' => true),
        'doc_unit_price' => array('name' => 'doc_unit_price', 'vname' => 'LBL_DOC_UNIT_PRICE', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'doc_ext_price' => array('name' => 'doc_ext_price', 'vname' => 'LBL_DOC_EXT_PRICE', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'governing' => array('name' => 'governing', 'vname' => 'LBL_GOVERNING', 'type' => 'bool', 'default' => 0, 'reportable' => true),
        'prototype' => array('name' => 'prototype', 'vname' => 'LBL_PROTOTYPE', 'type' => 'bool', 'default' => 0, 'reportable' => true),
    ),
    'indices' => array(
        array(
            'name' => 'idx_bd01_erp_quote_line_erp_sync_key',
            'type' => 'unique',
            'fields' => array('erp_sync_key'),
        ),
    ),
    'relationships' => array(),
    'optimistic_locking' => true,
    'unified_search' => true,
    'full_text_search' => true,
);

VardefManager::createVardef('bd01_ERP_Quote_Line', 'bd01_ERP_Quote_Line', array('basic', 'team_security', 'assignable', 'taggable', 'currency'));
