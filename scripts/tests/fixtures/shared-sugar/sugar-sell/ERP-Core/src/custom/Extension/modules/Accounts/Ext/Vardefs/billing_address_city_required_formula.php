<?php

// Required of a Customer only until the ERP holds it (an ERP customer may have no bill-to); the order check reads it.
$dictionary['Account']['fields']['billing_address_city']['required'] = true;
$dictionary['Account']['fields']['billing_address_city']['required_formula']
    = 'and(equal($account_type,"Customer"),equal($erp_display_sync_key,""))';
// No "(Required)" text on the form: Sugar's own flag; a Save without it still turns the field red.
$dictionary['Account']['fields']['billing_address_city']['no_required_placeholder'] = true;
