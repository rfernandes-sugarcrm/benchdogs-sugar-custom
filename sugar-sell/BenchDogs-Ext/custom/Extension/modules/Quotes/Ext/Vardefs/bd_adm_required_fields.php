<?php

/**
 * G380 / G381 (owner rulings 🔒 1705b, 🔒 1724b): the three values Bench Dogs'
 * ADM company requires that are ADM's OWN - nothing generic.
 *
 *   bd_lead_source  -> QuoteHed.LeadSrc_c  (an ADM LEADSRC code, e.g. DIRMAIL)
 *   bd_lead_type    -> QuoteHed.LeadType_c (an ADM LEADTYPE code, e.g. DIRFOOD)
 *   bd_project_id   -> OrderDtl.ProjectID  (an ADM project, e.g. 20065; one per
 *                      quote, pre-filled from bd_adm_project_by_group_list)
 *
 * NOT HERE (🔒 1724b): Reference is ERP-Epicor's generic Quotes.erp_reference
 * (this package only DEFAULTS it on an ADM quote, BdAdmRules); Expected Close is
 * the Opportunity's close date, sent by core; ProdCode is the catalog part's
 * group, sent by core. The bd_reference field of the unreleased G380 branch is
 * gone with that (that branch was never merged or released).
 *
 * SELLER-OWNED. The pickers store the ERP CODE, which is what ADM stores; the
 * label is looked up (BdAdmRules::lookupOptions) from the ERP_LookupValues rows
 * core publishes from the ADM connection's own code-list config, so a code
 * renamed in ADM shows its new name without touching a quote.
 *
 * 🛑 NOT 'required' => true, deliberately. This package also installs on Ophir
 * (EPIC06), where these lists are empty by design (only the ADM connection
 * publishes them): a required picker there would make every quote unsavable.
 * "Required" is enforced where it can name the company: the connector
 * extension's write-back hook refuses the send (adm_rules).
 *
 * PLACEMENT (G380 (f)): each field carries ERP-Epicor's `erp_layout` marker, and
 * ErpLayoutExtraFields::sync('Quotes') (ERP-Core, custom/include/) puts it on
 * the ERP panel ERP-Epicor's QuotesLayout owns - after Reference, in this order -
 * and takes it off again once this vardef is gone. This package ships no layout
 * code of its own.
 */

$dictionary['Quote']['fields']['bd_lead_source'] = array(
    'name' => 'bd_lead_source',
    'vname' => 'LBL_BD_LEAD_SOURCE',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'bd_adm_lead_source_options',
        'include' => 'custom/modules/Quotes/BdAdmLookupOptions.php',
    ),
    'comment' => 'ERP QuoteHed.LeadSrc_c: an ADM LEADSRC code the seller picks (G380)',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'LBL_RECORDVIEW_PANEL_ERP',
        'after' => 'erp_reference',
    ),
);

$dictionary['Quote']['fields']['bd_lead_type'] = array(
    'name' => 'bd_lead_type',
    'vname' => 'LBL_BD_LEAD_TYPE',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'bd_adm_lead_type_options',
        'include' => 'custom/modules/Quotes/BdAdmLookupOptions.php',
    ),
    'comment' => 'ERP QuoteHed.LeadType_c: an ADM LEADTYPE code the seller picks (G380)',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'LBL_RECORDVIEW_PANEL_ERP',
        'after' => 'bd_lead_source',
    ),
);

$dictionary['Quote']['fields']['bd_project_id'] = array(
    'name' => 'bd_project_id',
    'vname' => 'LBL_BD_PROJECT_ID',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'bd_adm_project_options',
        'include' => 'custom/modules/Quotes/BdAdmLookupOptions.php',
    ),
    'comment' => 'ERP OrderDtl.ProjectID for every order line: an ADM project (G381)',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'LBL_RECORDVIEW_PANEL_ERP',
        'after' => 'bd_lead_type',
    ),
);
