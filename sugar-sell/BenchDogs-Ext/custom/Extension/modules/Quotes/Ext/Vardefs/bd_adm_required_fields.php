<?php

/** G380 / G381 (🔒 1705b, 🔒 1724b), G460: the values ADM requires that are ADM's own, nothing generic (G809, G606, G570, G571). */

$dictionary['Quote']['fields']['bd_lead_source'] = array(
    'name' => 'bd_lead_source',
    'vname' => 'LBL_BD_LEAD_SOURCE',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::leadSourceOptions',
    ),
    'comment' => 'ERP QuoteHed.LeadSrc_c: an ADM LEADSRC code the seller picks (G380)',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
    // G574: never pre-picked by the browser (see the docblock).
    'defaultToBlank' => true,
    // G809: required until the quote is in the ERP; the create form fills it
    // from the account's newest quote (see the docblock).
    'erp_required_until_synced' => true,
    'erp_prefill_from_account_latest' => 'billing_account_id',
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'LBL_RECORDVIEW_PANEL_ERP',
        'after' => 'erp_reference',
        'type' => 'erp-dependent-enum',
    ),
);

$dictionary['Quote']['fields']['bd_lead_type'] = array(
    'name' => 'bd_lead_type',
    'vname' => 'LBL_BD_LEAD_TYPE',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::leadTypeOptions',
    ),
    'comment' => 'ERP QuoteHed.LeadType_c: an ADM LEADTYPE code the seller picks (G380)',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
    // G574: never pre-picked by the browser (see the docblock).
    'defaultToBlank' => true,
    // G809: required until the quote is in the ERP; prefilled on create.
    'erp_required_until_synced' => true,
    'erp_prefill_from_account_latest' => 'billing_account_id',
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'LBL_RECORDVIEW_PANEL_ERP',
        'after' => 'bd_lead_source',
        'type' => 'erp-dependent-enum',
    ),
);

$dictionary['Quote']['fields']['bd_project_id'] = array(
    'name' => 'bd_project_id',
    'vname' => 'LBL_BD_PROJECT_ID',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::projectOptions',
    ),
    'comment' => 'ERP OrderDtl.ProjectID for every order line: an ADM project (G381)',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
    // G574: never pre-picked by the browser (see the docblock).
    'defaultToBlank' => true,
    // G570: required in the browser while ADM projects exist (see the docblock).
    'erp_required_when_options' => true,
    // G809 (owner scope): the create form fills it from the account's newest
    // quote holding an offered project (see the docblock).
    'erp_prefill_from_account_latest' => 'billing_account_id',
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'LBL_RECORDVIEW_PANEL_ERP',
        'after' => 'bd_lead_type',
        'type' => 'erp-dependent-enum',
    ),
);

$dictionary['Quote']['fields']['bd_marketing_campaign'] = array(
    'name' => 'bd_marketing_campaign',
    'vname' => 'LBL_BD_MARKETING_CAMPAIGN',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::marketingCampaignOptions',
    ),
    'comment' => 'ERP QuoteHed.MktgCampaignID (and every OrderDtl): an ADM campaign the seller picks (G460)',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
    // G574: never pre-picked by the browser (see the docblock).
    'defaultToBlank' => true,
    // G809: required until the quote is in the ERP. NOT prefilled on its own:
    // the Event's prefill fills the pair from one quote (ERP-Core's rule).
    'erp_required_until_synced' => true,
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'LBL_RECORDVIEW_PANEL_ERP',
        'after' => 'bd_project_id',
        'type' => 'erp-dependent-enum',
    ),
);

$dictionary['Quote']['fields']['bd_marketing_event'] = array(
    'name' => 'bd_marketing_event',
    'vname' => 'LBL_BD_MARKETING_EVENT',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::marketingEventOptions',
    ),
    'comment' => 'ERP QuoteHed.MktgEvntSeq (and every OrderDtl): "<campaign>/<seq>", an ADM event the seller picks (G460)',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
    // G574: never pre-picked by the browser (see the docblock).
    'defaultToBlank' => true,
    // G571: only the chosen campaign's events; cleared when the seller changes
    // the campaign (see the docblock). '/' is BdAdmRules::EVENT_KEY_SEPARATOR.
    'erp_lookup_parent' => 'bd_marketing_campaign',
    'erp_lookup_parent_separator' => '/',
    'erp_lookup_parent_empty_label' => 'LBL_BD_MARKETING_EVENT_PICK_CAMPAIGN',
    // G809: required until the quote is in the ERP; the create form fills the
    // Campaign + Event PAIR from the account's newest quote holding both.
    'erp_required_until_synced' => true,
    'erp_prefill_from_account_latest' => 'billing_account_id',
    'erp_layout' => array(
        'view' => 'record',
        'panel' => 'LBL_RECORDVIEW_PANEL_ERP',
        'after' => 'bd_marketing_campaign',
        'type' => 'erp-dependent-enum',
    ),
);
