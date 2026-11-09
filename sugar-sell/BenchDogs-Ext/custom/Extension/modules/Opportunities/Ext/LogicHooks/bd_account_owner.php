<?php

/** A new Opportunity its creator saves as their own, or with no owner, takes its account's owner; a person's own pick stands. */
$hook_array['before_save'][] = array(
    20,
    'Bench Dogs: a new record takes its account owner',
    null,
    'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAccountOwner',
    'beforeSave',
);
