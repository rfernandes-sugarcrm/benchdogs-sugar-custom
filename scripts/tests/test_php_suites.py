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


def run(name: str, **env: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        [php, str(HERE / name)], cwd=ROOT, capture_output=True, text=True,
        env={**os.environ, **env},
    )


#: ERP-Epicor's quote facts (G380 (g), lane D) in the sibling checkout, when it
#: carries them. bd_adm_rules_test.php runs BdAdmRules against a stand-in for
#: this class; the stand-in's two method names are only worth anything while
#: the real class has them.
QUOTE_FACTS = shared_sugar.SIBLING / "sugar-sell/ERP-Epicor/src/custom/modules/Quotes/ErpQuoteFacts.php"


@unittest.skipUnless(php, "requires a PHP CLI")
class PhpSuitesTest(unittest.TestCase):
    def assert_suite_passes(self, name: str, **env: str) -> None:
        result = run(name, **env)
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
        """G380/G381 (🔒 1724b): the Bench Dogs ADM rules (which companies are
        ADM, the Reference and Project defaults and their exit order, the
        pickers' options, the vardef markers) - and, in a second run with no
        ErpQuoteFacts at all, that an older ERP-Epicor skips the defaults and
        never fails a save."""
        self.assert_suite_passes("bd_adm_rules_test.php")
        self.assert_suite_passes("bd_adm_rules_test.php", BD_NO_QUOTE_FACTS="1")



@unittest.skipUnless(QUOTE_FACTS.is_file(), "no ERP-Epicor checkout carrying ErpQuoteFacts")
class QuoteFactsStandInTest(unittest.TestCase):
    def test_the_stand_in_matches_erp_epicors_quote_facts(self):
        """The stand-in in bd_adm_rules_test.php must not outlive the real API."""
        real = QUOTE_FACTS.read_text(encoding="utf-8")
        for method in ("companyCode", "productGroup"):
            self.assertRegex(real, rf"public static function {method}\(\$\w+\): string")


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
