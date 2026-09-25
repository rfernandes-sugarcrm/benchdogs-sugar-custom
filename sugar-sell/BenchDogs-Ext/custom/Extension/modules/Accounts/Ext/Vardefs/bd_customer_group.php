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
 *
 * PLACEMENT (G380 (f), 🔒 1724b): each field carries ERP-Epicor's `erp_layout`
 * marker, and ErpLayoutExtraFields::sync('Accounts') (ERP-Core,
 * custom/include/) places it and retires it after uninstall - this package ships
 * no layout code (BdAccountsLayoutExtensions is gone). Defined name first, code
 * second, because sync() places marked fields in vardef order.
 *
 * 0.9.42-rc72 (G507, owner: "can we move this fields into the overview?"): the
 * panel is panel_overview, after Industry, NOT panel_body. rc70/rc71 named
 * panel_body on the belief that "rc69 put them there on every live tenant";
 * MEASURED on Ophir (SugarEnt 26.1.0, served Accounts record view, 2026-09-24)
 * that is false: that view has NO panel_body. Its first tab is panel_overview
 * ("Overview"), so rc69's writer fell back to the first panel with fields -
 * panel_header - and the two values rendered unlabelled beside the account name,
 * where sellers read them as buttons. On that view sync() would resolve
 * panel_body to ERP-Epicor's ERP tab (absent -> the module default), not the
 * Overview. panel_overview is the owner's "Business Card / overview" tab on the
 * Bench tenant. A tenant whose record view has panel_body instead (stock
 * SugarEnt 26.1.0 GA) keeps whatever rc69 placed there - sync() never moves a
 * placed field - and only a FIRST-EVER placement on such a view falls back to
 * the ERP tab (the marker names one panel; ERP-Core's fallback order is its own).
 *
 * sync() never moves a field that is already on the view, so this marker alone
 * does NOT take them out of the header on an upgraded tenant. The disposable
 * one-off sugar-sell/ONEOFF-MoveBdCustomerGroup does that, once, without adding
 * layout code to this package (🔒 1724b).
 *
 * READ-ONLY (G507): 'readonly' => true. sync() writes only name + label into the
 * view, so the flag lives here: Sidecar's isFieldAlwaysReadOnly() falls back to
 * the vardef's readonly when the viewdef sets none (26.1.0
 * include/javascript/sugar7/utils.js), and a readonly field renders in detail
 * mode even in the record view's Edit. It is CLIENT-side only for Accounts:
 * SugarBeanApiHelper::populateFromApi() checks field ACLs, not `readonly`, and
 * ERP-Core's SugarACLErpOwnedFields (which does refuse readonly fields) is
 * registered on its ERP_* modules only - so the Bench connector's REST writes of
 * both fields are unaffected.
 */

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
    'readonly' => true,
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'panel_overview',
        'after' => 'industry',
    ),
);

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
    'readonly' => true,
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'panel_overview',
        'after' => 'bd_customer_group',
    ),
);
