"""Bench release stages remain visible in Sugar's formatted enum-cascade."""

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
class StageDropdownStyleTest(unittest.TestCase):
    def test_extension_appends_required_styles_without_replacing_shared_styles(self):
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
        result = subprocess.run(
            ["php"], input=fixture, text=True, capture_output=True, check=True,
        )
        styles = json.loads(result.stdout)
        self.assertEqual(styles["Customer Stage"]["backgroundColor"], "#123456")
        self.assertIs(styles["applyFormatting"], True)
        self.assertEqual(styles["Prototype Closed"]["backgroundColor"], "#FEF08A")
        self.assertEqual(styles["Partial Production Closed"]["backgroundColor"], "#A7F3D0")
        self.assertEqual(styles["Prototype Closed"]["icon"]["class"], "check-circle")
        self.assertEqual(styles["Partial Production Closed"]["icon"]["class"], "check-circle")

    def test_extension_preserves_existing_same_key_tenant_style(self):
        fixture = rf'''<?php
$app_dropdowns_style = [
    'sales_stage_dom_style' => [
        'Prototype Closed' => [
            'backgroundColor' => '#123456',
            'icon' => ['class' => 'tenant-icon'],
        ],
        'Partial Production Closed' => null,
        'applyFormatting' => true,
    ],
];
require {json.dumps(str(STYLE))};
require {json.dumps(str(STYLE))};
echo json_encode($app_dropdowns_style['sales_stage_dom_style']);
'''
        result = subprocess.run(
            ["php"], input=fixture, text=True, capture_output=True, check=True,
        )
        styles = json.loads(result.stdout)
        self.assertEqual(styles["Prototype Closed"], {
            "backgroundColor": "#123456",
            "icon": {"class": "tenant-icon"},
        })
        self.assertIsNone(styles["Partial Production Closed"])
        self.assertIs(styles["applyFormatting"], True)

    def test_built_package_contains_exact_style_extension(self):
        version = (PACKAGE / "version").read_text().strip()
        archive_path = PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        relative = STYLE.relative_to(PACKAGE).as_posix()
        with zipfile.ZipFile(archive_path) as archive:
            self.assertEqual(archive.read(relative), STYLE.read_bytes())
            manifest = archive.read("manifest.php").decode()
            self.assertIn(relative, manifest)


if __name__ == "__main__":
    unittest.main()
