<?php

/** Bench Dogs: the quote line's Sales Category, a picker of ADM's sales categories (BdSalesCategories, published by the Bench connector extension as ERP_LookupValues). Sent to Kinetic as SalesCatID by erp_adm_quote_line_updates. */

// The name is the one the Bench connector extension reads and writes (Products.e_sales_category_c <-> QuoteDtl.SalesCatID).
$dictionary['Product']['fields']['e_sales_category_c'] = array(
    'name' => 'e_sales_category_c',
    'vname' => 'LBL_E_SALES_CATEGORY_C',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::salesCategoryOptions',
    ),
    'comment' => 'ERP QuoteDtl.SalesCatID: an ADM sales category code the seller picks',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
    // Never pre-picked by the browser, as the other ADM pickers.
    'defaultToBlank' => true,
);
