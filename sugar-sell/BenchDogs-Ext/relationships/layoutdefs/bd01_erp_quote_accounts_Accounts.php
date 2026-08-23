<?php
// Legacy layout_defs path. Kept for parity with the other three relationships,
// but NOT relied on: on benchdogs-sandbox the installdefs['layoutdefs'] channel
// demonstrably never reaches Sidecar metadata (verified 2026-08-23 — even stock
// Quotes showed 8 subpanels and none of ours). The component that actually
// renders is the Ext/clients/base/layouts/subpanels file shipped alongside.
$layout_defs["Accounts"]["subpanel_setup"]['bd01_erp_quote_accounts'] = array (
  'order' => 120,
  'module' => 'bd01_ERP_Quote',
  'subpanel_name' => 'default',
  'sort_order' => 'desc',
  'sort_by' => 'quote_num',
  'title_key' => 'LBL_BD01_ERP_QUOTE_ACCOUNTS_FROM_BD01_ERP_QUOTE_TITLE',
  'get_subpanel_data' => 'bd01_erp_quote_accounts',
  'top_buttons' => array (),
);
