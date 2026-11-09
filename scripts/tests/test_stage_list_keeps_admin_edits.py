#!/usr/bin/env python3
"""The Opportunity stage list keeps what an administrator adds or relabels in the Dropdown Editor.

THE RULING (owner, 2026-11-04, yes/no): the stage list and its probabilities keep
whatever an admin adds or relabels in Admin > Dropdown Editor; this package only
adds its own stages (key, default label, probability, style) where they are
missing; on a tenant where no admin edited the list, the result is exactly the
list test_bench_opportunity_stages.TABLE pins, in its order.

WHERE THE ADMIN'S LIST IS, FROM SUGAR'S SOURCE (SugarEnt 25.2.0 and 26.1.0, the
same code). ParserDropDown::saveDropDown (modules/ModuleBuilder/parsers/
parser.dropdown.php:30-127) writes the WHOLE list it was given to a PLAIN
application fragment, custom/Extension/application/Ext/Language/
<lang>.sugar_<dropdown>.php (getExtensionFilePath 184-195, getExtensionContents
341-348), then rebuilds the languages (finalize 204-222). sales_probability_dom
is its own dropdown and its own file. Plain fragments merge before every
``_override_`` one (sortExtensionFiles), so this package's fragment always runs
after the admin's list and sees it in $app_list_strings.

THE HOSTILE ORDERS. ERP-Core's template (a plain fragment landed by
install_languages) is rewritten at every ERP-Epicor install, so it can be newer
or older than the admin's file; Partial Fulfillment's ``_override_`` fragment can
run before or after this one. Every case runs the orders that matter. When
ERP-Core's file is the newer, its template has already moved its four keys to the
front with its own labels before this package runs (see the last class).

The admin files below are written in the exact shape getExtensionContents writes
(``var_export``), with the values as strings, as the Dropdown Editor saves them.
The seed is Sugar's own en_us lists (include/language/en_us.lang.php:665-676 and
717-728 in both trees), with the probabilities as the strings Sugar ships.

WHAT BROKEN LOOKS LIKE, and which case sees it:
  * the whole list assigned again (today's rc125)  -> AnAdminAddsAStage, AnAdminRelabels,
                                                      AnAdminChangesAProbability
  * the add-missing step gone                      -> AnAdminDeletesAStage
  * Sugar's or ERP-Core's labels taken as an admin's -> NoAdminList
  * an admin's stage given an invented probability -> AnAdminStageWithoutAProbability
  * an added stage anchored after a moved key      -> TheErpCoreTemplateRanLast
  * the table of shipped values drifting           -> TheShippedValuesAreDerived
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

from test_bench_opportunity_stages import (ERP_CORE_TEMPLATE, FRAGMENT, HARNESS, PF_FRAGMENT,
                                           TABLE)
from test_g450_suspect_account_type import sugar_order

#: Sugar's own en_us sales stage and probability lists, written out by hand from
#: include/language/en_us.lang.php (identical in SugarEnt 25.2.0 and 26.1.0).
STOCK = {
    "sales_stage_dom": {
        "Prospecting": "Prospecting", "Qualification": "Qualification",
        "Needs Analysis": "Needs Analysis", "Value Proposition": "Value Proposition",
        "Id. Decision Makers": "Id. Decision Makers", "Perception Analysis": "Perception Analysis",
        "Proposal/Price Quote": "Proposal/Price Quote", "Negotiation/Review": "Negotiation/Review",
        "Closed Won": "Closed Won", "Closed Lost": "Closed Lost",
    },
    "sales_probability_dom": {
        "Prospecting": "10", "Qualification": "20", "Needs Analysis": "25",
        "Value Proposition": "30", "Id. Decision Makers": "40", "Perception Analysis": "50",
        "Proposal/Price Quote": "65", "Negotiation/Review": "80", "Closed Won": "100",
        "Closed Lost": "0",
    },
}

TABLE_STAGES = [(key, label) for key, label, _ in TABLE]
TABLE_PROBABILITIES = [(key, probability) for key, _, probability in TABLE]
BENCH_KEYS = [key for key, _, _ in TABLE]

#: getExtensionContents, parser.dropdown.php:341-348, with a fixed date.
ADMIN_FILE = r'''<?php
$dropdown = json_decode($argv[2], true);
$contents = "<?php\n // created: 2026-11-04 23:18:00\n";
$contents .= "\n\$app_list_strings[" . var_export($argv[1], true) . "]=" . var_export($dropdown, true) . ';';
file_put_contents($argv[3], $contents);
'''


def admin_file(path: Path, dropdown: str, pairs: list[tuple[str, object]]) -> Path:
    """The file the Dropdown Editor saves for `dropdown`: every value a string."""
    as_saved = {key: str(value) for key, value in pairs}
    subprocess.run(["php", "-r", ADMIN_FILE.replace("<?php\n", "", 1), "--", dropdown,
                    json.dumps(as_saved), str(path)], check=True)
    return path


def inserted(pairs: list[tuple[str, object]], after: str, new: tuple[str, object]):
    out = []
    for pair in pairs:
        out.append(pair)
        if pair[0] == after:
            out.append(new)
    return out


def serve(seed: dict, *orders: list[Path]) -> list[dict]:
    with tempfile.TemporaryDirectory(prefix="bd-admin-stages-") as tmp:
        t = Path(tmp)
        (t / "plan.json").write_text(json.dumps({
            "seed": seed, "serves": [[str(p) for p in o] for o in orders]}))
        out = subprocess.run(["php"], input=HARNESS, text=True, cwd=t,
                             capture_output=True, check=True)
        result = json.loads(out.stdout)
    assert result["problems"] == [], result["problems"]
    return [{"stages": [tuple(s) for s in s_["stages"]],
             "probabilities": [tuple(p) for p in s_["probabilities"]]}
            for s_ in result["served"]]


@unittest.skipUnless(shutil.which("php"), "requires php")
class Stack(unittest.TestCase):
    """ERP-Core's template, Partial Fulfillment's fragment and this package's, with
    an admin's saved files where a case has them, in Sugar's merge orders."""

    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix="bd-admin-frags-"))
        self.addCleanup(shutil.rmtree, self.tmp)
        self.erp_core = self.tmp / "en_us.sugarai_erp_epicor.php"
        self.erp_core.write_bytes(ERP_CORE_TEMPLATE.read_bytes())
        self.pf = self.tmp / "_override_en_us.partial_fulfillment_sales_stage.php"
        self.pf.write_bytes(PF_FRAGMENT.read_bytes())
        self.admin: list[Path] = []

    def admin_stages(self, pairs):
        self.admin.append(admin_file(self.tmp / "en_us.sugar_sales_stage_dom.php",
                                     "sales_stage_dom", pairs))

    def admin_probabilities(self, pairs):
        self.admin.append(admin_file(self.tmp / "en_us.sugar_sales_probability_dom.php",
                                     "sales_probability_dom", pairs))

    def orders(self, admin_newer=(True, False)) -> dict[str, list[Path]]:
        """By first-seen mtime, through Sugar's sort: the admin's files newer or older
        than ERP-Core's, and Partial Fulfillment's before or after this package's."""
        found = {}
        for newer in admin_newer:
            for pf_first in (True, False):
                mtimes = {self.erp_core: 2 if newer else 5}
                for admin in self.admin:
                    mtimes[admin] = 4 if newer else 1
                mtimes[self.pf] = 3 if pf_first else 6
                mtimes[FRAGMENT] = 6 if pf_first else 3
                names = {str(p): p for p in mtimes}
                order = [names[n] for n in sugar_order([(str(p), m) for p, m in mtimes.items()])]
                name = (("admin edited after ERP-Epicor's install" if newer
                         else "ERP-Epicor installed after the admin's edit")
                        + (", PF before this package" if pf_first else ", PF after this package"))
                found[name] = order
        return found

    def served(self, admin_newer=(True, False)) -> dict[str, dict]:
        orders = self.orders(admin_newer)
        return dict(zip(orders, serve(STOCK, *orders.values())))

    def assertBenchKeysAsTable(self, served, but=()):
        stages = dict(served["stages"])
        probabilities = dict(served["probabilities"])
        for key, label, probability in TABLE:
            if key in but:
                continue
            with self.subTest(key=key):
                self.assertEqual((stages.get(key), probabilities.get(key)), (label, probability))


class NoAdminList(Stack):
    def test_exactly_todays_list_and_probabilities_in_every_order(self):
        """Keys, labels, order and probabilities as the table, and the values typed as
        the table types them: Sugar's own '10', '100' and '0' and its '65' and ERP-Core's
        'Prospecting' and 'Proposal-Quoting' are shipped values, not an admin's."""
        for name, served in self.served().items():
            with self.subTest(order=name):
                self.assertEqual(served["stages"], TABLE_STAGES)
                self.assertEqual(served["probabilities"], TABLE_PROBABILITIES)


class AnAdminAddsAStage(Stack):
    def setUp(self):
        super().setUp()
        self.admin_stages(inserted(TABLE_STAGES, "Estimating", ("Priced", "Priced")))
        self.admin_probabilities(inserted(TABLE_PROBABILITIES, "Estimating", ("Priced", 22)))

    def test_priced_stays_between_estimating_and_quote_delivered(self):
        want = [k for k, _ in inserted(TABLE_STAGES, "Estimating", ("Priced", "Priced"))]
        self.assertEqual(want[1:4], ["Estimating", "Priced", "Proposal/Price Quote"])
        for name, served in self.served().items():
            with self.subTest(order=name):
                self.assertEqual([k for k, _ in served["stages"]], want)
                self.assertEqual(dict(served["stages"])["Priced"], "Priced")
                self.assertEqual([k for k, _ in served["probabilities"]], want)
                self.assertEqual(dict(served["probabilities"])["Priced"], "22")

    def test_the_stages_of_this_package_are_untouched(self):
        for name, served in self.served().items():
            with self.subTest(order=name):
                self.assertBenchKeysAsTable(served)

    def test_partial_fulfillments_two_stages_keep_their_labels_and_probabilities(self):
        for name, served in self.served().items():
            with self.subTest(order=name):
                stages, probabilities = dict(served["stages"]), dict(served["probabilities"])
                self.assertEqual((stages["Prototype Ordered"], probabilities["Prototype Ordered"]),
                                 ("Prototype Ordered", 80))
                self.assertEqual((stages["Partial Production Ordered"],
                                  probabilities["Partial Production Ordered"]),
                                 ("Partial Production Ordered", 90))


class AnAdminStageWithoutAProbability(Stack):
    def test_the_stage_is_kept_and_given_no_probability(self):
        """Only the stage list was edited: the stage is served, and no probability is
        made up for it (the admin sets one in the probability list)."""
        self.admin_stages(inserted(TABLE_STAGES, "Estimating", ("Priced", "Priced")))
        for name, served in self.served().items():
            with self.subTest(order=name):
                self.assertIn("Priced", dict(served["stages"]))
                self.assertNotIn("Priced", dict(served["probabilities"]))
                self.assertEqual(served["probabilities"], TABLE_PROBABILITIES)


class AnAdminRelabels(Stack):
    def test_an_admins_label_for_prospecting_is_kept(self):
        """The admin's file is the newer, so it reaches this package as saved."""
        self.admin_stages([(k, "Qualifying" if k == "Prospecting" else v) for k, v in TABLE_STAGES])
        for name, served in self.served(admin_newer=(True,)).items():
            with self.subTest(order=name):
                self.assertEqual(served["stages"][0], ("Prospecting", "Qualifying"))
                self.assertBenchKeysAsTable(served, but=("Prospecting",))
                self.assertEqual([k for k, _ in served["stages"]], BENCH_KEYS)

    def test_an_admins_label_on_a_key_erp_core_leaves_alone_is_kept_in_every_order(self):
        self.admin_stages([(k, "Estimating (Eng)" if k == "Estimating" else v)
                           for k, v in TABLE_STAGES])
        for name, served in self.served().items():
            with self.subTest(order=name):
                self.assertEqual(dict(served["stages"])["Estimating"], "Estimating (Eng)")
                self.assertBenchKeysAsTable(served, but=("Estimating",))
                self.assertEqual([k for k, _ in served["stages"]], BENCH_KEYS)


class AnAdminChangesAProbability(Stack):
    def test_the_admins_probability_is_kept(self):
        self.admin_probabilities([(k, 30 if k == "Proposal/Price Quote" else v)
                                  for k, v in TABLE_PROBABILITIES])
        for name, served in self.served().items():
            with self.subTest(order=name):
                self.assertEqual(dict(served["probabilities"])["Proposal/Price Quote"], "30")
                self.assertBenchKeysAsTable(served, but=("Proposal/Price Quote",))


class AnAdminDeletesAStage(Stack):
    def test_the_missing_stage_comes_back_at_its_place(self):
        self.admin_stages([(k, v) for k, v in TABLE_STAGES if k != "Negotiation/Revision"])
        self.admin_probabilities([(k, v) for k, v in TABLE_PROBABILITIES
                                  if k != "Negotiation/Revision"])
        for name, served in self.served().items():
            with self.subTest(order=name):
                self.assertEqual(served["stages"], TABLE_STAGES)
                self.assertEqual(served["probabilities"], TABLE_PROBABILITIES)


class TheErpCoreTemplateRanLast(Stack):
    """ERP-Epicor installed after the admin's edit: ERP-Core's template has put
    Prospecting, Proposal/Price Quote, Closed Won and Closed Lost first, with its own
    labels, before this package runs."""

    def test_a_stage_added_after_prospecting_stays_before_estimating(self):
        """Not after Closed Lost, where the nearest earlier key now is."""
        self.admin_stages(inserted(TABLE_STAGES, "Prospecting", ("Priced", "Priced")))
        for name, served in self.served().items():
            with self.subTest(order=name):
                self.assertEqual([k for k, _ in served["stages"]][:3],
                                 ["Prospecting", "Priced", "Estimating"])
                self.assertBenchKeysAsTable(served)

    def test_a_label_erp_core_rewrote_first_is_this_packages_again(self):
        """What this package can see: the admin's Prospecting label is already gone,
        rewritten by ERP-Core's template, so the stage shows this package's label, not
        ERP-Core's. Keeping the admin's label here needs ERP-Core's template to merge."""
        self.admin_stages([(k, "Qualifying" if k == "Prospecting" else v) for k, v in TABLE_STAGES])
        for name, served in self.served(admin_newer=(False,)).items():
            with self.subTest(order=name):
                self.assertEqual(served["stages"], TABLE_STAGES)


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheShippedValuesAreDerived(unittest.TestCase):
    """The fragment's table of values other layers put on this package's keys is a
    hand copy (a language fragment cannot read another package's files). Derived
    here from Sugar's list, ERP-Core's template and PF's fragment; equal both ways."""

    def derived(self) -> dict[str, set]:
        tmp = Path(tempfile.mkdtemp(prefix="bd-shipped-"))
        self.addCleanup(shutil.rmtree, tmp)
        erp_core = tmp / "en_us.sugarai_erp_epicor.php"
        erp_core.write_bytes(ERP_CORE_TEMPLATE.read_bytes())
        pf = tmp / "_override_en_us.partial_fulfillment_sales_stage.php"
        pf.write_bytes(PF_FRAGMENT.read_bytes())
        stacks = serve(STOCK, [], [erp_core], [pf], [erp_core, pf])
        defaults = {key: (label, probability) for key, label, probability in TABLE}
        labels, probabilities = set(), set()
        for served in stacks:
            for key, label in served["stages"]:
                if key in defaults and str(label) != defaults[key][0]:
                    labels.add((key, str(label)))
            for key, probability in served["probabilities"]:
                if key in defaults and str(probability) != str(defaults[key][1]):
                    probabilities.add((key, str(probability)))
        return {"labels": labels, "probabilities": probabilities}

    def fragment_table(self) -> dict[str, set]:
        """The fragment's own two tables, read by running it with its last line (the
        unset) cut off."""
        source = FRAGMENT.read_text()
        body, cut = re.subn(r"\nunset\([^;]*\);\s*\Z", "\n", source)
        self.assertEqual(cut, 1, "the fragment no longer ends with its unset() line")
        probe = (body.replace("<?php", "", 1) +
                 "\necho json_encode(['labels' => $bdShippedLabels,"
                 " 'probabilities' => $bdShippedProbabilities]);")
        out = json.loads(subprocess.run(["php", "-r", probe], capture_output=True, text=True,
                                        check=True).stdout)
        return {kind: {(key, str(value)) for key, values in out[kind].items() for value in values}
                for kind in ("labels", "probabilities")}

    def test_the_table_is_what_the_other_layers_ship(self):
        derived, table = self.derived(), self.fragment_table()
        self.assertTrue(derived["labels"], "the control is empty: nothing to recognise")
        self.assertEqual(table, derived)


if __name__ == "__main__":
    unittest.main()
