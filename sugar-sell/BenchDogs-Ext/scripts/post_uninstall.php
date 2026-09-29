<?php

/**
 * Rebuild the caches so the instance stops serving the package it no longer has.
 *
 * This is the half of the cleanup that has to run AFTER the files are gone,
 * which is why it is not folded into pre_uninstall.php beside it. The layout
 * surgery there needs our classes on disk; this needs them absent. Run the
 * rebuild too early and it simply re-bakes the extensions it is meant to clear,
 * and the vardefs, layouts and logic hooks the uninstall just removed would
 * still be compiled into custom/Extension's .ext.php files and served from the
 * metadata cache until somebody happened to run a Quick Repair by hand.
 *
 * What that looked like in practice: a Quotes record view still referencing
 * bd_* fields, an after_save hook still registered against a class file that no
 * longer existed, and the API still advertising modules whose beans were gone.
 *
 * Top-level code with no wrapping function, for the reason set out at length in
 * pre_uninstall.php: it is the only shape that behaves the same whether Module
 * Loader merely requires the file or also calls a function named after the
 * installdef key.
 *
 * Deliberately self-contained - it names no class this package shipped, because
 * by now there are none (ErpLayoutExtraFields, used below, is ERP-Core's). Everything is in its own try/catch: a cache rebuild
 * that fails must not fail the uninstall, since the admin can always clear the
 * cache by hand, whereas an uninstall that reports failure after having already
 * removed the files leaves nobody knowing what state the instance is in.
 */

// Proof of life - see the matching note in pre_uninstall.php.
$GLOBALS['log']->fatal('BenchDogs-Ext: post_uninstall running - rebuilding caches');

// The stock modules this package extends. Since 0.9.42-rc69 (G280 / 🔒 1567)
// that was Accounts alone - two vardefs, two labels and one record-view
// placement; G380/G381/G460 (🔒 1705b, 🔒 1724b) add Quotes - five vardefs,
// their labels, one before_save hook and their marked placements (G606 adds
// the Reference marker). The package installs no
// module of its own, so there is no bean of ours for the uninstaller to drop.
$bdModules = array(
    'Accounts',
    'Quotes',
);

try {
    SugarAutoLoader::load('modules/Administration/QuickRepairAndRebuild.php');
    $bdRepair = new RepairAndClear();
    $bdRepair->show_output = false;
    $bdRepair->module_list = $bdModules;
    $bdRepair->clearVardefs();
    $bdRepair->rebuildExtensions($bdModules);
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: post-uninstall repair failed: ' . $e->getMessage());
}

// G380 (f) / 🔒 1724b: take this package's marked fields back off the record
// views, now that their vardefs are gone. ERP-Core's ErpLayoutExtraFields
// retires only fields it recorded as marked and that have no vardef any more,
// so nothing else on either view is touched. AFTER the rebuild above: it reads
// the merged vardefs, which must no longer carry this package's fragments. The
// class is ERP-Core's, not this package's, so it is still on disk here.
$bdLayoutHelper = 'custom/include/ErpLayoutExtraFields.php';
foreach (array('Accounts', 'Quotes') as $bdModule) {
    try {
        if (!class_exists('ErpLayoutExtraFields', false) && file_exists($bdLayoutHelper)) {
            require_once $bdLayoutHelper;
        }
        if (class_exists('ErpLayoutExtraFields', false)) {
            $bdSynced = ErpLayoutExtraFields::sync($bdModule);
            $GLOBALS['log']->fatal('BenchDogs-Ext: ' . $bdModule . ' retired fields taken off the view: '
                . implode(', ', (array) ($bdSynced['removed'] ?? array())));
        } else {
            $GLOBALS['log']->error("BenchDogs-Ext: {$bdLayoutHelper} missing; {$bdModule} record view"
                . ' not cleaned up (remove bd_* fields from it by hand)');
        }
    } catch (Throwable $e) {
        $GLOBALS['log']->error('BenchDogs-Ext: ' . $bdModule . ' layout cleanup failed: ' . $e->getMessage());
    }
}

// No TableDictionary or relationship rebuild any more (0.9.42-rc69). It existed
// for the quotes_erp_orders cardinality override, whose file this package
// stopped shipping long ago; this package declares no relationship at all.

// Last, so it refreshes what the rebuild above has settled on rather than what
// was there when this script started. A full refreshCache() rather than
// refreshModulesCache($bdModules): earlier builds of this package extended
// Quotes, Products, Opportunities and Contacts too, and a per-module refresh
// only rewrites entries for the modules it is handed.
try {
    MetaDataManager::refreshCache();
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: post-uninstall metadata refresh failed: ' . $e->getMessage());
}
