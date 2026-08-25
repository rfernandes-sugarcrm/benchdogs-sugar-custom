<?php

// Kinetic line xref for the native quoted line items. The ORDER decision no
// longer lives here: since ERP-Epicor 1.0.84 the seller ticks rows in the
// grid and presses its Order Selected Lines, and the lock is ERP-Epicor's own
// erp_ordered / erp_ordered_order_num / erp_ordered_at (BdQuoteReflectionHook
// stamps the same three for orders raised in Kinetic). bd_to_order and
// bd_ordered were retired in 0.9.39; their columns stay in the table.

$dictionary['Product']['fields']['bd_erp_line_num'] = array(
    'name' => 'bd_erp_line_num',
    'vname' => 'LBL_BD_ERP_LINE_NUM',
    'type' => 'int',
    'len' => 11,
    'comment' => 'Kinetic quote line number this quoted line item mirrors',
    'reportable' => true,
);
