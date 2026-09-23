<?php

/**
 * G380 / G381 (🔒 1705b): the Bench Dogs ADM rules, Sugar half, EXECUTED.
 *
 * Run:  BD_ERP_QUOTE_HOOKS=<path to ERP-Epicor's ErpQuoteHooks.php> \
 *       php scripts/tests/bd_adm_rules_test.php
 *       (scripts/tests/test_php_suites.py sets it: the sibling checkout's file
 *       when present, else the pinned copy under fixtures/shared-sugar/)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * What is proved, each against the real shipped file:
 *   A. the company gate: ADM yes, EPIC06 / blank / unknown no; the list is data
 *   B. Reference defaults to "CITY STATE"
 *   C. the part number is resolved exactly as ERP-Epicor sends it
 *   D. a line with no part number is refused, by name, on ADM only
 *   E. the Project default: unanimous groups only; the list is tenant data
 *   F. an admin's Dropdown Editor list survives a package reinstall, either order
 *   G. erp_lookup_type_list is extended key by key, never replaced
 *   H. applyDefaults: fills EMPTY fields on unsent ADM quotes, nothing else
 *   I. the pickers' options: active rows of one type, "CODE - Name", '' first
 *   J. the three option functions answer the same list whatever Sugar passes
 *   K. the two hook adapters, run THROUGH ERP-Epicor's real dispatcher
 *   L. the record-view placement: ERP panel, idempotent, undone on uninstall
 *   M. the vardefs: four fields, no 'required', functions that exist
 *   N. the before_save registration points at a real class and method
 *
 * The data mirrors measured ADM facts (🔒 1710b): part CMI-211967V3K is group
 * CMI, part 49000450 is group DISPLAYS, CMI's dominant project is 20065.
 */

namespace Sugarcrm\Sugarcrm\MetaData {
    class ViewdefManager
    {
        public static $loaded = null;
        public static $saved = null;
        public static $saveCount = 0;

        public function loadViewdef($client, $module, $view)
        {
            return self::$loaded;
        }

        public function saveViewdef($defs, $module, $client, $view): void
        {
            self::$saved = $defs;
            self::$saveCount++;
        }
    }
}

namespace Sugarcrm\Sugarcrm\Util\Files {
    class FileLoader
    {
        public static function validateFilePath($path)
        {
            return $path;
        }
    }
}

namespace {
    use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

    class MetaDataFiles
    {
        public static function clearModuleClientCache($module, $type): void
        {
        }
    }

    class TemplateHandler
    {
        public static function clearCache($module): void
        {
        }
    }

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
        public static $beans = [];
        public static $created = 0;

        public static function retrieveBean($module, $id)
        {
            return self::$beans[$module][$id] ?? null;
        }

        public static function newBean($module)
        {
            self::$created++;
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
        public $from = null;
        public $fromOptions = [];
        public $select = [];
        public $whereObj;
        public $order = [];

        public function __construct()
        {
            $this->whereObj = new BdTestWhere();
            self::$last = $this;
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
            usort($out, fn($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
            return $out;
        }
    }

    function return_app_list_strings_language($lang)
    {
        return $GLOBALS['app_list_strings'];
    }

    // include_once 'include/TemplateHandler/TemplateHandler.php' needs a real
    // file on the include path for the run to be warning-free.
    $tmp = sys_get_temp_dir() . '/bd_adm_rules_test_' . getmypid();
    @mkdir($tmp . '/include/TemplateHandler', 0777, true);
    file_put_contents($tmp . '/include/TemplateHandler/TemplateHandler.php', "<?php\n");
    set_include_path($tmp . PATH_SEPARATOR . get_include_path());

    // The package root is the tenant's docroot as far as these files know:
    // every path they name ('custom/modules/Quotes/...') is relative to it,
    // exactly as ERP-Epicor's file_exists() probes are on a tenant.
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
    require 'custom/modules/Quotes/BdAdmQuoteFieldsLayout.php';

    // ── fixtures ────────────────────────────────────────────────────────────
    $b = fn(array $f) => new BdTestBean($f);
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
            // no company link: falls back to its key's scope
            'acct-adm-nolink' => $b(['id' => 'acct-adm-nolink', 'erp_sync_key' => 'ADM__25392']),
            'acct-none' => $b(['id' => 'acct-none', 'erp_sync_key' => '']),
        ],
        'ProductCategories' => [
            'cat-cmi' => $b(['id' => 'cat-cmi', 'erp_display_sync_key' => 'CMI']),
            'cat-disp' => $b(['id' => 'cat-disp', 'erp_display_sync_key' => 'DISPLAYS']),
        ],
        'ProductTemplates' => [
            'pt-cmi' => $b(['id' => 'pt-cmi', 'erp_display_sync_key' => 'CMI-211967V3K', 'category_id' => 'cat-cmi']),
            'pt-cmi2' => $b(['id' => 'pt-cmi2', 'erp_display_sync_key' => 'CMI-100', 'category_id' => 'cat-cmi']),
            'pt-49' => $b(['id' => 'pt-49', 'erp_display_sync_key' => '49000450', 'category_id' => 'cat-disp']),
            // the measured fixture: a seeded catalog part whose number never reached Sugar (G382)
            'pt-pallet' => $b(['id' => 'pt-pallet', 'erp_display_sync_key' => '', 'category_id' => 'cat-disp']),
            'pt-nogroup' => $b(['id' => 'pt-nogroup', 'erp_display_sync_key' => 'BR-1', 'category_id' => '']),
        ],
    ];
    $line = function (string $id, array $f) use ($b) {
        $bean = $b(['id' => $id] + $f);
        BeanFactory::$beans['Products'][$id] = $bean;
        return $bean;
    };
    $lCmi = $line('l-cmi', ['name' => 'Floor display', 'product_template_id' => 'pt-cmi']);
    $lCmi2 = $line('l-cmi2', ['name' => 'Header card', 'product_template_id' => 'pt-cmi2']);
    $l49 = $line('l-49', ['name' => 'Pallet display', 'product_template_id' => 'pt-49']);
    $lPallet = $line('l-pallet', ['name' => 'PALLET', 'product_template_id' => 'pt-pallet',
                                  // a stale free-text number on a CATALOG line: core ignores it, so must we
                                  'mft_part_num' => '49000450']);
    $lFree = $line('l-free', ['name' => 'Freight', 'product_template_id' => '', 'mft_part_num' => 'FRT-1']);
    $lFreeBlank = $line('l-free-blank', ['name' => 'Misc', 'product_template_id' => '', 'mft_part_num' => ' ']);
    $lNoGroup = $line('l-nogroup', ['name' => 'Bracket', 'product_template_id' => 'pt-nogroup']);

    $quote = function (string $account, array $f = []) use ($b) {
        return $b(['id' => 'q-' . $account, 'billing_account_id' => $account] + $f);
    };

    // ── A. which quotes the rules cover ─────────────────────────────────────
    $check('A1 an ADM account quote is ADM', 'ADM', BdAdmRules::companyOf($quote('acct-adm')));
    $check('A2 an EPIC06 account quote is EPIC06', 'EPIC06', BdAdmRules::companyOf($quote('acct-epic')));
    $check('A3 no company link: the key scope answers', 'ADM', BdAdmRules::companyOf($quote('acct-adm-nolink')));
    $check('A4 no account: no company', '', BdAdmRules::companyOf($quote('')));
    $check('A5 the rules cover ADM', true, BdAdmRules::appliesTo('ADM'));
    $check('A6 case and padding do not matter', true, BdAdmRules::appliesTo(' adm '));
    $check('A7 the rules do not cover EPIC06', false, BdAdmRules::appliesTo('EPIC06'));
    $check('A8 nor a blank company', false, BdAdmRules::appliesTo(''));
    $GLOBALS['app_list_strings']['bd_adm_companies_list'] = ['ADM2' => 'ADM2'];
    $check('A9 the company list is data: an edited list moves the gate', [false, true],
        [BdAdmRules::appliesTo('ADM'), BdAdmRules::appliesTo('ADM2')]);
    $GLOBALS['app_list_strings']['bd_adm_companies_list'] = ['ADM' => 'ADM'];

    // ── B. Reference ────────────────────────────────────────────────────────
    $check('B1 city and state', 'WAYNE NJ', BdAdmRules::defaultReference('WAYNE', 'NJ'));
    $check('B2 padding is trimmed', 'WAYNE NJ', BdAdmRules::defaultReference(' WAYNE ', ' NJ '));
    $check('B3 city alone', 'WAYNE', BdAdmRules::defaultReference('WAYNE', ''));
    $check('B4 nothing: nothing invented', '', BdAdmRules::defaultReference(' ', ''));

    // ── C. the part number, as ERP-Epicor sends it ──────────────────────────
    $check('C1 a catalog line sends the template part number', 'CMI-211967V3K', BdAdmRules::partNumberOf($lCmi));
    $check('C2 a catalog line whose template has none sends none, whatever mft_part_num says',
        '', BdAdmRules::partNumberOf($lPallet));
    $check('C3 a free-text line sends its own mft_part_num', 'FRT-1', BdAdmRules::partNumberOf($lFree));
    $check('C4 a blank one is blank', '', BdAdmRules::partNumberOf($lFreeBlank));
    $check('C5 the group comes from the part\'s catalog category', ['CMI', 'DISPLAYS', ''],
        [BdAdmRules::productGroupOf($lCmi), BdAdmRules::productGroupOf($l49), BdAdmRules::productGroupOf($lFree)]);

    // ── D. the non-part block ───────────────────────────────────────────────
    $adm = $quote('acct-adm');
    $epic = $quote('acct-epic');
    $refusal = BdAdmRules::nonPartRefusal($adm, ['l-cmi', 'l-pallet']);
    $check('D1 an ADM order with a part-less line is refused', true, is_string($refusal));
    $check('D2 naming the line', true, str_contains((string) $refusal, '"PALLET" has no ERP part number'));
    $check('D3 and saying nothing was sent', true, str_contains((string) $refusal, 'Nothing was sent to the ERP'));
    $check('D4 an ADM order of real parts passes', null, BdAdmRules::nonPartRefusal($adm, ['l-cmi', 'l-49', 'l-free']));
    $check('D5 the same part-less line on EPIC06 passes (control)', null,
        BdAdmRules::nonPartRefusal($epic, ['l-cmi', 'l-pallet']));
    $two = (string) BdAdmRules::nonPartRefusal($adm, ['l-pallet', 'l-free-blank']);
    $check('D6 two part-less lines are both named, plural', true,
        str_contains($two, '"PALLET" and "Misc" have no ERP part number'));
    $check('D7 an unknown line id is not judged', null, BdAdmRules::nonPartRefusal($adm, ['l-gone']));

    // ── E. the Project default ──────────────────────────────────────────────
    $check('E1 CMI -> 20065 (the shipped starting entry)', '20065', BdAdmRules::defaultProject(['CMI']));
    $check('E2 every line CMI: still 20065', '20065', BdAdmRules::defaultProject(['CMI', 'cmi']));
    $check('E3 CMI mixed with DISPLAYS (no dominant project): the seller picks', '',
        BdAdmRules::defaultProject(['CMI', 'DISPLAYS']));
    $check('E4 a line with no group: the seller picks', '', BdAdmRules::defaultProject(['CMI', '']));
    $check('E5 no lines: nothing', '', BdAdmRules::defaultProject([]));
    $GLOBALS['app_list_strings']['bd_adm_project_by_group_list'] = ['CMI' => '20065', 'STL' => '20065', 'RET' => '30001'];
    $check('E6 two groups on ONE project pre-fill it', '20065', BdAdmRules::defaultProject(['CMI', 'STL']));
    $check('E7 two groups on two projects do not', '', BdAdmRules::defaultProject(['CMI', 'RET']));

    // ── F. the admin's list survives a reinstall, in either merge order ────
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

    // ── G. core's lookup-type list is extended, never replaced ─────────────
    $GLOBALS['app_list_strings'] = ['erp_lookup_type_list' => ['' => '', 'Country' => 'Country', 'Reason' => 'Reason']];
    $loadLists();
    $types = $GLOBALS['app_list_strings']['erp_lookup_type_list'];
    $check('G1 core\'s types survive', ['Country', 'Reason'],
        array_values(array_intersect(['Country', 'Reason'], array_keys($types))));
    $check('G2 the three Bench types are added', ['BdLeadSources', 'BdLeadTypes', 'BdProjects'],
        array_values(array_intersect(['BdLeadSources', 'BdLeadTypes', 'BdProjects'], array_keys($types))));
    $check('G3 and the shipped defaults are in place', ['ADM' => 'ADM'], $GLOBALS['app_list_strings']['bd_adm_companies_list']);

    // ── H. applyDefaults ────────────────────────────────────────────────────
    $createdBefore = BeanFactory::$created;
    $fresh = $quote('acct-adm', ['shipping_address_city' => 'WAYNE', 'shipping_address_state' => 'NJ',
                                 'lines' => [$lCmi, $lCmi2]]);
    (new BdAdmRules())->beforeSave($quote('acct-adm', ['lines' => [$lCmi]]), 'before_save', []);
    $set = BdAdmRules::applyDefaults($fresh);
    $check('H1 an unsent ADM quote gets Reference and Project', ['bd_reference', 'bd_project_id'], $set);
    $check('H2 Reference is the ship-to city and state', 'WAYNE NJ', $fresh->bd_reference);
    $check('H3 Project is the default for its all-CMI lines', '20065', $fresh->bd_project_id);
    $typed = $quote('acct-adm', ['shipping_address_city' => 'WAYNE', 'shipping_address_state' => 'NJ',
                                 'bd_reference' => 'SHOW BOOTH 4', 'bd_project_id' => '22008', 'lines' => [$lCmi]]);
    $check('H4 what the seller typed is never overwritten', [[], 'SHOW BOOTH 4', '22008'],
        [BdAdmRules::applyDefaults($typed), $typed->bd_reference, $typed->bd_project_id]);
    $sent = $quote('acct-adm', ['erp_display_sync_key' => '8761', 'shipping_address_city' => 'WAYNE',
                                'shipping_address_state' => 'NJ', 'lines' => [$lCmi]]);
    $check('H5 a quote already in the ERP is left alone', [], BdAdmRules::applyDefaults($sent));
    $epicFresh = $quote('acct-epic', ['shipping_address_city' => 'AUSTIN', 'shipping_address_state' => 'TX',
                                      'lines' => [$lCmi]]);
    $check('H6 an EPIC06 quote is left alone (control)', [[], null],
        [BdAdmRules::applyDefaults($epicFresh), $epicFresh->bd_reference ?? null]);
    $mixed = $quote('acct-adm', ['shipping_address_city' => 'WAYNE', 'lines' => [$lCmi, $l49]]);
    $check('H7 CMI + DISPLAYS lines: Reference only, the seller picks the Project', ['bd_reference'],
        BdAdmRules::applyDefaults($mixed));
    $noLines = $quote('acct-adm', ['shipping_address_city' => 'WAYNE', 'shipping_address_state' => 'NJ']);
    $check('H8 a quote with no lines yet: Reference only', ['bd_reference'], BdAdmRules::applyDefaults($noLines));
    $check('H9 🔒 1499: the before_save hook creates no record, ever', 0, BeanFactory::$created - $createdBefore);
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
    $check('H10 a defaults failure never fails the seller\'s save (MLP004)', null, $threw);
    $check('H11 and it is logged, naming the quote', true,
        (bool) array_filter($GLOBALS['log']->lines, fn($l) => $l[0] === 'error'
            && str_contains($l[1], 'q-broken') && str_contains($l[1], 'link table unreadable')));

    // ── I. the pickers' options ─────────────────────────────────────────────
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

    // ── J. the option functions ignore whatever Sugar passes ───────────────
    $legacy = [['BD_LEAD_SOURCE' => 'X'], 'bd_lead_source', 'X', 'ListView'];
    $check('J1 lead source: same list bare and with the legacy signature',
        bd_adm_lead_source_options(), bd_adm_lead_source_options(...$legacy));
    $check('J2 lead source reads its own type', BdAdmRules::lookupOptions('BdLeadSources'), bd_adm_lead_source_options());
    $check('J3 lead type reads its own type', BdAdmRules::lookupOptions('BdLeadTypes'), bd_adm_lead_type_options('BdProjects'));
    $check('J4 project reads its own type', BdAdmRules::lookupOptions('BdProjects'), bd_adm_project_options());

    // ── K. the hook adapters, through ERP-Epicor's REAL dispatcher ─────────
    $hooks = getenv('BD_ERP_QUOTE_HOOKS');
    $check('K0 the ERP-Epicor dispatcher is available to this run', true, is_string($hooks) && is_file($hooks));
    if (is_string($hooks) && is_file($hooks)) {
        require $hooks;
        $r = ErpQuoteHooks::fireResolveOrderableLines($adm, ['l-cmi', 'l-pallet']);
        $check('K1 Submit Order with PALLET on ADM: refused by the hook, not a contract violation',
            ['hook_refused', []], [$r['status'], $r['line_ids']]);
        $check('K2 the seller reads the Bench sentence verbatim', $refusal, $r['refuse']['message'] ?? null);
        $r = ErpQuoteHooks::fireResolveOrderableLines($adm, ['l-49', 'l-cmi']);
        $check('K3 real parts on ADM: every candidate, core\'s order', ['hook_resolved', ['l-49', 'l-cmi']],
            [$r['status'], $r['line_ids']]);
        $r = ErpQuoteHooks::fireResolveOrderableLines($epic, ['l-cmi', 'l-pallet']);
        $check('K4 EPIC06 with PALLET: the candidates unchanged (control)', ['hook_resolved', ['l-cmi', 'l-pallet']],
            [$r['status'], $r['line_ids']]);
        $r = ErpQuoteHooks::fireResolveOrderableLines($epic, []);
        $check('K5 no candidates: a seller refusal, never the administrator message',
            ['hook_refused', 'No lines to order'], [$r['status'], $r['refuse']['error'] ?? null]);
        $check('K6 Order Selected Lines with PALLET on ADM: refused', $refusal,
            ErpQuoteHooks::fireLineOrderRefusal($adm, ['l-cmi', 'l-pallet']));
        $check('K7 Order Selected Lines of real parts on ADM: proceeds', null,
            ErpQuoteHooks::fireLineOrderRefusal($adm, ['l-cmi']));
        $check('K8 Order Selected Lines with PALLET on EPIC06: proceeds (control)', null,
            ErpQuoteHooks::fireLineOrderRefusal($epic, ['l-pallet']));
    }

    // ── L. the record-view placement ────────────────────────────────────────
    $erpPanel = ['name' => 'LBL_RECORDVIEW_PANEL_ERP', 'fields' => [['name' => 'erp_quote_type'],
                                                                   ['name' => 'erp_quotes_ship_via_name']]];
    $body = ['name' => 'panel_body', 'fields' => [['name' => 'name']]];
    $reset = function (?array $panels) {
        ViewdefManager::$loaded = $panels === null ? null : ['panels' => $panels];
        ViewdefManager::$saved = null;
        ViewdefManager::$saveCount = 0;
    };
    $names = fn(array $panel) => array_map(fn($f) => is_array($f) ? $f['name'] : $f, $panel['fields']);
    $reset([$body, $erpPanel]);
    $added = BdAdmQuoteFieldsLayout::place();
    $check('L1 all four are added, in the order a seller fills them',
        ['bd_lead_source', 'bd_lead_type', 'bd_reference', 'bd_project_id'], $added);
    $check('L2 to the ERP panel, after what was there',
        ['erp_quote_type', 'erp_quotes_ship_via_name', 'bd_lead_source', 'bd_lead_type', 'bd_reference', 'bd_project_id'],
        $names(ViewdefManager::$saved['panels'][1]));
    $check('L3 and nothing else moves', ['name'], $names(ViewdefManager::$saved['panels'][0]));
    $reset(ViewdefManager::$saved['panels']);
    $check('L4 a second install adds nothing and writes nothing', [[], 0],
        [BdAdmQuoteFieldsLayout::place(), ViewdefManager::$saveCount]);
    $reset([['name' => 'panel_body', 'fields' => [['name' => 'name'], ['name' => 'bd_reference']]], $erpPanel]);
    $check('L5 one an admin already placed is not added again',
        ['bd_lead_source', 'bd_lead_type', 'bd_project_id'], BdAdmQuoteFieldsLayout::place());
    $reset([['name' => 'panel_hidden', 'fields' => [['name' => 'x']]], $body]);
    BdAdmQuoteFieldsLayout::place();
    $check('L6 no ERP panel: the stock panel_body', ['name', 'bd_lead_source', 'bd_lead_type', 'bd_reference', 'bd_project_id'],
        $names(ViewdefManager::$saved['panels'][1]));
    $reset(null);
    $check('L7 an unreadable view is never written', [[], 0], [BdAdmQuoteFieldsLayout::place(), ViewdefManager::$saveCount]);
    $reset([$body, ['name' => 'LBL_RECORDVIEW_PANEL_ERP', 'fields' => array_merge($erpPanel['fields'],
        [['name' => 'bd_lead_source'], ['name' => 'bd_reference']])], ['name' => 'p3', 'fields' => ['bd_project_id', 'y']]]);
    $check('L8 uninstall takes all of them off every panel, and only them', 3, BdAdmQuoteFieldsLayout::remove());
    $check('L9 leaving the rest exactly', [['name'], ['erp_quote_type', 'erp_quotes_ship_via_name'], ['y']],
        array_map($names, ViewdefManager::$saved['panels']));

    // ── M. the vardefs ──────────────────────────────────────────────────────
    $dictionary = [];
    include 'custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php';
    $fields = $dictionary['Quote']['fields'] ?? [];
    $check('M1 exactly the four fields', ['bd_lead_source', 'bd_lead_type', 'bd_project_id', 'bd_reference'],
        (function ($k) { sort($k); return $k; })(array_keys($fields)));
    $check('M2 none is required (Ophir/EPIC06 would be unable to save a quote)', [],
        array_keys(array_filter($fields, fn($f) => !empty($f['required']))));
    $fnOk = [];
    foreach (['bd_lead_source', 'bd_lead_type', 'bd_project_id'] as $f) {
        $fn = $fields[$f]['function'] ?? [];
        $fnOk[$f] = is_file($fn['include'] ?? '') && function_exists($fn['name'] ?? '') && empty($fields[$f]['options']);
    }
    $check('M3 each picker names a function that exists, in a file that ships',
        ['bd_lead_source' => true, 'bd_lead_type' => true, 'bd_project_id' => true], $fnOk);
    $mod_strings = [];
    include 'custom/Extension/modules/Quotes/Ext/Language/en_us.bd_adm_required_fields.php';
    $missing = [];
    foreach ($fields as $f) {
        if (!isset($mod_strings[$f['vname']])) {
            $missing[] = $f['vname'];
        }
    }
    $check('M4 every field has its label', [], $missing);

    // ── N. the before_save registration ─────────────────────────────────────
    $hook_array = [];
    include 'custom/Extension/modules/Quotes/Ext/LogicHooks/bd_adm_quote_defaults.php';
    $h = $hook_array['before_save'][0] ?? [];
    $check('N1 before_save -> an existing file, class and instance method', [true, true],
        [is_file($h[2] ?? ''), method_exists($h[3] ?? '', $h[4] ?? '')]);

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
