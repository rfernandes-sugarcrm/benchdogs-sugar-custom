<?php

// Not shipped. Runs the REAL pack.php, unpacks its zip, and runs its post_execute over synthetic tenants twice, the way ModuleInstaller::post_execute() does. Run: php tests/harness.php

namespace Sugarcrm\Sugarcrm\MetaData {
    // A stand-in for the deployed record view: custom/modules/<M>/clients/<p>/views/<v>/<v>.php, read and written as Sugar lays it out.
    class ViewdefManager
    {
        public function loadViewdef($platform, $module, $view)
        {
            $file = "custom/modules/$module/clients/$platform/views/$view/$view.php";
            if (!is_file($file)) {
                return array();
            }
            $viewdefs = array();
            include $file;
            return $viewdefs[$module][$platform]['view'][$view] ?? array();
        }

        public function saveViewdef($defs, $module, $platform, $view)
        {
            $file = "custom/modules/$module/clients/$platform/views/$view/$view.php";
            @mkdir(dirname($file), 0775, true);
            file_put_contents($file, "<?php\n\$viewdefs['$module']['$platform']['view']['$view'] = " . var_export($defs, true) . ";\n");
        }
    }
}

namespace {
function rmdir_recursive($path)
{
    if (is_file($path)) {
        return (unlink($path));
    }
    if (!is_dir($path)) {
        if (!empty($GLOBALS['log'])) {
            $GLOBALS['log']->fatal("ERROR: rmdir_recursive(): argument $path is not a file or a dir.");
        }
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
    $rmOk = @rmdir($path);
    if ($rmOk === false) {
        $GLOBALS['log']->error("ERROR: Unable to remove directory $path");
    }
    return ($status);
}


class HarnessLog
{
    public $fatals = array();
    public function fatal($m) { $this->fatals[] = $m; }
    public function __call($name, $args) {}
}

// The stock-file check: nothing a Bench package installed is in files.md5.
class HarnessScanner
{
    public function sugarFileExists($path) { return false; }
}

class HarnessInstaller
{
    public $base_dir;
    public $ms;

    public function __construct($baseDir)
    {
        $this->base_dir = $baseDir;
        $this->ms = new HarnessScanner();
    }

    // ModuleInstaller::uninstall_new_files(), SugarEnt-Full-26.1.0 ModuleInstall/ModuleInstaller.php, VERBATIM.
    public function uninstall_new_files($cp, $backup_path)
    {
        $zip_files = $this->dir_get_files($cp['from'], $cp['from']);
        $backup_files = $this->dir_get_files($backup_path, $backup_path);
        foreach ($zip_files as $k => $v) {
            //if it's not a backup then it is probably a new file but we'll check that it is not in the md5.files first
            if (!isset($backup_files[$k])) {
                $to = $cp['to'] . $k;
                //if it's not a sugar file then we remove it otherwise we can't restor it
                if (!$this->ms->sugarFileExists($to)) {
                    $GLOBALS['log']->debug('ModuleInstaller[uninstall_new_file] deleting file ' . $to);
                    if (file_exists($to)) {
                        unlink($to);
                    }
                } else {
                    $GLOBALS['log']->fatal('ModuleInstaller[uninstall_new_file] Could not remove file ' . $to . ' as no backup file was found to restore to');
                }
            }
        }
        //lets check if the directory is empty if it is we will delete it as well
        $files_remaining = $this->dir_file_count($cp['to']);
        if (file_exists($cp['to']) && $files_remaining == 0) {
            $GLOBALS['log']->debug('ModuleInstaller[uninstall_new_file] deleting directory ' . $cp['to']);
            rmdir_recursive($cp['to']);
        }
    }

    // ModuleInstaller::dir_get_files(), SugarEnt-Full-26.1.0 ModuleInstall/ModuleInstaller.php, VERBATIM.
    public function dir_get_files($path, $base_path)
    {
        $files = [];
        if (!is_dir($path)) {
            return $files;
        }
        $d = dir($path);
        while ($e = $d->read()) {
            //ignore invisible files . .. ._MACOSX
            if (substr($e, 0, 1) == '.') {
                continue;
            }
            if (is_file($path . '/' . $e)) {
                $files[str_replace($base_path, '', $path . '/' . $e)] = str_replace($base_path, '', $path . '/' . $e);
            }
            if (is_dir($path . '/' . $e)) {
                $files = array_merge($files, $this->dir_get_files($path . '/' . $e, $base_path));
            }
        }
        $d->close();
        return $files;
    }

    // ModuleInstaller::dir_file_count(), SugarEnt-Full-26.1.0 ModuleInstall/ModuleInstaller.php, VERBATIM.
    public function dir_file_count($path)
    {
        //if its a file then it has at least 1 file in the directory
        if (is_file($path)) {
            return 1;
        }
        if (!is_dir($path)) {
            return 0;
        }
        $d = dir($path);
        $count = 0;
        while ($e = $d->read()) {
            //ignore invisible files . .. ._MACOSX
            if (substr($e, 0, 1) == '.') {
                continue;
            }
            if (is_file($path . '/' . $e)) {
                $count++;
            }
            if (is_dir($path . '/' . $e)) {
                $count += $this->dir_file_count($path . '/' . $e);
            }
        }
        $d->close();
        return $count;
    }

    // ModuleInstaller::uninstall_customizations(), SugarEnt-Full-26.1.0 ModuleInstall/ModuleInstaller.php, VERBATIM.
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

    // ModuleInstaller::post_execute() requires each script inside a method, so $this and $manifest are in scope.
    public function runPostExecute(array $manifest)
    {
        ob_start();
        require $this->base_dir . '/scripts/post_execute.php';
        return ob_get_clean();
    }
}

$fail = 0;
function check(string $name, bool $ok, string $detail = '')
{
    global $fail;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : "  <- {$detail}") . "\n";
    if (!$ok) {
        $fail++;
    }
}

$src = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/bd-residue-harness-' . getmypid();
if (is_dir($tmp)) {
    rmdir_recursive($tmp);
}
mkdir($tmp . '/ONEOFF-RetireBdResidue/scripts', 0775, true);
mkdir($tmp . '/BenchDogs-Ext', 0775, true);
foreach (array('pack.php', 'version', 'scripts/post_execute.php', 'scripts/leftovers.php') as $f) {
    copy("$src/$f", "$tmp/ONEOFF-RetireBdResidue/$f");
}
copy("$src/../BenchDogs-Ext/version", "$tmp/BenchDogs-Ext/version");
$built = shell_exec('cd ' . escapeshellarg("$tmp/ONEOFF-RetireBdResidue") . ' && ' . escapeshellarg(PHP_BINARY) . ' pack.php 2>&1');
$version = trim((string) file_get_contents("$src/version"));
$zipPath = "$tmp/ONEOFF-RetireBdResidue/releases/oneoff_retire_bd_residue-$version.zip";
check('the real pack.php built the zip', is_file($zipPath), (string) $built);
$pkg = "$tmp/unpacked";
$zip = new ZipArchive();
$zip->open($zipPath);
$members = array();
for ($i = 0; $i < $zip->numFiles; $i++) {
    $members[] = $zip->getNameIndex($i);
}
$zip->extractTo($pkg);
$zip->close();

$leftovers = require "$src/scripts/leftovers.php";
$expected = array('manifest.php', 'scripts/post_execute.php', 'scripts/leftovers.php');
foreach ($leftovers['remove'] as $p) {
    $expected[] = 'leftovers/' . $p;
}
$g = 0;
foreach (array_keys($leftovers['remove_if_bench']) as $p) {
    $expected[] = 'leftovers/if-bench/' . $g++ . '/' . $p;
}
sort($members);
sort($expected);
check('the zip holds only the two scripts, the manifest and one placeholder per listed path', $members === $expected,
    json_encode(array_values(array_diff($members, $expected))) . ' / ' . json_encode(array_values(array_diff($expected, $members))));
$manifest = null;
$installdefs = null;
include "$pkg/manifest.php";
check('the manifest requires the Bench Dogs release built from this same tree',
    ($manifest['dependencies'][0] ?? null) === array('id_name' => 'sugarai_benchdogs_ext',
        'version' => trim((string) file_get_contents("$src/../BenchDogs-Ext/version"))), json_encode($manifest['dependencies'] ?? null));

// One run of the unpacked package over $tenant; returns [echo output, fatal lines].
function runOnce(string $tenant, string $pkg, array $manifest): array
{
    $GLOBALS['log'] = new HarnessLog();
    $cwd = getcwd();
    chdir($tenant);
    $out = (new HarnessInstaller($pkg))->runPostExecute($manifest);
    chdir($cwd);
    return array($out, $GLOBALS['log']->fatals);
}

function seed(string $root, string $rel, string $body): void
{
    @mkdir(dirname("$root/$rel"), 0775, true);
    file_put_contents("$root/$rel", $body);
}

$styleRel = 'custom/Extension/application/Ext/DropdownsStyle/sales_stage_dom_style.php';
$policyRel = 'custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php';
$adapterRel = 'custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php';
$plannerRel = 'custom/modules/Quotes/BdSubmitOrderPlan.php';
$fx = "$src/tests/fixtures";
$kept = array(
    'custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php',
    'custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php',
    'custom/modules/Accounts/BdAccountsLayoutExtensions.php',
    'custom/modules/Quotes/BdQuotesLayoutExtensions.php',
    'custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityLineRollupPolicy.php',
    'custom/modules/Quotes/ErpQuoteHooks/OrderSelectedLinesPolicy.php',
    'custom/modules/ProductBundles/clients/base/views/quote-data-group-list/quote-data-group-list.js',
    'custom/modules/Products/clients/base/views/quote-data-group-list/quote-data-group-list.php',
    'custom/src/BenchDogs/BdAdmRules.php',
);

// ── the full sweep: a tenant holding every listed path, the three guarded ones with Bench bodies ──
// Seeded from tests/census.txt (every path Bench Dogs ever installed), NOT from leftovers.php: a path dropped from the list must show up here.
$census = array_values(array_filter(array_map('trim', file("$src/tests/census.txt"))));
$tenant = "$tmp/tenant";
foreach (array_merge($census, $kept) as $rel) {
    seed($tenant, $rel, "<?php\n// pre-cleanup body for {$rel}\n");
}
seed($tenant, $styleRel, (string) file_get_contents("$fx/dropdowns-style/sales_stage_dom_style.rc39-rc64.php.txt"));
seed($tenant, $policyRel, (string) file_get_contents("$fx/release-stage-policy/OpportunityReleaseStagePolicy.rc45-rc64.php.txt"));
seed($tenant, $adapterRel, (string) file_get_contents("$fx/ResolveOrderableLines.bench-rc37.php.txt"));
foreach ($leftovers['directories'] as $d) {
    seed($tenant, "custom/modules/$d/a.js", '// retired');
}
seed($tenant, 'custom/modules/Quotes/clients/base/views/record/record.php',
    "<?php\n\$viewdefs['Quotes']['base']['view']['record'] = array('panels' => array(array('name' => 'panel_header'), array('name' => 'LBL_RECORDVIEW_PANEL_BENCHDOGS', 'fields' => array('bd_x')), array('name' => 'panel_body')));\n");
seed($tenant, 'custom/modules/Opportunities/clients/base/views/record/record.php',
    "<?php\n\$viewdefs['Opportunities']['base']['view']['record'] = array('panels' => array(array('name' => 'panel_body', 'fields' => array('name', array('name' => 'bd_governing_origin'), 'amount'))));\n");

[$out1, $fatal1] = runOnce($tenant, $pkg, $manifest);
[$out2, $fatal2] = runOnce($tenant, $pkg, $manifest);
echo "=== RUN 1 ===\n{$out1}\n=== RUN 2 ===\n{$out2}\n";
preg_match('/REMOVED \((\d+)\)/', $out1, $m1);
preg_match('/REMOVED \((\d+)\)/', $out2, $m2);
check('run 1 removed something', (int) ($m1[1] ?? -1) > 0, $out1);
check('run 2 removed NOTHING', (int) ($m2[1] ?? -1) === 0, $out2);
check('run 2 says NOTHING LEFT TO REMOVE', strpos($out2, 'NOTHING LEFT TO REMOVE') !== false);
foreach (array($out1, $out2) as $n => $o) {
    check('run ' . ($n + 1) . ' reported no failures', strpos($o, 'FAILED (') === false, $o);
    check('run ' . ($n + 1) . ' reported no skips', strpos($o, 'SKIPPED (') === false, $o);
}
$left = array();
foreach ($census as $rel) {
    if (!in_array($rel, $kept, true) && file_exists("$tenant/$rel")) {
        $left[] = $rel;
    }
}
check('every census path but the kept ones is DELETED (nothing is blanked any more)', $left === array(), json_encode($left));
foreach ($kept as $rel) {
    check('KEPT and unmodified: ' . $rel, strpos((string) @file_get_contents("$tenant/$rel"), 'pre-cleanup body') !== false);
}
foreach ($leftovers['directories'] as $d) {
    check('directory removed: custom/modules/' . $d, !file_exists("$tenant/custom/modules/$d"));
}
check('run 1 reported the stage style under REMOVED, with the rc39-rc64 md5',
    !file_exists("$tenant/$styleRel") && strpos($out1, $styleRel . ' (a body Bench Dogs shipped, md5 '
        . md5_file("$fx/dropdowns-style/sales_stage_dom_style.rc39-rc64.php.txt")) !== false);
check('K-2: the Bench Dogs panel is off the deployed Quotes record view, the rest in order',
    (function () use ($tenant) { $viewdefs = array(); include "$tenant/custom/modules/Quotes/clients/base/views/record/record.php";
        return array_column($viewdefs['Quotes']['base']['view']['record']['panels'], 'name'); })() === array('panel_header', 'panel_body'));
check('K-3: bd_governing_origin is off the deployed Opportunities record view, the rest in order',
    (function () use ($tenant) { $viewdefs = array(); include "$tenant/custom/modules/Opportunities/clients/base/views/record/record.php";
        return $viewdefs['Opportunities']['base']['view']['record']['panels'][0]['fields']; })() === array('name', 'amount'));
check('a fatal() summary was written on both runs, naming the version that ran',
    count($fatal1) === 1 && count($fatal2) === 1 && strpos($fatal1[0], "ONEOFF-RetireBdResidue $version:") === 0);
check('run 2 fatal says spent', strpos(implode(' ', $fatal2), 'NOTHING LEFT TO REMOVE') !== false);

// ── one tenant per guarded case ──
function guardedTenant(string $name, array $files, string $pkg, array $manifest, string $tmp): array
{
    $root = "$tmp/guard-$name";
    foreach ($files as $rel => $body) {
        seed($root, $rel, $body);
    }
    @mkdir($root, 0775, true);
    [$out] = runOnce($root, $pkg, $manifest);
    return array($root, $out);
}
$benchAdapter = (string) file_get_contents("$fx/ResolveOrderableLines.bench-rc37.php.txt");
[$r, $o] = guardedTenant('ophir', array($adapterRel => $benchAdapter, $plannerRel => "<?php\n"), $pkg, $manifest, $tmp);
check('Ophir state (adapter + planner ALREADY blank): the adapter is DELETED', !file_exists("$r/$adapterRel") && !file_exists("$r/$plannerRel"), $o);
[$r, $o] = guardedTenant('absent', array($adapterRel => $benchAdapter), $pkg, $manifest, $tmp);
check('adapter + NO planner: the adapter is DELETED', !file_exists("$r/$adapterRel"), $o);
// A planner that cannot be removed (a directory at its path, so unlink() fails) still answers: the adapter must stay.
$stuck = "$tmp/guard-stuck-planner";
seed($stuck, $adapterRel, $benchAdapter);
@mkdir("$stuck/$plannerRel", 0775, true);
seed($stuck, "$plannerRel/keep.txt", 'x');
[$o] = @runOnce($stuck, $pkg, $manifest);
check('CONTROL: while the planner is still there, the adapter is LEFT and the sweep says why', file_exists("$stuck/$adapterRel")
    && strpos($o, $adapterRel . " - LEFT: Bench Dogs' adapter, but its planner") !== false, $o);
[$r, $o] = guardedTenant('foreign-adapter', array($adapterRel => "<?php\n// another package's adapter\n"), $pkg, $manifest, $tmp);
check('CONTROL: a foreign adapter is LEFT, and reported under SKIPPED', file_exists("$r/$adapterRel")
    && strpos($o, 'SKIPPED (') !== false && strpos($o, $adapterRel . ' - LEFT: not a Bench body') !== false, $o);
foreach (glob("$fx/release-stage-policy/OpportunityReleaseStagePolicy.*.php.txt") as $body) {
    [$r, $o] = guardedTenant('policy-' . basename($body), array($policyRel => (string) file_get_contents($body)), $pkg, $manifest, $tmp);
    check('the release-stage policy body ' . basename($body) . ' is DELETED', !file_exists("$r/$policyRel"), $o);
}
[$r, $o] = guardedTenant('dev', array($policyRel => (string) file_get_contents("$fx/release-stage-policy/OpportunityReleaseStagePolicy.rc45-rc64.php.txt")), $pkg, $manifest, $tmp);
check('benchdogs-dev state (rc45-rc64 body): the release-stage policy is DELETED, not blanked', !file_exists("$r/$policyRel"), $o);
[$r, $o] = guardedTenant('foreign-policy', array($policyRel => "<?php\n// Partial Fulfillment's own policy\n"), $pkg, $manifest, $tmp);
check('CONTROL: a foreign release-stage policy is LEFT in place', file_exists("$r/$policyRel")
    && strpos((string) file_get_contents("$r/$policyRel"), "Partial Fulfillment's own policy") !== false, $o);
foreach (glob("$fx/dropdowns-style/sales_stage_dom_style.rc*.php.txt") as $body) {
    [$r, $o] = guardedTenant('style-' . basename($body), array($styleRel => (string) file_get_contents($body)), $pkg, $manifest, $tmp);
    check('the stage style body ' . basename($body) . ' is DELETED', !file_exists("$r/$styleRel"), $o);
}
$sugarStyle = (string) file_get_contents("$fx/dropdowns-style/FOREIGN.sugar-written.ossugarcube2-2026-09-11.php.txt");
[$r, $o] = guardedTenant('sugar-style', array($styleRel => $sugarStyle), $pkg, $manifest, $tmp);
check('CONTROL: a Sugar-written stage style (not a Bench body) is LEFT in place, unmodified',
    (string) @file_get_contents("$r/$styleRel") === $sugarStyle && strpos($o, 'SKIPPED (') !== false, $o);
[$r, $o] = guardedTenant('edited-style', array($styleRel => (string) file_get_contents("$fx/dropdowns-style/sales_stage_dom_style.rc39-rc64.php.txt") . "\n"), $pkg, $manifest, $tmp);
check('CONTROL: a Bench body with one byte changed is LEFT', file_exists("$r/$styleRel"), $o);
[$r, $o] = guardedTenant('two-in-one-dir', array($adapterRel => $benchAdapter, $policyRel => "<?php\n// Partial Fulfillment's own policy\n"), $pkg, $manifest, $tmp);
check('CONTROL: deleting the adapter never takes the foreign policy beside it', !file_exists("$r/$adapterRel") && file_exists("$r/$policyRel"), $o);

rmdir_recursive($tmp);
echo $fail === 0 ? "ALL CHECKS PASSED\n" : "{$fail} CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
}
