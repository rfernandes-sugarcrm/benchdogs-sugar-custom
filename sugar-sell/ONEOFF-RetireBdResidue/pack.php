#!/usr/bin/env php
<?php

// One-off (🔒2126b): removes what earlier Bench Dogs versions installed and the current one no longer ships; the zip is scripts/ plus a placeholder tree.

$packageID      = 'oneoff_retire_bd_residue';
$packageLabel   = 'One-off: Retire Bench Dogs Residue';
// Names what it removes in lower case, never a module the way a module is spelled (MLP008).
$description    = 'One-off cleanup. Removes the files and deployed metadata that earlier '
    . 'versions of the bench dogs package installed and that the current version no longer ships '
    . '(a hardcoded list): retired extension fragments, class files and button field directories, '
    . 'the accumulated stage vocabulary fragment, the bench dogs panel on the deployed quotes record '
    . 'view and the retired value-source marker on the deployed opportunities record view. Three paths '
    . 'other writers share are deleted only when they hold a body bench dogs shipped. Installs no file, '
    . 'changes no data, and can be uninstalled right afterwards; a second run reports nothing left to remove.';
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

// Only after the Bench Dogs release built from this same tree: before it, some listed files are still that version's own.
$benchVersion = trim((string) @file_get_contents(__DIR__ . '/../BenchDogs-Ext/version'));
if ($benchVersion === '') {
    die("Error: cannot read ../BenchDogs-Ext/version.\n");
}

$leftovers = require __DIR__ . '/scripts/leftovers.php';
$placeholders = array();
foreach ($leftovers['remove'] as $path) {
    $placeholders[] = 'leftovers/' . $path;
}
// Each guarded path gets its own subtree, so post_execute can remove it alone (scripts/post_execute.php step 2).
$guard = 0;
foreach (array_keys($leftovers['remove_if_bench']) as $path) {
    $placeholders[] = 'leftovers/if-bench/' . $guard++ . '/' . $path;
}
foreach (array_merge($leftovers['remove'], array_keys($leftovers['remove_if_bench'])) as $path) {
    if (strpos($path, 'custom/') !== 0 || strpos($path, '..') !== false) {
        die("Error: {$path} is not a path under custom/.\n");
    }
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
    'remove_tables'             => 'false',
    'acceptable_sugar_versions' => array('regex_matches' => array($supportedVersionRegex)),
    'acceptable_sugar_flavors'  => $acceptableSugarFlavors,
    'dependencies'              => array(
        array('id_name' => 'sugarai_benchdogs_ext', 'version' => $benchVersion),
    ),
);

$installdefs = array(
    'id'           => $packageID,
    'post_execute' => array('<basepath>/scripts/post_execute.php'),
);

$zipPath = "{$dir}/{$packageID}-{$version}.zip";
if (file_exists($zipPath)) {
    die("Error: {$zipPath} already exists. Delete it or bump version.\n");
}

echo "Creating {$zipPath} (requires Bench Dogs >= {$benchVersion}) ...\n";
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);
$zip->addFile('scripts/post_execute.php', 'scripts/post_execute.php');
$zip->addFile('scripts/leftovers.php', 'scripts/leftovers.php');
foreach ($placeholders as $placeholder) {
    $zip->addFromString($placeholder, substr($placeholder, -4) === '.php' ? "<?php\n" : '');
}
$zip->addFromString('manifest.php', sprintf(
    "<?php\n\$manifest = %s;\n\$installdefs = %s;\n",
    var_export($manifest, true),
    var_export($installdefs, true)
));
$zip->close();
echo " [*] " . count($placeholders) . " placeholder(s)\n";
echo "Done. Wrote {$zipPath}\n";
exit(0);
