<?php

/**
 * Refresh the deliverable revenue line items when a Bench Dogs ERP quote
 * line's value or role changes (REQ-6): a reflected Kinetic revision
 * re-prices lines, and the RLIs must pick up the new values even when the
 * header reflection has nothing of its own to notice.
 *
 * Registration only - the class lives at custom/modules/bd01_ERP_Quote_Line/
 * (outside this Ext/ tree) for the same double-require reason documented in
 * bd_governing_line.php. Priority 2: runs after BdGoverningLineHook has
 * finished enforcing single-governing, so the roles this reads are settled.
 */
$hook_array['after_save'][] = array(
    2,
    'Refresh deliverable revenue line items on ERP quote line changes',
    'custom/modules/bd01_ERP_Quote_Line/BdRliRefreshHook.php',
    'BdRliRefreshHook',
    'refreshDeliverables',
);
