"""Decision 72's auto-selected review report: its filter, columns and scope.

WHAT WENT, AND WHY THIS FILE IS NOW ONLY THE REPORT
---------------------------------------------------
This file used to carry decision 72's BLAST RADIUS as well - the gate deciding
WHEN `BdGoverningAutoSelectHook` could fire (created lines only, never an update
or a resync) and WHETHER the marker could lie. Decision 901/903 retired the
`bd01_*` quote mirror, taking `BdGoverningAutoSelect`, `BdGoverningAutoSelectHook`,
`BdGoverningLineHook` and the one-off `bd_governing_backfill.php` with it. A gate
on a hook that no longer exists cannot be held open or shut, so those tests were
deleted rather than repointed. Bench no longer selects anything: the per-line
governing pin is `Products.erp_governing`, owned by ERP-Epicor-PartialFulfillment,
and the exactly-one rule is proved in the package that enforces it -
`erp-integration-sugar/scripts/tests/test_line_rollup_refusal_contract.py`
(`test_refusal_at_zero_pins_*`, `test_refusal_at_two_pins_*`).

The cross-check test that pinned this report's literal `'auto'` against
`BdGoverningAutoSelect::ORIGIN_AUTO` went with the selector: there is no longer a
second definition of the constant for it to drift from.

🚩 REPORTED, NOT FIXED HERE: `BdAutoSelectedReport` still ships and still filters
Opportunities on `bd_governing_origin = 'auto'`, but the only code that ever wrote
'auto' was `BdGoverningAutoSelect`, and the only code that cleared it was
`BdGoverningLineHook` - both retired. Bench's own `scripts/post_install.php` now
records that "there is no automatic selection left to run". So the report is
well-formed and permanently empty. Whether it should still ship is a decision
about `custom/`, not about this test: the assertions below describe the report
that IS built, and they are what would notice it silently losing its filter.
"""

import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = ROOT.parent
REPO = ROOT.name
REL = f"{REPO}/sugar-sell/BenchDogs-Ext"


@unittest.skipUnless(shutil.which("php"), "requires a PHP CLI")
class AutoSelectedReportTest(unittest.TestCase):
    def report_def(self):
        result = subprocess.run(
            ["php", "-d", "error_reporting=E_ALL & ~E_DEPRECATED", "-r",
             "class SugarBean {} "
             "require '" + REL + "/custom/modules/Opportunities/BdAutoSelectedReport.php';"
             "echo json_encode((new BdAutoSelectedReport())->reportDef());"],
            cwd=WORKSPACE, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "", result.stderr)
        return json.loads(result.stdout)

    def test_the_report_filters_on_the_auto_marker_and_nothing_wider(self):
        """A report that quietly lost its filter would list the whole pipeline
        and read as a catastrophe rather than as a bug."""
        definition = self.report_def()
        self.assertEqual(definition["module"], "Opportunities")
        self.assertEqual(definition["report_type"], "tabular")
        primary = definition["filters_def"]["Filter_1"]["0"]
        self.assertEqual(primary["name"], "bd_governing_origin")
        self.assertEqual(primary["qualifier_name"], "equals")
        self.assertEqual(primary["input_name0"], "auto")
        self.assertEqual(primary["table_key"], "self")

    def test_the_marker_is_a_visible_column_not_only_a_filter(self):
        columns = [c["name"] for c in self.report_def()["display_columns"]]
        self.assertIn("bd_governing_origin", columns)
        self.assertIn("amount", columns)

    def test_closed_deals_are_not_a_review_queue(self):
        closed = self.report_def()["filters_def"]["Filter_1"]["1"]
        self.assertEqual(closed["name"], "sales_stage")
        self.assertEqual(closed["qualifier_name"], "not_one_of")
        self.assertEqual(sorted(closed["input_name0"]), ["Closed Lost", "Closed Won"])

    def test_every_display_column_names_a_table_the_report_joins(self):
        """A column on a table_key absent from full_table_list renders blank."""
        definition = self.report_def()
        known = set(definition["full_table_list"].keys())
        for column in definition["display_columns"]:
            self.assertIn(column["table_key"], known, column)
        for key, row in definition["filters_def"]["Filter_1"].items():
            if key == "operator":
                continue
            self.assertIn(row["table_key"], known, row)


if __name__ == "__main__":
    unittest.main()
