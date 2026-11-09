"""The ERP-Epicor copies under fixtures/rc111/ are the real files.

bd_order_line_total_test.php builds the ERP order's record and list views from
them. A copy edited by hand would grade the Bench overlays against views and
fields no tenant has, so each copy is held to its recorded hash everywhere and,
where the sibling checkout has the source commit, to that commit's bytes. The
suite reuses fixtures/rc107/BaseErpLayout.php.txt as ERP-Epicor 1.2.112's
layout installer, so that copy is also held to 1.2.112's bytes here.

IF IT WERE BROKEN you would see: test_each_copy_matches_its_provenance fail on
an edited copy, and test_each_copy_matches_its_source_commit_where_reachable or
test_the_reused_layout_installer_is_1_2_112s fail on one that no longer matches
erp-integration-sugar at the named commit.
"""
from __future__ import annotations

import hashlib
import json
import pathlib
import shutil
import subprocess
import unittest

import shared_sugar

HERE = pathlib.Path(__file__).resolve().parent
FIX = HERE / "fixtures/rc111"
PROVENANCE = json.loads((FIX / "PROVENANCE.json").read_text())
INSTALLER = HERE / "fixtures/rc107/BaseErpLayout.php.txt"
INSTALLER_COMMIT = "e96897e9528f945ce795364045e271cadd5082fe"
INSTALLER_SOURCE = "sugar-sell/ERP-Core/scripts/BaseErpLayout.php"


def _show(commit: str, source: str) -> bytes | None:
    """The file at that commit in the sibling checkout, or None where git or the commit is not reachable."""
    git = shutil.which("git")
    if git is None or not shared_sugar.SIBLING.is_dir():
        return None
    shown = subprocess.run(
        [git, "-C", str(shared_sugar.SIBLING), "show", f"{commit}:{source}"], capture_output=True,
    )
    return shown.stdout if shown.returncode == 0 else None


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

    def test_the_two_record_views_differ_only_by_the_lines_total(self):
        """The premise the suite's upgrade case rests on: 1.2.112 adds erp_line_total after erp_misc_amount and
        changes nothing else in the order's record view."""
        old = (FIX / "ERP_Orders.record.1.2.107.php.txt").read_text()
        new = (FIX / "ERP_Orders.record.1.2.112.php.txt").read_text()
        self.assertNotIn("erp_line_total", old)
        self.assertEqual(new.count("'erp_line_total'"), 1)
        self.assertLess(new.index("'erp_misc_amount'"), new.index("'erp_line_total'"))

    def test_each_copy_matches_its_source_commit_where_reachable(self):
        """Loud where the history is reachable (every local run); in CI, which has no sibling, the hash case above
        holds the copies still."""
        if shutil.which("git") is None or not shared_sugar.SIBLING.is_dir():
            return
        compared = 0
        for name, meta in PROVENANCE["files"].items():
            shown = _show(meta["commit"], meta["source"])
            if shown is None:
                continue
            compared += 1
            with self.subTest(file=name):
                self.assertEqual(shown, (FIX / name).read_bytes(), f"{name} drifted from its commit")
        self.assertGreaterEqual(compared, 1, "the sibling is present but no copy was compared; fetch the commits")

    def test_the_reused_layout_installer_is_1_2_112s(self):
        shown = _show(INSTALLER_COMMIT, INSTALLER_SOURCE)
        if shown is None:
            return
        self.assertEqual(shown, INSTALLER.read_bytes(), "ERP-Epicor 1.2.112's BaseErpLayout is not the rc107 copy")


if __name__ == "__main__":
    unittest.main()
