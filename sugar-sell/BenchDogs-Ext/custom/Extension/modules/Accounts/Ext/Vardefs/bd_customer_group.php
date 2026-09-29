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
 * ERP-owned ONCE THE ACCOUNT IS IN THE ERP. The container extension writes both
 * on the erp_customers sweep; nothing in Sugar edits them on such an account,
 * which is why they are declared here rather than left to Studio.
 *
 * G804 (🔒 2081b): BEFORE the account is in the ERP, the seller picks the
 * group. Create Customer in Epicor on Bench needs one: ADM answered HTTP 400
 * "Group is required." (benchdogs-sandbox, 2026-09-29), and the connector
 * extension now sends Customer.GroupCode from bd_customer_group_code or refuses
 * naming the account's Cust. Group. So on an account with no ERP key:
 *   - bd_customer_group_code is a PICKER over ADM's own customer groups
 *     (ERP_LookupValues type BdCustomerGroups, which the Bench connector
 *     extension publishes: key = GroupCode, label "CODE - GroupDesc"), built
 *     the way the Bench quote pickers are: a vardef `enum` with an option
 *     function (BdAdmRules::lookupOptions), `defaultToBlank`. It stores the
 *     CODE, the value the extension reads. The vardef was a varchar; the
 *     column is the same varchar(10), and a REST write of any string is still
 *     accepted (SugarFieldEnum has no apiValidate; core types an enum as a
 *     string). No view `type` marker: the stock enum is all it needs, and a
 *     marker type would rewrite every tenant's Accounts view (the placed
 *     entries carry no type, measured on benchdogs-sandbox 2026-09-29, so the
 *     vardef's enum is what renders);
 *   - bd_customer_group (the name) stays read-only and follows the pick on
 *     save (BdAdmRules::accountBeforeSave: ADM's GroupDesc for the code, else
 *     the code itself, the extension's own rule for the field).
 * On an account the ERP holds, both stay READ-ONLY (readonly_formula below):
 * Epicor owns the group, and the extension fills it Epicor -> Sugar.
 * Not required: a Prospect may be saved without a group; the extension's
 * refusal at create time names the field. No new field (🔒 1810b).
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
 * READ-ONLY (G507): 'readonly' => true. G804 adds a 'readonly_formula' to the
 * CODE field: read-only once the account has an ERP key, editable before -
 * ERP-Core's own shape for account_type (readonly + readonly_formula; sidecar's
 * isFieldAlwaysReadOnly() treats a vardef readonly WITH a readonly_formula as
 * "the formula decides", and the server's ReadOnly action is a no-op). The NAME
 * field keeps the plain flag. sync() writes only name + label into the
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
    // G804: a picker over ADM's customer groups (see the docblock); the column
    // is unchanged (an enum is stored as varchar(len)).
    'type' => 'enum',
    'len' => 10,
    'function' => array(
        'name' => 'bd_adm_customer_group_options',
        'include' => 'custom/modules/Quotes/BdAdmLookupOptions.php',
    ),
    // Never pre-picked by the browser (G574's rule for every Bench picker).
    'defaultToBlank' => true,
    'comment' => 'Epicor Customer.GroupCode verbatim - the stable key to group and filter on',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    'inline_edit' => false,
    'readonly' => true,
    // G804: read-only once the account is in the ERP (either key set).
    'readonly_formula' => 'not(and(equal($erp_display_sync_key,""),equal($erp_sync_key,"")))',
    // G817 (owner, 2026-09-29: "why did it offer to create an account with no
    // group then in the message????"): the quote's "Create <account> in the
    // ERP now?" prompt (ERP-Core, G805) asks ERP-Core's read-only
    // GET Accounts/{id}/erp-customer-create-requirements first, which
    // evaluates this key: on an ADM account (🔒2086b) with no group, the
    // seller is told "To create <account> in the ERP, set: Cust. Group." and
    // is offered no Create. Read ONLY by that prompt - never served as
    // `required` / `required_formula` (the connector's schema check would
    // dead-letter Account writes, G809) and never by ERP-Epicor's G496 order
    // check. The prompt only runs for an account with no ERP key, and types it
    // Customer on Create, so the ADM clause is the whole rule here. On an
    // older ERP-Epicor (no route) the key is inert and the extension's refusal
    // stays the backstop.
    'erp_customer_create_required_formula' => 'equal(related($erp_companies_accounts,"erp_sync_key"),"ADM")',
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'panel_overview',
        'after' => 'bd_customer_group',
    ),
);
