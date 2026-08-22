<?php
$dictionary["bd01_erp_line_costs"] = array (
  'true_relationship_type' => 'one-to-many',
  'from_studio' => true,
  'relationships' =>
  array (
    'bd01_erp_line_costs' =>
    array (
      'lhs_module' => 'bd01_ERP_Quote_Line',
      'lhs_table' => 'bd01_erp_quote_line',
      'lhs_key' => 'id',
      'rhs_module' => 'bd01_ERP_Quote_Cost',
      'rhs_table' => 'bd01_erp_quote_cost',
      'rhs_key' => 'id',
      'relationship_type' => 'many-to-many',
      'join_table' => 'bd01_erp_line_costs_c',
      'join_key_lhs' => 'bd01_erp_line_costsbd01_erp_quote_line_ida',
      'join_key_rhs' => 'bd01_erp_line_costsbd01_erp_quote_cost_idb',
    ),
  ),
  'table' => 'bd01_erp_line_costs_c',
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
    'bd01_erp_line_costsbd01_erp_quote_line_ida' =>
    array (
      'name' => 'bd01_erp_line_costsbd01_erp_quote_line_ida',
      'type' => 'id',
    ),
    'bd01_erp_line_costsbd01_erp_quote_cost_idb' =>
    array (
      'name' => 'bd01_erp_line_costsbd01_erp_quote_cost_idb',
      'type' => 'id',
    ),
  ),
  'indices' =>
  array (
    0 =>
    array (
      'name' => 'idx_bd01_erp_line_costs_pk',
      'type' => 'primary',
      'fields' =>
      array (
        0 => 'id',
      ),
    ),
    1 =>
    array (
      'name' => 'idx_bd01_erp_line_costs_ida1_deleted',
      'type' => 'index',
      'fields' =>
      array (
        0 => 'bd01_erp_line_costsbd01_erp_quote_line_ida',
        1 => 'deleted',
      ),
    ),
    2 =>
    array (
      'name' => 'idx_bd01_erp_line_costs_idb2_deleted',
      'type' => 'index',
      'fields' =>
      array (
        0 => 'bd01_erp_line_costsbd01_erp_quote_cost_idb',
        1 => 'deleted',
      ),
    ),
    3 =>
    array (
      'name' => 'bd01_erp_line_costs_alt',
      'type' => 'alternate_key',
      'fields' =>
      array (
        0 => 'bd01_erp_line_costsbd01_erp_quote_cost_idb',
      ),
    ),
  ),
);
