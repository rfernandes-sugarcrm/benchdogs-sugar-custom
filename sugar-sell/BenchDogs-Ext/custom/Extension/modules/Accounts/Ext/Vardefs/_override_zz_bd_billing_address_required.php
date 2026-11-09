<?php

// Billing street, city, postal code and country required on every browser save, the create form included. Only
// ERP-Core's formula changes: `required` stays ERP-Core's, so the hub's schema read is unchanged; State keeps its rule.
// An _override file merges after ERP-Core's vardefs whichever was installed last.
$dictionary['Account']['fields']['billing_address_street']['required_formula'] = 'true';
$dictionary['Account']['fields']['billing_address_city']['required_formula'] = 'true';
$dictionary['Account']['fields']['billing_address_postalcode']['required_formula'] = 'true';
$dictionary['Account']['fields']['billing_address_country']['required_formula'] = 'true';
