"""rc117/rc118: an account's billing street, city, postal code and country are required when a PERSON saves it, from
the create form as well as the record view, and never when the hub saves it.

WHY. Kinetic needs a billing address on a customer, so an account a seller creates in Sugar without one cannot be
sent. The accounts sync writes Kinetic customers into Sugar, and some of them have no billing address, so those
writes must never be refused.

WHICH ONE SUGAR ENFORCES ON REST (SugarEnt 26.1.0, read where it lives; the tree-only test
test_g809_connector_schema_safe.ReferenceFormulaInSugarsOwnParser.test_sugar_never_refuses_a_save_for_it pins these
platform facts from the source):
  - SugarBeanApiHelper::populateFromApi() never checks `required` (data/SugarBeanApiHelper.php:292-322);
  - DependencyManager runs a module dependency on a save only when its hooks hold 'all' or 'save';
  - SetRequiredAction::fire() on the server only flips field_defs, it refuses nothing.
So a vardef `required` would not stop a REST save either, but it is SERVED in the module metadata, and the hub's own
payload check (describe_module + check_payload) refuses a payload that lacks a served-required field. rc117's rule is
therefore a view dependency with the 'edit' hook only, the same shape as the Cust. Group rule.

RC118. Sugar serves an Accounts 'edit' dependency only in the record view's metadata, and Accounts has no create-view
dependencies, so rc117's rule never reached the create form (a Name-only account saved with no address). What does
reach every view is the module-level rule Sugar builds from a vardef `required_formula`
(DependencyManager::getRequiredFieldDependencies, SugarEnt 26.1.0: built when `required` is non-empty and
`required_formula` is set; a constant formula reads no field and fires on load). ERP-Core already ships that pair on
the five billing fields: `required` true and a formula that asks only a Customer not yet in the ERP. rc118 ships one
`_override` vardef that sets the formula of the four to 'true' and touches nothing else: `required` stays ERP-Core's,
so the hub's schema read (which reads `required` only) sees what it saw before, and State keeps ERP-Core's rule. Sugar
merges `_override` files after every other extension file (ModuleInstaller::sortExtensionFiles: is_override, then
mtime), so the override wins even when ERP-Core's files are newer (an ERP-Epicor upgrade after this package). At
ERP-Core 333d43be no other file of ERP-Core or ERP-Epicor touches a billing field (`git grep` over every package's
Accounts vardefs), so no later `_override` of theirs can put the old formula back. rc117's record-view rule stays: it
asks the same four, so it changes nothing beside the module rule.

IF IT WERE BROKEN you would see:
  test_the_rule_requires_the_four_billing_fields  FAIL - no rule (rc116), a field missing, State required too, or a
      condition that leaves a field optional;
  test_no_dependency_of_this_package_runs_on_a_server_save  FAIL - an 'all' or 'save' hook: the sync's account
      saves would run it;
  test_the_package_sets_only_the_formula_of_the_four  FAIL - a vardef of this package sets a billing field's
      `required` (the hub's schema read would change), puts a formula on State, or leaves one of the four out;
  test_a_rest_shaped_account_save_without_an_address_goes_through  FAIL - this package's account save hook refuses
      or changes an account the sync writes with no address.
  test_control_erp_core_alone_asks_only_a_customer_not_in_the_erp  FAIL - ERP-Core's real files no longer carry the
      pair the override relies on (`required` true, the Customer formula), so the override would do nothing;
  test_the_merged_vardefs_require_the_four_on_every_save  FAIL - the override is missing, is not an `_override` file
      (ERP-Core's newer file would win), sets anything but the formula, or changes State.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

import shared_sugar
from bd_retirement import PKG, ROOT
from test_g268_retired_stage_names import GET_EXTENSION_FILE_CONTENTS
from test_g450_suspect_account_type import sugar_order

RULE = PKG / "custom/Extension/modules/Accounts/Ext/Dependencies/bd_billing_address_required.php"
DEPENDENCIES = PKG / "custom/Extension/modules/Accounts/Ext/Dependencies"
VARDEFS = PKG / "custom/Extension/modules/Accounts/Ext/Vardefs"
#: rc118: the one vardef that makes the four required on every save in the browser.
OVERRIDE = VARDEFS / "_override_zz_bd_billing_address_required.php"
#: The UAT config package's Accounts vardefs, installed on the same tenants after this package.
UAT_VARDEFS = ROOT / "sugar-sell/BenchDogs-UAT-Config/custom/Extension/modules/Accounts/Ext/Vardefs"
FOUR = ["billing_address_street", "billing_address_city", "billing_address_postalcode", "billing_address_country"]
BILLING = FOUR + ["billing_address_state"]
#: ERP-Core's five real billing vardefs (the sibling checkout, else the pinned copy).
ERP_CORE_BILLING = [shared_sugar.resolve(f"{name}_required_formula.php") for name in BILLING]
#: ERP-Core's own rule on the five: required of a Customer the ERP does not hold yet.
ERP_CORE_FORMULA = 'and(equal($account_type,"Customer"),equal($erp_display_sync_key,""))'

LOAD_DEPENDENCIES = r"""
$dependencies = [];
foreach (array_slice($argv, 1) as $f) { include $f; }
echo json_encode($dependencies);
"""

LOAD_VARDEFS = r"""
$dictionary = [];
foreach (array_slice($argv, 1) as $f) { include $f; }
echo json_encode($dictionary['Account']['fields'] ?? new stdClass());
"""

#: The account a sync writes: a Kinetic customer with its keys and no billing address, saved as the connector.
REST_SAVE = r"""
namespace {
    class BeanFactory { public static function newBean($m) { return new stdClass(); } }
    class SugarQuery { public function __construct() { throw new RuntimeException('no query expected'); } }
    $GLOBALS['log'] = new class { public $lines = []; public function __call($l, $a) { $this->lines[] = $l . ': ' . $a[0]; } };
    require $argv[1];
    $bean = new stdClass();
    foreach (['id' => 'acct-1668', 'name' => 'LENEXA DISPLAYS', 'erp_sync_key' => 'ADM__1668',
              'erp_display_sync_key' => '1668', 'bd_customer_group_code' => 'DIST'] as $k => $v) { $bean->$k = $v; }
    $before = get_object_vars($bean);
    $hook = new \Sugarcrm\Sugarcrm\custom\BenchDogs\BdAdmRules();
    $hook->accountBeforeSave($bean, 'before_save', ['isUpdate' => false]);
    $hook->accountBeforeSave($bean, 'before_save', ['isUpdate' => true]);
    echo json_encode(['changed' => get_object_vars($bean) !== $before, 'errors' => $GLOBALS['log']->lines]);
}
"""


#: Sugar's compile of one module's vardef extensions: the fragments, already in Sugar's order, concatenated by
#: getExtensionFileContents (pinned to Sugar's source by test_g268) and included over the module's own defs.
COMPILE = r"""<?php
$problems = [];
set_error_handler(function ($no, $str, $file, $line) use (&$problems) {
    $problems[] = "$no $str @ " . basename($file) . ":$line";
    return true;
});
GET_EXTENSION_FILE_CONTENTS
$plan = json_decode(file_get_contents('plan.json'), true);
$compiled = getcwd() . '/vardefs.ext.php';
file_put_contents($compiled, getExtensionFileContents($plan['files']));
// The stock Account defs exist before the extensions are included; a name is enough to show nothing is replaced.
$dictionary = ['Account' => ['fields' => []]];
foreach ($plan['want'] as $n) { $dictionary['Account']['fields'][$n] = ['name' => $n]; }
include $compiled;
$out = [];
foreach ($plan['want'] as $n) { $out[$n] = $dictionary['Account']['fields'][$n]; }
echo json_encode(['fields' => $out, 'problems' => $problems]);
""".replace("GET_EXTENSION_FILE_CONTENTS", GET_EXTENSION_FILE_CONTENTS)


def compile_accounts_vardefs(fragments: list[tuple[Path, int]]) -> tuple[list[str], dict]:
    """Sort (path, mtime) fragments as Sugar does and compile them; the order and the five billing defs."""
    order = sugar_order([(str(path), mtime) for path, mtime in fragments])
    with tempfile.TemporaryDirectory(prefix="rc118-") as tmp:
        Path(tmp, "plan.json").write_text(json.dumps({"files": order, "want": BILLING}))
        out = subprocess.run(["php"], input=COMPILE, text=True, cwd=tmp, capture_output=True)
    if out.returncode != 0:
        raise AssertionError(out.stdout[-1500:] + out.stderr[-1500:])
    result = json.loads(out.stdout)
    assert result["problems"] == [], result["problems"]
    return order, result["fields"]


def php(code: str, *args: str) -> dict:
    out = subprocess.run(["php", "-r", code, "--", *args], capture_output=True, text=True)
    if out.returncode != 0:
        raise AssertionError(out.stdout[-1500:] + out.stderr[-1500:])
    return json.loads(out.stdout)


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheRuleIsTheEditViewsOnly(unittest.TestCase):
    def test_the_rule_requires_the_four_billing_fields(self):
        self.assertTrue(RULE.is_file(), f"{RULE} is not shipped")
        rule = php(LOAD_DEPENDENCIES, str(RULE))["Accounts"]["bd_billing_address_required"]
        self.assertEqual(rule["hooks"], ["edit"])
        self.assertTrue(rule["onload"], "set when the edit view opens, not only after a change")
        self.assertEqual(rule["trigger"], "true")
        required = {}
        for action in rule["actions"]:
            self.assertEqual(action["name"], "SetRequired")
            required[action["params"]["target"]] = action["params"]["value"]
        # Unconditional: a person cannot save any account without them.
        self.assertEqual(required, {name: "true" for name in FOUR})
        self.assertNotIn("billing_address_state", required, "the ask names street, city, postal code and country")

    def test_no_dependency_of_this_package_runs_on_a_server_save(self):
        files = sorted(str(p) for p in DEPENDENCIES.glob("*.php"))
        self.assertIn(str(RULE), files)
        for name, dep in php(LOAD_DEPENDENCIES, *files)["Accounts"].items():
            with self.subTest(dependency=name):
                # DependencyManager's own selection for a save: 'all' or 'save' among the hooks.
                hooks = dep.get("hooks", [])
                self.assertNotIn("all", hooks)
                self.assertNotIn("save", hooks)


@unittest.skipUnless(shutil.which("php"), "requires php")
class AServerSaveIsNeverRefused(unittest.TestCase):
    def test_the_package_sets_only_the_formula_of_the_four(self):
        """rc118: this package's vardefs set the four billing fields' `required_formula` to 'true' and nothing else.
        `required` itself is never set here: what the hub's schema read sees is ERP-Core's, unchanged."""
        served = php(LOAD_VARDEFS, *sorted(str(p) for p in VARDEFS.glob("*.php")))
        for name in FOUR:
            with self.subTest(field=name):
                self.assertEqual(served.get(name), {"required_formula": "true"},
                                 f"{name}: only its formula, made 'true'; never `required` (the hub reads that)")
        self.assertNotIn("billing_address_state", served, "the ask names street, city, postal code and country")
        # And no other file of the package marks one required by another route.
        for path in (PKG / "custom").rglob("*.php"):
            text = path.read_text(encoding="utf-8")
            for name in BILLING:
                self.assertNotIn(f"['{name}']['required']", text, path)

    def test_a_rest_shaped_account_save_without_an_address_goes_through(self):
        result = php(REST_SAVE, str(PKG / "custom/src/BenchDogs/BdAdmRules.php"))
        self.assertEqual(result["errors"], [], "the account save hook failed on an account with no address")
        self.assertFalse(result["changed"], "the account save hook changed an account the sync wrote")



@unittest.skipUnless(shutil.which("php"), "requires php")
class TheMergedVardefsRequireTheFourOnEverySave(unittest.TestCase):
    """rc118: ERP-Core's real billing vardefs and this package's, compiled in Sugar's extension order."""

    def test_control_erp_core_alone_asks_only_a_customer_not_in_the_erp(self):
        """What ERP-Core alone serves: the pair Sugar turns into a module rule, asking only a Customer not yet in
        the ERP. This is the rule the create form already applies, and the one the override changes."""
        _, alone = compile_accounts_vardefs([(path, 100) for path in ERP_CORE_BILLING])
        for name in BILLING:
            with self.subTest(field=name):
                self.assertTrue(alone[name].get("required"), f"{name}: ERP-Core no longer sets `required`")
                self.assertEqual(alone[name].get("required_formula"), ERP_CORE_FORMULA)

    def test_the_merged_vardefs_require_the_four_on_every_save(self):
        _, alone = compile_accounts_vardefs([(path, 100) for path in ERP_CORE_BILLING])
        bench = sorted(VARDEFS.glob("*.php")) + sorted(UAT_VARDEFS.glob("*.php"))
        self.assertIn(OVERRIDE, bench, f"{OVERRIDE} is not shipped")
        # Both orders of install: this package before ERP-Core's files (they are newer, the adverse case: an
        # ERP-Epicor upgrade after the Bench install) and after them.
        for label, core_mtime, bench_mtime in (("ERP-Core newer", 300, 100), ("Bench newer", 100, 300)):
            with self.subTest(order=label):
                order, merged = compile_accounts_vardefs(
                    [(path, core_mtime) for path in ERP_CORE_BILLING] + [(path, bench_mtime) for path in bench])
                self.assertEqual(order[-1], str(OVERRIDE), "the override is not merged last")
                for name in FOUR:
                    # Sugar builds the module rule from these two keys: `required` non-empty and a formula set.
                    self.assertTrue(merged[name].get("required"), f"{name}: no module rule without `required`")
                    self.assertEqual(merged[name].get("required_formula"), "true", f"{name}: not required always")
                    # Everything else, `required` included, is exactly what ERP-Core alone serves.
                    self.assertEqual({k: v for k, v in merged[name].items() if k != "required_formula"},
                                     {k: v for k, v in alone[name].items() if k != "required_formula"}, name)
                self.assertEqual(merged["billing_address_state"], alone["billing_address_state"],
                                 "State keeps ERP-Core's rule")


if __name__ == "__main__":
    unittest.main()
