<?php
// Bench Dogs: a relate field's link to ERP_LookupValues or ERP_Companies, the shape ERP-Core's erp_quotes_fob uses.
$dictionary["erp_orderlines_sales_cat"] = array (
  'true_relationship_type' => 'one-to-many',
  'relationships' =>
  array (
    'erp_orderlines_sales_cat' =>
    array (
      'lhs_module' => 'ERP_LookupValues',
      'lhs_table' => 'erp_lookupvalues',
      'lhs_key' => 'id',
      'rhs_module' => 'ERP_OrderLines',
      'rhs_table' => 'erp_orderlines',
      'rhs_key' => 'id',
      'relationship_type' => 'many-to-many',
      'join_table' => 'erp_orderlines_sales_cat_c',
      'join_key_lhs' => 'erp_orderlines_sales_catlookupvalues_idb',
      'join_key_rhs' => 'erp_orderlines_sales_caterp_orderlines_ida',
    ),
  ),
  'table' => 'erp_orderlines_sales_cat_c',
  'fields' =>
  array (
    'id' => array('name' => 'id', 'type' => 'id'),
    'date_modified' => array('name' => 'date_modified', 'type' => 'datetime'),
    'deleted' => array('name' => 'deleted', 'type' => 'bool', 'default' => 0),
    'erp_orderlines_sales_caterp_orderlines_ida' => array('name' => 'erp_orderlines_sales_caterp_orderlines_ida', 'type' => 'id'),
    'erp_orderlines_sales_catlookupvalues_idb' => array('name' => 'erp_orderlines_sales_catlookupvalues_idb', 'type' => 'id'),
  ),
  'indices' =>
  array (
    0 => array('name' => 'idx_erp_orderlines_sales_cat_pk', 'type' => 'primary', 'fields' => array('id')),
    1 => array('name' => 'idx_erp_orderlines_sales_cat_ida1_deleted', 'type' => 'index', 'fields' => array('erp_orderlines_sales_caterp_orderlines_ida', 'deleted')),
    2 => array('name' => 'idx_erp_orderlines_sales_cat_idb2_deleted', 'type' => 'index', 'fields' => array('erp_orderlines_sales_catlookupvalues_idb', 'deleted')),
    3 => array('name' => 'erp_orderlines_sales_cat_alt', 'type' => 'alternate_key', 'fields' => array('erp_orderlines_sales_catlookupvalues_idb')),
  ),
);
