<?php

// G532: both labels must read in full at the record view's label width. On the
// two-column Business Card, "Customer Group" and "Customer Group Code" were both
// cut to "Customer Gro..." (benchdogs-sandbox, r7, 2026-09-24), so the pair looked
// identical. About 12 characters fit before the ellipsis, so each label is kept
// to 11 or fewer. The keys stay as G507 named them: the placed viewdef entries
// carry these keys, so a label change here reaches an upgraded tenant.
$mod_strings['LBL_BD_CUSTOMER_GROUP_CODE'] = 'Group Code';
$mod_strings['LBL_BD_CUSTOMER_GROUP'] = 'Cust. Group';
