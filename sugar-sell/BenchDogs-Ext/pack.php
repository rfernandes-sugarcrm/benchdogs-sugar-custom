#!/usr/bin/env php
<?php
// Builds the Bench Dogs extension MLP: custom/ is copied as is, scripts/ carries the three lifecycle scripts. (G280 / 🔒 1567)

$packageID      = 'sugarai_benchdogs_ext';
$packageLabel   = 'SugarAI: Bench Dogs Extensions';
$description    = 'Bench Dogs extensions for Sugar Sell: the two customer-group fields (a Cust. Group picker before the account is in the ERP, required for an ADM Customer and asked for before the quote offers to create an ADM account in the ERP) and the Suspect account type on Accounts, and the ADM company\'s own quote values (Lead Source, Lead Type, Project, Marketing Campaign and Marketing Event pickers, required until the quote is in the ERP and defaulted from the account\'s last quote; Reference and Project defaults; the Reference placement and requirement; and, when an order converts an ADM ERP quote, ADM\'s Ship Via named in ERP-Epicor\'s one pre-send order refusal beside the FOB; and a hidden lead baseline on the quote, stamped at the first Send to Estimation, so the ERP read-back keeps a seller\'s later edit), and the Epicor contact Function, Role and primary flags (Bench Dogs\' own contact fields) shown on the Contacts record view where the tenant has them, Role editable and the others read-only; and, because Bench Dogs sellers do not apply discounts, no discount panel, order-level discount or line discount shown on a quote or in the Quotes list preview, with the subtotal and line total captioned Subtotal and Total; and the two fields the Bench connector extension needs to give an Epicor order with no Sugar quote, and an Epicor-born quote, exactly one Opportunity: the Opportunities key it finds that Opportunity by, and the Opportunity an ERP Orders record sits under (read-only, on no layout); and no Ship Via requirement on a quote: Ship Via is checked only when the seller presses Submit Order or Order Selected Lines; which company is ADM\'s is read from the companies the Bench connector extension publishes; and a default owner for ERP-created records: an administrator picks an active user on a Bench Dogs page in Admin, and an Opportunity or ERP order the integration saves with no owner takes that user; a seller\'s own save is never filled, and an owner that is set is never changed, with its link in Admin\'s ERP Integration group; and Bench Dogs\' Kinetic fields on quotes, quoted line items and ERP orders, created where a tenant lacks them and shown on their record views; and Bench Dogs\' Opportunity stages and probabilities; and Bench Dogs\' own quote layout as a PDF Manager template on Quotes, with their logo, created on install and unpublished on uninstall; and each quote line\'s Product Group (the Epicor group the sync stores as its category) shown read-only on the quote line grid, with Category named Product Group on quoted line items and products; and each quote line\'s own Project (an ADM project picker on the line grid and the line record, blank by default; the quote-level Project leaves the quote screen, and the upgrade copies its value once onto the quote\'s lines with none); and an account\'s billing street, city, postal code and country required when a person saves it (never on a server save); and the Kinetic part number shown in the Product Catalog list and its Search and Select list; and Needs Price Estimate as the default of the quote Create Opportunity & Quote makes (ERP-Epicor\'s own setting, set once by the upgrade); and the quote\'s Best Case % editable; and no Expires Date on the quote\'s views (Valid Until is the same date; the field and its data stay), with the Due right after Valid Until; and a new Opportunity or Quote owned by its account\'s owner (an active user; a quote\'s account is its Billing Account, else its Account Name) unless the person picks another owner on the create form; and the quote\'s Project Name (Kinetic\'s Quote Comment, a new text field created where a tenant lacks it) on the quote\'s record view and create form, and printed on the quote PDF\'s Project row.';
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
 * The time the package is dated by, in seconds since 1970: SOURCE_DATE_EPOCH when set, else the committer time of
 * the last commit that changed the version file, i.e. the commit that released the version. Never the clock, so
 * every build of one version from one tree is the same bytes; when git cannot give the date, the build stops.
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
 * Every file under $root as [real path, path from the package root with '/' separators], in name order, so the
 * copy list and the zip are the same whatever order the file system lists a folder in. An absent folder holds none.
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
foreach (filesUnder('custom') as $file) {
    $installdefs['copy'][] = array(
        'from' => "<basepath>/{$file[1]}",
        'to'   => $file[1],
    );
}

// scripts/ reaches the instance only through the lifecycle installdefs above.

$manifestContent = sprintf(
    "<?php\n\$manifest = %s;\n\$installdefs = %s;\n",
    var_export($manifest, true),
    var_export($installdefs, true)
);

$zipPath = "{$dir}/{$packageID}-{$version}.zip";
if (file_exists($zipPath)) {
    fail("{$zipPath} already exists. Delete it or bump the version.");
}

// custom/ and scripts/ ship at the zip root, where <basepath> resolves. Each entry is [file, null] or
// [null, content], keyed by its name and written in name order.
$entries = array();
foreach (array_merge(filesUnder('custom'), filesUnder('scripts')) as $file) {
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
