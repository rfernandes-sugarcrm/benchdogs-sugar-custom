<?php

/**
 * ONE-OFF REPAIR. Retires the Bench Dogs quote-mirror relationships and the
 * extension files left behind when its three mirror modules go.
 *
 * DISPOSABLE BY DESIGN. This package copies no files into the instance, adds
 * no modules, creates no tables and registers no hooks. Install it, let it
 * run, uninstall it. The uninstall is a genuine no-op because there is nothing
 * to take back out.
 *
 * WHY THIS IS NEEDED AT ALL
 *
 * Module Loader removes a package's files on uninstall and nothing else. When
 * a module is retired WITHOUT running an uninstall - which is what you do when
 * you want to keep the customer's data - the module definition goes and the
 * relationships' own definition files stay on disk, naming modules that no
 * longer exist.
 *
 * That is not cosmetic. It is the fault that takes an instance out. In the
 * sibling repository, on ossugarcube2, `failed to find link for
 * quotes_erp_quotes` appeared in sugarcrm.log on ordinary page loads - 17
 * times in two and a half hours - and every Module Loader install of any
 * package reported "Module Installed Successfully" while installing nothing.
 * The two were the same fault. ModuleInstaller::install() finishes with a wide
 * rebuild, the rebuild loads the module's relationships, the dangling link
 * fatals, and PackageManager::installPackage - with uninstallOnError at its
 * default of true - force-uninstalls the package it had just installed. The
 * success page has already rendered by then, and the fatal lands in
 * sugarcrm.log rather than package_install.log, so the UI and the install log
 * both look innocent.
 *
 * The Bench Dogs mirror has four such relationships, two of them onto live
 * modules (Quotes and Accounts). Retiring the mirror by hand in Studio would
 * reproduce that failure exactly.
 *
 * WHY DELETING THE `relationships` ROWS IS NOT ENOUGH
 *
 * Admin -> Repair -> Rebuild Relationships reads the MetaData files under
 * custom/Extension/modules/relationships/relationships/ and recreates the rows
 * from them. The definition on disk is the source of truth, so the definition
 * is what has to go. Rows regenerate from files; files do not regenerate from
 * rows. Hence the order below: definitions first, then the rows they made.
 *
 * WHY THIS DOES NOT DELETE THE FILES ITSELF
 *
 * scandir, is_dir, unlink and rmdir are all on ModuleScanner's deny-list for
 * packaged code. A repair package that walked the extension directories by
 * hand would be rejected at upload, so the fix for the broken instance would
 * itself be unloadable. ModuleInstaller::uninstall_relationship() does the
 * same work and is platform code, where the deny-list does not apply. Given a
 * relationship's metadata file it removes the Ext vardefs and layoutdefs, the
 * files under custom/Extension/modules/relationships/, the TableDictionary
 * entry and the metadata file itself. That is the complete set.
 *
 * NO CACHE WORK HERE, DELIBERATELY. Forcing a metadata refresh from
 * post_execute is what kills an install with "Call to a member function get()
 * on bool" inside MetaDataManager. ModuleInstaller::install() runs
 * rebuild_all(true) and repairAndClearAll() immediately after post_execute, so
 * the removals here are picked up on this same install without us forcing
 * anything.
 *
 * NO DATA IS DROPPED. uninstall_relationship() drops a relationship's join
 * table only when $GLOBALS['mi_remove_tables'] is true; it is forced false
 * around the loop below and restored afterwards. The rows survive this
 * package entirely. Dropping them is a separate, irreversible decision, and it
 * has its own package: ONEOFF-DropBdQuoteMirrorTables, which must run AFTER
 * this one.
 *
 * IDEMPOTENT. Once the definitions are gone it finds nothing and does nothing.
 */

// Guarded on the class, not the path. ModuleInstaller is already loaded during
// an install, and a second require of the same class through a different
// resolved path is what killed ERP-Epicor 1.1.8 with "Cannot redeclare class".
if (!class_exists('ModuleInstaller', false)) {
    require_once 'ModuleInstall/ModuleInstaller.php';
}

/**
 * THE ORDERING GUARD, AND THE REASON THIS PACKAGE IS SAFE TO RUN AT THE WRONG
 * TIME.
 *
 * This is the LAST step of retiring the mirror, not the first. It assumes the
 * three modules have already been deregistered - by installing a BenchDogs-Ext
 * build that no longer declares them in its `beans` and `relationships`
 * installdefs. Run before that, it would strip the links off modules that are
 * still installed and still being written to by the reflection hook, leaving a
 * half-state worse than either end of the job. Worse, the next BenchDogs-Ext
 * install would recreate every relationship this removed, so the damage would
 * look like it had healed itself while the mirror quietly came back.
 *
 * BeanFactory::newBean() yielding an object is the reliable signal that a
 * module is still registered - it is the same check ModuleInstaller's own
 * uninstall_beans() leads with. So: if any of the three still resolves, stop
 * and say why, rather than doing half a job.
 */
$bdMirrorModules = array(
    'bd01_ERP_Quote',
    'bd01_ERP_Quote_Line',
    'bd01_ERP_Quote_Cost',
);

$bdStillRegistered = array();
foreach ($bdMirrorModules as $bdModule) {
    try {
        $bdBean = BeanFactory::newBean($bdModule);
    } catch (Throwable $e) {
        $bdBean = null;
    }
    if (!empty($bdBean)) {
        $bdStillRegistered[] = $bdModule;
    }
}

if ($bdStillRegistered) {
    $GLOBALS['log']->fatal(sprintf(
        'RetireBdQuoteMirror: REFUSING TO RUN. %s still registered on this instance, '
            . 'which means a BenchDogs-Ext build that declares the mirror is still installed. '
            . 'Removing the relationships now would leave those modules present but unlinked, '
            . 'still written to by the reflection hook, and the next BenchDogs-Ext install '
            . 'would recreate every link this removed. Install a BenchDogs-Ext build that no '
            . 'longer ships the mirror first, then run this package again. Nothing was changed.',
        implode(', ', $bdStillRegistered)
    ));

    return;
}

/**
 * The mirror's four relationships, named explicitly rather than discovered.
 *
 * Discovery would mean globbing the extension directories, which is denied in
 * packaged code, and these four are a closed set - every one names
 * bd01_ERP_Quote, bd01_ERP_Quote_Line or bd01_ERP_Quote_Cost, none of which
 * exist any more by the time this runs.
 *
 * Two of them (bd01_erp_quote_quotes, bd01_erp_quote_accounts) have their
 * other end on a LIVE module, Quotes and Accounts respectively. Those are the
 * two that make ordinary page loads log a missing link, and the two that break
 * every subsequent install.
 */
$bdMirrorRelationships = array(
    'bd01_erp_quote_quotes',
    'bd01_erp_quote_accounts',
    'bd01_erp_quote_lines',
    'bd01_erp_line_costs',
);

$bdInstaller = new ModuleInstaller();
$bdInstaller->silent = true;

// Keep the join tables and their rows. See the docblock above.
$bdHadRemoveTables = array_key_exists('mi_remove_tables', $GLOBALS);
$bdPreviousRemoveTables = $bdHadRemoveTables ? $GLOBALS['mi_remove_tables'] : null;
$GLOBALS['mi_remove_tables'] = false;

$bdRetired = array();
$bdAlreadyGone = array();

foreach ($bdMirrorRelationships as $bdRelName) {
    $bdMetaFile = 'custom/metadata/' . $bdRelName . 'MetaData.php';

    if (!file_exists($bdMetaFile)) {
        $bdAlreadyGone[] = $bdRelName;
        continue;
    }

    $bdInstaller->uninstall_relationship($bdMetaFile);
    $bdRetired[] = $bdRelName;
}

// Restore the caller's setting exactly, including its absence.
if ($bdHadRemoveTables) {
    $GLOBALS['mi_remove_tables'] = $bdPreviousRemoveTables;
} else {
    unset($GLOBALS['mi_remove_tables']);
}

// With the definitions gone, retire the rows they generated. Order matters and
// is the whole point: rows first would simply be rebuilt from the files.
// Bound parameters, and the placeholder list is built from the count, never
// from the values.
if ($bdRetired) {
    $bdPlaceholders = rtrim(str_repeat('?, ', count($bdRetired)), ', ');
    // mlp-lint: ignore MLP005 - the only thing concatenated is the placeholder
    // list, built from count(); every name travels as a bound parameter.
    $bdSql = 'UPDATE relationships SET deleted = 1 WHERE deleted = 0 '
        . 'AND relationship_name IN (' . $bdPlaceholders . ')';
    DBManagerFactory::getConnection()->executeStatement($bdSql, $bdRetired);
}

/**
 * The retired modules' own leftovers.
 *
 * Removing the relationships above is what unblocks installs. This second part
 * is the tidy-up: the files under custom/Extension/modules/bd01_ERP_Quote and
 * its two siblings that are left when those modules are retired without an
 * uninstall. They are inert once the Include fragment no longer registers the
 * modules, but they are exactly the residue that makes the next person wonder
 * whether the mirror is really gone.
 *
 * uninstall_customizations() is the platform's own removal for this, clearing
 * custom/modules/<M>, custom/Extension/modules/<M> and
 * custom/working/modules/<M> for each. It uses rmdir_recursive internally,
 * which is the whole reason to call it rather than walk the directories here:
 * every filesystem function needed to do that by hand is on the scanner's
 * deny-list for packaged code.
 *
 * NO TABLES ARE DROPPED, and that is deliberate rather than an oversight. See
 * the note on ONEOFF-DropBdQuoteMirrorTables in the docblock at the top.
 */
$bdInstaller->uninstall_customizations($bdMirrorModules);

// fatal() so it lands in the log at default level: a repair that removes
// metadata silently is not one anybody can audit afterwards.
$GLOBALS['log']->fatal(sprintf(
    'RetireBdQuoteMirror: retired %d orphaned relationship(s)%s; %d already gone%s. '
        . 'Cleared leftover customisations for %s. No tables dropped, no rows deleted. '
        . 'Module Loader installs should now complete instead of reporting success and '
        . 'rolling back. This package can be uninstalled immediately; it left nothing behind.',
    count($bdRetired),
    $bdRetired ? ' (' . implode(', ', $bdRetired) . ')' : '',
    count($bdAlreadyGone),
    $bdAlreadyGone ? ' (' . implode(', ', $bdAlreadyGone) . ')' : '',
    implode(', ', $bdMirrorModules)
));
