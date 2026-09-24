<?php

// G380 (d) / 🔒 1724b — THE QUOTE'S ERP REFERENCE, A GENERIC FIELD.
//
// Epicor's QuoteHed.Reference is a standard column ("A reference field that
// could be used to enter the customer RFQ # or any other piece of useful
// information" - Erp.BO.QuoteSvc swagger, Erp.Quote). One customer company
// (Bench Dogs' ADM) REQUIRES it: Send to Estimation came back HTTP 400
// "Reference is required" on stage t7, 2026-09-23. The Bench package carried
// its own bd_reference for it (footprint SB2); the owner ruled the field
// generic, so it lives here and core sends it (g380-contract §1a: QuoteHed
// Reference <- Quotes.erp_reference, trimmed, only when non-empty).
//
// SELLER-OWNED. Not required - no company but ADM has been shown to need it,
// and a company that does refuses through its own write-back hook. Editable,
// on the Quotes record view's ERP panel (see 'erp_layout' below).
//
// 🚩 len 50 IS THE CONTRACT'S ASSUMPTION, NOT A MEASUREMENT. The contract asked
// for QuoteHed.Reference's MaxLength from Epicor's $metadata. It cannot come
// from there: Epicor's OData $metadata carries no $MaxLength on any property
// (connector_epicor/schema_meta.py, live-verified against EPIC06), and the
// captured Erp.BO.QuoteSvc swagger declares Reference as a bare string. The
// width lives in Epicor's data dictionary (Ice.BO.ZDataFieldSvc,
// DataTableID 'QuoteHed', FieldName 'Reference': its Format, e.g. "x(50)"),
// which is a read-only live probe nobody has run. Sugar holding FEWER
// characters than Epicor allows is the safe direction (Epicor never truncates
// what Sugar sent); if Epicor allows fewer than 50, a long value is refused by
// the ERP with its own message.
//
// A plain varchar with a real column: no 'source' key
// (scripts/tests/test_erp_core_vardefs_get_real_columns.py). Kept on a Quote
// Copy on purpose - it is the seller's reference for the customer's request,
// not a record of anything the ERP did (zz_erp_copy_policy.php sheds only
// ERP history).
$dictionary['Quote']['fields']['erp_reference'] = array(
    'name'            => 'erp_reference',
    'vname'           => 'LBL_ERP_REFERENCE',
    'type'            => 'varchar',
    'len'             => 50,
    'required'        => false,
    'comment'         => 'Epicor QuoteHed.Reference: the customer RFQ number or other reference, sent by core on Send to Estimation',
    'reportable'      => true,
    'audited'         => true,
    'importable'      => 'true',
    'massupdate'      => false,
    // Placed on the Quotes record view's ERP panel by the G380 (f) marker
    // (custom/include/ErpLayoutExtraFields.php, run at the end of
    // QuotesLayout::install()): on an upgraded tenant it is added after Ship
    // Via when it is on NO panel, and a tenant where an admin moved it keeps it
    // where the admin put it. A fresh install gets it from
    // QuotesLayout::erpPanel().
    'erp_layout'      => array(
        'view'  => 'record',
        'panel' => 'LBL_RECORDVIEW_PANEL_ERP',
        'after' => 'erp_quotes_ship_via_name',
    ),
);
