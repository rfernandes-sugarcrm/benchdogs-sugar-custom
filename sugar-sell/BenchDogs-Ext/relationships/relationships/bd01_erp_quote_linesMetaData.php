<?php
$dictionary["bd01_erp_quote_lines"] = array (
  'true_relationship_type' => 'one-to-many',
  'from_studio' => true,
  'relationships' =>
  array (
    'bd01_erp_quote_lines' =>
    array (
      'lhs_module' => 'bd01_ERP_Quote',
      'lhs_table' => 'bd01_erp_quote',
      'lhs_key' => 'id',
      'rhs_module' => 'bd01_ERP_Quote_Line',
      'rhs_table' => 'bd01_erp_quote_line',
      'rhs_key' => 'id',
      'relationship_type' => 'many-to-many',
      'join_table' => 'bd01_erp_quote_lines_c',
      'join_key_lhs' => 'bd01_erp_quote_linesbd01_erp_quote_ida',
      'join_key_rhs' => 'bd01_erp_quote_linesbd01_erp_quote_line_idb',
    ),
  ),
  'table' => 'bd01_erp_quote_lines_c',
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
    'bd01_erp_quote_linesbd01_erp_quote_ida' =>
    array (
      'name' => 'bd01_erp_quote_linesbd01_erp_quote_ida',
      'type' => 'id',
    ),
    'bd01_erp_quote_linesbd01_erp_quote_line_idb' =>
    array (
      'name' => 'bd01_erp_quote_linesbd01_erp_quote_line_idb',
      'type' => 'id',
    ),
  ),
  'indices' =>
  array (
    0 =>
    array (
      'name' => 'idx_bd01_erp_quote_lines_pk',
      'type' => 'primary',
      'fields' =>
      array (
        0 => 'id',
      ),
    ),
    1 =>
    array (
      'name' => 'idx_bd01_erp_quote_lines_ida1_deleted',
      'type' => 'index',
      'fields' =>
      array (
        0 => 'bd01_erp_quote_linesbd01_erp_quote_ida',
        1 => 'deleted',
      ),
    ),
    2 =>
    array (
      'name' => 'idx_bd01_erp_quote_lines_idb2_deleted',
      'type' => 'index',
      'fields' =>
      array (
        0 => 'bd01_erp_quote_linesbd01_erp_quote_line_idb',
        1 => 'deleted',
      ),
    ),
    3 =>
    array (
      'name' => 'bd01_erp_quote_lines_alt',
      'type' => 'alternate_key',
      'fields' =>
      array (
        0 => 'bd01_erp_quote_linesbd01_erp_quote_line_idb',
      ),
    ),
  ),
);
