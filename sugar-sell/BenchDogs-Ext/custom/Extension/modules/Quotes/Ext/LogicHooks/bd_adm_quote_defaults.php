<?php

/** On an ADM quote not yet in the ERP, fill an empty Reference from the ship-to; on a new quote, also an empty Lead Source, Lead Type and Campaign + Event pair from the account's newest quotes. */
$hook_array['before_save'][] = array(
    95,
    'Bench Dogs: ADM quote defaults (Reference, account history)',
    null,
    'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules',
    'beforeSave',
);
