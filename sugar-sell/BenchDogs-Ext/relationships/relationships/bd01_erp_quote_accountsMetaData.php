<?php
/**
 * Accounts 1--M bd01_ERP_Quote.
 *
 * Modelled byte-for-byte on bd01_erp_quote_quotesMetaData.php (Module Builder
 * shape) so the two behave identically. Exists so an ERP quote hangs off the
 * customer it belongs to: without it there is no path from an Account to the
 * quotes Epicor raised for it, which is the first thing a salesperson opens.
 */
$dictionary["bd01_erp_quote_accounts"] = array (
  'true_relationship_type' => 'one-to-many',
  'from_studio' => true,
  'relationships' =>
  array (
    'bd01_erp_quote_accounts' =>
    array (
      'lhs_module' => 'Accounts',
      'lhs_table' => 'accounts',
      'lhs_key' => 'id',
      'rhs_module' => 'bd01_ERP_Quote',
      'rhs_table' => 'bd01_erp_quote',
      'rhs_key' => 'id',
      'relationship_type' => 'many-to-many',
      'join_table' => 'bd01_erp_quote_accounts_c',
      'join_key_lhs' => 'bd01_erp_quote_accountsaccounts_ida',
      'join_key_rhs' => 'bd01_erp_quote_accountsbd01_erp_quote_idb',
    ),
  ),
  'table' => 'bd01_erp_quote_accounts_c',
  'fields' =>
  array (
    'id' => array ('name' => 'id', 'type' => 'id'),
    'date_modified' => array ('name' => 'date_modified', 'type' => 'datetime'),
    'deleted' => array ('name' => 'deleted', 'type' => 'bool', 'default' => 0),
    'bd01_erp_quote_accountsaccounts_ida' =>
      array ('name' => 'bd01_erp_quote_accountsaccounts_ida', 'type' => 'id'),
    'bd01_erp_quote_accountsbd01_erp_quote_idb' =>
      array ('name' => 'bd01_erp_quote_accountsbd01_erp_quote_idb', 'type' => 'id'),
  ),
  'indices' =>
  array (
    0 => array (
      'name' => 'idx_bd01_erp_quote_accounts_pk',
      'type' => 'primary',
      'fields' => array (0 => 'id'),
    ),
    1 => array (
      'name' => 'idx_bd01_erp_qt_acc_ida1_deleted',
      'type' => 'index',
      'fields' => array (0 => 'bd01_erp_quote_accountsaccounts_ida', 1 => 'deleted'),
    ),
    2 => array (
      'name' => 'idx_bd01_erp_qt_acc_idb2_deleted',
      'type' => 'index',
      'fields' => array (0 => 'bd01_erp_quote_accountsbd01_erp_quote_idb', 1 => 'deleted'),
    ),
    3 => array (
      'name' => 'bd01_erp_quote_accounts_alt',
      'type' => 'alternate_key',
      'fields' => array (0 => 'bd01_erp_quote_accountsbd01_erp_quote_idb'),
    ),
  ),
);
