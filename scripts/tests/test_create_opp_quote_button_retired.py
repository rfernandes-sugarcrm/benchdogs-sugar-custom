#!/usr/bin/env python3
"""G15: the Bench "Create Opportunity & Quote" button is RETIRED, and stays retired.

🛑 THE DEFECT THIS PINS. Under rc49 the Accounts record view rendered the button
TWICE. The header's own text read:

    ADDISON WB I85L06 ... Distribution DIST
    Create Opportunity & Quote  Create Opportunity & Quote  Edit

🚩 WHY THE ORIGINAL GUARD COULD NOT SEE IT, which is the whole lesson. This class
refused to inject when a button named ``bd_create_opp_quote_button`` was already
present. ERP-Epicor's AccountsLayout ships one named
``erp_create_opp_quote_button`` - a DIFFERENT name carrying an IDENTICAL LABEL.
A guard keyed on what the CODE sees (the name) cannot see a duplicate keyed on
what the USER sees (the label), so both packages correctly concluded they were
the only one and both injected. ``test_the_two_packages_still_share_one_label``
below pins that collision so nobody "fixes" this by renaming a label and
quietly reopening the hole.

📌 WHY CORE'S SURVIVES AND THIS ONE GOES. 🔒 1044: the Bench layer is TWO fields,
``bd_customer_group{,_code}``, plus the connector code that writes them. And
core's ``AccountsErpActionsApi`` is the SUPERSET, not an equivalent - it owns
``DEFAULT_PLACEHOLDER_PART = 'ETO-PENDING'`` (the exact placeholder REQ-20's
oracle names), is tenant-configurable, and types the quote for Advanced Quote.
Keeping Bench's instead would show one button and LOSE all three.

🛑 WHY writeButtons() STILL EXISTS INSTEAD OF BEING DELETED. The button was
written into the tenant's DEPLOYED viewdef by ``saveViewdef``. Deleting the code
that wrote it does NOT remove it - rc24 proved that the expensive way: it dropped
six files, installed clean, and was INERT. ONLY OVERWRITING RETIRES. So the
method must keep running and must actively remove what it used to add, which is
what the behavioural cases below drive.
"""

import json
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "sugar-sell/BenchDogs-Ext"
LAYOUT = PKG / "custom/modules/Accounts/BdAccountsLayoutExtensions.php"
BD_LABELS = PKG / "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_action_buttons.php"
#: ERP-Epicor lives in a sibling checkout; the label collision is only assertable
#: when it is present, so that one case skips rather than fails when it is not.
_EPICOR_REL = (
    "erp-integration-sugar/sugar-sell/ERP-Epicor/src/custom/Extension"
    "/modules/Accounts/Ext/Language/en_us.erp_create_opp_quote.php"
)


def _find_epicor_labels():
    """Walk up looking for the sibling checkout.

    A git worktree sits at <repo>/.worktrees/<name>, so a fixed ``ROOT.parent``
    resolves correctly from a normal clone and NOT from a worktree - the case
    this test was first written in, where it silently skipped.
    """
    here = ROOT
    for _ in range(4):
        cand = here.parent / _EPICOR_REL
        if cand.exists():
            return cand
        here = here.parent
    return ROOT.parent / _EPICOR_REL


EPICOR_LABELS = _find_epicor_labels()

BD_BUTTON = "bd_create_opp_quote_button"
ERP_BUTTON = "erp_create_opp_quote_button"

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
    // deployRecordView() ends with `include_once 'include/TemplateHandler/...'`
    // then TemplateHandler::clearCache(). Outside a Sugar root that include is a
    // warning and the call is FATAL, so the class is declared here. Without it the
    // harness dies AFTER the retirement has already been applied - which reads as
    // the fix failing rather than the harness being incomplete.
    class TemplateHandler {
        public static function clearCache($module = null, $view = null) {}
    }
    // The class logs through $GLOBALS['log']; give it a sink so the real code path runs.
    class _Log { public function __call($m, $a) {} }
    $GLOBALS['log'] = new _Log();

    require $argv[1];

    function run(array $buttons): array {
        // loadViewdef() returns the INNER defs; loadRecordView() wraps them as
        // ['base']['view']['record'] itself. Stubbing the wrapped shape here made
        // loadRecordView double-wrap it, the buttons key went missing, and the
        // method correctly did nothing - which read exactly like the fix failing.
        ViewdefManager::$defs = ['buttons' => $buttons];
        ViewdefManager::$saves = 0;
        BdAccountsLayoutExtensions::writeButtons();
        $after  = ViewdefManager::$defs['buttons'] ?? [];
        $saves  = ViewdefManager::$saves;
        // Idempotence: a second run over the ALREADY-RETIRED view must not rewrite it.
        BdAccountsLayoutExtensions::writeButtons();
        return [
            'names'         => array_values(array_map(
                static fn($b) => is_array($b) ? ($b['name'] ?? '?') : (string) $b, $after)),
            'saves'         => $saves,
            'rerun_saves'   => ViewdefManager::$saves - $saves,
        ];
    }

    $bd  = ['name' => 'bd_create_opp_quote_button',  'label' => 'LBL_BD_CREATE_OPP_QUOTE_BUTTON'];
    $erp = ['name' => 'erp_create_opp_quote_button', 'label' => 'LBL_ERP_CREATE_OPP_QUOTE_BUTTON'];
    $edit = ['name' => 'edit_button', 'label' => 'LBL_EDIT_BUTTON'];
    $main = ['name' => 'main_dropdown', 'label' => 'LBL_MAIN'];

    echo json_encode([
        // the defect as it shipped: BOTH buttons on the deployed view
        'duplicated' => run([$edit, $bd, $erp, $main]),
        // an upgraded tenant carrying only ours
        'bench_only' => run([$edit, $bd, $main]),
        // a clean tenant that never had ours - must be a NO-OP, no churn
        'core_only'  => run([$edit, $erp, $main]),
    ]);
}
'''


def _run_harness():
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
        fh.write("<?php\n" + HARNESS)
        harness = fh.name
    out = subprocess.run(
        # `include_once 'include/TemplateHandler/...'` cannot resolve outside a
        # Sugar root and emits a WARNING to stdout, ahead of our JSON. Silencing
        # it here is honest: the include is irrelevant to what this test asserts,
        # and TemplateHandler is stubbed above so the call itself still runs.
        ["php", "-d", "error_reporting=E_ALL & ~E_WARNING", harness, str(LAYOUT)],
        capture_output=True, text=True, check=False,
    )
    if out.returncode != 0:
        raise AssertionError(f"harness failed rc={out.returncode}: {out.stderr[:600]}")
    # Parse from the first brace so any residual notice cannot break the read.
    start = out.stdout.find("{")
    if start < 0:
        raise AssertionError(f"harness produced no JSON: {out.stdout[:400]!r}")
    return json.loads(out.stdout[start:])


class CreateOppQuoteButtonRetiredStatic(unittest.TestCase):
    def test_the_source_never_injects_the_button_again(self):
        """The shape that ADDED it must not come back."""
        src = LAYOUT.read_text(encoding="utf-8")
        self.assertNotIn(
            "'type' => 'bd-create-opp-quote'", src,
            "BdAccountsLayoutExtensions injects the Bench Create Opportunity & Quote "
            "button again. Core's erp_create_opp_quote_button owns this action "
            "(it carries ETO-PENDING and the Advanced Quote typing); adding ours "
            "back puts TWO identical buttons in front of the seller (G15).",
        )

    def test_the_retirement_mechanism_is_still_there(self):
        """Not adding it is NOT enough - a deployed viewdef outlives the package."""
        src = LAYOUT.read_text(encoding="utf-8")
        self.assertIn(BD_BUTTON, src, "the method no longer names the button it must remove")
        self.assertRegex(
            src, r"writeButtons\(\)\s*:\s*void",
            "writeButtons() is gone. Deleting it does NOT remove the button from a "
            "tenant that already has it - rc24 proved an omitted file is INERT. "
            "ONLY OVERWRITING RETIRES.",
        )

    def test_the_customer_group_fields_are_untouched(self):
        """🔒 1044: these two are the whole Bench layer. The G15 fix must not take them."""
        src = LAYOUT.read_text(encoding="utf-8")
        for field in ("bd_customer_group", "bd_customer_group_code"):
            self.assertIn(field, src, f"{field} lost from the Accounts layout (🔒 1044)")

    def test_the_two_packages_still_share_one_label(self):
        """THE ROOT CAUSE, pinned.

        A name-keyed guard cannot see a duplicate keyed on the label. If this
        assertion ever fails because the labels diverged, the original guard
        would silently start "working" again - and the next person to re-add the
        Bench button would not be caught by it.
        """
        if not EPICOR_LABELS.exists():
            self.skipTest("erp-integration-sugar checkout not present beside this repo")
        bd = BD_LABELS.read_text(encoding="utf-8")
        erp = EPICOR_LABELS.read_text(encoding="utf-8")

        def label_of(text, key):
            m = re.search(rf"\$mod_strings\['{key}'\]\s*=\s*'([^']+)'", text)
            return m.group(1) if m else None

        bd_label = label_of(bd, "LBL_BD_CREATE_OPP_QUOTE_BUTTON")
        erp_label = label_of(erp, "LBL_ERP_CREATE_OPP_QUOTE_BUTTON")
        self.assertIsNotNone(bd_label)
        self.assertIsNotNone(erp_label)
        self.assertEqual(
            bd_label, erp_label,
            "The two packages' buttons no longer share a label. That is the exact "
            "condition under which a NAME-keyed dedupe guard looks correct and is "
            "not - see this file's docstring.",
        )


class CreateOppQuoteButtonRetiredBehaviour(unittest.TestCase):
    """Drives the REAL class through a stubbed ViewdefManager."""

    @classmethod
    def setUpClass(cls):
        if subprocess.run(["which", "php"], capture_output=True).returncode != 0:
            raise unittest.SkipTest("php not available")
        cls.r = _run_harness()

    def test_it_removes_ours_from_a_duplicated_view(self):
        d = self.r["duplicated"]
        self.assertNotIn(BD_BUTTON, d["names"], "the Bench button survived the retirement")
        self.assertEqual(d["names"].count(ERP_BUTTON), 1, "core's button must remain, exactly once")
        self.assertEqual(d["saves"], 1, "the retirement must DEPLOY the view, or nothing changes")

    def test_it_removes_ours_on_an_upgraded_tenant(self):
        b = self.r["bench_only"]
        self.assertNotIn(BD_BUTTON, b["names"])
        self.assertEqual(b["saves"], 1)

    def test_it_is_a_no_op_when_there_is_nothing_to_retire(self):
        """No churn on a clean tenant - a needless saveViewdef is a real cost."""
        c = self.r["core_only"]
        self.assertEqual(c["names"].count(ERP_BUTTON), 1)
        self.assertEqual(c["saves"], 0, "rewrote the view with nothing to remove")

    def test_a_second_run_does_not_rewrite_the_view(self):
        for case in ("duplicated", "bench_only", "core_only"):
            with self.subTest(case=case):
                self.assertEqual(
                    self.r[case]["rerun_saves"], 0,
                    "re-running the retirement deploys the view again; post_install "
                    "runs on every upgrade, so this would churn forever",
                )

    def test_unrelated_buttons_survive(self):
        d = self.r["duplicated"]
        self.assertIn("edit_button", d["names"])
        self.assertIn("main_dropdown", d["names"])


if __name__ == "__main__":
    unittest.main()
