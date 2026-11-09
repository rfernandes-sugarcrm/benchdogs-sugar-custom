<?php

/** The key the connector creates and finds a Bench Opportunity by: one per Epicor order with no Sugar quote, one per Epicor-born quote. */

$dictionary['Opportunity']['fields']['erp_sync_key'] = array(
    'name' => 'erp_sync_key',
    'is_sync_key' => true,
    // A copied Opportunity is a new deal, never a second holder of this key (the index below is unique).
    'duplicate_on_record_copy' => 'no',
    'vname' => 'LBL_ERP_SYNC_KEY',
    'type' => 'varchar',
    'comment' => 'The connector key of the Epicor order or quote this Opportunity was created for',
    'max_size' => 255,
    'required' => false,
    'reportable' => true,
    'audited' => false,
    'importable' => 'true',
    'duplicate_merge' => false,
);

$dictionary['Opportunity']['indices'][] = array(
    'name' => 'idx_opportunities_erp_sync_key',
    'type' => 'unique',
    'fields' => array('erp_sync_key'),
);

// The orders under this Opportunity; the order holds the column (bd_opportunity_id).
$dictionary['Opportunity']['fields']['bd_erp_orders'] = array(
    'name' => 'bd_erp_orders',
    'type' => 'link',
    'relationship' => 'bd_opportunity_erp_orders',
    'module' => 'ERP_Orders',
    'bean_name' => 'ERP_Order',
    'source' => 'non-db',
    'vname' => 'LBL_BD_ERP_ORDERS',
);
