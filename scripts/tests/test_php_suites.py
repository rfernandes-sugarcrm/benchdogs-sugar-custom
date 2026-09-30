"""The PHP test suites RUN IN CI. Until now, none of them did.

🛑 WHAT THIS FILE FIXES. `.github/workflows/mlp-lint.yml` runs
`python3 -m pytest scripts/tests -q` and nothing else. pytest does not collect
`*.php`, so `scripts/tests/bench_panel_retired_test.php` — 18 checks, written
after the owner found a raw `LBL_RECORDVIEW_PANEL_BENCHDOGS` on a live quote —
has never executed on a single pull request. It is the workflow's own standard,
quoted from the step above the collected-count floor:

    A test that never runs is documentation, not a guard.

and the twin defect it names (652 of 892 collected in erp-integration-sugar)
was the same failure wearing a different hat: a runner that silently declines
to run something looks exactly like a passing suite.

WHY A WRAPPER RATHER THAN A SECOND CI STEP. A step in the workflow would run
them on CI only. Wrapping them in pytest means `python3 -m pytest scripts/tests`
— the command every one of these tests documents itself with, and the one
anybody runs locally — covers the PHP suites too, and each one lands in the
collected count that CI floors.

The PHP suites print `N checks, M failed` and exit non-zero on a failure. Both
are asserted: an exit code alone cannot see a suite that stopped running checks,
which is the same hole in miniature.
"""

import os
import re
import shutil
import subprocess
import unittest
from pathlib import Path

import shared_sugar


ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent

# Every PHP suite, named. The set is pinned rather than only globbed so that a
# new suite nobody wired in is a FAILURE here rather than silence — see
# test_every_php_suite_in_this_directory_is_named_above.
SUITES = (
    "bench_panel_retired_test.php",
    "bench_governing_origin_retired_test.php",
    "bd_adm_rules_test.php",
    "bd_erp_layout_test.php",
    "bd_customer_group_move_test.php",
    "bd_contact_fields_test.php",
)

php = shutil.which("php")


def run(name: str, **env: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        [php, str(HERE / name)], cwd=ROOT, capture_output=True, text=True,
        env={**os.environ, **env},
    )


#: rc70 (G380, 🔒 1724b) runs against ERP-Epicor's REAL code (lane D, landed
#: a0f6b632): the sibling checkout's files when present, else the pins.
#: 0.9.42-rc83: BD_QUOTE_FACTS is the GLOBAL ErpQuoteFacts of ERP-Epicor 1.1
#: (the last one shipped, 1.1.179, pinned to its commit under
#: fixtures/erp-epicor-1.1/): 1.2.0 no longer ships that file, and BdAdmRules
#: must keep working on a tenant that has not upgraded yet.
LANDED = {
    "BD_QUOTE_FACTS": str(HERE / "fixtures/erp-epicor-1.1/custom/modules/Quotes/ErpQuoteFacts.php"),
    "BD_LAYOUT_FIELDS": str(shared_sugar.resolve("ErpLayoutExtraFields.php")),
    "BD_ERP_REFERENCE": str(shared_sugar.resolve("erp_reference.php")),
}

#: ERP-Epicor 1.2.0 (T2 of the Rafael review, erp-integration-sugar #158; the
#: build 280e0929): ErpQuoteFacts is Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts
#: in ERP-Epicor's custom/src, found only through an autoloader - ERP-Core's own
#: stand-in, the sibling checkout's when present, else the pinned mirror, whose
#: roots are that same checkout's packages (shared_sugar.py).
ERP_AUTOLOADER = str(shared_sugar.resolve("sugar_autoloader.php"))


#: The fewest checks each suite may report and still count as having run.
#: `0 checks, 0 failed` satisfied the old assertion — a suite that stopped
#: running checks looked exactly like a passing one (the hole G21 names, in
#: miniature). Measured 2026-09-26 at 3dbe4d4: 24 / 19 / 83 (+3 in the
#: no-ErpQuoteFacts run) / 21 / 29; floors sit ~15% under, so a deleted
#: check trips them and a re-count does not. Raise a floor when a suite
#: grows; lowering one is a decision to name in the pull request.
MIN_CHECKS = {
    "bench_panel_retired_test.php": 20,
    "bench_governing_origin_retired_test.php": 15,
    # rc78 (G809 + G804): 113 checks (+30: sections Q, R, S, M9, M10); floor
    # ~15% under, as above. T2 (2026-09-30): 121 checks in the global run
    # (+T0, T0b), 124 in the namespaced run (+T1-T5); floor ~15% under 121.
    "bd_adm_rules_test.php": 102,
    "bd_erp_layout_test.php": 18,
    "bd_customer_group_move_test.php": 25,
    # rc82 (G458): 44 checks at 2026-09-30; floor ~15% under, as above.
    "bd_contact_fields_test.php": 37,
}
#: bd_adm_rules_test.php's second run has no ErpQuoteFacts and is meant to be
#: tiny: an older ERP-Epicor skips the defaults and never fails a save.
MIN_CHECKS_NO_QUOTE_FACTS = 3

_CHECKS = re.compile(r"(\d+) checks, (\d+) failed")


@unittest.skipUnless(php, "requires a PHP CLI")
class PhpSuitesTest(unittest.TestCase):
    def assert_suite_passes(self, name: str, **env: str) -> None:
        result = run(name, **env)
        report = result.stdout + result.stderr
        self.assertIn("checks, 0 failed", report, report)
        self.assertEqual(result.returncode, 0, report)
        self.assertEqual(result.stderr, "", result.stderr)
        counts = _CHECKS.findall(report)
        self.assertTrue(counts, f"{name} reported no 'N checks, M failed' line:\n{report}")
        checks = int(counts[-1][0])
        floor = (MIN_CHECKS_NO_QUOTE_FACTS if env.get("BD_NO_QUOTE_FACTS")
                 else MIN_CHECKS[name])
        self.assertGreaterEqual(
            checks, floor,
            f"{name} ran {checks} checks; the floor is {floor}. A suite that "
            f"stopped running checks is documentation, not a guard.")

    def test_bench_panel_retired(self):
        """The Bench Dogs panel is removed from the Quotes record view."""
        self.assert_suite_passes("bench_panel_retired_test.php")

    def test_bench_governing_origin_retired(self):
        """G116: bd_governing_origin is taken off the Opportunity record view
        on install, and never put back."""
        self.assert_suite_passes("bench_governing_origin_retired_test.php")

    def test_bd_adm_rules(self):
        """G380/G381 (🔒 1724b): the Bench Dogs ADM rules (which companies are
        ADM, the Reference and Project defaults and their exit order, the
        pickers' options, the vardef markers) - and, in a second run with no
        ErpQuoteFacts at all, that an older ERP-Epicor skips the defaults and
        never fails a save - and, in a third run, the whole suite against
        ERP-Epicor 1.2.0's namespaced, autoloaded ErpQuoteFacts (T2, #158),
        with no global class. The first run is ERP-Epicor 1.1's global class."""
        self.assert_suite_passes("bd_adm_rules_test.php", **LANDED)
        self.assert_suite_passes("bd_adm_rules_test.php", BD_NO_QUOTE_FACTS="1")
        self.assert_suite_passes(
            "bd_adm_rules_test.php", BD_ERP_AUTOLOADER=ERP_AUTOLOADER,
            BD_ERP_REFERENCE=LANDED["BD_ERP_REFERENCE"])

    def test_bd_erp_layout(self):
        """rc70: the Bench fields are placed and retired by ERP-Core's REAL
        ErpLayoutExtraFields through rc70's REAL lifecycle scripts - upgrade from
        rc69, reinstall, ERP-Epicor reinstall, uninstall fresh and upgraded."""
        self.assert_suite_passes("bd_erp_layout_test.php", **LANDED)

    def test_bd_customer_group_move(self):
        """G507: the one-off takes the customer-group pair out of the Account
        HEADER (where rc69 put it on a view with no panel_body - reproduced with
        rc69's real writer) onto the first tab, after Industry, labelled; keeps an
        admin's placement; places nothing without a vardef; writes once. Beside
        rc72's real scripts and ERP-Core's real sync(): rc72 alone does not move
        them, a fresh rc72 install lands in the same slots, either install order
        ends the same, and uninstall takes them off."""
        self.assert_suite_passes("bd_customer_group_move_test.php", **LANDED)

    def test_bd_contact_fields(self):
        """G458 (rc82): the five Epicor contact fields the customer's package
        creates are shown READ-ONLY on the Contacts record view (ERP panel),
        by a sidecar overlay run the way MetaDataFiles includes it - twice
        per build - and a tenant WITHOUT that package gets its view back
        byte for byte (no field it lacks is referenced)."""
        self.assert_suite_passes("bd_contact_fields_test.php")



class PhpSuiteCoverageTest(unittest.TestCase):
    """This one needs no PHP: it is about what is WIRED, not what passes."""

    def test_every_named_suite_has_a_check_floor(self):
        self.assertEqual(sorted(MIN_CHECKS), sorted(SUITES),
                         "MIN_CHECKS and SUITES disagree; a suite without a "
                         "floor can go quiet")

    def test_every_php_suite_in_this_directory_is_named_above(self):
        found = sorted(p.name for p in HERE.glob("*_test.php"))
        self.assertEqual(found, sorted(SUITES),
                         "a PHP suite that nothing runs is documentation, not a guard")

    def test_each_named_suite_has_a_test_method_of_its_own(self):
        """One method per suite, so a failure names the suite rather than
        stopping the loop at the first one."""
        methods = [m for m in dir(PhpSuitesTest) if m.startswith("test_")]
        self.assertEqual(len(methods), len(SUITES), methods)


if __name__ == "__main__":
    unittest.main()
