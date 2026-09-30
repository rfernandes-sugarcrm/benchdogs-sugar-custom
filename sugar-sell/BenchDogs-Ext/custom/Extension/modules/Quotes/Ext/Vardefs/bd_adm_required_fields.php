<?php

/**
 * G380 / G381 (owner rulings 🔒 1705b, 🔒 1724b) and G460: the values Bench
 * Dogs' ADM company requires that are ADM's OWN - nothing generic.
 *
 *   bd_lead_source  -> QuoteHed.LeadSrc_c  (an ADM LEADSRC code, e.g. DIRMAIL)
 *   bd_lead_type    -> QuoteHed.LeadType_c (an ADM LEADTYPE code, e.g. DIRFOOD)
 *   bd_project_id   -> OrderDtl.ProjectID  (an ADM project, e.g. 20065; one per
 *                      quote, pre-filled from bd_adm_project_by_group_list)
 *   bd_marketing_campaign -> QuoteHed.MktgCampaignID AND OrderDtl.MktgCampaignID
 *                      on every order line (an ADM campaign, e.g. 26DISCNV)
 *   bd_marketing_event    -> QuoteHed.MktgEvntSeq AND OrderDtl.MktgEvntSeq; the
 *                      picker stores "<campaign>/<seq>" (e.g. 26DISCNV/2),
 *                      because ADM reuses seq 1..4 under every campaign; the
 *                      connector extension splits it and checks the pair
 *
 * G460 (measured on benchdogs-dev 2026-09-24): without the pair ADM refuses
 * Send to Estimation ("A valid Marketing Campaign is required / A valid
 * Marketing Event is required") and Submit Order ("You must select an active
 * Marketing Campaign."). ADM defines NO default event, so nothing is ever
 * INVENTED for either field.
 *
 * G809 (owner, benchdogs-sandbox quote 8972, 2026-09-29: "if these are required
 * fields it should not let me save the quote and maybe we should put
 * defaults"): Lead Source, Lead Type, Project and the Campaign + Event pair
 * are now DEFAULTED FROM THE SAME ACCOUNT'S NEWEST QUOTE holding a value the
 * picker still offers, EACH FIELD FROM ITS OWN such quote (a code retired on
 * a newer quote is skipped, never copied) - the customer's own last choice,
 * not an invented code - and only into an empty field. Measured on the pilot ADM
 * company (760 quotes since 2025-09-01): consecutive quotes of one customer
 * repeat Lead Source 93 %, Lead Type 95 %, Campaign 74 %. Two writers, one
 * rule: ERP-Core's 'erp_prefill_from_account_latest' on the create form, and
 * BdAdmRules::applyDefaults() in before_save for a quote created without the
 * form (API, the Account button). Assumption recorded by the coordinator: the
 * owner may overrule the "newest quote" choice.
 *
 * NOT HERE (🔒 1724b): Reference is ERP-Epicor's generic Quotes.erp_reference
 * (this package DEFAULTS it on an ADM quote, BdAdmRules, and claims its layout
 * placement in _override_bd_erp_reference.php, G606); Expected Close is
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
 * The server-side guard stays the connector extension's write-back hook, which
 * refuses the send by name (adm_rules).
 *
 * G809: REQUIRED IN THE BROWSER, ONLY UNTIL THE QUOTE IS IN THE ERP. ERP-Core's
 * 'erp_required_until_synced' (erp-dependent-enum) makes Lead Source, Lead
 * Type, Campaign and Event required on the create and record views while the
 * picker has something to offer (so Ophir/EPIC06, with empty lists, stays
 * optional: ADM from ONE source, 🔒 1724b) AND the quote has no ERP key. A
 * quote the ERP holds is never blocked: its values are the ERP's, and all
 * 1,246 ERP quotes on benchdogs-sandbox hold these four EMPTY today (measured
 * 2026-09-29). Both quote types: Submit Order needs the pair too (G460).
 * Browser-only, by construction: Sugar's REST save path does not enforce
 * `required` (SugarBeanApiHelper::populateFromApi() validates only submitted
 * fields; a SugarLogic SetRequired only flips field_defs server-side), so the
 * connector's quote writes (the ERP quote sync, the key stamp after Send to
 * Estimation) are unaffected. Project keeps G570's 'erp_required_when_options'
 * (every quote: Submit Order needs it on an ERP quote too). An ERP-Epicor
 * whose ERP-Core predates G809 ignores both new keys: the four stay optional
 * and unfilled in the browser, exactly as before (no floor bump needed).
 *
 * G571 / G570 (ERP-Epicor 1.1.134+, ERP-Core's `erp-dependent-enum` field
 * type; this package only declares keys, the client code is ERP-Core's,
 * 🔒 1520 / 1567 / 1514). The marker's 'type' => 'erp-dependent-enum' puts
 * that type on the record-view entry (ErpLayoutExtraFields, also on a tenant
 * where the field is already placed); the vardef stays a plain enum, never a
 * `custom_type` (Sugar would skip the column on save). G809 gives all five
 * pickers that type (Lead Source, Lead Type and Campaign were plain enums):
 * the TYPE step of sync() rewrites entries already placed, so an upgraded
 * tenant gets it too (bd_erp_layout_test.php T6f).
 *  - bd_marketing_event: 'erp_lookup_parent' => 'bd_marketing_campaign' - the
 *    picker offers only the chosen campaign's events (the key split at the
 *    LAST '/', BdAdmRules::EVENT_KEY_SEPARATOR, exactly the campaign: 26DISC
 *    never gets 26DISCNV's), and a seller's campaign change clears an event
 *    that no longer belongs. Measured 2026-09-25: all 68 events were listed,
 *    and quote #4 saved 26DISCNV with 26BRECLN/1. A pair saved before this
 *    stays shown until the seller next changes the campaign (never cleared on
 *    open, edit or Cancel).
 *  - bd_project_id: 'erp_required_when_options' => true - required in the
 *    browser only while the tenant HAS ADM projects to pick, so Bench blocks
 *    an empty Project at save (it was found only at Submit Order) and Ophir,
 *    with an empty list, stays optional. The ext's refusal at send stays the
 *    server-side guard. Side effect: on a quote saved in the browser the
 *    seller now picks the Project, so the before_save CMI -> 20065 default
 *    (🔒 1712b, fills only an EMPTY Project) applies only to quotes created
 *    without the form (API / the Account button).
 *  - bd_project_id, G809 owner scope (2026-09-29T21:05Z, benchdogs-sandbox:
 *    "Project is also required ... but is NOT prefilled from the account's
 *    history like the other four"): 'erp_prefill_from_account_latest' too, so
 *    the create form fills it from the account's newest quote holding a
 *    project ADM still offers; the before_save history does the same for a
 *    quote created without the form, AFTER the product-group default above
 *    (which keeps precedence where it applies). Metadata on the existing
 *    field only: no new field, column or setting (🔒 1810b).
 *
 * G574: 'defaultToBlank' => true ON ALL FIVE - THE BROWSER NEVER PICKS ONE.
 * Measured on benchdogs-dev (SALES ORDER smoke 2026-09-25, #Quotes/create): a
 * new quote showed Project "17879 - LGH EXPANSION" before any account was
 * chosen - another customer's project. The cause is the stock EnumField
 * (clients/base/fields/enum/enum.js, SugarEnt 26.1.0, identical in 25.2.0):
 * _checkForDefaultValue() sets an enum with no value to its FIRST option on
 * create/edit unless the def says defaultToBlank; and the first option is not
 * the blank one here, because a JavaScript object lists integer-like keys
 * ("17879", "18126") BEFORE every other key, so the '' that
 * BdAdmRules::optionsFromRows() puts first comes last once the REST answer is
 * parsed. The field def a sidecar field reads is the vardef extended by the
 * viewdef (sidecar view/field.js: def = _.extend({}, fieldDefs, options.def)),
 * so the flag works from here, with no layout code; stock DataArchiver's
 * process_type sets it the same way. Side effect, intended: the pre-pick also
 * made bd_project_id non-empty, so the before_save CMI -> 20065 default
 * (🔒 1712b), which only fills an EMPTY Project, could never apply on a quote
 * created in the browser. Only Project has integer-like codes today; the flag is
 * on all five because none of them may ever be defaulted by the browser (no
 * ADM default exists, 🔒 362), whatever codes ADM adds later.
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
        'name' => 'bd_adm_lead_type_options',
        'include' => 'custom/modules/Quotes/BdAdmLookupOptions.php',
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
        'name' => 'bd_adm_project_options',
        'include' => 'custom/modules/Quotes/BdAdmLookupOptions.php',
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
        'name' => 'bd_adm_marketing_campaign_options',
        'include' => 'custom/modules/Quotes/BdAdmLookupOptions.php',
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
        'name' => 'bd_adm_marketing_event_options',
        'include' => 'custom/modules/Quotes/BdAdmLookupOptions.php',
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
