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

    scripts/post_execute.php -> BdOpportunitiesLayoutExtensions
                                ::writeGoverningOriginField()   (record view)
    scripts/post_execute.php -> (new BdAutoSelectedReport)->install()  (report)

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

🔁 0.9.42-rc69 (G280 / 🔒 1567, 🔒 1521) TAKES THE MARKER REMOVER OUT OF THE
PACKAGE, for the same reason rc66 took the report remover: it is a one-shot that
has run. The one-off ONEOFF-RetireBdResidue carries its own copy of
BdOpportunitiesLayoutExtensions (K-3) and ran it on every QA tenant, and it
deletes both 1044 stubs. So post_install and pre_uninstall no longer call it,
and the class no longer ships. The BUILDER guards stay - no placement, no label,
no saved report, anywhere in the package - because that is the duty owed to a
seller.

THE BEHAVIOURAL HALF RAN IN PHP through rc68
(scripts/tests/bench_governing_origin_retired_test.php); it now runs against the
one-off's K-3 copy, the only one left, wired into CI by
scripts/tests/test_php_suites.py.
"""

import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell" / "BenchDogs-Ext"
REPORT = PACKAGE / "custom" / "modules" / "Opportunities" / "BdAutoSelectedReport.php"
LAYOUT = PACKAGE / "custom" / "modules" / "Opportunities" / "BdOpportunitiesLayoutExtensions.php"
ONEOFF = ROOT / "sugar-sell" / "ONEOFF-RetireBdResidue"
ONEOFF_LAYOUT = ONEOFF / "leftovers" / "BdOpportunitiesLayoutExtensions.php"
POST_INSTALL = PACKAGE / "scripts" / "post_execute.php"
PRE_UNINSTALL = PACKAGE / "scripts" / "bd_pre_uninstall.php"

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
    def test_no_executable_mention_is_left_in_the_package(self):
        """FAILS ON THE OLD BEHAVIOUR: rc56 named the field in three places —
        the record-view writer, the report's display column and the report's
        filter. Through rc68 one was left, the name remove() strips; from rc69
        the remover is the one-off's, and the package names the field nowhere.

        Scoped to the whole package rather than to the files that had it,
        because the miss that produced G116 was a census that looked at one
        module. A placement anywhere is the same defect.
        """
        found = []
        for path in sorted(PACKAGE.rglob("*.php")):
            if "releases" in path.parts:
                continue
            for line in code(path).splitlines():
                if re.search(r"\b" + FIELD + r"\b", line):
                    found.append((str(path.relative_to(ROOT)), line.strip()))
        self.assertEqual(found, [], found)
        # ...and the one-off's copy is the one that still strips it.
        self.assertIn("private const FIELD = 'bd_governing_origin';", code(ONEOFF_LAYOUT))

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

    def test_both_halves_of_the_field_are_retired_off_the_tenant(self):
        """§CW / G37: a dropped custom/Extension file is not retired. Through
        rc68 both stubs shipped EMPTY and overwrote the tenant's copy; from rc69
        the one-off deletes both paths."""
        from bd_retirement import assert_retired_by_oneoff
        for rel in ("custom/Extension/modules/Opportunities/Ext/Vardefs/bd_governing_origin.php",
                    "custom/Extension/modules/Opportunities/Ext/Language/en_us.bd_governing_origin.php"):
            with self.subTest(file=rel):
                assert_retired_by_oneoff(self, rel, "Opportunity.bd_governing_origin")


class TheInstallRemovesRatherThanPlacesTest(unittest.TestCase):
    def test_the_placement_writer_is_GONE_from_the_remover_that_is_left(self):
        """A member that can never run reads as live machinery. The package no
        longer ships the class at all (rc69); the one-off's K-3 copy is the one
        remover left, and it must not have grown a writer back."""
        self.assertFalse(LAYOUT.exists(), f"{LAYOUT.name} ships again; K-3 is the one-off's")
        layout = code(ONEOFF_LAYOUT)
        self.assertNotIn("function writeGoverningOriginField", layout)
        self.assertNotIn("function indexOf", layout)
        # One caller of saveViewdef, and it is reached only from remove().
        self.assertEqual(layout.count("saveViewdef"), 1, layout)

    def test_the_install_neither_writes_nor_removes_the_marker_any_more(self):
        """FAILED ON THE ORIGINAL BEHAVIOUR: post_install.php:191 called
        writeGoverningOriginField() on every install. rc57-rc68 called remove()
        instead; rc69 calls neither - the removal is spent (one-off K-3)."""
        post = code(POST_INSTALL)
        self.assertNotIn("writeGoverningOriginField", post)
        self.assertNotIn("BdOpportunitiesLayoutExtensions", post)
        self.assertIn("BdOpportunitiesLayoutExtensions::remove();", code(ONEOFF / "scripts" / "post_execute.php"))

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

    def test_the_uninstall_cleanup_as_a_whole_was_not_emptied(self):
        """ANTI-VACUITY for the case above. rc69's pre_uninstall no longer
        removes the marker (spent), but the uninstall must still undo the
        placements the package still makes. Since rc70 (🔒 1724b) that is
        post_uninstall's ErpLayoutExtraFields::sync() for both modules, which
        retires the marked fields once their vardefs are gone."""
        pre = code(PRE_UNINSTALL)
        self.assertNotIn("BdOpportunitiesLayoutExtensions", pre)
        post = code(PRE_UNINSTALL.with_name("post_uninstall.php"))
        self.assertIn("ErpLayoutExtraFields::sync($bdModule)", post)
        self.assertIn("array('Accounts', 'Quotes')", post)


if __name__ == "__main__":
    unittest.main()
