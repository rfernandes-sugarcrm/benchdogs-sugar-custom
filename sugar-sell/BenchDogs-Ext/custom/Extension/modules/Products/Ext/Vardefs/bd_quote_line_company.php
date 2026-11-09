<?php

/** Bench Dogs: the quote line's ERP company, a calculated copy of its quote's company. The pre-filter of the line's lookup relates reads it from the line itself. */

$dictionary['Product']['fields']['erp_companies_products'] = array(
    'name' => 'erp_companies_products',
    'type' => 'link',
    'relationship' => 'erp_companies_products',
    'source' => 'non-db',
    'module' => 'ERP_Companies',
    'bean_name' => 'ERP_Company',
    'side' => 'right',
    'vname' => 'LBL_ERP_COMPANIES_PRODUCTS_TITLE',
    'id_name' => 'erp_companies_productserp_companies_ida',
    'link-type' => 'one',
);

$dictionary['Product']['fields']['erp_companies_products_name'] = array(
    'name' => 'erp_companies_products_name',
    'type' => 'relate',
    'source' => 'non-db',
    'vname' => 'LBL_ERP_COMPANIES_PRODUCTS_TITLE',
    'id_name' => 'erp_companies_productserp_companies_ida',
    'link' => 'erp_companies_products',
    'table' => 'erp_companies',
    'module' => 'ERP_Companies',
    'rname' => 'name',
);

$dictionary['Product']['fields']['erp_companies_productserp_companies_ida'] = array(
    'name' => 'erp_companies_productserp_companies_ida',
    'type' => 'id',
    'len' => 36,
    'vname' => 'LBL_ERP_COMPANIES_PRODUCTS_ID',
    'reportable' => false,
    'massupdate' => false,
    'calculated' => true,
    'enforced' => true,
    'formula' => 'related($quotes, "erp_companies_quoteserp_companies_ida")',
    'duplicate_merge' => 'disabled',
    'hideacl' => true,
);
