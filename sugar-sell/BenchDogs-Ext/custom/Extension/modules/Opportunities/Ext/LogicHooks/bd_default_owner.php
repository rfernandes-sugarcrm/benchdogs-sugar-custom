<?php

/** An Opportunity saved with no owner takes the default owner for ERP-created records, when one is set; an owner is never changed. */
$hook_array['before_save'][] = array(
    10,
    'Bench Dogs: an empty owner takes the default owner for ERP-created records',
    null,
    'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdDefaultOwner',
    'beforeSave',
);
