<?php

/**
 * Put bd_shipped_value_total on the ERP_Orders record view.
 *
 * Sits immediately beside order_total in ORDER DETAILS on purpose: the
 * shipped figure only means anything read AGAINST the ordered one, and a
 * partially shipped order is otherwise indistinguishable from an unshipped
 * one on screen (Epicor leaves the order status 'Open' either way, so the
 * status badge cannot carry it).
 *
 * Panel found BY NAME and no-op without base viewdefs, for the same reasons
 * as ERP_OrderLines::bd_shipped_value.
 */

$bdModule = 'ERP_Orders';
$bdPanel  = 'LBL_RECORDVIEW_PANEL_ORDER_DETAIL';

if (!empty($viewdefs[$bdModule]['base']['view']['record']['panels'])) {
    foreach ($viewdefs[$bdModule]['base']['view']['record']['panels'] as $bdI => $bdP) {
        if (empty($bdP['name']) || $bdP['name'] !== $bdPanel) {
            continue;
        }
        $bdSeen = false;
        foreach ((array) ($bdP['fields'] ?? array()) as $bdF) {
            $bdName = is_array($bdF) ? ($bdF['name'] ?? '') : $bdF;
            if ($bdName === 'bd_shipped_value_total') {
                $bdSeen = true;
                break;
            }
        }
        if (!$bdSeen) {
            $viewdefs[$bdModule]['base']['view']['record']['panels'][$bdI]['fields'][] = array(
                'name' => 'bd_shipped_value_total',
                'label' => 'LBL_BD_SHIPPED_VALUE_TOTAL',
                'readonly' => true,
                'related_fields' => array('currency_id', 'base_rate'),
            );
        }
        break;
    }
}
unset($bdModule, $bdPanel, $bdI, $bdP, $bdF, $bdName, $bdSeen);
