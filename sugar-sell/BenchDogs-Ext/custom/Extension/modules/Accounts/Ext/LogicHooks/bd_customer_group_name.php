<?php

/** G804 (🔒 2081b): on an Account not yet in the ERP, the readable Cust. Group follows the Group Code the seller picked. */
$hook_array['before_save'][] = array(
    96,
    'Bench Dogs: ADM Cust. Group name follows the picked Group Code',
    null,
    'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules',
    'accountBeforeSave',
);
