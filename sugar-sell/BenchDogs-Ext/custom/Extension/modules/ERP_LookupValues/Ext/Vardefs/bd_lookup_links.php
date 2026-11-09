<?php

// Bench Dogs: the lookup side of the quote line and order line category links (see metadata/).
$dictionary['ERP_LookupValue']['fields']['erp_products_sales_cat'] = array(
    'name' => 'erp_products_sales_cat',
    'type' => 'link',
    'relationship' => 'erp_products_sales_cat',
    'source' => 'non-db',
    'module' => 'Products',
    'bean_name' => 'Product',
    'side' => 'left',
    'id_name' => 'erp_products_sales_catlookupvalues_idb',
    'link-type' => 'many',
);
$dictionary['ERP_LookupValue']['fields']['erp_orderlines_sales_cat'] = array(
    'name' => 'erp_orderlines_sales_cat',
    'type' => 'link',
    'relationship' => 'erp_orderlines_sales_cat',
    'source' => 'non-db',
    'module' => 'ERP_OrderLines',
    'bean_name' => 'ERP_OrderLine',
    'side' => 'left',
    'id_name' => 'erp_orderlines_sales_catlookupvalues_idb',
    'link-type' => 'many',
);
$dictionary['ERP_LookupValue']['fields']['erp_orderlines_product_cat'] = array(
    'name' => 'erp_orderlines_product_cat',
    'type' => 'link',
    'relationship' => 'erp_orderlines_product_cat',
    'source' => 'non-db',
    'module' => 'ERP_OrderLines',
    'bean_name' => 'ERP_OrderLine',
    'side' => 'left',
    'id_name' => 'erp_orderlines_product_catlookupvalues_idb',
    'link-type' => 'many',
);
