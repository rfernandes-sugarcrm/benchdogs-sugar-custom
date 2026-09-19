<?php

// RETIRED - nothing writes this field. Decision 29 (2026-09-13): the governing
// selection is an explicit flag a person sets in Sugar, read fail-closed by
// ErpQuoteOpportunityContribution. Since decisions 901/903 retired the quote
// mirror that flag is `erp_governing` on the NATIVE Sugar quote line. No
// Quote-level label is derived from it, and no Kinetic header or UD01 field
// is read. Kept rather than deleted: an upgrade never removes a copied vardef
// file (BD-L-0005), and the column may still hold old values. Not placed on any
// Bench layout or dashboard (BdQuotesLayoutExtensions::RETIRED_PANEL_FIELDS),
// and hidden from Studio so it is not re-placed by hand.
$dictionary['Quote']['fields']['bd_governing_line'] = array(
    'name' => 'bd_governing_line',
    'vname' => 'LBL_BD_GOVERNING_LINE',
    'type' => 'varchar',
    'len' => 255,
    'comment' => 'Retired: no writer (decision 29); kept for existing data',
    'reportable' => true,
    'studio' => false,
);
