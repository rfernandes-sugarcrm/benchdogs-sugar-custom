<?php

/** G809 (🔒 1724b): Reference is required in the browser on an ADM quote not yet in the ERP whose ship-to gives its default nothing to work with (G530). */
$dependencies['Quotes']['bd_adm_reference_required'] = array(
    'hooks' => array('edit'),
    'trigger' => 'true',
    'triggerFields' => array(
        'erp_display_sync_key',
        'bd_lead_source',
        'shipping_address_city',
        'shipping_address_state',
    ),
    'onload' => true,
    'actions' => array(
        array(
            'name' => 'SetRequired',
            'params' => array(
                'target' => 'erp_reference',
                'label' => 'erp_reference_label',
                'value' => 'and(equal($erp_display_sync_key,""),not(equal($bd_lead_source,"")),'
                    . 'equal($shipping_address_city,""),equal($shipping_address_state,""))',
            ),
        ),
    ),
);
