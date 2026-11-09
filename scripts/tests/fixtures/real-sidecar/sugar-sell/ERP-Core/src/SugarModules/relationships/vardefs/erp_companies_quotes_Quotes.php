<?php
// created: manually added
$dictionary["Quote"]["fields"]["erp_companies_quotes"] = array (
  'name' => 'erp_companies_quotes',
  'type' => 'link',
  'relationship' => 'erp_companies_quotes',
  'source' => 'non-db',
  'module' => 'ERP_Companies',
  'bean_name' => 'ERP_Company',
  'side' => 'right',
  'vname' => 'LBL_ERP_COMPANIES_QUOTES_FROM_QUOTES_TITLE',
  'id_name' => 'erp_companies_quoteserp_companies_ida',
  'link-type' => 'one',
);
$dictionary["Quote"]["fields"]["erp_companies_quotes_name"] = array (
  'name' => 'erp_companies_quotes_name',
  'type' => 'relate',
  'source' => 'non-db',
  'vname' => 'LBL_ERP_COMPANIES_QUOTES_FROM_ERP_COMPANIES_TITLE',
  'save' => true,
  'id_name' => 'erp_companies_quoteserp_companies_ida',
  'link' => 'erp_companies_quotes',
  'table' => 'erp_companies',
  'module' => 'ERP_Companies',
  'rname' => 'name',
);
$dictionary["Quote"]["fields"]["erp_companies_quoteserp_companies_ida"] = array (
  'name' => 'erp_companies_quoteserp_companies_ida',
  'type' => 'id',
  'source' => 'non-db',
  'vname' => 'LBL_ERP_COMPANIES_QUOTES_FROM_QUOTES_TITLE_ID',
  'id_name' => 'erp_companies_quoteserp_companies_ida',
  'link' => 'erp_companies_quotes',
  'table' => 'erp_companies',
  'module' => 'ERP_Companies',
  'rname' => 'id',
  'reportable' => false,
  'side' => 'right',
  'massupdate' => false,
  'duplicate_merge' => 'disabled',
  'hideacl' => true,
);
