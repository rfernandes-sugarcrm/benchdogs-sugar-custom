<?php

// Read-only in the record view: the viewdef declares it (a vardef readonly alone is not honoured by Sidecar).
foreach ($viewdefs['ERP_OrderLines']['base']['view']['record']['panels'] as $i => $panel) {
    if (($panel['name'] ?? '') === 'LBL_RECORDVIEW_PANEL_LINE_ITEM_DETAIL') {
        $viewdefs['ERP_OrderLines']['base']['view']['record']['panels'][$i]['fields'][] =
            array('name' => 'e_sales_category_c', 'readonly' => true);
        $viewdefs['ERP_OrderLines']['base']['view']['record']['panels'][$i]['fields'][] =
            array('name' => 'e_product_category_c', 'readonly' => true);
    }
}
