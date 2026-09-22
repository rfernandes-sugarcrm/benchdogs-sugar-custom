#!/usr/bin/env php
<?php
/**
 * BenchDogs-Ext Package Builder
 *
 * Extension-only package: the bd_* fields on Quotes, Accounts, Opportunities,
 * Products and Contacts, the ERP_Orders / ERP_OrderLines record surfaces, the
 * Bench Dogs quote-led REST actions, the estimating notification hook, and the
 * layout / dashboard deploy scripts. Modeled on CORE-ShippingAddresses/pack.php.
 *
 * It ships NO modules of its own. Decisions 901/903/904/905 retired the bd01
 * quote-mirror modules, which is why there are no `beans`, `relationships`,
 * `vardefs` or `layoutdefs` installdefs here any more: the Opportunity
 * contribution and release-stage contracts read the native Sugar quote lines
 * instead.
 */

$packageID      = 'sugarai_benchdogs_ext';
$packageLabel   = 'SugarAI: Bench Dogs Extensions';
$description    = 'Bench Dogs quote-led extensions for Sugar Sell: bd_* fields on Quotes, Accounts, Opportunities, Products and Contacts, the quote-led REST actions (create Opportunity + Quote, send to estimating), the estimating notification hook, and the Opportunity contribution / release-stage contracts resolved from the native Sugar quote lines.';
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
    'remove_tables'             => 'prompt',
    'acceptable_sugar_versions' => array('regex_matches' => array($supportedVersionRegex)),
    'acceptable_sugar_flavors'  => $acceptableSugarFlavors,
    // The governing provider implements a neutral contract introduced by
    // shared ERP-Epicor. Refuse unsafe install order before copying any file.
    'dependencies'              => array(
        array(
            'id_name' => 'sugarai_erp_epicor',
            'version' => '1.1.24-rc9',
        ),
        array(
            'id_name' => 'sugarai_erp_epicor_partialfulfillment',
            // 1.0.40 is the floor because rc65 STOPPED shipping the stage
            // vocabulary: PF now owns sales_stage_dom['Prototype Ordered'] and
            // ['Partial Production Ordered'], their 80/90 probabilities, their
            // two styles (G278 / 🔒 1506) and quote_stage_dom['Partially
            // Fulfilled'], which it has always owned. Installing this package
            // over an older PF would leave Bench Opportunities and quotes
            // holding stage values no dropdown serves.
            'version' => '1.0.40',
        ),
    ),
);

$installdefs = array(
    'id'           => $packageID,
    // Empty since the quote mirror was retired: this package installs no bean
    // of its own, so it creates no table and no module tab.
    'beans'        => array(),
    'copy'         => array(),
    'post_execute' => array('<basepath>/scripts/post_install.php'),
    // The uninstall counterpart to post_execute, in two halves because the two
    // jobs need opposite conditions. pre_uninstall undoes the DEPLOYED METADATA
    // post_install.php wrote - record-view panels, buttons, the fields the
    // uninstaller cannot see because they live in a file this package does not
    // ship - and needs the helper classes under custom/ still on disk.
    // post_uninstall rebuilds the caches and has to run after those same files
    // are gone, or it simply re-bakes what it is meant to clear.
    //
    // Their absence is what broke the instance the last time this package was
    // removed. See the docblock at the top of each script.
    'pre_uninstall'  => array('<basepath>/scripts/pre_uninstall.php'),
    'post_uninstall' => array('<basepath>/scripts/post_uninstall.php'),
);

// Add custom/ files (Extension seams: the bd_* fields + dropdowns, the hook
// registrations and their classes, the REST action class, the Opportunity
// contribution / release-stage contract classes, and the layout script
// classes). There is no modules/ tree to add: the package ships no module.
$customReal = realpath('custom');
if ($customReal) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($customReal, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $real = $file->getRealPath();
        $relInZip = 'custom' . str_replace($customReal, '', $real);
        $relInZip = str_replace(DIRECTORY_SEPARATOR, '/', $relInZip);
        $installdefs['copy'][] = array(
            'from' => "<basepath>/{$relInZip}",
            'to'   => $relInZip,
        );
    }
}

// These three ship via the post_execute / pre_uninstall / post_uninstall
// installdefs above, not as plain copy entries - excluded here so they aren't
// ALSO copied to custom/include/bd_scripts/, where nothing would ever run
// them. The uninstall pair matters more than the install one: a copy of
// pre_uninstall.php under custom/ would be deleted by the very uninstall it is
// supposed to run during.
$lifecycleScripts = array(
    'post_install.php',
    'pre_uninstall.php',
    'post_uninstall.php',
);

// Add scripts/ files (kept on the instance for later re-runs)
$scriptsReal = realpath('scripts');
if ($scriptsReal) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scriptsReal, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if (in_array($file->getFilename(), $lifecycleScripts, true)) {
            continue;
        }
        $real = $file->getRealPath();
        $relInZip = 'scripts' . str_replace($scriptsReal, '', $real);
        $relInZip = str_replace(DIRECTORY_SEPARATOR, '/', $relInZip);
        $installdefs['copy'][] = array(
            'from' => "<basepath>/{$relInZip}",
            'to'   => 'custom/include/bd_' . $relInZip,
        );
    }
}

// ---------------------------------------------------------------------------
// Build the zip
// ---------------------------------------------------------------------------

$manifestContent = sprintf(
    "<?php\n\$manifest = %s;\n\$installdefs = %s;\n",
    var_export($manifest, true),
    var_export($installdefs, true)
);

$zipPath = "{$dir}/{$packageID}-{$version}.zip";
if (file_exists($zipPath)) {
    die("Error: {$zipPath} already exists. Delete it or bump version.\n");
}

echo "Creating {$zipPath} ...\n";
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);

$addTree = function (string $rootName) use ($zip) {
    $rootReal = realpath($rootName);
    if (!$rootReal) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootReal, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $real = $file->getRealPath();
        $relInZip = $rootName . str_replace($rootReal, '', $real);
        $relInZip = str_replace(DIRECTORY_SEPARATOR, '/', $relInZip);
        $zip->addFile($real, $relInZip);
        echo " [*] {$relInZip}\n";
    }
};

// custom/ + scripts/ both ship at the zip root: the copy entries and the
// post_execute / pre_uninstall / post_uninstall installdef paths all resolve
// against <basepath>.
$addTree('custom');
$addTree('scripts');

$zip->addFromString('manifest.php', $manifestContent);
$zip->close();

echo "Done. Wrote {$zipPath}\n";
exit(0);
