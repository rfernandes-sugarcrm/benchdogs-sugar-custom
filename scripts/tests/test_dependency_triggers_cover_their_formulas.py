"""Every field a shipped dependency's formula reads is one of its trigger fields.

In the browser a dependency is re-checked only when one of its triggerFields
changes. Sugar builds the triggers of its own formula dependencies
(required_formula, readonly_formula, calculated fields) from every `$name` the
formula reads, the link of a related() included. A hand-written Ext/Dependencies
file names its triggers itself, so a formula variable left out of the list
never re-checks the rule.

The case that matters: related($erp_companies_accounts, ...) on a create form
returns "" while the ERP company record loads, and the load announces itself
only as a change of the link. Without the link among the triggers, the
Cust. Group rule stays "not required" and the account saves without a group.

A file that REWRITES another package's rule (the Bench Part Number rule rewrites
ERP-Core's erp_part_number_read_only in place) defines nothing on its own, so
each file is read over ERP-Core's REAL Products rule (the shared pin, or the
sibling checkout's file) and what it adds or changes is what is checked.

IF IT WERE BROKEN you would see:
  test_every_formula_variable_is_a_trigger  FAIL - names the dependency and the
      variables its formula reads that no trigger re-checks.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest

import shared_sugar
from bd_retirement import PKG

DEPENDENCY_FILES = sorted(PKG.glob("custom/Extension/modules/*/Ext/Dependencies/*.php"))

#: Sugar's Parser::getFieldsFromExpression reads a formula's fields with this pattern.
VARIABLE = re.compile(r"\$(\w+)")

READ = r"""
$out = [];
$base = $argv[1];
foreach (array_slice($argv, 2) as $file) {
    $dependencies = [];
    include $base;
    $before = $dependencies;
    include $file;
    foreach ($dependencies as $module => $defs) {
        foreach ($defs as $name => $def) {
            if (isset($before[$module][$name]) && $before[$module][$name] === $def) {
                continue;
            }
            $formulas = [(string) ($def['trigger'] ?? 'true')];
            foreach (array_merge($def['actions'] ?? [], $def['notActions'] ?? []) as $action) {
                foreach (($action['params'] ?? []) as $param => $value) {
                    if (is_string($value) && $param !== 'target' && $param !== 'label') {
                        $formulas[] = $value;
                    }
                }
            }
            $out[basename($file)][$module . '.' . $name] = [
                'triggerFields' => array_values($def['triggerFields'] ?? []),
                'formulas' => $formulas,
                'onload' => !empty($def['onload']),
            ];
        }
    }
}
echo json_encode($out);
"""


def shipped_dependencies() -> dict:
    php = shutil.which("php")
    base = shared_sugar.resolve("erp_part_number_read_only.php")
    out = subprocess.run([php, "-r", READ, "--", str(base), *map(str, DEPENDENCY_FILES)],
                         capture_output=True, text=True, check=True)
    return json.loads(out.stdout)


#: A formula that is a constant reads no field, so no change can re-check it: such a rule (rc117's billing address,
#: required on every account) holds only if it is applied when the view opens.
CONSTANT = {"true", "false"}


class DependencyTriggersCoverTheirFormulas(unittest.TestCase):
    def test_every_formula_variable_is_a_trigger(self):
        shipped = shipped_dependencies()
        self.assertEqual(sorted(shipped), sorted(p.name for p in DEPENDENCY_FILES), "a dependency file defined nothing")
        checked = 0
        for file, deps in shipped.items():
            for name, dep in deps.items():
                read = {v for formula in dep["formulas"] for v in VARIABLE.findall(formula)}
                if all(formula.strip() in CONSTANT for formula in dep["formulas"]):
                    self.assertTrue(dep["onload"], f"{file} {name}: a constant rule that is not applied on load "
                                                   "is never applied at all")
                    continue
                checked += 1
                self.assertTrue(read, f"{file} {name}: its formula reads no field, so this test would check nothing")
                missing = sorted(read - set(dep["triggerFields"]))
                self.assertEqual(missing, [], f"{file} {name}: the formula reads {missing} but nothing re-checks it "
                                              "when they change")
        self.assertGreater(checked, 0, "no dependency with a formula was checked, so this test checked nothing")


if __name__ == "__main__":
    unittest.main()
