<?php
// Bench Dogs: a quote line's ERP company, one company to many lines. The company id is stored on the line (see the calculated field in Extension/modules/Products/Ext/Vardefs/bd_quote_line_company.php).
$dictionary["erp_companies_products"] = array (
  'true_relationship_type' => 'one-to-many',
  'relationships' =>
  array (
    'erp_companies_products' =>
    array (
      'lhs_module' => 'ERP_Companies',
      'lhs_table' => 'erp_companies',
      'lhs_key' => 'id',
      'rhs_module' => 'Products',
      'rhs_table' => 'products',
      'rhs_key' => 'erp_companies_productserp_companies_ida',
      'relationship_type' => 'one-to-many',
    ),
  ),
);
