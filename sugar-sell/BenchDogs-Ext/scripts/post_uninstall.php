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
 * by now there are none. Everything is in its own try/catch: a cache rebuild
 * that fails must not fail the uninstall, since the admin can always clear the
 * cache by hand, whereas an uninstall that reports failure after having already
 * removed the files leaves nobody knowing what state the instance is in.
 */

// Proof of life - see the matching note in pre_uninstall.php.
$GLOBALS['log']->fatal('BenchDogs-Ext: post_uninstall running - rebuilding caches');

// The stock modules this package extended with fields, hooks or layouts. Its
// OWN three modules (bd01_ERP_Quote and friends) are deliberately not listed:
// by now the uninstaller has dropped their beans, and asking the repair to
// rebuild a module that no longer exists is the one call here likely to throw
// rather than no-op.
$bdModules = array(
    'Quotes',
    'Products',
    'Accounts',
    'Opportunities',
    'Contacts',
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

// The relationship cache and the compiled TableDictionary are not module-scoped,
// so rebuildExtensions() above does not cover them - the same gap
// post_install.php documents on the way in. The quotes_erp_orders cardinality
// override ships in a TableDictionary extension file that has just been
// deleted, and the cached definition outlives the file unless it is rebuilt
// here.
try {
    require_once 'ModuleInstall/ModuleInstaller.php';
    $bdInstaller = new ModuleInstaller();
    $bdInstaller->silent = true;
    $bdInstaller->rebuild_tabledictionary();
    if (class_exists('SugarRelationshipFactory')) {
        SugarRelationshipFactory::deleteCache();
        SugarRelationshipFactory::rebuildCache();
    }
    VardefManager::clearVardef('Quotes', 'Quote');
    VardefManager::clearVardef('ERP_Orders', 'ERP_Order');
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: post-uninstall relationship rebuild failed: ' . $e->getMessage());
}

// Last, so it refreshes what the two rebuilds above have settled on rather than
// what was there when this script started. A full refreshCache() rather than
// refreshModulesCache($bdModules), because the module LIST itself has changed -
// three modules have gone - and a per-module refresh only rewrites entries for
// the modules it is handed.
try {
    MetaDataManager::refreshCache();
} catch (Throwable $e) {
    $GLOBALS['log']->error('BenchDogs-Ext: post-uninstall metadata refresh failed: ' . $e->getMessage());
}
