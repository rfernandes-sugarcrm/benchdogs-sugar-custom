<?php

// Labels for the Bench Dogs custom Quote fields, plus the bd_erp_stage_list
// dropdown. A brand-new list defined inline in a module Language ext is the
// simple, proven mechanism (exact precedent: ERP-Core's
// en_us.erp_quote_type.php, which defines erp_quote_type_list the same way).

$mod_strings['LBL_BD_ERP_TOTAL'] = 'ERP Quote Total';
$mod_strings['LBL_BD_ERP_STAGE'] = 'ERP Quote Stage';
$mod_strings['LBL_BD_PRICED_AT'] = 'ERP Priced At';
$mod_strings['LBL_BD_REASON_CODE'] = 'ERP Reason Code';
$mod_strings['LBL_BD_GOVERNING_LINE'] = 'ERP Governing Line';
$mod_strings['LBL_BD_COMMENT_PENDING'] = 'ERP Comment Pending';
$mod_strings['LBL_BD_COMMENT_TEXT'] = 'ERP Comment Text';
$mod_strings['LBL_BD_ORDER_REQUESTED_AT'] = 'ERP Order Requested At';
$mod_strings['LBL_BD_COMMENT_REQUESTED_AT'] = 'ERP Comment Requested At';
$mod_strings['LBL_BD_PRINT_REQUESTED_AT'] = 'ERP Print Requested At';
$mod_strings['LBL_BD_PRINT_STATUS'] = 'ERP Print Status';
$mod_strings['LBL_BD_PRINT_LINK'] = 'ERP Print Link';
$mod_strings['LBL_RECORDVIEW_PANEL_BENCHDOGS'] = 'Bench Dogs ERP';

$app_list_strings['bd_erp_stage_list'] = array(
    '' => '',
    'draft' => 'Draft',
    'in_estimating' => 'In Estimating',
    'priced' => 'Priced',
    'revision' => 'Revision',
    'accepted' => 'Accepted',
    'ordered' => 'Ordered',
    'lost' => 'Lost',
);
