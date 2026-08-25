<?php

/**
 * Put bd_shipped_value on the ERP_OrderLines record view.
 *
 * The field shipped as a vardef with no layout entry at all, so it held a
 * correct value that no screen could show - measured live on order 9571,
 * where bd_shipped_value was 3200.00 while every record view rendered
 * nothing. A field nobody can see is indistinguishable from a field that
 * was never written.
 *
 * The panel is found BY NAME, never by index: ERP-Core owns this layout and
 * reorders it between releases, and a hardcoded index would silently drop
 * the field into whichever panel happened to sit there. If the base viewdefs
 * are not loaded, this is a no-op rather than a fabricated panel.
 */

$bdModule = 'ERP_OrderLines';
$bdPanel  = 'LBL_RECORDVIEW_PANEL_LINE_ITEM_DETAIL';

if (!empty($viewdefs[$bdModule]['base']['view']['record']['panels'])) {
    foreach ($viewdefs[$bdModule]['base']['view']['record']['panels'] as $bdI => $bdP) {
        if (empty($bdP['name']) || $bdP['name'] !== $bdPanel) {
            continue;
        }
        // Never add it twice - a repeated Ext compile would render the field
        // once per pass.
        $bdSeen = false;
        foreach ((array) ($bdP['fields'] ?? array()) as $bdF) {
            $bdName = is_array($bdF) ? ($bdF['name'] ?? '') : $bdF;
            if ($bdName === 'bd_shipped_value') {
                $bdSeen = true;
                break;
            }
        }
        if (!$bdSeen) {
            $viewdefs[$bdModule]['base']['view']['record']['panels'][$bdI]['fields'][] = array(
                'name' => 'bd_shipped_value',
                'label' => 'LBL_BD_SHIPPED_VALUE',
                // Fulfilment is reflected FROM the ERP; typing over it in
                // Sugar would be overwritten on the next sync.
                'readonly' => true,
                'related_fields' => array('currency_id', 'base_rate'),
            );
        }
        break;
    }
}
unset($bdModule, $bdPanel, $bdI, $bdP, $bdF, $bdName, $bdSeen);
