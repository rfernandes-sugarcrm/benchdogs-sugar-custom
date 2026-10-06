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
 * its own FOB rule. This package answers for ADM: an order that CONVERTS an ADM
 * ERP quote (core's G304 route: a line whose erp_sync_key is
 * <CO>__<QuoteNum>_<QuoteLine>_<QtyNum>) takes a blank Ship Via from that ERP
 * quote, and the quote's own Ship Via relate is the read-back of the ERP
 * quote's ShipViaCode - so a quote with none, on that route, reaches ADM with
 * none (the connector extension's G852 rule). A Sales Order is NOT judged:
 * Kinetic takes the customer's Ship Via there, and Sugar does not hold it
 * (core never syncs Customer.ShipViaCode onto the Account), so its absence in
 * Sugar says nothing; the extension checks it before the send.
 *
 * ErpQuoteFacts is ERP-Epicor's REAL class, found through ERP-Core's own
 * autoloader stand-in; only BeanFactory and SugarQuery are faked. BeanFactory
 * COUNTS every read, which makes "a Sales Order reads nothing" observable.
 *
 * IF IT WERE BROKEN you would see:
 *   "AQ, ADM, no Ship Via: one requirement" FAIL - the rule is gone or reads
 *     the wrong relate;
 *   "Sales Order ... is not judged" FAIL - Sugar refuses orders ADM accepts
 *     on the customer's own Ship Via;
 *   "EPIC06 ... nothing" FAIL - the rule escaped the ADM gate;
 *   "a 2-part ladder key does not convert" FAIL - a key core does not convert
 *     is judged as if it did;
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

        public function __construct(array $fields = [])
        {
            foreach ($fields as $k => $v) {
                $this->$k = $v;
            }
        }

        public function load_relationship($name)
        {
            if ($name !== 'erp_quotes_ship_via' || $this->shipViaLink === null) {
                return false;
            }
            $this->erp_quotes_ship_via = new BdTestLink($this->shipViaLink);
            return true;
        }
    }

    class BeanFactory
    {
        public static $beans = [];
        public static $reads = [];
        public static $throw = false;

        public static function retrieveBean($module, $id)
        {
            self::$reads[] = $module . ':' . $id;
            if (self::$throw) {
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

    // ADM has published lead sources; EPIC06 has not (BdAdmRules::admCompanies()).
    $admRows = [['type' => BdAdmRules::TYPE_LEAD_SOURCES, 'erp_sync_key' => 'ADM__BdLeadSources_WEB']];
    $world = function (?array $rows = null) use ($admRows) {
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
                'acct-adm' => new BdTestBean(['id' => 'acct-adm', 'erp_companies_accountserp_companies_ida' => 'co-adm']),
                'acct-epic' => new BdTestBean(['id' => 'acct-epic',
                    'erp_companies_accountserp_companies_ida' => 'co-epic']),
            ],
            'ERP_LookupValues' => [
                'lv-ups' => new BdTestBean(['id' => 'lv-ups', 'type' => 'ShippingTerms', 'erp_display_sync_key' => 'UPS']),
                'lv-nocode' => new BdTestBean(['id' => 'lv-nocode', 'type' => 'ShippingTerms',
                    'erp_display_sync_key' => '  ']),
            ],
        ];
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

    // ── B. what is not judged ────────────────────────────────────────────────
    $world();
    $check('B1 Sales Order (no converted line), ADM, no Ship Via: not judged', [],
        $hook->missing($quote('acct-adm'), [$line('l-new'), $line('l-new2')]));
    $check('B1 ... and nothing was read for it', [[], 0], [BeanFactory::$reads, SugarQuery::$constructed]);
    $world();
    $check('B2 a 2-part ladder key does not convert (core converts 3-part rung keys only): not judged', [],
        $hook->missing($quote('acct-adm'), [$line('l-1', 'ADM__8720_1')]));
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
