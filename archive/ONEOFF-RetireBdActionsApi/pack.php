#!/usr/bin/env php
<?php
/**
 * Build ONEOFF-RetireBdActionsApi (G437): a disposable one-off that deletes
 * custom/clients/base/api/BdBenchDogsActionsApi.php - and nothing else - on a
 * tenant that NO LONGER has Bench Dogs installed (et). See scripts/post_execute.php
 * and README.md.
 *
 * Same shape as ONEOFF-RetireBdResidue (whose 1.0.2 deliberately kept this path):
 *     installdefs['copy']  = ABSENT  (not empty-array: absent)
 * so uninstall_copy() has nothing to walk and nothing to restore - uninstalling
 * this package is a genuine no-op and cannot put the file back.
 *
 * Usage: php pack.php [version]   (default: the `version` file)
 * Writes releases/oneoff_retire_bd_actions_api-<version>.zip
 */

$packageID      = 'oneoff_retire_bd_actions_api';
$packageLabel   = 'One-off: Retire Bench Dogs Actions API (et only)';
$description    = 'One-off cleanup for a tenant that has UNINSTALLED Bench Dogs (et, G437). Deletes the '
    . 'orphaned custom/clients/base/api/BdBenchDogsActionsApi.php, which a Bench Dogs uninstall restored and '
    . 'which still registers three REST routes (bd-create-opp-quote, bd-send-to-estimating, bd-tools/repair-ui); '
    . 'the install\'s own REST dictionary rebuild then unregisters them. It deletes NOTHING on a tenant where '
    . 'Bench Dogs is installed (there the file belongs to the Bench Dogs package, which ships it empty on '
    . 'purpose) and says so in its install log. Installs no file, creates or drops no table, writes no record. '
    . 'Idempotent. Uninstall it immediately afterwards; there is nothing to take back out.';
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
    // TRUE, and safe precisely because there is no 'copy'.
    'is_uninstallable'          => true,
    'published_date'            => date('Y-m-d H:i:s'),
    'type'                      => 'module',
    'version'                   => $version,
    // It creates none, so there is nothing for a prompt to ask about.
    'remove_tables'             => 'false',
    'acceptable_sugar_versions' => array('regex_matches' => array($supportedVersionRegex)),
    'acceptable_sugar_flavors'  => $acceptableSugarFlavors,
    // NO 'dependencies': it must run on a tenant whose Bench Dogs is already gone.
);

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

// Named explicitly rather than walked: this package ships exactly one script.
$files = array(
    'scripts/post_execute.php',
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
