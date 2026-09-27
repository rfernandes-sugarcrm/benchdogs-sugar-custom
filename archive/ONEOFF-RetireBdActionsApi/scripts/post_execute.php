<?php

/**
 * ONEOFF-RetireBdActionsApi (G437) - delete ONE orphaned Bench Dogs file, on a
 * tenant that NO LONGER HAS Bench Dogs installed.
 *
 * THE FILE: custom/clients/base/api/BdBenchDogsActionsApi.php. Up to rc63 it
 * declared class BdBenchDogsActionsApi and registered three REST routes:
 *   POST Accounts/:id/bd-create-opp-quote
 *   POST Quotes/:id/bd-send-to-estimating
 *   POST bd-tools/repair-ui
 * ServiceDictionary::buildAllDictionaries() require_once's every
 * custom/clients/<platform>/api/*.php on a REST rebuild and registers the class
 * named after the file, so for as long as the file exists the routes are live.
 *
 * WHY IT IS STILL ON et. et ran Bench Dogs 0.9.42-rc62 and then UNINSTALLED it
 * (G437, "tables kept"). Module Loader's uninstall_copy() writes each path's
 * -restore backup back over the live file, so the uninstall restored an older
 * route-registering body instead of deleting the file. ONEOFF-RetireBdResidue
 * 1.0.2 then removed 45 leftovers but deliberately KEPT this path, because on a
 * tenant WITH Bench Dogs rc69+ the file belongs to the installed Bench Dogs
 * package: rc69 ships it EMPTY (🔒 1573), and that empty body is the mechanism
 * that unregisters bd-tools/repair-ui there.
 *
 * THEREFORE THIS PACKAGE IS et-ONLY BY CONSTRUCTION, not by instruction: it
 * deletes the file ONLY when no Bench Dogs package (id sugarai_benchdogs_ext) is
 * INSTALLED on the tenant. On a tenant where Bench Dogs is installed it deletes
 * nothing and says so. That keeps it harmless if it is ever run on Ophir or
 * benchdogs-dev/sandbox by mistake: there the file is Bench Dogs', and Bench
 * Dogs' own next build decides its fate.
 *
 * HOW, WITHOUT unlink() (ModuleScanner denies unlink/copy/file_get_contents/
 * is_file/...). ModuleInstaller::copy_path($absent, $file, $absent, true) in
 * uninstall mode: copy_recursive_with_backup() deletes the destination when the
 * source is neither a file nor a directory - the same route uninstall_copy()
 * takes for a file a package installed with no backup, and the exact call
 * ONEOFF-RetireBdResidue 1.0.2 used to delete ResolveOrderableLines.php.
 * $absent is a path under this unpacked package that it never ships; it is
 * checked absent first (if it existed, copy_path would COPY it over the file).
 *
 * THE ROUTE CACHE NEEDS NO CODE HERE. ModuleInstaller::install() runs this
 * post_execute INSIDE its task loop, then reset_file_cache (the class file map),
 * then rebuild_all(), then MetaDataManager::clearAPICache() and
 * ServiceDictionaryRest::buildAllDictionaries() (SugarEnt 26.1.0
 * ModuleInstall/ModuleInstaller.php, install()). The dictionary is rebuilt from
 * the directory AFTER the file is gone, so the three routes are unregistered by
 * the install itself.
 *
 * WHAT IT DOES NOT DO: it installs no file (NO 'copy' key, so uninstall_copy()
 * has nothing to walk or restore and uninstalling this package is a no-op),
 * creates or drops no table, writes no record, and touches no other path.
 * Idempotent: a second run finds the file gone and says so.
 */

$raVersion = isset($manifest['version']) ? (string) $manifest['version'] : 'unknown';
$raFile = 'custom/clients/base/api/BdBenchDogsActionsApi.php';
$raBenchId = 'sugarai_benchdogs_ext';
$raNoBackup = dirname(__DIR__) . '/no-backup/' . $raFile;
$raOutcome = '';
$raDetail = '';

try {
    $raBenchInstalled = DBManagerFactory::getInstance()->getConnection()->executeQuery(
        "SELECT version FROM upgrade_history WHERE id_name = ? AND status = 'installed' AND deleted = 0",
        array($raBenchId)
    )->fetchFirstColumn();

    if (!file_exists($raFile)) {
        $raOutcome = 'ALREADY GONE';
        $raDetail = $raFile . ' is not on this tenant - nothing to do (the routes are not registered from it).';
    } elseif ($raBenchInstalled !== array()) {
        $raOutcome = 'NOT TOUCHED';
        $raDetail = $raFile . ' belongs to the INSTALLED Bench Dogs package (' . $raBenchId . ' '
            . implode(', ', $raBenchInstalled) . '), which ships it - rc69+ ships it EMPTY to unregister its '
            . 'route. This one-off is for tenants WITHOUT Bench Dogs (et, G437) and deletes nothing here.';
    } elseif (file_exists($raNoBackup)) {
        $raOutcome = 'SKIPPED';
        $raDetail = 'the no-backup source ' . $raNoBackup . ' exists, and copy_path would copy it over '
            . $raFile . ' instead of deleting it. Nothing was changed.';
    } else {
        $raMd5 = md5_file($raFile);
        $raInstaller = new ModuleInstaller();
        $raInstaller->copy_path($raNoBackup, $raFile, $raNoBackup, true);
        if (file_exists($raFile)) {
            $raOutcome = 'FAILED';
            $raDetail = $raFile . ' (md5 ' . $raMd5 . ') is still present after copy_path.';
        } else {
            $raOutcome = 'REMOVED';
            $raDetail = $raFile . ' (md5 ' . $raMd5 . ') deleted: no Bench Dogs package is installed. Module Loader '
                . 'rebuilds the REST dictionary at the end of this install, which unregisters '
                . 'bd-create-opp-quote, bd-send-to-estimating and bd-tools/repair-ui.';
        }
    }
} catch (Throwable $raError) {
    $raOutcome = 'FAILED';
    $raDetail = $raFile . ': ' . $raError->getMessage();
}

echo '==================================================================' . "\n";
echo 'ONEOFF-RetireBdActionsApi ' . $raVersion . ' (G437) - ' . $raOutcome . "\n";
echo '  ' . $raDetail . "\n";
echo 'This package installed no file, created no table and wrote no record;' . "\n";
echo 'uninstalling it takes nothing back. Uninstall it now.' . "\n";
echo '==================================================================' . "\n";

// fatal() so the outcome survives any log level and lands in package_install.log
// (MlpLogger points the default logger there for the duration of an install).
$GLOBALS['log']->fatal('ONEOFF-RetireBdActionsApi ' . $raVersion . ' (G437): ' . $raOutcome . ' - ' . $raDetail);
