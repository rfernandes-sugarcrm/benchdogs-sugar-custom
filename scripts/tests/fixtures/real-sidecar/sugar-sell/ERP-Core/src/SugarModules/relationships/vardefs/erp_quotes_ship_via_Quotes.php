<?php
// created: manually added
$dictionary["Quote"]["fields"]["erp_quotes_ship_via"] = array (
  'name' => 'erp_quotes_ship_via',
  'type' => 'link',
  'relationship' => 'erp_quotes_ship_via',
  'source' => 'non-db',
  'module' => 'ERP_LookupValues',
  'bean_name' => 'ERP_LookupValue',
  'side' => 'right',
  'vname' => 'LBL_ERP_QUOTES_SHIP_VIA_FROM_QUOTES_TITLE',
  'id_name' => 'erp_quotes_ship_viaerp_lookupvalues_idb',
  'link-type' => 'one',
);
$dictionary["Quote"]["fields"]["erp_quotes_ship_via_name"] = array (
  'name' => 'erp_quotes_ship_via_name',
  'type' => 'relate',
  'source' => 'non-db',
  'vname' => 'LBL_ERP_QUOTES_SHIP_VIA_FROM_ERP_LOOKUPVALUES_TITLE',
  'save' => true,
  'id_name' => 'erp_quotes_ship_viaerp_lookupvalues_idb',
  'link' => 'erp_quotes_ship_via',
  'table' => 'erp_lookupvalues',
  'module' => 'ERP_LookupValues',
  'rname' => 'name',
  'prefilter_field' => 'erp_companies_quotes_name',
  'prefilter_conditions' => '[{"type":{"$in":["ShippingTerms"]}},{"is_active":{"$equals":"1"}}]',
);
$dictionary["Quote"]["fields"]["erp_quotes_ship_viaerp_lookupvalues_idb"] = array (
  'name' => 'erp_quotes_ship_viaerp_lookupvalues_idb',
  'type' => 'id',
  'source' => 'non-db',
  'vname' => 'LBL_ERP_QUOTES_SHIP_VIA_FROM_QUOTES_TITLE_ID',
  'id_name' => 'erp_quotes_ship_viaerp_lookupvalues_idb',
  'link' => 'erp_quotes_ship_via',
  'table' => 'erp_lookupvalues',
  'module' => 'ERP_LookupValues',
  'rname' => 'id',
  'reportable' => false,
  'side' => 'right',
  'massupdate' => false,
  'duplicate_merge' => 'disabled',
  'hideacl' => true,
);
