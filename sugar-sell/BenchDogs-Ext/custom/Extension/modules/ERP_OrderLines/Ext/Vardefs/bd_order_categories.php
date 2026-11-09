<?php

/** Bench Dogs: the order line's two read-only categories, kept equal to Kinetic's OrderDtl by the Bench connector extension (erp_adm_order_lines). Read-only in Sugar: Kinetic is the only writer. Sales category is the ADM sales category picker's values (BdSalesCategories); product category is ADM's product group values (BdProductCategories). */

$dictionary['ERP_OrderLines']['fields']['e_sales_category_c'] = array(
    'name' => 'e_sales_category_c',
    'vname' => 'LBL_E_SALES_CATEGORY_C',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::salesCategoryOptions',
    ),
    'comment' => 'ERP OrderDtl.SalesCatID, read-only: written by Kinetic through the Bench connector extension',
    'reportable' => true,
    'audited' => false,
    'massupdate' => false,
    'readonly' => true,
);

$dictionary['ERP_OrderLines']['fields']['e_product_category_c'] = array(
    'name' => 'e_product_category_c',
    'vname' => 'LBL_E_PRODUCT_CATEGORY_C',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::productCategoryOptions',
    ),
    'comment' => 'ERP OrderDtl.ProdCode (product group), read-only: written by Kinetic through the Bench connector extension',
    'reportable' => true,
    'audited' => false,
    'massupdate' => false,
    'readonly' => true,
);
