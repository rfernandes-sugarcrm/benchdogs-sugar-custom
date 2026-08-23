<?php

/**
 * Bench Dogs REQ-6: the deliverable identity of a connector-maintained
 * revenue line item, format "<bd01_ERP_Quote id>:<role>" where role is
 * 'prototype' or 'production'.
 *
 * BdQuoteReflectionHook upserts RLIs by this key with REPLACE semantics:
 * five or six Kinetic revisions re-value the same RLI in place instead of
 * adding rows (adding would multiply the deal value - the Bench Dogs
 * working doc calls this out explicitly). The key is role-based rather
 * than quote-line-row-based on purpose: a revision that replaces the
 * underlying bd01_ERP_Quote_Line row must still land on the same RLI.
 *
 * Empty on human-created RLIs; the hook never touches those.
 */
$dictionary['RevenueLineItem']['fields']['bd_deliverable_key'] = array(
    'name' => 'bd_deliverable_key',
    'vname' => 'LBL_BD_DELIVERABLE_KEY',
    'type' => 'varchar',
    'len' => 80,
    'reportable' => true,
    'importable' => false,
    'studio' => 'visible',
);

$dictionary['RevenueLineItem']['indices'][] = array(
    'name' => 'idx_rli_bd_deliverable_key',
    'type' => 'index',
    'fields' => array('bd_deliverable_key'),
);
