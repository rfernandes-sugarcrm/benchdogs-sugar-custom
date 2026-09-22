"""The release-stage STYLES moved to Partial Fulfillment with the stages.

🛑 WHY A STYLE IS NOT DECORATION HERE (L-0004). Opportunities renders sales_stage
through Sugar's formatted enum-cascade: with applyFormatting on, a domain key
that has no matching style renders BLANK on the record even though the model and
the label are correct. So the styles had to travel with the keys, and PF ships
both (G278 / 🔒 1506, partial_fulfillment_sales_stage_style.php, each entry
guarded on the key so a tenant's own styling is never overwritten).

This package therefore ships the path EMPTY (0.9.42-rc65, G280 / 🔒 1507).
Emptied, not dropped: Sugar loads this fragment by path from every tenant that
ever installed one carrying it, and Module Loader deletes nothing (§CW / G37) -
leaving it out of the build would leave the old styles live and two packages
styling one key.
"""

import json
from pathlib import Path
import shutil
import subprocess
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"
STYLE = (
    PACKAGE / "custom/Extension/application/Ext/DropdownsStyle"
    / "sales_stage_dom_style.php"
)


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class StageDropdownStyleRetiredTest(unittest.TestCase):
    def test_the_fragment_ships_and_styles_nothing(self):
        """EXECUTED with a seeded style array: including the file twice (an
        upgrade re-merge) must leave the tenant's styles exactly as they were."""
        fixture = rf'''<?php
$app_dropdowns_style = [
    'sales_stage_dom_style' => [
        'Customer Stage' => ['backgroundColor' => '#123456'],
        'applyFormatting' => true,
    ],
];
require {json.dumps(str(STYLE))};
require {json.dumps(str(STYLE))};
echo json_encode($app_dropdowns_style['sales_stage_dom_style']);
'''
        self.assertTrue(STYLE.is_file(), "the style fragment must keep shipping, emptied")
        result = subprocess.run(["php"], input=fixture, text=True, capture_output=True, check=True)
        styles = json.loads(result.stdout)
        self.assertEqual(styles, {"Customer Stage": {"backgroundColor": "#123456"},
                                  "applyFormatting": True},
                         "this package is styling stage keys again; PF owns them")

    def test_the_built_package_still_carries_the_path(self):
        version = (PACKAGE / "version").read_text().strip()
        archive = PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        with zipfile.ZipFile(archive) as zf:
            member = "custom/Extension/application/Ext/DropdownsStyle/sales_stage_dom_style.php"
            self.assertIn(member, zf.namelist(),
                          "dropping the path leaves the old Bench styles live on every tenant")
            body = zf.read(member).decode("utf-8")
            for key in ("Prototype Ordered", "Partial Production Ordered", "backgroundColor"):
                self.assertNotIn(key, body.split("*/", 1)[-1],
                                 "the shipped fragment still declares a style")

    def test_core_ships_the_styles_now(self):
        """The control: if PF stopped shipping them, the two stages would render
        blank on every Opportunity that holds one."""
        body = _pf_style_text()
        if body is None:
            self.skipTest("erp-integration-sugar checkout not present beside this repo")
        for key in ("Prototype Ordered", "Partial Production Ordered"):
            self.assertIn(key, body, f"Partial Fulfillment no longer styles {key}")


#: PF's style file, as TEXT. Read from the sibling checkout's working tree when
#: it happens to be on a branch that has it, otherwise from git history - the
#: sibling is usually parked on an older build branch, and a control that skips
#: whenever that is true is a control that never runs.
PF_STYLE_REL = ("sugar-sell/ERP-Epicor-PartialFulfillment/custom/Extension/application"
                "/Ext/DropdownsStyle/partial_fulfillment_sales_stage_style.php")


def _sibling_repo():
    candidates = [ROOT.parent / "erp-integration-sugar"]
    try:
        common = subprocess.run(["git", "-C", str(ROOT), "rev-parse", "--git-common-dir"],
                                capture_output=True, text=True, check=True).stdout.strip()
        candidates.append((ROOT / common).resolve().parent.parent / "erp-integration-sugar")
    except (OSError, subprocess.CalledProcessError):
        pass
    for cand in candidates:
        if (cand / ".git").exists() or cand.is_dir():
            return cand
    return None


def _pf_style_text():
    repo = _sibling_repo()
    if repo is None:
        return None
    live = repo / PF_STYLE_REL
    if live.is_file():
        return live.read_text(encoding="utf-8", errors="replace")
    found = subprocess.run(["git", "-C", str(repo), "log", "--all", "--format=%H", "-1",
                            "--", PF_STYLE_REL], capture_output=True, text=True)
    sha = found.stdout.strip().splitlines()
    if not sha:
        return None
    shown = subprocess.run(["git", "-C", str(repo), "show", f"{sha[0]}:{PF_STYLE_REL}"],
                           capture_output=True, text=True)
    return shown.stdout if shown.returncode == 0 else None


if __name__ == "__main__":
    unittest.main()
