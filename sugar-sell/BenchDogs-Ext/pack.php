#!/usr/bin/env php
<?php
// Builds the Bench Dogs extension MLP: custom/ is copied as is, scripts/ carries the three lifecycle scripts. (G280 / 🔒 1567)

$packageID      = 'sugarai_benchdogs_ext';
$packageLabel   = 'SugarAI: Bench Dogs Extensions';
$description    = 'Bench Dogs extensions for Sugar Sell: the two customer-group fields (a Cust. Group picker before the account is in the ERP, required for an ADM Customer and asked for before the quote offers to create an ADM account in the ERP) and the Suspect account type on Accounts, and the ADM company\'s own quote values (Lead Source, Lead Type, Project, Marketing Campaign and Marketing Event pickers, required until the quote is in the ERP and defaulted from the account\'s last quote; Reference and Project defaults; the Reference placement and requirement; and, when an order converts an ADM ERP quote, ADM\'s Ship Via named in ERP-Epicor\'s one pre-send order refusal beside the FOB; and a hidden lead baseline on the quote, stamped at the first Send to Estimation, so the ERP read-back keeps a seller\'s later edit), and the Epicor contact Function, Role and primary flags (Bench Dogs\' own contact fields) shown read-only on the Contacts record view where the tenant has them; and, because Bench Dogs sellers do not apply discounts, no discount panel, order-level discount or line discount shown on a quote or in the Quotes list preview, with the subtotal and line total captioned Subtotal and Total.';
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
    // Refuse unsafe install order before copying any file.
    'dependencies'              => array(
        array(
            // 1.2.4: ERP-Epicor asks OrderRequirements.php for ADM's Ship Via (G860, 🔒2179b); 1.2.0 brought the namespaced ErpQuoteFacts. (🔒2167b)
            'id_name' => 'sugarai_erp_epicor',
            'version' => '1.2.4',
        ),
        array(
            'id_name' => 'sugarai_erp_epicor_partialfulfillment',
            // PF owns the stage vocabulary, OpportunityContribution.php and the partial-order stage default this package stopped carrying. (G278, G282, G305)
            'version' => '1.0.50',
        ),
    ),
);

$installdefs = array(
    'id'           => $packageID,
    // No bean of its own: no table and no module tab.
    'beans'        => array(),
    'copy'         => array(),
    // Not scripts/post_install.php: PackageManager also includes that reserved name, so it would run twice. (G294)
    'post_execute' => array('<basepath>/scripts/post_execute.php'),
    // pre_uninstall needs this package's files on disk; post_uninstall must run after they are gone.
    'pre_uninstall'  => array('<basepath>/scripts/bd_pre_uninstall.php'),
    'post_uninstall' => array('<basepath>/scripts/post_uninstall.php'),
);

// Every file under custom/ is copied to the same path.
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

// scripts/ reaches the instance only through the lifecycle installdefs above.

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

// custom/ and scripts/ ship at the zip root, where <basepath> resolves.
$addTree('custom');
$addTree('scripts');

$zip->addFromString('manifest.php', $manifestContent);
$zip->close();

echo "Done. Wrote {$zipPath}\n";
exit(0);
