<?php

/**
 * RETIRED. This file intentionally registers no hooks.
 *
 * It used to register two before_save hooks on Contacts, stickySyncedFlag and
 * stampSyncRequest, which maintained bd_erp_synced and
 * bd_erp_sync_requested_at for the extension container's bd_contact_sync
 * write-back sweep.
 *
 * That write-back was itself retired when core's connector-epicor began
 * shipping the identical Sugar -> Epicor Contacts write-back unconditionally
 * (1.15). Keeping our copy would have double-written every contact into
 * Epicor, so connector_ext_benchdogs dropped ContactSyncWriteBack. Nothing has
 * read either field since. Verified across all four packages that could:
 * connector_ext_benchdogs, connector_feature_epicor_contacts, connector_core
 * and connector_epicor. Zero references in any of them.
 *
 * The cost of leaving it was not zero: a before_save hook fired on EVERY
 * contact save to maintain a request field for a sweep that no longer exists
 * and a sticky flag nothing reads.
 *
 * WHY THIS FILE IS EMPTIED RATHER THAN DELETED. Deleting it from the package
 * would not remove it from an installed instance. An upgrade install copies
 * the files the new version ships and leaves everything else alone, and Sugar
 * Cloud's package scanner denylists every file-removal call, so the package
 * cannot delete it either (see the note in scripts/post_install.php). A file
 * simply dropped from the build therefore stays on disk, keeps being merged
 * into logichooks.ext.php, and the hooks keep firing. Overwriting the file
 * with this stub is what actually retires them.
 *
 * The same applies to Ext/Vardefs/bd_contact_sync_fields.php beside it.
 *
 * Safe to delete this stub outright once every instance has taken a release
 * at or after this one.
 */
