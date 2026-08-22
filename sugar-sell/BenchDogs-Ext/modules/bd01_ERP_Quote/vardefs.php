<?php

$dictionary['bd01_ERP_Quote'] = array(
    'table' => 'bd01_erp_quote',
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
        'current_stage' => array('name' => 'current_stage', 'vname' => 'LBL_CURRENT_STAGE', 'type' => 'varchar', 'len' => 100, 'reportable' => true),
        'quote_amt' => array('name' => 'quote_amt', 'vname' => 'LBL_QUOTE_AMT', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'quote_total' => array('name' => 'quote_total', 'vname' => 'LBL_QUOTE_TOTAL', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'quote_closed' => array('name' => 'quote_closed', 'vname' => 'LBL_QUOTE_CLOSED', 'type' => 'bool', 'default' => 0, 'reportable' => true),
        'reason_code' => array('name' => 'reason_code', 'vname' => 'LBL_REASON_CODE', 'type' => 'varchar', 'len' => 100, 'reportable' => true),
        'due_date' => array('name' => 'due_date', 'vname' => 'LBL_DUE_DATE', 'type' => 'date', 'reportable' => true),
        'sugar_quote_id' => array('name' => 'sugar_quote_id', 'vname' => 'LBL_SUGAR_QUOTE_ID', 'type' => 'varchar', 'len' => 36, 'comment' => 'id of the Sugar Quote this ERP quote reflects into', 'reportable' => true),
        'engineered' => array('name' => 'engineered', 'vname' => 'LBL_ENGINEERED', 'type' => 'bool', 'default' => 0, 'reportable' => true),
    ),
    'indices' => array(
        array(
            'name' => 'idx_bd01_erp_quote_erp_sync_key',
            'type' => 'unique',
            'fields' => array('erp_sync_key'),
        ),
        array(
            'name' => 'idx_bd01_erp_quote_sugar_qid',
            'type' => 'index',
            'fields' => array('sugar_quote_id'),
        ),
    ),
    'relationships' => array(),
    'optimistic_locking' => true,
    'unified_search' => true,
    'full_text_search' => true,
);

VardefManager::createVardef('bd01_ERP_Quote', 'bd01_ERP_Quote', array('basic', 'team_security', 'assignable', 'taggable', 'currency'));
