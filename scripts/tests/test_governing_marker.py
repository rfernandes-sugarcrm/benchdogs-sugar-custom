"""G116: decision 72's marker and its review report are REMOVED on install.

WHAT THIS FILE USED TO BE, AND WHY IT IS INVERTED RATHER THAN DELETED
---------------------------------------------------------------------
It pinned `BdAutoSelectedReport::reportDef()` — the filter, the columns and the
joins of a saved report that listed every Opportunity still valued from an
auto-selected quote line. Its own docstring already carried the finding that
closed it:

    🚩 REPORTED, NOT FIXED HERE: `BdAutoSelectedReport` still ships and still
    filters Opportunities on `bd_governing_origin = 'auto'`, but the only code
    that ever wrote 'auto' was `BdGoverningAutoSelect` ... both retired. So the
    report is well-formed and permanently empty.

🔒 1044 had retired `bd_governing_origin` on Opportunities — vardef AND label
emptied to stubs that declare nothing — while TWO surfaces went on placing it
once per install:

    scripts/post_install.php -> BdOpportunitiesLayoutExtensions
                                ::writeGoverningOriginField()   (record view)
    scripts/post_install.php -> (new BdAutoSelectedReport)->install()  (report)

The record-view one rendered the raw key `LBL_BD_GOVERNING_ORIGIN` as "No
data" on a live tenant. The report one cannot error and cannot fill; it renders
EMPTY, which on a review queue reads as "nothing to review".

The assertions below are INVERTED, not deleted, for the reason the hook stub in
test_account_country_guard.py gives: what regressed here was a retirement that
re-armed itself on the next install, so the guard has to be a test that fails
if the writer comes back. A test that pinned `reportDef()` would only pin the
shape of a report that must no longer be built.

0.9.42-rc66 (G280 / 🔒 1508) TAKES THE REMOVER OUT TOO, and the distinction is
the whole point of this file: the BUILDER guard stays and widens to the whole
package, because that is the duty owed to a seller; the REMOVER guard flips,
because a one-shot that has run and found nothing is not a safeguard. The
evidence for "has run" is the tenant's own install log, quoted on the case.

THE BEHAVIOURAL HALF IS IN PHP, and it RUNS the code rather than scanning it:
scripts/tests/bench_governing_origin_retired_test.php, wired into CI by
scripts/tests/test_php_suites.py.
"""

import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell" / "BenchDogs-Ext"
REPORT = PACKAGE / "custom" / "modules" / "Opportunities" / "BdAutoSelectedReport.php"
LAYOUT = PACKAGE / "custom" / "modules" / "Opportunities" / "BdOpportunitiesLayoutExtensions.php"
POST_INSTALL = PACKAGE / "scripts" / "post_install.php"
PRE_UNINSTALL = PACKAGE / "scripts" / "pre_uninstall.php"

FIELD = "bd_governing_origin"


def code(path: Path) -> str:
    """The file with /* */ and // comments removed.

    Every file here NAMES the retired field in the prose explaining why it no
    longer uses it, and a blunt substring match would read that explanation as
    the defect it documents.
    """
    source = re.sub(r"/\*.*?\*/", "", path.read_text(), flags=re.S)
    return re.sub(r"(?m)//.*$", "", source)


class TheRetiredFieldHasNoWriterLeftTest(unittest.TestCase):
    def test_the_only_executable_mention_left_is_the_one_that_removes_it(self):
        """FAILS ON THE OLD BEHAVIOUR: rc56 named the field in three places —
        the record-view writer, the report's display column and the report's
        filter. One is left, and it is the name remove() strips.

        Scoped to the whole package rather than to the files that had it,
        because the miss that produced G116 was a census that looked at one
        module. A placement anywhere is the same defect.
        """
        allowed = (
            "sugar-sell/BenchDogs-Ext/custom/modules/Opportunities/"
            "BdOpportunitiesLayoutExtensions.php",
            "private const FIELD = 'bd_governing_origin';",
        )
        found = []
        for path in sorted(PACKAGE.rglob("*.php")):
            if "releases" in path.parts:
                continue
            for line in code(path).splitlines():
                if re.search(r"\b" + FIELD + r"\b", line):
                    found.append((str(path.relative_to(ROOT)), line.strip()))
        self.assertEqual(found, [allowed], found)

    def test_the_label_the_placement_rendered_is_named_by_nothing(self):
        """`LBL_BD_GOVERNING_ORIGIN` is a 1044 stub that defines no label, so
        any file still naming it renders the raw key."""
        offenders = []
        for path in sorted(PACKAGE.rglob("*.php")):
            if "releases" in path.parts:
                continue
            if "LBL_BD_GOVERNING_ORIGIN" in code(path):
                offenders.append(str(path.relative_to(ROOT)))
        self.assertEqual(offenders, [])

    def test_both_halves_of_the_field_still_ship_as_stubs_that_declare_nothing(self):
        """§CW / G37: only OVERWRITING a copied custom/Extension file retires
        it. Deleting either stub would put the field back on every tenant that
        has it."""
        ext = PACKAGE / "custom" / "Extension" / "modules" / "Opportunities" / "Ext"
        for stub in (ext / "Vardefs" / "bd_governing_origin.php",
                     ext / "Language" / "en_us.bd_governing_origin.php"):
            self.assertTrue(stub.exists(), f"{stub} must keep shipping")
            body = code(stub).replace("<?php", "").strip()
            self.assertEqual(body, "", f"{stub} must declare nothing")


class TheInstallRemovesRatherThanPlacesTest(unittest.TestCase):
    def test_the_placement_writer_is_GONE_from_the_layout_class(self):
        """A member that can never run reads as live machinery. This package
        has already paid three install cycles for code that looked active and
        was not."""
        layout = code(LAYOUT)
        self.assertNotIn("function writeGoverningOriginField", layout)
        self.assertNotIn("function indexOf", layout)
        # One caller of saveViewdef, and it is reached only from remove().
        # Counted rather than positioned: an assertion keyed on where the
        # method sits in the file goes red when somebody reorders methods,
        # which is a mystery failure rather than a finding.
        self.assertEqual(layout.count("saveViewdef"), 1, layout)

    def test_post_install_REMOVES_the_marker_and_never_writes_it(self):
        """FAILS ON THE OLD BEHAVIOUR: post_install.php:191 called
        writeGoverningOriginField() on every install, re-arming the raw label
        after any repair or re-install."""
        post = code(POST_INSTALL)
        self.assertNotIn("writeGoverningOriginField", post)
        self.assertIn("BdOpportunitiesLayoutExtensions::remove()", post)

    def test_the_package_builds_no_saved_report_at_all(self):
        """THE DUTY THAT SURVIVES rc66, and the only one that was ever about a
        seller. G116's trap is a saved report that renders EMPTY on a review
        queue, which reads as "nothing to review" rather than as broken. The
        REMOVER is gone (see the class docstring below); the BUILDER must stay
        gone, and no new one may appear anywhere in the package."""
        offenders = []
        for php in PACKAGE.rglob("*.php"):
            body = code(php)
            if "save_report" in body or "filters_def" in body or "newBean('Reports')" in body:
                offenders.append(str(php.relative_to(PACKAGE)))
        self.assertEqual(offenders, [],
                         f"this package builds a saved report again: {offenders}")

    def test_the_spent_remover_no_longer_ships_or_is_called(self):
        """🛑 rc66 (G280 / 🔒 1508) DELETES BdAutoSelectedReport. Read the
        class docstring above before treating this as a loosened guard: the
        remover is not "unused code that looked safe to drop", it is a one-shot
        that HAS RUN AND FOUND NOTHING. The tenant's package_install.log for the
        rc65 install (PID 2069085, 17:16:59 -> 17:17:35, the window that carries
        post_install's own `running` and `finished` lines) contains NEITHER
        `removed retired saved report` NOR `missing, retired review report left
        behind`, while every other BenchDogs-Ext line of that install is there.
        So remove() executed and the row was already gone — and SugarQuery
        cannot re-find a row it has already soft-deleted.

        Both call sites go with it. A call left behind would be worse than the
        class: post_install guards with file_exists, but a bare require_once on
        a missing file is a COMPILE error no try/catch can catch."""
        self.assertFalse(REPORT.exists(), f"{REPORT.name} is shipping again")
        for script in (POST_INSTALL, PRE_UNINSTALL):
            with self.subTest(script=script.name):
                self.assertNotIn("BdAutoSelectedReport", code(script),
                                 f"{script.name} still calls the deleted remover")

    def test_uninstall_still_removes_the_marker(self):
        """ANTI-VACUITY for the case above: the uninstall cleanup as a whole
        must not have been emptied along with the report step."""
        pre = code(PRE_UNINSTALL)
        self.assertIn("BdOpportunitiesLayoutExtensions::remove()", pre)


if __name__ == "__main__":
    unittest.main()
