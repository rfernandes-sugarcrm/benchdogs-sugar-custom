<?php

// Bench Dogs contact sync (Sugar Contacts -> Epicor CustCnt), extension-layer
// customization: the container's bd_contact_sync write-back sweeps ONLY rows
// whose stamp field below is set (delta_field-only selection over HTTP). The
// stamp is written by BdContactSyncHook (before_save) when the contact's
// account is ERP-linked.

$dictionary['Contact']['fields']['bd_erp_sync_requested_at'] = array(
    'name' => 'bd_erp_sync_requested_at',
    'vname' => 'LBL_BD_ERP_SYNC_REQUESTED_AT',
    'type' => 'datetime',
    'comment' => 'When this contact was last queued for Epicor contact sync',
    'reportable' => true,
);
