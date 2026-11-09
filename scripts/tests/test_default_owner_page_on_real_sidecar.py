#!/usr/bin/env python3
"""Runs default_owner_page_on_real_sidecar.cjs: the Bench Dogs admin page for the default owner of ERP-created
records, drawn by Sugar's own Sidecar, relate field and select2 (26.1.0 and 26.2.0), talking to this package's real
endpoint through php. The page's own header says what it proves and what it does not.

It needs node, php and the pinned jsdom (npm ci --ignore-scripts --prefix scripts/tests/fixtures/real-sidecar/scripts/
tests/dom). None missing is a skip: CI installs all three, and a page test that does not run is not a guard.
"""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
PAGE = HERE / "default_owner_page_on_real_sidecar.cjs"
#: Two Sugars, eight cases each, plus the recordings check and the admin link.
EXPECTED_TESTS = 18


class TheDefaultOwnerPageOnSugarsOwnCode(unittest.TestCase):
    def test_the_page_runs_and_passes(self):
        node = shutil.which("node")
        self.assertIsNotNone(node, "node is required (CI installs it)")
        self.assertIsNotNone(shutil.which("php"), "php is required (CI installs it)")
        out = subprocess.run([node, "--test", str(PAGE)], capture_output=True, text=True, timeout=180)
        summary = out.stdout + out.stderr
        self.assertEqual(out.returncode, 0, summary[-4000:])
        passed = re.search(r"^\S* ?pass (\d+)$", summary, re.M)
        self.assertIsNotNone(passed, summary[-2000:])
        self.assertEqual(int(passed.group(1)), EXPECTED_TESTS, "every page test ran: " + summary[-2000:])


if __name__ == "__main__":
    unittest.main()
