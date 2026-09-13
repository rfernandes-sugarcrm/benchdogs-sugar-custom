"""Exercise the Bench estimating route against the shared action boundary."""

from __future__ import annotations

import base64
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
ENDPOINT = ROOT / "sugar-sell/BenchDogs-Ext/custom/clients/base/api/BdBenchDogsActionsApi.php"

HARNESS = r"""<?php
namespace Sugarcrm\Sugarcrm\Util\Files {
    class FileLoader { public static function validateFilePath($path) { return $path; } }
}
namespace {
class ServiceBase {}
class SugarBean {}
class SugarApiExceptionNotFound extends Exception {}
class SugarApiExceptionNotAuthorized extends Exception {}
class SugarApiExceptionInvalidParameter extends Exception {}
class TestQuote extends SugarBean {
    public $id = 'quote-1';
    public $bd_erp_stage = INITIAL_STAGE;
    public $erp_display_sync_key = INITIAL_ERP_ID;
    public $erp_sync_key = INITIAL_SCOPED_ID;
    public $saves = 0;
    public function save() {
        $this->saves++;
        if (THROW_SAVE) throw new Exception('private database details');
        return SAVE_RESULT ? $this->id : false;
    }
}
class TestErpQuote extends SugarBean {
    public $id = '';
    public $name = '';
    public $quote_num = 0;
    public $erp_sync_key = '';
    public $sugar_quote_id = '';
    public $bd_sent_to_estimating_at = '';
    public $saves = 0;
    public function save() {
        $this->saves++;
        if ($this->id === '') $this->id = 'created-' . count($GLOBALS['new_beans']);
        if (!ERP_SAVE_RESULT) return false;
        $GLOBALS['erp_beans'][$this->id] = $this;
        if (ERP_LOSE_PERSISTENCE) $this->bd_sent_to_estimating_at = '';
        return $this->id;
    }
}
if (LOAD_NOTIFICATION_HOOK) {
    class BdEstimatingNotificationHook {
        public static function consumeEstimatingOutcome($quoteId) {
            $GLOBALS['notification_consumes'][] = $quoteId;
            return [
                'status' => NOTIFICATION_STATUS,
                'message' => NOTIFICATION_MESSAGE,
            ];
        }
    }
}
class BeanFactory {
    public static function retrieveBean($module, $id, $options = []) {
        $GLOBALS['retrievals'][] = [$module, $id, $options];
        if ($module === 'Quotes') {
            $GLOBALS['quote_retrievals']++;
            if (LOSE_PERSISTENCE && $GLOBALS['quote_retrievals'] >= 3) {
                $GLOBALS['quote']->bd_erp_stage = 'draft';
            }
            return $GLOBALS['quote'];
        }
        return $GLOBALS['erp_beans'][$id] ?? null;
    }
    public static function newBean($module) {
        $bean = new TestErpQuote();
        if ($module === 'bd01_ERP_Quote') $GLOBALS['new_beans'][] = $bean;
        return $bean;
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
        foreach ($GLOBALS['erp_beans'] as $id => $bean) {
            if ($bean->erp_sync_key === $GLOBALS['query_key']) $rows[] = ['id' => $id];
        }
        return array_slice($rows, 0, 2);
    }
}
class TimeDate {
    public static function getInstance() { return new self(); }
    public function nowDb() { return '2026-09-12 12:34:56'; }
}
class TestLog { public function info($message) {} public function warn($message) {} public function error($message) {} }
$GLOBALS['log'] = new TestLog();
$GLOBALS['quote'] = new TestQuote();
$GLOBALS['retrievals'] = [];
$GLOBALS['quote_retrievals'] = 0;
$GLOBALS['delegated'] = [];
$GLOBALS['erp_beans'] = [];
$GLOBALS['new_beans'] = [];
$GLOBALS['query_key'] = '';
$GLOBALS['notification_consumes'] = [];
$mirrorMode = MIRROR_MODE;
if ($mirrorMode === 'two_companies') {
    $other = new TestErpQuote(); $other->id = 'other'; $other->quote_num = 1201; $other->erp_sync_key = 'OTHER__1201';
    $exact = new TestErpQuote(); $exact->id = 'exact'; $exact->quote_num = 1201; $exact->erp_sync_key = 'EPIC06__1201';
    $GLOBALS['erp_beans'] = ['other' => $other, 'exact' => $exact];
} elseif ($mirrorMode === 'duplicate_exact') {
    $a = new TestErpQuote(); $a->id = 'a'; $a->quote_num = 1201; $a->erp_sync_key = 'EPIC06__1201';
    $b = new TestErpQuote(); $b->id = 'b'; $b->quote_num = 1201; $b->erp_sync_key = 'EPIC06__1201';
    $GLOBALS['erp_beans'] = ['a' => $a, 'b' => $b];
}
$GLOBALS['delegate_result'] = DELEGATE_RESULT;
require 'custom/clients/base/api/BdBenchDogsActionsApi.php';
$endpoint = new BdBenchDogsActionsApi();
$routes = $endpoint->registerApiRest();
$results = [];
for ($i = 0; $i < CALLS; $i++) {
    $results[] = $endpoint->sendToEstimating(new ServiceBase(), ['record' => 'quote-1', 'caller' => 'bench']);
}
$erp = [];
$seenErp = [];
foreach (array_merge(array_values($GLOBALS['erp_beans']), $GLOBALS['new_beans']) as $bean) {
    if ($bean->erp_sync_key === '') continue;
    if (isset($seenErp[$bean->id])) continue;
    $seenErp[$bean->id] = true;
    $erp[] = ['id' => $bean->id, 'key' => $bean->erp_sync_key,
              'stamp' => $bean->bd_sent_to_estimating_at, 'saves' => $bean->saves];
}
echo json_encode([
    'route_method' => $routes['bdSendToEstimating']['method'] ?? '',
    'has_best_pricing_route' => isset($routes['bdBestPricing']),
    'delegated' => $GLOBALS['delegated'],
    'result' => $results[0],
    'results' => $results,
    'stage' => $GLOBALS['quote']->bd_erp_stage,
    'saves' => $GLOBALS['quote']->saves,
    'retrievals' => $GLOBALS['retrievals'],
    'erp' => $erp,
    'notification_consumes' => $GLOBALS['notification_consumes'],
]);
}
"""

BASE_STUB = "<?php\nclass BaseErpActionsApi {}\n"
SHARED_STUB = r"""<?php
class QuotesErpActionsApi extends BaseErpActionsApi {
    public function runErpAction(ServiceBase $api, array $args): array {
        $GLOBALS['delegated'][] = $args;
        if (DELEGATE_DISPLAY_ID !== '') $GLOBALS['quote']->erp_display_sync_key = DELEGATE_DISPLAY_ID;
        if (DELEGATE_SCOPED_ID !== '') $GLOBALS['quote']->erp_sync_key = DELEGATE_SCOPED_ID;
        return $GLOBALS['delegate_result'];
    }
}
"""


class EstimatingEntrypointTest(unittest.TestCase):
    def execute(
        self,
        result: dict,
        *,
        initial_stage: str = "draft",
        initial_erp_id: str = "",
        initial_scoped_id: str = "",
        delegate_display_id: str = "1201",
        delegate_scoped_id: str = "EPIC06__1201",
        mirror_mode: str = "none",
        calls: int = 1,
        erp_save_result: bool = True,
        erp_lose_persistence: bool = False,
        save_result: bool = True,
        throw_save: bool = False,
        lose_persistence: bool = False,
        load_notification_hook: bool = True,
        notification_status: str = "created",
        notification_message: str = "Sugar created the in-app notification.",
    ) -> dict:
        with tempfile.TemporaryDirectory(prefix="bench-estimating-") as tmp:
            target = Path(tmp)
            api_dir = target / "custom/clients/base/api"
            api_dir.mkdir(parents=True)
            shutil.copy2(ENDPOINT, api_dir / ENDPOINT.name)
            (api_dir / "BaseErpActionsApi.php").write_text(BASE_STUB, encoding="utf-8")
            encoded = base64.b64encode(json.dumps(result).encode("utf-8")).decode("ascii")
            code = HARNESS.replace(
                "DELEGATE_RESULT", f"json_decode(base64_decode('{encoded}'), true)"
            )
            replacements = {
                "INITIAL_STAGE": repr(initial_stage),
                "INITIAL_ERP_ID": repr(initial_erp_id),
                "INITIAL_SCOPED_ID": repr(initial_scoped_id),
                "DELEGATE_DISPLAY_ID": repr(delegate_display_id),
                "DELEGATE_SCOPED_ID": repr(delegate_scoped_id),
                "MIRROR_MODE": repr(mirror_mode),
                "CALLS": str(calls),
                "ERP_SAVE_RESULT": "true" if erp_save_result else "false",
                "ERP_LOSE_PERSISTENCE": "true" if erp_lose_persistence else "false",
                "SAVE_RESULT": "true" if save_result else "false",
                "THROW_SAVE": "true" if throw_save else "false",
                "LOSE_PERSISTENCE": "true" if lose_persistence else "false",
                "LOAD_NOTIFICATION_HOOK": "true" if load_notification_hook else "false",
                "NOTIFICATION_STATUS": repr(notification_status),
                "NOTIFICATION_MESSAGE": repr(notification_message),
            }
            for needle, replacement in replacements.items():
                code = code.replace(needle, replacement)
            shared_stub = SHARED_STUB
            for needle, replacement in replacements.items():
                shared_stub = shared_stub.replace(needle, replacement)
            (api_dir / "QuotesErpActionsApi.php").write_text(
                shared_stub, encoding="utf-8"
            )
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
            completed = subprocess.run(command, input=code, text=True, cwd=cwd,
                                       capture_output=True)
            if completed.returncode != 0:
                self.fail(
                    f"PHP harness failed ({completed.returncode}): "
                    f"stdout={completed.stdout!r} stderr={completed.stderr!r}"
                )
            return json.loads(completed.stdout)

    def test_route_delegates_to_shared_advanced_quote_and_then_stages_fresh_quote(self):
        response = {"status": "success", "message": "created", "erp_id": "1201"}
        observed = self.execute(response)
        self.assertEqual(observed["route_method"], "sendToEstimating")
        self.assertFalse(observed["has_best_pricing_route"])
        self.assertEqual(observed["delegated"], [{
            "record": "quote-1", "caller": "bench", "action": "advanced_quote",
        }])
        self.assertEqual(observed["result"]["status"], "success")
        self.assertEqual(observed["result"]["erp_id"], "1201")
        self.assertEqual(observed["result"]["estimating_timestamp_status"], "stamped")
        self.assertEqual(observed["result"]["erp_handoff_status"], "completed")
        self.assertEqual(observed["result"]["notification_status"], "created")
        self.assertEqual(observed["notification_consumes"], ["quote-1"])
        self.assertEqual(observed["stage"], "in_estimating")
        self.assertEqual(observed["saves"], 1)
        self.assertEqual(observed["retrievals"], [
            ["Quotes", "quote-1", {"use_cache": False}],
            ["Quotes", "quote-1", {"use_cache": False}],
            ["Quotes", "quote-1", {"use_cache": False}],
            ["bd01_ERP_Quote", "created-2", {"use_cache": False}],
        ])
        self.assertEqual(observed["erp"], [{
            "id": "created-2", "key": "EPIC06__1201",
            "stamp": "2026-09-12 12:34:56", "saves": 1,
        }])

    def test_notification_failure_remains_a_successful_non_retryable_erp_handoff(self):
        response = {"status": "success", "message": "created", "erp_id": "1201"}
        observed = self.execute(
            response,
            notification_status="save_failed",
            notification_message="Use the In Estimating view.",
        )
        self.assertEqual(observed["result"]["status"], "success")
        self.assertEqual(observed["result"]["erp_handoff_status"], "completed")
        self.assertEqual(observed["result"]["notification_status"], "save_failed")
        self.assertEqual(
            observed["result"]["notification_message"],
            "Use the In Estimating view.",
        )
        self.assertNotIn("retry_safe", observed["result"])

    def test_missing_notification_hook_is_visible_without_changing_erp_success(self):
        response = {"status": "success", "message": "created", "erp_id": "1201"}
        observed = self.execute(response, load_notification_hook=False)
        self.assertEqual(observed["result"]["status"], "success")
        self.assertEqual(observed["result"]["notification_status"], "not_observed")
        self.assertIn("did not report", observed["result"]["notification_message"])

    def test_shared_failure_is_returned_without_claiming_estimating_handoff(self):
        response = {"status": "error", "message": "ERP unavailable"}
        observed = self.execute(response)
        self.assertEqual(observed["result"], response)
        self.assertEqual(observed["stage"], "draft")
        self.assertEqual(observed["saves"], 0)
        self.assertEqual(observed["retrievals"], [[
            "Quotes", "quote-1", {"use_cache": False},
        ]])

    def test_direct_retry_preserves_a_priced_existing_erp_quote(self):
        response = {"status": "success", "message": "already exists", "erp_id": "1201"}
        observed = self.execute(
            response, initial_stage="priced", initial_erp_id="1201"
        )
        self.assertEqual(observed["result"], response)
        self.assertEqual(observed["stage"], "priced")
        self.assertEqual(observed["saves"], 0)
        self.assertEqual(len(observed["retrievals"]), 1)

    def test_failed_or_unproven_stage_save_refuses_misleading_success(self):
        response = {"status": "success", "message": "created", "erp_id": "1201"}
        scenarios = (
            {"save_result": False},
            {"throw_save": True},
            {"lose_persistence": True},
        )
        for scenario in scenarios:
            with self.subTest(scenario=scenario):
                observed = self.execute(response, **scenario)
                self.assertEqual(observed["result"]["status"], "error")
                self.assertIn("Kinetic quote was created", observed["result"]["message"])
                self.assertEqual(observed["result"]["erp_id"], "1201")
                self.assertTrue(observed["result"]["partial_success"])
                self.assertFalse(observed["result"]["retry_safe"])

    def test_exact_scoped_key_selects_right_company_when_bare_numbers_collide(self):
        response = {"status": "success", "message": "created", "erp_id": "1201"}
        observed = self.execute(response, mirror_mode="two_companies")
        self.assertEqual(observed["result"]["estimating_timestamp_status"], "stamped")
        by_key = {row["key"]: row for row in observed["erp"]}
        self.assertEqual(by_key["OTHER__1201"]["stamp"], "")
        self.assertEqual(by_key["OTHER__1201"]["saves"], 0)
        self.assertEqual(by_key["EPIC06__1201"]["stamp"], "2026-09-12 12:34:56")
        self.assertEqual(by_key["EPIC06__1201"]["saves"], 1)

    def test_missing_or_mismatched_scoped_identity_never_creates_a_mirror(self):
        response = {"status": "success", "message": "created", "erp_id": "1201"}
        for scoped in ("", "EPIC06__1202", "__1201"):
            with self.subTest(scoped=scoped):
                observed = self.execute(response, delegate_scoped_id=scoped)
                self.assertEqual(
                    observed["result"]["estimating_timestamp_status"],
                    "pending_exact_mirror",
                )
                self.assertEqual(observed["erp"], [])

    def test_duplicate_exact_scoped_identity_refuses_instead_of_guessing(self):
        response = {"status": "success", "message": "created", "erp_id": "1201"}
        observed = self.execute(response, mirror_mode="duplicate_exact")
        self.assertEqual(
            observed["result"]["estimating_timestamp_status"],
            "ambiguous_exact_mirror",
        )
        self.assertTrue(all(row["saves"] == 0 for row in observed["erp"]))

    def test_first_handoff_stamps_once_and_direct_retry_does_not_reset_it(self):
        response = {"status": "success", "message": "created", "erp_id": "1201"}
        observed = self.execute(response, calls=2)
        self.assertEqual(len(observed["results"]), 2)
        self.assertEqual(observed["results"][0]["estimating_timestamp_status"], "stamped")
        self.assertNotIn("estimating_timestamp_status", observed["results"][1])
        stamped = [row for row in observed["erp"] if row["stamp"]]
        self.assertEqual(len(stamped), 1)
        self.assertEqual(stamped[0]["stamp"], "2026-09-12 12:34:56")
        self.assertEqual(stamped[0]["saves"], 1)

    def test_mirror_save_or_readback_failure_never_reports_stamped(self):
        response = {"status": "success", "message": "created", "erp_id": "1201"}
        for scenario in (
            {"erp_save_result": False},
            {"erp_lose_persistence": True},
        ):
            with self.subTest(scenario=scenario):
                observed = self.execute(response, **scenario)
                self.assertEqual(
                    observed["result"]["estimating_timestamp_status"],
                    "pending_timestamp_persistence",
                )


class EntrypointSourceContractTest(unittest.TestCase):
    def test_no_broken_best_pricing_route_or_method_reference_remains(self):
        source = ENDPOINT.read_text(encoding="utf-8")
        self.assertNotIn("'bdBestPricing'", source)
        self.assertNotIn("'method' => 'bestPricingFromCatalog'", source)
        self.assertIn("(new QuotesErpActionsApi())->runErpAction", source)
        self.assertIn("$sharedArgs['action'] = 'advanced_quote';", source)
        self.assertNotIn("erpSyncKeyPrefix", source)
        self.assertNotIn("findErpQuoteByNumber", source)


if __name__ == "__main__":
    unittest.main()
