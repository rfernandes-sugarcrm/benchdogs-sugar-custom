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

// The account's Epicor CustNum, stamped by BdContactSyncHook alongside the
// sync-request stamp above. Carried on the contact so the extension's
// transform needs NO account lookup through core (the CLI pipeline path
// cannot serve extension callbacks - core's job registry is process-local
// to the serve process).
$dictionary['Contact']['fields']['bd_erp_custnum'] = array(
    'name' => 'bd_erp_custnum',
    'vname' => 'LBL_BD_ERP_CUSTNUM',
    'type' => 'varchar',
    'len' => 20,
    'comment' => 'Epicor CustNum of the linked account at stamp time',
    'reportable' => true,
    'audited' => false,
);

// Standard connector write-back stamp fields, declared exactly as ERP-Core
// declares them on Quotes/Accounts (same types, same options list). Without
// them core logs CRM_STAMP FAILED on every contact write; with them core
// stamps the created CustCnt's ConNum into erp_sync_key on success, which
// doubles as the extension's dedupe guard (a stamped contact is never
// re-created). Plain (non-unique) index: ConNum is only unique WITHIN a
// customer in Epicor.
$dictionary['Contact']['fields']['erp_sync_key'] = array(
    'name' => 'erp_sync_key',
    'is_sync_key' => true,
    'vname' => 'LBL_ERP_SYNC_KEY',
    'type' => 'varchar',
    'comment' => 'The id of the record from Epicor (CustCnt ConNum)',
    'default_value' => '',
    'max_size' => 255,
    'required' => false,
    'reportable' => true,
    'audited' => false,
    'importable' => 'true',
    'duplicate_merge' => false,
);
$dictionary['Contact']['indices'][] = array(
    'name' => 'idx_contacts_erp_sync_key',
    'type' => 'index',
    'fields' => array('erp_sync_key'),
);
$dictionary['Contact']['fields']['erp_writeback_status'] = array(
    'name' => 'erp_writeback_status',
    'vname' => 'LBL_ERP_WRITEBACK_STATUS',
    'type' => 'enum',
    'options' => 'erp_writeback_status_list',
    'len' => 20,
    'comment' => 'success / error, stamped by the connector after each write-back attempt',
    'required' => false,
    'readonly' => true,
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'duplicate_merge' => false,
);
$dictionary['Contact']['fields']['erp_writeback_at'] = array(
    'name' => 'erp_writeback_at',
    'vname' => 'LBL_ERP_WRITEBACK_AT',
    'type' => 'datetime',
    'comment' => 'ISO timestamp of the last write-back attempt, stamped by the connector',
    'required' => false,
    'readonly' => true,
    'reportable' => true,
    'audited' => false,
    'importable' => false,
    'duplicate_merge' => 'disabled',
);
$dictionary['Contact']['fields']['erp_writeback_msg'] = array(
    'name' => 'erp_writeback_msg',
    'vname' => 'LBL_ERP_WRITEBACK_MSG',
    'type' => 'text',
    'comment' => 'Last ERP write-back message, stamped by the connector. Null on success.',
    'required' => false,
    'readonly' => true,
    'reportable' => true,
    'audited' => false,
    'importable' => false,
    'duplicate_merge' => 'disabled',
);

// Sticky "this contact exists in Epicor" flag, set by BdContactSyncHook
// (before_save, priority 1) the moment the connector stamps
// erp_writeback_status = 'success', and never cleared afterwards. It exists
// because erp_writeback_status alone is NOT a reliable guard: core's
// container write-back route turns a deliberate transform skip into an empty
// POST that Epicor 400s, and the resulting 'error' stamp OVERWRITES the
// earlier 'success' (measured live 23 Aug 2026 - core defect "container
// write-back cannot express skip-this-row"). Without a sticky flag, that
// false error re-arms the sync and the next edit DUPLICATES the contact in
// Epicor. bd_erp_synced survives the false stamp, so the trigger hook can
// refuse to re-queue a contact that was ever created ERP-side.
$dictionary['Contact']['fields']['bd_erp_synced'] = array(
    'name' => 'bd_erp_synced',
    'vname' => 'LBL_BD_ERP_SYNCED',
    'type' => 'bool',
    'default' => false,
    'comment' => 'Set once the Epicor contact was created; sticky - survives later false error stamps',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'duplicate_merge' => false,
);
