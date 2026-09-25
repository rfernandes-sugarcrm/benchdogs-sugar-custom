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
// G530 — EPICOR TAKES AT MOST 10 CHARACTERS, MEASURED. `len` 50 was the
// G380 contract's assumption; it is now only STORAGE, and `erp_max_length`
// below is the limit Sugar enforces:
//   - ADM (Bench Dogs, benchdogs-sandbox quote #36, 2026-09-24 21:08:21Z):
//     Send to Estimation with Reference "HARRISBURG PA" (13) came back
//     "The maximum number of characters allowed for Reference is 10", and no
//     ERP quote was created.
//   - EPIC06 (read-only, 2026-09-24 23:20:37Z): Epicor's data dictionary,
//     GET Ice.BO.ZDataTableSvc/ZDataTables('Erp','QuoteHed')/ZDataFields
//     ?$filter=FieldName eq 'Reference' -> DefaultFormat "x(10)", nvarchar,
//     FieldFormat and UDFieldFormat empty (no company override), Company "".
//     So 10 is Epicor's SHIPPED width, not one customer's rule.
//     (Ice.BO.ZDataFieldSvc is 404 over REST v2; the child navigation above
//     is the route. OData $metadata carries no $MaxLength, see
//     connector_epicor/schema_meta.py.)
// A company whose Epicor overrides the format (a non-empty FieldFormat /
// UDFieldFormat on that row) would need its own width here; none is known.
//
// WHO READS erp_max_length: the Quotes record and create views (a validation
// on save, ERP-Epicor record.js / create.js), Send to Estimation's up-front
// refusal (QuotesErpActionsApi, through ErpQuoteFacts::referenceMaxLength()),
// and a customer package that DEFAULTS the field (Bench Dogs shortens its
// "CITY ST" default to fit). One number, on the field, read by all three.
//
// `len` STAYS 50, deliberately: lowering it would make an upgrade ALTER the
// column to varchar(10), which truncates, or under strict mode fails on, the
// longer values tenants already hold (benchdogs-sandbox #36 holds 13).
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
    // G530: what Epicor accepts in QuoteHed.Reference (see above). Not a Sugar
    // vardef key: a package attribute, served to the client with the field.
    'erp_max_length'  => 10,
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
