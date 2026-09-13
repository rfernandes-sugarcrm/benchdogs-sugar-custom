<?php

/**
 * Exact Kinetic estimating-completion facts. Neither field has a default:
 * absent means the source did not answer, not "not quoted" or an empty date.
 * BdQuoteReflectionHook owns the customer lifecycle policy that consumes them.
 */
$dictionary['bd01_ERP_Quote']['fields']['quoted'] = array(
    'name' => 'quoted',
    'vname' => 'LBL_BD_ERP_QUOTED',
    'type' => 'bool',
    'comment' => 'Kinetic QuoteHed.Quoted completion fact; null remains unknown',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
);

$dictionary['bd01_ERP_Quote']['fields']['date_quoted'] = array(
    'name' => 'date_quoted',
    'vname' => 'LBL_BD_ERP_DATE_QUOTED',
    'type' => 'datetime',
    'comment' => 'Kinetic QuoteHed.DateQuoted business completion date; null remains unknown',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
);
