<?php

/**
 * G860 (🔒2179b): ADM's Ship Via requirement, the Sugar half, EXECUTED.
 *
 * Run:  BD_ERP_AUTOLOADER=<ERP-Core/tests/support/sugar_autoloader.php of an
 *         ERP-Epicor >= 1.2.0 checkout> php scripts/tests/bd_order_requirements_test.php
 *       (scripts/tests/test_php_suites.py runs it with the sibling checkout's
 *       autoloader when present, else the pin under fixtures/shared-sugar/)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * ERP-Epicor (erp-integration-sugar, G860) asks the file at its slot
 * custom/modules/Quotes/ErpQuoteHooks/OrderRequirements.php what the order
 * header still lacks, and names the answer in its one pre-send refusal beside
 * its own FOB rule. This package answers for ADM, by the route the order takes:
 *
 *   - an order that CONVERTS an ADM ERP quote (core's G304 route: a line whose
 *     erp_sync_key is <CO>__<QuoteNum>_<QuoteLine>_<QtyNum>) takes a blank Ship
 *     Via from that ERP quote, and the quote's own Ship Via relate is the
 *     read-back of the ERP quote's ShipViaCode - so a quote with none, on that
 *     route, reaches ADM with none (the connector extension's G852 rule). The
 *     customer's default does not count there.
 *   - any other order (a Sales Order: no line converts an ERP quote) is a
 *     composite POST, where Kinetic takes the CUSTOMER's Ship Via. Since the
 *     connector maps Customer.ShipViaCode onto the Account's Ship Via relate
 *     (SDK 1.20), Sugar holds it: a quote with no Ship Via whose billing
 *     account has none either reaches ADM with none, and is refused here, in
 *     the words ERP-Epicor's own test pins for this case
 *     (OrderNamesEveryHeaderRequirementTest::G860_NO_SHIP_VIA), so the first
 *     Submit Order names the FOB and the Ship Via together.
 *
 * The account shapes are the recorded ones: the connector's account forward
 * links Customer.ShipViaCode "PMSP" to the ShippingTerms lookup
 * ADM__ShippingTerms_PMSP (id 4511c0de-...) and writes "" to clear it.
 *
 * ErpQuoteFacts is ERP-Epicor's REAL class, found through ERP-Core's own
 * autoloader stand-in; only BeanFactory and SugarQuery are faked. BeanFactory
 * COUNTS every read, which makes "the account is never read" observable.
 *
 * IF IT WERE BROKEN you would see:
 *   "AQ, ADM, no Ship Via: one requirement" FAIL - the rule is gone or reads
 *     the wrong relate;
 *   "AQ ... the customer HAS a default" FAIL - the conversion route trusts a
 *     customer default ADM does not use there;
 *   "SO, ADM, no Ship Via on the quote or its customer" FAIL - the first Submit
 *     Order names the FOB alone again, and the seller learns about the Ship Via
 *     only after a second click and a connector round trip;
 *   "SO ... the customer has a default Ship Via" FAIL - Sugar refuses orders
 *     ADM accepts on the customer's own Ship Via;
 *   "EPIC06 ... nothing" FAIL - the rule escaped the ADM gate;
 *   "a 2-part ladder key does not convert" FAIL - a key core does not convert
 *     is judged as if it did (on the ERP quote's Ship Via, not the customer's);
 *   "half-installed" FAIL - a missing class refuses every order on the tenant.
 */

namespace {
    // CHILD: the adapter alone, with no autoloader for this package's classes (a half-installed package).
    if (getenv('BD_ORDER_REQ_CHILD') === 'no-class') {
        $GLOBALS['log'] = new class {
            public $lines = [];

            public function __call($level, $args)
            {
                $this->lines[] = $level . ': ' . (string) ($args[0] ?? '');
            }
        };
        require __DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/modules/Quotes/ErpQuoteHooks/OrderRequirements.php';
        $quote = new stdClass();
        $quote->id = 'q-half';
        $answer = (new ErpQuoteOrderRequirementsHook())->missing($quote, []);
        echo json_encode(['answer' => $answer, 'log' => $GLOBALS['log']->lines]);
        exit(0);
    }

    use Sugarcrm\Sugarcrm\custom\BenchDogs\BdAdmOrderRequirements;
    use Sugarcrm\Sugarcrm\custom\BenchDogs\BdAdmRules;

    $erpAutoloader = (string) getenv('BD_ERP_AUTOLOADER');
    if ($erpAutoloader === '' || !is_file($erpAutoloader)) {
        fwrite(STDERR, "BD_ERP_AUTOLOADER must name ERP-Core's tests/support/sugar_autoloader.php\n");
        exit(2);
    }
    require $erpAutoloader;

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
        public $ids;

        public function __construct(array $ids)
        {
            $this->ids = $ids;
        }

        public function get()
        {
            return $this->ids;
        }
    }

    #[AllowDynamicProperties]
    class BdTestBean
    {
        public $id = '';
        /** The ids the quote's erp_quotes_ship_via link holds; null when the link cannot be loaded. */
        public $shipViaLink = [];
        /** Any other link this record has, by name => the ids it holds; null when the link cannot be loaded. */
        public $links = [];

        public function __construct(array $fields = [])
        {
            foreach ($fields as $k => $v) {
                $this->$k = $v;
            }
        }

        public function load_relationship($name)
        {
            if ($name === 'erp_quotes_ship_via') {
                if ($this->shipViaLink === null) {
                    return false;
                }
                $this->erp_quotes_ship_via = new BdTestLink($this->shipViaLink);
                return true;
            }
            if (!array_key_exists($name, $this->links) || $this->links[$name] === null) {
                return false;
            }
            $this->$name = new BdTestLink($this->links[$name]);
            return true;
        }
    }

    class BeanFactory
    {
        public static $beans = [];
        public static $reads = [];
        public static $throw = false;
        /** One read that fails, as '<module>:<id>'; every other read answers. */
        public static $throwOn = '';

        public static function retrieveBean($module, $id)
        {
            self::$reads[] = $module . ':' . $id;
            if (self::$throw || self::$throwOn === $module . ':' . $id) {
                throw new RuntimeException("database failure reading $module $id");
            }
            return self::$beans[$module][$id] ?? null;
        }

        public static function newBean($module)
        {
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
        public static $constructed = 0;
        public $whereObj;

        public function __construct()
        {
            $this->whereObj = new BdTestWhere();
            self::$constructed++;
        }

        public function from($bean, $options = [])
        {
        }

        public function select($fields)
        {
        }

        public function where()
        {
            return $this->whereObj;
        }

        public function limit($n)
        {
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
            return $out;
        }
    }

    // The package root is the tenant's docroot as far as these files know.
    chdir(realpath(__DIR__ . '/../../sugar-sell/BenchDogs-Ext'));
    require_once 'custom/modules/Quotes/ErpQuoteHooks/OrderRequirements.php';

    $checks = [];
    $check = function (string $name, $expected, $actual) use (&$checks) {
        $checks[] = [$name, $expected === $actual, $expected, $actual];
    };

    $noShipVia = 'This quote has no Ship Via and its ERP quote 8720 has none either, so it cannot be ordered. '
        . 'Pick a Ship Via on the quote, then submit again. Nothing was sent to the ERP.';
    $refused = [['error' => 'The quote has no Ship Via', 'message' => $noShipVia]];
    // Word for word ERP-Epicor's OrderNamesEveryHeaderRequirementTest::G860_NO_SHIP_VIA: the package answer that test
    // proves is joined after the FOB sentence in the one refusal.
    $noCustomerShipVia = 'This quote has no Ship Via and its customer has no default Ship Via in the ERP, so it '
        . 'cannot be ordered. Pick a Ship Via on the quote, then submit again. Nothing was sent to the ERP.';
    $refusedSo = [['error' => 'The quote has no Ship Via', 'message' => $noCustomerShipVia]];
    // The recorded ShippingTerms lookup the account forward links for Customer.ShipViaCode "PMSP".
    $pmsp = '4511c0de-b746-11f1-9e97-0a90a5871447';

    // The connector extension publishes ADM as a company that gets ADM's rules; EPIC06 only has lead sources, which
    // no longer make a company ADM's (BdAdmRules::admCompanies()).
    $admRows = [
        ['type' => BdAdmRules::TYPE_ADM_COMPANIES, 'is_active' => 1, 'erp_sync_key' => 'ADM__BdAdmCompanies_ADM'],
        ['type' => BdAdmRules::TYPE_LEAD_SOURCES, 'is_active' => 1, 'erp_sync_key' => 'EPIC06__BdLeadSources_WEB'],
    ];
    // An account as Sugar serves it: its ERP company relate and its Ship Via relate (link ids, else the relate id).
    $account = function (string $id, string $company, ?array $shipVia, string $shipViaId = '') {
        return new BdTestBean(['id' => $id, 'erp_companies_accountserp_companies_ida' => $company,
            'links' => ['erp_ship_via' => $shipVia], 'erp_ship_viaerp_lookupvalues_idb' => $shipViaId]);
    };
    $world = function (?array $rows = null) use ($admRows, $account, $pmsp) {
        BdAdmRules::forgetAdmCompanies();
        SugarQuery::$rows = $rows ?? $admRows;
        SugarQuery::$constructed = 0;
        BeanFactory::$reads = [];
        BeanFactory::$throw = false;
        $GLOBALS['log']->lines = [];
        BeanFactory::$beans = [
            'ERP_Companies' => [
                'co-adm' => new BdTestBean(['id' => 'co-adm', 'erp_sync_key' => 'ADM']),
                'co-epic' => new BdTestBean(['id' => 'co-epic', 'erp_sync_key' => 'EPIC06']),
            ],
            'Accounts' => [
                // The account forward wrote "" for an empty ShipViaCode: the link holds nothing.
                'acct-adm' => $account('acct-adm', 'co-adm', []),
                'acct-epic' => $account('acct-epic', 'co-epic', []),
                // Customer.ShipViaCode "PMSP", linked by the account forward.
                'acct-adm-pmsp' => $account('acct-adm-pmsp', 'co-adm', [$pmsp]),
                // The same, read from the relate id when the link cannot be loaded.
                'acct-adm-idb' => $account('acct-adm-idb', 'co-adm', null, $pmsp),
                'acct-adm-nocode' => $account('acct-adm-nocode', 'co-adm', ['lv-nocode']),
                'acct-adm-gone' => $account('acct-adm-gone', 'co-adm', ['lv-gone']),
                'acct-adm-throws' => $account('acct-adm-throws', 'co-adm', ['lv-throws']),
            ],
            'ERP_LookupValues' => [
                'lv-ups' => new BdTestBean(['id' => 'lv-ups', 'type' => 'ShippingTerms', 'erp_display_sync_key' => 'UPS']),
                'lv-nocode' => new BdTestBean(['id' => 'lv-nocode', 'type' => 'ShippingTerms',
                    'erp_display_sync_key' => '  ']),
                $pmsp => new BdTestBean(['id' => $pmsp, 'type' => 'ShippingTerms',
                    'erp_sync_key' => 'ADM__ShippingTerms_PMSP', 'erp_display_sync_key' => 'PMSP']),
                'lv-throws' => new BdTestBean(['id' => 'lv-throws', 'type' => 'ShippingTerms',
                    'erp_display_sync_key' => 'UPS']),
            ],
        ];
        BeanFactory::$throwOn = 'ERP_LookupValues:lv-throws';
    };
    $quote = function (string $account, array $shipViaLink = [], array $extra = []) {
        return new BdTestBean(array_merge(['id' => 'q-1', 'billing_account_id' => $account,
            'shipViaLink' => $shipViaLink], $extra));
    };
    $line = function (string $id, string $key = '') {
        return new BdTestBean(['id' => $id, 'name' => $id, 'erp_sync_key' => $key]);
    };
    $aq = [$line('l-1', 'ADM__8720_1_1'), $line('l-2', 'ADM__8720_2_1')];
    $hook = new ErpQuoteOrderRequirementsHook();

    // ── A. the Advanced Quote route (the order converts the ADM ERP quote) ──
    $world();
    $check('A1 AQ, ADM, no Ship Via: one requirement, in the seller sentence', $refused,
        $hook->missing($quote('acct-adm'), $aq));
    $world();
    $check('A2 AQ, ADM, a Ship Via on the quote: nothing missing', [],
        $hook->missing($quote('acct-adm', ['lv-ups']), $aq));
    $world();
    $check("A3 AQ, ADM, the Ship Via read from its relate id when the link cannot be loaded: nothing missing", [],
        $hook->missing($quote('acct-adm', [], ['shipViaLink' => null,
            'erp_quotes_ship_viaerp_lookupvalues_idb' => 'lv-ups']), $aq));
    $world();
    $check('A4 AQ, ADM, a Ship Via row with no ERP code (nothing core could send): refused', $refused,
        $hook->missing($quote('acct-adm', ['lv-nocode']), $aq));
    $world();
    $check('A5 AQ, ADM, a Ship Via relate to a deleted row: refused', $refused,
        $hook->missing($quote('acct-adm', ['lv-gone']), $aq));
    $world();
    $check('A6 a Sugar-only line beside a converted one: the order still converts, refused', $refused,
        $hook->missing($quote('acct-adm'), [$line('l-new'), $line('l-1', 'ADM__8720_1_1')]));
    $world();
    $check('A7 the ERP quote named is the first converted line\'s',
        [['error' => 'The quote has no Ship Via', 'message' => str_replace('8720', '8731', $noShipVia)]],
        $hook->missing($quote('acct-adm'), [$line('l-9', 'ADM__8731_4_2'), $line('l-1', 'ADM__8720_1_1')]));
    $world();
    $check('A8 AQ, ADM, no Ship Via, the customer HAS a default: still refused (a converted order takes the ERP '
        . "quote's Ship Via, never the customer's)", $refused, $hook->missing($quote('acct-adm-pmsp'), $aq));
    $check("A8 ... and the customer's Ship Via was not even read", false,
        in_array('ERP_LookupValues:' . $pmsp, BeanFactory::$reads, true));

    // ── B. what is not judged ────────────────────────────────────────────────
    $world();
    $check('B2 a 2-part ladder key does not convert (core converts 3-part rung keys only): not judged as ERP quote '
        . "8720, but on the customer's Ship Via", $refusedSo,
        $hook->missing($quote('acct-adm'), [$line('l-1', 'ADM__8720_1')]));
    $world();
    $check("B2 ... and the customer's default Ship Via satisfies it", [],
        $hook->missing($quote('acct-adm-pmsp'), [$line('l-1', 'ADM__8720_1')]));
    $world();
    $check('B3 EPIC06 AQ with no Ship Via: nothing (not ADM)', [],
        $hook->missing($quote('acct-epic'), [$line('l-1', 'EPIC06__1305_1_1')]));
    $world([]);
    $check('B4 a tenant with no ADM rows at all: nothing', [], $hook->missing($quote('acct-adm'), $aq));
    $check('B4 ... and no account or company was read', true,
        count(array_filter(BeanFactory::$reads, fn($r) => strpos($r, 'Accounts:') === 0)) === 0);
    $world();
    $check('B5 a quote with no billing account: nothing', [], $hook->missing($quote(''), $aq));
    $world();
    $check('B6 no lines: nothing', [], $hook->missing($quote('acct-adm'), []));
    $check('B6 ... and nothing was read for it', [[], 0], [BeanFactory::$reads, SugarQuery::$constructed]);

    // ── E. a Sales Order (no line converts an ERP quote): Kinetic takes the customer's Ship Via ──
    $so = [$line('l-new'), $line('l-new2')];
    $world();
    $check('E1 SO, ADM, no Ship Via on the quote or its customer: one requirement, in the customer sentence',
        $refusedSo, $hook->missing($quote('acct-adm'), $so));
    $world();
    $check('E2 SO, ADM, the customer has a default Ship Via (recorded PMSP): nothing missing', [],
        $hook->missing($quote('acct-adm-pmsp'), $so));
    $world();
    $check("E3 SO, ADM, the customer's Ship Via read from its relate id when the link cannot be loaded: nothing", [],
        $hook->missing($quote('acct-adm-idb'), $so));
    $world();
    $check('E4 SO, ADM, a Ship Via on the quote: nothing missing', [],
        $hook->missing($quote('acct-adm', ['lv-ups']), $so));
    $check('E4 ... and the account was not even read', 0,
        count(array_filter(BeanFactory::$reads, fn($r) => strpos($r, 'Accounts:') === 0)));
    $world();
    $check("E5 SO, ADM, the customer's Ship Via row has no ERP code (Kinetic would default nothing): refused",
        $refusedSo, $hook->missing($quote('acct-adm-nocode'), $so));
    $world();
    $check("E6 SO, ADM, the customer's Ship Via relate names a deleted row: refused", $refusedSo,
        $hook->missing($quote('acct-adm-gone'), $so));
    $world();
    $check('E7 SO, EPIC06, no Ship Via anywhere: nothing (not ADM)', [],
        $hook->missing($quote('acct-epic'), $so));
    $world([]);
    $check('E8 SO on a tenant with no ADM rows at all: nothing', [], $hook->missing($quote('acct-adm'), $so));
    $check('E8 ... and no account was read', 0,
        count(array_filter(BeanFactory::$reads, fn($r) => strpos($r, 'Accounts:') === 0)));
    $world();
    $check('E9 an Advanced Quote whose lines convert no ERP quote line goes the same route: the customer sentence',
        $refusedSo, $hook->missing($quote('acct-adm', [], ['erp_quote_type' => 'advanced_quote']), $so));
    $world();
    $check("E10 SO, ADM, the customer's Ship Via read fails: nothing missing (the extension checks again)", [],
        $hook->missing($quote('acct-adm-throws'), $so));
    $check('E10 ... and it is logged', true, (function () {
        foreach ($GLOBALS['log']->lines as [$level, $text]) {
            if ($level === 'error' && strpos($text, 'ADM order requirements skipped for quote q-1') !== false
                && strpos($text, 'lv-throws') !== false) {
                return true;
            }
        }
        return false;
    })());

    // ── C. the rung key, as core's parse_rung_key reads it ──────────────────
    foreach ([
        ['ADM__8720_1_1', [8720, 1]],
        [' ADM__8720_2_3 ', [8720, 2]],
        ['ADM_X__8720_1_1', [8720, 1]],
        ['ADM__8720_1', null],
        ['ADM__8720_1_1_1', null],
        ['ADM__8720__1_1', null],
        ['ADM__8720_0_1', null],
        ['ADM__0_1_1', null],
        ['ADM__x_1_1', null],
        ['ADM__8720_1_', null],
        ['__8720_1_1', null],
        ['8720_1_1', null],
        ['', null],
    ] as [$key, $want]) {
        $check("C quoteLineLink('$key')", $want, BdAdmOrderRequirements::quoteLineLink($key));
    }

    // ── D. the adapter: ERP-Epicor's contract, and it never breaks an order ──
    $world();
    BeanFactory::$throw = true;
    $check('D1 a read that fails: nothing missing (the extension checks again before the send)', [],
        $hook->missing($quote('acct-adm'), $aq));
    $check('D1 ... and it is logged', true, (function () {
        foreach ($GLOBALS['log']->lines as [$level, $text]) {
            if ($level === 'error' && strpos($text, 'ADM order requirements skipped for quote q-1') !== false) {
                return true;
            }
        }
        return false;
    })());
    $check('D2 the adapter is the global class ERP-Epicor instantiates, with its method', true,
        method_exists('ErpQuoteOrderRequirementsHook', 'missing'));
    $child = shell_exec('BD_ORDER_REQ_CHILD=no-class ' . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__FILE__) . ' 2>&1');
    $half = json_decode((string) $child, true);
    $check('D3 half-installed (adapter present, class not loadable): nothing missing, never a refusal', [],
        is_array($half) ? $half['answer'] : $child);
    $check('D3 ... and it is logged', true,
        is_array($half) && strpos(implode("\n", $half['log']), 'error: BenchDogs-Ext: ADM order requirements '
            . 'skipped for quote q-half') === 0);

    $failed = 0;
    foreach ($checks as [$name, $ok, $expected, $actual]) {
        echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
        if (!$ok) {
            $failed++;
            echo '       expected: ' . json_encode($expected) . "\n";
            echo '       actual:   ' . json_encode($actual) . "\n";
        }
    }
    echo "\n" . count($checks) . " checks, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
