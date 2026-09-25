<?php

/**
 * G380 / G381 (🔒 1705b, 🔒 1724b): the Bench Dogs ADM rules, Sugar half, EXECUTED.
 *
 * Run:  BD_QUOTE_FACTS=<ERP-Epicor's ErpQuoteFacts.php> php scripts/tests/bd_adm_rules_test.php
 *       BD_NO_QUOTE_FACTS=1 php scripts/tests/bd_adm_rules_test.php   (section O)
 *       (scripts/tests/test_php_suites.py runs both, with the sibling
 *       checkout's file when present, else the pin from the landed Sugar
 *       target a0f6b632 under fixtures/shared-sugar/)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * What is proved, each against the real shipped file:
 *   A. which companies are ADM: the ones that published BdLeadSources rows,
 *      split at the FIRST "__" in PHP (never SQL LIKE), one query per request
 *   B. Reference defaults to "CITY STATE", shortened to the ERP's limit (G530)
 *   E. the Project default: unanimous groups only; the list is tenant data
 *   F. an admin's Dropdown Editor list survives a package reinstall, either order
 *   G. erp_lookup_type_list is extended key by key; there is no company list
 *   H. applyDefaults: fills EMPTY erp_reference / bd_project_id on unsent ADM
 *      quotes, nothing else - and exits in cost order, loading nothing for a
 *      quote it cannot touch
 *   I. the pickers' options: active rows of one type, "CODE - Name", '' first
 *   J. the five option functions answer the same list whatever Sugar passes
 *   K. G460 the marketing pickers: only ACTIVE PAIRS are offered (a campaign
 *      with an active event, an event of an active campaign), events keyed
 *      "<campaign>/<seq>" and ordered by campaign then seq as a number
 *   M. the vardefs: five fields, no 'required', the erp_layout markers
 *   N. the before_save registration points at a real class and method
 *   O. (BD_NO_QUOTE_FACTS=1) an ERP-Epicor without ErpQuoteFacts: defaults
 *      skipped and logged, the save never fails
 *
 * ErpQuoteFacts IS ERP-EPICOR'S REAL CLASS (G380 (g), landed a0f6b632), not a
 * stand-in: a stand-in would encode our belief about it. Only BeanFactory is
 * faked - it serves the records and COUNTS every read, which is what makes the
 * exit ORDER observable (a quote the hook cannot touch reads nothing).
 * Measured facts the real class brings that a stand-in hid: companyCode has
 * NO erp_sync_key-prefix fallback (A8), and productGroup trims (A9).
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
        public static $beans = [];
        /** Every record read, as "Module:id", in order. */
        public static $reads = [];

        public static function retrieveBean($module, $id)
        {
            self::$reads[] = $module . ':' . $id;
            return self::$beans[$module][$id] ?? null;
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

    // ERP-Epicor's REAL ErpQuoteFacts, loaded before BdAdmRules exactly as a
    // tenant has it (QuotesErpActionsApi loads it first; BdAdmRules's own
    // guarded include then finds the class declared).
    // G530: ERP-Core's erp_reference vardef, the one that states the limit. The
    // sibling checkout's when BD_ERP_REFERENCE names it, else the pin.
    $referenceVardef = getenv('BD_ERP_REFERENCE') ?: (__DIR__ . '/fixtures/shared-sugar/erp_reference.php');

    $factsFile = getenv('BD_QUOTE_FACTS');
    if (!$noFacts) {
        if (!is_string($factsFile) || !is_file($factsFile)) {
            fwrite(STDERR, "BD_QUOTE_FACTS must name ERP-Epicor's ErpQuoteFacts.php\n");
            exit(2);
        }
        require $factsFile;
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
    // The records ErpQuoteFacts reads: account -> ERP company, template ->
    // category (measured ADM facts, 🔒 1710b: CMI-211967V3K is group CMI,
    // 49000450 is DISPLAYS).
    BeanFactory::$beans = [
        'ERP_Companies' => [
            'co-adm' => $b(['id' => 'co-adm', 'erp_sync_key' => 'ADM']),
            'co-epic' => $b(['id' => 'co-epic', 'erp_sync_key' => 'EPIC06']),
        ],
        'Accounts' => [
            'acct-adm' => $b(['id' => 'acct-adm', 'erp_companies_accountserp_companies_ida' => 'co-adm',
                              'erp_sync_key' => 'ADM__70']),
            'acct-epic' => $b(['id' => 'acct-epic', 'erp_companies_accountserp_companies_ida' => 'co-epic',
                               'erp_sync_key' => 'EPIC06__94']),
            // An ADM-keyed account with NO company relate: the payload sends no
            // company for it, so neither may the rules (no prefix fallback).
            'acct-adm-nolink' => $b(['id' => 'acct-adm-nolink', 'erp_sync_key' => 'ADM__25392']),
        ],
        'ProductTemplates' => [
            'pt-cmi' => $b(['id' => 'pt-cmi', 'erp_display_sync_key' => 'CMI-211967V3K', 'category_id' => 'cat-cmi']),
            'pt-cmi2' => $b(['id' => 'pt-cmi2', 'erp_display_sync_key' => 'CMI-100', 'category_id' => 'cat-cmi']),
            'pt-49' => $b(['id' => 'pt-49', 'erp_display_sync_key' => '49000450', 'category_id' => 'cat-disp']),
        ],
        'ProductCategories' => [
            'cat-cmi' => $b(['id' => 'cat-cmi', 'erp_display_sync_key' => ' CMI ']),
            'cat-disp' => $b(['id' => 'cat-disp', 'erp_display_sync_key' => 'DISPLAYS']),
        ],
    ];
    $line = fn(string $id, string $template) => $b(['id' => $id, 'product_template_id' => $template]);
    $lCmi = $line('l-cmi', 'pt-cmi');
    $lCmi2 = $line('l-cmi2', 'pt-cmi2');
    $l49 = $line('l-49', 'pt-49');
    $accountOf = ['ADM' => 'acct-adm', 'EPIC06' => 'acct-epic', 'ADM-NOLINK' => 'acct-adm-nolink'];
    $quote = function (string $company, array $f = []) use ($b, $accountOf) {
        return $b(['id' => 'q-' . $company, 'billing_account_id' => $accountOf[$company]] + $f);
    };
    $freshQuery = function () {
        BdAdmRules::forgetAdmCompanies();
        SugarQuery::$constructed = 0;
        BeanFactory::$reads = [];
    };
    // The modules read, in order (ids dropped).
    $readModules = fn() => array_map(fn($r) => strstr($r, ':', true), BeanFactory::$reads);

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
        // G530: shortened to what the ERP takes - state kept, city cut.
        $check('B5 G530 the sandbox #36 default fits in 10: HARRISBURG PA -> HARRISB PA', 'HARRISB PA',
            BdAdmRules::defaultReference('HARRISBURG', 'PA', 10));
        $check('B6 G530 a value that already fits is unchanged', 'WAYNE NJ', BdAdmRules::defaultReference('WAYNE', 'NJ', 10));
        $check('B7 G530 exactly the limit is unchanged', 'HARRISB PA', BdAdmRules::defaultReference('HARRISB', 'PA', 10));
        $check('B8 G530 no limit known (0): the full default, as before', 'HARRISBURG PA',
            BdAdmRules::defaultReference('HARRISBURG', 'PA', 0));
        $check('B9 G530 a cut ending on a space drops it (never a double space)', 'NEW NY',
            BdAdmRules::defaultReference('NEW YORK', 'NY', 7));
        $check('B10 G530 no state: the city is cut to the limit', 'HARRI', BdAdmRules::defaultReference('HARRISBURG', '', 5));
        $check('B11 G530 no room for a city beside a long state: the first characters of the whole', 'AB LONGSTA',
            BdAdmRules::defaultReference('AB', 'LONGSTATENAME', 10));
        $check('B12 G530 characters, not bytes', 'MÜNCHEN NW', BdAdmRules::defaultReference('MÜNCHENGLADBACH', 'NW', 10));
        $check('B13 G530 every cut fits the limit', [true, true, true, true],
            array_map(fn($c) => mb_strlen(BdAdmRules::defaultReference($c[0], $c[1], $c[2]), 'UTF-8') <= $c[2],
                [['HARRISBURG', 'PA', 10], ['SALT LAKE CITY', 'UT', 10], ['', 'PENNSYLVANIA', 10], ['X', 'NY', 3]]));

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
        $bdTypes = ['BdLeadSources', 'BdLeadTypes', 'BdProjects', 'BdMarketingCampaigns', 'BdMarketingEvents'];
        $check('G2 the five Bench types are added (G460: the two marketing lists)', $bdTypes,
            array_values(array_intersect($bdTypes, array_keys($types))));
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
        $check('H4 the company and each line\'s group were read through ErpQuoteFacts, in that order',
            ['Accounts', 'ERP_Companies', 'ProductTemplates', 'ProductCategories', 'ProductTemplates',
             'ProductCategories'], $readModules());

        $freshQuery();
        $typed = $quote('ADM', ['shipping_address_city' => 'WAYNE', 'shipping_address_state' => 'NJ',
                                'erp_reference' => 'SHOW BOOTH 4', 'bd_project_id' => '22008', 'lines' => [$lCmi]]);
        $check('H5 both set: nothing overwritten, and NOTHING read (exit 2)', [[], 'SHOW BOOTH 4', '22008', 0, []],
            [BdAdmRules::applyDefaults($typed), $typed->erp_reference, $typed->bd_project_id,
             SugarQuery::$constructed, BeanFactory::$reads]);

        $freshQuery();
        $sent = $quote('ADM', ['erp_display_sync_key' => '8761', 'shipping_address_city' => 'WAYNE',
                               'shipping_address_state' => 'NJ', 'lines' => [$lCmi]]);
        $check('H6 a quote already in the ERP: left alone, NOTHING read (exit 1)', [[], 0, []],
            [BdAdmRules::applyDefaults($sent), SugarQuery::$constructed, BeanFactory::$reads]);

        $freshQuery();
        SugarQuery::$rows = [$ADM_ROWS[3]];                  // a tenant with no lead sources at all
        $stock = $quote('ADM', ['shipping_address_city' => 'WAYNE', 'lines' => [$lCmi]]);
        $check('H7 no company published lead sources: one query, no company lookup (exit 3)', [[], 1, []],
            [BdAdmRules::applyDefaults($stock), SugarQuery::$constructed, BeanFactory::$reads]);
        SugarQuery::$rows = $ADM_ROWS;

        $freshQuery();
        $epic = $quote('EPIC06', ['shipping_address_city' => 'AUSTIN', 'shipping_address_state' => 'TX',
                                  'lines' => [$lCmi]]);
        $check('H8 an EPIC06 quote is left alone (control); its lines are never read',
            [[], null, ['Accounts', 'ERP_Companies']],
            [BdAdmRules::applyDefaults($epic), $epic->erp_reference ?? null, $readModules()]);

        $freshQuery();
        $nolink = $quote('ADM-NOLINK', ['shipping_address_city' => 'WAYNE', 'lines' => [$lCmi]]);
        $check('A8 an ADM-keyed account with no company relate is NOT ADM (the payload sends no company '
            . 'for it; ErpQuoteFacts has no key-prefix fallback)', [[], null],
            [BdAdmRules::applyDefaults($nolink), $nolink->erp_reference ?? null]);
        $check('A9 a padded category code is trimmed by ErpQuoteFacts, so it still maps', '20065',
            BdAdmRules::defaultProject([ErpQuoteFacts::productGroup($lCmi)]));

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

        // G530, end to end: the limit is ERP-Epicor's (ErpQuoteFacts::referenceMaxLength(), the REAL
        // class), read from ERP-Core's REAL erp_reference vardef (pinned with it), not restated here.
        $dictionary = [];
        include $referenceVardef;
        $referenceDefs = ['erp_reference' => $dictionary['Quote']['fields']['erp_reference']];
        $freshQuery();
        $long = $quote('ADM', ['shipping_address_city' => 'HARRISBURG', 'shipping_address_state' => 'PA',
                               'field_defs' => $referenceDefs, 'lines' => [$lCmi]]);
        $check('H13 G530 the sandbox #36 ship-to (HARRISBURG PA) defaults to a Reference ADM takes',
            [['erp_reference', 'bd_project_id'], 'HARRISB PA'],
            [BdAdmRules::applyDefaults($long), $long->erp_reference ?? null]);
        $check('H14 G530 the limit came from the field (the real vardef states 10)', 10,
            BdAdmRules::referenceMaxLength($long));
        $freshQuery();
        $olderDefs = $referenceDefs;
        unset($olderDefs['erp_reference']['erp_max_length']);   // an ERP-Core vardef from before G530
        $noDefs = $quote('ADM', ['shipping_address_city' => 'HARRISBURG', 'shipping_address_state' => 'PA',
                                 'field_defs' => $olderDefs, 'lines' => [$lCmi]]);
        BdAdmRules::applyDefaults($noDefs);
        $check('H15 G530 CONTROL a field that states no limit (older ERP-Core) keeps the full default',
            'HARRISBURG PA', $noDefs->erp_reference ?? null);
        $typedLong = $quote('ADM', ['shipping_address_city' => 'HARRISBURG', 'shipping_address_state' => 'PA',
                                    'erp_reference' => 'HARRISBURG PA', 'field_defs' => $referenceDefs, 'lines' => [$lCmi]]);
        BdAdmRules::applyDefaults($typedLong);
        $check('H16 G530 CONTROL a Reference already set is never shortened here (the seller\'s, or older)',
            'HARRISBURG PA', $typedLong->erp_reference);
        // An ERP-Epicor older than G530 (ErpQuoteFacts without referenceMaxLength): no cut, no error.
        $older = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
            'class ErpQuoteFacts {} class L { public $e = []; function error($m) { $this->e[] = $m; } }'
            . ' $GLOBALS["log"] = new L(); chdir(' . var_export($pkg, true) . ');'
            . ' require "custom/modules/Quotes/BdAdmRules.php"; $q = new stdClass(); $q->id = "q-old";'
            . ' $max = BdAdmRules::referenceMaxLength($q);'
            . ' echo json_encode([$max, BdAdmRules::defaultReference("HARRISBURG", "PA", $max),'
            . ' count($GLOBALS["log"]->e) === 1 && strpos($GLOBALS["log"]->e[0], "older than G530") !== false'
            . ' && strpos($GLOBALS["log"]->e[0], "q-old") !== false]);')
            . ' 2>&1');
        $check('H17 G530 an older ERP-Epicor (no referenceMaxLength): limit 0, the full default, no error, '
            . 'and the reason LOGGED with the quote', [0, 'HARRISBURG PA', true], json_decode((string) $older, true));

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
                public $billing_account_id = 'acct-adm';
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

        // ── K. G460 the marketing pickers ───────────────────────────────────
        // ADM's shapes (read-only 2026-09-25): 26DISCNV active with active
        // events 1, 2, 10 (10 is invented here to prove the NUMBER order); 25DIRCNV
        // active but every event retired (25 of ADM's 42 active campaigns look
        // like this); 16BLDHW retired; ADM reuses one description everywhere.
        $K_CAMPS = [
            ['type' => 'BdMarketingCampaigns', 'is_active' => 1, 'erp_display_sync_key' => '26DISCNV', 'name' => '2026 DISP-GROCERY/CONV'],
            ['type' => 'BdMarketingCampaigns', 'is_active' => 1, 'erp_display_sync_key' => '26BREHC', 'name' => '26BREHC'],
            ['type' => 'BdMarketingCampaigns', 'is_active' => 1, 'erp_display_sync_key' => '25DIRCNV', 'name' => '2025 DIRECT CONV'],
            ['type' => 'BdMarketingCampaigns', 'is_active' => 0, 'erp_display_sync_key' => '16BLDHW', 'name' => '2016 BUILDING'],
        ];
        $K_EVENTS = [
            ['type' => 'BdMarketingEvents', 'is_active' => 1, 'erp_display_sync_key' => '26DISCNV/10', 'name' => 'EXISTING CUST - NEW PROJECT'],
            ['type' => 'BdMarketingEvents', 'is_active' => 1, 'erp_display_sync_key' => '26DISCNV/2', 'name' => 'EXISTING CUST - RECURRING'],
            ['type' => 'BdMarketingEvents', 'is_active' => 1, 'erp_display_sync_key' => '26BREHC/4', 'name' => 'NEW CUST-BRAND ENV-HEALTH CLB'],
            ['type' => 'BdMarketingEvents', 'is_active' => 1, 'erp_display_sync_key' => '26DISCNV/1', 'name' => 'EXISTING CUST - NEW PROJECT'],
            ['type' => 'BdMarketingEvents', 'is_active' => 0, 'erp_display_sync_key' => '25DIRCNV/1', 'name' => 'EXISTING CUST - NEW PROJECT'],
            ['type' => 'BdMarketingEvents', 'is_active' => 1, 'erp_display_sync_key' => '16BLDHW/1', 'name' => 'EXISTING CUST - NEW PROJECT'],
            ['type' => 'BdMarketingEvents', 'is_active' => 1, 'erp_display_sync_key' => 'NOSEQ', 'name' => 'not a key'],
        ];
        SugarQuery::$rows = array_merge($K_CAMPS, $K_EVENTS);
        SugarQuery::$constructed = 0;
        $check('K1 campaigns: only those with an active event, in code order, "CODE - Name"',
            ['' => '', '26BREHC' => '26BREHC', '26DISCNV' => '26DISCNV - 2026 DISP-GROCERY/CONV'],
            BdAdmRules::marketingOptions('BdMarketingCampaigns'));
        $check('K2 events: active events of active campaigns, by campaign then seq AS A NUMBER, keyed "<campaign>/<seq>"',
            ['' => '', '26BREHC/4' => '26BREHC/4 - NEW CUST-BRAND ENV-HEALTH CLB',
             '26DISCNV/1' => '26DISCNV/1 - EXISTING CUST - NEW PROJECT',
             '26DISCNV/2' => '26DISCNV/2 - EXISTING CUST - RECURRING',
             '26DISCNV/10' => '26DISCNV/10 - EXISTING CUST - NEW PROJECT'],
            BdAdmRules::marketingOptions('BdMarketingEvents'));
        $check('K3 two queries per list, active rows of one type each, across teams',
            [4, ['type' => 'BdMarketingEvents', 'is_active' => 1], ['team_security' => false]],
            [SugarQuery::$constructed, SugarQuery::$last->whereObj->equals, SugarQuery::$last->fromOptions]);
        // Numeric-looking seqs a whole-number check must refuse (is_numeric()
        // would take "2.5" as 2 and "1e1" as 10: a different event than picked).
        $check('K4 the event key splits at the LAST separator; a non-key is null',
            [['26DISCNV', 2], ['A/B', 3], null, null, null, null, null, null, null, null],
            [BdAdmRules::eventCampaign('26DISCNV/2'), BdAdmRules::eventCampaign(' A/B/3 '),
             BdAdmRules::eventCampaign('26DISCNV'), BdAdmRules::eventCampaign('26DISCNV/0'),
             BdAdmRules::eventCampaign('/2'), BdAdmRules::eventCampaign('26DISCNV/x'),
             BdAdmRules::eventCampaign("26DISCNV/\u{00B2}"), BdAdmRules::eventCampaign('26DISCNV/2.5'),
             BdAdmRules::eventCampaign('26DISCNV/1e1'), BdAdmRules::eventCampaign('26DISCNV/+3')]);
        $check('K5 no rows published yet (a tenant before the connector publishes): only the blank',
            [['' => ''], ['' => '']],
            [BdAdmRules::marketingOptionsFromRows('BdMarketingCampaigns', [], []),
             BdAdmRules::marketingOptionsFromRows('BdMarketingEvents', $K_CAMPS, [])]);
        $check('K6 another type answers only the blank (never a mixed list)', ['' => ''],
            BdAdmRules::marketingOptionsFromRows('BdLeadSources', $K_CAMPS, $K_EVENTS));
        $check('K7 the two option functions read their own list, whatever Sugar passes',
            [BdAdmRules::marketingOptions('BdMarketingCampaigns'), BdAdmRules::marketingOptions('BdMarketingEvents')],
            [bd_adm_marketing_campaign_options(...$legacy), bd_adm_marketing_event_options('BdMarketingCampaigns')]);
        $check('K8 NO default: the before_save hook never names either field',
            [false, false],
            [str_contains(file_get_contents('custom/modules/Quotes/BdAdmRules.php'), '$bean->bd_marketing_'),
             str_contains(file_get_contents('custom/Extension/modules/Quotes/Ext/LogicHooks/bd_adm_quote_defaults.php'), 'marketing')]);

        // ── M. the vardefs ──────────────────────────────────────────────────
        $dictionary = [];
        include 'custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php';
        $fields = $dictionary['Quote']['fields'] ?? [];
        $check('M1 exactly the five fields, in placement order (Reference is ERP-Epicor\'s erp_reference)',
            ['bd_lead_source', 'bd_lead_type', 'bd_project_id', 'bd_marketing_campaign', 'bd_marketing_event'],
            array_keys($fields));
        $check('M2 none is required (Ophir/EPIC06 would be unable to save a quote)', [],
            array_keys(array_filter($fields, fn($f) => !empty($f['required']))));
        $fnOk = [];
        foreach (array_keys($fields) as $f) {
            $fn = $fields[$f]['function'] ?? [];
            $fnOk[$f] = is_file($fn['include'] ?? '') && function_exists($fn['name'] ?? '') && empty($fields[$f]['options']);
        }
        $check('M3 each picker names a function that exists, in a file that ships',
            ['bd_lead_source' => true, 'bd_lead_type' => true, 'bd_project_id' => true,
             'bd_marketing_campaign' => true, 'bd_marketing_event' => true], $fnOk);
        $check('M4 each carries ERP-Epicor\'s marker: record view, ERP panel, after Reference in order', [
                'bd_lead_source' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'erp_reference'],
                'bd_lead_type' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'bd_lead_source'],
                'bd_project_id' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'bd_lead_type'],
                'bd_marketing_campaign' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'bd_project_id'],
                'bd_marketing_event' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'bd_marketing_campaign'],
            ], array_map(fn($f) => $f['erp_layout'] ?? null, $fields));
        $mod_strings = [];
        include 'custom/Extension/modules/Quotes/Ext/Language/en_us.bd_adm_required_fields.php';
        $missing = [];
        foreach ($fields as $f) {
            if (!isset($mod_strings[$f['vname']])) {
                $missing[] = $f['vname'];
            }
        }
        $check('M5 every field has its label, and no orphan label is left', [[], 5], [$missing, count($mod_strings)]);
        // G578: "Marketing Campaign" was cut to "Marketing Ca..." on the Quotes ERP
        // panel (benchdogs-dev, 2026-09-25) while "Marketing Event" showed in full.
        $check('M5b G460/G578 the labels are ADM\'s words, the campaign one short enough to show',
            ['Mktg Campaign', 'Marketing Event'],
            [$mod_strings['LBL_BD_MARKETING_CAMPAIGN'] ?? null, $mod_strings['LBL_BD_MARKETING_EVENT'] ?? null]);
        $tooLong = array_filter($mod_strings, fn($text) => mb_strlen((string) $text, 'UTF-8') > mb_strlen('Marketing Event', 'UTF-8'));
        $check('M5c G578 no Bench quote label is longer than "Marketing Event", the longest measured to show in full',
            [], $tooLong);
        // G574: the stock EnumField pre-picks the FIRST option on create unless
        // the def says defaultToBlank (clients/base/fields/enum/enum.js,
        // _checkForDefaultValue, SugarEnt 26.1.0), and a JS object lists integer-
        // like project codes before the blank: Project read "17879 - LGH EXPANSION"
        // on a new quote with no account. None of the five may be browser-defaulted.
        $check('M7 G574 every picker is left blank by the browser (defaultToBlank), none names a default',
            ['bd_lead_source' => [true, false], 'bd_lead_type' => [true, false], 'bd_project_id' => [true, false],
             'bd_marketing_campaign' => [true, false], 'bd_marketing_event' => [true, false]],
            array_map(fn($f) => [($f['defaultToBlank'] ?? null) === true, array_key_exists('default', $f)], $fields));
        $dictionary = [];
        include 'custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php';
        // rc72 (G507): panel_overview after Industry - the first tab of the measured
        // Bench tenant, which has no panel_body (rc69 put them in its HEADER).
        $check('M6 the two Account fields: panel_overview after Industry, name then code', [
                'bd_customer_group' => ['view' => 'record', 'panel' => 'panel_overview', 'after' => 'industry'],
                'bd_customer_group_code' => ['view' => 'record', 'panel' => 'panel_overview', 'after' => 'bd_customer_group'],
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
