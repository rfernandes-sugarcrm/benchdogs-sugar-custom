<?php

// G380 (d) / 🔒 1724b — the quote's ERP reference, A generic field. (G606, G608, G530)
$dictionary['Quote']['fields']['erp_reference'] = array(
    'name'            => 'erp_reference',
    'vname'           => 'LBL_ERP_REFERENCE',
    'type'            => 'varchar',
    'len'             => 50,
    // G530: what Epicor accepts in QuoteHed.Reference (see above).
    'erp_max_length'  => 10,
    'required'        => false,
    'comment'         => 'Epicor QuoteHed.Reference: the customer RFQ number or other reference, sent by core on Send to Estimation',
    'reportable'      => true,
    'audited'         => true,
    'importable'      => 'true',
    'massupdate'      => false,
);
