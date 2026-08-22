<?php

$dictionary['Quote']['fields']['bd_erp_stage'] = array(
    'name' => 'bd_erp_stage',
    'vname' => 'LBL_BD_ERP_STAGE',
    'type' => 'enum',
    'options' => 'bd_erp_stage_list',
    'len' => 100,
    'comment' => 'ERP quote stage reflected from the Bench Dogs ERP quote',
    'reportable' => true,
    'audited' => true,
);
