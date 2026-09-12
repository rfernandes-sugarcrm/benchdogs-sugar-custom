#!/usr/bin/env php
<?php
/**
 * ONEOFF-RetireBdQuoteMirror package builder.
 *
 * A disposable repair, not a product package. It installs no files, adds no
 * modules, creates no tables and registers no hooks: the zip contains a
 * manifest and one post_execute script, which Module Loader runs from the
 * unpacked package and never copies anywhere.
 *
 * That is what makes it clean to remove. `copy` is empty, so uninstall_copy
 * has nothing to delete and nothing to restore; uninstalling is a genuine
 * no-op. Install it, let it run, uninstall it.
 *
 * See scripts/post_execute.php for what it repairs, why it refuses to run
 * before the mirror modules are deregistered, and why it cannot simply delete
 * the files itself.
 */

$packageID      = 'oneoff_retire_bd_quote_mirror';
$packageLabel   = 'One-off: Retire Bench Dogs Quote Mirror Relationships';
// Names the relationships it retires, and refers to the retired modules only
// in lower case. A description that spells them the way a module is spelled
// reads as a claim to INSTALL those modules, which is what MLP008 checks for
// and what once sent a reviewer looking for code nobody had written.
$description    = 'One-off repair. Retires the orphaned bd01_erp_quote_quotes, '
    . 'bd01_erp_quote_accounts, bd01_erp_quote_lines and bd01_erp_line_costs '
    . 'relationship definitions, left behind when the bench dogs quote mirror '
    . 'modules are retired without an uninstall. While they are present, every '
    . 'Module Loader install reports success and is then rolled back, because '
    . 'the rebuild that ends an install fatals on the dangling link. Installs '
    . 'no files, creates no modules, drops no tables, and can be uninstalled '
    . 'immediately afterwards. Refuses to run while the mirror is still '
    . 'registered.';
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
    // It creates none, so there is nothing for a prompt to ask about.
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
