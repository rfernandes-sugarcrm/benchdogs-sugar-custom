<?php

// Bench Dogs: the company side of a quote line's company link (see metadata/erp_companies_productsMetaData.php).
$dictionary['ERP_Company']['fields']['erp_companies_products'] = array(
    'name' => 'erp_companies_products',
    'type' => 'link',
    'relationship' => 'erp_companies_products',
    'source' => 'non-db',
    'module' => 'Products',
    'bean_name' => 'Product',
    'side' => 'left',
    'id_name' => 'erp_companies_productserp_companies_ida',
    'link-type' => 'many',
);
