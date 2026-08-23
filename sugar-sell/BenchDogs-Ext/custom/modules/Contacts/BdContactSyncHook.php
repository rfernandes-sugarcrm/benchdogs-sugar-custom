<?php

/**
 * before_save hook class for Contacts - see the registration in
 * custom/Extension/modules/Contacts/Ext/LogicHooks/bd_contact_sync.php for
 * why this class lives here and not alongside that registration.
 *
 * Bench Dogs contact sync trigger, minimal honest mechanism: when a contact
 * that belongs to an ERP-linked account (the account carries
 * erp_display_sync_key, the Epicor CustNum the product stamps on synced
 * accounts) is created or has a sync-relevant field change, stamp
 * bd_erp_sync_requested_at. The extension container's bd_contact_sync
 * write-back polls on exactly that stamp (delta_field-only selection over
 * HTTP), resolves the CustNum from the account, and creates the
 * Erp.BO.CustCnt - with its own ERP-side duplicate guard, so a re-stamp of
 * an already-synced contact skips cleanly instead of duplicating.
 *
 * before_save, NOT after_save: the stamp rides the same UPDATE as the edit
 * itself, so there is no second save to recurse through and no direct SQL.
 */
class BdContactSyncHook
{
    /** Fields whose change makes the contact worth (re)queueing. */
    private const RELEVANT_FIELDS = [
        'first_name', 'last_name', 'phone_work', 'title', 'account_id',
    ];

    /**
     * before_save, priority 1 (runs BEFORE stampSyncRequest): maintain the
     * sticky bd_erp_synced flag.
     *
     * Set it the moment the connector's success stamp lands
     * (erp_writeback_status = 'success' arrives as a REST PUT, and hooks
     * fire on those sync PUTs - the proven BdQuoteReflectionHook pattern).
     * Once true it is NEVER cleared here: core's container route turns a
     * deliberate transform skip into an empty POST that Epicor rejects, and
     * the resulting 'error' stamp overwrites 'success' (measured live
     * 23 Aug 2026). The sticky flag is what survives that false error.
     * Clearing it is an explicit admin action (mass update / SQL), on
     * purpose.
     *
     * before_save, so the flag rides the same UPDATE as the stamp that set
     * it - no second save, no recursion to guard.
     */
    public function stickySyncedFlag(SugarBean $bean, string $event, array $arguments): void
    {
        try {
            if (!empty($bean->fetched_row['bd_erp_synced'])) {
                $bean->bd_erp_synced = true;   // sticky: refuse any clear
                return;
            }
            if ((string) ($bean->erp_writeback_status ?? '') === 'success') {
                $bean->bd_erp_synced = true;
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BdContactSyncHook::stickySyncedFlag: ' . $e->getMessage());
        }
    }

    public function stampSyncRequest(SugarBean $bean, string $event, array $arguments): void
    {
        try {
            // Create-only sync: field updates deliberately do not propagate,
            // so re-stamping a contact that already EXISTS in Epicor can only
            // produce a duplicate or a false error (the container's skip is
            // mangled into an empty POST by core - see stickySyncedFlag).
            // bd_erp_synced, not erp_writeback_status, is the authority: the
            // status field gets overwritten by that same false error. A
            // contact whose create genuinely FAILED (bd_erp_synced false)
            // stays retryable on its next relevant edit.
            if (!empty($bean->bd_erp_synced) || !empty($bean->fetched_row['bd_erp_synced'])) {
                return;
            }

            $accountId = (string) ($bean->account_id ?? '');
            if ($accountId === '') {
                return;   // no account - no Epicor customer to attach to
            }

            $isNew = empty($bean->fetched_row);
            if (!$isNew && !$this->relevantChange($bean)) {
                return;   // resave with nothing the ERP contact would carry
            }

            $account = BeanFactory::retrieveBean('Accounts', $accountId);
            if ($account === null || (string) ($account->erp_display_sync_key ?? '') === '') {
                return;   // account not ERP-linked - sync it first
            }

            // CustNum travels WITH the contact so the extension's transform
            // needs no account lookup through core (see bd_erp_custnum
            // vardef for why callbacks are off the table on the CLI path).
            $bean->bd_erp_custnum = (string) $account->erp_display_sync_key;
            $bean->bd_erp_sync_requested_at = TimeDate::getInstance()->nowDb();
        } catch (Throwable $e) {
            // A broken stamp must never block saving a contact.
            $GLOBALS['log']->error('BdContactSyncHook: ' . $e->getMessage());
        }
    }

    private function relevantChange(SugarBean $bean): bool
    {
        foreach (self::RELEVANT_FIELDS as $field) {
            $before = (string) ($bean->fetched_row[$field] ?? '');
            if ((string) ($bean->$field ?? '') !== $before) {
                return true;
            }
        }
        // The primary email lives on the relationship, not in fetched_row -
        // emailAddress->hasFetched tracks whether addresses were touched this
        // save. Treat any touched email widget as relevant: over-stamping is
        // harmless (the container guard self-skips), under-stamping loses a
        // new address.
        if (!empty($bean->emailAddress) && !empty($bean->emailAddress->hasFetched)) {
            return true;
        }
        return false;
    }
}
