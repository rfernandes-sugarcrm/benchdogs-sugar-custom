<?php

/** On an ADM quote not yet in the ERP, fill an empty Reference from the ship-to and an empty Project from the product-group default list; on a new quote, also fill an empty Lead Source, Lead Type, Project and Campaign + Event pair from the account's newest quotes. */
$hook_array['before_save'][] = array(
    95,
    'Bench Dogs: ADM quote defaults (Reference, Project, account history)',
    null,
    'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules',
    'beforeSave',
);
