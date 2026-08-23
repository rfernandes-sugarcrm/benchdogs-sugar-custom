<?php
// Bean name is "Account", not the module name "Accounts" — same convention the
// Quotes side of bd01_erp_quote_quotes uses ($dictionary["Quote"]).
$dictionary["Account"]["fields"]["bd01_erp_quote_accounts"] = array (
  'name' => 'bd01_erp_quote_accounts',
  'type' => 'link',
  'relationship' => 'bd01_erp_quote_accounts',
  'source' => 'non-db',
  'module' => 'bd01_ERP_Quote',
  'bean_name' => 'bd01_ERP_Quote',
  'vname' => 'LBL_BD01_ERP_QUOTE_ACCOUNTS_FROM_ACCOUNTS_TITLE',
  'id_name' => 'bd01_erp_quote_accountsaccounts_ida',
  'link-type' => 'many',
  'side' => 'left',
);
