<?php

/**
 * G380 / G381 (🔒 1705b, 🔒 1724b): the Bench Dogs ADM rules, Sugar half, EXECUTED.
 *
 * Run:  BD_QUOTE_FACTS=<ERP-Epicor 1.1's global ErpQuoteFacts.php> php scripts/tests/bd_adm_rules_test.php
 *       BD_NO_QUOTE_FACTS=1 php scripts/tests/bd_adm_rules_test.php   (section O)
 *       BD_ERP_AUTOLOADER=<ERP-Core/tests/support/sugar_autoloader.php of an
 *         ERP-Epicor 1.2.0 checkout> php scripts/tests/bd_adm_rules_test.php
 *                                                                (section T)
 *       (scripts/tests/test_php_suites.py runs all three: the first with the
 *       last global class ERP-Epicor shipped (1.1.179, pinned under
 *       fixtures/erp-epicor-1.1/); the third with the sibling checkout's
 *       autoloader when present, else the pin of 1.2.0 (erp-integration-sugar
 *       280e0929) under fixtures/shared-sugar/)
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
 *   M. the vardefs: five fields, no 'required', the erp_layout markers, and
 *      (G571/G570) the erp-dependent-enum keys on the Event and the Project
 *   N. the before_save registration points at a real class and method
 *   O. (BD_NO_QUOTE_FACTS=1) an ERP-Epicor without ErpQuoteFacts: defaults
 *      skipped and logged, the save never fails
 *   T. (BD_ERP_AUTOLOADER) ERP-Epicor 1.2.0, T2 of the Rafael review
 *      (erp-integration-sugar #158): ERP-Epicor's ErpQuoteFacts is
 *      Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts in custom/src, AUTOLOADED,
 *      and the old global file is gone. The whole suite runs again against
 *      that class, found only by an autoloading lookup - ERP-Core's own
 *      stand-in for SugarAutoLoader, so the autoload rule is upstream's, not
 *      written here; a leftover old file beside it is never included. In
 *      every run an autoloader is registered (Sugar always has one), so the
 *      other two runs prove the namespaced lookup misses cleanly where the
 *      class is not installed.
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

    // T2 / ERP-Epicor 1.2.0: the namespaced class. BD_ERP_AUTOLOADER names
    // ERP-Core's tests/support/sugar_autoloader.php in a 1.2.0 checkout (the
    // sibling's, or the pinned mirror under fixtures/shared-sugar/). Required,
    // it registers THAT checkout's ERP-Epicor and ERP-Core custom roots
    // (relative to itself), so ErpQuoteFacts is reachable only by an
    // autoloading lookup; nothing loads it up front.
    $NS_FACTS = 'Sugarcrm\\Sugarcrm\\custom\\Erp\\ErpQuoteFacts';
    $erpAutoloader = (string) getenv('BD_ERP_AUTOLOADER');
    $nsFacts = $erpAutoloader !== '';
    if ($nsFacts) {
        require $erpAutoloader;
    }
    // A spy at the HEAD of the chain, registered in EVERY run as Sugar always
    // has an autoloader: it records each Sugarcrm\Sugarcrm\custom\ class asked
    // for and, in the 1.2.0 run, loads it by ERP-Core's rule
    // (erp_test_class_file(), over the roots the stand-in registered), so what
    // was LOADED is recorded too. In the other two runs no 1.2.0 is installed
    // and the stand-in is never required - its roots would be 1.2.0's - so the
    // lookup misses the way it does on a tenant whose ERP-Epicor predates T2.
    $GLOBALS['bd_autoload_asked'] = [];
    $GLOBALS['bd_autoloaded'] = [];
    spl_autoload_register(function ($class) use ($nsFacts) {
        $prefix = 'Sugarcrm\\Sugarcrm\\custom\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $GLOBALS['bd_autoload_asked'][] = $class;
        $file = $nsFacts ? erp_test_class_file($class) : null;
        if ($file !== null) {
            $GLOBALS['bd_autoloaded'][] = $class;
            require_once $file;
        }
    }, true, true);
    // The class the suite's own direct calls name (A9): the one this run installs.
    $factsClass = $nsFacts ? $NS_FACTS : 'ErpQuoteFacts';

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
            // A SugarQuery's from() template (ERP_LookupValues; G809: Quotes, the
            // account-history read) is not a record; anything else the rules
            // instantiate would be.
            if ($module !== 'ERP_LookupValues' && $module !== 'Quotes') {
                self::$created++;
            }
            return new BdTestBean(['module_name' => $module]);
        }
    }

    class BdTestWhere
    {
        public $equals = [];
        public $notEmpty = [];
        public $notEquals = [];

        public function equals($field, $value)
        {
            $this->equals[$field] = $value;
            return $this;
        }

        public function isNotEmpty($field)
        {
            $this->notEmpty[] = $field;
            return $this;
        }

        public function notEquals($field, $value)
        {
            $this->notEquals[$field] = $value;
            return $this;
        }
    }

    class SugarQuery
    {
        public static $rows = [];
        public static $last = null;
        /** G809: every query built, in order (the Quotes history read is not the last one). */
        public static $all = [];
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
            self::$all[] = $this;
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
            // G809: a row belongs to one module ('_module', default the lookup
            // table every earlier section reads), as a real table does.
            $module = is_object($this->from) ? (string) ($this->from->module_name ?? '') : '';
            $out = [];
            foreach (self::$rows as $row) {
                if (($row['_module'] ?? 'ERP_LookupValues') !== $module) {
                    continue;
                }
                foreach ($this->whereObj->equals as $f => $v) {
                    if ((string) ($row[$f] ?? '') !== (string) $v) {
                        continue 2;
                    }
                }
                foreach ($this->whereObj->notEmpty as $f) {
                    if (trim((string) ($row[$f] ?? '')) === '') {
                        continue 2;
                    }
                }
                foreach ($this->whereObj->notEquals as $f => $v) {
                    if ((string) ($row[$f] ?? '') === (string) $v) {
                        continue 2;
                    }
                }
                $out[] = $row;
            }
            [$by, $dir] = $this->order ?: ['name', 'ASC'];
            usort($out, fn($a, $b) => (strtoupper($dir) === 'DESC' ? -1 : 1)
                * strcmp((string) ($a[$by] ?? ''), (string) ($b[$by] ?? '')));
            return $this->limit === null ? $out : array_slice($out, 0, $this->limit);
        }
    }

    // ERP-Epicor's REAL ErpQuoteFacts, loaded before BdAdmRules exactly as a
    // tenant has it (QuotesErpActionsApi loads it first; BdAdmRules's own
    // guarded include then finds the class declared).
    // G530: ERP-Core's erp_reference vardef, the one that states the limit. The
    // sibling checkout's when BD_ERP_REFERENCE names it, else the pin.
    $referenceVardef = getenv('BD_ERP_REFERENCE') ?: (__DIR__ . '/fixtures/shared-sugar/sugar-sell/'
        . 'ERP-Core/src/custom/Extension/modules/Quotes/Ext/Vardefs/erp_reference.php');

    $factsFile = getenv('BD_QUOTE_FACTS');
    if ($nsFacts) {
        // Asked of the stand-in's rule without loading anything: T1 needs the
        // class NOT loaded until BdAdmRules asks for it.
        if (erp_test_class_file($NS_FACTS) === null) {
            fwrite(STDERR, "BD_ERP_AUTOLOADER's roots hold no Erp/ErpQuoteFacts.php\n");
            exit(2);
        }
    } elseif (!$noFacts) {
        if (!is_string($factsFile) || !is_file($factsFile)) {
            fwrite(STDERR, "BD_QUOTE_FACTS must name ERP-Epicor 1.1's global ErpQuoteFacts.php\n");
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
        SugarQuery::$all = [];
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
        $check('O4 T2: neither class: the namespaced lookup was ASKED of the autoloader and found nothing',
            [true, false], [in_array($NS_FACTS, $GLOBALS['bd_autoload_asked'], true), class_exists($NS_FACTS, false)]);
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
        $bdTypes = ['BdLeadSources', 'BdLeadTypes', 'BdProjects', 'BdMarketingCampaigns', 'BdMarketingEvents',
            'BdCustomerGroups'];
        $check('G2 the six Bench types are added (G460: the two marketing lists; G804: customer groups)', $bdTypes,
            array_values(array_intersect($bdTypes, array_keys($types))));
        $check('G3 the shipped project default is in place', ['CMI' => '20065'],
            $GLOBALS['app_list_strings']['bd_adm_project_by_group_list']);
        $check('G4 there is NO company list any more (🔒 1724b: one source for "ADM")', false,
            isset($GLOBALS['app_list_strings']['bd_adm_companies_list']));

        // ── H. applyDefaults ────────────────────────────────────────────────
        // T2: nothing above touched ErpQuoteFacts, so whatever loads the
        // namespaced class from here on is BdAdmRules asking for it.
        $nsLoadedBeforeRules = class_exists($NS_FACTS, false);
        SugarQuery::$rows = $ADM_ROWS;
        $createdBefore = BeanFactory::$created;
        $freshQuery();
        $fresh = $quote('ADM', ['shipping_address_city' => 'WAYNE', 'shipping_address_state' => 'NJ',
                                'lines' => [$lCmi, $lCmi2]]);
        $set = BdAdmRules::applyDefaults($fresh);
        // T2: what the autoloader had loaded once BdAdmRules alone had run (A9
        // below names the class itself, so a later read would prove nothing).
        $autoloadedByRules = array_values(array_unique($GLOBALS['bd_autoloaded']));
        $nsAskedByRules = count(array_keys($GLOBALS['bd_autoload_asked'], $NS_FACTS, true));
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
            BdAdmRules::defaultProject([$factsClass::productGroup($lCmi)]));

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
        // K8 pinned "the before_save hook never names either field" until G809:
        // the owner asked for defaults (quote 8972), so the pair is now COPIED
        // from the account's newest quote (section Q). What still holds is the
        // G460 rule: nothing is ever INVENTED - with no history both stay empty.
        SugarQuery::$rows = array_merge($ADM_ROWS, $K_CAMPS, $K_EVENTS);
        $freshQuery();
        $noHistory = $quote('ADM', ['id' => 'q-k8', 'lines' => []]);
        BdAdmRules::applyDefaults($noHistory, true);
        $check('K8 G460/G809 NO invented default: an account with no quotes leaves Campaign and Event empty',
            [null, null], [$noHistory->bd_marketing_campaign ?? null, $noHistory->bd_marketing_event ?? null]);

        // ── Q. G809: defaults from the account's newest quote ─────────────
        // Owner, quote 8972: "maybe we should put defaults". The pilot measured
        // consecutive quotes of one customer repeating Lead Source 93 %, Lead
        // Type 95 %, Campaign 74 %. The account's quotes (acct-adm) are rows of
        // module Quotes; a quote of ANOTHER account (acct-epic) must never count.
        $Q_LOOKUPS = [
            ['type' => 'BdLeadSources', 'is_active' => 1, 'erp_sync_key' => 'ADM__BdLeadSources_E-MAIL',
             'erp_display_sync_key' => 'E-MAIL', 'name' => 'Email'],
            ['type' => 'BdLeadSources', 'is_active' => 1, 'erp_sync_key' => 'ADM__BdLeadSources_ADVERTISE',
             'erp_display_sync_key' => 'ADVERTISE', 'name' => 'ADVERTISE'],
            ['type' => 'BdLeadSources', 'is_active' => 0, 'erp_sync_key' => 'ADM__BdLeadSources_LOYPROG',
             'erp_display_sync_key' => 'LOYPROG', 'name' => 'Retired'],
            ['type' => 'BdLeadTypes', 'is_active' => 1, 'erp_sync_key' => 'ADM__BdLeadTypes_BROKER',
             'erp_display_sync_key' => 'BROKER', 'name' => 'Broker'],
            ['type' => 'BdLeadTypes', 'is_active' => 1, 'erp_sync_key' => 'ADM__BdLeadTypes_DIRFOOD',
             'erp_display_sync_key' => 'DIRFOOD', 'name' => 'Direct food'],
            // G809 retest (benchdogs-dev, 2026-09-30): the measured codes of the
            // graded account, a retired 2024 campaign whose event row is still
            // active (ADM keeps events of an inactive campaign; the picker
            // offers neither), and ADM projects, one retired.
            ['type' => 'BdLeadSources', 'is_active' => 1, 'erp_sync_key' => 'ADM__BdLeadSources_BIDINVTE',
             'erp_display_sync_key' => 'BIDINVTE', 'name' => 'Bid invite'],
            ['type' => 'BdLeadTypes', 'is_active' => 1, 'erp_sync_key' => 'ADM__BdLeadTypes_CASEGDS',
             'erp_display_sync_key' => 'CASEGDS', 'name' => 'Casegoods'],
            ['type' => 'BdMarketingCampaigns', 'is_active' => 0, 'erp_display_sync_key' => '24CGINST', 'name' => '2024 CG INST'],
            ['type' => 'BdMarketingEvents', 'is_active' => 1, 'erp_display_sync_key' => '24CGINST/1', 'name' => 'EXISTING CUST'],
            ['type' => 'BdProjects', 'is_active' => 1, 'erp_display_sync_key' => '20065', 'name' => 'CMI program'],
            ['type' => 'BdProjects', 'is_active' => 1, 'erp_display_sync_key' => '17879', 'name' => 'LGH EXPANSION'],
            ['type' => 'BdProjects', 'is_active' => 0, 'erp_display_sync_key' => '11111', 'name' => 'Closed project'],
        ];
        $qRow = fn(string $id, string $account, string $entered, array $f) =>
            ['_module' => 'Quotes', 'id' => $id, 'billing_account_id' => $account, 'date_entered' => $entered] + $f;
        $HISTORY = [
            // oldest first; the NEWEST holding each field wins
            $qRow('h-1', 'acct-adm', '2026-01-05 10:00:00', ['bd_lead_source' => 'ADVERTISE', 'bd_lead_type' => 'DIRFOOD',
                'bd_marketing_campaign' => '26BREHC', 'bd_marketing_event' => '26BREHC/4']),
            $qRow('h-2', 'acct-adm', '2026-03-10 10:00:00', ['bd_lead_source' => 'E-MAIL', 'bd_lead_type' => '',
                'bd_marketing_campaign' => '26DISCNV', 'bd_marketing_event' => '26DISCNV/2']),
            $qRow('h-3', 'acct-adm', '2026-05-20 10:00:00', ['bd_lead_source' => '', 'bd_lead_type' => 'BROKER',
                'bd_marketing_campaign' => '26DISCNV', 'bd_marketing_event' => '']),
            // another account's NEWER quote: never read for acct-adm
            $qRow('o-1', 'acct-epic', '2026-09-01 10:00:00', ['bd_lead_source' => 'ADVERTISE', 'bd_lead_type' => 'DIRFOOD',
                'bd_marketing_campaign' => '26BREHC', 'bd_marketing_event' => '26BREHC/4']),
        ];
        $withHistory = function (array $history) use ($ADM_ROWS, $Q_LOOKUPS, $K_CAMPS, $K_EVENTS, $freshQuery) {
            SugarQuery::$rows = array_merge($ADM_ROWS, $Q_LOOKUPS, $K_CAMPS, $K_EVENTS, $history);
            $freshQuery();
        };
        $picks = fn($q) => [$q->bd_lead_source ?? null, $q->bd_lead_type ?? null,
            $q->bd_marketing_campaign ?? null, $q->bd_marketing_event ?? null];
        // The Quotes reads (the account-history lookups), in order.
        $quoteReads = fn() => array_values(array_filter(SugarQuery::$all,
            fn($q) => is_object($q->from) && ($q->from->module_name ?? '') === 'Quotes'));

        $withHistory($HISTORY);
        $new = $quote('ADM', ['id' => 'q-new', 'shipping_address_city' => 'LENEXA', 'shipping_address_state' => 'KS',
                              'lines' => []]);
        $set = BdAdmRules::applyDefaults($new, true);
        $check('Q1 a NEW ADM quote: each empty pick from the account\'s NEWEST quote holding it; the pair from '
            . 'the newest holding BOTH', [['E-MAIL', 'BROKER', '26DISCNV', '26DISCNV/2'],
            ['erp_reference', 'bd_lead_source', 'bd_lead_type', 'bd_marketing_campaign', 'bd_marketing_event']],
            [$picks($new), $set]);
        $reads = $quoteReads();
        $pairRead = end($reads);
        $check('Q2 four reads (Lead Source, Lead Type, Project, the pair): this account\'s quotes, the field(s) not '
            . 'empty, newest first, up to 20 rows (the first usable wins, Q15), never this quote',
            [4, [['bd_lead_source'], ['bd_lead_type'], ['bd_project_id'], ['bd_marketing_campaign', 'bd_marketing_event']],
             ['billing_account_id' => 'acct-adm'], ['id' => $new->id], ['date_entered', 'DESC'], 20],
            [count($reads), array_map(fn($q) => $q->whereObj->notEmpty, $reads), $pairRead->whereObj->equals,
             $pairRead->whereObj->notEquals, $pairRead->order, $pairRead->limit]);

        $withHistory(array_merge($HISTORY, [$qRow('q-ADM', 'acct-adm', '2026-09-29 12:05:15',
            ['bd_lead_source' => 'ADVERTISE', 'bd_lead_type' => 'DIRFOOD'])]));
        $itself = $quote('ADM', ['lines' => []]);   // id q-ADM: its own row is the newest
        BdAdmRules::applyDefaults($itself, true);
        $check('Q2b a quote is never its own history (its own newer row is skipped)', ['E-MAIL', 'BROKER'],
            [$itself->bd_lead_source ?? null, $itself->bd_lead_type ?? null]);

        $withHistory($HISTORY);
        $update = $quote('ADM', ['id' => 'q-upd', 'lines' => []]);
        BdAdmRules::applyDefaults($update);
        $check('Q3 an UPDATE (not a create) copies nothing from history', [null, null, null, null], $picks($update));

        $withHistory($HISTORY);
        $typed = $quote('ADM', ['id' => 'q-typed', 'bd_lead_source' => 'ADVERTISE', 'bd_lead_type' => 'DIRFOOD',
                                'bd_marketing_campaign' => '26BREHC', 'bd_marketing_event' => '26BREHC/4', 'lines' => []]);
        BdAdmRules::applyDefaults($typed, true);
        $check('Q4 the seller\'s picks are never overwritten', ['ADVERTISE', 'DIRFOOD', '26BREHC', '26BREHC/4'],
            $picks($typed));
        $withHistory($HISTORY);
        $partly = $quote('ADM', ['id' => 'q-part', 'bd_lead_source' => 'ADVERTISE', 'lines' => []]);
        BdAdmRules::applyDefaults($partly, true);
        $check('Q4b one pick typed, the rest empty: the typed one stays, only the empty ones are filled',
            ['ADVERTISE', 'BROKER', '26DISCNV', '26DISCNV/2'], $picks($partly));

        $withHistory([$qRow('r-1', 'acct-adm', '2026-06-01 10:00:00', ['bd_lead_source' => 'LOYPROG',
            'bd_lead_type' => 'GONE', 'bd_marketing_campaign' => '25DIRCNV', 'bd_marketing_event' => '25DIRCNV/1'])]);
        $retired = $quote('ADM', ['id' => 'q-ret', 'lines' => []]);
        BdAdmRules::applyDefaults($retired, true);
        $check('Q5 a value the picker no longer offers (inactive code, retired campaign/event) is NOT copied',
            [null, null, null, null], $picks($retired));

        $withHistory($HISTORY);
        $sent = $quote('ADM', ['id' => 'q-sent', 'erp_display_sync_key' => '8719', 'lines' => []]);
        $check('Q6 a quote already in the ERP: nothing copied, NOTHING read (exit 1)', [[], [null, null, null, null], 0, []],
            [BdAdmRules::applyDefaults($sent, true), $picks($sent), SugarQuery::$constructed, BeanFactory::$reads]);

        $withHistory($HISTORY);
        $epic = $quote('EPIC06', ['id' => 'q-epic', 'lines' => []]);
        BdAdmRules::applyDefaults($epic, true);
        $check('Q7 CONTROL an EPIC06 quote copies nothing (its account has a newer quote with values)',
            [null, null, null, null], $picks($epic));

        $withHistory([$qRow('m-1', 'acct-adm', '2026-06-01 10:00:00', ['bd_marketing_campaign' => '26DISCNV',
            'bd_marketing_event' => '26BREHC/4'])]);
        $mismatch = $quote('ADM', ['id' => 'q-mis', 'lines' => []]);
        BdAdmRules::applyDefaults($mismatch, true);
        $check('Q8 a quote whose event is NOT its campaign\'s (quote #4\'s mismatch) gives no pair', [null, null],
            [$mismatch->bd_marketing_campaign ?? null, $mismatch->bd_marketing_event ?? null]);

        $withHistory($HISTORY);
        $other = $quote('ADM', ['id' => 'q-oth', 'bd_marketing_campaign' => '26BREHC', 'lines' => []]);
        BdAdmRules::applyDefaults($other, true);
        $same = $quote('ADM', ['id' => 'q-same', 'bd_marketing_campaign' => '26DISCNV', 'lines' => []]);
        BdAdmRules::applyDefaults($same, true);
        $eventOnly = $quote('ADM', ['id' => 'q-ev', 'bd_marketing_event' => '26DISCNV/1', 'lines' => []]);
        BdAdmRules::applyDefaults($eventOnly, true);
        // Q9's first case read ONE row until the G809 retest (the newest pair,
        // 26DISCNV, was not the seller's, so nothing). Now a quote with another
        // campaign is skipped like any unusable one, and the newest quote holding
        // the SELLER's campaign (h-1) gives its own event: still one quote's pair.
        $check('Q9 the seller chose ANOTHER campaign: the event of the newest quote holding THAT campaign; the SAME '
            . 'campaign: its event; an event alone: no campaign derived',
            [['26BREHC', '26BREHC/4'], ['26DISCNV', '26DISCNV/2'], [null, '26DISCNV/1']],
            [[$other->bd_marketing_campaign, $other->bd_marketing_event ?? null],
             [$same->bd_marketing_campaign, $same->bd_marketing_event ?? null],
             [$eventOnly->bd_marketing_campaign ?? null, $eventOnly->bd_marketing_event]]);

        $check('Q10 pairToCopy, the pure rule', [['26DISCNV', '26DISCNV/2'], [], [], [], ['26DISCNV', '26DISCNV/2']],
            [BdAdmRules::pairToCopy('', '26DISCNV', '26DISCNV/2'), BdAdmRules::pairToCopy('', '26DISC', '26DISCNV/2'),
             BdAdmRules::pairToCopy('26BREHC', '26DISCNV', '26DISCNV/2'), BdAdmRules::pairToCopy('', '', ''),
             BdAdmRules::pairToCopy(' 26DISCNV ', '26DISCNV', '26DISCNV/2')]);

        $withHistory($HISTORY);
        $noAccount = new BdTestBean(['id' => 'q-noacct', 'lines' => []]);
        $check('Q11 a quote with no account reads no quote and copies nothing', [[], []],
            [BdAdmRules::applyDefaults($noAccount, true), $quoteReads()]);

        // The hook decides "create" from Sugar's own before_save argument.
        $viaHook = function ($arguments) use ($withHistory, $HISTORY, $quote, $picks) {
            $withHistory($HISTORY);
            $q = $quote('ADM', ['id' => 'q-hook', 'lines' => []]);
            (new BdAdmRules())->beforeSave($q, 'before_save', $arguments);
            return $picks($q)[0];
        };
        $check('Q12 before_save: isUpdate=false fills; isUpdate=true and a missing argument do not',
            ['E-MAIL', null, null],
            [$viaHook(['isUpdate' => false]), $viaHook(['isUpdate' => true]), $viaHook([])]);
        $check('Q13 🔒 1499: the history defaults create no record', 0, BeanFactory::$created - $createdBefore);

        // ── Q14-Q18. G809 retest (Bench rc80 + ERP-Epicor 1.1.174, benchdogs-dev,
        // 2026-09-30T00:26Z): Lead Source / Lead Type prefilled, Mktg Campaign and
        // Marketing Event EMPTY although older quotes of the account hold a pair.
        // Each field already came from the newest quote holding IT (Q1, Q16b);
        // what failed is that ONE row was read per field, so a retired value on
        // the newest holder (a 2024 campaign) hid every older, usable one. Now
        // the newest HISTORY_SCAN holders are read and the first usable wins -
        // the same rule, and the same number, as ERP-Core's create form.
        $picks5 = fn($q) => [$q->bd_lead_source ?? null, $q->bd_lead_type ?? null, $q->bd_project_id ?? null,
            $q->bd_marketing_campaign ?? null, $q->bd_marketing_event ?? null];
        $check('Q14 HISTORY_SCAN is 20, the number ERP-Core\'s erp-dependent-enum reads (erpPrefillScan): one rule, '
            . 'two writers', 20, defined('BdAdmRules::HISTORY_SCAN') ? constant('BdAdmRules::HISTORY_SCAN') : null);

        $withHistory([
            $qRow('r-new', 'acct-adm', '2026-09-01 10:00:00', ['bd_lead_source' => 'LOYPROG', 'bd_lead_type' => 'GONE',
                'bd_project_id' => '11111', 'bd_marketing_campaign' => '24CGINST', 'bd_marketing_event' => '24CGINST/1']),
            $qRow('r-old', 'acct-adm', '2026-04-01 10:00:00', ['bd_lead_source' => 'E-MAIL', 'bd_lead_type' => 'BROKER',
                'bd_project_id' => '17879', 'bd_marketing_campaign' => '26DISCNV', 'bd_marketing_event' => '26DISCNV/2']),
            $qRow('r-oldest', 'acct-adm', '2026-01-01 10:00:00', ['bd_lead_source' => 'ADVERTISE', 'bd_lead_type' => 'DIRFOOD',
                'bd_project_id' => '20065', 'bd_marketing_campaign' => '26BREHC', 'bd_marketing_event' => '26BREHC/4']),
        ]);
        $skip = $quote('ADM', ['id' => 'q-skip', 'lines' => []]);
        BdAdmRules::applyDefaults($skip, true);
        $check('Q15 the NEWEST quote holds only retired codes (LOYPROG, GONE, project 11111, 24CGINST/1): each field '
            . 'from the next newest quote holding an OFFERED one; the pair from that ONE quote',
            ['E-MAIL', 'BROKER', '17879', '26DISCNV', '26DISCNV/2'], $picks5($skip));

        // THE MEASURED SHAPE: 7647 (newest, Sugar-only) holds Lead Source and
        // Lead Type but no pair; ADM__7178 holds 24CGINST / 24CGINST/1.
        $dev = fn(array $older) => array_merge([
            $qRow('q-7647', 'acct-adm', '2026-09-29 17:20:00', ['bd_lead_source' => 'BIDINVTE', 'bd_lead_type' => 'CASEGDS']),
        ], $older);
        $withHistory($dev([
            $qRow('q-7178', 'acct-adm', '2026-09-29 04:00:00', ['bd_lead_source' => 'E-MAIL', 'bd_lead_type' => 'BROKER',
                'bd_marketing_campaign' => '24CGINST', 'bd_marketing_event' => '24CGINST/1']),
            $qRow('q-6120', 'acct-adm', '2026-09-29 03:00:00', ['bd_lead_source' => 'E-MAIL', 'bd_lead_type' => 'BROKER',
                'bd_marketing_campaign' => '26DISCNV', 'bd_marketing_event' => '26DISCNV/2']),
        ]));
        $measured = $quote('ADM', ['id' => 'q-dev', 'lines' => []]);
        BdAdmRules::applyDefaults($measured, true);
        $check('Q16 measured shape, the newest pair RETIRED: Lead Source/Type from 7647, the pair from the newest '
            . 'quote holding an OFFERED pair', ['BIDINVTE', 'CASEGDS', null, '26DISCNV', '26DISCNV/2'], $picks5($measured));
        $withHistory($dev([
            $qRow('q-7178', 'acct-adm', '2026-09-29 04:00:00', ['bd_lead_source' => 'E-MAIL', 'bd_lead_type' => 'BROKER',
                'bd_marketing_campaign' => '26BREHC', 'bd_marketing_event' => '26BREHC/4']),
        ]));
        $literal = $quote('ADM', ['id' => 'q-dev2', 'lines' => []]);
        BdAdmRules::applyDefaults($literal, true);
        $check('Q16b measured shape, the older pair OFFERED: all four (each field from the newest quote holding IT - '
            . 'true before this fix too)', ['BIDINVTE', 'CASEGDS', null, '26BREHC', '26BREHC/4'], $picks5($literal));

        $withHistory([
            $qRow('p-1', 'acct-adm', '2026-09-01 10:00:00', ['bd_marketing_campaign' => '26DISCNV', 'bd_marketing_event' => '26BREHC/4']),
            $qRow('p-2', 'acct-adm', '2026-08-01 10:00:00', ['bd_marketing_campaign' => '26DISCNV', 'bd_marketing_event' => '26DISCNV/1']),
            $qRow('p-3', 'acct-adm', '2026-07-01 10:00:00', ['bd_marketing_campaign' => '26BREHC', 'bd_marketing_event' => '26BREHC/4']),
        ]);
        $mixed = $quote('ADM', ['id' => 'q-mixed', 'lines' => []]);
        BdAdmRules::applyDefaults($mixed, true);
        $chosen = $quote('ADM', ['id' => 'q-chosen', 'bd_marketing_campaign' => '26BREHC', 'lines' => []]);
        BdAdmRules::applyDefaults($chosen, true);
        $check('Q17 the pair: a newest quote whose event is not its campaign\'s is skipped (never mixed); with the '
            . 'seller\'s campaign chosen, the event of the newest quote holding THAT campaign',
            [['26DISCNV', '26DISCNV/1'], ['26BREHC', '26BREHC/4']],
            [[$mixed->bd_marketing_campaign ?? null, $mixed->bd_marketing_event ?? null],
             [$chosen->bd_marketing_campaign ?? null, $chosen->bd_marketing_event ?? null]]);

        // G809, owner scope (2026-09-29T21:05Z): Project prefills from the
        // account's history like the other four. 🔒 1712b's product-group
        // default is applied FIRST (it fills only an empty Project, as before);
        // the history fills only what that left empty. Both only into EMPTY.
        $projectHistory = [
            $qRow('j-new', 'acct-adm', '2026-09-01 10:00:00', ['bd_project_id' => '11111']),
            $qRow('j-old', 'acct-adm', '2026-06-01 10:00:00', ['bd_project_id' => '17879']),
        ];
        $withHistory($projectHistory);
        $pNew = $quote('ADM', ['id' => 'q-pnew', 'lines' => []]);
        $pCmi = $quote('ADM', ['id' => 'q-pcmi', 'lines' => [$lCmi]]);
        $pTyped = $quote('ADM', ['id' => 'q-ptyped', 'bd_project_id' => '20065', 'lines' => []]);
        $pUpd = $quote('ADM', ['id' => 'q-pupd', 'lines' => []]);
        BdAdmRules::applyDefaults($pNew, true);
        BdAdmRules::applyDefaults($pCmi, true);
        BdAdmRules::applyDefaults($pTyped, true);
        BdAdmRules::applyDefaults($pUpd);
        $check('Q18 Project: a new quote gets the newest OFFERED project of the account (retired 11111 skipped); a '
            . 'CMI quote keeps 🔒 1712b\'s 20065; the seller\'s pick stays; an update copies nothing',
            ['17879', '20065', '20065', null],
            [$pNew->bd_project_id ?? null, $pCmi->bd_project_id ?? null, $pTyped->bd_project_id ?? null,
             $pUpd->bd_project_id ?? null]);

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
        // G809: all five carry ERP-Core's erp-dependent-enum (it reads the
        // required-until-synced and prefill keys); before G809 only Project and Event did.
        $check('M4 each carries ERP-Epicor\'s marker: record view, ERP panel, after Reference in order', [
                'bd_lead_source' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'erp_reference',
                    'type' => 'erp-dependent-enum'],
                'bd_lead_type' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'bd_lead_source',
                    'type' => 'erp-dependent-enum'],
                'bd_project_id' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'bd_lead_type',
                    'type' => 'erp-dependent-enum'],
                'bd_marketing_campaign' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'bd_project_id',
                    'type' => 'erp-dependent-enum'],
                'bd_marketing_event' => ['view' => 'record', 'panel' => 'LBL_RECORDVIEW_PANEL_ERP', 'after' => 'bd_marketing_campaign',
                    'type' => 'erp-dependent-enum'],
            ], array_map(fn($f) => $f['erp_layout'] ?? null, $fields));
        $mod_strings = [];
        include 'custom/Extension/modules/Quotes/Ext/Language/en_us.bd_adm_required_fields.php';
        $missing = [];
        $fieldLabels = [];
        foreach ($fields as $f) {
            if (!isset($mod_strings[$f['vname']])) {
                $missing[] = $f['vname'];
            }
            $fieldLabels[$f['vname']] = $mod_strings[$f['vname']] ?? null;
        }
        // G571: the one label that is not a field's - the Event picker's
        // placeholder while no campaign is chosen.
        $hint = $fields['bd_marketing_event']['erp_lookup_parent_empty_label'] ?? '';
        $check('M5 every field has its label, the Event hint has its text, and no orphan label is left',
            [[], true, 6], [$missing, isset($mod_strings[$hint]), count($mod_strings)]);
        // G578: "Marketing Campaign" was cut to "Marketing Ca..." on the Quotes ERP
        // panel (benchdogs-dev, 2026-09-25) while "Marketing Event" showed in full.
        $check('M5b G460/G578 the labels are ADM\'s words, the campaign one short enough to show',
            ['Mktg Campaign', 'Marketing Event'],
            [$mod_strings['LBL_BD_MARKETING_CAMPAIGN'] ?? null, $mod_strings['LBL_BD_MARKETING_EVENT'] ?? null]);
        $tooLong = array_filter($fieldLabels, fn($text) => mb_strlen((string) $text, 'UTF-8') > mb_strlen('Marketing Event', 'UTF-8'));
        $check('M5c G578 no Bench quote FIELD label is longer than "Marketing Event", the longest measured to show in full',
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
        // G571 / G570: ERP-Core's erp-dependent-enum reads these keys; the
        // client code is ERP-Core's (lane D19), this package only declares them.
        $depKeys = ['erp_lookup_parent', 'erp_lookup_parent_separator', 'erp_lookup_parent_empty_label',
            'erp_required_when_options'];
        $check('M8 G571 the Event follows the Campaign, split at the ext\'s separator; G570 Project is required '
            . 'when ADM projects exist; no other picker carries a key, and none a custom_type',
            ['bd_lead_source' => [], 'bd_lead_type' => [], 'bd_project_id' => ['erp_required_when_options' => true],
             'bd_marketing_campaign' => [],
             'bd_marketing_event' => ['erp_lookup_parent' => 'bd_marketing_campaign',
                 'erp_lookup_parent_separator' => BdAdmRules::EVENT_KEY_SEPARATOR,
                 'erp_lookup_parent_empty_label' => 'LBL_BD_MARKETING_EVENT_PICK_CAMPAIGN'],
             'custom_type' => []],
            array_map(fn($f) => array_intersect_key($f, array_flip($depKeys)), $fields)
                + ['custom_type' => array_keys(array_filter($fields, fn($f) => isset($f['custom_type'])))]);
        $dictionary = [];
        include 'custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php';
        // rc72 (G507): panel_overview after Industry - the first tab of the measured
        // Bench tenant, which has no panel_body (rc69 put them in its HEADER).
        $check('M6 the two Account fields: panel_overview after Industry, name then code', [
                'bd_customer_group' => ['view' => 'record', 'panel' => 'panel_overview', 'after' => 'industry'],
                'bd_customer_group_code' => ['view' => 'record', 'panel' => 'panel_overview', 'after' => 'bd_customer_group'],
            ], array_map(fn($f) => $f['erp_layout'] ?? null, $dictionary['Account']['fields'] ?? []));

        // G809: the four seller pickers are required in the browser only until
        // the quote is in the ERP, and three of them prefill on the create form
        // (the Event fills the Campaign + Event pair). None carries a vardef
        // 'required' (M2): the served flag is what the connector's schema reads.
        $g809Keys = ['erp_required_until_synced', 'erp_prefill_from_account_latest'];
        $check('M9 G809 the ERP-Core keys: required-until-synced on Lead Source, Lead Type, Campaign, Event (not '
            . 'Project, which keeps G570\'s rule); prefill from billing_account_id on Lead Source, Lead Type, Project '
            . '(owner scope, 2026-09-29) and the Event',
            ['bd_lead_source' => ['erp_required_until_synced' => true, 'erp_prefill_from_account_latest' => 'billing_account_id'],
             'bd_lead_type' => ['erp_required_until_synced' => true, 'erp_prefill_from_account_latest' => 'billing_account_id'],
             'bd_project_id' => ['erp_prefill_from_account_latest' => 'billing_account_id'],
             'bd_marketing_campaign' => ['erp_required_until_synced' => true],
             'bd_marketing_event' => ['erp_required_until_synced' => true, 'erp_prefill_from_account_latest' => 'billing_account_id']],
            array_map(fn($f) => array_intersect_key($f, array_flip($g809Keys)), $fields));
        $check('M10 G809 no picker combines the two required keys (erp_required_when_options would block ERP quotes)',
            [], array_keys(array_filter($fields, fn($f) => !empty($f['erp_required_when_options'])
                && !empty($f['erp_required_until_synced']))));

        // ── S. G809: the Reference requirement is a VIEW dependency ─────────
        $dependencies = [];
        include 'custom/Extension/modules/Quotes/Ext/Dependencies/bd_adm_reference_required.php';
        $dep = $dependencies['Quotes']['bd_adm_reference_required'] ?? [];
        $action = $dep['actions'][0] ?? [];
        $formula = (string) ($action['params']['value'] ?? '');
        preg_match_all('/\$([a-z_]+)/', $formula, $named);
        $check('S1 one SetRequired on erp_reference, for the EDIT views only (never a server save: no "save"/"all" hook)',
            [['edit'], 'SetRequired', 'erp_reference', true, 1],
            [$dep['hooks'] ?? null, $action['name'] ?? null, $action['params']['target'] ?? null, $dep['onload'] ?? null,
             count($dep['actions'] ?? [])]);
        $check('S2 the formula reads exactly: not in the ERP, an ADM Lead Source, no ship-to city, no ship-to state; '
            . 'and those are its trigger fields',
            [['bd_lead_source', 'erp_display_sync_key', 'shipping_address_city', 'shipping_address_state'],
             ['bd_lead_source', 'erp_display_sync_key', 'shipping_address_city', 'shipping_address_state']],
            [array_values(array_unique(array_merge([], (function ($a) { sort($a); return $a; })($named[1])))),
             (function ($a) { sort($a); return $a; })($dep['triggerFields'] ?? [])]);

        // ── R. G804: the Account's Cust. Group ──────────────────────────────
        $dictionary = [];
        include 'custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php';
        $code = $dictionary['Account']['fields']['bd_customer_group_code'] ?? [];
        $name = $dictionary['Account']['fields']['bd_customer_group'] ?? [];
        $check('R1 the Group Code is ADM\'s picker: an enum over bd_adm_customer_group_options, never pre-picked, '
            . 'same column length, not required',
            ['enum', 'bd_adm_customer_group_options', true, true, 10, false],
            [$code['type'] ?? null, $code['function']['name'] ?? null, is_file($code['function']['include'] ?? ''),
             $code['defaultToBlank'] ?? null, $code['len'] ?? null, !empty($code['required'])]);
        $check('R2 read-only once the account holds EITHER ERP key (formula), editable before; the NAME stays read-only',
            [true, 'not(and(equal($erp_display_sync_key,""),equal($erp_sync_key,"")))', true, false],
            [$code['readonly'] ?? null, $code['readonly_formula'] ?? null, $name['readonly'] ?? null,
             isset($name['readonly_formula'])]);
        $GROUP_ROWS = [
            ['type' => 'BdCustomerGroups', 'is_active' => 1, 'erp_sync_key' => 'ADM__BdCustomerGroups_BRKR',
             'erp_display_sync_key' => 'BRKR', 'name' => 'Broker'],
            ['type' => 'BdCustomerGroups', 'is_active' => 1, 'erp_sync_key' => 'ADM__BdCustomerGroups_AUTO',
             'erp_display_sync_key' => 'AUTO', 'name' => 'Automotive'],
            ['type' => 'BdCustomerGroups', 'is_active' => 0, 'erp_sync_key' => 'ADM__BdCustomerGroups_OLD',
             'erp_display_sync_key' => 'OLD', 'name' => 'Retired group'],
        ];
        SugarQuery::$rows = array_merge($ADM_ROWS, $GROUP_ROWS);
        $check('R3 the options: ADM\'s ACTIVE groups, "CODE - Name", blank first',
            ['' => '', 'AUTO' => 'AUTO - Automotive', 'BRKR' => 'BRKR - Broker'], bd_adm_customer_group_options());
        $acct = fn(array $f) => new BdTestBean(['id' => 'acct-new'] + $f);
        $freshQuery();
        $picked = $acct(['bd_customer_group_code' => 'BRKR']);
        $check('R4 a NEW account with a picked group gets ADM\'s name for it',
            [true, 'Broker'], [BdAdmRules::applyCustomerGroupName($picked, false), $picked->bd_customer_group ?? null]);
        $unknown = $acct(['bd_customer_group_code' => 'ZZZ']);
        BdAdmRules::applyCustomerGroupName($unknown, false);
        $check('R5 a code ADM\'s list does not know: the code itself, never an invented name', 'ZZZ',
            $unknown->bd_customer_group);
        $freshQuery();
        $erpAcct = $acct(['erp_sync_key' => 'ADM__1668', 'erp_display_sync_key' => '1668',
                          'bd_customer_group_code' => 'BRKR', 'bd_customer_group' => 'Brokers (ERP)',
                          'fetched_row' => ['bd_customer_group_code' => 'AUTO']]);
        $check('R6 CONTROL an account the ERP holds: untouched, nothing read (the extension writes both)',
            [false, 'Brokers (ERP)', 0], [BdAdmRules::applyCustomerGroupName($erpAcct, true), $erpAcct->bd_customer_group,
             SugarQuery::$constructed]);
        $freshQuery();
        $same = $acct(['bd_customer_group_code' => 'BRKR', 'bd_customer_group' => 'Broker',
                       'fetched_row' => ['bd_customer_group_code' => 'BRKR']]);
        $check('R7 an update that does not change the code reads nothing', [false, 0],
            [BdAdmRules::applyCustomerGroupName($same, true), SugarQuery::$constructed]);
        $changed = $acct(['bd_customer_group_code' => 'AUTO', 'bd_customer_group' => 'Broker',
                          'fetched_row' => ['bd_customer_group_code' => 'BRKR']]);
        $cleared = $acct(['bd_customer_group_code' => '', 'bd_customer_group' => 'Broker',
                          'fetched_row' => ['bd_customer_group_code' => 'BRKR']]);
        BdAdmRules::applyCustomerGroupName($changed, true);
        BdAdmRules::applyCustomerGroupName($cleared, true);
        $check('R8 an update that changes the code follows it; one that clears it clears the name',
            ['Automotive', ''], [$changed->bd_customer_group, $cleared->bd_customer_group]);
        $freshQuery();
        $none = $acct(['bd_customer_group' => 'Legacy']);
        $check('R9 a new account with no code: nothing touched, nothing read', [false, 'Legacy', 0],
            [BdAdmRules::applyCustomerGroupName($none, false), $none->bd_customer_group, SugarQuery::$constructed]);
        $viaAccountHook = function ($arguments) use ($acct) {
            $a = $acct(['bd_customer_group_code' => 'AUTO', 'fetched_row' => ['bd_customer_group_code' => 'AUTO']]);
            (new BdAdmRules())->accountBeforeSave($a, 'before_save', $arguments);
            return $a->bd_customer_group ?? null;
        };
        $check('R10 the hook reads Sugar\'s isUpdate: a create names the group; an unchanged update does not',
            ['Automotive', null], [$viaAccountHook(['isUpdate' => false]), $viaAccountHook(['isUpdate' => true])]);
        $hook_array = [];
        include 'custom/Extension/modules/Accounts/Ext/LogicHooks/bd_customer_group_name.php';
        $ah = $hook_array['before_save'][0] ?? [];
        $check('R11 the Accounts before_save points at an existing file, class and method', [true, true],
            [is_file($ah[2] ?? ''), method_exists($ah[3] ?? '', $ah[4] ?? '')]);

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

        // ── T. T2: ERP-Epicor's ErpQuoteFacts, namespaced and autoloaded ────
        if ($nsFacts) {
            $check('T1 the namespaced class was NOT loaded before the rules asked (only an autoloading '
                . 'lookup can find it)', false, $nsLoadedBeforeRules);
            $check('T2 BdAdmRules itself loaded it through the autoloader, by H1 (before this suite names it)',
                [$NS_FACTS], $autoloadedByRules);
            $check('T3 the old global name was never declared in this run', false,
                class_exists('ErpQuoteFacts', false));

            // An upgraded tenant between T2's install and the leftovers one-off:
            // the OLD global file still sits at the literal path, beside the
            // namespaced class. A stale stand-in (a sentinel, and no
            // referenceMaxLength) is put there; the control run, without the
            // namespaced class, proves the literal include really reaches it.
            $leftoverRoot = sys_get_temp_dir() . '/bd-t2-leftover-' . getmypid();
            @mkdir($leftoverRoot . '/custom/modules/Quotes', 0777, true);
            $leftoverFile = $leftoverRoot . '/custom/modules/Quotes/ErpQuoteFacts.php';
            file_put_contents($leftoverFile, '<?php $GLOBALS["bd_stale_included"] = true;'
                . ' class ErpQuoteFacts { public static function companyCode($q) { return "STALE"; } }');
            $probeFile = $leftoverRoot . '/probe.php';
            file_put_contents($probeFile, <<<'PROBE'
<?php
[, $pkg, $erpAutoloader, $leftoverRoot] = $argv;
if ($erpAutoloader !== '') {
    require $erpAutoloader;
}
class L { public $e = []; function error($m) { $this->e[] = $m; } }
$GLOBALS['log'] = new L();
set_include_path($leftoverRoot . PATH_SEPARATOR . get_include_path());
chdir($pkg);
require 'custom/modules/Quotes/BdAdmRules.php';
$available = BdAdmRules::quoteFactsAvailable();
$q = new stdClass();
$q->id = 'q-leftover';
$q->field_defs = ['erp_reference' => ['erp_max_length' => 10]];
echo json_encode([$available, isset($GLOBALS['bd_stale_included']),
    class_exists('Sugarcrm\\Sugarcrm\\custom\\Erp\\ErpQuoteFacts', false),
    BdAdmRules::referenceMaxLength($q), count($GLOBALS['log']->e)]);
PROBE);
            $runProbe = fn(string $root) => json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($probeFile) . ' ' . escapeshellarg($pkg) . ' ' . escapeshellarg($root) . ' '
                . escapeshellarg($leftoverRoot) . ' 2>&1'), true);
            $check('T4 CONTROL an old global file alone at the literal path IS included and answers '
                . '(it has no referenceMaxLength: limit 0, logged)', [true, true, false, 0, 1], $runProbe(''));
            $check('T5 beside the namespaced class the leftover is NEVER included; the namespaced class '
                . 'answers (the field\'s limit, 10; nothing logged)', [true, false, true, 10, 0],
                $runProbe($erpAutoloader));
            @unlink($probeFile);
            @unlink($leftoverFile);
            @rmdir($leftoverRoot . '/custom/modules/Quotes');
            @rmdir($leftoverRoot . '/custom/modules');
            @rmdir($leftoverRoot . '/custom');
            @rmdir($leftoverRoot);
        } else {
            $check('T0 only the global class installed: the namespaced lookup was asked and missed, and the '
                . 'global class answered H1-H4', [true, false, true],
                [in_array($NS_FACTS, $GLOBALS['bd_autoload_asked'], true), class_exists($NS_FACTS, false),
                 class_exists('ErpQuoteFacts', false)]);
            // A miss is a file-system lookup on every call (PHP remembers no
            // negative autoload), so it is made once per save, never per line:
            // H1's quote has two lines and a Reference to cut.
            $check('T0b and that lookup is made ONCE per save, not once per line or per fact', 1, $nsAskedByRules);
        }
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
