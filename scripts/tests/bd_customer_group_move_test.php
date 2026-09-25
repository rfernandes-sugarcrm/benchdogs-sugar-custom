<?php

/**
 * G507: the Bench Dogs customer-group fields leave the Account HEADER and land,
 * labelled and read-only, on the record's first tab ("Overview" on Ophir).
 *
 * Run:  BD_LAYOUT_FIELDS=<ERP-Core's custom/include/ErpLayoutExtraFields.php> \
 *       php scripts/tests/bd_customer_group_move_test.php
 *       (scripts/tests/test_php_suites.py sets it: the sibling checkout's file
 *       when present, else the pin under fixtures/shared-sugar)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT IS REAL: the one-off's scripts/post_execute.php
 * (sugar-sell/ONEOFF-MoveBdCustomerGroup), run the way Module Loader runs it;
 * BenchDogs-Ext rc72's vardef and its post_execute / bd_pre_uninstall /
 * post_uninstall scripts; ERP-Core's ErpLayoutExtraFields; rc69's REAL writer
 * and vardef (fixtures/rc69, byte copies of the rc69 cut). WHAT IS FAKED: the
 * deployed viewdefs, the config table and the extension compiler (as in
 * bd_erp_layout_test.php: compiled vardefs change only on rebuildExtensions()).
 *
 * THE VIEWS. OPHIR is the Accounts record view SERVED by ophirsx177 (SugarEnt
 * 26.1.0) on 2026-09-24, panel names, flags and field order as read from
 * App.metadata: panel_header (with the two fields rc69 put there, each carrying a
 * baked 'type' => 'text'), panel_overview (newTab, the "Overview" tab),
 * panel_phone_and_address, the Predict tab, panel_information, panel_hidden, and
 * ERP-Epicor's four panels. There is NO panel_body. STOCK is SugarEnt 26.1.0 GA's
 * modules/Accounts/clients/base/views/record/record.php panel list, with
 * panel_body made a tab by ERP-Epicor's setPanelBodyAsNewTab().
 *
 * If the one-off were broken the reading would be: panels[0] (the header) still
 * lists bd_customer_group / bd_customer_group_code, or panel_overview does not.
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

    class BdFakeAccount
    {
        public $field_defs = [];
    }

    /**
     * Accounts' field_defs: an override when a unit case sets
     * $GLOBALS['mv_field_defs'], else the stock fields plus the COMPILED
     * extension vardefs - what a bean on the tenant would carry.
     */
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
            if ($module === 'Accounts') {
                $bean = new BdFakeAccount();
                if (isset($GLOBALS['mv_field_defs'])) {
                    $bean->field_defs = $GLOBALS['mv_field_defs'];
                } else {
                    VardefManager::refreshVardefs('Accounts', 'Account');
                    $bean->field_defs = $GLOBALS['dictionary']['Account']['fields'];
                }
                return $bean;
            }
            throw new RuntimeException("unexpected bean $module");
        }
    }

    $GLOBALS['bd_stock'] = [
        'Quote' => ['id', 'name', 'quote_stage'],
        'Account' => ['id', 'name', 'website', 'industry', 'parent_name', 'account_type', 'assigned_user_name',
                      'last_opportunity_date', 'description', 'tag', 'phone_office'],
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
        public static $cleared = [];

        public static function clearModuleClientCache($module, $type = '')
        {
            self::$cleared[] = "$module/$type";
        }
    }

    class TemplateHandler
    {
        public static $cleared = [];

        public static function clearCache($module)
        {
            self::$cleared[] = $module;
        }
    }

    $layoutFile = getenv('BD_LAYOUT_FIELDS');
    if (!is_string($layoutFile) || !is_file($layoutFile)) {
        fwrite(STDERR, "BD_LAYOUT_FIELDS must name the landed ERP-Core ErpLayoutExtraFields.php\n");
        exit(2);
    }

    $repo = realpath(__DIR__ . '/../..');
    $pkg = "$repo/sugar-sell/BenchDogs-Ext";
    $oneOff = "$repo/sugar-sell/ONEOFF-MoveBdCustomerGroup/scripts/post_execute.php";
    $rc72Accounts = file_get_contents("$pkg/custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php");
    $rc72Quotes = file_get_contents("$pkg/custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php");
    $rc69Accounts = file_get_contents(__DIR__ . '/fixtures/rc69/bd_customer_group.php.txt');
    $rc69Writer = __DIR__ . '/fixtures/rc69/BdAccountsLayoutExtensions.php.txt';

    // A throwaway docroot: ERP-Core's class at its fixed path, the Extension
    // files the compiler reads, and the TemplateHandler the scripts include.
    $root = sys_get_temp_dir() . '/bd_customer_group_move_test_' . getmypid();
    @mkdir("$root/custom/include", 0777, true);
    @mkdir("$root/custom/Extension/modules/Quotes/Ext/Vardefs", 0777, true);
    @mkdir("$root/custom/Extension/modules/Accounts/Ext/Vardefs", 0777, true);
    @mkdir("$root/include/TemplateHandler", 0777, true);
    file_put_contents("$root/include/TemplateHandler/TemplateHandler.php", "<?php\n");
    copy($layoutFile, "$root/custom/include/ErpLayoutExtraFields.php");
    chdir($root);
    set_include_path($root . PATH_SEPARATOR . get_include_path());
    require_once 'custom/include/ErpLayoutExtraFields.php';
    $AV = 'custom/Extension/modules/Accounts/Ext/Vardefs/';
    $QV = 'custom/Extension/modules/Quotes/Ext/Vardefs/';

    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $checks[] = [$name, $expected === $actual, $expected, $actual];
    };
    $entryName = fn($e) => is_string($e) ? $e : (is_array($e) ? (string) ($e['name'] ?? '') : '');
    $names = fn(?array $panel) => $panel === null ? null : array_map($entryName, $panel['fields'] ?? []);
    $panelOf = function (string $name) {
        foreach (ViewdefManager::$views['Accounts']['panels'] ?? [] as $p) {
            if (($p['name'] ?? '') === $name) {
                return $p;
            }
        }
        return null;
    };
    $entry = function (string $panel, string $field) use ($panelOf) {
        foreach ($panelOf($panel)['fields'] ?? [] as $e) {
            if (is_array($e) && ($e['name'] ?? '') === $field) {
                return $e;
            }
        }
        return null;
    };
    $recorded = function () {
        $list = json_decode($GLOBALS['bd_config']['erp_layout']['extra_fields_Accounts'] ?? '[]', true);
        sort($list);
        return $list;
    };
    // Run the one-off exactly as Module Loader does: require (not _once - the
    // cases run it repeatedly) inside a method scope with $manifest extracted.
    $runOneOff = function () use ($oneOff) {
        $manifest = ['version' => '1.0.0-TEST'];
        ob_start();
        require $oneOff;
        ob_end_clean();
    };
    $lastOutcome = function () {
        foreach (array_reverse($GLOBALS['log']->lines) as [$level, $line]) {
            if (str_starts_with($line, 'ONEOFF-MoveBdCustomerGroup')) {
                return $line;
            }
        }
        return '';
    };
    $lifecycle = function (string $script) use ($pkg) {
        $manifest = ['version' => '0.9.42-rcTEST'];
        require "$pkg/scripts/$script";
    };
    $install = function (array $files) {
        foreach ($files as $path => $body) {
            file_put_contents($path, $body);
        }
        (new RepairAndClear())->rebuildExtensions(['Accounts', 'Quotes']);
    };

    // ── the views ──────────────────────────────────────────────────────────
    $BUTTONS = [['type' => 'button', 'name' => 'erp_create_opp_quote_button'], ['type' => 'actiondropdown', 'name' => 'main_dropdown']];
    $headerStock = [
        ['name' => 'picture', 'type' => 'avatar'], ['name' => 'name', 'type' => 'name'],
        ['name' => 'favorite', 'type' => 'favorite'], ['name' => 'is_escalated', 'type' => 'badge'],
        ['name' => 'erp_credit_hold_badge', 'type' => 'erp-credit-badge'],
        ['name' => 'erp_inactive_account_badge', 'type' => 'erp-source-badge'],
        ['name' => 'follow', 'type' => 'follow'],
    ];
    $rc69Header = [
        ['name' => 'bd_customer_group', 'label' => 'LBL_BD_CUSTOMER_GROUP', 'type' => 'text'],
        ['name' => 'bd_customer_group_code', 'label' => 'LBL_BD_CUSTOMER_GROUP_CODE', 'type' => 'text'],
    ];
    $erpPanels = [
        ['name' => 'LBL_RECORDVIEW_PANEL_ERP', 'newTab' => true, 'fields' => [['name' => 'erp_companies_accounts_name'], ['name' => 'erp_account_id']]],
        ['name' => 'LBL_RECORDVIEW_PANEL_BILLING_DETAIL', 'newTab' => false, 'fields' => [['name' => 'erp_currency_id']]],
        ['name' => 'LBL_RECORDVIEW_PANEL_CREDIT_DETAIL', 'newTab' => false, 'fields' => [['name' => 'erp_credit_limit']]],
        ['name' => 'LBL_RECORDVIEW_PANEL_SYNC_STATUS', 'newTab' => false, 'fields' => [['name' => 'erp_writeback_status']]],
    ];
    /** Ophir's served Accounts record view (2026-09-24); $header is panel_header's fields. */
    $ophir = fn(array $header) => ['buttons' => $BUTTONS, 'panels' => array_merge([
        ['name' => 'panel_header', 'header' => true, 'fields' => $header],
        ['name' => 'panel_overview', 'label' => 'LBL_RECORD_PANEL_OVERVIEW', 'newTab' => true, 'fields' => [
            'parent_name', 'assigned_user_name', 'account_type', 'industry', 'last_opportunity_date', [],
            ['name' => 'description', 'span' => 12], ['name' => 'tag', 'span' => 12]]],
        ['name' => 'panel_phone_and_address', 'newTab' => false, 'fields' => [
            'last_interaction_date', 'action_log_activity', 'phone_office', 'phone_fax', 'phone_alternate',
            'website', 'billing_address', []]],
        ['name' => 'LBL_RECORDVIEW_PANEL_PREDICT', 'newTab' => true, 'fields' => [['name' => 'salesi_id']]],
        ['name' => 'panel_information', 'newTab' => true, 'fields' => ['team_name', [], 'date_entered_by', 'date_modified_by']],
        ['name' => 'panel_hidden', 'hide' => true, 'fields' => ['epicor_deeplink_url', 'erp_display_sync_key']],
    ], $erpPanels)];
    /** SugarEnt 26.1.0 GA's Accounts panels, panel_body made a tab by ERP-Epicor. */
    $stock = fn(array $header) => ['buttons' => $BUTTONS, 'panels' => array_merge([
        ['name' => 'panel_header', 'header' => true, 'fields' => $header],
        ['name' => 'panel_body', 'label' => 'LBL_RECORD_BODY', 'newTab' => true, 'fields' => [
            'website', 'industry', 'parent_name', 'account_type', 'assigned_user_name', 'phone_office',
            ['name' => 'tag', 'span' => 12], []]],
        ['name' => 'panel_hidden', 'hide' => true, 'fields' => ['description']],
    ], $erpPanels)];

    $OVERVIEW_MOVED = ['parent_name', 'assigned_user_name', 'account_type', 'industry',
                       'bd_customer_group', 'bd_customer_group_code', 'last_opportunity_date', '', 'description', 'tag'];
    $OVERVIEW_STOCK = ['parent_name', 'assigned_user_name', 'account_type', 'industry', 'last_opportunity_date', '',
                       'description', 'tag'];
    $HEADER_CLEAN = ['picture', 'name', 'favorite', 'is_escalated', 'erp_credit_hold_badge', 'erp_inactive_account_badge', 'follow'];
    $BD = ['bd_customer_group' => ['name' => 'bd_customer_group', 'type' => 'varchar'],
           'bd_customer_group_code' => ['name' => 'bd_customer_group_code', 'type' => 'varchar']];
    $DEFS_WITH_BD = array_merge(['id' => ['name' => 'id'], 'name' => ['name' => 'name'], 'industry' => ['name' => 'industry']], $BD);
    // Quotes only so BenchDogs-Ext's quotes_erp_layout step has a view to read.
    $QUOTES = ['panels' => [
        ['name' => 'panel_header', 'header' => true, 'fields' => [['name' => 'name']]],
        ['name' => 'panel_body', 'newTab' => true, 'fields' => [['name' => 'quote_stage']]],
        ['name' => 'LBL_RECORDVIEW_PANEL_ERP', 'newTab' => true, 'fields' => [['name' => 'erp_quote_type']]],
    ]];
    $reset = function (array $view, ?array $fieldDefs) use ($QUOTES) {
        ViewdefManager::$views = ['Accounts' => $view, 'Quotes' => $QUOTES];
        ViewdefManager::$saves = [];
        MetaDataFiles::$cleared = [];
        TemplateHandler::$cleared = [];
        $GLOBALS['mv_field_defs'] = $fieldDefs;
    };

    // ── R: THE ROOT CAUSE, reproduced with rc69's REAL writer ─────────────
    $reset($ophir($headerStock), $DEFS_WITH_BD);
    require_once $rc69Writer;
    BdAccountsLayoutExtensions::writeCustomerGroupField();
    $check('R1 rc69\'s writer, on Ophir\'s view (no panel_body), put both fields in the HEADER',
        array_merge($HEADER_CLEAN, ['bd_customer_group', 'bd_customer_group_code']), $names($panelOf('panel_header')));
    $reset($stock($headerStock), $DEFS_WITH_BD);
    BdAccountsLayoutExtensions::writeCustomerGroupField();
    $check('R2 control: on a stock view (panel_body present) it put them in panel_body',
        [$HEADER_CLEAN, true], [$names($panelOf('panel_header')), in_array('bd_customer_group', $names($panelOf('panel_body')), true)]);

    // ── M: the one-off, unit cases ─────────────────────────────────────────
    $reset($ophir(array_merge($headerStock, $rc69Header)), $DEFS_WITH_BD);
    $before = ViewdefManager::$views['Accounts'];
    $runOneOff();
    $after = ViewdefManager::$views['Accounts'];
    $check('M1a the header loses both fields and keeps everything else, in order', $HEADER_CLEAN, $names($panelOf('panel_header')));
    $check('M1b both land on panel_overview (the first tab), after Industry, name then code', $OVERVIEW_MOVED, $names($panelOf('panel_overview')));
    $check('M1c each is a NEW labelled entry, not the header entry moved (no baked type)',
        [['name' => 'bd_customer_group', 'label' => 'LBL_BD_CUSTOMER_GROUP'],
         ['name' => 'bd_customer_group_code', 'label' => 'LBL_BD_CUSTOMER_GROUP_CODE']],
        [$entry('panel_overview', 'bd_customer_group'), $entry('panel_overview', 'bd_customer_group_code')]);
    $check('M1d exactly one write, and the Accounts caches cleared', [['Accounts'], ['Accounts/view', 'Accounts/layout'], ['Accounts']],
        [ViewdefManager::$saves, MetaDataFiles::$cleared, TemplateHandler::$cleared]);
    $untouched = [];
    foreach ($after['panels'] as $i => $p) {
        if (!in_array($p['name'], ['panel_header', 'panel_overview'], true)) {
            $untouched[] = $p === $before['panels'][$i];
        }
    }
    $check('M1e buttons and every other panel are byte-identical', [true, array_fill(0, count($before['panels']) - 2, true)],
        [$after['buttons'] === $before['buttons'], $untouched]);
    $check('M1f the log names the outcome', true, str_contains($lastOutcome(), '(G507): MOVED - bd_customer_group: moved from the header to panel_overview (after industry)'));

    $runOneOff();
    $check('M2 a second run writes nothing and says ALREADY CLEAN', [[['Accounts']], true],
        [[ViewdefManager::$saves], str_contains($lastOutcome(), 'ALREADY CLEAN')]);

    // An admin put both on the phone panel; rc69's header copies are still there.
    $admin = $ophir(array_merge($headerStock, $rc69Header));
    $admin['panels'][2]['fields'][] = ['name' => 'bd_customer_group'];
    $admin['panels'][2]['fields'][] = ['name' => 'bd_customer_group_code'];
    $reset($admin, $DEFS_WITH_BD);
    $runOneOff();
    $check('M3 admin placement elsewhere is KEPT; only the header duplicates go; nothing added to Overview',
        [$HEADER_CLEAN, $OVERVIEW_STOCK, ['last_interaction_date', 'action_log_activity', 'phone_office', 'phone_fax',
          'phone_alternate', 'website', 'billing_address', '', 'bd_customer_group', 'bd_customer_group_code'], true],
        [$names($panelOf('panel_header')), $names($panelOf('panel_overview')), $names($panelOf('panel_phone_and_address')),
         str_contains($lastOutcome(), 'RETIRED FROM HEADER')]);

    // Mixed: the code is admin-placed inside a FIELDSET on the phone panel.
    $mixed = $ophir(array_merge($headerStock, $rc69Header));
    $mixed['panels'][2]['fields'][] = ['name' => 'bd_group_pair', 'fields' => ['bd_customer_group_code']];
    $reset($mixed, $DEFS_WITH_BD);
    $runOneOff();
    $check('M4 a fieldset member counts as placed: only the name moves to Overview (after Industry)',
        [$HEADER_CLEAN, ['parent_name', 'assigned_user_name', 'account_type', 'industry', 'bd_customer_group',
          'last_opportunity_date', '', 'description', 'tag']],
        [$names($panelOf('panel_header')), $names($panelOf('panel_overview'))]);

    // Bench Dogs absent (stock / et shape): no vardef for either field.
    $reset($ophir(array_merge($headerStock, $rc69Header)), ['id' => ['name' => 'id'], 'name' => ['name' => 'name']]);
    $runOneOff();
    $check('M5 no vardef: retired from the header, placed NOWHERE (never an orphan placement)',
        [$HEADER_CLEAN, $OVERVIEW_STOCK, true],
        [$names($panelOf('panel_header')), $names($panelOf('panel_overview')), str_contains($lastOutcome(), 'NOT placed (no vardef')]);

    $reset($ophir(array_merge($headerStock, $rc69Header)), ['bd_customer_group' => []]);
    $runOneOff();
    $check('M6 vardefs that look unread (no id/name): SKIPPED, nothing written', [[], true],
        [ViewdefManager::$saves, str_contains($lastOutcome(), 'SKIPPED')]);

    $reset($stock(array_merge($headerStock, $rc69Header)), $DEFS_WITH_BD);
    $runOneOff();
    $check('M7 stock-shaped view: the first tab is panel_body; after Industry there',
        [$HEADER_CLEAN, ['website', 'industry', 'bd_customer_group', 'bd_customer_group_code', 'parent_name',
          'account_type', 'assigned_user_name', 'phone_office', 'tag', '']],
        [$names($panelOf('panel_header')), $names($panelOf('panel_body'))]);

    $noTabs = $ophir(array_merge($headerStock, $rc69Header));
    foreach ($noTabs['panels'] as $i => $p) {
        unset($noTabs['panels'][$i]['newTab']);
    }
    $reset($noTabs, $DEFS_WITH_BD);
    $runOneOff();
    $check('M8 no tab at all: the first non-header panel with fields', $OVERVIEW_MOVED, $names($panelOf('panel_overview')));

    $reset(['buttons' => $BUTTONS, 'panels' => [['name' => 'panel_header', 'header' => true,
        'fields' => array_merge($headerStock, $rc69Header)]]], $DEFS_WITH_BD);
    $runOneOff();
    $check('M9 no panel can take them: SKIPPED, the header left exactly as it was',
        [[], array_merge($HEADER_CLEAN, ['bd_customer_group', 'bd_customer_group_code']), true],
        [ViewdefManager::$saves, $names($panelOf('panel_header')), str_contains($lastOutcome(), 'SKIPPED')]);

    $reset($ophir(array_merge($headerStock, ['bd_customer_group_code', 'bd_customer_group'])), $DEFS_WITH_BD);
    $runOneOff();
    $check('M10 plain-string header entries are evicted too, and placed in canonical order', [$HEADER_CLEAN, $OVERVIEW_MOVED],
        [$names($panelOf('panel_header')), $names($panelOf('panel_overview'))]);

    $noIndustry = $ophir(array_merge($headerStock, $rc69Header));
    $noIndustry['panels'][1]['fields'] = ['parent_name', 'account_type'];
    $reset($noIndustry, $DEFS_WITH_BD);
    $runOneOff();
    $check('M11 no Industry on the tab: appended at its end, name then code',
        ['parent_name', 'account_type', 'bd_customer_group', 'bd_customer_group_code'], $names($panelOf('panel_overview')));

    // A non-tab panel with fields sits ABOVE the first tab: the first TAB wins.
    $intro = $ophir(array_merge($headerStock, $rc69Header));
    array_splice($intro['panels'], 1, 0, [['name' => 'panel_intro', 'fields' => ['website']]]);
    $reset($intro, $DEFS_WITH_BD);
    $runOneOff();
    $check('M12 a non-tab panel above the first tab is skipped: they go to panel_overview',
        [['website'], $OVERVIEW_MOVED], [$names($panelOf('panel_intro')), $names($panelOf('panel_overview'))]);

    // ── L: the tenant's life, with BenchDogs-Ext's REAL scripts ────────────
    unset($GLOBALS['mv_field_defs']);
    $GLOBALS['bd_config'] = [];
    // L0: Ophir today - ERP-Epicor + Bench rc69, the fields in the header.
    $install([$AV . 'bd_customer_group.php' => $rc69Accounts]);
    $reset($ophir(array_merge($headerStock, $rc69Header)), null);
    unset($GLOBALS['mv_field_defs']);

    // L1: upgrade to rc72 ALONE.
    $install([$AV . 'bd_customer_group.php' => $rc72Accounts, $QV . 'bd_adm_required_fields.php' => $rc72Quotes]);
    ViewdefManager::$saves = [];
    $lifecycle('post_execute.php');
    $check('L1 rc72 alone does NOT move them (sync() never moves a placed field) - it records them',
        [array_merge($HEADER_CLEAN, ['bd_customer_group', 'bd_customer_group_code']), [], ['bd_customer_group', 'bd_customer_group_code']],
        [$names($panelOf('panel_header')), array_values(array_filter(ViewdefManager::$saves, fn($m) => $m === 'Accounts')), $recorded()]);

    // L2: the one-off.
    $runOneOff();
    $check('L2 the one-off moves them: header clean, Overview after Industry', [$HEADER_CLEAN, $OVERVIEW_MOVED],
        [$names($panelOf('panel_header')), $names($panelOf('panel_overview'))]);
    $afterOneOff = ViewdefManager::$views['Accounts'];

    // L3: rc72 reinstalled.
    ViewdefManager::$saves = [];
    $lifecycle('post_execute.php');
    $check('L3 a reinstall writes no Accounts view', [], array_values(array_filter(ViewdefManager::$saves, fn($m) => $m === 'Accounts')));

    // L4: a FRESH Ophir-shaped tenant, rc72 installed: the marker gives the same slots.
    $GLOBALS['bd_config'] = [];
    $reset($ophir($headerStock), null);
    unset($GLOBALS['mv_field_defs']);
    $lifecycle('post_execute.php');
    $check('L4 fresh install via the rc72 marker == upgraded tenant via the one-off (same panel, same slots, same entries)',
        [$afterOneOff['panels'][0], $afterOneOff['panels'][1]],
        [ViewdefManager::$views['Accounts']['panels'][0], ViewdefManager::$views['Accounts']['panels'][1]]);

    // L5: one-off FIRST (still on rc69 vardefs, no marker), then rc72.
    $GLOBALS['bd_config'] = [];
    $install([$AV . 'bd_customer_group.php' => $rc69Accounts]);
    $reset($ophir(array_merge($headerStock, $rc69Header)), null);
    unset($GLOBALS['mv_field_defs']);
    $runOneOff();
    $install([$AV . 'bd_customer_group.php' => $rc72Accounts]);
    ViewdefManager::$saves = [];
    $lifecycle('post_execute.php');
    $check('L5 order-independent: one-off on rc69, then rc72 - same view, and rc72 writes nothing more',
        [$afterOneOff['panels'][0], $afterOneOff['panels'][1], []],
        [ViewdefManager::$views['Accounts']['panels'][0], ViewdefManager::$views['Accounts']['panels'][1],
         array_values(array_filter(ViewdefManager::$saves, fn($m) => $m === 'Accounts'))]);

    // L6: uninstall rc72 (fresh: no rc69 backup restored).
    $lifecycle('bd_pre_uninstall.php');
    unlink($AV . 'bd_customer_group.php');
    unlink($QV . 'bd_adm_required_fields.php');
    $lifecycle('post_uninstall.php');
    $check('L6 uninstall takes both off Overview; the header stays clean; the record forgets them',
        [$HEADER_CLEAN, $OVERVIEW_STOCK, []],
        [$names($panelOf('panel_header')), $names($panelOf('panel_overview')), $recorded()]);

    // Uninstall over a tenant whose fields are still in the header (rc72 without
    // the one-off): sync() retires from EVERY panel, the header included.
    $GLOBALS['bd_config'] = [];
    $install([$AV . 'bd_customer_group.php' => $rc72Accounts, $QV . 'bd_adm_required_fields.php' => $rc72Quotes]);
    $reset($ophir(array_merge($headerStock, $rc69Header)), null);
    unset($GLOBALS['mv_field_defs']);
    $lifecycle('post_execute.php');
    unlink($AV . 'bd_customer_group.php');
    unlink($QV . 'bd_adm_required_fields.php');
    $lifecycle('post_uninstall.php');
    $check('L7 uninstall with the fields still in the header takes them off the header too',
        [$HEADER_CLEAN, $OVERVIEW_STOCK], [$names($panelOf('panel_header')), $names($panelOf('panel_overview'))]);

    // ── V: the rc72 vardef itself ──────────────────────────────────────────
    $dictionary = [];
    eval('?>' . $rc72Accounts);
    $f = $dictionary['Account']['fields'];
    $check('V1 both fields are read-only, never inline-edited, marked for panel_overview after Industry / after the name',
        [[true, false, 'panel_overview', 'industry'], [true, false, 'panel_overview', 'bd_customer_group']],
        [[$f['bd_customer_group']['readonly'] ?? null, $f['bd_customer_group']['inline_edit'] ?? null,
          $f['bd_customer_group']['erp_layout']['panel'] ?? null, $f['bd_customer_group']['erp_layout']['after'] ?? null],
         [$f['bd_customer_group_code']['readonly'] ?? null, $f['bd_customer_group_code']['inline_edit'] ?? null,
          $f['bd_customer_group_code']['erp_layout']['panel'] ?? null, $f['bd_customer_group_code']['erp_layout']['after'] ?? null]]);
    $check('V2 the labels are the two the owner named', ['LBL_BD_CUSTOMER_GROUP', 'LBL_BD_CUSTOMER_GROUP_CODE'],
        [$f['bd_customer_group']['vname'], $f['bd_customer_group_code']['vname']]);

    $check('Z no layout error was logged', [],
        array_values(array_filter($GLOBALS['log']->lines, fn($l) => str_contains($l[1], '[ErpLayoutExtraFields]')
            || str_contains($l[1], 'layout sync failed') || str_contains($l[1], ': FAILED'))));

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
