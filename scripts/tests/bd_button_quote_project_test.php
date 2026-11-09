<?php

/**
 * A quote made on the Account by ERP-Epicor's "Create Opportunity & Quote" button, on an ADM account. rc110 gave it the
 * account's newest quote's Project; since rc117 the Project is each quote line's own, so the quote gets no quote-level
 * Project (section P), and it starts with Needs Price Estimate on: this package's install sets ERP-Epicor's own
 * per-tenant default once, and ERP-Epicor's real button reads it (section N). The create form and every later save keep
 * what the seller sends. rc121: the button's Opportunity and Quote are the account owner's, not the presser's, through
 * this package's before_save on both modules (section O); the quote PDF's Sales Person is the quote's owner.
 *
 * Run:  BD_ERP_AUTOLOADER=<ERP-Core/tests/support/sugar_autoloader.php> \
 *       BD_ACCOUNT_ACTIONS=<ERP-Epicor/src/custom/clients/base/api/AccountsErpActionsApi.php> \
 *       php scripts/tests/bd_button_quote_project_test.php
 *       (scripts/tests/test_php_suites.py runs it with the sibling checkout's files, else the pins under
 *       fixtures/shared-sugar/)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * What runs for real: the button's server half, ERP-Epicor's AccountsErpActionsApi::createOppQuote(), with
 * ERP-Epicor's ErpQuoteFacts; this package's shipped Quotes before_save registration and its BdAdmRules.
 *
 * What is faked is Sugar itself, in the order SugarEnt 26.1.0 has it:
 *   - SugarBean::save(): a bean with no id is a create (isUpdate(), data/SugarBean.php:1849), gets its id with
 *     new_with_id (ensureHasId(), :1944) and the before_save hooks are called with isUpdate false (:2252); the
 *     insert clears new_with_id (:2290), so every later save is an update. fixUpFormatting() and cleanBean() leave
 *     a field that was never set unset (:1806, fixUpFormatting's isset skip).
 *   - the create form's request, ModuleApi::createBean() (clients/base/api/ModuleApi.php:383): the id first, then
 *     only the fields the request carries (SugarBeanApiHelper::populateFromApi(), data/SugarBeanApiHelper.php:293,
 *     skips one that is absent; SugarFieldBase::apiSave() writes '' as ''). What Sugar's own create form sends for a
 *     cleared Project is proven on its real create view in project_required_on_real_sidecar.cjs.
 *   - the record view's save, ModuleApi::updateRecord(): the stored record, with the request's fields over it.
 *
 * IF IT WERE BROKEN: the button's quote has no Project although the account's newest quote holds one the picker
 * offers (what a seller met: Submit Order refused for the missing Project), or a later save refills a Project the
 * seller cleared, or the create form's cleared Project comes back, or a non-ADM quote is given one.
 */

namespace {
    use Sugarcrm\Sugarcrm\custom\BenchDogs\BdAdmRules;

    $erpAutoloader = (string) getenv('BD_ERP_AUTOLOADER');
    $accountActions = (string) getenv('BD_ACCOUNT_ACTIONS');
    if ($erpAutoloader === '' || $accountActions === '' || !is_file($accountActions)) {
        fwrite(STDERR, "set BD_ERP_AUTOLOADER and BD_ACCOUNT_ACTIONS (ERP-Epicor 1.2.x)\n");
        exit(2);
    }
    // ERP-Core's stand-in for SugarAutoLoader: ErpQuoteFacts is found the way Sugar finds it.
    require $erpAutoloader;

    class SugarApi
    {
    }

    // The button's own base class does nothing createOppQuote() uses.
    class BaseErpActionsApi extends SugarApi
    {
    }

    class ServiceBase
    {
        public $user;
        public $action = '';
    }

    class SugarApiExceptionInvalidParameter extends \Exception
    {
    }

    class SugarApiExceptionNotAuthorized extends \Exception
    {
    }

    class SugarApiExceptionNotFound extends \Exception
    {
    }

    class SugarCurrency
    {
        public static function getCurrencyByID($id)
        {
            return null;
        }
    }

    class TimeDate
    {
        public static function getInstance()
        {
            return new self();
        }

        public function getNow($userTime = false)
        {
            return new \DateTime('2026-10-21 05:00:00');
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
        public $added = [];

        public function __construct(array $beans = [])
        {
            $this->beans = $beans;
        }

        public function getBeans($params = [])
        {
            return $this->beans;
        }

        public function get()
        {
            return array_map(fn($b) => $b->id, $this->beans);
        }

        public function add($bean, $params = [])
        {
            $this->added[] = $bean;
            return true;
        }
    }

    /** Sugar's record store, its save() and its before_save dispatch, as SugarEnt 26.1.0 orders them. */
    class BdTestSugar
    {
        /** Stored records: module => id => fields. */
        public static $records = [];
        /** Rows SugarQuery reads that are not records saved here (lookup values). */
        public static $lookupRows = [];
        /** before_save entries per module, from this package's shipped registration. */
        public static $hooks = [];
        /** Every save: [module, id, isUpdate the hooks were given]. */
        public static $saves = [];
        public static $clock = 0;
        public static $seq = 0;

        public static function save(SugarBean $bean): void
        {
            $isUpdate = $bean->isUpdate();
            if (!$isUpdate && empty($bean->id)) {
                $bean->id = strtolower($bean->module_name) . '-new-' . (++self::$seq);
                $bean->new_with_id = true;
            }
            if (!$isUpdate) {
                $bean->date_entered = sprintf('2026-10-21 05:%02d:00', ++self::$clock);
            }
            $isUpdate = $bean->isUpdate();
            foreach (self::$hooks[$bean->module_name] ?? [] as $entry) {
                $class = $entry[3];
                $method = $entry[4];
                (new $class())->$method($bean, 'before_save', ['check_notify' => false, 'isUpdate' => $isUpdate]);
            }
            self::$saves[] = [$bean->module_name, $bean->id, $isUpdate];
            $fields = [];
            foreach (get_object_vars($bean) as $name => $value) {
                if (is_scalar($value) || $value === null) {
                    $fields[$name] = $value;
                }
            }
            self::$records[$bean->module_name][$bean->id] = $fields;
            $bean->new_with_id = false;
        }

        /** A stored record as a fresh bean, as retrieve() builds one: every stored field set, new_with_id false. */
        public static function retrieve(string $module, string $id): ?SugarBean
        {
            $fields = self::$records[$module][$id] ?? null;
            if ($fields === null) {
                return null;
            }
            $bean = BeanFactory::newBean($module);
            foreach ($fields as $name => $value) {
                $bean->$name = $value;
            }
            $bean->new_with_id = false;

            return $bean;
        }

        /** The rows SugarQuery sees for a module: the lookup rows, and every stored Quote. */
        public static function rows(string $module): array
        {
            $out = [];
            foreach (self::$lookupRows as $row) {
                if (($row['_module'] ?? 'ERP_LookupValues') === $module) {
                    $out[] = $row;
                }
            }
            foreach (self::$records[$module] ?? [] as $fields) {
                $out[] = $fields;
            }

            return $out;
        }
    }

    #[AllowDynamicProperties]
    class SugarBean
    {
        public $id = '';
        public $new_with_id = false;
        public $deleted = 0;
        public $module_name = '';

        public function __construct(string $module = '', array $fields = [])
        {
            $this->module_name = $module;
            foreach ($fields as $k => $v) {
                $this->$k = $v;
            }
        }

        public function isUpdate(): bool
        {
            return !(empty($this->id) || !empty($this->new_with_id));
        }

        public function save($check_notify = false)
        {
            BdTestSugar::save($this);
        }

        public function load_relationship($name)
        {
            return isset($this->$name) && is_object($this->$name);
        }

        public function ACLAccess($action, $context = null)
        {
            return true;
        }
    }

    class BeanFactory
    {
        /** Accounts and ERP companies by id: what the button and ErpQuoteFacts read. */
        public static $beans = [];

        public static function newBean($module)
        {
            $bean = new SugarBean($module);
            if ($module === 'Quotes') {
                $bean->billing_accounts = new BdTestLink();
                $bean->opportunities = new BdTestLink();
                $bean->product_bundles = new BdTestLink();
            } elseif ($module === 'Opportunities') {
                $bean->accounts = new BdTestLink();
            } elseif ($module === 'ProductBundles') {
                $bean->products = new BdTestLink();
            }

            return $bean;
        }

        public static function retrieveBean($module, $id = null, $params = [])
        {
            return self::$beans[$module][(string) $id] ?? BdTestSugar::retrieve((string) $module, (string) $id);
        }

        public static function getBean($module, $id = null, $params = [])
        {
            return new BdTestAdmin();
        }
    }

    /** Sugar's Administration over the config table: category => key => value, platform 'base'. */
    class BdTestAdmin
    {
        public static $config = [];
        public static $writes = [];

        public function getConfigForModule($category, $platform = 'base', $clean = false)
        {
            return self::$config[$category] ?? [];
        }

        public function saveSetting($category, $key, $value, $platform = '')
        {
            self::$writes[] = [$category, $key, $value, $platform];
            self::$config[$category][$key] = $value;
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
        /** Every query built, in order. */
        public static $all = [];
        public $from = null;
        public $select = [];
        public $whereObj;
        public $order = [];
        public $limit = null;

        public function __construct()
        {
            $this->whereObj = new BdTestWhere();
            self::$all[] = $this;
        }

        public function from($bean, $options = [])
        {
            $this->from = $bean;
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
            $module = is_object($this->from) ? (string) $this->from->module_name : '';
            $out = [];
            foreach (BdTestSugar::rows($module) as $row) {
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

    function return_app_list_strings_language($lang)
    {
        return $GLOBALS['app_list_strings'];
    }
    $GLOBALS['app_list_strings'] = [
        'sales_stage_dom' => ['Prospecting' => 'Prospecting'],
        'quote_stage_dom' => ['Draft' => 'Draft'],
        // ERP-Core's list (custom/Extension/modules/Quotes/Ext/Language/en_us.erp_quote_type.php).
        'erp_quote_type_list' => ['sales_order' => 'Sales Order', 'advanced_quote' => 'Needs Price Estimate'],
    ];

    // The package root is the tenant's docroot as far as its files know.
    $pkg = realpath(__DIR__ . '/../../sugar-sell/BenchDogs-Ext');
    chdir($pkg);
    require 'custom/src/BenchDogs/BdAdmRules.php';
    if (is_file('custom/src/BenchDogs/BdQuoteTypeDefault.php')) {
        require 'custom/src/BenchDogs/BdQuoteTypeDefault.php';
    }
    require $accountActions;

    $hook_array = [];
    include 'custom/Extension/modules/Quotes/Ext/LogicHooks/bd_adm_quote_defaults.php';
    BdTestSugar::$hooks['Quotes'] = $hook_array['before_save'] ?? [];
    // rc121: the account owner rule, on both records the button saves (its class reads users through BdDefaultOwner).
    require 'custom/src/BenchDogs/BdDefaultOwner.php';
    if (is_file('custom/src/BenchDogs/BdAccountOwner.php')) {
        require 'custom/src/BenchDogs/BdAccountOwner.php';
    }
    foreach (['Opportunities', 'Quotes'] as $hooked) {
        $hook_array = [];
        if (is_file("custom/Extension/modules/{$hooked}/Ext/LogicHooks/bd_account_owner.php")) {
            include "custom/Extension/modules/{$hooked}/Ext/LogicHooks/bd_account_owner.php";
        }
        BdTestSugar::$hooks[$hooked] = array_merge(BdTestSugar::$hooks[$hooked] ?? [], $hook_array['before_save'] ?? []);
    }

    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $checks[] = [$name, $expected === $actual, $expected, $actual];
    };

    // ── the world: an ADM tenant, its projects, and the accounts' earlier quotes ──
    $ADM_PUBLISHED = ['type' => 'BdAdmCompanies', 'is_active' => 1, 'erp_sync_key' => 'ADM__BdAdmCompanies_ADM',
        'erp_display_sync_key' => 'ADM', 'name' => 'ADM'];
    $LOOKUPS = [
        ['type' => 'BdProjects', 'is_active' => 1, 'erp_display_sync_key' => '17879', 'name' => 'LGH EXPANSION'],
        ['type' => 'BdProjects', 'is_active' => 1, 'erp_display_sync_key' => '20065', 'name' => 'CMI program'],
        ['type' => 'BdProjects', 'is_active' => 0, 'erp_display_sync_key' => '11111', 'name' => 'Closed project'],
        ['type' => 'BdLeadSources', 'is_active' => 1, 'erp_display_sync_key' => 'E-MAIL', 'name' => 'Email'],
    ];
    $account = function (string $id, string $company) {
        $shipTo = new SugarBean('ShippingAddresses', ['id' => 'ship-' . $id, 'name' => 'Main yard',
            'erp_primary_ship_to' => true, 'shipping_address_city' => 'YORK', 'shipping_address_state' => 'PA']);

        return new SugarBean('Accounts', ['id' => $id, 'name' => 'Customer ' . $id,
            'erp_companies_accountserp_companies_ida' => $company, 'shipping_addresses' => new BdTestLink([$shipTo])]);
    };
    $earlier = function (string $id, string $accountId, string $entered, array $fields) {
        BdTestSugar::$records['Quotes'][$id] = ['id' => $id, 'module_name' => 'Quotes',
            'billing_account_id' => $accountId, 'date_entered' => $entered] + $fields;
    };
    $world = function (bool $admPublished = true) use ($ADM_PUBLISHED, $LOOKUPS, $account, $earlier) {
        BdAdmRules::forgetAdmCompanies();
        BdTestAdmin::$config = [];
        BdTestAdmin::$writes = [];
        BdTestSugar::$records = [];
        BdTestSugar::$saves = [];
        SugarQuery::$all = [];
        BdTestSugar::$lookupRows = $admPublished ? array_merge([$ADM_PUBLISHED], $LOOKUPS) : $LOOKUPS;
        BeanFactory::$beans = [
            'ERP_Companies' => [
                'co-adm' => new SugarBean('ERP_Companies', ['id' => 'co-adm', 'name' => 'ADM', 'erp_sync_key' => 'ADM']),
                'co-epic' => new SugarBean('ERP_Companies', ['id' => 'co-epic', 'name' => 'EPIC06',
                    'erp_sync_key' => 'EPIC06']),
            ],
            'Accounts' => [
                'acct-adm' => $account('acct-adm', 'co-adm'),
                'acct-first' => $account('acct-first', 'co-adm'),
                'acct-closed' => $account('acct-closed', 'co-adm'),
                'acct-epic' => $account('acct-epic', 'co-epic'),
            ],
        ];
        // acct-adm: its newest quote holds no Project, the next a closed one (not offered), then 17879, then 20065.
        $earlier('q-a1', 'acct-adm', '2026-06-01 10:00:00', ['bd_project_id' => '20065', 'bd_lead_source' => 'E-MAIL']);
        $earlier('q-a2', 'acct-adm', '2026-09-01 10:00:00', ['bd_project_id' => '17879']);
        $earlier('q-a3', 'acct-adm', '2026-10-01 10:00:00', ['bd_project_id' => '11111']);
        $earlier('q-a4', 'acct-adm', '2026-10-20 10:00:00', ['bd_project_id' => '']);
        // Another account's newer quote: never this account's history.
        $earlier('q-o1', 'acct-epic', '2026-10-20 12:00:00', ['bd_project_id' => '20065']);
        // acct-closed's only Project is a closed one.
        $earlier('q-c1', 'acct-closed', '2026-10-01 10:00:00', ['bd_project_id' => '11111']);
    };
    // The button: ERP-Epicor's real endpoint, as the Account's header action calls it (an empty body).
    $press = function (string $accountId): array {
        $api = new ServiceBase();
        $api->user = new SugarBean('Users', ['id' => 'seller-1']);
        // A REST request runs as its user: Sugar's $current_user is the API user.
        $GLOBALS['current_user'] = $api->user;

        return (new AccountsErpActionsApi())->createOppQuote($api, ['module' => 'Accounts', 'record' => $accountId]);
    };
    $stored = fn(string $id, string $field) => BdTestSugar::$records['Quotes'][$id][$field] ?? null;
    // The create form's request: ModuleApi::createBean() gives the id first, then only the fields it carries.
    $formCreate = function (array $sent): string {
        $bean = BeanFactory::newBean('Quotes');
        $bean->id = 'q-form-' . (++BdTestSugar::$seq);
        $bean->new_with_id = true;
        foreach ($sent as $field => $value) {
            if (isset($sent[$field])) {
                $bean->$field = $value;
            }
        }
        $bean->save();

        return $bean->id;
    };
    // The record view's Save: the stored quote, with the request's fields over it.
    $recordSave = function (string $id, array $sent) {
        $bean = BdTestSugar::retrieve('Quotes', $id);
        foreach ($sent as $field => $value) {
            if (isset($sent[$field])) {
                $bean->$field = $value;
            }
        }
        $bean->save();
    };
    $quoteSaves = fn() => array_values(array_filter(BdTestSugar::$saves, fn($s) => $s[0] === 'Quotes'));
    $projectReads = fn() => array_values(array_filter(SugarQuery::$all, fn($q) => is_object($q->from)
        && $q->from->module_name === 'Quotes' && $q->whereObj->notEmpty === ['bd_project_id']));

    // ── P. the button's quote ────────────────────────────────────────────────
    $world();
    $answer = $press('acct-adm');
    $made = (string) ($answer['quote_id'] ?? '');
    $check('P1 the button answers success and names its new quote', ['success', true],
        [$answer['status'] ?? null, $made !== '']);
    $check('P2 the button saves the quote once, and Sugar hands the hook a create (isUpdate false)',
        [['Quotes', $made, false]], $quoteSaves());
    $check('P3 control: on that create the hook already fills the account-history fields (Lead Source from the '
        . 'newest quote holding one) and the Reference from the ship-to', ['E-MAIL', 'YORK PA'],
        [$stored($made, 'bd_lead_source'), $stored($made, 'erp_reference')]);
    $check('P4 rc117: it has no quote-level Project, though the account\'s quotes hold 17879 (the Project is each '
        . 'line\'s own)', null, $stored($made, 'bd_project_id'));
    $check('P5 rc117: and no read of the account\'s quotes for a Project', 0, count($projectReads()));

    // Once: the seller clears it on the quote's page and saves, then saves again.
    $recordSave($made, ['bd_project_id' => '']);
    $afterClear = $stored($made, 'bd_project_id');
    $recordSave($made, ['name' => 'Renamed by the seller']);
    $check('P6 once only: cleared on the quote\'s page it stays empty, and a later save of another field does not '
        . 'bring it back (both saves are updates)', ['', '', [true, true]],
        [$afterClear, $stored($made, 'bd_project_id'), array_map(fn($s) => $s[2], array_slice($quoteSaves(), 1))]);
    $earlier('q-before', 'acct-adm', '2026-10-21 04:40:00', []);
    $recordSave('q-before', ['name' => 'Edited']);
    $check('P7 a quote the button made before this change, with no Project, gets none on a later save', null,
        $stored('q-before', 'bd_project_id'));

    // ── C. controls: where the button's quote gets no Project ───────────────
    $world();
    $first = (string) ($press('acct-first')['quote_id'] ?? '');
    $check('C1 an ADM account with no earlier quote: the button\'s quote has no Project, and is still made',
        [true, null], [$first !== '', $stored($first, 'bd_project_id')]);
    $world();
    $closed = (string) ($press('acct-closed')['quote_id'] ?? '');
    $check('C2 an account whose only Project is closed (the picker no longer offers it): none', null,
        $stored($closed, 'bd_project_id'));
    $world();
    $epic = (string) ($press('acct-epic')['quote_id'] ?? '');
    $check('C3 an account in a company that does not get ADM\'s rules (EPIC06), whose quote holds 20065: none, '
        . 'and no Project read', [null, 0], [$stored($epic, 'bd_project_id'), count($projectReads())]);
    $world(false);
    $stock = (string) ($press('acct-adm')['quote_id'] ?? '');
    $check('C4 a tenant where no company is published as ADM\'s: none, and no Project read', [null, 0],
        [$stored($stock, 'bd_project_id'), count($projectReads())]);

    // ── F. the create form: what the seller sends is kept ───────────────────
    $world();
    $form = ['billing_account_id' => 'acct-adm', 'name' => 'From the form', 'erp_reference' => 'BOOTH 4'];
    $cleared = $formCreate($form + ['bd_project_id' => '']);
    $kept = $formCreate($form + ['bd_project_id' => '17879']);
    $picked = $formCreate($form + ['bd_project_id' => '20065']);
    $check('F1 the create form with the Project the seller cleared (sent as \'\'): it stays empty, on a create',
        ['', false], [$stored($cleared, 'bd_project_id'), $quoteSaves()[0][2]]);
    $check('F2 the create form with its offer left (17879) or another pick (20065): each kept as sent',
        ['17879', '20065'], [$stored($kept, 'bd_project_id'), $stored($picked, 'bd_project_id')]);
    $check('F3 none of the three reads the account\'s quotes for a Project', 0, count($projectReads()));

    // ── N. Needs Price Estimate on by default (rc117) ───────────────────────
    // The install sets ERP-Epicor's per-tenant default once; ERP-Epicor's real button reads it on every click.
    $world();
    $before = (string) ($press('acct-adm')['quote_id'] ?? '');
    $check('N1 control: with no default set (rc116), the button\'s quote is a Sales Order (ERP-Epicor\'s own default)',
        'sales_order', $stored($before, 'erp_quote_type'));
    $class = 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdQuoteTypeDefault';
    $applied = class_exists($class) ? $class::apply(new BdTestAdmin()) : 'no BdQuoteTypeDefault';
    $check('N2 the install step writes exactly one setting: ERP-Epicor\'s erp_integration.opp_quote_type = '
        . 'advanced_quote, platform base (what its config read sees)',
        [['erp_integration', 'opp_quote_type', 'advanced_quote', 'base']], BdTestAdmin::$writes);
    $after = (string) ($press('acct-adm')['quote_id'] ?? '');
    $check('N3 the button\'s next quote starts with Needs Price Estimate on (erp_quote_type advanced_quote), still '
        . 'with no quote-level Project', ['advanced_quote', null],
        [$stored($after, 'erp_quote_type'), $stored($after, 'bd_project_id')]);
    $check('N4 the step says what it did', 'set to advanced_quote (was unset)', $applied);
    $again = class_exists($class) ? $class::apply(new BdTestAdmin()) : '';
    $check('N5 run again (it already is): nothing written', [1, 'kept: already advanced_quote'],
        [count(BdTestAdmin::$writes), $again]);
    BdTestAdmin::$config['erp_integration']['opp_quote_type'] = 'sales_order';
    $chosen = (string) ($press('acct-adm')['quote_id'] ?? '');
    $check('N6 an administrator who sets it back to Sales Order gets Sales Order quotes (the install sets it once, '
        . 'it never pins it)', 'sales_order', $stored($chosen, 'erp_quote_type'));
    $check('N7 the setting is ERP-Epicor\'s own: the same category and key as AccountsErpActionsApi\'s constants',
        [AccountsErpActionsApi::CONFIG_CATEGORY, AccountsErpActionsApi::CFG_QUOTE_TYPE],
        class_exists($class) ? [constant($class . '::CATEGORY'), constant($class . '::KEY')] : []);
    $check('N8 and the value is a key of the quote type list the button checks it against (ERP-Core\'s '
        . 'erp_quote_type_list: sales_order, advanced_quote "Needs Price Estimate")', true,
        class_exists($class) && array_key_exists(constant($class . '::NEEDS_PRICE_ESTIMATE'),
            $GLOBALS['app_list_strings']['erp_quote_type_list']));

    // ── O. rc121: the button's Opportunity and Quote are the account owner's ─
    $owned = function (string $owner) use ($world) {
        $world();
        BeanFactory::$beans['Users'] = [
            'seller-1' => new SugarBean('Users', ['id' => 'seller-1', 'status' => 'Active']),
            'lynn' => new SugarBean('Users', ['id' => 'lynn', 'status' => 'Active']),
            'gone' => new SugarBean('Users', ['id' => 'gone', 'status' => 'Inactive']),
        ];
        BeanFactory::$beans['Accounts']['acct-adm']->assigned_user_id = $owner;
    };
    $owners = function (array $answer): array {
        return [BdTestSugar::$records['Opportunities'][$answer['opportunity_id'] ?? '']['assigned_user_id'] ?? null,
            BdTestSugar::$records['Quotes'][$answer['quote_id'] ?? '']['assigned_user_id'] ?? null];
    };
    $owned('lynn');
    $answer = $press('acct-adm');
    $check('O1 pressed by seller-1 on an account Lynn owns: the Opportunity and the Quote are Lynn\'s', ['lynn', 'lynn'],
        $owners($answer));
    $check('O2 ...on the records\' create saves (one each, isUpdate false), the quote\'s other defaults as before',
        [[['Opportunities', $answer['opportunity_id'] ?? '', false]], [['Quotes', $answer['quote_id'] ?? '', false]],
            'E-MAIL'],
        [array_values(array_filter(BdTestSugar::$saves, fn($s) => $s[0] === 'Opportunities')), $quoteSaves(),
            $stored((string) ($answer['quote_id'] ?? ''), 'bd_lead_source')]);
    $owned('seller-1');
    $check('O3 control: the presser owns the account: theirs, unchanged', ['seller-1', 'seller-1'],
        $owners($press('acct-adm')));
    $owned('gone');
    $check('O4 control: the account\'s owner is Inactive: the presser\'s, as before rc121', ['seller-1', 'seller-1'],
        $owners($press('acct-adm')));
    $owned('');
    $check('O5 control: an account owned by no one: the presser\'s', ['seller-1', 'seller-1'], $owners($press('acct-adm')));
    $owned('lynn');
    $made = (string) ($press('acct-adm')['quote_id'] ?? '');
    $recordSave($made, ['assigned_user_id' => 'seller-1']);
    $check('O6 the seller changes the quote\'s owner on its page afterwards: kept (an update is never changed)',
        'seller-1', $stored($made, 'assigned_user_id'));

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
