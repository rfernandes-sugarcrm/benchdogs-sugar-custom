<?php

/**
 * REQ-21, per line: shipped quantity x unit price on this order line.
 *
 * See ERP_Orders::bd_shipped_value_total for why the "(not invoiced)" in the
 * label is load-bearing rather than decorative.
 */

// The dictionary key is the BEAN OBJECT name, which is not always the
// module name - and ERP-Core does not publish its object names through
// any API this package can read (measured: /metadata returns fields but
// no object_name). So attach to whichever key the module's own vardefs
// actually created, rather than guessing one and shipping a field that
// silently never appears.
$bdShippedDef = array(
    'name' => 'bd_shipped_value',
    'vname' => 'LBL_BD_SHIPPED_VALUE',
    'type' => 'currency',
    'dbType' => 'currency',
    'len' => 26,
    'precision' => 6,
    'default' => 0.0,
    'comment' => 'shipped qty x unit price for this line - shipped, NOT invoiced',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    'inline_edit' => false,
    'convertToBase' => true,
    'related_fields' => array('currency_id', 'base_rate'),
);

foreach (array('ERP_OrderLines', 'ERP_OrderLine') as $bdObject) {
    if (isset($dictionary[$bdObject]['fields'])) {
        $dictionary[$bdObject]['fields']['bd_shipped_value'] = $bdShippedDef;
    }
}
unset($bdShippedDef, $bdObject);

