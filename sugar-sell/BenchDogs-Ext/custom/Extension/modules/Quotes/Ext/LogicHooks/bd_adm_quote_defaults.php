<?php

/**
 * G380 / G381 (🔒 1705b, 🔒 1724b): on an ADM quote not yet sent to the ERP, fill
 * an EMPTY Reference (ERP-Epicor's generic erp_reference) from the ship-to's
 * city and state, and an EMPTY Project from the product-group default list.
 * Never overwrites a value; another company's quote is untouched. Runs on every
 * Quote save, so it exits before loading any record for a quote it cannot
 * touch. See BdAdmRules::applyDefaults().
 */
$hook_array['before_save'][] = array(
    95,
    'Bench Dogs: ADM quote defaults (Reference, Project)',
    'custom/modules/Quotes/BdAdmRules.php',
    'BdAdmRules',
    'beforeSave',
);
