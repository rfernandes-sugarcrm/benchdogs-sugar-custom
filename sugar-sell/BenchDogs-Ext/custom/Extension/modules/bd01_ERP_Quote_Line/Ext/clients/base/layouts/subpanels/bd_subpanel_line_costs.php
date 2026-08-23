<?php
/**
 * Declares a REAL subpanel component on the Sidecar `subpanels` layout.
 *
 * WHY THIS FILE EXISTS (verified live on benchdogs-sandbox, 2026-08-23):
 * the package already shipped `_overridesubpanel-*` files, but
 * `override_subpanel_list_view` only re-skins a subpanel that is ALREADY in the
 * layout — it never adds one. The thing that was supposed to add it, the
 * legacy `layout_defs[...][subpanel_setup]` installed through
 * installdefs["layoutdefs"], never reaches Sidecar metadata on this instance:
 * a live read showed stock Accounts with 22 components, stock Quotes with its 8
 * built-ins and NONE of ours, and bd01_ERP_Quote with exactly one component —
 * the override — and therefore zero subpanels. The record page rendered an
 * empty "Related" box while the data underneath was complete and correctly
 * linked.
 *
 * Files under custom/Extension/modules/<M>/Ext/clients/base/layouts/subpanels/
 * DO reach metadata (that is how the override arrived), so the component is
 * declared here directly. Shape copied from stock Accounts components.
 */
$viewdefs['bd01_ERP_Quote_Line']['base']['layout']['subpanels']['components'][] = array(
    'layout'  => 'subpanel',
    'label'   => 'LBL_BD01_ERP_LINE_COSTS_FROM_BD01_ERP_QUOTE_COST_TITLE',
    'context' => array('link' => 'bd01_erp_line_costs'),
);
