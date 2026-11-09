<?php

/** Each quote line's own ADM project (Kinetic QuoteDtl.ProjectID, and OrderDtl.ProjectID when it is ordered): parts on one quote can be for different projects. */
$dictionary['Product']['fields']['bd_project_id'] = array(
    'name' => 'bd_project_id',
    'vname' => 'LBL_BD_PROJECT_ID',
    'type' => 'enum',
    'len' => 50,
    'function' => array(
        'name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::projectOptions',
    ),
    'comment' => 'Kinetic QuoteDtl.ProjectID of this line: an ADM project, blank until someone picks one',
    'reportable' => true,
    'audited' => true,
    'massupdate' => false,
    // Never pre-picked by the browser: the project codes would list before the blank option.
    'defaultToBlank' => true,
);
