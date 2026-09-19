"""A dropdown the package owns must be declared where Sugar actually reads it.

`Quotes.bd_erp_stage` is an enum whose `options` name `bd_erp_stage_list`. The
list used to be declared in the Quotes **module** Language ext, beside the
field's `LBL_*` labels. Sugar includes a module Language ext from
`LanguageManager::loadModuleLanguage()`, which declares `global $mod_strings`
and nothing else, so the `$app_list_strings` assignment was a discarded local
and the list reached neither `app_list_strings` nor the module's `mod_strings`
- `bd_erp_stage` rendered blank wherever it was placed.

Application Language exts are included by `return_app_list_strings_language()`
under `global $app_list_strings`, which is why every other list this package
owns already ships from there.
"""

from __future__ import annotations

import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"
APP_LANGUAGE = PACKAGE / "custom/Extension/application/Ext/Language"
MODULE_LANGUAGE = PACKAGE / "custom/Extension/modules"
STAGE_LIST = APP_LANGUAGE / "en_us.bd_erp_stage_list.php"
STAGE_VARDEF = (
    PACKAGE / "custom/Extension/modules/Quotes/Ext/Vardefs/bd_erp_stage.php"
)

# An assignment, not a mention of one in a comment.
ASSIGNS_APP_LIST = re.compile(r"^\s*\$app_list_strings\[", re.MULTILINE)

# The keys BdQuoteReflectionHook and bd-send-to-estimating write.
EXPECTED_KEYS = [
    "",
    "draft",
    "in_estimating",
    "priced",
    "revision",
    "accepted",
    "ordered",
    "lost",
]


class StageListScopeTest(unittest.TestCase):
    def test_no_module_language_ext_declares_an_application_list(self):
        for path in MODULE_LANGUAGE.rglob("Ext/Language/*.php"):
            with self.subTest(path=path.relative_to(PACKAGE)):
                self.assertNotRegex(
                    path.read_text(encoding="utf-8"), ASSIGNS_APP_LIST
                )

    def test_the_stage_list_ships_from_application_scope(self):
        declarations = [
            path
            for path in sorted(PACKAGE.rglob("custom/**/*.php"))
            if "$app_list_strings['bd_erp_stage_list'] ="
            in path.read_text(encoding="utf-8")
        ]
        self.assertEqual(declarations, [STAGE_LIST])

    def test_the_vardef_is_RETIRED_and_names_no_list(self):
        """INVERTED 2026-09-19, not deleted.

        bd_erp_stage is retired (🔒 1044): the ERP-side stage belongs to
        ERP-Core. The field was also a TRAP — it spells a value 'accepted' of
        its own, distinct from quote_stage's stored 'Closed Accepted'.

        The list itself still ships at APPLICATION scope (asserted above) and
        that is deliberate: this package has already paid once for declaring a
        dropdown at MODULE scope, where it rendered blank. Retiring the field
        must not quietly re-scope the list.
        """
        code = STAGE_VARDEF.read_text(encoding="utf-8")
        self.assertTrue(STAGE_VARDEF.exists(), "the stub must still ship (§CW / G37)")
        self.assertNotIn("$dictionary", code, "the vardef must declare nothing")
        self.assertNotIn("'options' => 'bd_erp_stage_list'", code)


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 or later")
class StageListLoaderSemanticsTest(unittest.TestCase):
    """Load the file the way Sugar loads it, not the way it reads."""

    def execute(self, path: Path) -> dict:
        # loadModuleLanguage() globalises $mod_strings alone and returns it;
        # return_app_list_strings_language() globalises $app_list_strings.
        harness = rf"""<?php
$mod_strings = [];
$app_list_strings = [];
function loadModuleLanguage($file) {{
    global $mod_strings;
    include $file;
    return $mod_strings;
}}
function loadApplicationLanguage($file) {{
    global $app_list_strings;
    include $file;
    return $app_list_strings;
}}
echo json_encode([
    'as_module' => loadModuleLanguage({json.dumps(str(path))}),
    'as_application' => loadApplicationLanguage({json.dumps(str(path))}),
]);
"""
        completed = subprocess.run(
            ["php"], input=harness, text=True, capture_output=True, check=True
        )
        return json.loads(completed.stdout)

    def test_application_scope_delivers_every_stage_key(self):
        observed = self.execute(STAGE_LIST)
        self.assertEqual(
            list(observed["as_application"]["bd_erp_stage_list"]), EXPECTED_KEYS
        )
        self.assertEqual(
            observed["as_application"]["bd_erp_stage_list"]["in_estimating"],
            "In Estimating",
        )

    def test_module_scope_would_have_dropped_it(self):
        # The regression this guards: the same file included as a module
        # Language ext contributes nothing at all.
        observed = self.execute(STAGE_LIST)
        self.assertEqual(observed["as_module"], [])


if __name__ == "__main__":
    unittest.main()
