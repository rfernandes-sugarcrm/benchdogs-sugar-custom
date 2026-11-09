<?php

/** Bench Dogs: the order line's two read-only categories, Sales Category relates to ERP_LookupValues (BdSalesCategories, filtered by the line's ERP company) and Product Category to the standard ProductCategories module ERP Core syncs. The Bench connector extension writes the lookup ids from Kinetic's OrderDtl. Read-only in Sugar: Kinetic is the only writer. */

$dictionary['ERP_OrderLine']['fields']['erp_orderlines_sales_cat'] = array(
    'name' => 'erp_orderlines_sales_cat',
    'type' => 'link',
    'relationship' => 'erp_orderlines_sales_cat',
    'source' => 'non-db',
    'module' => 'ERP_LookupValues',
    'bean_name' => 'ERP_LookupValue',
    'side' => 'right',
    'vname' => 'LBL_ERP_ORDERLINES_SALES_CAT_TITLE',
    'id_name' => 'erp_orderlines_sales_catlookupvalues_idb',
    'link-type' => 'one',
);

$dictionary['ERP_OrderLine']['fields']['e_sales_category_c'] = array(
    'name' => 'e_sales_category_c',
    'type' => 'relate',
    'source' => 'non-db',
    'vname' => 'LBL_E_SALES_CATEGORY_C',
    'save' => true,
    'id_name' => 'erp_orderlines_sales_catlookupvalues_idb',
    'link' => 'erp_orderlines_sales_cat',
    'table' => 'erp_lookupvalues',
    'module' => 'ERP_LookupValues',
    'rname' => 'name',
    'readonly' => true,
    'prefilter_field' => 'erp_companies_erp_orderlines_name',
    'prefilter_conditions' => '[{"type":{"$in":["BdSalesCategories"]}},{"is_active":{"$equals":"1"}}]',
);

$dictionary['ERP_OrderLine']['fields']['erp_orderlines_sales_catlookupvalues_idb'] = array(
    'name' => 'erp_orderlines_sales_catlookupvalues_idb',
    'type' => 'id',
    'source' => 'non-db',
    'vname' => 'LBL_ERP_ORDERLINES_SALES_CAT_ID',
    'id_name' => 'erp_orderlines_sales_catlookupvalues_idb',
    'link' => 'erp_orderlines_sales_cat',
    'table' => 'erp_lookupvalues',
    'module' => 'ERP_LookupValues',
    'rname' => 'id',
    'reportable' => false,
    'side' => 'right',
    'massupdate' => false,
    'duplicate_merge' => 'disabled',
    'hideacl' => true,
);

$dictionary['ERP_OrderLine']['fields']['erp_orderlines_prodcat'] = array(
    'name' => 'erp_orderlines_prodcat',
    'type' => 'link',
    'relationship' => 'erp_orderlines_prodcat',
    'source' => 'non-db',
    'module' => 'ProductCategories',
    'bean_name' => 'ProductCategory',
    'side' => 'right',
    'vname' => 'LBL_ERP_ORDERLINES_PRODCAT_TITLE',
    'id_name' => 'erp_orderlines_prodcatproductcategories_idb',
    'link-type' => 'one',
);

$dictionary['ERP_OrderLine']['fields']['e_product_category_c'] = array(
    'name' => 'e_product_category_c',
    'type' => 'relate',
    'source' => 'non-db',
    'vname' => 'LBL_E_PRODUCT_CATEGORY_C',
    'save' => true,
    'id_name' => 'erp_orderlines_prodcatproductcategories_idb',
    'link' => 'erp_orderlines_prodcat',
    'table' => 'product_categories',
    'module' => 'ProductCategories',
    'rname' => 'name',
    'readonly' => true,
);

$dictionary['ERP_OrderLine']['fields']['erp_orderlines_prodcatproductcategories_idb'] = array(
    'name' => 'erp_orderlines_prodcatproductcategories_idb',
    'type' => 'id',
    'source' => 'non-db',
    'vname' => 'LBL_ERP_ORDERLINES_PRODCAT_ID',
    'id_name' => 'erp_orderlines_prodcatproductcategories_idb',
    'link' => 'erp_orderlines_prodcat',
    'table' => 'product_categories',
    'module' => 'ProductCategories',
    'rname' => 'id',
    'reportable' => false,
    'side' => 'right',
    'massupdate' => false,
    'duplicate_merge' => 'disabled',
    'hideacl' => true,
);
