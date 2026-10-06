<?php

/** Hidden: what Sugar and the ERP last agreed for Lead Source, Lead Type, Campaign and Event (JSON), so the ERP read-back never overwrites a seller's edit. */
$dictionary['Quote']['fields']['bd_lead_baseline'] = array(
    'name' => 'bd_lead_baseline',
    'vname' => 'LBL_BD_LEAD_BASELINE',
    'type' => 'text',
    'comment' => 'Per lead field: the value both systems agreed and the last ERP value; stamped at the first Send to Estimation, rewritten by the ERP read-back',
    'audited' => false,
    'reportable' => false,
    'importable' => false,
    'massupdate' => false,
    'duplicate_on_record_copy' => 'no',
    'studio' => false,
);
