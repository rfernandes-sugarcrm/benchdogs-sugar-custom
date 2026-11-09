<?php

/** G758 (owner, with ruling 1956b) — A quote line's part number is never typed. (G559) */
if (isset($dependencies['Products']['read_only_fields']['actions'])
    && is_array($dependencies['Products']['read_only_fields']['actions'])
) {
    $erpStockReadOnlyActions = array();
    foreach ($dependencies['Products']['read_only_fields']['actions'] as $erpStockAction) {
        if (isset($erpStockAction['params']['target']) && $erpStockAction['params']['target'] === 'mft_part_num') {
            continue;
        }
        $erpStockReadOnlyActions[] = $erpStockAction;
    }
    $dependencies['Products']['read_only_fields']['actions'] = $erpStockReadOnlyActions;
    unset($erpStockReadOnlyActions, $erpStockAction);
}

$dependencies['Products']['erp_part_number_read_only'] = array(
    'hooks'          => array('all'),
    'trigger'        => 'true',
    'triggerFields'  => array('product_template_id', 'mft_part_num'),
    'onload'         => true,
    'notActions'     => array(),
    'actions'        => array(
        array(
            'name'   => 'ReadOnly',
            'params' => array(
                'target' => 'mft_part_num',
                'value'  => 'true',
            ),
        ),
    ),
);
