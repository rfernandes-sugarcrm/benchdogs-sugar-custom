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

// include/utils/sugar_file_utils.php:262 (simplified: the directory already
// exists here, so only the touch() half is reproduced)
function sugar_touch($filename, $time = null, $atime = null)
{
    return $time === null ? touch($filename) : touch($filename, $time, $atime ?? $time);
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
        } elseif (!$this->copy_recursive_with_backup($from, $to, $backup_path, $uninstall)) {
            // :1457-1459. Missing from this harness through 1.0.1, because nothing
            // called copy_path with a backup path. 1.0.2's adapter step does.
            throw new ModuleInstallerException('Failed to copy ' . $from . ' ' . $to);
        }
    }

    // :2653-2713, VERBATIM. 1.0.2 deletes the orphaned order adapter through the
    // `elseif (!is_dir($source))` + $uninstall branch: a source that does not
    // exist unlinks the destination. That is the branch under test, so it is
    // Sugar's code here, not a paraphrase of it.
    public function copy_recursive_with_backup($source, $dest, $backup_path, $uninstall = false)
    {
        if (is_file($source)) {
            if ($uninstall) {
                $GLOBALS['log']->debug('Restoring ... ' . $source . ' to ' . $dest);
                if (copy($source, $dest)) {
                    if (is_writable($dest)) {
                        sugar_touch($dest, filemtime($source));
                    }
                    return (unlink($source));
                } else {
                    $GLOBALS['log']->debug("Can't restore file: " . $source);
                    return true;
                }
            } else {
                if (file_exists($dest)) {
                    $rest = clean_path($backup_path . "/$dest");
                    if (!is_dir(dirname($rest))) {
                        mkdir_recursive(dirname($rest), true);
                    }

                    $GLOBALS['log']->debug('Backup ... ' . $dest . ' to ' . $rest);
                    if (copy($dest, $rest)) {
                        if (is_writable($rest)) {
                            sugar_touch($rest, filemtime($dest));
                        }
                    } else {
                        $GLOBALS['log']->debug("Can't backup file: " . $dest);
                    }
                }
                return (copy($source, $dest));
            }
        } elseif (!is_dir($source)) {
            if ($uninstall) {
                if (is_file($dest)) {
                    return (unlink($dest));
                } else {
                    //don't do anything we already cleaned up the files using uninstall_new_files
                    return true;
                }
            } else {
                return false;
            }
        }

        if (!is_dir($dest) && !$uninstall) {
            sugar_mkdir($dest);
        }

        $status = true;

        $d = dir($source);
        while ($f = $d->read()) {
            if ($f == '.' || $f == '..') {
                continue;
            }
            $status &= $this->copy_recursive_with_backup("$source/$f", "$dest/$f", $backup_path, $uninstall);
        }
        $d->close();
        return ($status);
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

// 1.0.2: the ORDER ADAPTER gets the body Bench Dogs really shipped (rc37-rc40,
// blob 2963abf0), because the package deletes only that body. The census gives
// every path a generic "pre-cleanup body", which the package would rightly
// refuse to treat as Bench Dogs' adapter.
$adapterRel = 'custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php';
$plannerRel = 'custom/modules/Quotes/BdSubmitOrderPlan.php';
$adapterFixture = $pkgDir . '/tests/fixtures/ResolveOrderableLines.bench-rc37.php.txt';
copy($adapterFixture, $tenant . '/' . $adapterRel);

// 1.0.3 (G594): the RELEASE-STAGE POLICY gets the rc45-rc64 body - the one
// benchdogs-dev still holds from rc60 - for the same reason: the package deletes
// only a body Bench Dogs shipped, and the census's generic body is not one.
$releasePolicyRel = 'custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php';
$releasePolicyFixtures = $pkgDir . '/tests/fixtures/release-stage-policy';
$releasePolicyDevBody = $releasePolicyFixtures . '/OpportunityReleaseStagePolicy.rc45-rc64.php.txt';
copy($releasePolicyDevBody, $tenant . '/' . $releasePolicyRel);

$before = count($paths) + 1;

// ---------------------------------------------------------------------------
// Run it twice.
// ---------------------------------------------------------------------------

function runOnce(string $tenant, string $pkgDir): array
{
    $GLOBALS['log'] = new HarnessLog();
    // ModuleInstaller::post_execute() (26.1.0 :426-440) runs extract($data) over
    // readManifest() before the require, so $manifest is in scope. This is the
    // version file pack.php writes into that manifest.
    $manifest = ['version' => trim((string) file_get_contents($pkgDir . '/version'))];
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
    'custom/modules/Quotes/ErpQuoteHooks/OrderSelectedLinesPolicy.php',
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
    'custom/modules/Quotes/ErpQuoteHooks/OrderSelectedLinesPolicy.php',
];
sort($expectedLeftovers);
check(
    'exactly the 11 intended survivors are left with their original body',
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

// --- 1.0.2: the version in the log is the manifest's, not a typed "1.0.0" ----
$version = trim((string) file_get_contents($pkgDir . '/version'));
check(
    'the Display Log header names the manifest version (' . $version . ')',
    strpos($out1, 'ONEOFF-RetireBdResidue ' . $version . ' - Bench Dogs retirement sweep') !== false
);
check(
    'the fatal() summary names the manifest version (' . $version . ')',
    strpos(implode(' ', $fatal1), 'ONEOFF-RetireBdResidue ' . $version . ':') !== false
);

// --- 1.0.2: the orphaned order adapter (Ophir, quote 368, 2026-09-23) --------
check('the full sweep DELETED the Bench Dogs order adapter', !file_exists($tenant . '/' . $adapterRel));
check('run 1 reported the adapter under REMOVED',
    strpos($out1, $adapterRel . " (Bench Dogs' order adapter, deleted") !== false);
check('run 2 reported the adapter as already gone',
    strpos($out2, '. ' . $adapterRel . ' (the Bench Dogs order adapter)') !== false);

// Three more tenants, each holding only the pair. runAdapterScenario() builds
// one, runs the package once and returns what is left.
function runAdapterScenario(string $name, string $pkgDir, string $adapterBody, ?string $plannerBody): array
{
    $root = sys_get_temp_dir() . '/bd-residue-adapter-' . $name . '-' . getmypid();
    if (is_dir($root)) {
        rmdir_recursive($root);
    }
    @mkdir($root . '/custom/modules/Quotes/ErpQuoteHooks', 0775, true);
    file_put_contents($root . '/custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php', $adapterBody);
    if ($plannerBody !== null) {
        file_put_contents($root . '/custom/modules/Quotes/BdSubmitOrderPlan.php', $plannerBody);
    }
    [$out, $fatals] = runOnce($root, $pkgDir);
    return [
        'adapter' => file_exists($root . '/custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php'),
        'planner_blank' => file_exists($root . '/custom/modules/Quotes/BdSubmitOrderPlan.php')
            && md5_file($root . '/custom/modules/Quotes/BdSubmitOrderPlan.php') === md5_file($pkgDir . '/lib/emptied.php'),
        'out' => $out,
        'root' => $root,
    ];
}

$benchAdapter = (string) file_get_contents($adapterFixture);
$blankPlanner = (string) file_get_contents($pkgDir . '/lib/emptied.php');

// THE OPHIR STATE: 1.0.1 already blanked the planner; the adapter is still there.
// 1.0.1's logic leaves the adapter and Submit Order refuses. This is the case
// that must go red on 1.0.1's post_execute.php.
$ophir = runAdapterScenario('ophir', $pkgDir, $benchAdapter, $blankPlanner);
check('Ophir state (adapter + planner ALREADY blank): the adapter is DELETED', !$ophir['adapter'], $ophir['root']);
check('Ophir state: the planner stays blank', $ophir['planner_blank']);
check('Ophir state: reported under REMOVED, nothing FAILED or SKIPPED',
    strpos($ophir['out'], "Bench Dogs' order adapter, deleted: its planner is blank") !== false
        && strpos($ophir['out'], 'FAILED (') === false && strpos($ophir['out'], 'SKIPPED (') === false);

// Planner never there at all: the adapter refuses just the same, so it goes.
$absent = runAdapterScenario('absent', $pkgDir, $benchAdapter, null);
check('adapter + NO planner: the adapter is DELETED', !$absent['adapter']);

// CONTROL: the planner could NOT be blanked, so the pair still answers and the
// adapter must stay. Blanking is made to fail the one way that fails for root too
// (CI runs as root, where a read-only file does not stop a copy): the planner path
// is a DIRECTORY, so step 4's copy_path() throws and step 4 reports FAILED.
$intactRoot = sys_get_temp_dir() . '/bd-residue-adapter-intact-' . getmypid();
if (is_dir($intactRoot)) {
    rmdir_recursive($intactRoot);
}
@mkdir($intactRoot . '/custom/modules/Quotes/ErpQuoteHooks', 0775, true);
@mkdir($intactRoot . '/custom/modules/Quotes/BdSubmitOrderPlan.php', 0775, true);
file_put_contents($intactRoot . '/custom/modules/Quotes/BdSubmitOrderPlan.php/body', 'x');
copy($adapterFixture, $intactRoot . '/' . $adapterRel);
[$intactOut] = @runOnce($intactRoot, $pkgDir);
check('CONTROL: planner NOT blank (step 4 failed): the adapter is LEFT', file_exists($intactRoot . '/' . $adapterRel));
check('CONTROL: and it is named under NOT TOUCHED as a pair that still answers',
    strpos($intactOut, "its planner custom/modules/Quotes/BdSubmitOrderPlan.php still has a body") !== false);

// CONTROL: an adapter body Bench Dogs never shipped is somebody else's - LEFT, loudly.
$foreign = runAdapterScenario('foreign', $pkgDir, "<?php\nclass ErpQuoteResolveOrderableLinesHook {}\n", $blankPlanner);
check('CONTROL: a foreign adapter body is LEFT in place', $foreign['adapter']);
check('CONTROL: and it is reported under SKIPPED, naming its md5',
    strpos($foreign['out'], 'SKIPPED (') !== false && strpos($foreign['out'], 'is not the adapter Bench Dogs shipped') !== false);

// --- 1.0.3: the orphaned release-stage policy (G594, benchdogs-dev quote #8) --
// Partial Fulfillment finds this file by a hardcoded path and obeys it. The
// rc45-rc64 body answers Partial Production Ordered / 90 on EVERY release, the
// final one included, so the Opportunity never reaches Closed Won.
check('the full sweep DELETED the Bench Dogs release-stage policy', !file_exists($tenant . '/' . $releasePolicyRel));
check('run 1 reported the release-stage policy under REMOVED, with the rc45-rc64 md5',
    strpos($out1, $releasePolicyRel . " (Bench Dogs' release-stage policy, md5 e5e6e3fff432a5dcd6624accf6bb8598, deleted") !== false);
check('run 2 reported the release-stage policy as already gone',
    strpos($out2, '. ' . $releasePolicyRel . ' (the Bench Dogs release-stage policy)') !== false);
check('the release-stage policy is no longer reported as NOT TOUCHED',
    strpos($out1, '= ' . $releasePolicyRel) === false);

// One tenant per body: only the policy on disk. Returns what is left.
function runReleasePolicyScenario(string $name, string $pkgDir, string $body): array
{
    $root = sys_get_temp_dir() . '/bd-residue-release-policy-' . $name . '-' . getmypid();
    if (is_dir($root)) {
        rmdir_recursive($root);
    }
    $rel = 'custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php';
    @mkdir($root . '/custom/modules/Quotes/ErpQuoteHooks', 0775, true);
    file_put_contents($root . '/' . $rel, $body);
    [$out] = runOnce($root, $pkgDir);
    [$again] = runOnce($root, $pkgDir);
    return [
        'policy' => file_exists($root . '/' . $rel),
        'out' => $out,
        'again' => $again,
        'root' => $root,
    ];
}

// THE benchdogs-dev STATE: rc60's body is the only residue at the path.
$dev = runReleasePolicyScenario('dev', $pkgDir, (string) file_get_contents($releasePolicyDevBody));
check('benchdogs-dev state (rc45-rc64 body): the release-stage policy is DELETED, not blanked',
    !$dev['policy'], $dev['root']);
check('benchdogs-dev state: reported under REMOVED, nothing FAILED or SKIPPED',
    strpos($dev['out'], "Bench Dogs' release-stage policy, md5 e5e6e3fff432a5dcd6624accf6bb8598, deleted") !== false
        && strpos($dev['out'], 'FAILED (') === false && strpos($dev['out'], 'SKIPPED (') === false);
check('benchdogs-dev state: a second run finds it already gone',
    strpos($dev['again'], '. custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php'
        . ' (the Bench Dogs release-stage policy)') !== false);

// EVERY body Bench Dogs ever shipped at the path is deleted - one tenant each, so
// an allowlist entry that goes missing leaves exactly its own body behind.
$releaseBodies = glob($releasePolicyFixtures . '/OpportunityReleaseStagePolicy.*.php.txt');
sort($releaseBodies);
check('eight pinned Bench Dogs release-stage bodies (rc4 .. rc65)', count($releaseBodies) === 8,
    (string) count($releaseBodies));
foreach ($releaseBodies as $releaseBody) {
    $label = basename($releaseBody, '.php.txt');
    $run = runReleasePolicyScenario(md5_file($releaseBody), $pkgDir, (string) file_get_contents($releaseBody));
    check('Bench Dogs body ' . $label . ' (md5 ' . md5_file($releaseBody) . ') is DELETED', !$run['policy'],
        $run['root']);
}

// CONTROL: a body Bench Dogs never shipped is somebody else's provider - LEFT,
// and reported loudly, because it may be exactly why Closed Won is never reached.
$foreignPolicy = runReleasePolicyScenario('foreign', $pkgDir,
    "<?php\nclass ErpOpportunityReleaseStagePolicy\n{\n    public function resolve(\$quote)\n    {\n"
    . "        return array('sales_stage' => 'Negotiation', 'probability' => 80);\n    }\n}\n");
check('CONTROL: a foreign release-stage policy is LEFT in place', $foreignPolicy['policy']);
check('CONTROL: and it is reported under SKIPPED with its md5 and the Closed Won hint',
    strpos($foreignPolicy['out'], 'SKIPPED (') !== false
        && strpos($foreignPolicy['out'], 'is not a release-stage policy Bench Dogs shipped') !== false
        && strpos($foreignPolicy['out'], 'never reaches Closed Won') !== false);

echo "\nTenant tree left at: {$tenant}\n";
echo $fail === 0 ? "ALL CHECKS PASSED\n" : "{$fail} CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
