<?php

/** Bench Dogs: the order line's two read-only categories, relates to ERP_LookupValues (SalesCategories and BdProductCategories), filtered by the line's ERP company. The Bench connector extension writes the lookup ids from Kinetic's OrderDtl. Read-only in Sugar: Kinetic is the only writer. */

$dictionary['ERP_OrderLines']['fields']['erp_orderlines_sales_cat'] = array(
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

$dictionary['ERP_OrderLines']['fields']['e_sales_category_c'] = array(
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
    'prefilter_conditions' => '[{"type":{"$in":["SalesCategories"]}},{"is_active":{"$equals":"1"}}]',
);

$dictionary['ERP_OrderLines']['fields']['erp_orderlines_sales_catlookupvalues_idb'] = array(
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

$dictionary['ERP_OrderLines']['fields']['erp_orderlines_product_cat'] = array(
    'name' => 'erp_orderlines_product_cat',
    'type' => 'link',
    'relationship' => 'erp_orderlines_product_cat',
    'source' => 'non-db',
    'module' => 'ERP_LookupValues',
    'bean_name' => 'ERP_LookupValue',
    'side' => 'right',
    'vname' => 'LBL_ERP_ORDERLINES_PRODUCT_CAT_TITLE',
    'id_name' => 'erp_orderlines_product_catlookupvalues_idb',
    'link-type' => 'one',
);

$dictionary['ERP_OrderLines']['fields']['e_product_category_c'] = array(
    'name' => 'e_product_category_c',
    'type' => 'relate',
    'source' => 'non-db',
    'vname' => 'LBL_E_PRODUCT_CATEGORY_C',
    'save' => true,
    'id_name' => 'erp_orderlines_product_catlookupvalues_idb',
    'link' => 'erp_orderlines_product_cat',
    'table' => 'erp_lookupvalues',
    'module' => 'ERP_LookupValues',
    'rname' => 'name',
    'readonly' => true,
    'prefilter_field' => 'erp_companies_erp_orderlines_name',
    'prefilter_conditions' => '[{"type":{"$in":["BdProductCategories"]}},{"is_active":{"$equals":"1"}}]',
);

$dictionary['ERP_OrderLines']['fields']['erp_orderlines_product_catlookupvalues_idb'] = array(
    'name' => 'erp_orderlines_product_catlookupvalues_idb',
    'type' => 'id',
    'source' => 'non-db',
    'vname' => 'LBL_ERP_ORDERLINES_PRODUCT_CAT_ID',
    'id_name' => 'erp_orderlines_product_catlookupvalues_idb',
    'link' => 'erp_orderlines_product_cat',
    'table' => 'erp_lookupvalues',
    'module' => 'ERP_LookupValues',
    'rname' => 'id',
    'reportable' => false,
    'side' => 'right',
    'massupdate' => false,
    'duplicate_merge' => 'disabled',
    'hideacl' => true,
);
