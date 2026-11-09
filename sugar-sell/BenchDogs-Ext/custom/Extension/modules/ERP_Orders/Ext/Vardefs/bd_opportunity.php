<?php

/** The Opportunity an Epicor order with no Sugar quote sits under; only the connector sets it. */

$dictionary['ERP_Order']['fields']['bd_opportunity_id'] = array(
    'name' => 'bd_opportunity_id',
    'vname' => 'LBL_BD_OPPORTUNITY_ID',
    'type' => 'relate',
    // A real column on erp_orders, so the link lives on the order itself (no join table).
    'dbType' => 'id',
    'rname' => 'id',
    'module' => 'Opportunities',
    'id_name' => 'bd_opportunity_id',
    'comment' => 'The Opportunity the connector created for this order',
    'reportable' => true,
    'audited' => false,
    'importable' => false,
    'massupdate' => false,
    'duplicate_merge' => 'disabled',
    'readonly' => true,
);

$dictionary['ERP_Order']['fields']['bd_opportunity_name'] = array(
    'name' => 'bd_opportunity_name',
    'vname' => 'LBL_BD_OPPORTUNITY_NAME',
    'type' => 'relate',
    'rname' => 'name',
    'id_name' => 'bd_opportunity_id',
    'link' => 'bd_opportunity',
    'table' => 'opportunities',
    'join_name' => 'bd_opportunity',
    'module' => 'Opportunities',
    'dbType' => 'varchar',
    'len' => 255,
    'source' => 'non-db',
    'reportable' => true,
    'importable' => false,
    'massupdate' => false,
    'readonly' => true,
);

$dictionary['ERP_Order']['fields']['bd_opportunity'] = array(
    'name' => 'bd_opportunity',
    'type' => 'link',
    'relationship' => 'bd_opportunity_erp_orders',
    'link_type' => 'one',
    'side' => 'right',
    'module' => 'Opportunities',
    'bean_name' => 'Opportunity',
    'source' => 'non-db',
    'vname' => 'LBL_BD_OPPORTUNITY',
);

$dictionary['ERP_Order']['indices'][] = array(
    'name' => 'idx_erp_orders_bd_opportunity_id',
    'type' => 'index',
    'fields' => array('bd_opportunity_id'),
);

$dictionary['ERP_Order']['relationships']['bd_opportunity_erp_orders'] = array(
    'lhs_module' => 'Opportunities',
    'lhs_table' => 'opportunities',
    'lhs_key' => 'id',
    'rhs_module' => 'ERP_Orders',
    'rhs_table' => 'erp_orders',
    'rhs_key' => 'bd_opportunity_id',
    'relationship_type' => 'one-to-many',
);
