<?php
// Bench Dogs: the order line's link to the standard ProductCategories module, same many-to-many join shape as erp_orderlines_sales_cat.
$dictionary["erp_orderlines_prodcat"] = array (
  'true_relationship_type' => 'one-to-many',
  'relationships' =>
  array (
    'erp_orderlines_prodcat' =>
    array (
      'lhs_module' => 'ProductCategories',
      'lhs_table' => 'product_categories',
      'lhs_key' => 'id',
      'rhs_module' => 'ERP_OrderLines',
      'rhs_table' => 'erp_orderlines',
      'rhs_key' => 'id',
      'relationship_type' => 'many-to-many',
      'join_table' => 'erp_orderlines_prodcat_c',
      'join_key_lhs' => 'erp_orderlines_prodcatproductcategories_idb',
      'join_key_rhs' => 'erp_orderlines_prodcaterp_orderlines_ida',
    ),
  ),
  'table' => 'erp_orderlines_prodcat_c',
  'fields' =>
  array (
    'id' => array('name' => 'id', 'type' => 'id'),
    'date_modified' => array('name' => 'date_modified', 'type' => 'datetime'),
    'deleted' => array('name' => 'deleted', 'type' => 'bool', 'default' => 0),
    'erp_orderlines_prodcaterp_orderlines_ida' => array('name' => 'erp_orderlines_prodcaterp_orderlines_ida', 'type' => 'id'),
    'erp_orderlines_prodcatproductcategories_idb' => array('name' => 'erp_orderlines_prodcatproductcategories_idb', 'type' => 'id'),
  ),
  'indices' =>
  array (
    0 => array('name' => 'idx_erp_orderlines_prodcat_pk', 'type' => 'primary', 'fields' => array('id')),
    1 => array('name' => 'idx_erp_orderlines_prodcat_ida1_deleted', 'type' => 'index', 'fields' => array('erp_orderlines_prodcaterp_orderlines_ida', 'deleted')),
    2 => array('name' => 'idx_erp_orderlines_prodcat_idb2_deleted', 'type' => 'index', 'fields' => array('erp_orderlines_prodcatproductcategories_idb', 'deleted')),
    3 => array('name' => 'erp_orderlines_prodcat_alt', 'type' => 'alternate_key', 'fields' => array('erp_orderlines_prodcatproductcategories_idb')),
  ),
);
