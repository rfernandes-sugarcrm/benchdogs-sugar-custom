#!/usr/bin/env python3
"""Ship Via is not required on a quote, and the package ships its Quotes lookup controller as an inert module field.

The rule (since 0.9.46-rc113): Ship Via is checked when the seller presses Submit Order or Order Selected Lines
(BdAdmOrderRequirements), never on a quote's Save. ship_via_required_on_real_sidecar.cjs runs the shipped controller in
Sugar's own record and create views. Here, what that page cannot see:
  * the controller still ships at the path rc104 to rc112 shipped theirs at, and adds nothing to ERP-Core's erp-lookup
    field: Module Loader never deletes a file a later build stops shipping, so only a file at that path takes the old
    required rule off an upgraded tenant;
  * nothing serves Ship Via required: this package's vardefs and dependencies never name it with `required`,
    `required_formula` or SetRequired, so the connector's quote writes are never asked for it;
  * the package's only scripts are module client files, which an install serves with the metadata, and it adds
    nothing to a script bundle (code there would wait for an admin's Quick Repair).

IF IT WERE BROKEN: the controller is dropped or moved (an upgraded tenant keeps rc112's rule), it defines anything but
its parent, a vardef or dependency of this package requires Ship Via, or a script lands in a bundle.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest

from bd_retirement import PKG

CONTROLLER = PKG / "custom/modules/Quotes/clients/base/fields/erp-lookup/erp-lookup.js"
#: The rc112 controller as shipped; its note names the path it shipped at.
RC112 = PKG.parents[1] / "scripts/tests/fixtures/rc112/erp-lookup.js"

node = shutil.which("node")

JS_KEYS = r"""
const fs = require('fs');
const vm = require('vm');
const c = vm.runInNewContext(fs.readFileSync(process.argv[1], 'utf8'), {app: {}});
process.stdout.write(JSON.stringify({keys: Object.keys(c), parent: c.extendsFrom}));
"""


@unittest.skipUnless(node, "requires node")
class TheControllerOverwritesTheOldRuleAndAddsNothing(unittest.TestCase):
    def test_it_ships_at_the_path_rc112_shipped_its_rule_at(self):
        note = RC112.read_text(encoding="utf-8").split("\n", 1)[0]
        shipped_at = re.search(r"sugar-sell/BenchDogs-Ext/(\S+\.js) as BenchDogs-Ext 0\.9\.46-rc112", note)
        self.assertIsNotNone(shipped_at, note)
        self.assertEqual((PKG / shipped_at.group(1)).resolve(), CONTROLLER.resolve())
        self.assertTrue(CONTROLLER.is_file())

    def test_it_defines_nothing_but_its_parent(self):
        facts = json.loads(subprocess.run([node, "-e", JS_KEYS, str(CONTROLLER)], capture_output=True, text=True,
                                          check=True).stdout)
        self.assertEqual(facts, {"keys": ["extendsFrom"], "parent": "ErpLookupField"})

    def test_control_the_rc112_controller_defines_more(self):
        body = RC112.read_text(encoding="utf-8").split("\n", 1)[1]
        with subprocess.Popen([node, "-e", JS_KEYS, "/dev/stdin"], stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                              text=True) as proc:
            out, _ = proc.communicate(body)
        self.assertIn("bdRequiredIdField", json.loads(out)["keys"])


class NothingServesShipViaRequired(unittest.TestCase):
    def test_no_vardef_or_dependency_of_this_package_requires_it(self):
        sources = [p for p in (PKG / "custom/Extension/modules/Quotes/Ext").rglob("*.php")
                   if p.parent.name in ("Vardefs", "Dependencies")]
        self.assertTrue(sources, "the package's Quotes vardefs and dependencies are read")
        for path in sources:
            text = path.read_text(encoding="utf-8")
            with self.subTest(path=path.name):
                self.assertNotRegex(text, r"\['erp_quotes_ship_via_name'\]\['required(_formula)?'\]")
                self.assertNotRegex(text, r"'target'\s*=>\s*'erp_quotes_ship_via_name'")


class NothingTheRuleNeedsWaitsForAQuickRepair(unittest.TestCase):
    """A package install refreshes the metadata Sugar serves (a module's field controllers ride it), but never
    rebuilds the script bundles (sugar_sidecar.min.js and the other JSGroupings files)."""

    def test_every_script_the_package_ships_is_a_module_client_file(self):
        scripts = sorted(path.relative_to(PKG).as_posix() for path in PKG.rglob("*.js"))
        self.assertIn(CONTROLLER.relative_to(PKG).as_posix(), scripts)
        for script in scripts:
            with self.subTest(script=script):
                self.assertRegex(script, r"^custom/modules/[^/]+/clients/", "served with the module's metadata")

    def test_the_package_adds_nothing_to_a_script_bundle(self):
        for path in PKG.rglob("*"):
            if path.is_file() and path.suffix in (".php", ".js"):
                with self.subTest(path=path.relative_to(PKG).as_posix()):
                    self.assertNotIn("JSGroupings", path.relative_to(PKG).as_posix())
                    self.assertNotIn("js_groupings", path.read_text(encoding="utf-8", errors="ignore"))


if __name__ == "__main__":
    unittest.main()
