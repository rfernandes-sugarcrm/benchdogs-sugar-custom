#!/usr/bin/env python3
"""Decision 29: the Quote-level bd_governing_line label is retired.

The governing selection is `Products.erp_governing` on the NATIVE Sugar quote
line - a person's choice, read fail-closed by ErpQuoteOpportunityContribution.
(It was `bd01_ERP_Quote_Line.governing` until decisions 901/903 retired the quote
mirror; the Quote-level label was already dead either way.) Nothing writes
Quotes.bd_governing_line, so it must not be shown anywhere:
- on the Bench Dogs Quotes panel of a fresh install;
- left behind on an upgraded tenant, whose panel the append path otherwise
  never touches again;
- on the Account dashlet.
"""

import json
import re
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "sugar-sell/BenchDogs-Ext"
LAYOUT = PKG / "custom/modules/Quotes/BdQuotesLayoutExtensions.php"
DASHBOARDS = PKG / "scripts/BdDemoDashboards.php"
VARDEF = PKG / "custom/Extension/modules/Quotes/Ext/Vardefs/bd_governing_line.php"
PANEL = "LBL_RECORDVIEW_PANEL_BENCHDOGS"
#: The Bench panel as the package ships it. `bd_priced_at` left this list
#: when 🔒 1044 gave it a core owner (`erp_priced_at`); it is now retired FROM
#: the panel rather than placed on it, so it appears in RETIRED_PANEL_FIELDS
#: below instead.
PACKAGED = ["bd_erp_total", "bd_erp_stage", "bd_reason_code"]

HARNESS = r'''
namespace Sugarcrm\Sugarcrm\MetaData {
    class ViewdefManager {
        public static $defs = [];
        public static $saves = 0;
        public function loadViewdef($platform, $module, $view, $loadBase = false, $isLayout = false) {
            return self::$defs;
        }
        public function saveViewdef($viewdef, $module, $platform, $view, $isLayout = false) {
            self::$saves++;
            self::$defs = $viewdef;
        }
    }
}

namespace {
    use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

    class MetaDataFiles {
        public static function clearModuleClientCache($modules = [], $type = '', $platforms = []) {}
    }

    require $argv[1];

    function run(array $panels, bool $replace = false): array {
        ViewdefManager::$defs = ['panels' => $panels];
        ViewdefManager::$saves = 0;
        BdQuotesLayoutExtensions::write($replace);
        $panelsAfter = ViewdefManager::$defs['panels'];
        $deploys = ViewdefManager::$saves;
        BdQuotesLayoutExtensions::write($replace);
        return [
            'panels' => $panelsAfter,
            'deploys' => $deploys,
            'rerun_deploys' => ViewdefManager::$saves - $deploys,
        ];
    }
    $stale = ['name' => 'LBL_RECORDVIEW_PANEL_BENCHDOGS', 'label' => 'LBL_RECORDVIEW_PANEL_BENCHDOGS', 'fields' => [
    ['name' => 'bd_erp_total', 'label' => 'LBL_BD_ERP_TOTAL', 'readonly' => true],
    ['name' => 'bd_governing_line', 'label' => 'LBL_BD_GOVERNING_LINE', 'readonly' => true],
    'description',
]];
    $adminPlaced = ['name' => 'panel_body', 'fields' => ['name', 'bd_governing_line']];
    echo json_encode([
    'upgrade' => run([$adminPlaced, $stale]),
    'fresh' => run([['name' => 'panel_body', 'fields' => ['name']]]),
    'replace' => run([$adminPlaced, $stale], true),
]);
}
'''


def names(panel):
    return [f["name"] if isinstance(f, dict) else f for f in panel["fields"]]


def panel(panels, name):
    return next(p for p in panels if p.get("name") == name)


class GoverningLineRetiredStaticTest(unittest.TestCase):
    def test_packaged_panel_no_longer_lists_the_retired_label(self):
        source = LAYOUT.read_text(encoding="utf-8")
        body = source.split("private static function benchDogsPanel", 1)[1]
        body = body.split("private const RETIRED_PANEL_FIELDS", 1)[0]
        self.assertNotIn("'name' => 'bd_governing_line'", body)
        # Membership, not an exact literal: the list legitimately grows as other
        # fields are retired from this panel (bd_priced_at joined it at 🔒 1044).
        # What must not change is that the removal mechanism exists and still
        # names THIS field - pinning the whole literal made an unrelated
        # retirement look like a regression in this one.
        retired = re.search(
            r"private const RETIRED_PANEL_FIELDS = \[(.*?)\];", source, re.S
        )
        self.assertIsNotNone(retired, "the removal mechanism is gone")
        self.assertIn("bd_governing_line", re.findall(r"'([a-z0-9_]+)'", retired.group(1)))

    def test_account_dashlet_no_longer_lists_the_retired_label(self):
        self.assertNotIn("bd_governing_line", DASHBOARDS.read_text(encoding="utf-8"))

    def test_vardef_is_kept_but_declares_nothing(self):
        """🛑 THIS USED TO ASSERT `'studio' => false` ON A LIVE DECLARATION.
        That was the old retirement: keep the field, hide it from Studio. 🔒 1044
        retired the field outright, and §0.2's mechanism is to EMPTY the vardef
        file to a comment-only stub rather than delete it - on Sugar Cloud only
        an overwrite removes an Extension file a previous install copied, so
        deleting the stub would resurrect the field on every upgraded tenant.

        So the file must still exist, must still be reachable by the installer,
        and must declare NOTHING."""
        source = VARDEF.read_text(encoding="utf-8")
        self.assertTrue(VARDEF.exists(), "keep the file - it overwrites the stale declaration")
        self.assertIn("RETIRED", source.upper())
        self.assertIn("bd_governing_line", source, "the stub must say what it retired")
        code = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
        self.assertNotIn("$dictionary", code, code)


@unittest.skipUnless(shutil.which("php"), "requires the PHP build-test image")
class GoverningLineRetiredInstallTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with tempfile.TemporaryDirectory() as sugar:
            handler = Path(sugar, "include/TemplateHandler")
            handler.mkdir(parents=True)
            Path(handler, "TemplateHandler.php").write_text(
                "<?php\nclass TemplateHandler { public static function clearCache($m = null, $v = null) {} }\n"
            )
            result = subprocess.run(["php", "-r", HARNESS, str(LAYOUT)], cwd=sugar,
                                    capture_output=True, text=True, check=False)
        assert result.returncode == 0, result.stderr + result.stdout
        cls.out = json.loads(result.stdout)

    def test_upgraded_tenant_loses_the_retired_label_from_the_bench_panel_only(self):
        upgrade = self.out["upgrade"]
        self.assertEqual(names(panel(upgrade["panels"], PANEL)), ["bd_erp_total", "description"])
        # An admin's own placement on another panel is not ours to remove.
        self.assertIn("bd_governing_line", names(panel(upgrade["panels"], "panel_body")))
        self.assertEqual(upgrade["deploys"], 1)
        self.assertEqual(upgrade["rerun_deploys"], 0, "a clean panel is not redeployed")

    def test_fresh_install_panel_has_no_retired_label(self):
        fresh = self.out["fresh"]
        self.assertEqual(names(panel(fresh["panels"], PANEL)), PACKAGED)

    def test_replace_rewrites_to_the_packaged_panel(self):
        replaced = self.out["replace"]
        self.assertEqual(names(panel(replaced["panels"], PANEL)), PACKAGED)
        self.assertIn("bd_governing_line", names(panel(replaced["panels"], "panel_body")))


if __name__ == "__main__":
    unittest.main()
