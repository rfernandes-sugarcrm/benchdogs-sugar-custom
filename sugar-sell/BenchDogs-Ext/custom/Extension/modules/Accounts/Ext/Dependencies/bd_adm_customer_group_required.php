<?php

/**
 * 🔒 2085b (owner, 2026-09-29, YES): on a Bench account, Cust. Group (the
 * Group Code picker, bd_customer_group_code) is REQUIRED ON SAVE when the
 * account's type is Customer and it is NOT yet in the ERP. ADM refuses a new
 * customer without a group (G804: HTTP 400 "Group is required."); the Bench
 * connector extension refuses the create naming the field, and that refusal
 * stays the backstop. Leads, prospects and suspects kept in Sugar are never
 * blocked, and an account the ERP holds is never blocked (its group is
 * Epicor's, written by the extension).
 *
 * The formula, term by term:
 *   - account_type is Customer;
 *   - the account has no ERP key (neither erp_display_sync_key nor
 *     erp_sync_key - the same test as the field's readonly_formula);
 *   - the account's ERP company is ADM: related($erp_companies_accounts,
 *     "erp_sync_key"), the company the account's own ERP Company relate names
 *     (ERP-Core requires that relate on a Customer). Only ADM's customers need
 *     a group, and only the ADM connection publishes the groups the picker
 *     offers (BdCustomerGroups). MEASURED 2026-09-29: benchdogs-sandbox holds
 *     23 groups and one company, ADM ("BENCH DOGS"); Ophir (sugar.local.dev,
 *     this package installed, company EPIC06) and stock hold 0 - without this
 *     term every new Customer account on Ophir would be unsavable, with an
 *     empty required picker.
 *     OWNER RULING 🔒 2086b ("no its benchdogs specific"): this is a Bench
 *     Dogs rule, and the Bench package may name its own company, so the
 *     literal "ADM" here is ruled, not a deviation from 🔒 1724b. It is the
 *     connector extension's ADM company too (SUGARAI_BD_ADM_COMPANIES).
 *
 * 🛑 A VIEW DEPENDENCY, NEVER `required` / `required_formula` ON THE VARDEF.
 *   - A served `required: true` would make the connector's schema check
 *     (SugarSellClient.describe_module + connector_base check_payload) refuse
 *     every Account write that lacks the field - the erp_customers sweep and
 *     the seed would dead-letter.
 *   - A served `required_formula` equal to `equal($account_type,"Customer")`
 *     would be read by ERP-Epicor's G496 order check (QuotesErpActionsApi
 *     CUSTOMER_REQUIRED_FORMULA) and the G805 price-step create, which carry
 *     no ADM gate: orders on Ophir/EPIC06 would be refused for a group those
 *     tenants cannot pick.
 * 'hooks' => array('edit') reaches the record and create views
 * (DependencyManager::getDependenciesForView; RecordView and CreateView are
 * editable views) and never a server save (getModuleDependenciesForAction
 * 'save' takes only 'all' or 'save' hooks). So the G805 prompt path, which
 * updates the account over REST without a view, is NOT covered by this rule:
 * the connector extension's refusal names the field there.
 *
 * Pinned by scripts/tests/test_g809_connector_schema_safe.py.
 */
$dependencies['Accounts']['bd_adm_customer_group_required'] = array(
    'hooks' => array('edit'),
    'trigger' => 'true',
    'triggerFields' => array(
        'account_type',
        'erp_display_sync_key',
        'erp_sync_key',
        'erp_companies_accounts_name',
        'erp_companies_accountserp_companies_ida',
    ),
    'onload' => true,
    'actions' => array(
        array(
            'name' => 'SetRequired',
            'params' => array(
                'target' => 'bd_customer_group_code',
                'label' => 'bd_customer_group_code_label',
                'value' => 'and(equal($account_type,"Customer"),equal($erp_display_sync_key,""),'
                    . 'equal($erp_sync_key,""),equal(related($erp_companies_accounts,"erp_sync_key"),"ADM"))',
            ),
        ),
    ),
);
