"""Exercise the shipped Bench estimating notification hook in PHP."""

from __future__ import annotations

import base64
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
HOOK = ROOT / "sugar-sell/BenchDogs-Ext/custom/modules/Quotes/BdEstimatingNotificationHook.php"

HARNESS = r"""<?php
class SugarBean {}
class TestLog {
    public $rows = [];
    public function info($message) { $this->rows[] = ['info', $message]; }
    public function warn($message) { $this->rows[] = ['warn', $message]; }
    public function error($message) { $this->rows[] = ['error', $message]; }
}
class SugarConfig {
    public static function getInstance() { return new self(); }
    public function get($key, $default = null) {
        return $GLOBALS['scenario']['config'][$key] ?? $default;
    }
}
class SugarCurrency {
    public static function formatAmountUserLocale($amount) { return '$' . number_format($amount, 2); }
}
class TestUser extends SugarBean {
    public $id = '';
    public $status = 'Active';
    public $sugar_login = 1;
    public $deleted = 0;
    public $is_group = 0;
    public $portal_only = 0;
    public $reports_to_id = '';
    public function __construct($id, $values = []) {
        $this->id = $id;
        foreach ($values as $key => $value) $this->{$key} = $value;
    }
}
class TestQuote extends SugarBean {
    public $id = 'quote-1';
    public $name = 'Engineered Bench';
    public $quote_num = 13;
    public $erp_display_sync_key = '1201';
    public $bd_erp_stage = 'in_estimating';
    public $bd_erp_total = 4200;
    public $assigned_user_id = 'assigned';
    public $created_by = 'creator';
    public $date_modified = '2026-09-12 12:34:56';
}
class TestNotification extends SugarBean {
    public $id = '';
    public $name = '';
    public $description = '';
    public $severity = '';
    public $is_read = 0;
    public $assigned_user_id = '';
    public $parent_type = '';
    public $parent_id = '';
    public $sync_key = '';
    public $deleted = 0;
    public function save($checkNotify = true) {
        $GLOBALS['save_args'][] = $checkNotify;
        $mode = $GLOBALS['scenario']['save_mode'] ?? 'success';
        if ($mode === 'race_throw') {
            $copy = clone $this;
            $copy->id = 'race-existing';
            $GLOBALS['notifications'][$copy->id] = $copy;
            throw new RuntimeException('duplicate key details');
        }
        if ($mode === 'throw') throw new RuntimeException('database details');
        if ($mode === 'false') return false;
        $this->id = 'notification-' . (count($GLOBALS['notifications']) + 1);
        $copy = clone $this;
        if (!empty($GLOBALS['scenario']['mismatch_readback'])) {
            $copy->assigned_user_id = 'different-user';
        }
        $GLOBALS['notifications'][$copy->id] = $copy;
        return $this->id;
    }
}
class BeanFactory {
    public static function newBean($module) {
        if ($module === 'Notifications') {
            if (!empty($GLOBALS['scenario']['factory_unavailable'])) return null;
            return new TestNotification();
        }
        return null;
    }
    public static function retrieveBean($module, $id, $options = []) {
        $GLOBALS['retrievals'][] = [$module, $id, $options];
        if ($module === 'Users') {
            if (!empty($GLOBALS['scenario']['throw_user_lookup'])) {
                throw new RuntimeException('private user lookup details');
            }
            return $GLOBALS['users'][$id] ?? null;
        }
        if ($module === 'Notifications') {
            if (!empty($GLOBALS['scenario']['lose_readback']) && str_starts_with($id, 'notification-')) {
                return null;
            }
            return $GLOBALS['notifications'][$id] ?? null;
        }
        return null;
    }
}
class SugarQueryWhere {
    public function equals($field, $value) { $GLOBALS['query_key'] = $value; return $this; }
}
class SugarQuery {
    public function select($fields) { return $this; }
    public function from($bean) { return $this; }
    public function where() { return new SugarQueryWhere(); }
    public function limit($limit) { return $this; }
    public function execute() {
        $rows = [];
        foreach ($GLOBALS['notifications'] as $id => $bean) {
            if ($bean->sync_key === $GLOBALS['query_key']) $rows[] = ['id' => $id];
        }
        return array_slice($rows, 0, 2);
    }
}

$GLOBALS['scenario'] = json_decode(base64_decode('SCENARIO'), true);
$GLOBALS['log'] = new TestLog();
$GLOBALS['save_args'] = [];
$GLOBALS['retrievals'] = [];
$GLOBALS['query_key'] = '';
$GLOBALS['notifications'] = [];
$GLOBALS['users'] = [];
foreach ($GLOBALS['scenario']['users'] ?? [] as $id => $values) {
    $GLOBALS['users'][$id] = new TestUser($id, $values);
}
require 'BdEstimatingNotificationHook.php';
$hook = new BdEstimatingNotificationHook();
$quote = new TestQuote();
foreach ($GLOBALS['scenario']['quote'] ?? [] as $key => $value) $quote->{$key} = $value;
$direction = $GLOBALS['scenario']['direction'] ?? 'estimating';
$calls = $GLOBALS['scenario']['calls'] ?? [[
    'before' => 'draft', 'after' => $quote->bd_erp_stage,
    'date_modified' => $quote->date_modified,
]];
$outcomes = [];
foreach ($calls as $call) {
    $quote->bd_erp_stage = $call['after'];
    $quote->date_modified = $call['date_modified'];
    $arguments = ['dataChanges' => [[
        'field_name' => 'bd_erp_stage',
        'before' => $call['before'],
        'after' => $call['after'],
    ]]];
    if ($direction === 'estimating') {
        $hook->notifyEstimating($quote, 'after_save', $arguments);
        $outcomes[] = BdEstimatingNotificationHook::consumeEstimatingOutcome($quote->id);
    } else {
        $hook->notifyPricingReturned($quote, 'after_save', $arguments);
    }
}
$notificationRows = [];
foreach ($GLOBALS['notifications'] as $bean) {
    $notificationRows[] = [
        'id' => $bean->id,
        'recipient' => $bean->assigned_user_id,
        'parent_type' => $bean->parent_type,
        'parent_id' => $bean->parent_id,
        'sync_key' => $bean->sync_key,
        'name' => $bean->name,
        'description' => $bean->description,
    ];
}
echo json_encode([
    'outcomes' => $outcomes,
    'notifications' => $notificationRows,
    'save_args' => $GLOBALS['save_args'],
    'retrievals' => $GLOBALS['retrievals'],
    'logs' => $GLOBALS['log']->rows,
]);
"""


class EstimatingNotificationTest(unittest.TestCase):
    def execute(self, **scenario):
        defaults = {
            "users": {
                "configured": {"status": "Active"},
                "assigned": {"status": "Active", "reports_to_id": "manager"},
                "manager": {"status": "Active"},
                "creator": {"status": "Active"},
            },
            "config": {},
        }
        defaults.update(scenario)
        encoded = base64.b64encode(json.dumps(defaults).encode()).decode()
        code = HARNESS.replace("SCENARIO", encoded)
        with tempfile.TemporaryDirectory(prefix="bench-notification-") as tmp:
            target = Path(tmp)
            shutil.copy2(HOOK, target / HOOK.name)
            if shutil.which("php"):
                command = ["php"]
                cwd = target
            elif shutil.which("docker"):
                command = [
                    "docker", "run", "--rm", "-i",
                    "-v", f"{target}:/work", "-w", "/work", "composer:2", "php",
                ]
                cwd = None
            else:
                self.skipTest("requires PHP 8.2 or the local composer:2 test image")
            completed = subprocess.run(
                command, input=code, text=True, cwd=cwd, capture_output=True
            )
            if completed.returncode != 0:
                self.fail(f"PHP failed: stdout={completed.stdout!r} stderr={completed.stderr!r}")
            return json.loads(completed.stdout)

    def test_configured_active_recipient_is_created_and_read_back(self):
        observed = self.execute(config={
            "benchdogs_ext.estimating_notify_user_id": "configured"
        })
        self.assertEqual(observed["outcomes"][0]["status"], "created")
        self.assertEqual(len(observed["notifications"]), 1)
        notification = observed["notifications"][0]
        self.assertEqual(notification["recipient"], "configured")
        self.assertEqual(notification["parent_type"], "Quotes")
        self.assertEqual(notification["parent_id"], "quote-1")
        self.assertTrue(notification["sync_key"].startswith("bdh:"))
        self.assertEqual(len(notification["sync_key"]), 68)
        self.assertEqual(observed["save_args"], [False])

    def test_invalid_config_is_authoritative_and_never_silently_reroutes(self):
        invalid_users = (
            None,
            {"status": "Inactive"},
            {"status": "Active", "sugar_login": None},
            {"status": "Active", "sugar_login": 0},
            {"status": "Active", "is_group": 1},
            {"status": "Active", "portal_only": 1},
        )
        for invalid in invalid_users:
            with self.subTest(invalid=invalid):
                users = {
                    "assigned": {"status": "Active", "reports_to_id": "manager"},
                    "manager": {"status": "Active"},
                    "creator": {"status": "Active"},
                }
                if invalid is not None:
                    users["configured"] = invalid
                observed = self.execute(
                    users=users,
                    config={"benchdogs_ext.estimating_notify_user_id": "configured"},
                )
                self.assertEqual(
                    observed["outcomes"][0]["status"],
                    "configured_recipient_invalid",
                )
                self.assertEqual(observed["notifications"], [])
                self.assertIn("did not reroute", observed["outcomes"][0]["message"])

    def test_login_disabled_configured_return_recipient_never_reroutes(self):
        observed = self.execute(
            users={
                "configured": {"status": "Active", "sugar_login": 0},
                "assigned": {"status": "Active", "sugar_login": 1},
                "creator": {"status": "Active", "sugar_login": 1},
            },
            config={"benchdogs_ext.pricing_notify_user_id": "configured"},
            direction="sales",
            quote={"bd_erp_stage": "priced"},
            calls=[{
                "before": "in_estimating", "after": "priced",
                "date_modified": "2026-09-12 13:00:00",
            }],
        )
        self.assertEqual(observed["notifications"], [])
        self.assertIn("configured_recipient_invalid", observed["logs"][-1][1])

    def test_missing_config_prefers_active_manager_then_active_owner(self):
        manager = self.execute()
        self.assertEqual(manager["notifications"][0]["recipient"], "manager")

        for unavailable_manager in (
            {"status": "Inactive"},
            {"status": "Active", "sugar_login": 0},
        ):
            with self.subTest(manager=unavailable_manager):
                users = {
                    "assigned": {
                        "status": "Active", "sugar_login": 1,
                        "reports_to_id": "manager",
                    },
                    "manager": unavailable_manager,
                    "creator": {"status": "Active", "sugar_login": 1},
                }
                owner = self.execute(users=users)
                self.assertEqual(owner["notifications"][0]["recipient"], "assigned")

    def test_dynamic_recipient_without_enabled_sugar_login_fails_closed(self):
        observed = self.execute(users={
            "assigned": {
                "status": "Active", "sugar_login": 0,
                "reports_to_id": "manager",
            },
            "manager": {"status": "Active", "sugar_login": 0},
            "creator": {"status": "Active", "sugar_login": 1},
        })
        self.assertEqual(observed["outcomes"][0]["status"], "recipient_unavailable")
        self.assertEqual(observed["notifications"], [])

        returned = self.execute(
            users={
                "assigned": {"status": "Active", "sugar_login": 0},
                "creator": {"status": "Active", "sugar_login": 0},
            },
            direction="sales",
            quote={"bd_erp_stage": "priced"},
            calls=[{
                "before": "in_estimating", "after": "priced",
                "date_modified": "2026-09-12 13:00:00",
            }],
        )
        self.assertEqual(returned["notifications"], [])
        self.assertIn("recipient_unavailable", returned["logs"][-1][1])

    def test_return_leg_skips_inactive_owner_and_uses_active_creator(self):
        users = {
            "assigned": {"status": "Inactive"},
            "creator": {"status": "Active"},
        }
        observed = self.execute(
            users=users,
            direction="sales",
            quote={"bd_erp_stage": "priced"},
            calls=[{
                "before": "in_estimating", "after": "priced",
                "date_modified": "2026-09-12 13:00:00",
            }],
        )
        self.assertEqual(observed["notifications"][0]["recipient"], "creator")
        self.assertIn("Kinetic quote 1201", observed["notifications"][0]["description"])

    def test_no_active_recipient_is_visible_but_non_throwing(self):
        observed = self.execute(users={
            "assigned": {"status": "Inactive", "reports_to_id": "manager"},
            "manager": {"status": "Inactive"},
        })
        self.assertEqual(observed["outcomes"][0]["status"], "recipient_unavailable")
        self.assertEqual(observed["notifications"], [])

    def test_false_throw_and_lost_readback_are_never_reported_created(self):
        for scenario, expected in (
            ({"save_mode": "false"}, "save_failed"),
            ({"save_mode": "throw"}, "save_failed"),
            ({"lose_readback": True}, "persistence_unconfirmed"),
            ({"mismatch_readback": True}, "persistence_unconfirmed"),
        ):
            with self.subTest(scenario=scenario):
                observed = self.execute(**scenario)
                self.assertEqual(observed["outcomes"][0]["status"], expected)

    def test_unique_sync_key_turns_a_save_race_into_already_created(self):
        observed = self.execute(save_mode="race_throw")
        self.assertEqual(observed["outcomes"][0]["status"], "already_created")
        self.assertEqual(len(observed["notifications"]), 1)

    def test_duplicate_event_reuses_one_notification_but_later_cycle_gets_another(self):
        same = {
            "before": "draft", "after": "in_estimating",
            "date_modified": "2026-09-12 12:34:56",
        }
        observed = self.execute(calls=[same, same])
        self.assertEqual(
            [row["status"] for row in observed["outcomes"]],
            ["created", "already_created"],
        )
        self.assertEqual(len(observed["notifications"]), 1)

        later = dict(same, date_modified="2026-09-13 08:00:00")
        observed = self.execute(calls=[same, later])
        self.assertEqual(len(observed["notifications"]), 2)
        self.assertEqual(len({row["sync_key"] for row in observed["notifications"]}), 2)

    def test_noop_and_initial_priced_backfill_do_not_notify(self):
        noop = self.execute(calls=[{
            "before": "in_estimating", "after": "in_estimating",
            "date_modified": "2026-09-12 12:34:56",
        }])
        self.assertEqual(noop["notifications"], [])
        self.assertEqual(noop["outcomes"][0]["status"], "not_observed")

        backfill = self.execute(
            direction="sales",
            quote={"bd_erp_stage": "priced"},
            calls=[{
                "before": "", "after": "priced",
                "date_modified": "2026-09-12 12:34:56",
            }],
        )
        self.assertEqual(backfill["notifications"], [])

    def test_lookup_exception_is_redacted_and_never_escapes_quote_save(self):
        observed = self.execute(throw_user_lookup=True)
        self.assertEqual(observed["outcomes"][0]["status"], "delivery_failed")
        self.assertEqual(observed["notifications"], [])
        log = observed["logs"][-1][1]
        self.assertIn("exception=RuntimeException", log)
        self.assertNotIn("private user lookup details", log)


class NotificationSourceContractTest(unittest.TestCase):
    def test_native_sync_key_save_false_and_readback_are_required(self):
        source = HOOK.read_text(encoding="utf-8")
        self.assertIn("$notification->sync_key = $syncKey;", source)
        self.assertIn("$notification->save(false)", source)
        self.assertIn("['use_cache' => false]", source)
        self.assertNotIn("$notification->save();", source)


if __name__ == "__main__":
    unittest.main()
