<?php

// Accounts "Create Opportunity & Quote" header button.
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_BUTTON'] = 'Create Opportunity & Quote';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_RUNNING'] = 'Creating the opportunity and its quote...';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_ERROR'] = 'Could not create the opportunity and quote.';

// G223, the message half.
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_TITLE'] = 'Ship To not set';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_NONE'] =
    '{{account}} has no active shipping address — add one on the account, then choose it on the quote.';
// G297 — these two no longer name an action the seller cannot take. (G218, G286, G311)
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_ONE_NOT_DEFAULT'] =
    '{{account}} has 1 shipping address and the ERP does not name it as the default '
    . '— choose one on the quote.';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_NO_PRIMARY'] =
    '{{account}} has {{count}} shipping addresses and the ERP names no default among them '
    . '— choose one on the quote.';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_MANY_PRIMARY'] =
    '{{account}} has {{count}} shipping addresses and the ERP names more than one of them as its default '
    . '— choose one on the quote.';
$mod_strings['LBL_ERP_CREATE_OPP_QUOTE_SHIP_TO_EMPTY'] =
    '{{account}}: no shipping address was chosen for this quote — choose one on the quote.';
