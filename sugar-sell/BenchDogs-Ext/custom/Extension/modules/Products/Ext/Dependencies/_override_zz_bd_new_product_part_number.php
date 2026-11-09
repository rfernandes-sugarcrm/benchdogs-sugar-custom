<?php

// A new product that is not in the catalog takes the Part Number the seller types, once its Product Group is picked
// and until the ERP holds the line; every other line keeps ERP-Core's read-only Part Number. Where ERP-Core's rule is
// absent this changes nothing, and it rewrites that one rule rather than adding a second rule on the same field.
if (isset($dependencies['Products']['erp_part_number_read_only']['actions'])
    && is_array($dependencies['Products']['erp_part_number_read_only']['actions'])
) {
    foreach ($dependencies['Products']['erp_part_number_read_only']['actions'] as $bdPartKey => $bdPartAction) {
        if (($bdPartAction['name'] ?? '') === 'ReadOnly'
            && ($bdPartAction['params']['target'] ?? '') === 'mft_part_num'
        ) {
            $dependencies['Products']['erp_part_number_read_only']['actions'][$bdPartKey]['params']['value']
                = 'or(not(equal($product_template_id, "")), equal($category_id, ""), not(equal($erp_sync_key, "")))';
        }
    }
    $dependencies['Products']['erp_part_number_read_only']['triggerFields'] = array_values(array_unique(array_merge(
        (array) ($dependencies['Products']['erp_part_number_read_only']['triggerFields'] ?? array()),
        array('product_template_id', 'category_id', 'erp_sync_key')
    )));
    unset($bdPartKey, $bdPartAction);
}
