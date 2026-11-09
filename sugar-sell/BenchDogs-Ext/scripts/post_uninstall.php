<?php

/** Rebuild the caches so the instance stops serving the package it no longer has. */

// Proof of life - see the matching note in pre_uninstall.php.
$GLOBALS['log']->fatal('BenchDogs-Ext: post_uninstall running - rebuilding caches');

// The modules whose placed fields are taken off the record view below (G280, 🔒 1567, G380, G381).
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

// G380 (f) / 🔒 1724b: take this package's marked fields back off the record views, now that their vardefs are gone; ERP-Core's class is autoloaded.
foreach (array('Accounts', 'Quotes') as $bdModule) {
    try {
        if (class_exists('ErpLayoutExtraFields')) {
            $bdSynced = ErpLayoutExtraFields::sync($bdModule);
            $GLOBALS['log']->fatal('BenchDogs-Ext: ' . $bdModule . ' retired fields taken off the view: '
                . implode(', ', (array) ($bdSynced['removed'] ?? array())));
        } else {
            $GLOBALS['log']->error('BenchDogs-Ext: class ErpLayoutExtraFields not found; ' . $bdModule . ' record view'
                . ' not cleaned up (remove bd_* fields from it by hand)');
        }
    } catch (Throwable $e) {
        $GLOBALS['log']->error('BenchDogs-Ext: ' . $bdModule . ' layout cleanup failed: ' . $e->getMessage());
    }
}

// No TableDictionary or relationship rebuild here. The one relationship this package declares (the Opportunity
// on ERP_Orders) has no table of its own: it is the bd_opportunity_id column, which stays, like every bd_* column.

// Last, so it refreshes what the rebuild above has settled on rather than what was there when this script started.
try {
    MetaDataManager::refreshCache();
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: post-uninstall metadata refresh failed: ' . $e->getMessage());
}
