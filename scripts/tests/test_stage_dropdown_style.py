"""The release-stage STYLES moved to Partial Fulfillment with the stages.

🛑 WHY A STYLE IS NOT DECORATION HERE (L-0004). Opportunities renders sales_stage
through Sugar's formatted enum-cascade: with applyFormatting on, a domain key
that has no matching style renders BLANK on the record even though the model and
the label are correct. So the styles had to travel with the keys, and PF ships
both (G278 / 🔒 1506, partial_fulfillment_sales_stage_style.php, each entry
guarded on the key so a tenant's own styling is never overwritten).

This package shipped the path EMPTY from 0.9.42-rc65 (G280 / 🔒 1507), because
Sugar loads this fragment by path from every tenant that ever installed one
carrying it and Module Loader deletes nothing (§CW / G37) - merely leaving it out
of the build would have left the old styles live and two packages styling one
key. From rc69 (G280 / 🔒 1567, 🔒 1521) the one-off ONEOFF-RetireBdResidue
DELETES the path on the tenant, so the package stops shipping it; the cases
below assert both halves of that.
"""

import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"
STYLE = (
    PACKAGE / "custom/Extension/application/Ext/DropdownsStyle"
    / "sales_stage_dom_style.php"
)


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class StageDropdownStyleRetiredTest(unittest.TestCase):
    def test_no_shipped_file_styles_a_stage_key(self):
        """EXECUTED with a seeded style array: including every PHP file this
        package ships must leave the tenant's styles exactly as they were -
        which also covers a style that moves to a new path."""
        shipped = [p for top in ("custom", "scripts")
                   for p in sorted((PACKAGE / top).rglob("*.php"))
                   if "Extension" in p.parts]
        self.assertTrue(shipped, "no Extension fragment found - PACKAGE points at nothing")
        requires = "".join(f"require {json.dumps(str(p))};\n" for p in shipped)
        fixture = f"""<?php
$dictionary = []; $mod_strings = [];
$app_dropdowns_style = [
    'sales_stage_dom_style' => [
        'Customer Stage' => ['backgroundColor' => '#123456'],
        'applyFormatting' => true,
    ],
];
{requires}
echo json_encode($app_dropdowns_style['sales_stage_dom_style']);
"""
        result = subprocess.run(["php"], input=fixture, text=True, capture_output=True, check=True)
        styles = json.loads(result.stdout)
        self.assertEqual(styles, {"Customer Stage": {"backgroundColor": "#123456"},
                                  "applyFormatting": True},
                         "this package is styling stage keys again; PF owns them")

    def test_the_style_fragment_is_retired_off_the_tenant(self):
        """Not shipped, not in the built zip, and still on the one-off's
        worklist - dropping the path alone would leave the old Bench styles live
        on every tenant that has it."""
        from bd_retirement import assert_retired_by_oneoff
        assert_retired_by_oneoff(self, str(STYLE.relative_to(PACKAGE)), "the old Bench stage styles")

    def test_core_ships_the_styles_now(self):
        """The control: if PF stopped shipping them, the two stages would render
        blank on every Opportunity that holds one."""
        body = _pf_style_text()
        self.assertIsNotNone(body, "PF's pinned style copy is missing from fixtures/g268")
        for key in ("Prototype Ordered", "Partial Production Ordered"):
            self.assertIn(key, body, f"Partial Fulfillment no longer styles {key}")


#: PF's style file, as TEXT, from the copy pinned under fixtures/g268 (see
#: PF_PROVENANCE.json there). Pinned because the sibling repo is private and
#: absent in CI; test_g268_retired_stage_names.py compares the pin against the
#: live file whenever that is reachable, so a stale pin is loud.
PF_STYLE_PIN = ROOT / "scripts/tests/fixtures/g268/pf_sales_stage_style.php"


def _pf_style_text():
    return PF_STYLE_PIN.read_text(encoding="utf-8") if PF_STYLE_PIN.is_file() else None


if __name__ == "__main__":
    unittest.main()
