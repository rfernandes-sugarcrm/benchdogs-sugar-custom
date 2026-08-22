#!/usr/bin/env php
<?php
/**
 * BenchDogs-Ext Package Builder
 * Bench Dogs ERP reflection modules (bd01_ERP_Quote / _Line / _Cost), their
 * relationships, Quotes bd_* reflection fields, and the after_save reflection
 * hook. Modeled on CORE-ShippingAddresses/pack.php, with the relationship
 * installdefs blocks from ERP-Epicor/pack.php.
 */

$packageID      = 'sugarai_benchdogs_ext';
$packageLabel   = 'SugarAI: Bench Dogs Extensions';
$description    = 'Bench Dogs ERP quote reflection: bd01_ERP_Quote/_Line/_Cost modules synced from the ERP, bd_* reflection fields on Quotes, and an after_save hook that reflects ERP stage/totals onto the linked Quote and its primary Opportunity.';
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
);

$installdefs = array(
    'id'           => $packageID,
    'beans'        => array(
        array(
            'module' => 'bd01_ERP_Quote',
            'class'  => 'bd01_ERP_Quote',
            'path'   => 'modules/bd01_ERP_Quote/bd01_ERP_Quote.php',
            'tab'    => true,
        ),
        array(
            'module' => 'bd01_ERP_Quote_Line',
            'class'  => 'bd01_ERP_Quote_Line',
            'path'   => 'modules/bd01_ERP_Quote_Line/bd01_ERP_Quote_Line.php',
            'tab'    => true,
        ),
        array(
            'module' => 'bd01_ERP_Quote_Cost',
            'class'  => 'bd01_ERP_Quote_Cost',
            'path'   => 'modules/bd01_ERP_Quote_Cost/bd01_ERP_Quote_Cost.php',
            'tab'    => true,
        ),
    ),
    'copy'         => array(),
    'post_execute' => array('<basepath>/scripts/post_install.php'),
);

// Add the new modules' own files
$modulesReal = realpath('modules');
if ($modulesReal) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesReal, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $real = $file->getRealPath();
        $relInZip = 'modules' . str_replace($modulesReal, '', $real);
        $relInZip = str_replace(DIRECTORY_SEPARATOR, '/', $relInZip);
        $installdefs['copy'][] = array(
            'from' => "<basepath>/{$relInZip}",
            'to'   => $relInZip,
        );
    }
}

// Add custom/ files (Extension seams: Quotes bd_* fields + dropdown, the
// dual-path relationship vardef copies, the hook registration + class, and
// the layout script class)
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
// Relationships (Studio-style metadata) - block shapes from ERP-Epicor/pack.php
// ---------------------------------------------------------------------------

$knownModules = array('bd01_ERP_Quote_Line', 'bd01_ERP_Quote_Cost', 'bd01_ERP_Quote', 'Quotes');
usort($knownModules, function ($a, $b) { return strlen($b) - strlen($a); });

$extractTrailing = function (string $base) use ($knownModules) {
    foreach ($knownModules as $m) {
        $needle = '_' . $m;
        if (substr($base, -strlen($needle)) === $needle) {
            return $m;
        }
    }
    return null;
};

$extractLeading = function (string $base) use ($knownModules) {
    foreach ($knownModules as $m) {
        $needle = $m . '.';
        if (strpos($base, $needle) === 0) {
            return $m;
        }
    }
    return null;
};

$installdefs['relationships'] = array();
foreach (glob('relationships/relationships/*MetaData.php') as $f) {
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', $f);
    $installdefs['relationships'][] = array(
        'meta_data' => "<basepath>/{$rel}",
    );
}

$installdefs['vardefs'] = array();
foreach (glob('relationships/vardefs/*.php') as $f) {
    $rel  = str_replace(DIRECTORY_SEPARATOR, '/', $f);
    $base = basename($f, '.php');
    $toModule = $extractTrailing($base);
    if ($toModule === null) {
        die("ERROR: cannot determine to_module for {$rel}\n");
    }
    $installdefs['vardefs'][] = array('from' => "<basepath>/{$rel}", 'to_module' => $toModule);
}

$installdefs['layoutdefs'] = array();
foreach (glob('relationships/layoutdefs/*.php') as $f) {
    $rel  = str_replace(DIRECTORY_SEPARATOR, '/', $f);
    $base = basename($f, '.php');
    $toModule = $extractTrailing($base);
    if ($toModule === null) {
        die("ERROR: cannot determine to_module for {$rel}\n");
    }
    $installdefs['layoutdefs'][] = array('from' => "<basepath>/{$rel}", 'to_module' => $toModule);
}

$installdefs['language'] = array();
foreach (glob('relationships/language/*.php') as $f) {
    $rel  = str_replace(DIRECTORY_SEPARATOR, '/', $f);
    $base = basename($f, '.php');
    $toModule = $extractLeading($base);
    if ($toModule === null) {
        die("ERROR: cannot determine to_module for {$rel}\n");
    }
    $installdefs['language'][] = array(
        'from'      => "<basepath>/{$rel}",
        'to_module' => $toModule,
        'language'  => 'en_us',
    );
}

// ---------------------------------------------------------------------------
// Build the zip
// ---------------------------------------------------------------------------

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

// modules/ + custom/ + relationships/ + scripts/ all ship at the zip root:
// copy entries and the relationship/vardef/layoutdef/language/post_execute
// installdef paths all resolve against <basepath>.
$addTree('modules');
$addTree('custom');
$addTree('relationships');
$addTree('scripts');

// Generate manifest
$manifestContent = sprintf(
    "<?php\n\$manifest = %s;\n\$installdefs = %s;\n",
    var_export($manifest, true),
    var_export($installdefs, true)
);
$zip->addFromString('manifest.php', $manifestContent);
$zip->close();

echo "Done. Wrote {$zipPath}\n";
exit(0);
