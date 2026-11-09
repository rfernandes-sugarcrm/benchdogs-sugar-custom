"""The ERP-Epicor 1.2.107 layout copies under fixtures/rc107/ are the real files.

bd_cost_capacity_section_test.php runs them as ERP-Epicor's own install of the
quoted line item's record view. A copy edited by hand, or left behind when
ERP-Epicor moves on, would grade the Bench section against a layout no tenant
runs, so each copy is held to its recorded hash everywhere and, where the
sibling checkout has the source commit, to that commit's bytes.

IF IT WERE BROKEN you would see: test_each_copy_matches_its_provenance fail on
an edited copy, and test_each_copy_matches_its_source_commit_where_reachable
fail on one that no longer matches erp-integration-sugar at the named commit.
"""
from __future__ import annotations

import hashlib
import json
import pathlib
import shutil
import subprocess
import unittest

import shared_sugar

FIX = pathlib.Path(__file__).resolve().parent / "fixtures/rc107"
PROVENANCE = json.loads((FIX / "PROVENANCE.json").read_text())


class TheCopiesAreTheRealFiles(unittest.TestCase):
    def test_the_folder_holds_exactly_the_provenance(self):
        on_disk = sorted(p.name for p in FIX.iterdir() if p.is_file())
        self.assertEqual(on_disk, sorted([*PROVENANCE["files"], "PROVENANCE.json"]))

    def test_each_copy_matches_its_provenance(self):
        for name, meta in PROVENANCE["files"].items():
            with self.subTest(file=name):
                data = (FIX / name).read_bytes()
                self.assertEqual(hashlib.sha256(data).hexdigest(), meta["sha256"])
                self.assertEqual(len(data), meta["bytes"])

    def test_the_stock_view_names_the_blob_it_was_recorded_from(self):
        meta = PROVENANCE["files"]["products_record_stock.json"]
        recorded = json.loads((FIX / "products_record_stock.json").read_text())
        self.assertIn(meta["git_blob"], recorded["recorded"])
        self.assertIn(meta["source"], recorded["recorded"])
        panels = recorded["viewdefs"]["Products"]["base"]["view"]["record"]["panels"]
        self.assertEqual([p["name"] for p in panels], ["panel_header", "panel_body", "panel_hidden"])

    def test_each_copy_matches_its_source_commit_where_reachable(self):
        """Loud where the history is reachable (every local run); in CI, which has no
        git and no sibling, the hash case above holds the copies still."""
        git = shutil.which("git")
        if git is None or not shared_sugar.SIBLING.is_dir():
            return
        compared = 0
        for name, meta in PROVENANCE["files"].items():
            if meta["repository"] != "erp-integration-sugar":
                continue
            shown = subprocess.run(
                [git, "-C", str(shared_sugar.SIBLING), "show", f"{meta['commit']}:{meta['source']}"],
                capture_output=True,
            )
            if shown.returncode != 0:
                continue
            compared += 1
            with self.subTest(file=name):
                self.assertEqual(shown.stdout, (FIX / name).read_bytes(), f"{name} drifted from its commit")
        self.assertGreaterEqual(compared, 1, "the sibling is present but no copy was compared; fetch the commit")


if __name__ == "__main__":
    unittest.main()
