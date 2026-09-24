<?php

/**
 * G380 / G381 (🔒 1705b, 🔒 1724b): the Bench Dogs ADM rules, Sugar half, EXECUTED.
 *
 * Run:  php scripts/tests/bd_adm_rules_test.php
 *       BD_NO_QUOTE_FACTS=1 php scripts/tests/bd_adm_rules_test.php   (section O)
 *       (scripts/tests/test_php_suites.py runs both)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * What is proved, each against the real shipped file:
 *   A. which companies are ADM: the ones that published BdLeadSources rows,
 *      split at the FIRST "__" in PHP (never SQL LIKE), one query per request
 *   B. Reference defaults to "CITY STATE"
 *   E. the Project default: unanimous groups only; the list is tenant data
 *   F. an admin's Dropdown Editor list survives a package reinstall, either order
 *   G. erp_lookup_type_list is extended key by key; there is no company list
 *   H. applyDefaults: fills EMPTY erp_reference / bd_project_id on unsent ADM
 *      quotes, nothing else - and exits in cost order, loading nothing for a
 *      quote it cannot touch
 *   I. the pickers' options: active rows of one type, "CODE - Name", '' first
 *   J. the three option functions answer the same list whatever Sugar passes
 *   M. the vardefs: three fields, no 'required', the erp_layout markers
 *   N. the before_save registration points at a real class and method
 *   O. (BD_NO_QUOTE_FACTS=1) an ERP-Epicor without ErpQuoteFacts: defaults
 *      skipped and logged, the save never fails
 *
 * 🚩 ErpQuoteFacts IS A STAND-IN HERE. It is ERP-Epicor's class (G380 (g), lane
 * D), not this package's; the stand-in encodes the contract
 * (g380-contract.md §2(g): companyCode($quote): string, productGroup($line):
 * string, '' for unknown) and counts its calls so the exit ORDER is observable.
 * That the real class has those two methods is checked by test_php_suites.py
 * against ERP-Epicor's file when a checkout carries it - a fixture cannot test
 * the belief it encodes.
 */

namespace {
    $noFacts = getenv('BD_NO_QUOTE_FACTS') === '1';

    class BdTestLog
    {
        public $lines = [];

        public function __call($level, $args)
        {
            $this->lines[] = [$level, (string) ($args[0] ?? '')];
        }
    }
    $GLOBALS['log'] = new BdTestLog();

    class BdTestLink
    {
        public $beans;

        public function __construct(array $beans)
        {
            $this->beans = $beans;
        }

        public function getBeans($params = [])
        {
            return $this->beans;
        }
    }

    #[AllowDynamicProperties]
    class BdTestBean
    {
        public $id = '';
        public $lines = null;

        public function __construct(array $fields = [])
        {
            foreach ($fields as $k => $v) {
                $this->$k = $v;
            }
        }

        public function load_relationship($name)
        {
            if ($name !== 'products' || $this->lines === null) {
                return false;
            }
            $this->products = new BdTestLink($this->lines);
            return true;
        }
    }

    class BeanFactory
    {
        public static $created = 0;

        public static function retrieveBean($module, $id)
        {
            throw new RuntimeException("BdAdmRules read a {$module} record itself; it must ask ErpQuoteFacts");
        }

        public static function newBean($module)
        {
            // A SugarQuery's from() template (ERP_LookupValues) is not a record;
            // anything else the rules instantiate would be.
            if ($module !== 'ERP_LookupValues') {
                self::$created++;
            }
            return new BdTestBean(['module_name' => $module]);
        }
    }

    class BdTestWhere
    {
        public $equals = [];

        public function equals($field, $value)
        {
            $this->equals[$field] = $value;
            return $this;
        }
    }

    class SugarQuery
    {
        public static $rows = [];
        public static $last = null;
        public static $constructed = 0;
        public $from = null;
        public $fromOptions = [];
        public $select = [];
        public $whereObj;
        public $order = [];
        public $limit = null;

        public function __construct()
        {
            $this->whereObj = new BdTestWhere();
            self::$last = $this;
            self::$constructed++;
        }

        public function from($bean, $options = [])
        {
            $this->from = $bean;
            $this->fromOptions = $options;
        }

        public function select($fields)
        {
            $this->select = $fields;
        }

        public function where()
        {
            return $this->whereObj;
        }

        public function orderBy($field, $dir)
        {
            $this->order = [$field, $dir];
        }

        public function limit($n)
        {
            $this->limit = $n;
        }

        public function execute()
        {
            $out = [];
            foreach (self::$rows as $row) {
                foreach ($this->whereObj->equals as $f => $v) {
                    if ((string) ($row[$f] ?? '') !== (string) $v) {
                        continue 2;
                    }
                }
                $out[] = $row;
            }
            usort($out, fn($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
            return $out;
        }
    }

    if (!$noFacts) {
        /** The contract stand-in for ERP-Epicor's ErpQuoteFacts (see the header). */
        class ErpQuoteFacts
        {
            public static $calls = [];

            public static function companyCode($quote): string
            {
                self::$calls[] = 'companyCode';
                return (string) ($quote->test_company ?? '');
            }

            public static function productGroup($line): string
            {
                self::$calls[] = 'productGroup';
                return (string) ($line->test_group ?? '');
            }
        }
    }

    function return_app_list_strings_language($lang)
    {
        return $GLOBALS['app_list_strings'];
    }

    // The package root is the tenant's docroot as far as these files know:
    // every path they name ('custom/modules/Quotes/...') is relative to it.
    $pkg = realpath(__DIR__ . '/../../sugar-sell/BenchDogs-Ext');
    chdir($pkg);

    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $checks[] = [$name, $expected === $actual, $expected, $actual];
    };

    // The shipped application-list fragment, over a stand-in for core's list.
    $GLOBALS['app_list_strings'] = ['erp_lookup_type_list' => ['' => '', 'Country' => 'Country']];
    $loadLists = function () {
        global $app_list_strings;
        include 'custom/Extension/application/Ext/Language/en_us.bd_adm_lists.php';
    };
    $loadLists();

    require 'custom/modules/Quotes/BdAdmRules.php';
    require 'custom/modules/Quotes/BdAdmLookupOptions.php';

    // What core publishes for the ADM connection's code lists (core's scoped
    // key: <company>__<type>_<code>), plus rows that must not be read as ADM.
    $ADM_ROWS = [
        ['type' => 'BdLeadSources', 'is_active' => 1, 'erp_sync_key' => 'ADM__BdLeadSources_DIRMAIL',
         'erp_display_sync_key' => 'DIRMAIL', 'name' => 'Direct mail'],
        ['type' => 'BdLeadSources', 'is_active' => 0, 'erp_sync_key' => 'ADM__BdLeadSources_OLD',
         'erp_display_sync_key' => 'OLD', 'name' => 'Retired'],
        ['type' => 'BdLeadTypes', 'is_active' => 1, 'erp_sync_key' => 'EPIC06__BdLeadTypes_X',
         'erp_display_sync_key' => 'X', 'name' => 'Not a lead source'],
        ['type' => 'Country', 'is_active' => 1, 'erp_sync_key' => 'EPIC06__Country_USA',
         'erp_display_sync_key' => 'USA', 'name' => 'United States'],
    ];

    $b = fn(array $f) => new BdTestBean($f);
    $line = fn(string $id, string $group) => $b(['id' => $id, 'test_group' => $group]);
    $lCmi = $line('l-cmi', 'CMI');
    $lCmi2 = $line('l-cmi2', 'CMI');
    $l49 = $line('l-49', 'DISPLAYS');
    $quote = function (string $company, array $f = []) use ($b) {
        return $b(['id' => 'q-' . $company, 'test_company' => $company] + $f);
    };
    $freshQuery = function () {
        BdAdmRules::forgetAdmCompanies();
        SugarQuery::$constructed = 0;
        if (class_exists('ErpQuoteFacts', false) && property_exists('ErpQuoteFacts', 'calls')) {
            ErpQuoteFacts::$calls = [];
        }
    };

    if ($noFacts) {
        // ── O. an ERP-Epicor without ErpQuoteFacts ──────────────────────────
        SugarQuery::$rows = $ADM_ROWS;
        $freshQuery();
        $GLOBALS['log']->lines = [];
        $q = $quote('ADM', ['shipping_address_city' => 'WAYNE', 'shipping_address_state' => 'NJ',
                            'lines' => [$lCmi]]);
        $threw = null;
        try {
            (new BdAdmRules())->beforeSave($q, 'before_save', []);
            $set = BdAdmRules::applyDefaults($q);
        } catch (\Throwable $e) {
            $threw = get_class($e) . ': ' . $e->getMessage();
        }
        $check('O1 no ErpQuoteFacts: nothing is defaulted, nothing throws', [null, [], null],
            [$threw, $set ?? null, $q->erp_reference ?? null]);
        $check('O2 the class really is absent in this run (the include found no file)', false,
            class_exists('ErpQuoteFacts', false));
        $check('O3 and the reason is logged, naming the file and the quote', true,
            (bool) array_filter($GLOBALS['log']->lines, fn($l) => $l[0] === 'error'
                && str_contains($l[1], 'custom/modules/Quotes/ErpQuoteFacts.php')
                && str_contains($l[1], 'q-ADM')));
    } else {
        // ── A. which companies are ADM ──────────────────────────────────────
        $check('A1 the company is the text before the FIRST "__"', ['ADM', 'ADM2', 'EPIC06'],
            BdAdmRules::companiesFromKeys([
                ['erp_sync_key' => 'ADM__BdLeadSources_DIRMAIL'],
                ['erp_sync_key' => 'adm__BdLeadSources_PHONE'],       // case folds to ADM
                ['erp_sync_key' => 'ADM2__BdLeadSources_X'],          // a distinct company
                ['erp_sync_key' => 'EPIC06__BdLeadSources_Y'],
            ]));
        $check('A2 no "__", a leading "__", or no key: no company', [],
            BdAdmRules::companiesFromKeys([
                ['erp_sync_key' => 'ADMX_BdLeadSources_Y'],           // the LIKE 'ADM__%' trap
                ['erp_sync_key' => '__BdLeadSources_Q'],
                ['erp_sync_key' => ''],
                [],
            ]));
        SugarQuery::$rows = $ADM_ROWS;
        $freshQuery();
        $check('A3 ADM published lead sources: ADM, and only ADM', ['ADM'], BdAdmRules::admCompanies());
        $check('A4 the query reads BdLeadSources keys across teams, bounded',
            [['type' => 'BdLeadSources'], ['team_security' => false], ['erp_sync_key'], BdAdmRules::MAX_LEAD_SOURCE_ROWS],
            [SugarQuery::$last->whereObj->equals, SugarQuery::$last->fromOptions, SugarQuery::$last->select,
             SugarQuery::$last->limit]);
        BdAdmRules::admCompanies();
        BdAdmRules::isAdmCompany('EPIC06');
        $check('A5 one query per request, however often it is asked', 1, SugarQuery::$constructed);
        $check('A6 case and padding do not matter; EPIC06 (no lead sources) is not ADM', [true, false, false],
            [BdAdmRules::isAdmCompany(' adm '), BdAdmRules::isAdmCompany('EPIC06'), BdAdmRules::isAdmCompany('')]);
        $check('A7 an inactive-only company still counts (it published the type)', ['ADM'],
            BdAdmRules::companiesFromKeys([$ADM_ROWS[1]]));

        // ── B. Reference ────────────────────────────────────────────────────
        $check('B1 city and state', 'WAYNE NJ', BdAdmRules::defaultReference('WAYNE', 'NJ'));
        $check('B2 padding is trimmed', 'WAYNE NJ', BdAdmRules::defaultReference(' WAYNE ', ' NJ '));
        $check('B3 city alone', 'WAYNE', BdAdmRules::defaultReference('WAYNE', ''));
        $check('B4 nothing: nothing invented', '', BdAdmRules::defaultReference(' ', ''));

        // ── E. the Project default ──────────────────────────────────────────
        $check('E1 CMI -> 20065 (the shipped starting entry)', '20065', BdAdmRules::defaultProject(['CMI']));
        $check('E2 every line CMI: still 20065', '20065', BdAdmRules::defaultProject(['CMI', 'cmi']));
        $check('E3 CMI mixed with DISPLAYS (no dominant project): the seller picks', '',
            BdAdmRules::defaultProject(['CMI', 'DISPLAYS']));
        $check('E4 a line with no group: the seller picks', '', BdAdmRules::defaultProject(['CMI', '']));
        $check('E5 no lines: nothing', '', BdAdmRules::defaultProject([]));
        $saved = $GLOBALS['app_list_strings']['bd_adm_project_by_group_list'];
        $GLOBALS['app_list_strings']['bd_adm_project_by_group_list'] = ['CMI' => '20065', 'STL' => '20065', 'RET' => '30001'];
        $check('E6 two groups on ONE project pre-fill it', '20065', BdAdmRules::defaultProject(['CMI', 'STL']));
        $check('E7 two groups on two projects do not', '', BdAdmRules::defaultProject(['CMI', 'RET']));
        $GLOBALS['app_list_strings']['bd_adm_project_by_group_list'] = $saved;

        // ── F. the admin's list survives a reinstall, in either merge order ─
        $admin = ['CMI' => '20065', 'DISPLAYS' => '41000'];
        $GLOBALS['app_list_strings'] = ['erp_lookup_type_list' => ['' => '']];
        $GLOBALS['app_list_strings']['bd_adm_project_by_group_list'] = $admin; // admin fragment first
        $loadLists();                                                          // then the reinstalled package
        $check('F1 admin first, package after: the admin list stands', $admin,
            $GLOBALS['app_list_strings']['bd_adm_project_by_group_list']);
        $GLOBALS['app_list_strings'] = ['erp_lookup_type_list' => ['' => '']];
        $loadLists();                                                          // package first
        $GLOBALS['app_list_strings']['bd_adm_project_by_group_list'] = $admin; // then the admin's whole list
        $check('F2 package first, admin after: the admin list stands', '41000', BdAdmRules::defaultProject(['DISPLAYS']));

        // ── G. core's lookup-type list is extended, never replaced ─────────
        $GLOBALS['app_list_strings'] = ['erp_lookup_type_list' => ['' => '', 'Country' => 'Country', 'Reason' => 'Reason']];
        $loadLists();
        $types = $GLOBALS['app_list_strings']['erp_lookup_type_list'];
        $check('G1 core\'s types survive', ['Country', 'Reason'],
            array_values(array_intersect(['Country', 'Reason'], array_keys($types))));
        $check('G2 the three Bench types are added', ['BdLeadSources', 'BdLeadTypes', 'BdProjects'],
            array_values(array_intersect(['BdLeadSources', 'BdLeadTypes', 'BdProjects'], array_keys($types))));
        $check('G3 the shipped project default is in place', ['CMI' => '20065'],
            $GLOBALS['app_list_strings']['bd_adm_project_by_group_list']);
        $check('G4 there is NO company list any more (🔒 1724b: one source for "ADM")', false,
            isset($GLOBALS['app_list_strings']['bd_adm_companies_list']));

        // ── H. applyDefaults ────────────────────────────────────────────────
        SugarQuery::$rows = $ADM_ROWS;
        $createdBefore = BeanFactory::$created;
        $freshQuery();
        $fresh = $quote('ADM', ['shipping_address_city' => 'WAYNE', 'shipping_address_state' => 'NJ',
                                'lines' => [$lCmi, $lCmi2]]);
        $set = BdAdmRules::applyDefaults($fresh);
        $check('H1 an unsent ADM quote gets Reference and Project', ['erp_reference', 'bd_project_id'], $set);
        $check('H2 Reference is the ship-to city and state, in the GENERIC field', ['WAYNE NJ', null],
            [$fresh->erp_reference, $fresh->bd_reference ?? null]);
        $check('H3 Project is the default for its all-CMI lines', '20065', $fresh->bd_project_id);
        $check('H4 the company and each line\'s group were ASKED of ErpQuoteFacts',
            ['companyCode', 'productGroup', 'productGroup'], ErpQuoteFacts::$calls);

        $freshQuery();
        $typed = $quote('ADM', ['shipping_address_city' => 'WAYNE', 'shipping_address_state' => 'NJ',
                                'erp_reference' => 'SHOW BOOTH 4', 'bd_project_id' => '22008', 'lines' => [$lCmi]]);
        $check('H5 both set: nothing overwritten, and NOTHING read (exit 2)', [[], 'SHOW BOOTH 4', '22008', 0, []],
            [BdAdmRules::applyDefaults($typed), $typed->erp_reference, $typed->bd_project_id,
             SugarQuery::$constructed, ErpQuoteFacts::$calls]);

        $freshQuery();
        $sent = $quote('ADM', ['erp_display_sync_key' => '8761', 'shipping_address_city' => 'WAYNE',
                               'shipping_address_state' => 'NJ', 'lines' => [$lCmi]]);
        $check('H6 a quote already in the ERP: left alone, NOTHING read (exit 1)', [[], 0, []],
            [BdAdmRules::applyDefaults($sent), SugarQuery::$constructed, ErpQuoteFacts::$calls]);

        $freshQuery();
        SugarQuery::$rows = [$ADM_ROWS[3]];                  // a tenant with no lead sources at all
        $stock = $quote('ADM', ['shipping_address_city' => 'WAYNE', 'lines' => [$lCmi]]);
        $check('H7 no company published lead sources: one query, no company lookup (exit 3)', [[], 1, []],
            [BdAdmRules::applyDefaults($stock), SugarQuery::$constructed, ErpQuoteFacts::$calls]);
        SugarQuery::$rows = $ADM_ROWS;

        $freshQuery();
        $epic = $quote('EPIC06', ['shipping_address_city' => 'AUSTIN', 'shipping_address_state' => 'TX',
                                  'lines' => [$lCmi]]);
        $check('H8 an EPIC06 quote is left alone (control); its lines are never read', [[], null, ['companyCode']],
            [BdAdmRules::applyDefaults($epic), $epic->erp_reference ?? null, ErpQuoteFacts::$calls]);

        $freshQuery();
        $mixed = $quote('ADM', ['shipping_address_city' => 'WAYNE', 'lines' => [$lCmi, $l49]]);
        $check('H9 CMI + DISPLAYS lines: Reference only, the seller picks the Project', ['erp_reference'],
            BdAdmRules::applyDefaults($mixed));
        $noLines = $quote('ADM', ['shipping_address_city' => 'WAYNE', 'shipping_address_state' => 'NJ']);
        $check('H10 a quote with no lines yet: Reference only', ['erp_reference'], BdAdmRules::applyDefaults($noLines));
        $onlyProject = $quote('ADM', ['erp_reference' => 'X', 'lines' => [$lCmi]]);
        $check('H11 Reference set, Project empty: Project only', ['bd_project_id'],
            BdAdmRules::applyDefaults($onlyProject));
        $check('H12 🔒 1499: the before_save hook creates no record, ever', 0, BeanFactory::$created - $createdBefore);

        $freshQuery();
        foreach (range(1, 5) as $i) {
            (new BdAdmRules())->beforeSave($quote('ADM', ['id' => "q-$i", 'lines' => []]), 'before_save', []);
        }
        $check('H13 five saves in one request: one query', 1, SugarQuery::$constructed);

        $GLOBALS['log']->lines = [];
        $threw = null;
        try {
            (new BdAdmRules())->beforeSave(new class {
                public $id = 'q-broken';
                public $test_company = 'ADM';
                public function load_relationship($n) { throw new RuntimeException('link table unreadable'); }
            }, 'before_save', []);
        } catch (\Throwable $e) {
            $threw = get_class($e);
        }
        $check('H14 a defaults failure never fails the seller\'s save (MLP004)', null, $threw);
        $check('H15 and it is logged, naming the quote', true,
            (bool) array_filter($GLOBALS['log']->lines, fn($l) => $l[0] === 'error'
                && str_contains($l[1], 'q-broken') && str_contains($l[1], 'link table unreadable')));

        // ── I. the pickers' options ─────────────────────────────────────────
        SugarQuery::$rows = [
            ['type' => 'BdLeadSources', 'is_active' => 1, 'erp_display_sync_key' => 'DIRMAIL', 'name' => 'Direct mail'],
            ['type' => 'BdLeadSources', 'is_active' => 1, 'erp_display_sync_key' => 'PHONE', 'name' => 'PHONE'],
            ['type' => 'BdLeadTypes', 'is_active' => 1, 'erp_display_sync_key' => 'DIRFOOD', 'name' => 'Direct food'],
            ['type' => 'BdLeadTypes', 'is_active' => 0, 'erp_display_sync_key' => 'AGENCY', 'name' => 'Agency'],
            ['type' => 'BdProjects', 'is_active' => 1, 'erp_display_sync_key' => '20065', 'name' => 'CMI program'],
            ['type' => 'BdProjects', 'is_active' => 1, 'erp_display_sync_key' => '', 'name' => 'no code'],
        ];
        $check('I1 lead sources: \'\' first, "CODE - Name", a code-only label stays the code',
            ['' => '', 'DIRMAIL' => 'DIRMAIL - Direct mail', 'PHONE' => 'PHONE'],
            BdAdmRules::lookupOptions('BdLeadSources'));
        $check('I2 lead types: the inactive AGENCY is not offered', ['' => '', 'DIRFOOD' => 'DIRFOOD - Direct food'],
            BdAdmRules::lookupOptions('BdLeadTypes'));
        $check('I3 the query asks for active rows of one type, across teams',
            [['type' => 'BdLeadTypes', 'is_active' => 1], ['team_security' => false]],
            [SugarQuery::$last->whereObj->equals, SugarQuery::$last->fromOptions]);
        $check('I4 a row with no code is skipped', ['' => '', '20065' => '20065 - CMI program'],
            BdAdmRules::lookupOptions('BdProjects'));

        // ── J. the option functions ignore whatever Sugar passes ───────────
        $legacy = [['BD_LEAD_SOURCE' => 'X'], 'bd_lead_source', 'X', 'ListView'];
        $check('J1 lead source: same list bare and with the legacy signature',
            bd_adm_lead_source_options(), bd_adm_lead_source_options(...$legacy));
        $check('J2 lead source reads its own type', BdAdmRules::lookupOptions('BdLeadSources'), bd_adm_lead_source_options());
        $check('J3 lead type reads its own type', BdAdmRules::lookupOptions('BdLeadTypes'), bd_adm_lead_type_options('BdProjects'));
        $check('J4 project reads its own type', BdAdmRules::lookupOptions('BdProjects'), bd_adm_project_options());

        // ── M. the vardefs ──────────────────────────────────────────────────
        $dictionary = [];
        include 'custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php';
        $fields = $dictionary['Quote']['fields'] ?? [];
        $check('M1 exactly the three fields, in placement order (Reference is ERP-Epicor\'s erp_reference)',
            ['bd_lead_source', 'bd_lead_type', 'bd_project_id'], array_keys($fields));
        $check('M2 none is required (Ophir/EPIC06 would be unable to save a quote)', [],
            array_keys(array_filter($fields, fn($f) => !empty($f['required']))));
        $fnOk = [];
        foreach (array_keys($fields) as $f) {
            $fn = $fields[$f]['function'] ?? [];
            $fnOk[$f] = is_file($fn['include'] ?? '') && function_exists($fn['name'] ?? '') && empty($fields[$f]['options']);
        }
        $check('M3 each picker names a function that exists, in a file that ships',
            ['bd_lead_source' => true, 'bd_lead_type' => true, 'bd_project_id' => true], $fnOk);
        $check('M4 each carries ERP-Epicor\'s marker: record view, ERP panel, after Reference in order', [
                'bd_lead_source' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'erp_reference'],
                'bd_lead_type' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'bd_lead_source'],
                'bd_project_id' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'bd_lead_type'],
            ], array_map(fn($f) => $f['erp_layout'] ?? null, $fields));
        $mod_strings = [];
        include 'custom/Extension/modules/Quotes/Ext/Language/en_us.bd_adm_required_fields.php';
        $missing = [];
        foreach ($fields as $f) {
            if (!isset($mod_strings[$f['vname']])) {
                $missing[] = $f['vname'];
            }
        }
        $check('M5 every field has its label, and no orphan label is left', [[], 3], [$missing, count($mod_strings)]);
        $dictionary = [];
        include 'custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php';
        $check('M6 the two Account fields: panel_body (where rc69 put them), name then code', [
                'bd_customer_group' => ['view' => 'record', 'panel' => 'panel_body', 'after' => ''],
                'bd_customer_group_code' => ['view' => 'record', 'panel' => 'panel_body', 'after' => 'bd_customer_group'],
            ], array_map(fn($f) => $f['erp_layout'] ?? null, $dictionary['Account']['fields'] ?? []));

        // ── N. the before_save registration ─────────────────────────────────
        $hook_array = [];
        include 'custom/Extension/modules/Quotes/Ext/LogicHooks/bd_adm_quote_defaults.php';
        $h = $hook_array['before_save'][0] ?? [];
        $check('N1 before_save -> an existing file, class and instance method', [true, true],
            [is_file($h[2] ?? ''), method_exists($h[3] ?? '', $h[4] ?? '')]);

        // ── P. the one fixed path ───────────────────────────────────────────
        $src = file_get_contents('custom/modules/Quotes/BdAdmRules.php');
        $check('P1 the include names the same literal path as QUOTE_FACTS_FILE', true,
            str_contains($src, "@include_once '" . BdAdmRules::QUOTE_FACTS_FILE . "';"));
    }

    // ── report ──────────────────────────────────────────────────────────────
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
