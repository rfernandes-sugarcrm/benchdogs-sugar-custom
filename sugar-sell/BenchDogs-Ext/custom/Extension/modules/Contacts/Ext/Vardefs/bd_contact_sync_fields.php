<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare three fields on Contacts:
 *
 *   bd_erp_custnum             the Epicor customer number of the contact's account
 *   bd_erp_sync_requested_at   stamped by the before_save hook to queue a sync
 *   bd_erp_synced              sticky flag set on the connector's success stamp
 *
 * All three existed for the bd_contact_sync write-back, which was retired when
 * core's connector-epicor began shipping the identical Sugar -> Epicor Contacts
 * write-back unconditionally (1.15). Nothing has read them since. Verified
 * across connector_ext_benchdogs, connector_feature_epicor_contacts,
 * connector_core and connector_epicor: zero references to any of the three.
 *
 * See the sibling Ext/LogicHooks/bd_contact_sync.php for why these files are
 * emptied rather than deleted: on Sugar Cloud a file dropped from the build
 * stays on the installed instance, so only overwriting it actually retires it.
 *
 * EXISTING DATA. Removing a vardef does not drop the underlying columns. The
 * three values stay in contacts_cstm until someone removes them deliberately,
 * which is harmless: with no vardef, Sugar neither reads nor displays them.
 * Left in place on purpose, so this release is reversible by restoring the two
 * files and running Quick Repair, with no data lost in the meantime.
 */
