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

    public function stampSyncRequest(SugarBean $bean, string $event, array $arguments): void
    {
        try {
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
