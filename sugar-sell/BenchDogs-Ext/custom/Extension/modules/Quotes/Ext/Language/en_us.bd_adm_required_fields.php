<?php

// The words ADM's own refusal uses, so the seller matches field to message.
// (Reference is ERP-Epicor's generic erp_reference, labelled there - 🔒 1724b.)
$mod_strings['LBL_BD_LEAD_SOURCE'] = 'Lead Source';
$mod_strings['LBL_BD_LEAD_TYPE'] = 'Lead Type';
$mod_strings['LBL_BD_PROJECT_ID'] = 'Project';
// G460: ADM's own words ("A valid Marketing Campaign is required", "A valid
// Marketing Event is required").
// G578: "Marketing Campaign" was cut to "Marketing Ca..." on the Quotes ERP panel
// (benchdogs-dev ADVANCED QUOTE smoke 2026-09-25), while "Marketing Event" showed
// in full beside it. "Mktg Campaign" is narrower than "Marketing Event" (13 px
// Helvetica/Arial: 91.0 vs 93.9; "Marketing Campaign" 119.9), so it fits the same
// label cell, and it still reads as ADM's word. The key is unchanged: the viewdef
// entry ERP-Core's ErpLayoutExtraFields placed carries it, so an upgraded tenant
// gets the new text through this language extension.
$mod_strings['LBL_BD_MARKETING_CAMPAIGN'] = 'Mktg Campaign';
$mod_strings['LBL_BD_MARKETING_EVENT'] = 'Marketing Event';
