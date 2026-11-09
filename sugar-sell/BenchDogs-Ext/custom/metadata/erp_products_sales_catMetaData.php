<?php
// Bench Dogs: a relate field's link to ERP_LookupValues or ERP_Companies, the shape ERP-Core's erp_quotes_fob uses.
$dictionary["erp_products_sales_cat"] = array (
  'true_relationship_type' => 'one-to-many',
  'relationships' =>
  array (
    'erp_products_sales_cat' =>
    array (
      'lhs_module' => 'ERP_LookupValues',
      'lhs_table' => 'erp_lookupvalues',
      'lhs_key' => 'id',
      'rhs_module' => 'Products',
      'rhs_table' => 'products',
      'rhs_key' => 'id',
      'relationship_type' => 'many-to-many',
      'join_table' => 'erp_products_sales_cat_c',
      'join_key_lhs' => 'erp_products_sales_catlookupvalues_idb',
      'join_key_rhs' => 'erp_products_sales_catproducts_ida',
    ),
  ),
  'table' => 'erp_products_sales_cat_c',
  'fields' =>
  array (
    'id' => array('name' => 'id', 'type' => 'id'),
    'date_modified' => array('name' => 'date_modified', 'type' => 'datetime'),
    'deleted' => array('name' => 'deleted', 'type' => 'bool', 'default' => 0),
    'erp_products_sales_catproducts_ida' => array('name' => 'erp_products_sales_catproducts_ida', 'type' => 'id'),
    'erp_products_sales_catlookupvalues_idb' => array('name' => 'erp_products_sales_catlookupvalues_idb', 'type' => 'id'),
  ),
  'indices' =>
  array (
    0 => array('name' => 'idx_erp_products_sales_cat_pk', 'type' => 'primary', 'fields' => array('id')),
    1 => array('name' => 'idx_erp_products_sales_cat_ida1_deleted', 'type' => 'index', 'fields' => array('erp_products_sales_catproducts_ida', 'deleted')),
    2 => array('name' => 'idx_erp_products_sales_cat_idb2_deleted', 'type' => 'index', 'fields' => array('erp_products_sales_catlookupvalues_idb', 'deleted')),
    3 => array('name' => 'erp_products_sales_cat_alt', 'type' => 'alternate_key', 'fields' => array('erp_products_sales_catlookupvalues_idb')),
  ),
);
