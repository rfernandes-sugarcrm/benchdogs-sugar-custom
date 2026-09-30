#!/usr/bin/env php
<?php
// One-off (G507): moves the two customer-group fields out of the Account record header; no copy installdef, so its uninstall restores nothing.

$packageID      = 'oneoff_move_bd_customer_group';
$packageLabel   = 'One-off: Move Bench Dogs Customer Group out of the Account header (G507)';
$description    = 'One-off layout repair for a tenant WITH Bench Dogs (G507). Removes bd_customer_group and '
    . 'bd_customer_group_code from the Account record view HEADER, where they render unlabelled, and places '
    . 'them, labelled, on the record\'s first tab (Overview) after Industry. A field an admin placed elsewhere '
    . 'is kept (only its header duplicate goes); a field with no vardef is taken out of the header and placed '
    . 'nowhere. Touches nothing else. Installs no file, creates or drops no table, writes no record. '
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
    // NO 'dependencies': it must work under Bench Dogs rc69 as well as rc72, in
    // either install order, and it places nothing where Bench Dogs is absent.
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
