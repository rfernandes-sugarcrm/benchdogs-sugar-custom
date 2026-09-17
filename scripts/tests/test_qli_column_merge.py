"""Decision 803: an install must NOT delete another package's grid column.

Until 0.9.43 this package shipped its authored column list AT the live
viewdef path, so Module Loader's copy replaced the whole custom viewdef and
any column another package had appended went with it. The regression is
silent -- no error, and reinstalling the victim looks like a fix -- so it
needs a test that fails loudly.
"""

from __future__ import annotations

import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "sugar-sell/BenchDogs-Ext"
LAYOUT = PKG / "custom/modules/Quotes/BdQliColumnsLayout.php"
TEMPLATE = PKG / "custom/modules/Quotes/BdQliColumnTemplate.php"

# A column this package knows nothing about, sitting in the deployed view --
# exactly ERP-Epicor-QuantityAlternatives' erp_break_select.
FOREIGN = "erp_break_select"


PARENT_STUB = r"""<?php
use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

class BaseErpLayout {
    protected function loadView(string $module, string $view): ?array {
        $defs = (new ViewdefManager())->loadViewdef('base', $module, $view);
        if (empty($defs)) { return null; }
        return ['base' => ['view' => [$view => $defs]]];
    }
    protected function deployView(string $module, string $view, array $viewdefs): void {
        (new ViewdefManager())->saveViewdef($viewdefs['base']['view'][$view], $module, 'base', $view);
    }
    protected function addFieldsToNestedCollection($m, $c, $f) {}
    protected function removeFieldsFromDataGroupListView($m, $f) {}
}
"""

HARNESS = r'''
namespace Sugarcrm\Sugarcrm\Util\Files {
    class FileLoader { public static function validateFilePath($p) { return $p; } }
}

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

    class MetaDataFiles { public static function clearModuleClientCache($m = [], $t = '', $p = []) {} }

    // BdQliColumnsLayout is guarded behind file_exists() on the parent, so the
    // parent has to be a real FILE for the class to be defined at all -- the
    // test exercises that guard rather than sidestepping it.
    require $argv[1];

    // Deployed view: this package's own first column, then a FOREIGN one.
    ViewdefManager::$defs = ['panels' => [['fields' => [
        ['name' => 'line_num'],
        ['name' => 'ARGV_FOREIGN'],
    ]]]];

    (new BdQliColumnsLayout())->install();

    $after = ViewdefManager::$defs['panels'][0]['fields'];
    $names = [];
    foreach ($after as $f) { $names[] = is_array($f) ? ($f['name'] ?? '?') : (string) $f; }
    echo json_encode(['names' => $names, 'saves' => ViewdefManager::$saves]);
}
'''


@unittest.skipUnless(shutil.which("php"), "requires PHP")
class QliColumnMergeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with tempfile.TemporaryDirectory() as sugar:
            dest = Path(sugar, "custom/modules/Quotes")
            dest.mkdir(parents=True)
            shutil.copy(TEMPLATE, dest / "BdQliColumnTemplate.php")

            parent = Path(sugar, "custom/include/scripts")
            parent.mkdir(parents=True)
            Path(parent, "BaseErpLayout.php").write_text(PARENT_STUB)
            harness = HARNESS.replace("ARGV_FOREIGN", FOREIGN)
            res = subprocess.run(["php", "-r", harness, str(LAYOUT)], cwd=sugar,
                                 capture_output=True, text=True, check=False)
        assert res.returncode == 0, res.stderr + res.stdout
        cls.out = json.loads(res.stdout)

    def test_a_foreign_column_SURVIVES_the_install(self):
        """THE CLAUSE THAT STOPS THIS PACKAGE DELETING SOMEONE ELSE'S COLUMN."""
        self.assertIn(FOREIGN, self.out["names"])

    def test_the_authored_order_still_wins(self):
        """The template exists to control ORDER; merging must not cost that."""
        names = self.out["names"]
        self.assertEqual(names[0], "line_num")
        # every authored column precedes the foreign one, which is appended
        self.assertEqual(names[-1], FOREIGN)

    def test_no_duplicate_columns(self):
        """line_num is in BOTH the template and the deployed list; merging by
        name must not render it twice."""
        names = self.out["names"]
        self.assertEqual(len(names), len(set(names)), f"duplicates in {names}")

    def test_it_actually_wrote(self):
        self.assertGreaterEqual(self.out["saves"], 1)


if __name__ == "__main__":
    unittest.main()
