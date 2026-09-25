#!/usr/bin/env python3
"""G450 - the Bench-only "Suspect" account type, and why it survives ERP-Epicor.

WHAT SHIPS. One application language fragment,
custom/Extension/application/Ext/Language/_override_en_us.bd_account_type_suspect.php,
adding ONE key, account_type_dom['Suspect'], beside the stock Customer and
Prospect. Core types an Epicor SUS customer's Account "Suspect" once the ADM
connection's customer_type_extra is {"SUS": "Suspect"} (connector_epicor.normalize,
live since core c54); ADM holds 1,022 of them, 846 active (read-only counts,
2026-09-24 / 2026-09-25). Owner: Yes to a Bench-only third value, never renaming
the stock keys (GAPS.md G450); voided with rc71 (🔒1775b), revived for rc74 by
the customer's reversal (🔒1783b).

THE HOSTILE CASE. ERP-Core's REPLACE install assigns account_type_dom as a WHOLE
ARRAY {Prospect, Customer} (its dropdown template, pinned under
fixtures/shared-sugar/). Sugar concatenates the application fragments in
ModuleInstaller::sortExtensionFiles order - is_override first, then an order-map
mtime - and includes the result with the accumulated $app_list_strings in scope
(_mergeCustomAppListStrings). A plain fragment that sorts before ERP-Core's
loses the key after every ERP-Epicor REPLACE upgrade: rc23 measured exactly
that for the Bench lookup-type label and fixed it with the `_override` name.

The cases below EXECUTE the merge in PHP: the fragments, in Sugar's order,
concatenated with a verbatim getExtensionFileContents (the G268 harness) and
included over a stock seed. The order itself is Sugar's rule, transcribed as
`sugar_order` and pinned against both SugarEnt trees by
test_the_order_rule_is_sugars (SugarEnt-tree only, named in mlp-lint.yml).

WHAT BROKEN LOOKS LIKE, and which case sees it:
  * the fragment renamed without `_override`      -> test_the_fragment_merges_after_erp_core
  * a whole-array assignment (wipes the stock two) -> test_the_stock_keys_are_kept_and_unrenamed,
                                                      test_it_adds_exactly_one_key
  * the isset guard dropped (admin relabel lost)   -> test_an_admin_relabel_survives_a_reinstall
  * the key misspelled ('suspect', 'SUS')          -> test_the_key_is_what_the_connector_writes
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

import shared_sugar
from bd_retirement import PKG, zip_names
from test_g268_retired_stage_names import GET_EXTENSION_FILE_CONTENTS

FRAGMENT_REL = ("custom/Extension/application/Ext/Language/"
                "_override_en_us.bd_account_type_suspect.php")
FRAGMENT = PKG / FRAGMENT_REL
ERP_CORE_REPLACE = shared_sugar.resolve("account_type_dom.replace.php")
SUGAR_TREES = [Path.home() / f"Documents/Code/SugarEnt-Full-{v}" for v in ("25.2.0", "26.1.0")]

#: What core writes for an Epicor SUS customer: the customer_type_extra VALUE,
#: as configured. The dom KEY must be exactly this, or the value renders blank.
#: The configured value is the owner's God's View PATCH, which this package's
#: RELEASE-NOTES state byte for byte; test_the_release_notes_patch_names_this_key
#: holds the two together so neither can drift alone.
CONNECTOR_WRITES = "Suspect"
RELEASE_NOTES = PKG / "RELEASE-NOTES.md"

#: Stock SugarEnt account_type_dom (include/language/en_us.lang.php), the seed
#: every tenant starts from.
STOCK = {"account_type_dom": {
    "": "", "Analyst": "Analyst", "Competitor": "Competitor", "Customer": "Customer",
    "Integrator": "Integrator", "Investor": "Investor", "Partner": "Partner",
    "Press": "Press", "Prospect": "Prospect", "Reseller": "Reseller", "Other": "Other"}}

HARNESS = r'''<?php
$problems = [];
set_error_handler(function ($no, $str, $file, $line) use (&$problems) {
    $problems[] = "$no $str @ " . basename($file) . ":$line";
    return true;
});
GET_EXTENSION_FILE_CONTENTS
$plan = json_decode(file_get_contents('plan.json'), true);
$served = [];
foreach ($plan['serves'] as $i => $files) {
    $compiled = getcwd() . "/compiled.$i.php";
    file_put_contents($compiled, getExtensionFileContents($files));
    $app_list_strings = $plan['seed'];
    include $compiled;
    $served[] = $app_list_strings['account_type_dom'] ?? null;
}
echo json_encode(['served' => $served, 'problems' => $problems]);
'''.replace("GET_EXTENSION_FILE_CONTENTS", GET_EXTENSION_FILE_CONTENTS)


def sugar_order(fragments: list[tuple[str, int]]) -> list[str]:
    """ModuleInstaller::sortExtensionFiles (25.2.0 / 26.1.0): a stable sort on
    (is_override, mtime), is_override meaning the basename starts '_override'."""
    return [name for name, _ in sorted(
        fragments, key=lambda f: (Path(f[0]).name[:9] == "_override", f[1]))]


def serve(*orders: list[Path]) -> list[dict]:
    """Merge each ordered fragment list over the stock seed; the served dom each time."""
    with tempfile.TemporaryDirectory(prefix="g450-") as tmp:
        t = Path(tmp)
        (t / "plan.json").write_text(json.dumps({
            "seed": STOCK, "serves": [[str(p) for p in order] for order in orders]}))
        out = subprocess.run(["php"], input=HARNESS, text=True, cwd=t,
                             capture_output=True, check=True)
        result = json.loads(out.stdout)
    assert result["problems"] == [], result["problems"]
    return result["served"]


def code_only(path: Path) -> str:
    body = re.sub(r"/\*.*?\*/", "", path.read_text(encoding="utf-8"), flags=re.S)
    return re.sub(r"(?m)(^|\s)(//|#)[^\n]*", r"\1", body)


class TheFragmentShips(unittest.TestCase):
    def test_it_is_in_the_source_and_the_built_zip(self):
        self.assertTrue(FRAGMENT.is_file(), FRAGMENT)
        self.assertIn(FRAGMENT_REL, zip_names())

    def test_its_name_merges_last_and_joins_the_en_us_merge(self):
        """Sugar's filter for the en_us merge is substr_count($entry, 'en_us');
        its override test is the first 9 characters of the basename."""
        self.assertEqual(FRAGMENT.name[:9], "_override")
        self.assertIn("en_us", FRAGMENT.name)

    def test_it_never_assigns_the_whole_list(self):
        code = code_only(FRAGMENT)
        self.assertNotRegex(code, r"\$app_list_strings\['account_type_dom'\]\s*=")
        self.assertRegex(code, r"\$app_list_strings\['account_type_dom'\]\['Suspect'\]\s*=")


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheSuspectKeySurvivesTheMerge(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix="g450-frag-"))
        self.addCleanup(shutil.rmtree, self.tmp)
        # ERP-Core's REPLACE template as install_languages() lands it: a plain
        # (non-override) application fragment under ERP-Epicor's id.
        self.erp_core = self.tmp / "en_us.sugarai_erp_epicor.php"
        shutil.copy2(ERP_CORE_REPLACE, self.erp_core)

    def test_control_erp_core_alone_serves_two(self):
        (served,) = serve([self.erp_core])
        self.assertEqual(served, {"Prospect": "Prospect", "Customer": "Customer"})

    def test_the_fragment_merges_after_erp_core(self):
        """ERP-Core's fragment is NEWER (an ERP-Epicor REPLACE upgrade after the
        Bench install) and still merges first: the override sorts last."""
        order = sugar_order([(str(FRAGMENT), 100), (str(self.erp_core), 200)])
        self.assertEqual(order[-1], str(FRAGMENT))
        (served,) = serve([Path(p) for p in order])
        self.assertEqual(list(served), ["Prospect", "Customer", "Suspect"])

    def test_control_a_plain_name_would_lose_the_key(self):
        """The rc23 shape: the same content under a plain name, older than
        ERP-Core's fragment, is wiped by the whole-array assignment. This is
        what makes the `_override` name load-bearing rather than decorative."""
        plain = self.tmp / "en_us.bd_account_type_suspect.php"
        shutil.copy2(FRAGMENT, plain)
        order = sugar_order([(str(plain), 100), (str(self.erp_core), 200)])
        (served,) = serve([Path(p) for p in order])
        self.assertNotIn("Suspect", served)

    def test_the_stock_keys_are_kept_and_unrenamed(self):
        (served,) = serve([self.erp_core, FRAGMENT])
        self.assertEqual(served["Customer"], "Customer")
        self.assertEqual(served["Prospect"], "Prospect")
        (over_stock,) = serve([FRAGMENT])          # a tenant without ERP-Core's REPLACE
        self.assertEqual({k: v for k, v in over_stock.items() if k != "Suspect"},
                         STOCK["account_type_dom"])

    def test_it_adds_exactly_one_key(self):
        (before,), (after,) = serve([self.erp_core]), serve([self.erp_core, FRAGMENT])
        self.assertEqual(set(after) - set(before), {"Suspect"})
        self.assertEqual(set(before) - set(after), set())

    def test_the_key_is_what_the_connector_writes(self):
        (served,) = serve([self.erp_core, FRAGMENT])
        self.assertIn(CONNECTOR_WRITES, served)
        self.assertEqual(served[CONNECTOR_WRITES], "Suspect")

    def test_an_admin_relabel_survives_a_reinstall(self):
        """Admin -> Dropdown Editor saves the WHOLE list to its own plain
        fragment, which merges before this one; the guard keeps its label."""
        admin = self.tmp / "en_us.sugar_account_type_dom.php"
        admin.write_text("<?php\n$app_list_strings['account_type_dom'] = array(\n"
                         "    'Prospect' => 'Prospect', 'Customer' => 'Customer',\n"
                         "    'Suspect' => 'Suspect (Kinetic)',\n);\n")
        order = sugar_order([(str(self.erp_core), 100), (str(admin), 200), (str(FRAGMENT), 50)])
        (served,) = serve([Path(p) for p in order])
        self.assertEqual(served["Suspect"], "Suspect (Kinetic)")

    def test_the_release_notes_patch_names_this_key(self):
        """The owner's PATCH body, as the release notes hand it over, maps SUS to
        a key this package serves. Broken looks like: the notes say "suspect"
        (or the fragment's key moves) and core writes a value that renders blank
        on every Suspect account."""
        bodies = re.findall(r'\{"customer_type_extra": (\{[^{}]*\})\}', RELEASE_NOTES.read_text())
        self.assertTrue(bodies, "no customer_type_extra PATCH body in RELEASE-NOTES.md")
        (served,) = serve([self.erp_core, FRAGMENT])
        for body in bodies:
            mapping = json.loads(body)
            self.assertEqual(set(mapping), {"SUS"})
            self.assertEqual(mapping["SUS"], CONNECTOR_WRITES)
            self.assertIn(mapping["SUS"], served)

    def test_merging_twice_is_the_same_as_once(self):
        once, twice = serve([self.erp_core, FRAGMENT], [self.erp_core, FRAGMENT, FRAGMENT])
        self.assertEqual(once, twice)


class TheOrderRuleIsSugars(unittest.TestCase):
    """`sugar_order` above is a transcription; this pins it to the platform."""

    def test_the_order_rule_is_sugars(self):
        trees = [t for t in SUGAR_TREES if (t / "ModuleInstall/ModuleInstaller.php").is_file()]
        self.assertTrue(trees, "no SugarEnt tree present")
        for tree in trees:
            with self.subTest(tree=tree.name):
                src = (tree / "ModuleInstall/ModuleInstaller.php").read_text()
                self.assertIn("'is_override' => substr(basename($extFile), 0, 9) === '_override'", src)
                self.assertIn("return $a['is_override'] <=> $b['is_override'] ?: "
                              "$a['mtime'] <=> $b['mtime'];", src)
                self.assertIn("$filterCheck = empty($filter) || substr_count($entry, $filter) > 0;",
                              src)

    # Reads SugarCRM's own ModuleInstaller.php, which CI can never have (no public
    # copy), so CI does not collect it. Named in mlp-lint.yml's left-out list.
    test_the_order_rule_is_sugars.requires_sugarent_tree = True


if __name__ == "__main__":
    unittest.main()
