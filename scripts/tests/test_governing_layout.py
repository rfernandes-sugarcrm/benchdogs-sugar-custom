#!/usr/bin/env python3
"""Regression coverage for the governing selector on ERP Quote Line records."""

from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
RECORD_METADATA = ROOT / (
    "sugar-sell/BenchDogs-Ext/modules/bd01_ERP_Quote_Line/"
    "clients/base/views/record/record.php"
)


class GoverningRecordLayoutTest(unittest.TestCase):
    def test_governing_is_editable_in_the_primary_record_panel(self) -> None:
        source = RECORD_METADATA.read_text(encoding="utf-8")
        panel_body = source.split("'name' => 'panel_body'", 1)[1]
        panel_body = panel_body.split("'name' => 'panel_hidden'", 1)[0]

        self.assertIn("=> 'governing',", panel_body)
        self.assertNotIn("'name' => 'governing',\n                'readonly' => true", panel_body)


if __name__ == "__main__":
    unittest.main()
