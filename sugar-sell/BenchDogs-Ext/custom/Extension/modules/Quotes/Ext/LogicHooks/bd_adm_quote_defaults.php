<?php

/**
 * G380 / G381 (🔒 1705b): on an ADM quote not yet sent to the ERP, fill an EMPTY
 * Reference from the ship-to's city and state, and an EMPTY Project from the
 * product-group default list. Never overwrites a value; another company's
 * quote is untouched. See BdAdmRules::applyDefaults().
 */
$hook_array['before_save'][] = array(
    95,
    'Bench Dogs: ADM quote defaults (Reference, Project)',
    'custom/modules/Quotes/BdAdmRules.php',
    'BdAdmRules',
    'beforeSave',
);
