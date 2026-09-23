#!/usr/bin/env python3
"""The uninstall no longer touches the stage vocabulary — INVERTED, not deleted.

🛑 WHAT G234 FIXED, AND WHY THAT FIX IS NOW THE WRONG SHAPE.
rc61 taught pre_uninstall.php to remove the stage keys, because post_install had
appended them through `ModuleInstaller::install_languages()` under id_name
`zz_bd_stage_doms`, neither uninstall route could reach that file, and a clean
16/16 uninstall left stock (ossugarcube2) still serving "Prototype Ordered" and
"Partial Production Ordered" on a tenant where ZERO opportunities held either —
which invalidated every row graded "stock does not have this stage".

🔒 1506 removed the CAUSE instead of teaching the uninstall to clean up after it:
Partial Fulfillment now OWNS both sales stages (and has always owned
quote_stage_dom's 'Partially Fulfilled', at
`_override_en_us.partial_fulfillment_quote_stage.php`). 0.9.42-rc65 declares none
of them, and post_install deletes the accumulated fragment once, on INSTALL.

So an uninstall that removed those keys would now delete ANOTHER PACKAGE's
vocabulary from under records that hold it — the same damage G234 existed to
prevent, pointed the other way. The owner's instruction is explicit: a Bench Dogs
uninstall must stop removing them at all.

This file is INVERTED rather than deleted for the reason the package applies to
its own stubs: a deleted test stops noticing. It EXECUTES the real
pre_uninstall.php and asserts the language seam is never touched.

MUTATION-VERIFIED: re-add any uninstall_languages()/install_languages() call to
pre_uninstall.php -> touches_no_language_fragment fails; make it read the stage
doms -> reads_no_stage_vocabulary fails.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PACKAGE = Path(os.environ.get("BD_PKG", ROOT / "sugar-sell/BenchDogs-Ext"))
SCRIPT = PACKAGE / "scripts/bd_pre_uninstall.php"

HARNESS = r'''<?php
$events = [];
$errors = [];
class TestLog {
    public function error($s) { $GLOBALS['errors'][] = $s; }
    public function fatal($s) { $GLOBALS['errors'][] = $s; }
    public function warn($s) { $GLOBALS['errors'][] = $s; }
}
$GLOBALS['log'] = new TestLog();
class BeanFactory {
    public static function newBean($module) { $GLOBALS['events'][] = ['newBean', $module]; throw new Exception('no beans'); }
    public static function getBean($module = null) { $GLOBALS['events'][] = ['getBean', $module]; throw new Exception('no beans'); }
}
class SugarQuery { public function __call($m, $a) { $GLOBALS['events'][] = ['SugarQuery', $m]; return $this; } }
function return_app_list_strings_language($language, $useCache = true) {
    $GLOBALS['events'][] = ['read_app_list_strings', $language];
    return [];
}
$failure = null;
try {
    require 'scripts/bd_pre_uninstall.php';
} catch (Throwable $e) { $failure = $e->getMessage(); }
echo json_encode(['failure' => $failure, 'events' => $events, 'errors' => $errors]);
'''

INSTALLER_STUB = r'''<?php
class ModuleInstaller {
    public $silent; public $id_name; public $base_dir; public $installdefs;
    public function install_languages() { $GLOBALS['events'][] = 'install_languages'; }
    public function uninstall_languages() { $GLOBALS['events'][] = 'uninstall_languages'; }
    public function rebuild_languages($l = [], $m = []) { $GLOBALS['events'][] = 'rebuild_languages'; }
    public function rebuild_tabledictionary() {}
}
'''


def _code_without_comments(path: Path) -> str:
    raw = path.read_text(encoding="utf-8")
    raw = re.sub(r"/\*.*?\*/", "", raw, flags=re.S)
    return re.sub(r"(^|\s)//[^\n]*", r"\1", raw)


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class UninstallLeavesTheStagesAloneTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with tempfile.TemporaryDirectory(prefix="bench-stage-uninstall-") as tmp:
            target = Path(tmp)
            (target / "scripts").mkdir(parents=True)
            shutil.copy2(SCRIPT, target / "scripts/bd_pre_uninstall.php")
            (target / "ModuleInstall").mkdir()
            (target / "ModuleInstall/ModuleInstaller.php").write_text(INSTALLER_STUB)
            result = subprocess.run(["php"], input=HARNESS, text=True, cwd=target,
                                    capture_output=True, check=True)
        cls.observed = json.loads(result.stdout)

    def test_touches_no_language_fragment(self):
        names = [e if isinstance(e, str) else e[0] for e in self.observed["events"]]
        self.assertNotIn("uninstall_languages", names,
                         "the uninstall is deleting a language fragment Partial Fulfillment owns")
        self.assertNotIn("install_languages", names)
        self.assertNotIn("rebuild_languages", names)

    def test_reads_no_stage_vocabulary(self):
        """It must not even LOOK: counting records to decide whether to remove a
        key is the mechanism 🔒 1506 retired."""
        names = [e if isinstance(e, str) else e[0] for e in self.observed["events"]]
        self.assertNotIn("read_app_list_strings", names)
        self.assertNotIn("SugarQuery", names)

    def test_it_still_runs_and_cannot_kill_an_uninstall(self):
        self.assertIsNone(self.observed["failure"])
        # The version comes from the installer's extract($data); a bare
        # top-level require has none, so here it reads "(unknown)".
        self.assertIn("BenchDogs-Ext: pre_uninstall running (unknown) - cleaning up deployed metadata",
                      self.observed["errors"])

    def test_the_source_names_no_stage_key(self):
        code = _code_without_comments(SCRIPT)
        for key in ("Prototype Ordered", "Partial Production Ordered", "Partially Fulfilled",
                    "zz_bd_stage_doms", "sales_stage_dom", "quote_stage_dom"):
            self.assertNotIn(key, code, f"pre_uninstall still names {key}")

    def test_the_body_stays_top_level_code(self):
        """Same property the install side has: ModuleInstaller only require_once's
        this file, so a function named for the installdef key is never called and
        the cleanup would silently not happen."""
        code = _code_without_comments(SCRIPT)
        self.assertNotIn("function pre_uninstall", code)
        self.assertNotIn("throw new", code)


if __name__ == "__main__":
    unittest.main()
