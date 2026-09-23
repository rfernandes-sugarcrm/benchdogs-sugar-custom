<?php

/**
 * G380 / G381 (owner rulings 🔒 1705b): the four values Bench Dogs' ADM company
 * requires on a quote or its order that nothing in Sugar carried.
 *
 *   bd_reference    -> QuoteHed.Reference   ("WAYNE NJ"; defaults to the
 *                      ship-to's city and state, the seller may edit it)
 *   bd_lead_source  -> QuoteHed.LeadSrc_c   (an ADM LEADSRC code, e.g. DIRMAIL)
 *   bd_lead_type    -> QuoteHed.LeadType_c  (an ADM LEADTYPE code, e.g. DIRFOOD)
 *   bd_project_id   -> OrderDtl.ProjectID   (an ADM project, e.g. 20065; one per
 *                      quote, pre-filled from bd_adm_project_by_group_list)
 *
 * Expected Close needs no field: it is the Opportunity's close date.
 *
 * SELLER-OWNED. The pickers store the ERP CODE, which is what ADM stores; the
 * label is looked up (BdAdmRules::lookupOptions), so a code renamed in ADM
 * shows its new name without touching a quote.
 *
 * 🛑 NOT 'required' => true, deliberately. This package also installs on Ophir
 * (EPIC06), where these lists are empty by design (the connector publishes
 * ADM's rows only): a required picker there would make every quote
 * unsavable. "Required" is enforced where it can name the company: the
 * connector extension refuses the send (adm_rules), once core has the seam.
 *
 * The options come from functions, not an app_list_strings list, because the
 * lists are ADM data the connector refreshes hourly, not package text.
 */

$dictionary['Quote']['fields']['bd_reference'] = array(
    'name' => 'bd_reference',
    'vname' => 'LBL_BD_REFERENCE',
    'type' => 'varchar',
    'len' => 100,
    'comment' => 'ERP QuoteHed.Reference; defaults to the ship-to city and state (G380)',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
);

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
);
