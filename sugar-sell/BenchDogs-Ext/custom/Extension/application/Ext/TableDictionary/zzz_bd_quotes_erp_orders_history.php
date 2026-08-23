<?php
// BenchDogs-Ext: quotes_erp_orders cardinality override.
//
// ERP-Core ships this relationship as true_relationship_type one-to-one
// (quotes_erp_ordersMetaData.php), which made sense when a quote could
// only ever produce one ERP order. The Bench Dogs flow is quote-led with
// repeat orders (REQ-1: prototype order first, production run later from
// the SAME quote), and under one-to-one every link add SOFT-DELETES the
// sibling link row - confirmed live: linking order 9344 dropped 9340 and
// vice versa, so the quote could never show its own order history.
//
// one-to-many (a quote has many ERP orders; an order belongs to one
// quote) is the truthful cardinality. Same join table, same keys - only
// the enforced exclusivity changes. This file sorts after ERP-Core's
// TableDictionary entry (zzz_ prefix), so this later plain-assignment
// definition wins in the compiled tabledictionary.ext.php.
$dictionary["quotes_erp_orders"] = array (
  'true_relationship_type' => 'one-to-many',
  'from_studio' => true,
  'relationships' =>
  array (
    'quotes_erp_orders' =>
    array (
      'lhs_module' => 'Quotes',
      'lhs_table' => 'quotes',
      'lhs_key' => 'id',
      'rhs_module' => 'ERP_Orders',
      'rhs_table' => 'erp_orders',
      'rhs_key' => 'id',
      'relationship_type' => 'many-to-many',
      'join_table' => 'quotes_erp_orders',
      'join_key_lhs' => 'quotes_erp_ordersquotes_ida',
      'join_key_rhs' => 'quotes_erp_orderserp_orders_idb',
    ),
  ),
  'table' => 'quotes_erp_orders',
  'fields' =>
  array (
    'id' => array ('name' => 'id', 'type' => 'id'),
    'date_modified' => array ('name' => 'date_modified', 'type' => 'datetime'),
    'deleted' => array ('name' => 'deleted', 'type' => 'bool', 'default' => 0),
    'quotes_erp_ordersquotes_ida' => array ('name' => 'quotes_erp_ordersquotes_ida', 'type' => 'id'),
    'quotes_erp_orderserp_orders_idb' => array ('name' => 'quotes_erp_orderserp_orders_idb', 'type' => 'id'),
  ),
  'indices' =>
  array (
    0 => array ('name' => 'idx_quotes_erp_orders_pk', 'type' => 'primary', 'fields' => array (0 => 'id')),
    1 => array ('name' => 'idx_quotes_erp_orders_ida1_deleted', 'type' => 'index', 'fields' => array (0 => 'quotes_erp_ordersquotes_ida', 1 => 'deleted')),
    2 => array ('name' => 'idx_quotes_erp_orders_idb2_deleted', 'type' => 'index', 'fields' => array (0 => 'quotes_erp_orderserp_orders_idb', 1 => 'deleted')),
  ),
);
