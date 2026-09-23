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
)

php = shutil.which("php")


def run(name: str) -> subprocess.CompletedProcess:
    # bd_adm_rules_test.php runs the Bench hook adapters THROUGH ERP-Epicor's
    # real dispatcher: the sibling checkout's file when present, else the pin.
    env = {**os.environ,
           "BD_ERP_QUOTE_HOOKS": str(shared_sugar.resolve("ErpQuoteHooks.php"))}
    return subprocess.run(
        [php, str(HERE / name)], cwd=ROOT, capture_output=True, text=True, env=env,
    )


@unittest.skipUnless(php, "requires a PHP CLI")
class PhpSuitesTest(unittest.TestCase):
    def assert_suite_passes(self, name: str) -> None:
        result = run(name)
        report = result.stdout + result.stderr
        self.assertIn("checks, 0 failed", report, report)
        self.assertEqual(result.returncode, 0, report)
        self.assertEqual(result.stderr, "", result.stderr)

    def test_bench_panel_retired(self):
        """The Bench Dogs panel is removed from the Quotes record view."""
        self.assert_suite_passes("bench_panel_retired_test.php")

    def test_bench_governing_origin_retired(self):
        """G116: bd_governing_origin is taken off the Opportunity record view
        on install, and never put back."""
        self.assert_suite_passes("bench_governing_origin_retired_test.php")

    def test_bd_adm_rules(self):
        """G380/G381: the Bench Dogs ADM rules (company gate, Reference and
        Project defaults, the non-part block through ERP-Epicor's real hook
        dispatcher, the pickers' options, placement, vardefs)."""
        self.assert_suite_passes("bd_adm_rules_test.php")


class PhpSuiteCoverageTest(unittest.TestCase):
    """This one needs no PHP: it is about what is WIRED, not what passes."""

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
