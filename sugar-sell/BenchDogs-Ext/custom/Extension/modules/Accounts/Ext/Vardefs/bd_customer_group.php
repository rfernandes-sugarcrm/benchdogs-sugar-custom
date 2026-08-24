<?php

/**
 * REQ-19: which customer group Epicor puts this account in.
 *
 * Two fields, not one, because they answer two different questions. The CODE
 * is Epicor's Customer.GroupCode verbatim ('DIST', 'COMM') - a stable key,
 * safe to group and filter a report by, and unchanged when somebody rewords
 * the description. The NAME is CustGrup.GroupDesc for that code
 * ('Distribution') - what a salesperson actually reads. Storing only the code
 * would make every report unreadable; storing only the description would make
 * every report break the day somebody renames a group.
 *
 * ERP-owned. The container extension writes both on the erp_customers sweep;
 * nothing in Sugar should be editing them, which is why they are declared
 * here rather than left to Studio.
 *
 * These declarations are a HARD PREREQUISITE for that extension, not a
 * convenience: core enforces the cached Sugar schema on the delivery path
 * (connector_core/pipeline/schema_enforcement.py), so a record carrying a
 * field name Sugar does not have is dropped BEFORE Sugar, DLQ'd as a
 * schema_violation, and counted failed - measured live on this tenant as 29
 * Accounts records with {'created': 0, 'updated': 0, 'failed': 29}. The
 * per-field exemption decorator is not reachable across the container
 * boundary, so the vardef is the only way through.
 */

$dictionary['Account']['fields']['bd_customer_group_code'] = array(
    'name' => 'bd_customer_group_code',
    'vname' => 'LBL_BD_CUSTOMER_GROUP_CODE',
    'type' => 'varchar',
    'len' => 10,
    'comment' => 'Epicor Customer.GroupCode verbatim - the stable key to group and filter on',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    'inline_edit' => false,
);

$dictionary['Account']['fields']['bd_customer_group'] = array(
    'name' => 'bd_customer_group',
    'vname' => 'LBL_BD_CUSTOMER_GROUP',
    'type' => 'varchar',
    'len' => 60,
    'comment' => 'Epicor CustGrup.GroupDesc for bd_customer_group_code - the human-readable group name',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    'inline_edit' => false,
);
