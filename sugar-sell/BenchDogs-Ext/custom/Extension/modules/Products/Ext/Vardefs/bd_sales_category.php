<?php

/** Bench Dogs: the quote line's Sales Category, a relate to ERP_LookupValues (SalesCategories), filtered by the line's ERP company. The Bench connector extension writes the lookup id for Kinetic's SalesCatID. */

$dictionary['Product']['fields']['erp_products_sales_cat'] = array(
    'name' => 'erp_products_sales_cat',
    'type' => 'link',
    'relationship' => 'erp_products_sales_cat',
    'source' => 'non-db',
    'module' => 'ERP_LookupValues',
    'bean_name' => 'ERP_LookupValue',
    'side' => 'right',
    'vname' => 'LBL_ERP_PRODUCTS_SALES_CAT_TITLE',
    'id_name' => 'erp_products_sales_catlookupvalues_idb',
    'link-type' => 'one',
);

$dictionary['Product']['fields']['e_sales_category_c'] = array(
    'name' => 'e_sales_category_c',
    'type' => 'relate',
    'source' => 'non-db',
    'vname' => 'LBL_E_SALES_CATEGORY_C',
    'save' => true,
    'id_name' => 'erp_products_sales_catlookupvalues_idb',
    'link' => 'erp_products_sales_cat',
    'table' => 'erp_lookupvalues',
    'module' => 'ERP_LookupValues',
    'rname' => 'name',
    'prefilter_field' => 'erp_companies_products_name',
    'prefilter_conditions' => '[{"type":{"$in":["SalesCategories"]}},{"is_active":{"$equals":"1"}}]',
);

$dictionary['Product']['fields']['erp_products_sales_catlookupvalues_idb'] = array(
    'name' => 'erp_products_sales_catlookupvalues_idb',
    'type' => 'id',
    'source' => 'non-db',
    'vname' => 'LBL_ERP_PRODUCTS_SALES_CAT_ID',
    'id_name' => 'erp_products_sales_catlookupvalues_idb',
    'link' => 'erp_products_sales_cat',
    'table' => 'erp_lookupvalues',
    'module' => 'ERP_LookupValues',
    'rname' => 'id',
    'reportable' => false,
    'side' => 'right',
    'massupdate' => false,
    'duplicate_merge' => 'disabled',
    'hideacl' => true,
);
