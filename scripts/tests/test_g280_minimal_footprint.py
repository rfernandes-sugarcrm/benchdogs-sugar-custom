#!/usr/bin/env python3
"""G280 / 🔒 1567 - the Bench Dogs package is MINIMAL, and this file pins it.

Owner, verbatim: *"do we overwrite for benchdogs??? if so dont"*, *"Benchdog MLP
shoudl be mininal with mininal foot print of overide,,,"* and *"just if we have
to"* (🔒 1567), on top of 🔒 1508 / 🔒 1520 (customer-category code only;
removal is the default and retention needs the owner's item-by-item consent).

WHAT SHIPS (0.9.42-rc69), and why each item cannot live upstream:

  custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php
  custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php
      Bench's customer category, REQ-19: Epicor Customer.GroupCode and
      CustGrup.GroupDesc. Core has no equivalent field, and core's schema
      enforcement dead-letters the Bench connector's erp_customers writes
      without the vardef.
      (Placed on the Accounts record view by ERP-Core's ErpLayoutExtraFields
      from their `erp_layout` vardef marker since rc70 - 🔒 1724b retired
      BdAccountsLayoutExtensions.php, this package's own layout writer.)
  custom/clients/base/api/BdBenchDogsActionsApi.php
      EMPTY. The one retirement stub left: an api file is require_once'd by
      path on every REST dictionary rebuild, and dropping it would leave rc68's
      bd-tools/repair-ui route registered on upgraded tenants.
  scripts/post_execute.php, bd_pre_uninstall.php, post_uninstall.php
      the lifecycle of the above.

Everything else is REMOVED, and every removed path is held out of the source
and the built zip - asserted below, not assumed. Through 2026-09-30 each also
had a named route off a tenant that already carried it (the one-off
ONEOFF-RetireBdResidue's worklist, or "inert", or "Partial Fulfillment owns the
path"). 🔒2173b withdrew every one-off and deleted its code, so a tenant that
still carries a removed path keeps it: Module Loader never deletes a file a
later build stops shipping. That is the owner's accepted state, and the reasons
below are kept as the record of why each path went.

GROWN ONCE SINCE, WITH CONSENT: G380 / G381 (🔒 1705b, the per-item consent
🔒 1520 asks for) add files for Bench Dogs' ADM company's required quote values.
🔒 1724b ("Bench keeps ONLY ADM config + ADM rules") then cut that to six: the
generic parts (Reference, the part-number refusal, field placement, the
company and product-group answers) are ERP-Epicor's. Each is listed in KEPT
with its reason; see the package README.

MUTATION-VERIFIED (each applied, this file re-run, the named case observed red):
  restore one retired stub (git checkout 019fbe1 -- <stub>)
      -> test_the_source_tree_is_exactly_the_kept_list
      -> test_the_built_zip_is_exactly_the_kept_list (after a rebuild)
  delete a kept file (the customer-group label)
      -> both exact-list cases, and test_the_kept_fields_are_exactly_the_two
  add a stray custom/x.php
      -> test_the_source_tree_is_exactly_the_kept_list
  put a route back in the api stub
      -> test_the_api_stub_registers_no_route
  restore post_install's partial_order_sales_stage write
      -> test_post_install_runs_exactly_the_two_steps
  lower the Partial Fulfillment floor back to 1.0.40
      -> test_partial_fulfillment_is_new_enough_to_own_what_this_stopped_doing
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
import zipfile

from bd_retirement import PKG, built_zip, zip_names

#: The whole package. A new entry here is a new override on a customer tenant,
#: and under 🔒 1520 it needs the owner's item-by-item consent first.
KEPT = {
    "custom/Extension/modules/Quotes/Ext/Vardefs/_override_bd_erp_reference.php":
        "G606 (1796b/1797b): claims placement of core's existing Reference field",
    "custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php":
        "the customer category (REQ-19): the two Account fields",
    "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php":
        "their two labels",
    "custom/Extension/modules/Products/Ext/Vardefs/bd_sales_category.php":
        "the quote line's Sales Category (e_sales_category_c): an enum picker of ADM's SalesCategories lookup values, replacing the free-text field the Bench connector writes SalesCatID into (owner-approved 2026-11-09)",
    "custom/Extension/modules/Products/Ext/Language/en_us.bd_sales_category.php":
        "its label",
    "custom/Extension/modules/ERP_OrderLines/Ext/Vardefs/bd_order_categories.php":
        "the order line's read-only Sales Category and Product Category (e_sales_category_c, e_product_category_c), kept equal to Kinetic's OrderDtl by the Bench connector extension (ticket SUG-17)",
    "custom/Extension/modules/ERP_OrderLines/Ext/Language/en_us.bd_order_categories.php":
        "their two labels",
    "custom/Extension/modules/ERP_OrderLines/Ext/clients/base/views/record/bd_order_categories.php":
        "their read-only placement on the order line's record view",
    "custom/metadata/erp_products_sales_catMetaData.php":
        "the quote line's link to ERP_LookupValues (Sales Category), the shape of ERP-Core's erp_quotes_fob",
    "custom/metadata/erp_orderlines_sales_catMetaData.php":
        "the order line's link to ERP_LookupValues (Sales Category)",
    "custom/metadata/erp_orderlines_product_catMetaData.php":
        "the order line's link to ERP_LookupValues (Product Category)",
    "custom/metadata/erp_companies_productsMetaData.php":
        "the quote line's ERP company link, the pre-filter anchor for its lookup relates",
    "custom/Extension/modules/Products/Ext/Vardefs/bd_quote_line_company.php":
        "the quote line's ERP company relate, copied from the quote",
    "custom/Extension/modules/ERP_LookupValues/Ext/Vardefs/bd_lookup_links.php":
        "the lookup side of the quote line and order line category links",
    "custom/Extension/modules/ERP_Companies/Ext/Vardefs/bd_companies_products.php":
        "the company side of the quote line's company link",
    "scripts/post_execute.php": "lifecycle",
    "scripts/bd_pre_uninstall.php": "lifecycle",
    "scripts/post_uninstall.php": "lifecycle",
    # ── G380 / G381: the owner's per-item consent is 🔒 1705b ("Lead Source and
    # Lead Type ... required pickers in Sugar loaded from ADM's own code
    # lists"; "Project ID becomes a picker of ADM projects on the quote,
    # PRE-FILLED from a product-group -> project default list"), build
    # authorised by 🔒 1704b, cut to ADM's own by 🔒 1724b. Every one is a rule
    # or value of ONE customer's ERP company (ADM) that ERP-Epicor must not
    # carry (gate G2), and each is gated on the quote's company.
    # G460 adds two fields to these same files (no new file): the coordinator's
    # decided design per the owner pattern (GAPS G460, 2026-09-25 00:48Z): ADM
    # refuses every quote and order without a Marketing Campaign / Event.
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php":
        "G380/G381: bd_lead_source, bd_lead_type, bd_project_id; G460: bd_marketing_campaign, "
        "bd_marketing_event - on Quote (erp_layout-marked)",
    "custom/Extension/modules/Quotes/Ext/Language/en_us.bd_adm_required_fields.php":
        "their five labels, in ADM's own words",
    "custom/Extension/modules/Quotes/Ext/LogicHooks/bd_adm_quote_defaults.php":
        "G380/G381: before_save fills an EMPTY Reference on an unsent ADM quote (rc110: the Project only on a "
        "create that sends none)",
    # The owner approved this one hidden field (2026-10-02) to replace the
    # connector extension's own state file: it holds what both systems last
    # agreed for the four lead fields, so the ERP read-back keeps a seller's edit.
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_lead_baseline.php":
        "the hidden lead baseline on Quote (no layout), stamped by the before_save above",
    "custom/Extension/modules/Quotes/Ext/Language/en_us.bd_lead_baseline.php":
        "its one label",
    "custom/Extension/application/Ext/Language/en_us.bd_adm_lists.php":
        "the lookup-type labels (rc108: the group->project list is gone)",
    "custom/src/BenchDogs/BdAdmRules.php":
        "the ADM rules themselves: which companies are ADM, the quote defaults, options (rc86: namespaced, MLP024)",
    "custom/src/BenchDogs/BdHiddenFields.php":
        "G848 (rc87): the one body the six discount overlays call (Rafael's review of #41, item 3)",
    # ── G860 (0.9.42-rc89): the owner's per-item consent is 🔒2179b (2026-10-01,
    # "it's a Bench Dogs thing"): no new field; the Bench package supplies ADM's
    # Ship Via rule so ERP-Epicor's one pre-send refusal names FOB and Ship Via.
    "custom/modules/Quotes/ErpQuoteHooks/OrderRequirements.php":
        "G860: the adapter at ERP-Epicor 1.2.4's OrderRequirements slot (a global class ERP-Epicor "
        "instantiates by name, like PF's ErpQuoteHooks adapters)",
    "custom/src/BenchDogs/BdAdmOrderRequirements.php":
        "G860: ADM's Ship Via requirement on an order that converts an ADM ERP quote (the extension's G852 rule) "
        "and, since rc93, on a Sales Order whose customer has no default Ship Via either",
    # ── G450 (0.9.42-rc74): the owner's per-item consent is his Yes to "a
    # Bench-only third type Suspect, alongside the stock Customer/Prospect,
    # never renaming them" (GAPS.md G450 row; register ~06:55Z 2026-09-24),
    # voided with rc71 by 🔒1775b and REVIVED by the customer's reversal
    # 🔒1783b (2026-09-25 02:19Z: "all 3 record types ... including Suspects").
    # A dom VALUE, not a field and not a layout: one key on the stock
    # account_type_dom. The writer is core's customer_type_extra {"SUS": "Suspect"}.
    "custom/Extension/application/Ext/Language/_override_en_us.bd_account_type_suspect.php":
        "G450: account_type_dom['Suspect'] beside Customer/Prospect (Epicor SUS)",
    # ── G809 (0.9.42-rc78): the owner's per-item consent is his own request on
    # benchdogs-sandbox quote 8972, 2026-09-29 19:10Z: "if these are required
    # fields it should not let me save the quote ... can we fix it on the
    # benchdogs MLP?". ADM's Reference requirement, in the browser only.
    "custom/Extension/modules/Quotes/Ext/Dependencies/bd_adm_reference_required.php":
        "G809: Reference required in the edit views on an unsent ADM quote whose ship-to "
        "cannot default it (a view dependency: never served required, never run on save)",
    # ── G804 (0.9.42-rc78): the owner approved the ADM customer-group list,
    # 🔒 2081b (2026-09-29). ADM refuses a new customer without a group.
    "custom/Extension/modules/Accounts/Ext/LogicHooks/bd_customer_group_name.php":
        "G804: on an Account not in the ERP, the Cust. Group name follows the picked Group Code",
    # ── 🔒 2085b (0.9.42-rc79): the owner's YES to "Cust. Group required on
    # save for a Customer not yet in the ERP"; 🔒 2086b ruled the ADM gate a
    # Bench-specific rule ("no its benchdogs specific").
    # rc120: the owner's ruling of 2026-10-29: whatever the account's type (Send to Estimation creates a Prospect).
    "custom/Extension/modules/Accounts/Ext/Dependencies/bd_adm_customer_group_required.php":
        "rc79: Cust. Group required in the edit views on an ADM account not yet in the ERP, of any type since rc120 "
        "(a view dependency: never served required, never run on save)",
    # rc117: the owner's request from the Bench Dogs UAT call (2026-10-26): Kinetic needs a customer's billing
    # address, so a person saving an account gives it; the sync's own account writes are never refused.
    "custom/Extension/modules/Accounts/Ext/Dependencies/bd_billing_address_required.php":
        "rc117: billing street, city, postal code and country required in the Accounts edit views "
        "(a view dependency: never served required, never run on save)",
    # rc118: the rc117 rule above reaches the record view only, so an account made in the create form saved with no
    # billing address. ERP-Core's own vardefs already give the four a module-wide required rule (a Customer not yet in
    # the ERP); this sets only its formula to 'true'. `required` stays ERP-Core's, so the hub's schema read is unchanged.
    "custom/Extension/modules/Accounts/Ext/Vardefs/_override_zz_bd_billing_address_required.php":
        "rc118: ERP-Core's required_formula on billing street, city, postal code and country set to 'true', so the "
        "create form and the record view ask for them on every save (the `required` key and State untouched)",
    # ── G458 (0.9.42-rc82): the owner's 🔒2102b (build the Bench contact
    # Function / Role / primary flags now, READ-ONLY Epicor -> Sugar) and the
    # coordinator's brief (2026-09-30: the five fields synced by ext 0.3.6 L807
    # are on no Contacts view; place them, read-only, guarded). The fields are
    # the CUSTOMER's (Bench_Dogs_Account_Contact_Fields 1.0.0), so this is a
    # metadata-time overlay, not a field and not a vardef marker: ERP-Core's
    # ErpLayoutExtraFields supports Quotes/Accounts only, and a marker on a
    # field another package owns leaves a typeless phantom def where that
    # package is absent (see the file's docblock).
    "custom/Extension/modules/Contacts/Ext/clients/base/views/record/bd_epicor_contact_fields.php":
        "G458: the customer's five Epicor contact fields shown on the Contacts record view (ERP panel), only where "
        "the tenant has them (a sidecar overlay; no deployed-view write); rc117: Role editable, the other four read-only",
    # ── G848 (0.9.42-rc84): the owner's order 🔒2151b (2026-09-30: "benchdog dont
    # want seller to apply discount so both the discount pannel and the line
    # item doscounts should be not vissible on benchdog MLP"; the coordinator's
    # assumption (c) records that it supersedes 🔒 1508's trim for this item).
    # Five sidecar overlays, one body: they HIDE (no field, no setting, no
    # deployed-view write), only in this package.
    "custom/Extension/modules/Quotes/Ext/clients/base/views/record/_override_zz_bd_hide_seller_discounts.php":
        "G848: ERP-Epicor's Discount panel (erp_discount_panel) off the quote record/create view",
    "custom/Extension/modules/Quotes/Ext/clients/base/views/quote-data-grand-totals-header/"
    "_override_zz_bd_hide_seller_discounts.php":
        "G848: the totals strip's Order Level Discount (deal_tot) not drawn",
    "custom/Extension/modules/Quotes/Ext/clients/base/views/quote-data-grand-totals-footer/"
    "_override_zz_bd_hide_seller_discounts.php":
        "G848: the footer's Order Level Discount row (erp_document_discount_amount) not drawn",
    "custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list/"
    "_override_zz_bd_hide_seller_discounts.php":
        "G848: the grid's Line Discount column / edit-row input (discount_field) not drawn",
    "custom/Extension/modules/Products/Ext/clients/base/views/record/_override_zz_bd_hide_seller_discounts.php":
        "G848: the quote line's own page draws no discount_field",
    # G848 (0.9.42-rc85, 🔒2159b): the list preview's discount, and two captions relabelled (existing keys, en_us).
    "custom/Extension/modules/Quotes/Ext/clients/base/views/preview/_override_zz_bd_hide_seller_discounts.php":
        "G848: the Quotes list preview draws no Order Discount (deal_tot)",
    # rc92 (owner): Sugar's Shipping Provider is not sent to Epicor and Bench has no shippers; off the quote screen.
    "custom/Extension/modules/Quotes/Ext/clients/base/views/record/_override_zz_bd_hide_shipping_provider.php":
        "rc92: Shipping Provider (shipper_name) off the quote record/create view",
    "custom/Extension/modules/Quotes/Ext/clients/base/views/preview/_override_zz_bd_hide_shipping_provider.php":
        "rc92: Shipping Provider (shipper_name) off the Quotes list preview",
    "custom/Extension/modules/Quotes/Ext/Language/_override_en_us.bd_hide_seller_discounts.php":
        "G848: ERP-Core's 'Line Items Discounted Subtotal' (LBL_NEW_SUB) reads 'Subtotal' on Bench",
    "custom/Extension/modules/Products/Ext/Language/_override_en_us.bd_hide_seller_discounts.php":
        "G848: ERP-Core's 'Discounted Total' grid column (LBL_ERP_DISCOUNTED_TOTAL) reads 'Total' on Bench",
    # rc94: the owner relayed Bench Dogs' ask (2026-10-14, "only their MLP or extension"): the seller types the Part
    # Number of a new product that is not in the catalog. No field, no setting: ERP-Core's one read-only rule is
    # rewritten for that line only, and every other customer keeps it as ERP-Core ships it.
    "custom/Extension/modules/Products/Ext/Dependencies/_override_zz_bd_new_product_part_number.php":
        "rc94: Part Number typeable on an unsent new product with its Product Group picked (ERP-Core's "
        "erp_part_number_read_only rewritten in place; read-only on every other line)",
    # rc95: the owner approved two fields, in this package only (2026-10-14): Bench Dogs wants an Epicor order
    # with no Sugar quote, and an Epicor-born quote, to carry one Opportunity for Closed Won reporting. The
    # connector extension creates the Opportunity; these two fields are what it keys and links it by.
    "custom/Extension/modules/Opportunities/Ext/Vardefs/bd_erp_sync_key.php":
        "rc95: Opportunities.erp_sync_key (unique), the key the connector upserts and links an Opportunity "
        "by, and the bd_erp_orders link (no layout)",
    "custom/Extension/modules/Opportunities/Ext/Language/en_us.bd_erp_sync_key.php":
        "their two labels",
    "custom/Extension/modules/ERP_Orders/Ext/Vardefs/bd_opportunity.php":
        "rc95: the Opportunity relate on ERP_Orders (bd_opportunity_id, a real column; read-only; no layout) "
        "and its one-to-many relationship",
    "custom/Extension/modules/ERP_Orders/Ext/Language/en_us.bd_opportunity.php":
        "their three labels",
    # rc96: a Quotes override of ERP-Core's erp-lookup field (a label marker, then from rc104 Ship Via required on
    # every quote). Since rc113 Ship Via is checked at Submit Order and Order Selected Lines only, and the file ships
    # empty: Module Loader never deletes a file, so only a file at this path takes rc112's rule off a tenant.
    "custom/modules/Quotes/clients/base/fields/erp-lookup/erp-lookup.js":
        "rc113: ERP-Core's erp-lookup field on Quotes, unchanged; ships only to overwrite rc104-rc112's "
        "Ship Via required rule on an upgraded tenant",
    # rc99: the owner approved one setting, in this package only (2026-10-16): a default owner for the records the
    # ERP integration creates without one. Sugar fills no owner on a REST create, so the connector's order and
    # quote Opportunities and its ERP orders arrive unowned; a save hook gives them the user the setting names.
    "custom/src/BenchDogs/BdDefaultOwner.php":
        "rc99: the default owner rule (the setting, who may own records, the before_save hook on the "
        "integration's saves only)",
    "custom/Extension/modules/Opportunities/Ext/LogicHooks/bd_default_owner.php":
        "rc99: an Opportunity saved with no owner takes the default owner",
    "custom/Extension/modules/ERP_Orders/Ext/LogicHooks/bd_default_owner.php":
        "rc99: an ERP order saved with no owner takes the default owner",
    # rc99: where an administrator picks that user. Sugar's own Users lookup on a small Admin page; a module client
    # page, served with the metadata, so no Quick Repair is needed.
    "custom/Extension/modules/Administration/Ext/Administration/bd_default_owner.php":
        "rc99's path for the link, shipped EMPTY from rc100: an upgrade keeps a copied file, and its old body "
        "would draw a second link",
    # rc100: the owner's request approves exactly Bench Dogs' additional mapping's fields (2026-10-17): created where a
    # tenant lacks them, as Studio custom fields, and drawn on the three record views where the tenant has them.
    "custom/src/BenchDogs/BdKineticFields.php":
        "rc100: the 23 Kinetic fields (names, types, sizes, places, labels) and which a tenant lacks (rc111: 22; the "
        "order lines total is retired, relabelled 'Line Total (old)' where a tenant has it, never deleted; rc119: "
        "23, the quote's Due date, one new field approved by the owner on 2026-10-27; rc122: 24, the quote's Project "
        "Name, a text field for Kinetic's QuoteComment, one new field approved by the owner)",
    "custom/src/BenchDogs/BdStudioFields.php":
        "rc100: the two Sugar calls that read a module's fields and create one, through Studio's own field code",
    # rc112 (G1313): the owner asked for Bench Dogs' quotes in their own layout (2026-10-21, "open a gap to show the
    # quotes for benchdogs in this format", with their Kinetic quote form as the sample). No field and no setting: a
    # PDF Manager template the install creates on Quotes, unpublished on uninstall, and the logo it prints.
    "custom/src/BenchDogs/BdQuotePdf.php":
        "rc112: the quote layout as a PDF Manager template on Quotes (template_name erp_customer_quote; rc122: its "
        "Project row prints the quote's Project Name)",
    "custom/themes/default/images/bd_quote_logo.jpg":
        "rc112: the logo that layout prints, taken from the sample quote PDF",
    "custom/src/BenchDogs/BdFieldPlacement.php":
        "rc100: where the record views draw them (a view overlay; ERP-Epicor's layout files are never written)",
    # rc116 (G1338): the owner's ruling of 2026-10-25 (yes/no, "only in the Bench Dogs MLP", "no new field"): Lynn
    # could not see Epicor's Product Group on a quote. It is synced as the stock product category; no field, list or
    # vardef is added, the category is shown on the quote line grid and its existing labels read "Product Group".
    "custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list/bd_product_group_column.php":
        "rc116: the quote line grid's Product Group column (category_name), read-only after Part Number "
        "(BdFieldPlacement::gridColumn, a view overlay; no deployed-view write)",
    "custom/Extension/modules/Products/Ext/Language/_override_en_us.bd_product_group.php":
        "rc116: the quoted line item's Category / Category Name read 'Product Group' (existing keys, en_us)",
    "custom/Extension/modules/ProductTemplates/Ext/Language/_override_en_us.bd_product_group.php":
        "rc116: the product's Category / Category Name / list Category read 'Product Group' (existing keys, en_us)",
    # rc117: the owner's request from the Bench Dogs UAT call (2026-10-26): the Product Catalog shows the Kinetic part
    # number. No field: the stored mft_part_num, drawn as a column; ERP-Core already makes it a quick-search field.
    "custom/Extension/modules/ProductTemplates/Ext/clients/base/views/list/bd_part_number_column.php":
        "rc117: the Product Catalog list's Part Number column (mft_part_num), read-only after the name",
    "custom/Extension/modules/ProductTemplates/Ext/clients/base/views/selection-list/bd_part_number_column.php":
        "rc117: the same column in the Search and Select list a quote line's product field opens",
    "custom/Extension/modules/Quotes/Ext/clients/base/views/record/bd_kinetic_fields.php":
        "rc100: the quote's three header fields, in the ERP panel above the status block (rc119: four, the Due date; "
        "rc120: the Due right after Expires Date wherever that is drawn; rc122: five, the Project Name on a row of its "
        "own right after the Due)",
    # rc117: the owner's request from the Bench Dogs UAT call and his ruling of 2026-10-26: the Project is each quote
    # line's own (one new field, approved by the request), the quote-level one leaves the quote screen (its column and
    # data stay), and the install that moves it copies each quote's Project onto its blank lines, once.
    "custom/Extension/modules/Products/Ext/Vardefs/bd_line_project.php":
        "rc117: Products.bd_project_id, each quote line's own ADM project (the quote's options, blank by default)",
    "custom/Extension/modules/Products/Ext/Language/en_us.bd_line_project.php":
        "rc117: its label, Project",
    "custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list/bd_project_column.php":
        "rc117: the quote line grid's Project column, editable, after Product Group",
    # rc120: a UAT ask: the Product column of the quote line grid is too narrow to read a product's name.
    "custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list/bd_product_column_width.php":
        "rc120: the quote line grid's Product column drawn cell-xxlarge (460px) instead of stock's cell-large "
        "(BdFieldPlacement::gridColumnWidth, a view overlay; no deployed-view write)",
    "custom/Extension/modules/Products/Ext/clients/base/views/record/bd_project.php":
        "rc117: the line's Project on the quoted line item's record view, editable",
    "custom/Extension/modules/Quotes/Ext/clients/base/views/record/bd_line_project.php":
        "rc117: the quote-level Project off the quote record/create view, and the lines' Project fetched with them",
    "custom/Extension/modules/Quotes/Ext/clients/base/views/recorddashlet/bd_line_project.php":
        "rc117: the same on the quote's dashlet view",
    "custom/src/BenchDogs/BdInstallHistory.php":
        "rc117: what Module Loader's history says, so the one-time steps run on the upgrade from below rc117 only",
    "custom/src/BenchDogs/BdLineProject.php":
        "rc117: the one-time copy of each quote's Project onto its lines with none (never over a line's own)",
    # rc117: the owner's request (UAT call, 2026-10-26): Create Opportunity & Quote starts with Needs Price Estimate on.
    # No new setting: ERP-Epicor's own per-tenant default for that quote's type, set once by the install.
    "custom/src/BenchDogs/BdQuoteTypeDefault.php":
        "rc117: ERP-Epicor's erp_integration.opp_quote_type set to advanced_quote, once per tenant",
    "custom/Extension/modules/Products/Ext/clients/base/views/record/bd_kinetic_fields.php":
        "rc100: the quoted line item's fields, in a Cost & capacity panel (rc107: directly under ERP-Epicor's Epicor "
        "Cost Worksheet panel; rc108: ours found inside that panel move to it; rc109: with the blank rows they leave)",
    "custom/Extension/modules/Products/Ext/Language/en_us.bd_kinetic_fields.php":
        "rc100: that panel's label",
    "custom/Extension/modules/ERP_Orders/Ext/clients/base/views/record/bd_kinetic_fields.php":
        "rc100: the ERP order's Project Manager, Engineer and Charges (rc111: the retired lines total off the view, "
        "ERP-Epicor's own ERP Line Total right after ERP Misc. Charges where the stored view lacks it)",
    "custom/Extension/modules/ERP_Orders/Ext/clients/base/views/list/bd_kinetic_fields.php":
        "rc111: the retired lines total off the ERP Orders list view and its column chooser",
    "custom/Extension/application/Ext/Language/_override_en_us.bd_sales_category_list.php":
        "rc100: the Sales Category list, only where a tenant has none of its own",
    # rc100: the owner's ruling of 2026-10-17 (yes/no): Bench Dogs' Opportunity stage list, over the integration's keys.
    "custom/Extension/application/Ext/Language/_override_en_us.bd_sales_stages.php":
        "rc100: Bench Dogs' stage list and probabilities, in order; merged after ERP-Core's template",
    "custom/Extension/application/Ext/DropdownsStyle/bd_sales_stage_style.php":
        "rc100: a style for the new stages (a formatted enum draws an unstyled key blank), append-only; On Hold since rc125",
    # rc100: the owner asked for the link inside Admin -> ERP Integration, beside ERP Sales Reps.
    "custom/Extension/modules/Administration/Ext/Administration/_override_bd_default_owner.php":
        "rc100: the default owner link, added to ERP-Core's ERP Integration group (merged after it, as an "
        "_override_ fragment), else in a Bench Dogs box",
    "custom/Extension/modules/Administration/Ext/Language/en_us.bd_default_owner.php":
        "rc99: the page's and the link's words",
    "custom/modules/Administration/clients/base/layouts/bd-default-owner/bd-default-owner.php":
        "rc99: the page's layout (#Administration/layout/bd-default-owner)",
    "custom/modules/Administration/clients/base/views/bd-default-owner/bd-default-owner.php":
        "rc99: the setting as Sugar's relate field over Users",
    "custom/modules/Administration/clients/base/views/bd-default-owner/bd-default-owner.js":
        "rc99: reads the setting, saves the picked user's id (empty for off), shows a refusal",
    "custom/modules/Administration/clients/base/views/bd-default-owner/bd-default-owner.hbs":
        "rc99: the page: the lookup, its help, Save and Cancel, a word when the saved user is not active",
    "custom/modules/Administration/clients/base/api/BdDefaultOwnerApi.php":
        "rc99: GET and PUT BenchDogs/default-owner, administrators only; refuses an id that is not an active user",
    # rc121: Bench Dogs' UAT ask: Valid Until and Kinetic's expiry are one date, so Expires Date leaves every quote view
    # (the field and its data stay: the Bench connector extension still writes it). View overlays only.
    "custom/Extension/modules/Quotes/Ext/clients/base/views/recorddashlet/bd_expires_date_off.php":
        "rc121: Expires Date off the quote's dashlet view (BdFieldPlacement::takenOff)",
    "custom/Extension/modules/Quotes/Ext/clients/base/views/preview/bd_expires_date_off.php":
        "rc121: Expires Date off the Quotes list preview",
    "custom/Extension/modules/Quotes/Ext/clients/base/views/list/bd_expires_date_off.php":
        "rc121: Expires Date off the Quotes list view and its column chooser",
    # rc121: Bench Dogs' UAT ask: a new Opportunity or Quote is owned by its account's owner, so one entered by an
    # assistant is the seller's (the quote PDF's Sales Person follows). The form's half is a view with no template,
    # added to the two create layouts by overlay; the server's half a before_save hook. No field, no setting.
    "custom/src/BenchDogs/BdAccountOwner.php":
        "rc121: the account owner rule (creates only, not the integration's, never over a pick) and the create "
        "layouts' component",
    "custom/Extension/modules/Opportunities/Ext/LogicHooks/bd_account_owner.php":
        "rc121: a new Opportunity takes its account's owner",
    "custom/Extension/modules/Quotes/Ext/LogicHooks/bd_account_owner.php":
        "rc121: a new Quote takes its account's owner",
    "custom/Extension/modules/Opportunities/Ext/clients/base/layouts/create/bd_account_owner.php":
        "rc121: the bd-account-owner view in the Opportunities create layout, after Sugar's create view",
    "custom/Extension/modules/Quotes/Ext/clients/base/layouts/create/bd_account_owner.php":
        "rc121: the bd-account-owner view in the Quotes create layout, after Sugar's create view",
    "custom/modules/Quotes/clients/base/views/bd-account-owner/bd-account-owner.js":
        "rc121: on a create form, Assigned To follows the account's active owner until the person picks one "
        "(a module client file, served with the metadata: no Quick Repair)",
    "custom/modules/Opportunities/clients/base/views/bd-account-owner/bd-account-owner.js":
        "rc121: the same controller for the Opportunities create form",
}

ONEOFF = ("the one-off ONEOFF-RetireBdResidue deleted it (🔒 1521) until 🔒2173b "
          "withdrew it; a tenant it never ran on keeps its copy")
INERT = ("nothing shipped calls it any more; the copy an upgraded tenant keeps is "
         "unreachable")
PF_OWNS = ("Partial Fulfillment ships the SAME path (>= 1.0.41 with the G282 preserve "
           "gate); reinstall PF after rc69 so PF's body is the one on disk (G282)")


#: Every path rc68 shipped that rc69 does not, and every path rc69 shipped that
#: rc70 does not (and later cuts), with why it went.
REMOVED = {
    # rc70 (🔒 1724b): ERP-Core's ErpLayoutExtraFields places the fields now.
    # Module Loader never deletes a file a later build stops shipping (§CW /
    # G37), so an upgraded tenant KEEPS this file - inert: nothing shipped
    # requires it (test_nothing_shipped_still_names_a_removed_class). The
    # one-off must not blank it while a tenant may still run rc69, whose
    # install and uninstall call it.
    "custom/modules/Accounts/BdAccountsLayoutExtensions.php": INERT,
    "custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php": PF_OWNS,
    "custom/modules/Quotes/BdQuotesLayoutExtensions.php": INERT,
    "custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php": INERT,
    "custom/modules/Quotes/BdKineticOpportunityHook.php": ONEOFF,
    # rc87 (Rafael's review of #41): the emptied REST stub and the option functions (now BdAdmRules methods).
    "custom/clients/base/api/BdBenchDogsActionsApi.php": ONEOFF,
    "custom/modules/Quotes/BdAdmLookupOptions.php": ONEOFF,
    # rc86 (MLP024): the class moved to custom/src/BenchDogs; the one-off (1.0.5) blanked the old path.
    "custom/modules/Quotes/BdAdmRules.php": ONEOFF,
    "custom/Extension/application/Ext/DropdownsStyle/sales_stage_dom_style.php": ONEOFF,
    "custom/Extension/application/Ext/Language/_override_en_us.bd_country_lookup.php": ONEOFF,
    "custom/Extension/application/Ext/Language/en_us.bd_country_lookup.php": ONEOFF,
    "custom/Extension/application/Ext/Language/en_us.bd_erp_stage_list.php": ONEOFF,
    "custom/Extension/application/Ext/Language/en_us.bd_stage_doms.php": ONEOFF,
    "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_action_buttons.php": ONEOFF,
    "custom/Extension/modules/Accounts/Ext/LogicHooks/bd_account_country_guard.php": ONEOFF,
    "custom/Extension/modules/Contacts/Ext/LogicHooks/bd_contact_sync.php": ONEOFF,
    "custom/Extension/modules/Contacts/Ext/Vardefs/bd_contact_sync_fields.php": ONEOFF,
    "custom/Extension/modules/ERP_OrderLines/Ext/clients/base/views/record/bd_shipped_value.php": ONEOFF,
    "custom/Extension/modules/ERP_OrderLines/Ext/Language/en_us.bd_shipped_value.php": ONEOFF,
    "custom/Extension/modules/ERP_OrderLines/Ext/Vardefs/bd_shipped_value.php": ONEOFF,
    "custom/Extension/modules/ERP_Orders/Ext/clients/base/views/record/bd_shipped_value_total.php": ONEOFF,
    "custom/Extension/modules/ERP_Orders/Ext/Language/en_us.bd_shipped_value_total.php": ONEOFF,
    "custom/Extension/modules/ERP_Orders/Ext/Vardefs/bd_shipped_value_total.php": ONEOFF,
    "custom/Extension/modules/Opportunities/Ext/Language/en_us.bd_governing_origin.php": ONEOFF,
    "custom/Extension/modules/Opportunities/Ext/Vardefs/bd_forecast_provenance.php": ONEOFF,
    "custom/Extension/modules/Opportunities/Ext/Vardefs/bd_governing_origin.php": ONEOFF,
    "custom/Extension/modules/Products/Ext/Language/en_us.bd_line_order.php": ONEOFF,
    "custom/Extension/modules/Products/Ext/Vardefs/bd_deleted_erp_sync_key.php": ONEOFF,
    "custom/Extension/modules/Products/Ext/Vardefs/bd_governing_line_fields.php": ONEOFF,
    "custom/Extension/modules/Products/Ext/Vardefs/bd_line_order_fields.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Language/en_us.bd_action_buttons.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Language/en_us.bd_erp_fields.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/LogicHooks/bd_kinetic_opportunity.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_comment_pending.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_comment_requested_at.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_comment_text.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_deleted_erp_sync_key.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_erp_kpi_inputs.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_erp_stage_code.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_estimating_turnaround.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_governing_line.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_priced_at.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_print_link.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_print_requested_at.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_print_status.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_quantity_breaks.php": ONEOFF,
    "custom/Extension/modules/RevenueLineItems/Ext/Vardefs/bd_deliverable_key.php": ONEOFF,
}

REMOVED_CLASSES = (
    "ErpQuoteOpportunityContribution", "BdQuotesLayoutExtensions",
    "BdOpportunitiesLayoutExtensions", "BdKineticOpportunityHook", "BdBenchDogsActionsApi",
    # rc70 (🔒 1724b), and the unreleased G380 branch's own layout writer.
    "BdAccountsLayoutExtensions", "BdAdmQuoteFieldsLayout",
)


def shipped_tree() -> set[str]:
    return {
        str(p.relative_to(PKG))
        for top in ("custom", "scripts")
        for p in (PKG / top).rglob("*") if p.is_file()
    }


def code_only(path) -> str:
    """PHP with comments stripped, so a retirement NOTE never reads as a caller."""
    body = re.sub(r"/\*.*?\*/", "", path.read_text(encoding="utf-8"), flags=re.S)
    return re.sub(r"(?m)(^|\s)(//|#)[^\n]*", r"\1", body)


class TheKeptListIsTheWholePackage(unittest.TestCase):
    def test_the_source_tree_is_exactly_the_kept_list(self):
        self.assertEqual(sorted(shipped_tree()), sorted(KEPT),
                         "the package grew or shrank; under 🔒 1520 an addition needs "
                         "the owner's per-item consent - update KEPT only with it")

    def test_the_built_zip_is_exactly_the_kept_list(self):
        self.assertEqual(sorted(zip_names()), sorted(set(KEPT) | {"manifest.php"}))

    def test_the_manifest_copies_exactly_the_kept_custom_files(self):
        with zipfile.ZipFile(built_zip()) as zipped:
            manifest = zipped.read("manifest.php").decode()
        copied = set(re.findall(r"'to'\s*=>\s*'([^']+)'", manifest))
        self.assertEqual(copied, {k for k in KEPT if k.startswith("custom/")})

    def test_the_kept_and_removed_lists_do_not_overlap(self):
        self.assertEqual(set(KEPT) & set(REMOVED), set())


class EveryRemovedPathLeavesTheTenant(unittest.TestCase):
    def test_none_of_them_ships(self):
        names = zip_names()
        for rel in REMOVED:
            with self.subTest(path=rel):
                self.assertFalse((PKG / rel).exists(), f"{rel} is back in the source")
                self.assertNotIn(rel, names, f"{rel} is back in the built zip")

    def test_nothing_shipped_still_names_a_removed_class(self):
        """An inert file stays inert only while nothing reaches for it. A
        require_once of a file this build no longer ships is a compile fatal no
        catch can see."""
        offenders = []
        for rel in KEPT:
            if rel.endswith(".jpg"):
                continue  # rc112: the quote logo is an image, not code
            code = code_only(PKG / rel)
            for name in REMOVED_CLASSES:
                if name in code:
                    offenders.append(f"{rel}: {name}")
        self.assertEqual(offenders, [])


class NothingShippedDeclaresAnotherPackagesField(unittest.TestCase):
    def test_nothing_shipped_names_partial_fulfillments_governing_flag(self):
        """`Products.erp_governing` is Partial Fulfillment's field. A Bench file
        declaring or writing it would override that package's own definition on
        every tenant. Moved here from the retired-mirror suite (rc98), whose
        other cases held long-retired paths out and are covered by the kept list."""
        offenders = [rel for rel in KEPT if rel.endswith(".php")
                     and re.search(r"\berp_governing\b", code_only(PKG / rel))]
        self.assertTrue([rel for rel in KEPT if rel.endswith(".php")], "anti-vacuity: no PHP file was scanned")
        self.assertEqual(offenders, [])


class TheApiStubIsRetired(unittest.TestCase):
    """rc87 (Rafael's review of #41): the emptied REST stub no longer ships. The one-off that deleted the
    tenant's copy was withdrawn (🔒2173b)."""

    def test_the_api_stub_no_longer_ships(self):
        self.assertFalse((PKG / "custom/clients/base/api").exists())


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheKeptFieldsAreExactlyTheTwo(unittest.TestCase):
    PROBE = ("$dictionary = []; $mod_strings = []; include $argv[1]; include $argv[2];"
             " echo json_encode(['fields' => array_keys($dictionary['Account']['fields'] ?? []),"
             " 'labels' => $mod_strings]);")

    def test_the_kept_fields_are_exactly_the_two(self):
        out = subprocess.run(
            ["php", "-r", self.PROBE,
             str(PKG / "custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php"),
             str(PKG / "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php")],
            capture_output=True, text=True, check=True)
        observed = json.loads(out.stdout)
        self.assertEqual(sorted(observed["fields"]), ["bd_customer_group", "bd_customer_group_code"])
        # G532: short enough to read in full at the record view's label width
        # (about 12 characters show before the ellipsis), and not the same text.
        self.assertEqual(observed["labels"], {"LBL_BD_CUSTOMER_GROUP_CODE": "Group Code",
                                              "LBL_BD_CUSTOMER_GROUP": "Cust. Group"})
        for text in observed["labels"].values():
            self.assertLessEqual(len(text), 11, text)


class TheLifecycleDoesOnlyTheKeptWork(unittest.TestCase):
    def test_post_install_runs_exactly_the_kept_steps(self):
        code = code_only(PKG / "scripts/post_execute.php")
        # rc87: the two layout steps share one loop, keyed by their step names.
        steps = sorted(set(re.findall(r"\$bdStepReport\['([a-z_]+)'\]\s*=\s*'ok'", code))
                       | set(re.findall(r"'[A-Za-z]+' => '([a-z_]+_erp_layout)'", code)))
        # rc106 adds the Kinetic labels step (ERP Line Total).
        # rc112 adds the quote PDF template step.
        # rc117 adds the install history read and the two one-time steps it decides.
        self.assertEqual(steps, ["accounts_erp_layout", "install_history", "kinetic_fields", "kinetic_labels",
                                 "line_project_copy", "opp_quote_type", "quote_pdf_template", "quotes_erp_layout",
                                 "repair_rebuild"])
        self.assertEqual(code.count("ErpLayoutExtraFields::sync("), 1)
        self.assertIn("foreach (array('Accounts' => 'accounts_erp_layout', 'Quotes' => 'quotes_erp_layout')", code)
        self.assertLess(code.index("rebuildExtensions"), code.index("ErpLayoutExtraFields::sync("),
                        "sync() must read the vardefs AFTER the rebuild merged this package's")
        for gone in ("partial_order_sales_stage", "uninstall_languages", "zz_bd_stage_doms",
                     "saveSetting('erp_integration'", "rebuild_tabledictionary",
                     "return_app_list_strings_language"):
            self.assertNotIn(gone, code, f"post_install still does '{gone}'")
        self.assertIn("array('Accounts', 'Quotes', 'Products', 'ERP_Orders')", code,
                      "the rebuild is not narrowed to the four modules this package extends")
        self.assertLess(code.index("BdKineticFields::createMissing("), code.index("rebuildExtensions"),
                        "the rebuild must see the fields the step just created")
        # rc117: the one ERP-Epicor setting the package writes is the Create Opportunity & Quote type, through
        # BdQuoteTypeDefault, after the rebuild; Partial Fulfillment's partial order stage stays its own (above).
        self.assertLess(code.index("rebuildExtensions"), code.index("BdLineProject::copyFromQuotes("),
                        "the copy must read the Products vardefs AFTER the rebuild merged this package's")
        writer = code_only(PKG / "custom/src/BenchDogs/BdQuoteTypeDefault.php")
        self.assertEqual(re.findall(r"saveSetting\(([^)]*)\)", writer),
                         ["self::CATEGORY, self::KEY, self::NEEDS_PRICE_ESTIMATE, 'base'"])
        others = [rel for rel in KEPT if rel.endswith(".php") and rel != "custom/src/BenchDogs/BdQuoteTypeDefault.php"
                  and "erp_integration" in code_only(PKG / rel)]
        self.assertEqual(others, [], "no other shipped file names ERP-Epicor's settings category")

    def test_pre_uninstall_undoes_only_the_kept_placements(self):
        """rc70 (🔒 1724b): nothing before the files go. The marked fields are
        taken off both views by post_uninstall's ErpLayoutExtraFields::sync(),
        once their vardefs are gone - leaving them would be the
        orphan-on-a-view the G280 grade looks for."""
        pre = code_only(PKG / "scripts/bd_pre_uninstall.php")
        # rc112: the one call before the files go unpublishes the quote PDF template, whose logo goes with them.
        self.assertEqual(re.findall(r"\b([A-Z][A-Za-z]+)::", pre), ["BdQuotePdf"])
        post = code_only(PKG / "scripts/post_uninstall.php")
        self.assertIn("ErpLayoutExtraFields::sync($bdModule)", post)
        self.assertIn("foreach (array('Accounts', 'Quotes') as $bdModule)", post)
        self.assertLess(post.index("rebuildExtensions"), post.index("ErpLayoutExtraFields::sync"),
                        "sync() must read the vardefs AFTER the rebuild dropped this package's")

    def test_post_uninstall_rebuilds_only_the_extended_modules(self):
        code = code_only(PKG / "scripts/post_uninstall.php")
        self.assertRegex(code, r"\$bdModules\s*=\s*array\(\s*'Accounts',\s*'Quotes',\s*\);")
        self.assertIn("MetaDataManager::refreshCache()", code)

    def test_partial_fulfillment_is_new_enough_to_own_what_this_stopped_doing(self):
        """PF >= 1.0.41: OpportunityContribution.php with the all-alternative
        preserve gate (G282). PF >= 1.0.43: the reader-side default
        'Partial Production Ordered' this package's post_install used to write
        (G305). A lower floor lets rc69 install over a PF that does neither."""
        with zipfile.ZipFile(built_zip()) as zipped:
            manifest = zipped.read("manifest.php").decode()
        pf = re.search(r"'id_name'\s*=>\s*'sugarai_erp_epicor_partialfulfillment',\s*"
                       r"'version'\s*=>\s*'([0-9.]+)'", manifest)
        self.assertIsNotNone(pf, "the Partial Fulfillment dependency is gone")
        # 1.0.50 since rc70: the PF release that ships with ERP-Epicor 1.1.125
        # (coordinator, 2026-09-24).
        self.assertEqual(pf.group(1), "1.0.50")
        epicor = re.search(r"'id_name'\s*=>\s*'sugarai_erp_epicor',\s*'version'\s*=>\s*'([0-9.]+)'",
                           manifest)
        self.assertIsNotNone(epicor)
        # 1.1.125: the release carrying G380 (d)-(g) - erp_reference, the
        # part-number switch, ErpLayoutExtraFields, ErpQuoteFacts (🔒 1724b).
        # 1.1.131 since rc73: the release carrying G530's limit on the field
        # (erp_reference.erp_max_length, ErpQuoteFacts::referenceMaxLength()),
        # which the Reference default is shortened to.
        # rc87 (🔒2167b): BdAdmRules asks ERP-Epicor 1.2.0's namespaced ErpQuoteFacts only.
        # rc89 (G860, 🔒2179b): 1.2.4 is the first ERP-Epicor that asks OrderRequirements.php.
        self.assertEqual(epicor.group(1), "1.2.4")


if __name__ == "__main__":
    unittest.main()
