<?php

/**
 * G809 (owner, benchdogs-sandbox quote 8972: "if these are required fields it
 * should not let me save the quote"): Reference is REQUIRED IN THE BROWSER on
 * an ADM quote not yet in the ERP whose ship-to gives the default nothing to
 * work with.
 *
 * ADM requires Reference (the connector extension's adm_rules QUOTE_REQUIRED).
 * BdAdmRules::defaultReference() fills an EMPTY one from the ship-to's city and
 * state on every save - the ONE Reference rule (G380 / G530); the extension
 * only names it in its refusal ("Reference defaults to the ship-to's city and
 * state"). So the seller must type a Reference only when that rule has nothing
 * to fill it from, and the formula says exactly that:
 *   - the quote is NOT in the ERP (a quote the ERP holds is never blocked:
 *     1,245 of the 1,246 on benchdogs-sandbox hold no Reference, measured
 *     2026-09-29; the same key test as ERP-Core's erp_territory rule);
 *   - it is an ADM quote: a Lead Source is picked. That picker offers only
 *     ADM's codes and is itself required until the quote is in the ERP, so
 *     this follows ADM from ONE source (🔒 1724b) and is never true on
 *     Ophir/EPIC06, whose list is empty;
 *   - the ship-to has neither a city nor a state (the default's two inputs).
 * Measured on 8972: its account holds only a billing address, so the quote's
 * ship-to had no city/state when Send to Estimation was refused; this
 * package's before_save filled "LENEXA KS" at 12:08:20 PDT, once it had one.
 *
 * 🛑 WHY A DEPENDENCY AND NOT `required` + `required_formula` ON THE VARDEF.
 * Both reach the browser the same way, but a vardef `required => true` is
 * SERVED in the module metadata, and the connector reads that flag: core's
 * SugarSellClient.describe_module() marks every served required non-relate
 * field required, and connector_base schema_meta.check_payload() then refuses
 * a Quote create that lacks it (missing_required - dead-lettered). The ERP
 * quote sync creates Quotes without a Reference, so that design would stop it.
 * This file leaves erp_reference's served `required` false (ERP-Core's value)
 * and adds only a view dependency: 'hooks' => array('edit') reaches the record
 * and create views (DependencyManager::getDependenciesForView, RecordView is an
 * editable view) and NEVER fires on a server save (getModuleDependenciesForAction
 * 'save' takes only 'all' or 'save' hooks). Pinned by
 * scripts/tests/test_g809_connector_schema_safe.py.
 */
$dependencies['Quotes']['bd_adm_reference_required'] = array(
    'hooks' => array('edit'),
    'trigger' => 'true',
    'triggerFields' => array(
        'erp_display_sync_key',
        'bd_lead_source',
        'shipping_address_city',
        'shipping_address_state',
    ),
    'onload' => true,
    'actions' => array(
        array(
            'name' => 'SetRequired',
            'params' => array(
                'target' => 'erp_reference',
                'label' => 'erp_reference_label',
                'value' => 'and(equal($erp_display_sync_key,""),not(equal($bd_lead_source,"")),'
                    . 'equal($shipping_address_city,""),equal($shipping_address_state,""))',
            ),
        ),
    ),
);
