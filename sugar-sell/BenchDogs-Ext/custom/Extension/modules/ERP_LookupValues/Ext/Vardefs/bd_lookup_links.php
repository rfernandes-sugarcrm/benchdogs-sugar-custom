<?php

// Bench Dogs: the lookup side of the quote line's and order line's Sales Category links (see metadata/). Like ERP-Core's erp_quotes_fob, a relationship is declared as a link on BOTH modules: without the lhs link Sugar saves no join row and reads the relate back empty.
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
