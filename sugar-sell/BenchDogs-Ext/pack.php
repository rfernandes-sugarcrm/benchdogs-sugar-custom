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
    // The governing provider implements a neutral contract introduced by
    // shared ERP-Epicor. Refuse unsafe install order before copying any file.
    'dependencies'              => array(
        array(
            'id_name' => 'sugarai_erp_epicor',
            'version' => '1.1.24-rc4',
        ),
        array(
            'id_name' => 'sugarai_erp_epicor_partialfulfillment',
            'version' => '1.0.12',
        ),
    ),
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
// Relationships (Studio-style metadata) - block shapes from ERP-Epicor/pack.php
// ---------------------------------------------------------------------------

$knownModules = array('bd01_ERP_Quote_Line', 'bd01_ERP_Quote_Cost', 'bd01_ERP_Quote', 'Quotes', 'Accounts');
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

// moduleList entries: without an 'application' language file the modules have
// no route and Studio will not list them, however cleanly the beans install.
foreach (glob('language/application/*.lang.php') as $f) {
    $rel      = str_replace(DIRECTORY_SEPARATOR, '/', $f);
    $language = preg_replace('/\.lang\.php$/', '', basename($f));
    $installdefs['language'][] = array(
        'from'      => "<basepath>/{$rel}",
        'to_module' => 'application',
        'language'  => $language,
    );
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

// modules/ + custom/ + relationships/ + language/ + scripts/ all ship at the
// zip root: copy entries and the relationship/vardef/layoutdef/language/
// post_execute installdef paths all resolve against <basepath>.
$addTree('modules');
$addTree('custom');
$addTree('relationships');
$addTree('language');
$addTree('scripts');

$zip->addFromString('manifest.php', $manifestContent);
$zip->close();

echo "Done. Wrote {$zipPath}\n";
exit(0);
