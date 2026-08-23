<?php

$dictionary['bd01_ERP_Quote_Cost'] = array(
    'table' => 'bd01_erp_quote_cost',
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
        'qty_num' => array('name' => 'qty_num', 'vname' => 'LBL_QTY_NUM', 'type' => 'int', 'len' => 11, 'reportable' => true),
        'quantity' => array('name' => 'quantity', 'vname' => 'LBL_QUANTITY', 'type' => 'decimal', 'len' => 18, 'precision' => 4, 'default' => 0, 'reportable' => true),
        'material_cost' => array('name' => 'material_cost', 'vname' => 'LBL_MATERIAL_COST', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'labor_cost' => array('name' => 'labor_cost', 'vname' => 'LBL_LABOR_COST', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'material_burden' => array('name' => 'material_burden', 'vname' => 'LBL_MATERIAL_BURDEN', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'labor_burden' => array('name' => 'labor_burden', 'vname' => 'LBL_LABOR_BURDEN', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'subcontract_cost' => array('name' => 'subcontract_cost', 'vname' => 'LBL_SUBCONTRACT_COST', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'misc_cost' => array('name' => 'misc_cost', 'vname' => 'LBL_MISC_COST', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'profit' => array('name' => 'profit', 'vname' => 'LBL_PROFIT', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'gross_margin_pct' => array('name' => 'gross_margin_pct', 'vname' => 'LBL_GROSS_MARGIN_PCT', 'type' => 'decimal', 'len' => 8, 'precision' => 2, 'default' => 0, 'reportable' => true),
        'hours' => array('name' => 'hours', 'vname' => 'LBL_HOURS', 'type' => 'decimal', 'len' => 18, 'precision' => 4, 'default' => 0, 'reportable' => true),
        // Epicor's own rollup, as opposed to the component breakdown above.
        // The transformer (ErpQuoteCostHandler.map_to_sell) has always emitted
        // these four; the module never declared them, and Sugar drops unknown
        // field names on write WITHOUT erroring - so QuoteQty.TotalCost,
        // UnitPrice, TotalQuotedPrice and QuotedMarkup were being computed and
        // then silently thrown away on every sync. Verified live on quote 1190:
        // Epicor reported TotalCost 480/75/50/40 and Sugar stored none of it,
        // leaving a cost subpanel whose visible columns were all $0.00 while
        // the ERP had the real numbers all along.
        //
        // These matter more than the breakdown on a shop like Bench Dogs:
        // parts quoted without a full BOM book their whole cost as MiscCost,
        // so material_cost/labor_cost are legitimately 0 and TotalCost is the
        // only figure that means anything.
        'total_cost' => array('name' => 'total_cost', 'vname' => 'LBL_TOTAL_COST', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'unit_price' => array('name' => 'unit_price', 'vname' => 'LBL_UNIT_PRICE', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        'total_quoted_price' => array('name' => 'total_quoted_price', 'vname' => 'LBL_TOTAL_QUOTED_PRICE', 'type' => 'currency', 'dbType' => 'currency', 'len' => 26, 'precision' => 6, 'default' => 0.0, 'reportable' => true, 'convertToBase' => true, 'related_fields' => array('currency_id', 'base_rate')),
        // A PERCENTAGE on this BO, not a currency amount - Epicor returns 100
        // against a $750 line. Typed decimal deliberately so it can never be
        // mistaken for money (see QuoteQtyERP.QuotedMarkup).
        'quoted_markup_pct' => array('name' => 'quoted_markup_pct', 'vname' => 'LBL_QUOTED_MARKUP_PCT', 'type' => 'decimal', 'len' => 8, 'precision' => 2, 'default' => 0, 'reportable' => true),
        // False means "Epicor has not rolled this worksheet up yet", which is
        // NOT the same as "this costs nothing" - the distinction the transform
        // is careful to preserve and which was previously lost on write.
        'rolled_up' => array('name' => 'rolled_up', 'vname' => 'LBL_ROLLED_UP', 'type' => 'bool', 'default' => false, 'reportable' => true),
    ),
    'indices' => array(
        array(
            'name' => 'idx_bd01_erp_quote_cost_erp_sync_key',
            'type' => 'unique',
            'fields' => array('erp_sync_key'),
        ),
    ),
    'relationships' => array(),
    'optimistic_locking' => true,
    'unified_search' => true,
    'full_text_search' => true,
);

VardefManager::createVardef('bd01_ERP_Quote_Cost', 'bd01_ERP_Quote_Cost', array('basic', 'team_security', 'assignable', 'taggable', 'currency'));
