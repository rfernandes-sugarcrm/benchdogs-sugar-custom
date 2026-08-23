<?php

/**
 * Queue a Contact for the Bench Dogs Epicor contact sync (before_save stamps
 * bd_erp_sync_requested_at; the extension container's bd_contact_sync
 * write-back sweeps stamped rows and creates the Erp.BO.CustCnt).
 *
 * Registration only - the class lives at custom/modules/Contacts/ (outside
 * this Ext/ tree) on purpose: Sugar's Ext-merge concatenates this file's raw
 * content into logichooks.ext.php while LogicHook::loadHookClass() separately
 * require_once()s the file named below, and a class defined here would be
 * declared twice ("Cannot redeclare class" - see ERP-Core's
 * order_stage_opportunity_cascade.php for the full story).
 */
$hook_array['before_save'][] = array(
    1,
    'Sticky bd_erp_synced flag: set on connector success stamp, never cleared',
    'custom/modules/Contacts/BdContactSyncHook.php',
    'BdContactSyncHook',
    'stickySyncedFlag',
);
$hook_array['before_save'][] = array(
    2,
    'Stamp Contacts of ERP-linked accounts for the Epicor contact sync',
    'custom/modules/Contacts/BdContactSyncHook.php',
    'BdContactSyncHook',
    'stampSyncRequest',
);
