<?php

/**
 * G804 (🔒 2081b): on an Account not yet in the ERP, the readable Cust. Group
 * (bd_customer_group) follows the Group Code the seller picked
 * (bd_customer_group_code). An Account the ERP holds is untouched: the Bench
 * connector extension writes both from Epicor. Reads nothing unless the code
 * changed. See BdAdmRules::accountBeforeSave().
 */
$hook_array['before_save'][] = array(
    96,
    'Bench Dogs: ADM Cust. Group name follows the picked Group Code',
    'custom/modules/Quotes/BdAdmRules.php',
    'BdAdmRules',
    'accountBeforeSave',
);
