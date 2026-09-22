<?php
/**
 * ONEOFF-RetireBdResidue - IDEMPOTENCY AND COVERAGE HARNESS.
 *
 * NOT SHIPPED. pack.php names the four files it packs explicitly, so nothing
 * under tests/ ever reaches a zip; the build prints 5 entries and a 6th would be
 * the signal that this rule was broken.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT.
 *
 * It is NOT a fixture that asserts what I believe post_execute does. A fixture
 * cannot test the belief it encodes. The three platform methods the package
 * relies on - ModuleInstaller::uninstallExt(), ::uninstall_customizations() and
 * ::copy_path() - are reproduced here BY COPYING THEIR BODIES VERBATIM out of
 * SugarEnt-Full-26.1.0, together with the global helpers they call
 * (rmdir_recursive from include/dir_inc.php:96, copy_recursive from :13,
 * mkdir_recursive from :38, clean_path and isValidCopyPath from
 * include/utils/file_utils.php:20 and :40). Line references are on each method.
 * So when this harness says a path was removed, the code that removed it is
 * Sugar's, not mine.
 *
 * WHAT IT CANNOT TELL YOU, STATED PLAINLY:
 *   - It does not prove the package installs. Only Module Loader can say that,
 *     and only the owner runs installs (🔒 1515).
 *   - It does not exercise ModuleScanner. mlp_lint --zip does, with a known-bad
 *     control; see the build notes.
 *   - K-2, K-3 and K-5 are stubbed here, because they mutate deployed metadata
 *     rows and a language cache that exist only on a tenant. What IS tested is
 *     that the script reaches them, reports them, and survives their absence.
 *
 * HOW TO READ THE RESULT. Run it twice over one tree. The first run must remove
 * everything that is present; the second must remove nothing and print
 * "NOTHING LEFT TO REMOVE". That difference is the whole product of this package.
 *
 *     php tests/harness.php
 */

// ---------------------------------------------------------------------------
// Platform helpers - VERBATIM from SugarEnt-Full-26.1.0 unless marked.
// ---------------------------------------------------------------------------

// include/utils/file_utils.php:20-32 (is_windows() branch dropped: POSIX only here)
function clean_path($path)
{
    $path = str_replace('\\', '/', $path);
    $path = str_replace('//', '/', $path);
    $path = str_replace('/./', '/', $path);
    return $path;
}

// include/utils/file_utils.php:40-60
function isValidCopyPath($path)
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || $path[0] === '/') {
        return false;
    }
    if (preg_match('/(^|\/)\.\.(\/|$)/', $path)) {
        return false;
    }
    return true;
}

function sugar_is_dir($path, $mode = 'r')
{
    return is_dir($path);
}

function sugar_mkdir($path, $mode = null)
{
    return @mkdir($path, 0775, true);
}

// include/dir_inc.php:38-54 (simplified to the behaviour post_execute depends on)
function mkdir_recursive($path, $check_is_parent_dir = false)
{
    if ($check_is_parent_dir) {
        $path = dirname($path . '/x');
    }
    return is_dir($path) ? true : @mkdir($path, 0775, true);
}

// include/dir_inc.php:13-36
function copy_recursive($source, $dest)
{
    if (is_file($source)) {
        return (copy($source, $dest));
    }
    if (!sugar_is_dir($dest)) {
        sugar_mkdir($dest);
    }
    $status = true;
    $d = dir($source);
    if ($d === false) {
        return false;
    }
    while (false !== ($f = $d->read())) {
        if ($f == '.' || $f == '..') {
            continue;
        }
        $status &= copy_recursive("$source/$f", "$dest/$f");
    }
    $d->close();
    return $status;
}

// include/dir_inc.php:96-124 - THE FILE BRANCH IS THE ONE THAT MATTERS:
// rmdir_recursive() on a plain file unlinks it, which is how uninstallExt()
// removes a single Extension fragment.
function rmdir_recursive($path)
{
    if (is_file($path)) {
        return (unlink($path));
    }
    if (!is_dir($path)) {
        return false;
    }
    $status = true;
    $d = dir($path);
    while (($f = $d->read()) !== false) {
        if ($f == '.' || $f == '..') {
            continue;
        }
        $status &= rmdir_recursive("$path/$f");
    }
    $d->close();
    @rmdir($path);
    return $status;
}

if (!defined('DISABLED_PATH')) {
    define('DISABLED_PATH', 'Disabled');
}

class ModuleInstallerException extends Exception
{
}

/**
 * The three methods the package calls, VERBATIM from
 * ModuleInstall/ModuleInstaller.php at the line numbers given, with only the
 * translate()/log() calls stripped (they need a language pack, not a filesystem).
 */
class ModuleInstaller
{
    public $installdefs;          // :85  - PUBLIC, which is what lets the package supply a worklist
    public $base_dir = '';        // :80
    public $silent = false;
    public $id_name = '';

    // :675-712
    public function uninstallExt($section, $extname, $module = '')
    {
        if (isset($this->installdefs[$section])) {
            foreach ($this->installdefs[$section] as $item) {
                if (isset($item['from'])) {
                    $from = str_replace('<basepath>', $this->base_dir, $item['from']);
                } else {
                    $from = '';
                }
                if (!empty($module)) {
                    $item['to_module'] = $module;
                }
                if (stristr($extname, '__PH_SUBTYPE__')) {
                    $path = $this->getClientExtPath($item['to_module'], $from);
                } elseif ($item['to_module'] == 'application') {
                    $path = "custom/Extension/application/Ext/$extname";
                } else {
                    $path = "custom/Extension/modules/{$item['to_module']}/Ext/$extname";
                }
                if (isset($item['name'])) {
                    $target = $item['name'];
                } elseif (!empty($from)) {
                    $target = basename($from, '.php');
                } else {
                    $target = $this->id_name;
                }
                $disabled_path = $path . '/' . DISABLED_PATH;
                if (file_exists("$path/$target.php")) {
                    rmdir_recursive("$path/$target.php");
                } elseif (file_exists("$disabled_path/$target.php")) {
                    rmdir_recursive("$disabled_path/$target.php");
                } elseif (!empty($from) && file_exists($path . '/' . basename($from))) {
                    rmdir_recursive($path . '/' . basename($from));
                } elseif (!empty($from) && file_exists($disabled_path . '/' . basename($from))) {
                    rmdir_recursive($disabled_path . '/' . basename($from));
                }
            }
        }
    }

    public function getClientExtPath($module, $from)
    {
        $path = "custom/Extension/modules/{$module}/Ext/";
        if ($module == 'application') {
            $path = 'custom/Extension/application/Ext/';
        }
        $start = strpos($from, '/clients/');
        $path .= substr($from, $start + 1);
        return dirname($path);
    }

    // :2625-2640
    public function uninstall_customizations($beans)
    {
        foreach ($beans as $bean) {
            $dirs = [
                'custom/modules/' . $bean,
                'custom/Extension/modules/' . $bean,
                'custom/working/modules/' . $bean,
            ];
            foreach ($dirs as $dir) {
                if (is_dir($dir)) {
                    rmdir_recursive($dir);
                }
            }
        }
    }

    // :1424-1462
    public function copy_path($from, $to, $backup_path = '', $uninstall = false)
    {
        $to = str_replace('<basepath>', $this->base_dir, $to);
        if (!$uninstall) {
            $from = str_replace('<basepath>', $this->base_dir, $from);
        } else {
            $from = str_replace('<basepath>', $backup_path, $from);
        }
        $from = clean_path($from);
        $to = clean_path($to);
        if (!isValidCopyPath($to)) {
            throw new ModuleInstallerException('Invalid destination path: ' . $to);
        }
        $dir = dirname($to);
        if ($dir == '.' && is_dir($from)) {
            $dir = $to;
        }
        if (!sugar_is_dir($dir)) {
            mkdir_recursive($dir, true);
        }
        if (empty($backup_path)) {
            if (!copy_recursive($from, $to)) {
                throw new ModuleInstallerException('Failed to copy ' . $from . ' ' . $to);
            }
        }
    }

    // K-5 is stubbed: uninstall_languages() deletes the fragment and rebuilds the
    // language cache, and the cache needs a live instance. The DELETE is what the
    // package's report checks, so it is reproduced; the rebuild is not.
    public function uninstall_languages()
    {
        $file = 'custom/Extension/application/Ext/Language/en_us.' . $this->id_name . '.php';
        if (file_exists($file)) {
            unlink($file);
        }
    }
}

// K-2 / K-3 stand-ins: the real classes mutate deployed record-view rows, which
// do not exist off a tenant. The harness proves the script REACHES and REPORTS
// them; it does not claim to prove the metadata surgery.
// K-2 / K-3 stand-ins. They reproduce the ONE property the package's reporting
// depends on and that the real classes are measured to have: they rewrite
// custom/modules/<M>/clients/base/views/record/record.php ONLY when the thing
// they remove was actually present (real write(): "$at === false" -> return;
// real remove(): "if (!$changed) return;"). Everything else about the real
// metadata surgery is out of reach off a tenant and is not claimed here.
class BdQuotesLayoutExtensions
{
    public static $calls = 0;
    const VIEWDEF = 'custom/modules/Quotes/clients/base/views/record/record.php';
    public static function write(bool $replace = false): void
    {
        self::$calls++;
        if (!file_exists(self::VIEWDEF)) {
            return;
        }
        $body = (string) file_get_contents(self::VIEWDEF);
        if (strpos($body, 'LBL_RECORDVIEW_PANEL_BENCHDOGS') === false) {
            return;                       // already retired: writes nothing
        }
        file_put_contents(self::VIEWDEF, str_replace('LBL_RECORDVIEW_PANEL_BENCHDOGS', '', $body));
    }
}

class BdOpportunitiesLayoutExtensions
{
    public static $calls = 0;
    const VIEWDEF = 'custom/modules/Opportunities/clients/base/views/record/record.php';
    public static function remove(): void
    {
        self::$calls++;
        if (!file_exists(self::VIEWDEF)) {
            return;
        }
        $body = (string) file_get_contents(self::VIEWDEF);
        if (strpos($body, 'bd_governing_origin') === false) {
            return;                       // already retired: writes nothing
        }
        file_put_contents(self::VIEWDEF, str_replace('bd_governing_origin', '', $body));
    }
}

class HarnessLog
{
    public $fatals = [];
    public function fatal($m)
    {
        $this->fatals[] = $m;
    }
    public function debug($m)
    {
    }
}

// ---------------------------------------------------------------------------
// Build a tenant that looks like et: EVERY path the package ever installed.
// ---------------------------------------------------------------------------

$pkgDir = dirname(__DIR__);
$census = trim((string) file_get_contents($pkgDir . '/tests/census.txt'));
$paths = array_values(array_filter(array_map('trim', explode("\n", $census))));

$tenant = sys_get_temp_dir() . '/bd-residue-harness-' . getmypid();
if (is_dir($tenant)) {
    rmdir_recursive($tenant);
}
mkdir($tenant, 0775, true);

foreach ($paths as $rel) {
    $abs = $tenant . '/' . $rel;
    @mkdir(dirname($abs), 0775, true);
    file_put_contents($abs, "<?php\n// pre-cleanup body for {$rel}\n\$dictionary['x'] = 1;\n");
}
// the accumulated K-5 fragment, which is NOT in the package's own tree - it is
// something install_languages() CREATED on the tenant.
$stageFragment = $tenant . '/custom/Extension/application/Ext/Language/en_us.zz_bd_stage_doms.php';
@mkdir(dirname($stageFragment), 0775, true);
file_put_contents($stageFragment, "<?php\n// accumulated across every past version\n");

// The two DEPLOYED record viewdefs K-2 and K-3 mutate. These are tenant rows, not
// package files, which is exactly why no installdef can reach them.
$quotesViewdef = $tenant . '/custom/modules/Quotes/clients/base/views/record/record.php';
@mkdir(dirname($quotesViewdef), 0775, true);
file_put_contents($quotesViewdef, "<?php\n\$viewdefs['Quotes']['base']['view']['record'] = ['panels' => [['name' => 'LBL_RECORDVIEW_PANEL_BENCHDOGS']]];\n");
$oppsViewdef = $tenant . '/custom/modules/Opportunities/clients/base/views/record/record.php';
@mkdir(dirname($oppsViewdef), 0775, true);
file_put_contents($oppsViewdef, "<?php\n\$viewdefs['Opportunities']['base']['view']['record'] = ['panels' => [['fields' => ['bd_governing_origin']]]];\n");

$before = count($paths) + 1;

// ---------------------------------------------------------------------------
// Run it twice.
// ---------------------------------------------------------------------------

function runOnce(string $tenant, string $pkgDir): array
{
    $GLOBALS['log'] = new HarnessLog();
    $cwd = getcwd();
    chdir($tenant);
    ob_start();
    require $pkgDir . '/scripts/post_execute.php';
    $out = ob_get_clean();
    chdir($cwd);
    return [$out, $GLOBALS['log']->fatals];
}

[$out1, $fatal1] = runOnce($tenant, $pkgDir);
[$out2, $fatal2] = runOnce($tenant, $pkgDir);

// ---------------------------------------------------------------------------
// Assertions.
// ---------------------------------------------------------------------------

$fail = 0;
function check(string $name, bool $ok, string $detail = '')
{
    global $fail;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($detail === '' ? '' : "  <- {$detail}") . "\n";
    if (!$ok) {
        $fail++;
    }
}

echo "=== RUN 1 ===\n{$out1}\n";
echo "=== RUN 2 ===\n{$out2}\n";

preg_match('/REMOVED \((\d+)\)/', $out1, $m1);
preg_match('/REMOVED \((\d+)\)/', $out2, $m2);
$removed1 = isset($m1[1]) ? (int) $m1[1] : -1;
$removed2 = isset($m2[1]) ? (int) $m2[1] : -1;

check('run 1 removed something', $removed1 > 0, "removed={$removed1}");
check('run 2 removed NOTHING', $removed2 === 0, "removed={$removed2}");
check(
    'run 2 says NOTHING LEFT TO REMOVE',
    strpos($out2, 'NOTHING LEFT TO REMOVE') !== false
);
check('run 1 reported no failures', strpos($out1, 'FAILED (') === false, $out1);
check('run 2 reported no failures', strpos($out2, 'FAILED (') === false, $out2);
check('run 1 reported no skips', strpos($out1, 'SKIPPED (') === false, $out1);

// The two paths 🔒 1508 / 🔒 1514 KEEP must still be there, untouched.
foreach ([
    'custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php',
    'custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php',
    'custom/modules/Accounts/BdAccountsLayoutExtensions.php',
    'custom/modules/Quotes/BdQuotesLayoutExtensions.php',
    'custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php',
    'custom/clients/base/api/BdBenchDogsActionsApi.php',
    // the shared / contract paths: another package's body may be in these
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityLineRollupPolicy.php',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php',
    'custom/modules/Quotes/ErpQuoteHooks/OrderSelectedLinesPolicy.php',
    'custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php',
    'custom/modules/ProductBundles/clients/base/views/quote-data-group-list/quote-data-group-list.js',
    'custom/modules/Products/clients/base/views/quote-data-group-list/quote-data-group-list.php',
] as $keep) {
    $abs = $tenant . '/' . $keep;
    $intact = file_exists($abs)
        && strpos((string) file_get_contents($abs), 'pre-cleanup body') !== false;
    check('KEPT and unmodified: ' . $keep, $intact);
}

// Everything else must be gone, or blank.
$emptyHash = md5_file($pkgDir . '/lib/emptied.php');
$leftovers = [];
foreach ($paths as $rel) {
    $abs = $tenant . '/' . $rel;
    if (!file_exists($abs)) {
        continue;
    }
    if (md5_file($abs) === $emptyHash) {
        continue;
    }
    if (strpos((string) file_get_contents($abs), 'pre-cleanup body') !== false) {
        $leftovers[] = $rel;
    }
}
sort($leftovers);
$expectedLeftovers = [
    'custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php',
    'custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php',
    'custom/clients/base/api/BdBenchDogsActionsApi.php',
    'custom/modules/Accounts/BdAccountsLayoutExtensions.php',
    'custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php',
    'custom/modules/ProductBundles/clients/base/views/quote-data-group-list/quote-data-group-list.js',
    'custom/modules/Products/clients/base/views/quote-data-group-list/quote-data-group-list.php',
    'custom/modules/Quotes/BdQuotesLayoutExtensions.php',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityLineRollupPolicy.php',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php',
    'custom/modules/Quotes/ErpQuoteHooks/OrderSelectedLinesPolicy.php',
    'custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php',
];
sort($expectedLeftovers);
check(
    'exactly the 13 intended survivors are left with their original body',
    $leftovers === $expectedLeftovers,
    "got " . count($leftovers) . ": " . implode(', ', array_diff($leftovers, $expectedLeftovers))
        . ' / missing: ' . implode(', ', array_diff($expectedLeftovers, $leftovers))
);

check('K-5 fragment deleted', !file_exists($stageFragment));
check(
    'K-2 panel really left the deployed Quotes viewdef',
    strpos((string) file_get_contents($quotesViewdef), 'LBL_RECORDVIEW_PANEL_BENCHDOGS') === false
);
check(
    'K-3 marker really left the deployed Opportunities viewdef',
    strpos((string) file_get_contents($oppsViewdef), 'bd_governing_origin') === false
);
check(
    'run 1 REPORTED K-2 as removed (the panel was there)',
    strpos($out1, 'K-2 Bench Dogs panel spliced out') !== false
);
check(
    'run 2 reported K-2 as ALREADY ABSENT, not as a second removal',
    strpos($out2, 'K-2 Bench Dogs panel - already absent') !== false
);
check('K-2 write() ran exactly once per run', BdQuotesLayoutExtensions::$calls === 2, (string) BdQuotesLayoutExtensions::$calls);
check('K-3 remove() ran exactly once per run', BdOpportunitiesLayoutExtensions::$calls === 2, (string) BdOpportunitiesLayoutExtensions::$calls);
check('a fatal() summary was written on both runs', count($fatal1) >= 1 && count($fatal2) >= 1);
check('run 2 fatal says spent', strpos(implode(' ', $fatal2), 'NOTHING LEFT TO REMOVE') !== false);

echo "\nTenant tree left at: {$tenant}\n";
echo $fail === 0 ? "ALL CHECKS PASSED\n" : "{$fail} CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
