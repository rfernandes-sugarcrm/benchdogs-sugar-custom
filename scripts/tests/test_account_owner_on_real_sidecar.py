#!/usr/bin/env python3
"""Runs account_owner_on_real_sidecar.cjs: a new Opportunity's or Quote's Assigned To follows its account's owner on
the create form, by this package's bd-account-owner view on Sugar's own Sidecar 26.1.0, until the person picks an
owner. The page's own header says what it proves and what it does not.

It needs node and the pinned jsdom (npm ci --ignore-scripts --prefix scripts/tests/fixtures/real-sidecar/scripts/
tests/dom). CI installs both, and a page test that does not run is not a guard.
"""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
PAGE = HERE / "account_owner_on_real_sidecar.cjs"
#: The controller's declaration, both modules, the subpanel form, four pick cases, the account cases, a late answer,
#: a refused read, a copy, and the existing-record control; rc123: three on the quote's top Account Name.
EXPECTED_TESTS = 16


class TheAccountOwnerOnSugarsOwnSidecar(unittest.TestCase):
    def test_the_page_runs_and_passes(self):
        node = shutil.which("node")
        self.assertIsNotNone(node, "node is required (CI installs it)")
        out = subprocess.run([node, "--test", str(PAGE)], capture_output=True, text=True, timeout=180)
        summary = out.stdout + out.stderr
        self.assertEqual(out.returncode, 0, summary[-4000:])
        passed = re.search(r"^\S* ?pass (\d+)$", summary, re.M)
        self.assertIsNotNone(passed, summary[-2000:])
        self.assertEqual(int(passed.group(1)), EXPECTED_TESTS, "every page test ran: " + summary[-2000:])


if __name__ == "__main__":
    unittest.main()
