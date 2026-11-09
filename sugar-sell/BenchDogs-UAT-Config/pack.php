#!/usr/bin/env php
<?php
// Builds the UAT configuration MLP: custom/ is copied as is, language/ goes through the manifest's language section,
// scripts/ carries the post-install step that sets the navigation bar and the hidden subpanels.
// The id stays the same from version to version: Module Loader installs a higher version with the same id as an
// upgrade of the installed one, and a new id would install beside it instead.

$packageID      = 'sugarai_benchdogs_uat_config';
$packageLabel   = 'SugarAI: Bench Dogs UAT Config';
$description    = 'The Bench Dogs UAT configuration: Studio layouts for Accounts, Contacts, Opportunities, Quotes, Quoted Line Items, ERP Orders, Meetings and Users (record, list, preview, dashlet, popup and filter views), Meetings shown as Interactions with a new Interaction Type dropdown field, the Industry, Lead Source and Interaction Type lists and their colours, field settings on Accounts and Leads, the Quick Create menu (Opportunities, Documents, Emails, Tasks and Notes shown in that order; Calls, Cases, Contacts, Leads and Meetings hidden), and a post-install step that sets the 13 navigation tabs and hides 14 subpanels.';
$supportedVersionRegex = '(26|25|14)\\..*$';
$acceptableSugarFlavors = array('ENT', 'ULT', 'PRO');

/** Stops the build: $message on stderr and a failing exit status (die() with a string exits 0). */
function fail(string $message): void
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

/** 1980-01-01 00:00:00 UTC, the earliest time a zip entry can store. */
const EARLIEST_ZIP_TIME = 315532800;

/** Every entry's Unix mode: a regular file, rw-r--r--. */
const PLAIN_FILE = 0100644;

/** What git prints for $args in this folder, trimmed, or null when git is missing or the command fails. */
function gitOutput(array $args): ?string
{
    $git = @proc_open(
        array_merge(array('git'), $args),
        array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
        $pipes
    );
    if (!is_resource($git)) {
        return null;
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return proc_close($git) === 0 ? trim((string) $out) : null;
}

/**
 * The time the package is dated by: SOURCE_DATE_EPOCH when set, else the committer time of the last commit that
 * changed the version file. Never the clock, so every build of one version from one tree is the same bytes.
 */
function buildDate(bool $versionIsTheFiles): int
{
    $howTo = 'Build in a full git clone, or set SOURCE_DATE_EPOCH to the seconds since 1970 to date the package by.';
    $given = getenv('SOURCE_DATE_EPOCH');
    if ($given !== false && $given !== '') {
        if (preg_match('/\A[0-9]+\z/', $given) !== 1 || (int) $given < EARLIEST_ZIP_TIME) {
            fail("SOURCE_DATE_EPOCH must be whole seconds since 1970, from 1980 on; got '{$given}'.");
        }
        return (int) $given;
    }
    if (!$versionIsTheFiles) {
        fail("no commit released a version given on the command line. {$howTo}");
    }
    $shallow = gitOutput(array('rev-parse', '--is-shallow-repository'));
    if ($shallow === null) {
        fail("there is no git history here to date the package by. {$howTo}");
    }
    if ($shallow !== 'false') {
        // git log would answer with the edge of the fetched history, not the commit that released the version.
        fail("this is a shallow clone. {$howTo}");
    }
    if (gitOutput(array('status', '--porcelain', '--', 'version')) !== '') {
        fail("the version file is not as a commit has it (edited, or never committed). {$howTo}");
    }
    $epoch = gitOutput(array('log', '-1', '--format=%ct', '--', 'version'));
    if ($epoch === null || preg_match('/\A[0-9]+\z/', $epoch) !== 1) {
        fail("no commit has the version file. {$howTo}");
    }

    return (int) $epoch;
}

/**
 * Every file under $root as [real path, path from the package root with '/' separators], in name order.
 * @return array<int, array{0: string, 1: string}>
 */
function filesUnder(string $root): array
{
    $rootReal = realpath($root);
    if (!$rootReal) {
        return array();
    }
    $out = array();
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootReal, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $real = $file->getRealPath();
        $out[] = array($real, str_replace(DIRECTORY_SEPARATOR, '/', $root . str_replace($rootReal, '', $real)));
    }
    usort($out, function (array $a, array $b) {
        return strcmp($a[1], $b[1]);
    });

    return $out;
}

$fileVersion = file_exists('version') ? trim(file_get_contents('version')) : '';
$version = empty($argv[1]) ? $fileVersion : $argv[1];
if (empty($version)) {
    fail("no version. Usage: {$argv[0]} [version]");
}
$sourceDate = buildDate($version === $fileVersion);

$dir = 'releases';
if (!is_dir($dir) && !mkdir($dir)) {
    fail("could not create the {$dir} folder");
}

$manifest = array(
    'key'                       => $packageID,
    'name'                      => $packageLabel,
    'description'               => $description,
    'author'                    => 'SugarCRM, Inc.',
    'is_uninstallable'          => true,
    'published_date'            => gmdate('Y-m-d H:i:s', $sourceDate),
    'type'                      => 'module',
    'version'                   => $version,
    'remove_tables'             => 'prompt',
    'acceptable_sugar_versions' => array('regex_matches' => array($supportedVersionRegex)),
    'acceptable_sugar_flavors'  => $acceptableSugarFlavors,
    // The layouts were taken against these versions; a tenant without them is refused before any file is copied.
    'dependencies'              => array(
        // erp_last_sale_date, read by the Days Since Last Sale formula, and the ERP fields on every layout.
        array('id_name' => 'sugarai_erp_epicor', 'version' => '1.2.120'),
        // The Suspect account type, the customer-group fields, and since 1.0.2 each quote line's own Project.
        array('id_name' => 'sugarai_benchdogs_ext', 'version' => '0.9.46-rc117'),
        // health_c, days_since_last_interaction_c and days_since_last_sale_c, whose settings this package changes.
        array('id_name' => 'Bench_Dogs_Account_Contact_Fields', 'version' => '1.0.1'),
    ),
);

$installdefs = array(
    'id'             => $packageID,
    // No bean of its own: no table and no module tab.
    'beans'          => array(),
    'copy'           => array(),
    'language'       => array(),
    // The Meetings field, as Studio's export declares one; its column is made by the loader, its settings come
    // from the copied sugarfield_interaction_type_c.php and its label from the Meetings language file.
    'custom_fields'  => array(
        array(
            'name'           => 'interaction_type_c',
            'label'          => 'LBL_INTERACTION_TYPE',
            'comments'       => '',
            'help'           => '',
            'module'         => 'Meetings',
            'type'           => 'enum',
            'max_size'       => '100',
            'require_option' => '0',
            'default_value'  => '',
            'audited'        => '0',
            'mass_update'    => '1',
            'duplicate_merge' => '1',
            'reportable'     => '1',
            'importable'     => 'true',
            'ext1'           => 'interaction_type_list',
            'ext2'           => '',
            'ext3'           => '',
            'ext4'           => '',
        ),
    ),
    // Not scripts/post_install.php: PackageManager also includes that reserved name, so it would run twice.
    'post_execute'   => array('<basepath>/scripts/post_execute.php'),
);

// Every file under custom/ is copied to the same path.
foreach (filesUnder('custom') as $file) {
    $installdefs['copy'][] = array(
        'from' => "<basepath>/{$file[1]}",
        'to'   => $file[1],
    );
}

// language/<module or application>/<language>.lang.php, installed as <language>.<package id>.php for that module.
foreach (filesUnder('language') as $file) {
    if (preg_match('#\Alanguage/([A-Za-z0-9_]+)/([a-z]{2}_[A-Za-z]{2})\.lang\.php\z#', $file[1], $m) !== 1) {
        fail("{$file[1]} is not language/<module>/<language>.lang.php");
    }
    $installdefs['language'][] = array(
        'from'      => "<basepath>/{$file[1]}",
        'to_module' => $m[1],
        'language'  => $m[2],
    );
}

$manifestContent = sprintf(
    "<?php\n\$manifest = %s;\n\$installdefs = %s;\n",
    var_export($manifest, true),
    var_export($installdefs, true)
);

$zipPath = "{$dir}/{$packageID}-{$version}.zip";
if (file_exists($zipPath)) {
    fail("{$zipPath} already exists. Delete it or bump the version.");
}

// custom/, language/ and scripts/ ship at the zip root, where <basepath> resolves. Each entry is [file, null] or
// [null, content], keyed by its name and written in name order.
$entries = array();
foreach (array_merge(filesUnder('custom'), filesUnder('language'), filesUnder('scripts')) as $file) {
    $entries[$file[1]] = array($file[0], null);
}
$entries['manifest.php'] = array(null, $manifestContent);
ksort($entries, SORT_STRING);

// libzip stores an entry's time as local time; in UTC it is the same wherever the build runs.
putenv('TZ=UTC');

echo "Creating {$zipPath}, dated " . gmdate('Y-m-d H:i:s', $sourceDate) . " UTC ...\n";
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fail("could not open {$zipPath} for writing");
}

foreach ($entries as $name => $entry) {
    $name = (string) $name;
    $added = $entry[0] !== null ? $zip->addFile($entry[0], $name) : $zip->addFromString($name, $entry[1]);
    if (!$added
        || !$zip->setMtimeName($name, $sourceDate)
        || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, PLAIN_FILE << 16)) {
        // Closed and deleted here: left open, PHP writes the entries added so far as it exits.
        $zip->close();
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }
        fail("could not add {$name} to {$zipPath}");
    }
    echo " [*] {$name}\n";
}
if (!$zip->close()) {
    fail("could not write {$zipPath}");
}

echo "Done. Wrote {$zipPath}\n";
exit(0);
