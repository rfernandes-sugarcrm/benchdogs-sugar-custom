#!/usr/bin/env php
<?php
/**
 * ONEOFF-DropBdQuoteMirrorTables package builder.
 *
 * A disposable repair, not a product package, built exactly like its sibling
 * ONEOFF-RetireBdQuoteMirror: the zip holds a manifest and one post_execute
 * script, which Module Loader runs from the unpacked package and never copies
 * anywhere. `copy` is empty, so uninstalling is a genuine no-op.
 *
 * Unlike its sibling, this one DELETES DATA. Run ONEOFF-RetireBdQuoteMirror
 * first; this package refuses to run otherwise. See scripts/post_execute.php.
 */

$packageID      = 'oneoff_drop_bd_quote_mirror_tables';
$packageLabel   = 'One-off: Drop Bench Dogs Quote Mirror Tables (DELETES DATA)';
// Refers to the retired modules only in lower case, as table names. A
// description that spells them the way a module is spelled reads as a claim to
// INSTALL those modules, which is what MLP008 checks for.
$description    = 'One-off, and it DELETES DATA. Drops the ten retired bench dogs quote '
    . 'mirror tables: bd01_erp_quote, bd01_erp_quote_line, bd01_erp_quote_cost, their '
    . 'three _cstm siblings, and the four join tables of the relationships that '
    . 'ONEOFF-RetireBdQuoteMirror retires. Take a database backup first; there is no '
    . 'undo. Refuses to run while any mirror module is still registered or any of the '
    . 'four relationship definitions is still on disk, so it cannot be run out of '
    . 'order. Every table is checked against the schema and its row count logged before '
    . 'it is dropped. Installs no files and can be uninstalled immediately afterwards.';
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
    'is_uninstallable'          => true,
    'published_date'            => date('Y-m-d H:i:s'),
    'type'                      => 'module',
    'version'                   => $version,
    // It creates none. The tables it drops are another package's, already
    // retired, and dropping them is this package's whole job rather than
    // something an uninstall prompt should be deciding.
    'remove_tables'             => 'false',
    'acceptable_sugar_versions' => array('regex_matches' => array($supportedVersionRegex)),
    'acceptable_sugar_flavors'  => $acceptableSugarFlavors,
);

// No 'copy': nothing lands in the instance, so nothing is left behind on
// uninstall. post_execute is addressed by <basepath> and runs from the
// unpacked package.
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

$zip->addFile('scripts/post_execute.php', 'scripts/post_execute.php');
echo " [*] scripts/post_execute.php\n";

$manifestContent = sprintf(
    "<?php\n\$manifest = %s;\n\$installdefs = %s;\n",
    var_export($manifest, true),
    var_export($installdefs, true)
);
$zip->addFromString('manifest.php', $manifestContent);
$zip->close();

echo "Done. Wrote {$zipPath}\n";
exit(0);
