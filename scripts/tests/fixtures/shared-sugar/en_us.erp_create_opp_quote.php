<?php

// Accounts "Create Opportunity & Quote" header button - see
// custom/modules/Accounts/clients/base/fields/erp-create-opp-quote/ and
// AccountsErpActionsApi::createOppQuote.
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_BUTTON'] = 'Create Opportunity & Quote';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_RUNNING'] = 'Creating the opportunity and its quote...';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_ERROR'] = 'Could not create the opportunity and quote.';

// G223, the message half. The quote is created but its Ship To is EMPTY
// when the account's data names no default (none at all, several with no
// ERP primary, or more than one primary). The API's own sentence said so,
// but the page showed it in a green auto-closing toast and navigated away,
// so the seller of Bench quote 335 (ADDISON, 3 ship-tos) read nothing.
// These are the persistent warning the page raises instead, one per case,
// each naming the account so the seller knows whose quote it is about.
// {{account}} and {{count}} are filled through app.lang.get's template
// context (the {{part}} pattern in ERP-Core's en_us.erp_no_catalog_price).
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_TITLE'] = 'Ship To not set';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_NONE'] =
    '{{account}} has no active shipping address — add one on the account, then choose it on the quote.';
// 🛑 G297 — THESE TWO NO LONGER NAME AN ACTION THE SELLER CANNOT TAKE.
//
// They used to read "...and none is marked primary" / "...and more than one is
// marked primary", which asks a Sugar seller to set erp_primary_ship_to. They
// cannot, and the reason is not an oversight to be fixed with a layout entry:
//
//   * the field is declared 'readonly' => true and 'inline_edit' => false
//     (ERP-Core/.../Ext/Vardefs/erp_primary_ship_to.php), so no record view
//     and no grid cell can write it;
//   * it is on NO layout, viewdef or record view in either package;
//   * 🚩 and NOTHING writes it at all. That vardef says "WRITTEN BY THE
//     CONNECTOR", and as of 2026-09-22 that is not true: `erp_primary_ship_to`
//     and `PrimaryShipTo` appear nowhere in connector-core, connector-base or
//     connector-epicor. The same correction ErpQuantityBreakRepriceHook had to
//     make about erp_break_unit_price under G218.
//
// So the clause is gone and the half that WORKS is kept - "choose one on the
// quote", the Ship To picker G286 shipped. The cause is stated as what it is:
// the ERP has not named a default, which is a fact about the ERP's data and
// not a task the seller has left undone. Same words the server's own
// shipToEmptyReason() uses (AccountsErpActionsApi), so the toast and the
// API sentence cannot describe the same account differently.
//
// 📌 NOT FIXED HERE, AND DELIBERATELY: a seller who wants ADDISON's default to
// stick needs Epicor's ShipTo.PrimaryShipTo to reach Sugar. That is the
// connector lane's mapping, not a Sugar screen.
//
// ⚠️ SUPERSEDED FACTS, KEPT SO THE REASONING ABOVE STAYS READABLE: the flag
// DOES have a writer (the connector's ShipToPrimaryEnrichmentStep, from
// Epicor's Customer.ShipToNum), and it IS shown read-only on the Shipping
// Address record view (ShippingAddressesLayout). The conclusion stands: the
// seller still cannot set it, so no sentence here asks them to.
//
// G311 - ONE ADDRESS THAT IS NOT THE ERP DEFAULT. "Exactly one active address"
// no longer auto-picks (AccountsErpActionsApi::defaultShippingAddress), so a
// seller can now meet an account with ONE address and an empty Ship To. The
// multi-address sentence would read "1 shipping addresses ... among them" and
// hide why, so this case has its own words: the lone address is not the one
// the ERP names. Deliberately no "primary" in it (G297).
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_ONE_NOT_DEFAULT'] =
    '{{account}} has 1 shipping address and the ERP does not name it as the default '
    . '— choose one on the quote.';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_NO_PRIMARY'] =
    '{{account}} has {{count}} shipping addresses and the ERP names no default among them '
    . '— choose one on the quote.';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_MANY_PRIMARY'] =
    '{{account}} has {{count}} shipping addresses and the ERP names more than one of them as its default '
    . '— choose one on the quote.';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_EMPTY'] =
    '{{account}}: no shipping address was chosen for this quote — choose one on the quote.';
