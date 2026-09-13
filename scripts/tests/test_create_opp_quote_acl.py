"""The Bench account action must not let a view-only user create sales records.

`POST Accounts/:record/bd-create-opp-quote` saves an Opportunity, a Quote, its
bundle and a placeholder line. It must apply the same gate as ERP-Epicor's
`AccountsErpActionsApi::createOppQuote`: edit access on the account plus save
access on Opportunities and Quotes, all checked before the first save.
"""

from __future__ import annotations

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
// Diagnostics go to stderr so stdout stays the JSON result.
ini_set('display_errors', 'stderr');
#[\AllowDynamicProperties]
class SugarBean {}
class SugarApiExceptionNotFound extends Exception {}
class SugarApiExceptionNotAuthorized extends Exception {}
class SugarApiExceptionInvalidParameter extends Exception {}
class TestUser { public $id = 'user-1'; }
class ServiceBase { public $user; public function __construct() { $this->user = new TestUser(); } }
class TestBean extends SugarBean {
    public $id = '';
    public $module = '';
    public $name = '';
    public function __construct($module) { $this->module = $module; }
    public function ACLAccess($action) {
        $GLOBALS['acl_checks'][] = [$this->module, $action];
        $grants = ACL_GRANTS;
        return in_array($this->module . ':' . $action, $grants, true);
    }
    public function save() {
        $this->id = strtolower($this->module) . '-' . (count($GLOBALS['saves']) + 1);
        $GLOBALS['saves'][] = $this->module;
        return $this->id;
    }
    public function load_relationship($name) { return false; }
}
class BeanFactory {
    public static function retrieveBean($module, $id, $options = []) {
        if ($module !== 'Accounts') return null;
        $account = new TestBean('Accounts');
        $account->id = $id;
        $account->name = 'Harbor Lane';
        return $account;
    }
    public static function newBean($module) { return new TestBean($module); }
}
$GLOBALS['acl_checks'] = [];
$GLOBALS['saves'] = [];
require 'custom/clients/base/api/BdBenchDogsActionsApi.php';
$endpoint = new BdBenchDogsActionsApi();
$outcome = ['status' => 'returned'];
try {
    $result = $endpoint->createOppQuote(new ServiceBase(), ['record' => 'account-1']);
    $outcome['result'] = $result;
} catch (SugarApiExceptionNotAuthorized $e) {
    $outcome = ['status' => 'not_authorized', 'message' => $e->getMessage()];
}
echo json_encode([
    'outcome' => $outcome,
    'saves' => $GLOBALS['saves'],
    'acl_checks' => $GLOBALS['acl_checks'],
]);
}
"""

BASE_STUB = "<?php\nclass BaseErpActionsApi {}\n"
FULL = ["Accounts:view", "Accounts:edit", "Opportunities:save", "Quotes:save"]


class CreateOppQuoteAclTest(unittest.TestCase):
    def execute(self, grants: list[str]) -> dict:
        with tempfile.TemporaryDirectory(prefix="bench-create-opp-quote-") as tmp:
            target = Path(tmp)
            api_dir = target / "custom/clients/base/api"
            api_dir.mkdir(parents=True)
            shutil.copy2(ENDPOINT, api_dir / ENDPOINT.name)
            (api_dir / "BaseErpActionsApi.php").write_text(BASE_STUB, encoding="utf-8")
            code = HARNESS.replace("ACL_GRANTS", "json_decode('" + json.dumps(grants) + "', true)")
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

    def test_view_only_account_access_creates_nothing(self):
        observed = self.execute(["Accounts:view", "Opportunities:save", "Quotes:save"])
        self.assertEqual(observed["outcome"]["status"], "not_authorized")
        self.assertEqual(observed["saves"], [])

    def test_missing_opportunity_create_access_creates_nothing(self):
        observed = self.execute(["Accounts:view", "Accounts:edit", "Quotes:save"])
        self.assertEqual(observed["outcome"]["status"], "not_authorized")
        self.assertEqual(observed["saves"], [])

    def test_missing_quote_create_access_creates_nothing(self):
        observed = self.execute(["Accounts:view", "Accounts:edit", "Opportunities:save"])
        self.assertEqual(observed["outcome"]["status"], "not_authorized")
        self.assertEqual(observed["saves"], [])

    def test_authorized_caller_creates_exactly_one_graph(self):
        observed = self.execute(FULL)
        self.assertEqual(observed["outcome"]["status"], "returned")
        self.assertEqual(observed["outcome"]["result"]["status"], "success")
        self.assertEqual(
            observed["saves"], ["Opportunities", "Quotes", "ProductBundles", "Products"]
        )
        self.assertIn(["Accounts", "edit"], observed["acl_checks"])


class CreateOppQuoteGateSourceTest(unittest.TestCase):
    def test_gate_matches_the_shared_account_action(self):
        source = ENDPOINT.read_text(encoding="utf-8")
        start = source.index("public function createOppQuote")
        body = source[start:source.index("public function sendToEstimating")]
        self.assertNotIn("ACLAccess('view')", body)
        self.assertIn("$account->ACLAccess('edit')", body)
        self.assertIn("BeanFactory::newBean('Opportunities')->ACLAccess('save')", body)
        self.assertIn("BeanFactory::newBean('Quotes')->ACLAccess('save')", body)
        # The gate precedes the first save.
        self.assertLess(body.index("ACLAccess('edit')"), body.index("->save()"))


if __name__ == "__main__":
    unittest.main()
