<?php

/** A person saving an account in the edit views must give its billing street, city, postal code and country, which Kinetic needs; a server save (the accounts sync) is never refused for them. */
$dependencies['Accounts']['bd_billing_address_required'] = array(
    'hooks' => array('edit'),
    'trigger' => 'true',
    'triggerFields' => array(
        'billing_address_street',
        'billing_address_city',
        'billing_address_postalcode',
        'billing_address_country',
    ),
    'onload' => true,
    'actions' => array(
        array(
            'name' => 'SetRequired',
            'params' => array(
                'target' => 'billing_address_street',
                'label' => 'billing_address_street_label',
                'value' => 'true',
            ),
        ),
        array(
            'name' => 'SetRequired',
            'params' => array(
                'target' => 'billing_address_city',
                'label' => 'billing_address_city_label',
                'value' => 'true',
            ),
        ),
        array(
            'name' => 'SetRequired',
            'params' => array(
                'target' => 'billing_address_postalcode',
                'label' => 'billing_address_postalcode_label',
                'value' => 'true',
            ),
        ),
        array(
            'name' => 'SetRequired',
            'params' => array(
                'target' => 'billing_address_country',
                'label' => 'billing_address_country_label',
                'value' => 'true',
            ),
        ),
    ),
);
