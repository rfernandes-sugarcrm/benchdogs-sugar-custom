<?php
/*
 * scanner_oracle.php - run SugarCRM's REAL package scanner, not a copy of it.
 *
 * NOT RUN IN CI, and it cannot be: it needs a SugarEnt source tree (for the
 * scanner classes and the nikic/php-parser that ships in its vendor/), which
 * this repository does not carry. The tests that use it skip without one.
 *
 * WHY IT EXISTS. scripts/mlp_lint.py carries TRANSCRIPTIONS of ModuleScanner's
 * deny-lists and a Python lexer that imitates how the scanner finds calls.
 * Twice a transcription was incomplete and a package the linter passed was
 * refused at upload (1.1.24-rc29, array_filter; 1.1.100, G222,
 * stream_resolve_include_path). A transcription can only be checked against
 * the source it came from, so this asks the source: it loads Sugar's own
 * CodeScanner, BlacklistVisitor, DynamicNameVisitor, IncludesVisitor and
 * ManifestScanner and reads the lists out of ModuleScanner.php by reflection,
 * configured the way SugarCloud runs them:
 *
 *   EnhancedModuleChecks ON   $unsafeHttpClientFunctions merged into $blackList
 *                             (ModuleScanner.php:610-611)
 *   SecureSmarty ON           smarty, sugar_smarty, sugarpdfsmarty denied (:614-616)
 *   StrictIncludes ON         IncludesVisitor registered (:627-629)
 *   TranslateMLPCode OFF      DynamicNameVisitor registered (:624-626)
 *   StrictManifestChecks ON   ManifestScanner run on manifest.php (:634-636)
 *
 * and replays ModuleScanner::scanFile per entry: the $validExt check, the
 * "only .php files can contain PHP code" check, then the code scan. It does
 * NOT run Rector, HealthCheck, or a tenant's sugar_config overrides.
 *
 * Verified 2026-09-21 against the two ERP-Epicor 1.1.100 zips SugarCloud
 * refused: it reports exactly the refusal (QuotesErpActionsApi.php lines 2870
 * and 3165), and nothing on the packages SugarCloud accepted.
 *
 * usage: php scripts/tests/scanner_oracle.php <SugarEnt root> <zip | dir | file.php>
 *        php scripts/tests/scanner_oracle.php <SugarEnt root> - --lists
 * Paths must be absolute: Sugar's bootstrap changes directory.
 * Output: one JSON object per issue, {"file": ..., "line": ..., "msg": ...}.
 */

$root = $argv[1];
$target = $argv[2];
require $root . '/vendor/autoload.php';
foreach (glob($root . '/src/Security/ModuleScanner/Issues/*.php') as $f) {
    require_once $f;
}
foreach (['ForbiddenStatementVisitor', 'CodeScanner', 'BlacklistVisitor', 'DynamicNameVisitor', 'IncludesVisitor', 'ManifestScanner'] as $c) {
    require_once $root . "/src/Security/ModuleScanner/$c.php";
}
if (!function_exists('is_absolute_path')) { require_once $root . '/include/utils/file_utils.php'; }
require_once $root . '/ModuleInstall/ModuleScanner.php';

use Sugarcrm\Sugarcrm\Security\ModuleScanner\{CodeScanner, BlacklistVisitor, DynamicNameVisitor, IncludesVisitor, ManifestScanner};

$defaults = (new ReflectionClass('ModuleScanner'))->getDefaultProperties();
$functions = array_values(array_diff(array_merge($defaults['blackList'], $defaults['unsafeHttpClientFunctions']), $defaults['blackListExempt']));
$classes = array_values(array_merge(array_diff($defaults['classBlackList'], $defaults['classBlackListExempt']), ['smarty', 'sugar_smarty', 'sugarpdfsmarty']));
$methods = $defaults['methodsBlackList'];
$validExt = $defaults['validExt'];

if (in_array('--lists', $argv, true)) {
    echo json_encode([
        'blackList' => $defaults['blackList'],
        'unsafeHttpClientFunctions' => $defaults['unsafeHttpClientFunctions'],
        'classBlackList' => $defaults['classBlackList'],
        'methodsBlackList' => $defaults['methodsBlackList'],
        'validExt' => $validExt,
    ], JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$scanner = (new CodeScanner())
    ->registerVisitor(new DynamicNameVisitor())
    ->registerVisitor(new IncludesVisitor())
    ->registerVisitor(new BlacklistVisitor($classes, $functions, $methods));

function emit($file, $msg)
{
    $line = preg_match('/on line (\d+)/', $msg, $m) ? (int)$m[1] : 0;
    echo json_encode(['file' => $file, 'line' => $line, 'msg' => $msg]), "\n";
}

$entries = [];
if (preg_match('/\.zip$/i', $target)) {
    $zip = new ZipArchive();
    if ($zip->open($target) !== true) {
        fwrite(STDERR, "cannot open $target\n");
        exit(2);
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (substr($name, -1) === '/') {
            continue;
        }
        $entries[$name] = $zip->getFromIndex($i);
    }
} elseif (is_dir($target)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $entries[substr($f->getPathname(), strlen(rtrim($target, '/')) + 1)] = file_get_contents($f->getPathname());
        }
    }
    ksort($entries);
} else {
    $entries[basename($target)] = file_get_contents($target);
}

foreach ($entries as $name => $contents) {
    // ModuleScanner::isValidExtension
    $lower = strtolower($name);
    $pi = pathinfo($lower);
    if ($pi['basename'] !== 'license' && (empty($pi['extension']) || $pi['basename'] === 'files.md5' || !in_array($pi['extension'], $validExt))) {
        emit($name, 'ML_INVALID_EXT');
        continue;
    }
    if ($name === 'manifest.php') {
        foreach ((new ManifestScanner())->scan($contents) as $issue) {
            emit($name, 'MANIFEST: ' . $issue->getMessage());
        }
    }
    // ModuleScanner::scanFile
    $isPhp = pathinfo($name, PATHINFO_EXTENSION) === 'php';
    if (!$isPhp && (str_contains($contents, '<?php') || str_contains($contents, '<?='))) {
        try {
            (new PhpParser\ParserFactory())->createForHostVersion()->parse($contents);
            emit($name, 'Only .php files can contain PHP code');
        } catch (\Throwable $e) {
        }
        continue;
    }
    foreach ($scanner->scan($contents) as $issue) {
        emit($name, $issue->getMessage());
    }
}
