#!/usr/bin/env python3
"""Runs project_required_on_real_sidecar.cjs: an Epicor-born quote with no Project, FOB or Ship Via saves in Sugar's own
record view, a new quote's create form asks for the other four pickers but not Project, and since rc117 draws,
offers and asks for no quote-level Project at all, the Project being each line's own (Sidecar, the stock
RecordView, CreateView and Quotes create view, recorded from SugarEnt 26.1.0 and 26.2.0, with ERP-Core's real
erp-dependent-enum and erp-lookup fields under this package's shipped vardef and marker). The page's own header says
what it proves and what it does not.

It needs node, php and the pinned jsdom (npm ci --ignore-scripts --prefix scripts/tests/fixtures/real-sidecar/scripts/
tests/dom). None missing is a skip: CI installs them, and a page test that does not run is not a guard.
"""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
PAGE = HERE / "project_required_on_real_sidecar.cjs"
#: Two Sugars, five cases each, plus the set-up check.
EXPECTED_TESTS = 11


class TheProjectRuleOnSugarsOwnRecordView(unittest.TestCase):
    def test_the_page_runs_and_passes(self):
        node = shutil.which("node")
        self.assertIsNotNone(node, "node is required (CI installs it)")
        out = subprocess.run([node, "--test", str(PAGE)], capture_output=True, text=True, timeout=120)
        summary = out.stdout + out.stderr
        self.assertEqual(out.returncode, 0, summary[-4000:])
        passed = re.search(r"^\S* ?pass (\d+)$", summary, re.M)
        self.assertIsNotNone(passed, summary[-2000:])
        self.assertEqual(int(passed.group(1)), EXPECTED_TESTS, "every page test ran: " + summary[-2000:])


if __name__ == "__main__":
    unittest.main()
