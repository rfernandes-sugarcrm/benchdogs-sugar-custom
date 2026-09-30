<?php

/** G380 / G381 (🔒 1705b, 🔒 1724b): on an ADM quote not yet in the ERP, fill an empty Reference from the ship-to and an empty Project from the product-group default list. */
$hook_array['before_save'][] = array(
    95,
    'Bench Dogs: ADM quote defaults (Reference, Project)',
    'custom/modules/Quotes/BdAdmRules.php',
    'BdAdmRules',
    'beforeSave',
);
