#!/usr/bin/env python3
"""Runs ship_via_required_on_real_sidecar.cjs: Ship Via is never required on a quote (Sugar's own check lets a Save
without it through, at every stage, on the create form too, with no "Required" text and no red; Submit Order and
Order Selected Lines check it), in Sugar's own record view and create form (Sidecar, the stock RecordView, CreateView
and Quotes create view, and record-decor, recorded from SugarEnt 26.1.0 and 26.2.0, with ERP-Core's real erp-lookup
field under this package's controller). The page's own header says what it proves and what it does not.

It needs node and the pinned jsdom (npm ci --ignore-scripts --prefix scripts/tests/fixtures/real-sidecar/scripts/
tests/dom). Neither missing is a skip: CI installs both, and a page test that does not run is not a guard.
"""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
PAGE = HERE / "ship_via_required_on_real_sidecar.cjs"
#: Two Sugars, five cases each (three on the shipped controller, the rc112 and rc103 controls), plus the two set-up
#: checks.
EXPECTED_TESTS = 12


class ShipViaOnSugarsOwnRecordView(unittest.TestCase):
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
