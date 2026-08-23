<?php

// Native-line partial ordering (1:1 CRM quote <-> ERP quote): the quoted
// line items themselves carry the order decision - tick bd_to_order on the
// break(s) the customer committed to, bd-order-selected-lines raises the
// Epicor order from ONLY those rows, and bd_ordered records which rows have
// already turned into orders (they stay on the quote as history; nothing is
// deleted). bd_erp_line_num is the xref to the Kinetic quote line.

$dictionary['Product']['fields']['bd_to_order'] = array(
    'name' => 'bd_to_order',
    'vname' => 'LBL_BD_TO_ORDER',
    'type' => 'bool',
    'default' => '0',
    'comment' => 'Line is selected to go to the next Epicor sales order',
    'reportable' => true,
    'audited' => true,
);

$dictionary['Product']['fields']['bd_ordered'] = array(
    'name' => 'bd_ordered',
    'vname' => 'LBL_BD_ORDERED',
    'type' => 'bool',
    'default' => '0',
    'comment' => 'Line has already been ordered in Epicor',
    'reportable' => true,
    'audited' => true,
);

$dictionary['Product']['fields']['bd_erp_line_num'] = array(
    'name' => 'bd_erp_line_num',
    'vname' => 'LBL_BD_ERP_LINE_NUM',
    'type' => 'int',
    'len' => 11,
    'comment' => 'Kinetic quote line number this quoted line item mirrors',
    'reportable' => true,
);
