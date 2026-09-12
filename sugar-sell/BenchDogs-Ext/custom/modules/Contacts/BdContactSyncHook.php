<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * RETIRED. Both methods are deliberate no-ops.
 *
 * See custom/Extension/modules/Contacts/Ext/LogicHooks/bd_contact_sync.php for
 * the full reasoning. In short: the bd_contact_sync write-back these methods
 * fed was retired when core's connector-epicor began shipping the identical
 * Sugar -> Epicor Contacts write-back unconditionally, and nothing has read
 * bd_erp_synced or bd_erp_sync_requested_at since.
 *
 * WHY THE CLASS SURVIVES AS A STUB rather than being deleted.
 * LogicHook::loadHookClass() does a require_once on the path named in the
 * registration, and a require_once of a missing file is a FATAL, not a
 * catchable error. The registration lives in the compiled logichooks.ext.php,
 * which is rebuilt by Quick Repair and Rebuild - and an instance can sit with a
 * stale compiled copy between the upgrade install and that repair. If the class
 * file were gone during that window, every contact save would fatal.
 *
 * An empty class costs nothing and closes that window. Delete it in a later
 * release, once every instance has taken this one and been repaired.
 *
 * The parameters are deliberately untyped, where the original declared
 * (SugarBean, string, array): void. A no-op that must never fail should accept
 * whatever it is handed. Typed parameters would turn an unexpected argument
 * into a TypeError on a contact save, which is precisely the failure this stub
 * exists to prevent.
 */
class BdContactSyncHook
{
    /**
     * Was: set bd_erp_synced once the connector stamped a success, and never
     * clear it again.
     */
    public function stickySyncedFlag($bean, $event, $arguments)
    {
        return;
    }

    /**
     * Was: stamp bd_erp_sync_requested_at on contacts of ERP-linked accounts,
     * which is what the retired write-back swept for.
     */
    public function stampSyncRequest($bean, $event, $arguments)
    {
        return;
    }
}
