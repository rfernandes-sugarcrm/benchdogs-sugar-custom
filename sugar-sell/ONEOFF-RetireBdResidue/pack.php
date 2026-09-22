#!/usr/bin/env php
<?php
/**
 * ONEOFF-RetireBdResidue package builder.
 *
 * A disposable cleanup, not a product package, built exactly like its two
 * siblings ONEOFF-RetireBdQuoteMirror and ONEOFF-DropBdQuoteMirrorTables: the zip
 * holds a manifest, one post_execute script and the files that script reads.
 * Module Loader runs post_execute from the unpacked package and copies nothing.
 *
 * 🛑 THERE IS NO `copy` INSTALLDEF AND THAT IS THE ENTIRE DESIGN, NOT A DETAIL.
 *
 * The natural way to retire a shipped path is to ship it EMPTY through
 * installdefs['copy'], which is how BenchDogs-Ext itself retires its 38 stubs.
 * MEASURED IN SUGARENT 26.1.0, A ONE-OFF BUILT THAT WAY WOULD UNDO ITSELF:
 *
 *   install_copy()   ModuleInstaller.php:503-518
 *       $backup_path = getBackupPath()            // <installed zip>-restore
 *       copy_path($from, $to, $backup_path)
 *         -> copy_recursive_with_backup(:2669-2678)
 *            if (file_exists($dest)) copy($dest, "$backup_path/$dest");  // BACKUP
 *            copy($source, $dest);                                        // then overwrite
 *
 *   uninstall_copy() ModuleInstaller.php:521-545
 *       copy_path($backup_path, $cp['to'], $backup_path, true)
 *         -> copy_recursive_with_backup(:2656-2662)
 *            copy($source, $dest);   // THE PRE-INSTALL BODY IS PUT BACK
 *
 * So an installdefs['copy'] cleanup hands Module Loader a pristine copy of the
 * very residue it was built to remove, and gives it back the moment anyone
 * uninstalls the one-off. Every tenant would silently revert, and the install log
 * would still show the cleanup succeeding. That is strictly worse than doing
 * nothing, because it is invisible.
 *
 * With no `copy` entry there is no copy list for uninstall_copy() to walk, no
 * -restore directory, and nothing to put back. Uninstalling this package is a
 * genuine no-op - the same property both sibling one-offs were built for. The
 * removals are performed from post_execute by platform code (ModuleInstaller), so
 * they survive.
 *
 * 🚩 THE ONE CASE WHERE RESTORE STILL BITES, AND IT IS NOT THIS PACKAGE'S.
 * BenchDogs-Ext DOES ship every one of these paths through `copy`. Uninstalling
 * BenchDogs-Ext restores the bodies that were on disk when THAT zip installed.
 * Re-run this one-off after any BenchDogs-Ext uninstall. It is idempotent.
 *
 * EXPECTED BUILD NUMBERS, stated because an ERP-class build is different and a
 * silent difference is how a bad zip ships:
 *
 *     zip entries          = 5       (4 files + manifest.php)
 *     installdefs['copy']  = ABSENT  (not empty-array: absent)
 *     ERP-Core merge       = NONE, correctly
 *
 * A healthy ERP-Epicor build shows copy = 443 over 1309 entries because
 * sugar-sell/buildPackages.sh merges ERP-Core/src into the work dir first. THIS
 * PACKAGE IS NOT AN ERP-CLASS PACKAGE: it contains no ERP-Core content, ships no
 * files into the instance, and buildPackages.sh has no stanza for it - it lives
 * in benchdogs-sugar-custom, which has no buildPackages.sh at all. `php pack.php`
 * from this directory is the correct and complete build, exactly as it is for the
 * two sibling one-offs. If a future build prints anything other than 5 entries,
 * something was added that should not have been.
 */

$packageID      = 'oneoff_retire_bd_residue';
$packageLabel   = 'One-off: Retire Bench Dogs Residue';
// Names what it REMOVES, in lower case, and never spells a module the way a
// module is spelled - a description that does reads as a claim to INSTALL that
// module, which is what MLP008 checks for.
$description    = 'One-off cleanup. Removes the files and deployed metadata that earlier '
    . 'versions of the bench dogs package installed and that Module Loader can no longer '
    . 'take back: 87 retired custom/Extension fragments (vardefs, labels, hook '
    . 'registrations, a dropdown style, a table dictionary entry and the subpanel and '
    . 'record-view layout fragments), five retired button field directories, the '
    . 'accumulated zz_bd_stage_doms language fragment, the bench dogs panel on the '
    . 'deployed quotes record view, the retired value-source marker on the deployed '
    . 'opportunities record view, and 21 orphaned class files. Installs no file, creates '
    . 'no table, drops no table and writes no record. It KEEPS the two customer-group '
    . 'fields and their placement, the admin repair route, and every path another package '
    . 'also ships - all of which it names in its own install log. Idempotent: run it '
    . 'twice and the second run reports nothing left to remove. Uninstall it immediately '
    . 'afterwards; there is nothing to take back out.';
$supportedVersionRegex = '(26|25|14)\\..*$';
$acceptableSugarFlavors = array('ENT', 'ULT', 'PRO');

if (empty($argv[1])) {
    if (file_exists('version')) {
        $version = trim(file_get_contents('version'));
    }
} else {
    $version = $argv[1];
}
if (empty($version)) {
    die("Usage: {$argv[0]} [version]\n");
}

$dir = 'releases';
if (!is_dir($dir)) {
    mkdir($dir);
}

$manifest = array(
    'key'                       => $packageID,
    'name'                      => $packageLabel,
    'description'               => $description,
    'author'                    => 'SugarCRM, Inc.',
    // TRUE, and it is safe precisely because there is no 'copy'. See the docblock.
    'is_uninstallable'          => true,
    'published_date'            => date('Y-m-d H:i:s'),
    'type'                      => 'module',
    'version'                   => $version,
    // It creates none, so there is nothing for a prompt to ask about.
    'remove_tables'             => 'false',
    'acceptable_sugar_versions' => array('regex_matches' => array($supportedVersionRegex)),
    'acceptable_sugar_flavors'  => $acceptableSugarFlavors,
    // NO 'dependencies'. This package must be runnable on a tenant that has
    // already had Bench Dogs uninstalled, which is exactly the tenant with the
    // most residue. A dependency on sugarai_benchdogs_ext would refuse that case.
);

// No 'copy' key at all - see the docblock. post_execute is addressed by
// <basepath> and runs from the unpacked package; lib/ is read by that script
// through dirname(__DIR__) and never lands in the instance.
$installdefs = array(
    'id'           => $packageID,
    'post_execute' => array('<basepath>/scripts/post_execute.php'),
);

$zipPath = "{$dir}/{$packageID}-{$version}.zip";
if (file_exists($zipPath)) {
    die("Error: {$zipPath} already exists. Delete it or bump version.\n");
}

echo "Creating {$zipPath} ...\n";
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);

// Named explicitly rather than walked. A directory walk is how a build picks up
// a stray editor backup or a README and ships it to a scanner, and this package
// has exactly four files.
$files = array(
    'scripts/post_execute.php',
    'lib/BdQuotesLayoutExtensions.php',
    'lib/BdOpportunitiesLayoutExtensions.php',
    'lib/emptied.php',
);
foreach ($files as $f) {
    if (!file_exists($f)) {
        die("Error: {$f} is missing; refusing to build an incomplete package.\n");
    }
    $zip->addFile($f, $f);
    echo " [*] {$f}\n";
}

$manifestContent = sprintf(
    "<?php\n\$manifest = %s;\n\$installdefs = %s;\n",
    var_export($manifest, true),
    var_export($installdefs, true)
);
$zip->addFromString('manifest.php', $manifestContent);
$zip->close();

echo "Done. Wrote {$zipPath}\n";
exit(0);
