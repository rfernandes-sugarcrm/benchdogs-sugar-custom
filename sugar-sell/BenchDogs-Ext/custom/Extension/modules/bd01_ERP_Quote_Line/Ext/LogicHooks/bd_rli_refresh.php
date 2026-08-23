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

/**
 * Same materialization, fired by the LINK rather than the save.
 *
 * The connector creates a quote line and links it to its ERP quote in two
 * separate calls, so the after_save above runs before the line has a parent
 * and can do nothing. Without this second registration the deliverable RLIs
 * stay at whatever the header reflection guessed from a line-less quote -
 * see BdRliRefreshHook::refreshOnLink for the live account.
 */
$hook_array['after_relationship_add'][] = array(
    2,
    'Refresh deliverable revenue line items when a line is linked to its ERP quote',
    'custom/modules/bd01_ERP_Quote_Line/BdRliRefreshHook.php',
    'BdRliRefreshHook',
    'refreshOnLink',
);
