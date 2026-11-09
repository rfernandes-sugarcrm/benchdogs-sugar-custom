<?php

// Bench Dogs: the ProductCategories side of the order line's Product Category link (see metadata/erp_orderlines_prodcatMetaData.php).
$dictionary['ProductCategory']['fields']['erp_orderlines_prodcat'] = array(
    'name' => 'erp_orderlines_prodcat',
    'type' => 'link',
    'relationship' => 'erp_orderlines_prodcat',
    'source' => 'non-db',
    'module' => 'ERP_OrderLines',
    'bean_name' => 'ERP_OrderLine',
    'side' => 'left',
    'id_name' => 'erp_orderlines_prodcatproductcategories_idb',
    'link-type' => 'many',
);
