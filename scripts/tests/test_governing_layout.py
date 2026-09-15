#!/usr/bin/env python3
"""Regression coverage for the governing selector on ERP Quote Line records."""

from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
VIEWS = ROOT / (
    "sugar-sell/BenchDogs-Ext/modules/bd01_ERP_Quote_Line/clients/base/views"
)
RECORD_METADATA = VIEWS / "record/record.php"

# The three list/subpanel views §TIER1-2 measured `governing` as absent from.
# It was on all three until 5a5c399 imported the sandbox's parallel 0.9.29
# build over this line of work; the removal was collateral, never a decision.
SELLER_VIEWS = (
    VIEWS / (
        "subpanel-for-bd01_erp_quote-bd01_erp_quote_lines/"
        "subpanel-for-bd01_erp_quote-bd01_erp_quote_lines.php"
    ),
    VIEWS / "subpanel-list/subpanel-list.php",
    VIEWS / "list/list.php",
)


class GoverningRecordLayoutTest(unittest.TestCase):
    def test_governing_is_editable_in_the_primary_record_panel(self) -> None:
        source = RECORD_METADATA.read_text(encoding="utf-8")
        panel_body = source.split("'name' => 'panel_body'", 1)[1]
        panel_body = panel_body.split("'name' => 'panel_hidden'", 1)[0]

        self.assertIn("=> 'governing',", panel_body)
        self.assertNotIn("'name' => 'governing',\n                'readonly' => true", panel_body)

    def test_the_auto_selection_marker_is_on_the_row_it_is_a_fact_about(self) -> None:
        source = RECORD_METADATA.read_text(encoding="utf-8")
        panel_body = source.split("'name' => 'panel_body'", 1)[1]
        panel_body = panel_body.split("'name' => 'panel_hidden'", 1)[0]

        self.assertIn("=> 'bd_governing_origin',", panel_body)

    def test_a_seller_can_see_which_break_governs_without_opening_a_line(self) -> None:
        for view in SELLER_VIEWS:
            with self.subTest(view=view.name):
                self.assertIn(
                    "'name' => 'governing',", view.read_text(encoding="utf-8")
                )


if __name__ == "__main__":
    unittest.main()
