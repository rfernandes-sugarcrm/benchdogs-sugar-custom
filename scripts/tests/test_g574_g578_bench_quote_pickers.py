#!/usr/bin/env python3
"""G574 - a new quote no longer starts with ANOTHER customer's Project.

MEASURED (benchdogs-dev SALES ORDER smoke, 2026-09-25, #Quotes/create): Project
read "17879 - LGH EXPANSION" before any account was chosen; a REST read of the
form's model held bd_project_id "17879". The Account-button path, which never
renders the picker, left it empty.

THE CAUSE, read from SugarCRM's own source (SugarEnt-Full 26.1.0 and 25.2.0):

  1. clients/base/fields/enum/enum.js, _checkForDefaultValue(): on a create or
     edit form an enum with no value in its option list is set to
     _getDefaultOption() - the FIRST option key - unless the field def says
     `defaultToBlank`.
  2. The first key is not the blank one. BdAdmRules::optionsFromRows() puts ''
     first, but the browser reads the REST answer with JSON.parse, and a
     JavaScript object lists integer-like keys ("17879", "18126") before every
     other key, so '' ends up after them.
  3. sidecar/src/view/field.js builds a field's def as the vardef extended by
     the viewdef (`this.def = _.extend({}, fieldDefs, options.def)`), so a
     `defaultToBlank` on the vardef reaches the field with no layout code.

THE FIX: `'defaultToBlank' => true` on all five Bench quote pickers (the vardef
docblock says why all five). bd_adm_rules_test.php M7 pins the flag in CI.

WHAT THIS FILE ADDS, so the fix is shown against the defect and not only present:

  * TheBrowserListsTheProjectBeforeTheBlank (runs in CI; needs php + node): the
    premise of (2), on the package's own optionsFromRows() output.
  * SugarsOwnEnumFieldOnTheCreateForm (reads the SugarEnt trees, so CI does not
    collect it; named in mlp-lint.yml): Sugar's real EnumField, the shipped
    Project def and the browser's key order. The shipped def -> no default is
    set. The CONTROL - the same def without `defaultToBlank` - reproduces the
    measured defect: setDefault('bd_project_id', '17879'). A test that could not
    produce that red would not be evidence.

G578 (label) is pinned in bd_adm_rules_test.php M5b / M5c.

MUTATION-VERIFIED (applied, re-run, red observed; see the commit message):
  drop 'defaultToBlank' from bd_project_id -> M7 and the 26.1.0/25.2.0 cases red
  put the campaign label back to "Marketing Campaign" -> M5b and M5c red
"""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

from bd_retirement import PKG

HERE = Path(__file__).resolve().parent
DRIVER = HERE / "g574_stock_enum_default.cjs"
SUGAR_TREES = [Path.home() / f"Documents/Code/SugarEnt-Full-{v}" for v in ("25.2.0", "26.1.0")]

php = shutil.which("php")
node = shutil.which("node")

#: The measured create form's first options (benchdogs-dev, 2026-09-25): the
#: project Sugar pre-picked, the next one, and the one the smoke's seller chose.
ROWS = [
    {"erp_display_sync_key": "17879", "name": "LGH EXPANSION"},
    {"erp_display_sync_key": "18126", "name": "ANOTHER CUSTOMER"},
    {"erp_display_sync_key": "212041-00", "name": "BIMBO - RUSTIK DISPLAY"},
]

PHP_INPUT = r"""
require 'custom/modules/Quotes/BdAdmRules.php';
$dictionary = [];
include 'custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php';
$rows = json_decode($argv[1], true);
echo json_encode([
    'def' => $dictionary['Quote']['fields']['bd_project_id'],
    'options_json' => json_encode(BdAdmRules::optionsFromRows($rows)),
]);
"""


def php_input() -> dict:
    out = subprocess.run([php, "-r", PHP_INPUT, json.dumps(ROWS)], cwd=PKG,
                         capture_output=True, text=True, check=True)
    return json.loads(out.stdout)


def run_stock_enum(tree: Path, payload: dict) -> dict:
    out = subprocess.run([node, str(DRIVER)], input=json.dumps({**payload, "tree": str(tree)}),
                         capture_output=True, text=True, check=True)
    return json.loads(out.stdout)


@unittest.skipUnless(php and node, "requires a PHP CLI and node")
class TheBrowserListsTheProjectBeforeTheBlank(unittest.TestCase):
    """The premise of the cause: PHP's order is not the browser's."""

    def test_php_puts_the_blank_first_and_the_browser_puts_it_after_the_numeric_codes(self):
        payload = php_input()
        php_order = list(json.loads(payload["options_json"], object_pairs_hook=lambda kv: [k for k, _ in kv]))
        self.assertEqual(php_order, ["", "17879", "18126", "212041-00"])
        js = subprocess.run([node, "-e", "process.stdout.write(JSON.stringify(Object.keys(JSON.parse("
                             "require('fs').readFileSync(0, 'utf8')))))"],
                            input=payload["options_json"], capture_output=True, text=True, check=True)
        self.assertEqual(json.loads(js.stdout), ["17879", "18126", "", "212041-00"])


@unittest.skipUnless(php and node, "requires a PHP CLI and node")
class SugarsOwnEnumFieldOnTheCreateForm(unittest.TestCase):
    """Sugar's EnumField, the shipped def, the browser's order: no pre-pick."""

    requires_sugarent_tree = True

    def trees(self) -> list[Path]:
        trees = [t for t in SUGAR_TREES if (t / "clients/base/fields/enum/enum.js").is_file()]
        self.assertTrue(trees, "no SugarEnt tree present")
        return trees

    def test_the_shipped_project_def_is_left_blank_and_the_control_reproduces_17879(self):
        payload = php_input()
        # The control is the shipped def WITHOUT the flag (rc74's def), so it can
        # only differ from the shipped run by what the flag does.
        control = {**payload, "def": {k: v for k, v in payload["def"].items() if k != "defaultToBlank"}}
        for tree in self.trees():
            with self.subTest(tree=tree.name):
                # The platform facts this relies on, read where they live.
                enum_src = (tree / "clients/base/fields/enum/enum.js").read_text()
                self.assertIn("&& !this.def.defaultToBlank", enum_src)
                field_src = (tree / "sidecar/src/view/field.js").read_text()
                self.assertIn("this.def = _.extend({}, fieldDefs, options.def);", field_src)

                shipped = run_stock_enum(tree, {**payload, "field": "bd_project_id"})
                self.assertEqual(shipped["keys"][0], "17879", "the premise: the blank is not first")
                self.assertEqual(shipped["defaults"], [], "the shipped def must leave Project blank")

                defect = run_stock_enum(tree, {**control, "field": "bd_project_id"})
                self.assertEqual(defect["defaults"], [["bd_project_id", "17879"]],
                                 "the control must reproduce the measured defect")


if __name__ == "__main__":
    unittest.main()
