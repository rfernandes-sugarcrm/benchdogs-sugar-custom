<?php

/**
 * Production-path composition harness for the Kinetic quoted lifecycle.
 *
 * The test doubles only Sugar persistence and relationships. Both decisions
 * under test are the shipped classes: BdQuoteReflectionHook reflects the ERP
 * bean and its actual Quote::save() below dispatches
 * BdEstimatingNotificationHook with the save's generated dataChanges.
 */

#[AllowDynamicProperties]
class SugarBean
{
}

if (!function_exists('mb_substr')) {
    function mb_substr($value, $start, $length = null)
    {
        return $length === null
            ? substr($value, $start)
            : substr($value, $start, $length);
    }
}

class TestLog
{
    public array $rows = [];

    public function info($message): void
    {
        $this->rows[] = ['info', $message];
    }

    public function warn($message): void
    {
        $this->rows[] = ['warn', $message];
    }

    public function error($message): void
    {
        $this->rows[] = ['error', $message];
    }
}

class TimeDate
{
    public static int $modifiedSequence = 0;

    public static function getInstance(): self
    {
        return new self();
    }

    public function nowDb(): string
    {
        return '2026-09-12 14:23:45';
    }

    public static function nextModified(): string
    {
        self::$modifiedSequence++;
        return sprintf('2026-09-12 14:24:%02d', self::$modifiedSequence);
    }
}

class SugarConfig
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function get($key, $default = null)
    {
        return $GLOBALS['config'][$key] ?? $default;
    }
}

class SugarCurrency
{
    public static function formatAmountUserLocale($amount): string
    {
        return '$' . number_format((float) $amount, 2);
    }
}

class TestRelationship
{
    public array $ids = [];

    public function add($id): void
    {
        $this->ids[(string) $id] = true;
    }

    public function get(): array
    {
        return array_keys($this->ids);
    }

    public function getBeans(): array
    {
        return [];
    }
}

class TestUser extends SugarBean
{
    public string $id;
    public string $status = 'Active';
    public int $sugar_login = 1;
    public int $deleted = 0;
    public int $is_group = 0;
    public int $portal_only = 0;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}

class TestNotification extends SugarBean
{
    public string $id = '';
    public string $sync_key = '';
    public string $assigned_user_id = '';
    public string $parent_type = '';
    public string $parent_id = '';
    public int $deleted = 0;

    public function save($checkNotify = true)
    {
        $this->id = 'notification-' . (count($GLOBALS['notifications']) + 1);
        $GLOBALS['notifications'][$this->id] = clone $this;
        return $this->id;
    }
}

class TestQuote extends SugarBean
{
    public string $id = 'quote-1';
    public string $name = 'Bench lifecycle composition';
    public string $bd_erp_stage = 'in_estimating';
    public float $bd_erp_total = 4200.0;
    public string $bd_priced_at = '';
    public string $bd_reason_code = '';
    public string $assigned_user_id = 'sales-user';
    public string $created_by = 'creator-user';
    public string $erp_display_sync_key = '1201';
    public string $date_modified = '2026-09-12 14:20:00';
    public int $erp_is_primary_quote = 0;
    public array $field_defs = [];
    public int $save_count = 0;

    public function load_relationship($name): bool
    {
        // No order is linked and this is not an Opportunity-valuation test.
        return false;
    }

    public function save()
    {
        $before = $GLOBALS['quote_store'];
        $this->date_modified = TimeDate::nextModified();
        $this->save_count = $before->save_count + 1;

        $changes = [];
        foreach (['bd_erp_stage', 'bd_priced_at', 'bd_erp_total', 'bd_reason_code'] as $field) {
            if (($before->{$field} ?? null) !== ($this->{$field} ?? null)) {
                $changes[] = [
                    'field_name' => $field,
                    'before' => $before->{$field} ?? null,
                    'after' => $this->{$field} ?? null,
                ];
            }
        }
        $GLOBALS['quote_store'] = clone $this;
        $GLOBALS['quote_save_changes'][] = $changes;

        $arguments = ['dataChanges' => $changes];
        $notification = new BdEstimatingNotificationHook();
        $notification->notifyEstimating($this, 'after_save', $arguments);
        $notification->notifyPricingReturned($this, 'after_save', $arguments);
        return $this->id;
    }
}

class TestErpQuote extends SugarBean
{
    public string $id = 'erp-quote-1';
    public string $sugar_quote_id = 'quote-1';
    public string $bd_materialized_quote_id = '';
    public string $name = 'Kinetic quote 1201';
    public int $quote_num = 1201;
    public string $current_stage = 'Quote';
    public $quoted = null;
    public string $date_quoted = '';
    public int $quote_closed = 0;
    public string $reason_code = '';
    public float $quote_total = 4200.0;
    public string $bd_priced_back_at = '';
    public string $bd_sent_to_estimating_at = '';
    public int $save_count = 0;
    public TestRelationship $bd01_erp_quote_quotes;

    public function __construct()
    {
        $this->bd01_erp_quote_quotes = new TestRelationship();
    }

    public function load_relationship($name): bool
    {
        return $name === 'bd01_erp_quote_quotes';
    }

    public function save()
    {
        $this->save_count++;
        return $this->id;
    }
}

class BeanFactory
{
    public static function retrieveBean($module, $id, $options = [])
    {
        if ($module === 'Quotes' && $id === 'quote-1') {
            return clone $GLOBALS['quote_store'];
        }
        if ($module === 'Users') {
            return $GLOBALS['users'][$id] ?? null;
        }
        if ($module === 'Notifications') {
            return isset($GLOBALS['notifications'][$id])
                ? clone $GLOBALS['notifications'][$id]
                : null;
        }
        return null;
    }

    public static function newBean($module)
    {
        return $module === 'Notifications' ? new TestNotification() : null;
    }
}

class SugarQueryWhere
{
    public function equals($field, $value): self
    {
        $GLOBALS['query_sync_key'] = (string) $value;
        return $this;
    }
}

class SugarQuery
{
    public function select($fields): self
    {
        return $this;
    }

    public function from($bean): self
    {
        return $this;
    }

    public function where(): SugarQueryWhere
    {
        return new SugarQueryWhere();
    }

    public function limit($limit): self
    {
        return $this;
    }

    public function execute(): array
    {
        $rows = [];
        foreach ($GLOBALS['notifications'] as $id => $notification) {
            if ($notification->sync_key === $GLOBALS['query_sync_key']) {
                $rows[] = ['id' => $id];
            }
        }
        return array_slice($rows, 0, 2);
    }
}

$GLOBALS['log'] = new TestLog();
$GLOBALS['config'] = ['benchdogs_ext.pricing_notify_user_id' => 'sales-user'];
$GLOBALS['users'] = [
    'sales-user' => new TestUser('sales-user'),
    'creator-user' => new TestUser('creator-user'),
];
$GLOBALS['notifications'] = [];
$GLOBALS['query_sync_key'] = '';
$GLOBALS['quote_store'] = new TestQuote();
$GLOBALS['quote_save_changes'] = [];

require $argv[1];
require $argv[2];

$reflection = new BdQuoteReflectionHook();
$erp = new TestErpQuote();

$sync = function ($quoted, string $dateQuoted, array $changes) use ($reflection, $erp): array {
    $erp->quoted = $quoted;
    $erp->date_quoted = $dateQuoted;
    $reflection->reflect($erp, 'after_save', ['dataChanges' => $changes]);
    return [
        'stage' => $GLOBALS['quote_store']->bd_erp_stage,
        'quote_save_count' => $GLOBALS['quote_store']->save_count,
        'notification_count' => count($GLOBALS['notifications']),
        'priced_at' => $GLOBALS['quote_store']->bd_priced_at,
        'priced_back_at' => $erp->bd_priced_back_at,
        'erp_save_count' => $erp->save_count,
    ];
};

$states = [];
$states['false_in_estimating'] = $sync(false, '', [[
    'field_name' => 'quoted', 'before' => null, 'after' => false,
]]);
$states['first_priced'] = $sync(true, '2008-09-10T00:00:00-05:00', [
    ['field_name' => 'quoted', 'before' => false, 'after' => true],
    ['field_name' => 'date_quoted', 'before' => '', 'after' => '2008-09-10T00:00:00-05:00'],
]);
$states['repeat_first_priced'] = $sync(true, '2008-09-10T00:00:00-05:00', [
    ['field_name' => 'quoted', 'before' => true, 'after' => true],
    ['field_name' => 'date_quoted', 'before' => '2008-09-10T00:00:00-05:00', 'after' => '2008-09-10T00:00:00-05:00'],
]);
$states['reopened_revision'] = $sync(false, '', [
    ['field_name' => 'quoted', 'before' => true, 'after' => false],
    ['field_name' => 'date_quoted', 'before' => '2008-09-10T00:00:00-05:00', 'after' => ''],
]);
$states['true_without_date'] = $sync(true, '', [[
    'field_name' => 'quoted', 'before' => false, 'after' => true,
]]);
$states['revision_priced'] = $sync(true, '2008-09-11T00:00:00-05:00', [[
    'field_name' => 'date_quoted', 'before' => '', 'after' => '2008-09-11T00:00:00-05:00',
]]);
$states['repeat_revision_priced'] = $sync(true, '2008-09-11T00:00:00-05:00', [[
    'field_name' => 'date_quoted', 'before' => '2008-09-11T00:00:00-05:00', 'after' => '2008-09-11T00:00:00-05:00',
]]);

$notificationRows = [];
foreach ($GLOBALS['notifications'] as $notification) {
    $notificationRows[] = [
        'id' => $notification->id,
        'sync_key' => $notification->sync_key,
        'recipient' => $notification->assigned_user_id,
        'parent_type' => $notification->parent_type,
        'parent_id' => $notification->parent_id,
    ];
}

echo json_encode([
    'states' => $states,
    'notifications' => $notificationRows,
    'quote_save_changes' => $GLOBALS['quote_save_changes'],
    'warnings' => array_values(array_filter(
        $GLOBALS['log']->rows,
        fn ($row) => $row[0] === 'warn'
    )),
]);
