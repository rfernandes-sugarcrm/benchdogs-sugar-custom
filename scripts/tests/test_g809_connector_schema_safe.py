"""G809: the five values ADM requires on a quote are required IN THE BROWSER
ONLY, so the connector's own Quote writes can never be refused for them.

WHY THIS FILE EXISTS. The connector does not only write through Sugar's REST
API (which does not enforce `required` at all: SugarBeanApiHelper::
populateFromApi() validates only the submitted fields). It also CHECKS each
outgoing payload against Sugar's SERVED module metadata first:
`SugarSellClient.describe_module()` (erp-integration-core,
connector_core/gateway/clients/sugar_sell.py) turns every served
`required: true` on a non-relate field into a required FieldSpec, and
`connector_base.schema_meta.check_payload()` then reports a payload that lacks
it as `missing_required` - a Quote create the ERP quote sync sends without a
Reference would be refused and dead-lettered. So "required" for G809 must never
reach the served vardef:

  - Lead Source, Lead Type, Campaign and Event use ERP-Core's
    `erp_required_until_synced` (a key only erp-dependent-enum reads, in the
    browser); their served `required` stays unset;
  - Reference uses a view Dependency (Ext/Dependencies, hooks 'edit'), not
    `required` + `required_formula` on the vardef - that pair would have been
    SERVED as required: true.

IF IT WERE BROKEN you would see:
  test_served_quote_fields_are_not_required   FAIL - a shipped vardef makes one
      of the five (or the Account's two group fields) served-required;
  test_the_connectors_own_check_passes_a_quote_without_them  FAIL - the REAL
      describe_module + check_payload refuse a connector-shaped Quote create
      that carries none of them (and its CONTROL, the rejected vardef design,
      shows that refusal is what the check does);
  ReferenceFormulaInSugarsOwnParser  FAIL - the Reference formula is true when
      it should not be (an ERP quote, an Ophir quote, a ship-to with a city or
      a state) or false when it should be.

🔒 2085b adds the Accounts twin: Cust. Group (bd_customer_group_code) is
required in the edit views on an ADM Customer not yet in the ERP, through
Ext/Dependencies (never served `required` or `required_formula`):
  test_2085b_the_group_rule_is_a_view_dependency...  FAIL - the rule is gone,
      aimed at another field, or would run on a server save;
  test_2085b_the_connectors_own_check_passes_an_account_without_a_group  FAIL -
      an Account write without a group is refused by the connector;
  GroupFormulaInSugarsOwnParser  FAIL - the formula blocks a Prospect/Suspect,
      an ERP account or an Ophir/EPIC06 customer, or misses the ADM Customer.
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import unittest
from pathlib import Path

import shared_sugar
from bd_retirement import PKG
from test_g450_suspect_account_type import code_only, sugar_order

FIVE = ["erp_reference", "bd_lead_source", "bd_lead_type", "bd_marketing_campaign", "bd_marketing_event"]
ACCOUNT_TWO = ["bd_customer_group", "bd_customer_group_code"]
DEPENDENCY = PKG / "custom/Extension/modules/Quotes/Ext/Dependencies/bd_adm_reference_required.php"
#: 🔒 2085b: the Accounts twin - Cust. Group required on an ADM Customer not yet in the ERP.
GROUP_DEPENDENCY = PKG / "custom/Extension/modules/Accounts/Ext/Dependencies/bd_adm_customer_group_required.php"

MERGE = r"""<?php
$plan = json_decode(file_get_contents('php://stdin'), true);
$dictionary = [];
foreach ($plan['files'] as $f) { include $f; }
$out = [];
foreach ($plan['want'] as $object => $names) {
    foreach ($names as $n) { $out[$object][$n] = $dictionary[$object]['fields'][$n] ?? null; }
}
echo json_encode($out);
"""


def served_fields() -> dict:
    """The merged vardefs Sugar serves as module metadata (MetaDataManager
    serves the merged `fields` as they are), from the REAL files: ERP-Core's
    erp_reference.php (sibling checkout, else the pin) and every Bench Quotes
    and Accounts vardef fragment, in Sugar's merge order (core first, then
    Bench; `_override` last)."""
    core = str(shared_sugar.resolve("erp_reference.php"))
    bench = sorted((PKG / "custom/Extension/modules/Quotes/Ext/Vardefs").glob("*.php")) + sorted(
        (PKG / "custom/Extension/modules/Accounts/Ext/Vardefs").glob("*.php"))
    files = sugar_order([(core, 100)] + [(str(p), 200) for p in bench])
    plan = {"files": files, "want": {"Quote": FIVE, "Account": ACCOUNT_TWO}}
    out = subprocess.run(["php"], input=MERGE.replace("php://stdin", "plan.json"), text=True,
                         capture_output=True, cwd=_plan_dir(plan), check=True)
    return json.loads(out.stdout)


def _plan_dir(plan: dict) -> str:
    import tempfile
    d = tempfile.mkdtemp(prefix="g809-")
    Path(d, "plan.json").write_text(json.dumps(plan))
    return d


class ServedQuoteFieldsAreNotRequired(unittest.TestCase):
    def test_served_quote_fields_are_not_required(self):
        served = served_fields()
        for name in FIVE:
            self.assertIsNotNone(served["Quote"][name], f"{name} is not defined by the merged vardefs")
            self.assertFalse(served["Quote"][name].get("required"),
                             f"{name} would be SERVED required: the connector refuses a Quote without it")
            self.assertNotIn("required_formula", served["Quote"][name])
        for name in ACCOUNT_TWO:
            self.assertFalse(served["Account"][name].get("required"), name)
            # 🔒 2085b: nor a required_formula - ERP-Epicor's G496 order check
            # and the G805 price-step create read any served
            # 'equal($account_type,"Customer")' formula, with no ADM gate.
            self.assertNotIn("required_formula", served["Account"][name], name)

    def test_the_reference_rule_is_a_view_dependency_that_never_runs_on_save(self):
        text = code_only(DEPENDENCY)
        self.assertIn("'hooks' => array('edit'),", text)
        self.assertNotIn("'save'", text)
        self.assertNotIn("'all'", text)

    def test_2085b_the_group_rule_is_a_view_dependency_on_the_picker_that_never_runs_on_save(self):
        text = code_only(GROUP_DEPENDENCY)
        self.assertIn("$dependencies['Accounts']['bd_adm_customer_group_required']", text)
        self.assertIn("'hooks' => array('edit'),", text)
        self.assertNotIn("'save'", text)
        self.assertNotIn("'all'", text)
        self.assertIn("'target' => 'bd_customer_group_code',", text)
        self.assertIn("'name' => 'SetRequired',", text)
        # it re-evaluates when any input changes, the company relate included
        for trigger in ("account_type", "erp_display_sync_key", "erp_sync_key",
                        "erp_companies_accounts_name", "erp_companies_accountserp_companies_ida"):
            self.assertIn(f"'{trigger}',", text)


#: The connector's Python: an explicit BD_CORE_PYTHON, else the workspace's
#: validation venv (which imports the sibling erp-integration-core checkout).
CORE_PYTHON = os.environ.get("BD_CORE_PYTHON") or str(
    shared_sugar._workspace() / "validation-venv" / "bin" / "python")

RUN_CORE = r"""
import asyncio, json, sys
from types import SimpleNamespace
from connector_core.gateway.clients.sugar_sell import SugarSellClient
from connector_base.schema_meta import check_payload

served = json.load(sys.stdin)

class Resp:
    status_code = 200
    def __init__(self, body): self._body = body
    def json(self): return self._body

def describe(fields):
    # The REAL describe_module, with only the HTTP round trip stood in for.
    class Probe(SugarSellClient):
        _api_root = "https://tenant.invalid/rest/v11_4"
        def _ensure_client(self): return SimpleNamespace(get=None)
        async def _headers(self, _c): return {}
        async def _request_with_retry(self, _c, _fn, _url, **_kw):
            return Resp({"modules": {"Quotes": {"fields": fields}}})
        def _raise_for_status(self, *_a, **_k): return None
    return asyncio.run(object.__new__(Probe).describe_module("Quotes"))

# The five from the SHIPPED vardefs; the other fields are a stand-in for the
# rest of the Quotes metadata a connector create touches (not required).
base = {n: {"name": n, "type": "varchar"} for n in ("id", "name", "erp_sync_key", "erp_display_sync_key", "quote_stage")}
fields = dict(base, **served)
payload = {"name": "ADM quote 9001", "erp_sync_key": "ADM__9001", "erp_display_sync_key": "9001", "quote_stage": "Draft"}
ok = [v.__dict__ if hasattr(v, "__dict__") else str(v) for v in check_payload(payload, describe(fields))]
rejected = dict(fields)
rejected["erp_reference"] = dict(fields["erp_reference"], required=True, required_formula="true")
control = [getattr(v, "field", None) + ":" + getattr(v, "kind", "") for v in check_payload(payload, describe(rejected))]
print(json.dumps({"violations": [str(v) for v in ok], "control": control}))
"""


@unittest.skipUnless(Path(CORE_PYTHON).is_file(),
                     "needs the connector's Python (BD_CORE_PYTHON or the workspace validation-venv); "
                     "CI has no erp-integration-core")
class TheConnectorsOwnSchemaCheck(unittest.TestCase):
    def test_2085b_the_connectors_own_check_passes_an_account_without_a_group(self):
        """The erp_customers sweep and the seed write Accounts through the same
        check: an account payload with no group must still pass, and the
        CONTROL (the same field served required) is refused."""
        served = served_fields()["Account"]
        out = subprocess.run([CORE_PYTHON, "-c", RUN_CORE_ACCOUNTS], input=json.dumps(served), text=True,
                             capture_output=True)
        self.assertEqual(out.returncode, 0, out.stderr[-2000:])
        result = json.loads(out.stdout.strip().splitlines()[-1])
        self.assertEqual(result["violations"], [])
        self.assertIn("bd_customer_group_code:missing_required", result["control"])

    def test_the_connectors_own_check_passes_a_quote_without_them(self):
        served = served_fields()["Quote"]
        out = subprocess.run([CORE_PYTHON, "-c", RUN_CORE], input=json.dumps(served), text=True,
                             capture_output=True)
        self.assertEqual(out.returncode, 0, out.stderr[-2000:])
        result = json.loads(out.stdout.strip().splitlines()[-1])
        self.assertEqual(result["violations"], [],
                         "the connector's check_payload refuses a Quote create without the five")
        # CONTROL: the design this build rejected (vardef required + required_formula
        # on erp_reference) IS refused by the same check - so the pass above is
        # the shipped vardefs' doing, not a check that never refuses.
        self.assertIn("erp_reference:missing_required", result["control"])


RUN_CORE_ACCOUNTS = RUN_CORE.replace('"Quotes"', '"Accounts"').replace(
    'payload = {"name": "ADM quote 9001", "erp_sync_key": "ADM__9001", "erp_display_sync_key": "9001", "quote_stage": "Draft"}',
    'payload = {"name": "LENEXA DISPLAYS", "erp_sync_key": "ADM__1668", "erp_display_sync_key": "1668", "quote_stage": "x"}',
).replace(
    'rejected["erp_reference"] = dict(fields["erp_reference"], required=True, required_formula="true")',
    'rejected["bd_customer_group_code"] = dict(fields["bd_customer_group_code"], required=True)',
)
assert RUN_CORE_ACCOUNTS.count('"Accounts"') >= 2 and "LENEXA" in RUN_CORE_ACCOUNTS and "bd_customer_group_code" in RUN_CORE_ACCOUNTS

TREES = [p for p in (Path.home() / "Documents/Code" / f"SugarEnt-Full-{v}" for v in ("26.1.0", "25.2.0"))
         if (p / "include/Expressions/Expression/Parser/Parser.php").is_file()]
#: The parser reads its function map from the tree's cache; a tree without a
#: built map (25.2.0 here) would rebuild it through the full application.
PARSER_TREES = [t for t in TREES if (t / "cache/Expressions/functionmap.php").is_file()]

EVALUATE = r"""<?php
chdir($argv[1]);
define('sugarEntry', true);
spl_autoload_register(function ($c) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('include/Expressions/Expression',
        FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->getFilename() === $c . '.php') { require_once $f->getPathname(); return; } }
});
function sugar_cached($p) { return 'cache/' . $p; }
function safeCount($a) { return is_countable($a) ? count($a) : 0; }
class TimeDate { static function getInstance() { return new self(); } }
$GLOBALS['log'] = new class { function __call($n, $a) {} };
#[AllowDynamicProperties] class FakeQuote { public $field_defs = []; }
$dependencies = [];
include $argv[2];
$formula = $dependencies['Quotes']['bd_adm_reference_required']['actions'][0]['params']['value'];
$out = [];
foreach (json_decode($argv[3], true) as $label => $case) {
    $q = new FakeQuote();
    foreach (['erp_display_sync_key' => 'varchar', 'bd_lead_source' => 'enum',
              'shipping_address_city' => 'varchar', 'shipping_address_state' => 'varchar'] as $n => $t) {
        $q->field_defs[$n] = ['name' => $n, 'type' => $t];
        $q->$n = $case[$n] ?? '';
    }
    $r = Parser::evaluate($formula, $q)->evaluate();
    $out[$label] = $r === AbstractExpression::$TRUE;
}
echo json_encode($out);
"""

CASES = {
    # 8972 at the refusal: not in the ERP, ADM, the ship-to had no city/state
    "8972 before its ship-to had an address": ({"bd_lead_source": "ADVERTISE"}, True),
    "the same quote once in the ERP": ({"erp_display_sync_key": "8719", "bd_lead_source": "ADVERTISE"}, False),
    "Ophir/EPIC06 (no Lead Source can be picked)": ({}, False),
    "a ship-to with a city (the default fills it)": ({"bd_lead_source": "E-MAIL",
                                                      "shipping_address_city": "LENEXA"}, False),
    "a ship-to with a state (the default fills it)": ({"bd_lead_source": "E-MAIL",
                                                       "shipping_address_state": "KS"}, False),
    "whitespace-only key is empty in SugarLogic too": ({"erp_display_sync_key": "", "bd_lead_source": "E-MAIL"}, True),
}


EVALUATE_GROUP = r"""<?php
chdir($argv[1]);
define('sugarEntry', true);
spl_autoload_register(function ($c) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('include/Expressions/Expression',
        FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->getFilename() === $c . '.php') { require_once $f->getPathname(); return; } }
});
function sugar_cached($p) { return 'cache/' . $p; }
function safeCount($a) { return is_countable($a) ? count($a) : 0; }
class TimeDate { static function getInstance() { return new self(); } }
$GLOBALS['log'] = new class { function __call($n, $a) {} };
#[AllowDynamicProperties] class FakeCompany {
    public $field_defs = ['erp_sync_key' => ['name' => 'erp_sync_key', 'type' => 'varchar']];
    function checkUserAccess() { return true; }
}
class Link2 { public $beans; function __construct($b) { $this->beans = $b; } function getBeansForSugarLogic() { return $this->beans; } }
#[AllowDynamicProperties] class FakeAccount {
    public $field_defs = [];
    function load_relationship($n) { return isset($this->$n); }
}
$dependencies = [];
include $argv[2];
$formula = $dependencies['Accounts']['bd_adm_customer_group_required']['actions'][0]['params']['value'];
$out = [];
foreach (json_decode($argv[3], true) as $label => $case) {
    $a = new FakeAccount();
    foreach (['account_type' => 'enum', 'erp_display_sync_key' => 'varchar', 'erp_sync_key' => 'varchar'] as $n => $t) {
        $a->field_defs[$n] = ['name' => $n, 'type' => $t];
        $a->$n = $case[$n] ?? '';
    }
    $a->field_defs['erp_companies_accounts'] = ['name' => 'erp_companies_accounts', 'type' => 'link'];
    $beans = [];
    if (isset($case['company'])) {
        $c = new FakeCompany();
        $c->erp_sync_key = $case['company'];
        $beans = ['co-1' => $c];
    }
    $a->erp_companies_accounts = new Link2($beans);
    $out[$label] = Parser::evaluate($formula, $a)->evaluate() === AbstractExpression::$TRUE;
}
echo json_encode($out);
"""

GROUP_CASES = {
    "a NEW ADM Customer, not in the ERP (the rule)": ({"account_type": "Customer", "company": "ADM"}, True),
    "an ADM Prospect kept in Sugar": ({"account_type": "Prospect", "company": "ADM"}, False),
    "an ADM Suspect kept in Sugar": ({"account_type": "Suspect", "company": "ADM"}, False),
    "an ADM Customer already in the ERP (display key)": (
        {"account_type": "Customer", "company": "ADM", "erp_display_sync_key": "1668"}, False),
    "an ADM Customer already in the ERP (scoped key only)": (
        {"account_type": "Customer", "company": "ADM", "erp_sync_key": "ADM__1668"}, False),
    "Ophir/stock: an EPIC06 Customer, not in the ERP (no groups to pick)": (
        {"account_type": "Customer", "company": "EPIC06"}, False),
    "a Customer with no ERP company yet (ERP-Core requires one first)": ({"account_type": "Customer"}, False),
}


class GroupFormulaInSugarsOwnParser(unittest.TestCase):
    """🔒 2085b: the Cust. Group formula, evaluated by SugarCRM's own SugarLogic
    Parser with related() over the account's ERP company link."""

    requires_sugarent_tree = True

    def test_the_group_formula_in_sugars_own_parser(self):
        self.assertTrue(PARSER_TREES, "no SugarEnt tree with a built SugarLogic function map")
        php = shutil.which("php")
        for tree in PARSER_TREES:
            out = subprocess.run([php, "-d", "error_reporting=E_ALL & ~E_DEPRECATED", "-r",
                                  EVALUATE_GROUP.replace("<?php", "", 1), "--", str(tree), str(GROUP_DEPENDENCY),
                                  json.dumps({k: v[0] for k, v in GROUP_CASES.items()})],
                                 capture_output=True, text=True)
            self.assertEqual(out.returncode, 0, out.stdout[-1500:] + out.stderr[-1500:])
            got = json.loads(out.stdout.strip().splitlines()[-1])
            self.assertEqual(got, {k: v[1] for k, v in GROUP_CASES.items()}, tree.name)


class ReferenceFormulaInSugarsOwnParser(unittest.TestCase):
    requires_sugarent_tree = True

    def test_the_formula_in_sugars_own_parser(self):
        self.assertTrue(PARSER_TREES, "no SugarEnt tree with a built SugarLogic function map")
        php = shutil.which("php")
        with_open = {k: v[0] for k, v in CASES.items()}
        for tree in PARSER_TREES:
            out = subprocess.run([php, "-d", "error_reporting=E_ALL & ~E_DEPRECATED", "-r",
                                  EVALUATE.replace("<?php", "", 1), "--", str(tree), str(DEPENDENCY),
                                  json.dumps(with_open)], capture_output=True, text=True)
            self.assertEqual(out.returncode, 0, out.stdout[-1500:] + out.stderr[-1500:])
            got = json.loads(out.stdout.strip().splitlines()[-1])
            self.assertEqual(got, {k: v[1] for k, v in CASES.items()}, tree.name)

    def test_sugar_never_refuses_a_save_for_it(self):
        """Platform facts, read where they live: the server-side SetRequired only
        flips field_defs; a module Dependency fires on a save only with an
        'all' or 'save' hook; the REST save path does not check `required`."""
        self.assertTrue(TREES, "no SugarEnt tree")
        for tree in TREES:
            action = (tree / "include/Expressions/Actions/SetRequiredAction.php").read_text()
            fire = action[action.index("public function fire"):action.index("public static function getActionName")]
            self.assertIn("['required'] = ", fire)
            self.assertNotIn("throw", fire)
            manager = (tree / "include/Expressions/DependencyManager.php").read_text()
            self.assertIn("if (safeInArray('all', $hooks) || safeInArray($action, $hooks)) {", manager)
            helper = (tree / "data/SugarBeanApiHelper.php").read_text()
            self.assertNotIn("'required'", helper)
            self.assertIn("->apiValidate($bean, $submittedData, $fieldName, $properties)", helper)


if __name__ == "__main__":
    unittest.main()
