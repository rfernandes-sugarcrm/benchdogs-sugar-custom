#!/usr/bin/env python3
"""Bench Dogs' Opportunity stages: exactly the owner's list, in its order, whatever installs last.

This is the list where no admin edited it in the Dropdown Editor; since rc126 an admin's
edits are kept, and test_stage_list_keeps_admin_edits.py holds that.

THE RULING (owner, 2026-10-17, yes/no): this package ships Bench Dogs' stage list
over the integration's keys, with these labels and probabilities, in this order:

    Prospecting                 Qualifying_Designing          10
    Estimating                  Estimating                    20
    Proposal/Price Quote        Quote Delivered               25
    Negotiation/Revision        Negotiation/Revision          50
    Verbal commit/PO Pending    Verbal commit/PO Pending      75
    Prototype Ordered           Prototype Ordered             80   (Partial Fulfillment's)
    Partial Production Ordered  Partial Production Ordered    90   (Partial Fulfillment's)
    On Hold                     On Hold                        5   (added 2026-11-03)
    Closed Won                  Closed Won                   100
    Closed Lost                 Closed Lost                    0

No record is remapped, and every key the integration writes stays a key.

ON HOLD (owner, 2026-11-03): the customer asked for an Opportunity stage named
like the Quote stage "On Hold" (the same key, ``On Hold``), at 5 so a stalled deal
stays in pipeline reports without inflating them, placed last among the open
stages so pipeline charts do not show it mid-funnel. Each probability is used by
one stage only. The nine stages before it keep their keys, labels, order and
probabilities. Nothing moves an Opportunity to or from it: it is only a choice.

THE LOAD ORDER, FROM SUGAR'S SOURCE (SugarEnt 25.2.0 and 26.1.0). ERP-Core installs
its whole-list template with ``install_languages`` as
``custom/Extension/application/Ext/Language/en_us.sugarai_erp_epicor.php``, a PLAIN
fragment. Partial Fulfillment ships ``_override_en_us.partial_fulfillment_sales_stage.php``.
Sugar concatenates the fragments plain first, then ``_override_``, each by the
mtime it first saw (``sortExtensionFiles``; the rule is
``test_g450_suspect_account_type.sugar_order``, pinned against both trees there),
and the compiled file is merged after Studio's own dropdown file, so it wins.
This package's ``_override_en_us.bd_sales_stages.php`` therefore always follows
ERP-Core's template; against Partial Fulfillment's fragment it follows or
precedes by mtime, which flips when either is upgraded. It is right either way
only because it carries PF's two keys with PF's own labels and probabilities, so
PF setting them again moves nothing. Every case runs both orders.

THE STYLES. Opportunities draws sales_stage as a formatted enum, which shows a key
with no style blank (L-0004). Sugar styles every stock key; this package styles
its four new ones (Estimating, Negotiation/Revision, Verbal commit/PO Pending,
On Hold), each only when absent. Sugar's own On Hold style is for quotes
(quote_stage_dom_style), not sales stages, so On Hold needs this package's.

The cases EXECUTE Sugar's merge in PHP: the pinned ERP-Core template
(fixtures/merge-order/) and PF's pinned fragment (fixtures/g268/), in Sugar's
order, concatenated with the verbatim ``getExtensionFileContents`` and included
over the stock lists.

WHAT BROKEN LOOKS LIKE, and which case sees it:
  * the fragment named without ``_override``        -> TheServedList (ERP-Core wins)
  * a key, label or probability off the table        -> test_the_served_list_is_exactly_the_table
  * On Hold missing, moved, or not at 5              -> test_on_hold_is_the_last_open_stage_at_5
  * two stages sharing a probability                 -> test_every_served_probability_is_unique
  * an existing stage changed or moved               -> test_the_stages_before_on_hold_are_unchanged
  * PF's values diverging from PF's own              -> test_partial_fulfillments_values_are_its_own
  * a new key without a style                       -> test_the_new_stages_are_styled
"""
from __future__ import annotations

import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

from bd_retirement import PKG
from test_g268_retired_stage_names import GET_EXTENSION_FILE_CONTENTS, STOCK
from test_g450_suspect_account_type import sugar_order

HERE = Path(__file__).resolve().parent
LANG = PKG / "custom/Extension/application/Ext/Language"
FRAGMENT = LANG / "_override_en_us.bd_sales_stages.php"
STYLE = PKG / "custom/Extension/application/Ext/DropdownsStyle/bd_sales_stage_style.php"
ERP_CORE_TEMPLATE = HERE / "fixtures/merge-order/sales_stage_dom.replace.php"
PF_FRAGMENT = HERE / "fixtures/g268/pf_sales_stage_override.php"

#: The owner's table, written out by hand: (key, label, probability), in order.
TABLE = [
    ("Prospecting", "Qualifying_Designing", 10),
    ("Estimating", "Estimating", 20),
    ("Proposal/Price Quote", "Quote Delivered", 25),
    ("Negotiation/Revision", "Negotiation/Revision", 50),
    ("Verbal commit/PO Pending", "Verbal commit/PO Pending", 75),
    ("Prototype Ordered", "Prototype Ordered", 80),
    ("Partial Production Ordered", "Partial Production Ordered", 90),
    ("On Hold", "On Hold", 5),
    ("Closed Won", "Closed Won", 100),
    ("Closed Lost", "Closed Lost", 0),
]
#: The list as it stood before On Hold (rc100 to rc124), written out by hand: every
#: one of these keeps its label, probability and relative order.
BEFORE_ON_HOLD = [
    ("Prospecting", "Qualifying_Designing", 10),
    ("Estimating", "Estimating", 20),
    ("Proposal/Price Quote", "Quote Delivered", 25),
    ("Negotiation/Revision", "Negotiation/Revision", 50),
    ("Verbal commit/PO Pending", "Verbal commit/PO Pending", 75),
    ("Prototype Ordered", "Prototype Ordered", 80),
    ("Partial Production Ordered", "Partial Production Ordered", 90),
    ("Closed Won", "Closed Won", 100),
    ("Closed Lost", "Closed Lost", 0),
]
#: The Quote stage key the new Opportunity stage is named after (Sugar's own
#: quote_stage_dom key, kept by ERP-Core's quote stage template).
ON_HOLD = "On Hold"
#: The keys the integration writes onto an Opportunity, written out by hand: the
#: Bench connector extension's Closed Won and open-quote stage, ERP-Epicor's Closed
#: Won, Partial Fulfillment's two release stages.
WRITTEN_BY_THE_INTEGRATION = {
    "Closed Won", "Proposal/Price Quote", "Prototype Ordered", "Partial Production Ordered"}
NEW_KEYS = ["Estimating", "Negotiation/Revision", "Verbal commit/PO Pending", "On Hold"]

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
    $served[] = [
        'stages' => array_map(null, array_keys($app_list_strings['sales_stage_dom']),
                              array_values($app_list_strings['sales_stage_dom'])),
        'probabilities' => array_map(null, array_keys($app_list_strings['sales_probability_dom']),
                                     array_values($app_list_strings['sales_probability_dom'])),
    ];
}
echo json_encode(['served' => $served, 'problems' => $problems]);
'''.replace("GET_EXTENSION_FILE_CONTENTS", GET_EXTENSION_FILE_CONTENTS)


def serve(*orders: list[Path]) -> list[dict]:
    with tempfile.TemporaryDirectory(prefix="bd-stages-") as tmp:
        t = Path(tmp)
        (t / "plan.json").write_text(json.dumps({
            "seed": STOCK, "serves": [[str(p) for p in o] for o in orders]}))
        out = subprocess.run(["php"], input=HARNESS, text=True, cwd=t,
                             capture_output=True, check=True)
        result = json.loads(out.stdout)
    assert result["problems"] == [], result["problems"]
    return result["served"]


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheServedList(unittest.TestCase):
    def setUp(self):
        tmp = Path(tempfile.mkdtemp(prefix="bd-stage-frags-"))
        self.addCleanup(shutil.rmtree, tmp)
        # ERP-Core's template as install_languages() lands it: a plain fragment
        # under ERP-Epicor's id.
        self.erp_core = tmp / "en_us.sugarai_erp_epicor.php"
        self.erp_core.write_bytes(ERP_CORE_TEMPLATE.read_bytes())
        self.pf = tmp / "_override_en_us.partial_fulfillment_sales_stage.php"
        self.pf.write_bytes(PF_FRAGMENT.read_bytes())

    def orders(self) -> dict[str, list[Path]]:
        """Each install order, by first-seen mtime, put through Sugar's sort."""
        def ordered(mtimes: dict[Path, int]) -> list[Path]:
            names = {str(p): p for p in mtimes}
            return [names[n] for n in sugar_order([(str(p), m) for p, m in mtimes.items()])]
        return {
            "ERP-Epicor, then PF, then Bench": ordered({self.erp_core: 1, self.pf: 2, FRAGMENT: 3}),
            "PF upgraded after Bench": ordered({self.erp_core: 1, FRAGMENT: 2, self.pf: 3}),
            "ERP-Epicor upgraded last": ordered({FRAGMENT: 1, self.pf: 2, self.erp_core: 3}),
        }

    def test_the_served_list_is_exactly_the_table(self):
        orders = self.orders()
        for (name, order), served in zip(orders.items(), serve(*orders.values())):
            with self.subTest(order=name):
                self.assertEqual([tuple(s) for s in served["stages"]],
                                 [(key, label) for key, label, _ in TABLE])
                self.assertEqual([tuple(p) for p in served["probabilities"]],
                                 [(key, probability) for key, _, probability in TABLE])

    def served_rows(self) -> dict[str, list[tuple[str, str, int]]]:
        """(key, label, probability) as served, per install order; a key served in
        one list and not the other fails here rather than reading as a row."""
        orders = self.orders()
        rows = {}
        for name, served in zip(orders, serve(*orders.values())):
            stages = [tuple(s) for s in served["stages"]]
            probabilities = dict(tuple(p) for p in served["probabilities"])
            self.assertEqual([key for key, _ in stages], list(probabilities), name)
            rows[name] = [(key, label, probabilities[key]) for key, label in stages]
        return rows

    def test_on_hold_is_the_last_open_stage_at_5(self):
        """On Hold, labelled On Hold, at 5, right after Partial Production Ordered
        and right before Closed Won, then Closed Lost, in every install order."""
        for name, rows in self.served_rows().items():
            with self.subTest(order=name):
                keys = [key for key, _, _ in rows]
                self.assertIn(ON_HOLD, keys)
                at = keys.index(ON_HOLD)
                self.assertEqual(rows[at], (ON_HOLD, "On Hold", 5))
                self.assertEqual(keys[at - 1], "Partial Production Ordered")
                self.assertEqual(keys[at + 1:], ["Closed Won", "Closed Lost"])

    def test_every_served_probability_is_unique(self):
        """Sugar maps a stage to a probability, and pipeline reports read a stage
        back from it: no two served stages may share one."""
        for name, rows in self.served_rows().items():
            with self.subTest(order=name):
                probabilities = [probability for _, _, probability in rows]
                self.assertEqual(len(probabilities), len(set(probabilities)), rows)

    def test_the_stages_before_on_hold_are_unchanged(self):
        """Taking On Hold out leaves exactly the earlier list: no key, label,
        probability or position of an existing stage changed."""
        for name, rows in self.served_rows().items():
            with self.subTest(order=name):
                self.assertEqual([row for row in rows if row[0] != ON_HOLD], BEFORE_ON_HOLD)

    def test_the_fragment_follows_erp_cores_template_in_every_order(self):
        for name, order in self.orders().items():
            with self.subTest(order=name):
                self.assertLess(order.index(self.erp_core), order.index(FRAGMENT))

    def test_the_control_erp_cores_list_wins_without_the_fragment(self):
        """The hostile case is real: with ERP-Core and PF alone the list is
        ERP-Core's four and PF's two, so the table above is this package's doing."""
        (served,) = serve(sugar_order_paths({self.erp_core: 1, self.pf: 2}))
        self.assertEqual([s[0] for s in served["stages"]], [
            "Prospecting", "Proposal/Price Quote", "Closed Won", "Closed Lost",
            "Prototype Ordered", "Partial Production Ordered"])

    def test_every_key_the_integration_writes_is_served(self):
        (served,) = serve([self.erp_core, self.pf, FRAGMENT])
        self.assertLessEqual(WRITTEN_BY_THE_INTEGRATION, {s[0] for s in served["stages"]})

    def test_the_stripped_stock_key_never_comes_back(self):
        (served,) = serve([self.erp_core, self.pf, FRAGMENT])
        self.assertNotIn("Negotiation/Review", {s[0] for s in served["stages"]})
        self.assertNotIn("Negotiation/Review", {p[0] for p in served["probabilities"]})


def sugar_order_paths(mtimes: dict[Path, int]) -> list[Path]:
    names = {str(p): p for p in mtimes}
    return [names[n] for n in sugar_order([(str(p), m) for p, m in mtimes.items()])]


@unittest.skipUnless(shutil.which("php"), "requires php")
class PartialFulfillmentKeepsItsOwn(unittest.TestCase):
    def test_partial_fulfillments_values_are_its_own(self):
        """PF's two keys carry exactly PF's labels and probabilities, read from
        PF's own (pinned, drift-checked) fragment, so PF loading after this
        package moves nothing."""
        probe = (
            "$app_list_strings = ['sales_stage_dom' => [], 'sales_probability_dom' => []];"
            f"include {json.dumps(str(PF_FRAGMENT))};"
            "echo json_encode($app_list_strings);"
        )
        pf = json.loads(subprocess.run(["php", "-r", probe], capture_output=True, text=True,
                                       check=True).stdout)
        table = {key: (label, probability) for key, label, probability in TABLE}
        for key, label in pf["sales_stage_dom"].items():
            with self.subTest(key=key):
                self.assertEqual(table[key], (label, pf["sales_probability_dom"][key]))
        self.assertEqual(set(pf["sales_stage_dom"]), {"Prototype Ordered", "Partial Production Ordered"})


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheNewStagesAreStyled(unittest.TestCase):
    def styles(self, seed: dict) -> dict:
        probe = (
            f"$app_dropdowns_style = ['sales_stage_dom_style' => {php_array(seed)}];"
            f"include {json.dumps(str(STYLE))};"
            "echo json_encode($app_dropdowns_style['sales_stage_dom_style']);"
        )
        return json.loads(subprocess.run(["php", "-r", probe], capture_output=True, text=True,
                                         check=True).stdout)

    def test_the_new_stages_are_styled(self):
        styles = self.styles({"applyFormatting": True, "Prospecting": {"backgroundColor": "#FBCFE8"}})
        for key in NEW_KEYS:
            with self.subTest(key=key):
                self.assertIn("backgroundColor", styles.get(key, {}))
        self.assertEqual(set(styles) - set(NEW_KEYS), {"applyFormatting", "Prospecting"})
        self.assertEqual(styles["Prospecting"], {"backgroundColor": "#FBCFE8"})

    def test_a_tenants_own_style_is_kept(self):
        styles = self.styles({"Estimating": {"backgroundColor": "#123456"}})
        self.assertEqual(styles["Estimating"], {"backgroundColor": "#123456"})

    def test_every_served_key_without_a_stock_style_gets_one(self):
        stock_styled = {"Prospecting", "Proposal/Price Quote", "Closed Won", "Closed Lost"}
        pf_styled = {"Prototype Ordered", "Partial Production Ordered"}
        self.assertEqual({key for key, _, _ in TABLE} - stock_styled - pf_styled, set(NEW_KEYS))


def php_array(value: dict) -> str:
    return "json_decode(" + json.dumps(json.dumps(value)) + ", true)"


if __name__ == "__main__":
    unittest.main()
