"""G606: Bench owns Reference placement; ERP-Core still owns the field.

Run the real merged vardefs, marker sync and core's G606 placement step.
Sugar's metadata/config storage is in memory; no tenant is accessed.
"""

import json
import shutil
import subprocess
import tempfile
from pathlib import Path

import pytest

import shared_sugar
from bd_retirement import PKG, zip_names
from test_g450_suspect_account_type import sugar_order

REL = "custom/Extension/modules/Quotes/Ext/Vardefs/_override_bd_erp_reference.php"
MARKER = {
    "view": "record",
    "panel": "LBL_RECORDVIEW_PANEL_ERP",
    "after": "erp_quotes_ship_via_name",
}
PICKERS = [
    "bd_lead_source",
    "bd_lead_type",
    "bd_project_id",
    "bd_marketing_campaign",
    "bd_marketing_event",
]

HARNESS = r"""<?php
namespace Sugarcrm\Sugarcrm\MetaData {
    class ViewdefManager {
        public static $defs;
        public static $writes = 0;
        public function loadViewdef(...$args) { return self::$defs; }
        public function saveViewdef($defs, ...$args) {
            self::$defs = $defs;
            self::$writes++;
        }
    }
}
namespace {
    use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;
    class MetaDataFiles { public static function clearModuleClientCache(...$args) {} }
    class Administration {
        public static $settings = [];
        public function getConfigForModule(...$args) { return self::$settings; }
        public function saveSetting($category, $key, $value, ...$args) {
            self::$settings[$key] = $value;
        }
    }
    class BeanFactory {
        public static function getObjectName($module) { return 'Quote'; }
        public static function newBean($module) { return new Administration(); }
    }
    class VardefManager {
        public static $fields;
        public static function refreshVardefs(...$args) {
            $GLOBALS['dictionary']['Quote']['fields'] = self::$fields;
        }
    }
    $plan = json_decode(file_get_contents('plan.json'), true);
    $dictionary = ['Quote' => ['fields' => ['id' => ['name' => 'id'], 'name' => ['name' => 'name']]]];
    foreach ($plan['files'] as $file) { include $file; }
    VardefManager::$fields = $dictionary['Quote']['fields'];
    ViewdefManager::$defs = ['panels' => [
        ['name' => 'panel_body', 'fields' => $plan['admin'] ? ['name', 'erp_reference'] : ['name']],
        ['name' => 'LBL_RECORDVIEW_PANEL_ERP', 'fields' => ['erp_quotes_ship_via_name']],
    ]];
    require 'custom/include/ErpLayoutExtraFields.php';
    require 'custom/include/scripts/Modules/QuotesLayout.php';
    // The two final steps of QuotesLayout::install(), using its real methods.
    $place = new ReflectionMethod('QuotesLayout', 'placeReferenceOnlyWhereClaimed');
    $layout = new QuotesLayout(false);
    $place->invoke($layout);
    ErpLayoutExtraFields::sync('Quotes');
    $once = ViewdefManager::$defs;
    ViewdefManager::$writes = 0;
    $place->invoke($layout);
    ErpLayoutExtraFields::sync('Quotes');
    echo json_encode(['fields' => VardefManager::$fields, 'once' => $once,
        'twice' => ViewdefManager::$defs, 'repeat_writes' => ViewdefManager::$writes]);
}
"""


def run_merge(files, admin=False):
    with tempfile.TemporaryDirectory(prefix="g606-") as tmp:
        root = Path(tmp)
        for name, rel in {
            "BaseErpLayout.php": "custom/include/scripts/BaseErpLayout.php",
            "QuotesLayout.php": "custom/include/scripts/Modules/QuotesLayout.php",
            "ErpLayoutExtraFields.php": "custom/include/ErpLayoutExtraFields.php",
        }.items():
            dest = root / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(shared_sugar.resolve(name), dest)
        template = root / "include/TemplateHandler/TemplateHandler.php"
        template.parent.mkdir(parents=True)
        template.write_text(
            "<?php class TemplateHandler { public static function clearCache(...$args) {} }\n"
        )
        (root / "plan.json").write_text(json.dumps({"files": files, "admin": admin}))
        result = subprocess.run(
            ["php"],
            input=HARNESS,
            cwd=root,
            capture_output=True,
            text=True,
        )
        assert result.returncode == 0, result.stdout + result.stderr
        assert result.stderr == "", result.stderr
        return json.loads(result.stdout)


def fragments(bench_newer=True, claim=True):
    core = str(shared_sugar.resolve("erp_reference.php"))
    bench = list((PKG / "custom/Extension/modules/Quotes/Ext/Vardefs").glob("*.php"))
    if not claim:
        bench = [p for p in bench if p.name != Path(REL).name]
    return sugar_order(
        [(core, 100 if bench_newer else 200)]
        + [(str(p), 200 if bench_newer else 100) for p in bench]
    )


def panel_names(result, index):
    return [
        f["name"] if isinstance(f, dict) else f
        for f in result["once"]["panels"][index]["fields"]
    ]


def test_claim_ships_in_package():
    assert (PKG / REL).is_file()
    assert REL in zip_names()


@pytest.mark.parametrize("bench_newer", [False, True])
def test_claim_survives_either_install_order_without_redefining_field(bench_newer):
    core = run_merge([str(shared_sugar.resolve("erp_reference.php"))])
    result = run_merge(fragments(bench_newer))
    field = result["fields"]["erp_reference"]
    assert field.get("erp_layout") == MARKER
    assert {k: v for k, v in field.items() if k != "erp_layout"} == core["fields"][
        "erp_reference"
    ]
    assert set(result["fields"]) - set(core["fields"]) == set(PICKERS)
    assert (
        panel_names(result, 1)
        == ["erp_quotes_ship_via_name", "erp_reference"] + PICKERS
    )
    assert result["once"] == result["twice"]
    assert result["repeat_writes"] == 0


def test_core_first_keeps_reference_for_bench_without_the_new_claim():
    result = run_merge(fragments(claim=False))
    assert "erp_layout" not in result["fields"]["erp_reference"]
    assert (
        panel_names(result, 1)
        == ["erp_quotes_ship_via_name", "erp_reference"] + PICKERS
    )


def test_admin_placement_is_preserved_without_duplicates():
    result = run_merge(fragments(), admin=True)
    assert panel_names(result, 0) == ["name", "erp_reference"]
    assert "erp_reference" not in panel_names(result, 1)
    assert result["repeat_writes"] == 0
