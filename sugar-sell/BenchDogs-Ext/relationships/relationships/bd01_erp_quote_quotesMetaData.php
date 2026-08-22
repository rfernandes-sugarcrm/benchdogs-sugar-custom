<?php
$dictionary["bd01_erp_quote_quotes"] = array (
  'true_relationship_type' => 'one-to-many',
  'from_studio' => true,
  'relationships' =>
  array (
    'bd01_erp_quote_quotes' =>
    array (
      'lhs_module' => 'Quotes',
      'lhs_table' => 'quotes',
      'lhs_key' => 'id',
      'rhs_module' => 'bd01_ERP_Quote',
      'rhs_table' => 'bd01_erp_quote',
      'rhs_key' => 'id',
      'relationship_type' => 'many-to-many',
      'join_table' => 'bd01_erp_quote_quotes_c',
      'join_key_lhs' => 'bd01_erp_quote_quotesquotes_ida',
      'join_key_rhs' => 'bd01_erp_quote_quotesbd01_erp_quote_idb',
    ),
  ),
  'table' => 'bd01_erp_quote_quotes_c',
  'fields' =>
  array (
    'id' =>
    array (
      'name' => 'id',
      'type' => 'id',
    ),
    'date_modified' =>
    array (
      'name' => 'date_modified',
      'type' => 'datetime',
    ),
    'deleted' =>
    array (
      'name' => 'deleted',
      'type' => 'bool',
      'default' => 0,
    ),
    'bd01_erp_quote_quotesquotes_ida' =>
    array (
      'name' => 'bd01_erp_quote_quotesquotes_ida',
      'type' => 'id',
    ),
    'bd01_erp_quote_quotesbd01_erp_quote_idb' =>
    array (
      'name' => 'bd01_erp_quote_quotesbd01_erp_quote_idb',
      'type' => 'id',
    ),
  ),
  'indices' =>
  array (
    0 =>
    array (
      'name' => 'idx_bd01_erp_quote_quotes_pk',
      'type' => 'primary',
      'fields' =>
      array (
        0 => 'id',
      ),
    ),
    1 =>
    array (
      'name' => 'idx_bd01_erp_quote_quotes_ida1_deleted',
      'type' => 'index',
      'fields' =>
      array (
        0 => 'bd01_erp_quote_quotesquotes_ida',
        1 => 'deleted',
      ),
    ),
    2 =>
    array (
      'name' => 'idx_bd01_erp_quote_quotes_idb2_deleted',
      'type' => 'index',
      'fields' =>
      array (
        0 => 'bd01_erp_quote_quotesbd01_erp_quote_idb',
        1 => 'deleted',
      ),
    ),
    3 =>
    array (
      'name' => 'bd01_erp_quote_quotes_alt',
      'type' => 'alternate_key',
      'fields' =>
      array (
        0 => 'bd01_erp_quote_quotesbd01_erp_quote_idb',
      ),
    ),
  ),
);
