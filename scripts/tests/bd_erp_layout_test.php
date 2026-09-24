<?php

/**
 * 0.9.42-rc70 (G380 (f), 🔒 1724b): the Bench Dogs fields are placed and retired by
 * ERP-Core's REAL ErpLayoutExtraFields, through rc70's REAL lifecycle scripts.
 *
 * Run:  BD_LAYOUT_FIELDS=<ERP-Core's custom/include/ErpLayoutExtraFields.php> \
 *       BD_ERP_REFERENCE=<ERP-Core's Quotes/Ext/Vardefs/erp_reference.php> \
 *       php scripts/tests/bd_erp_layout_test.php
 *       (scripts/tests/test_php_suites.py sets both: the sibling checkout's files
 *       when present, else the pins from the landed Sugar target a0f6b632)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT IS REAL: ErpLayoutExtraFields (lane D, landed), the erp_reference vardef
 * whose marker the Bench pickers are placed after, rc70's vardef files, and rc70's
 * scripts/post_execute.php, bd_pre_uninstall.php and post_uninstall.php, run the
 * way Module Loader runs them. WHAT IS FAKED: the deployed viewdefs, the config
 * table, and the extension compiler - and the compiler is faked CONSERVATIVELY:
 * the compiled vardefs change ONLY when RepairAndClear::rebuildExtensions() runs.
 * Real Sugar's uninstall_extensions() also rebuilds before post_uninstall; here it
 * does not, so a post_uninstall that called sync() BEFORE its own rebuild would see
 * the Bench fields still defined and retire nothing (lane D's rule 3: call sync()
 * only after the extensions are rebuilt without your fields).
 *
 * The tenant's life, in order:
 *   T0  ERP-Epicor 1.1.125 + Bench rc69: the two Account fields on panel_body,
 *       placed by rc69's retired layout writer, UNMARKED; erp_reference on the
 *       Quotes ERP panel, recorded by ERP-Epicor's own sync().
 *   T1  upgrade to rc70 (install_copy over rc69, then post_execute)
 *   T2  reinstall rc70 (idempotent)
 *   T3  ERP-Epicor reinstalled: its layouts rewrite both views, then it calls sync()
 *   T4  uninstall rc70 on a FRESH-installed tenant (no rc69 backup to restore)
 *   T5  uninstall rc70 on the UPGRADED tenant (Module Loader restores rc69's
 *       backed-up Account vardef, so those two fields are NOT orphans)
 */

namespace Sugarcrm\Sugarcrm\MetaData {
    class ViewdefManager
    {
        public static $views = [];
        public static $saves = [];

        public function loadViewdef($client, $module, $view)
        {
            return self::$views[$module] ?? null;
        }

        public function saveViewdef($defs, $module, $client, $view): void
        {
            self::$views[$module] = $defs;
            self::$saves[] = $module;
        }
    }
}

namespace {
    use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

    class BdTestLog
    {
        public $lines = [];

        public function __call($level, $args)
        {
            $this->lines[] = [$level, (string) ($args[0] ?? '')];
        }
    }
    $GLOBALS['log'] = new BdTestLog();

    /** The config table, as Administration reads and writes it. */
    $GLOBALS['bd_config'] = [];

    class Administration
    {
        public function getConfigForModule($category, $platform = 'base', $clean = false)
        {
            return $GLOBALS['bd_config'][$category] ?? [];
        }

        public function saveSetting($category, $key, $value, $platform = '')
        {
            $GLOBALS['bd_config'][$category][$key] = $value;
            return true;
        }
    }

    class BeanFactory
    {
        public static function getObjectName($module)
        {
            return ['Quotes' => 'Quote', 'Accounts' => 'Account'][$module] ?? '';
        }

        public static function newBean($module)
        {
            if ($module === 'Administration') {
                return new Administration();
            }
            throw new RuntimeException("unexpected bean $module");
        }
    }

    /** Stock fields, then the COMPILED extension vardefs - exactly what refreshVardefs reads. */
    $GLOBALS['bd_stock'] = [
        'Quote' => ['id', 'name', 'quote_stage', 'erp_quote_type', 'erp_quotes_ship_via_name'],
        'Account' => ['id', 'name', 'website'],
    ];
    $GLOBALS['bd_compiled'] = ['Quotes' => [], 'Accounts' => []];

    class VardefManager
    {
        public static function refreshVardefs($module, $object)
        {
            global $dictionary;
            $dictionary[$object] = ['fields' => []];
            foreach ($GLOBALS['bd_stock'][$object] as $f) {
                $dictionary[$object]['fields'][$f] = ['name' => $f, 'type' => 'varchar'];
            }
            foreach ($GLOBALS['bd_compiled'][$module] ?? [] as $source) {
                eval('?>' . $source);
            }
        }
    }

    class RepairAndClear
    {
        public $show_output;
        public $module_list;

        public function clearVardefs()
        {
        }

        /** The ONLY thing that changes the compiled vardefs (see the header). */
        public function rebuildExtensions($modules = [])
        {
            foreach ((array) $modules as $m) {
                $out = [];
                foreach (glob("custom/Extension/modules/{$m}/Ext/Vardefs/*.php") ?: [] as $f) {
                    $out[] = file_get_contents($f);
                }
                $GLOBALS['bd_compiled'][$m] = $out;
            }
        }
    }

    class SugarAutoLoader
    {
        public static function load($path)
        {
        }
    }

    class MetaDataManager
    {
        public static function refreshModulesCache($m)
        {
        }

        public static function refreshCache()
        {
        }
    }

    class MetaDataFiles
    {
        public static function clearModuleClientCache($module, $type = '')
        {
        }
    }

    $layoutFile = getenv('BD_LAYOUT_FIELDS');
    $referenceFile = getenv('BD_ERP_REFERENCE');
    foreach (['BD_LAYOUT_FIELDS' => $layoutFile, 'BD_ERP_REFERENCE' => $referenceFile] as $var => $file) {
        if (!is_string($file) || !is_file($file)) {
            fwrite(STDERR, "$var must name the landed ERP-Core file\n");
            exit(2);
        }
    }

    $pkg = realpath(__DIR__ . '/../../sugar-sell/BenchDogs-Ext');
    $rc70Quotes = file_get_contents("$pkg/custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php");
    $rc70Accounts = file_get_contents("$pkg/custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php");
    // rc69's Account vardef: the same two fields, with NO marker (rc69 placed them
    // with its own layout writer). Built from rc70's by removing the markers, and
    // checked below so the removal cannot silently fail.
    $rc69Accounts = preg_replace("/\\s*'erp_layout' => array\\([^)]*\\),/s", '', $rc70Accounts);

    // A throwaway tenant docroot: ERP-Core's class at its fixed path, and the
    // Extension files the compiler reads.
    $root = sys_get_temp_dir() . '/bd_erp_layout_test_' . getmypid();
    @mkdir("$root/custom/include", 0777, true);
    @mkdir("$root/custom/Extension/modules/Quotes/Ext/Vardefs", 0777, true);
    @mkdir("$root/custom/Extension/modules/Accounts/Ext/Vardefs", 0777, true);
    @mkdir("$root/include/TemplateHandler", 0777, true);
    file_put_contents("$root/include/TemplateHandler/TemplateHandler.php", "<?php\n");
    copy($layoutFile, "$root/custom/include/ErpLayoutExtraFields.php");
    chdir($root);
    set_include_path($root . PATH_SEPARATOR . get_include_path());

    $QV = 'custom/Extension/modules/Quotes/Ext/Vardefs/';
    $AV = 'custom/Extension/modules/Accounts/Ext/Vardefs/';

    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $checks[] = [$name, $expected === $actual, $expected, $actual];
    };
    $names = fn(array $panel) => array_map(fn($e) => is_array($e) ? ($e['name'] ?? '') : $e, $panel['fields'] ?? []);
    $panel = function (string $module, string $panelName) use ($names) {
        foreach (ViewdefManager::$views[$module]['panels'] ?? [] as $p) {
            if (($p['name'] ?? '') === $panelName) {
                return $names($p);
            }
        }
        return null;
    };
    $recorded = function (string $module) {
        $raw = $GLOBALS['bd_config']['erp_layout']['extra_fields_' . $module] ?? '[]';
        $list = json_decode($raw, true);
        sort($list);
        return $list;
    };
    $lifecycle = function (string $script) use ($pkg) {
        // Module Loader require_once's each script inside a METHOD after
        // extract($data): the script's locals are that scope's, $manifest is set.
        $manifest = ['version' => '0.9.42-rcTEST'];
        require "$pkg/scripts/$script";
    };
    $erpEpicorLayouts = function () {
        // ERP-Epicor's QuotesLayout / AccountsLayout, REPLACE mode: the views are
        // rewritten from ERP-Epicor's own definitions - no package's fields - and
        // then ERP-Epicor calls sync() for both modules (a0f6b632).
        ViewdefManager::$views['Quotes'] = ['panels' => [
            ['name' => 'panel_header', 'header' => true, 'fields' => [['name' => 'name']]],
            ['name' => 'panel_body', 'fields' => [['name' => 'quote_stage']]],
            ['name' => 'LBL_RECORDVIEW_PANEL_ERP', 'fields' => [['name' => 'erp_quote_type'],
                                                               ['name' => 'erp_quotes_ship_via_name']]],
        ]];
        ViewdefManager::$views['Accounts'] = ['panels' => [
            ['name' => 'panel_header', 'header' => true, 'fields' => [['name' => 'name']]],
            ['name' => 'panel_body', 'fields' => [['name' => 'website']]],
            ['name' => 'LBL_RECORDVIEW_PANEL_ERP', 'fields' => [['name' => 'erp_credit_hold_badge']]],
        ]];
        ErpLayoutExtraFields::sync('Quotes');
        ErpLayoutExtraFields::sync('Accounts');
    };
    $installFiles = function (array $files) {
        foreach ($files as $path => $body) {
            file_put_contents($path, $body);
        }
    };
    $report = fn() => json_decode($GLOBALS['bd_config']['benchdogs_ext']['install_report'] ?? '{}', true);

    $check('X0 the rc69 stand-in really has no marker, and rc70 has two', [0, 2],
        [substr_count($rc69Accounts, "'erp_layout' => array("), substr_count($rc70Accounts, "'erp_layout' => array(")]);

    // ── T0: ERP-Epicor 1.1.125 + Bench rc69 ────────────────────────────────
    require_once 'custom/include/ErpLayoutExtraFields.php';
    $installFiles([$QV . 'erp_reference.php' => file_get_contents($referenceFile), $AV . 'bd_customer_group.php' => $rc69Accounts]);
    (new RepairAndClear())->rebuildExtensions(['Quotes', 'Accounts']);
    $erpEpicorLayouts();
    // rc69's own writer appended its two fields to panel_body (unmarked).
    ViewdefManager::$views['Accounts']['panels'][1]['fields'][] = ['name' => 'bd_customer_group', 'label' => 'LBL_BD_CUSTOMER_GROUP'];
    ViewdefManager::$views['Accounts']['panels'][1]['fields'][] = ['name' => 'bd_customer_group_code', 'label' => 'LBL_BD_CUSTOMER_GROUP_CODE'];
    $check('T0 ERP-Epicor placed and recorded its own erp_reference; nothing Bench is recorded',
        [['erp_quote_type', 'erp_quotes_ship_via_name', 'erp_reference'], ['erp_reference'], []],
        [$panel('Quotes', 'LBL_RECORDVIEW_PANEL_ERP'), $recorded('Quotes'), $recorded('Accounts')]);

    // ── T1: upgrade to rc70 ────────────────────────────────────────────────
    $installFiles([$QV . 'bd_adm_required_fields.php' => $rc70Quotes, $AV . 'bd_customer_group.php' => $rc70Accounts]);
    ViewdefManager::$saves = [];
    $lifecycle('post_execute.php');
    $check('T1a every post_install step applied',
        ['repair_rebuild' => 'ok', 'accounts_erp_layout' => 'ok', 'quotes_erp_layout' => 'ok'],
        $report()['steps'] ?? null);
    $check('T1b the three pickers land on the ERP panel, after Reference, in order',
        ['erp_quote_type', 'erp_quotes_ship_via_name', 'erp_reference', 'bd_lead_source', 'bd_lead_type', 'bd_project_id'],
        $panel('Quotes', 'LBL_RECORDVIEW_PANEL_ERP'));
    $labels = [];
    foreach (ViewdefManager::$views['Quotes']['panels'][2]['fields'] as $e) {
        if (str_starts_with($e['name'], 'bd_')) {
            $labels[$e['name']] = $e['label'] ?? null;
        }
    }
    $check('T1c each with its own label',
        ['bd_lead_source' => 'LBL_BD_LEAD_SOURCE', 'bd_lead_type' => 'LBL_BD_LEAD_TYPE', 'bd_project_id' => 'LBL_BD_PROJECT_ID'],
        $labels);
    $check('T1d the Account fields rc69 placed stay exactly where they were (no Accounts write)',
        [['website', 'bd_customer_group', 'bd_customer_group_code'], ['Quotes']],
        [$panel('Accounts', 'panel_body'), ViewdefManager::$saves]);
    $check('T1e and are RECORDED now, so an uninstall can retire them',
        [['bd_customer_group', 'bd_customer_group_code'], ['bd_lead_source', 'bd_lead_type', 'bd_project_id', 'erp_reference']],
        [$recorded('Accounts'), $recorded('Quotes')]);

    // ── T2: reinstall rc70 ─────────────────────────────────────────────────
    ViewdefManager::$saves = [];
    $lifecycle('post_execute.php');
    $check('T2 a second install places nothing and writes no view', [], ViewdefManager::$saves);

    // ── T3: ERP-Epicor reinstalled ─────────────────────────────────────────
    $erpEpicorLayouts();
    $check('T3 ERP-Epicor\'s own sync() puts every Bench field back, same places, same order',
        [['erp_quote_type', 'erp_quotes_ship_via_name', 'erp_reference', 'bd_lead_source', 'bd_lead_type', 'bd_project_id'],
         ['website', 'bd_customer_group', 'bd_customer_group_code'], ['erp_credit_hold_badge']],
        [$panel('Quotes', 'LBL_RECORDVIEW_PANEL_ERP'), $panel('Accounts', 'panel_body'),
         $panel('Accounts', 'LBL_RECORDVIEW_PANEL_ERP')]);

    // A field of the UNRELEASED G380 branch, as if a tenant had it: unmarked in
    // every build, so never recorded - sync() will never take it off (lane D's
    // rule 1). rc70 relies on it never having been installed (see RELEASE-NOTES).
    ViewdefManager::$views['Quotes']['panels'][2]['fields'][] = ['name' => 'bd_reference'];

    // ── T4: uninstall rc70 (fresh tenant: nothing to restore) ──────────────
    ViewdefManager::$saves = [];
    $lifecycle('bd_pre_uninstall.php');
    $check('T4a pre_uninstall writes no view (nothing is undone before the files go)', [], ViewdefManager::$saves);
    unlink($QV . 'bd_adm_required_fields.php');       // uninstall_copy
    unlink($AV . 'bd_customer_group.php');
    $lifecycle('post_uninstall.php');
    $check('T4b every Bench picker is off the Quotes view; ERP-Epicor\'s own fields stay',
        ['erp_quote_type', 'erp_quotes_ship_via_name', 'erp_reference', 'bd_reference'],
        $panel('Quotes', 'LBL_RECORDVIEW_PANEL_ERP'));
    $check('T4c the two Account fields are off the view; the vardef-less ERP badge stays',
        [['website'], ['erp_credit_hold_badge']],
        [$panel('Accounts', 'panel_body'), $panel('Accounts', 'LBL_RECORDVIEW_PANEL_ERP')]);
    $check('T4d the record forgets them', [[], ['erp_reference']], [$recorded('Accounts'), $recorded('Quotes')]);
    $check('T4e an UNMARKED stray (the unreleased bd_reference) is never touched by sync()', true,
        in_array('bd_reference', $panel('Quotes', 'LBL_RECORDVIEW_PANEL_ERP'), true));

    // ── T5: uninstall rc70 on the UPGRADED tenant ──────────────────────────
    $installFiles([$QV . 'bd_adm_required_fields.php' => $rc70Quotes, $AV . 'bd_customer_group.php' => $rc70Accounts]);
    ViewdefManager::$views['Quotes']['panels'][2]['fields'] = array_values(array_filter(
        ViewdefManager::$views['Quotes']['panels'][2]['fields'], fn($e) => $e['name'] !== 'bd_reference'));
    $lifecycle('post_execute.php');
    unlink($QV . 'bd_adm_required_fields.php');       // uninstall_copy ...
    file_put_contents($AV . 'bd_customer_group.php', $rc69Accounts);   // ... restores rc69's backup
    $lifecycle('post_uninstall.php');
    $check('T5a the pickers go; the Account fields, defined again by the restored rc69 vardef, stay',
        [['erp_quote_type', 'erp_quotes_ship_via_name', 'erp_reference'], ['website', 'bd_customer_group', 'bd_customer_group_code']],
        [$panel('Quotes', 'LBL_RECORDVIEW_PANEL_ERP'), $panel('Accounts', 'panel_body')]);
    $check('T5b no layout error was logged across the whole life', [],
        array_values(array_filter($GLOBALS['log']->lines,
            fn($l) => str_contains($l[1], '[ErpLayoutExtraFields]') || str_contains($l[1], 'layout sync failed'))));

    // ── report ──────────────────────────────────────────────────────────────
    foreach (array_merge(glob("$root/custom/Extension/modules/*/Ext/Vardefs/*.php") ?: [],
                         glob("$root/custom/include/*.php") ?: [],
                         ["$root/include/TemplateHandler/TemplateHandler.php"]) as $f) {
        @unlink($f);
    }
    $failed = 0;
    foreach ($checks as [$name, $ok, $expected, $actual]) {
        if (!$ok) {
            $failed++;
            echo "FAIL  {$name}\n      expected: " . var_export($expected, true)
                . "\n      actual:   " . var_export($actual, true) . "\n";
        }
    }
    echo count($checks) . " checks, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
