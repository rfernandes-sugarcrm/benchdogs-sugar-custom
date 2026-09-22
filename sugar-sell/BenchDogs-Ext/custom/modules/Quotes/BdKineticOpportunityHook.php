<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * 🛑 G243 / 🔒 1499 — TOMBSTONE. THIS CLASS CREATES NOTHING.
 *
 * It keeps the name, the file path and the three public entry points of the
 * pairing writer that 0.9.42-rc39/rc40 shipped here, and every one of them
 * returns without writing. Nothing in this package registers it any more
 * (`custom/Extension/modules/Quotes/Ext/LogicHooks/bd_kinetic_opportunity.php`
 * is an empty registration), so on a correctly rebuilt tenant it is never
 * loaded at all.
 *
 * ============ SO WHY SHIP IT ============
 *
 * Because the empty registration only takes effect once the extensions are
 * rebuilt, and the failure this fix is for is precisely a tenant whose
 * PREVIOUS install's artefacts outlived the package that put them there.
 * Module Loader copies; it does not delete. If `install_extensions` is skipped,
 * fails, or a stale `custom/modules/Quotes/Ext/LogicHooks/logichooks.ext.php`
 * survives, rc39's two entries still name `pairOnSave` and `pairOnAccountLink`
 * in THIS file - and with the real implementation still on disk they would go
 * on raising an Opportunity per synced quote.
 *
 * Overwriting the class is the second half of the same retirement, and it is
 * the half that holds when the first half does not run. Together they are
 * "unreachable rather than detected": the registration removes the caller, the
 * tombstone removes the capability.
 *
 * SugarEnt's `LogicHook::loadHookClass()` (26.1.0
 * `include/utils/LogicHook.php:203-222`) already fails SOFT on a missing file -
 * it logs "Unable to load custom logic class" and `process_hooks()` continues -
 * so a stale entry pointing at a deleted file would not have fataled either.
 * It would simply have kept an error on every Quote save forever. A silent,
 * correct no-op is the better answer, and it is the one that can be tested.
 *
 * ============ WHAT THE RETIRED IMPLEMENTATION DID ============
 *
 * `pair()` read the quote's `erp_sync_key`, took the Kinetic quote number from
 * it, compared it against a history floor held in the `benchdogs` /
 * `materialize_from_quote_num` Administration row, and for anything above the
 * floor created an Opportunity (`sales_stage` "Proposal/Price Quote",
 * `probability` 65, `amount` 0, name `<Account> - Kinetic Quote <num>`) and
 * added it to the quote's `opportunities` link. That link event is what then
 * fired the flag writer, which is why G215's `erp_is_primary_quote` audit row
 * lands in the same second as G243's Opportunity.
 *
 * 🚩 THE FLOOR IS NOT WHAT WAS WRONG, SO DO NOT "FIX" THIS BY RAISING IT.
 * 🔒 1499 is unconditional: *"YES — the sync must never create one"*. A floor
 * that stops creating them TODAY still creates them for the next quote number.
 *
 * 🛑 THE CONTROL: the seller's "Create Opportunity & Quote" button
 * (ERP-Epicor's `AccountsErpActionsApi::createOppQuote`) is a different code
 * path that never called this class, and it still creates its Opportunity.
 *
 * ⚠️ DO NOT re-implement `pair()` here. Reviving the rule needs 🔒 1499
 * overturned first, and the register is where that happens - not this file.
 */
class BdKineticOpportunityHook
{
    /**
     * Retired hook entry point: `after_save` on Quotes, priority 2 in rc39/rc40.
     *
     * @param mixed $bean      the Quote being saved
     * @param string $event     the logic-hook event name
     * @param array<string,mixed> $arguments the logic-hook arguments
     */
    public function pairOnSave($bean, string $event, array $arguments): void
    {
        $this->recordRetired('pairOnSave', $bean);
    }

    /**
     * Retired hook entry point: `after_relationship_add` on Quotes, priority 2
     * in rc39/rc40. This is the one G243 measured - the connector links
     * `billing_accounts` in a call SEPARATE from the header create, which is
     * why the Opportunity landed six seconds after the quote.
     *
     * @param mixed $bean      the Quote whose relationship changed
     * @param string $event     the logic-hook event name
     * @param array<string,mixed> $arguments the logic-hook arguments
     */
    public function pairOnAccountLink($bean, string $event, array $arguments): void
    {
        $this->recordRetired('pairOnAccountLink', $bean);
    }

    /**
     * Retired public API. Returned true when it had created and linked an
     * Opportunity; now always false, because it never creates one.
     *
     * @param mixed $quote   the Quote that would have been paired
     * @param mixed $account the Account it would have been paired under
     */
    public function pair($quote = null, $account = null): bool
    {
        $this->recordRetired('pair', $quote);

        return false;
    }

    /**
     * One line, at debug level, naming the decision rather than the symptom -
     * so a tenant still carrying rc39's compiled registration is visible to
     * whoever reads the log, without adding noise to every quote save.
     *
     * @param mixed $bean
     */
    private function recordRetired(string $entryPoint, $bean = null): void
    {
        if (empty($GLOBALS['log'])) {
            return;
        }
        $id = is_object($bean) && !empty($bean->id) ? (string) $bean->id : '(none)';
        $GLOBALS['log']->debug(
            'BdKineticOpportunityHook::' . $entryPoint . ' is retired (G243 / decision 1499:'
            . ' the sync must never create an Opportunity); no Opportunity created for quote '
            . $id
        );
    }
}
