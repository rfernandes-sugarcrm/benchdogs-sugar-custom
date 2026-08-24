<?php

/**
 * REQ-21: what has left the building on this order and has not been invoiced.
 *
 * Shipped quantity x unit price, summed over the order's lines. That is NOT
 * an invoiced amount and must never be read as one, which is why the label
 * carries "(not invoiced)" - the whole point of the figure is the gap
 * between shipping and billing, and a label that hides the distinction turns
 * a useful number into a wrong one.
 *
 * Declared here because ERP_Orders has no spare currency field to borrow -
 * every currency-typed field it ships with is already in use, and the module
 * carries no _c custom fields at all. ERP-owned; the container extension
 * writes it on the erp_order_shipped_total step.
 */

// The dictionary key is the BEAN OBJECT name, which is not always the
// module name - and ERP-Core does not publish its object names through
// any API this package can read (measured: /metadata returns fields but
// no object_name). So attach to whichever key the module's own vardefs
// actually created, rather than guessing one and shipping a field that
// silently never appears.
$bdShippedDef = array(
    'name' => 'bd_shipped_value_total',
    'vname' => 'LBL_BD_SHIPPED_VALUE_TOTAL',
    'type' => 'currency',
    'dbType' => 'currency',
    'len' => 26,
    'precision' => 6,
    'default' => 0.0,
    'comment' => 'sum of shipped qty x unit price across this order lines - shipped, NOT invoiced',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    'inline_edit' => false,
    'convertToBase' => true,
    'related_fields' => array('currency_id', 'base_rate'),
);

foreach (array('ERP_Orders', 'ERP_Order') as $bdObject) {
    if (isset($dictionary[$bdObject]['fields'])) {
        $dictionary[$bdObject]['fields']['bd_shipped_value_total'] = $bdShippedDef;
    }
}
unset($bdShippedDef, $bdObject);

