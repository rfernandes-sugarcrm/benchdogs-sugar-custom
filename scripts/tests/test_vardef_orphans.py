#!/usr/bin/env python3
"""🔒 1044 completed: the two Products vardefs that were DELETED, not emptied.

A retired field leaves this package by being OVERWRITTEN with an empty stub.
It does not leave by being deleted from the build: on Sugar Cloud neither
omission nor uninstall removes a custom/Extension file a previous install
already copied (rc24 proved it, Bench 2026-09-14 - six files dropped, clean
install, INERT). Delete the file and the field stays live on every tenant that
has it, while vanishing from the tree - so the retirement reads as done and
greps clean.

That is exactly what happened to two Products vardefs. Measured across every
Bench zip on disk:

    rc29..rc41  declare Product.bd_governing_origin  and  Product.bd_deleted_erp_sync_key
    rc43..rc50  ship neither file at all

and measured on the tenant (ophirsx177, 2026-09-19) those two are the only bd_*
fields Sugar still serves for Products, while Opportunities - whose twin 🔒 1044
did empty correctly - serves none.

These tests pinned the correction: each one FAILED on the old behaviour, where
the files were absent.

🔁 RE-POINTED 0.9.42-rc69 (G280 / 🔒 1567, 🔒 1521). The lesson above still
stands - DROPPING a path retires nothing - but the thing that removes the path
from the tenant is no longer an empty stub this package keeps shipping. It is
the one-off ONEOFF-RetireBdResidue, which deleted each path through
ModuleInstaller::uninstallExt() and ran on every QA tenant. So each "must ship"
below became "retired off the tenant": not in the package, not in the built zip,
and still on the one-off's worklist.

🔁 🔒2173b (2026-09-30) WITHDREW the one-off and deleted its code, so the
worklist half is gone and each case now checks "not shipped"
(bd_retirement.assert_not_shipped). Said plainly: that alone passes on exactly
the defect this file was written about. A tenant the one-off never ran on keeps
the stale declarations, and nothing in this repository removes them any more.
"""

import re
import unittest
from pathlib import Path

from bd_retirement import assert_not_shipped


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell" / "BenchDogs-Ext"
VARDEFS = PACKAGE / "custom" / "Extension" / "modules" / "Products" / "Ext" / "Vardefs"

# Products: filename -> the field name whose stale declaration it must overwrite
ORPHANS = {
    "bd_governing_line_fields.php": "bd_governing_origin",
    "bd_deleted_erp_sync_key.php": "bd_deleted_erp_sync_key",
}

QUOTE_VARDEFS = PACKAGE / "custom" / "Extension" / "modules" / "Quotes" / "Ext" / "Vardefs"

# Quotes: the same defect, found by diffing what the package DECLARES against
# what the tenant SERVES. Each was deleted from the build rather than emptied,
# so each is still live on every tenant that has it (G38).
QUOTE_ORPHANS = {
    "bd_comment_pending.php": "bd_comment_pending",
    "bd_comment_requested_at.php": "bd_comment_requested_at",
    "bd_comment_text.php": "bd_comment_text",
    "bd_erp_stage_code.php": "bd_erp_stage_code",
    "bd_estimating_turnaround.php": "bd_estimating_turnaround",
    "bd_print_link.php": "bd_print_link",
    "bd_print_requested_at.php": "bd_print_requested_at",
    "bd_print_status.php": "bd_print_status",
    "bd_quantity_breaks.php": "bd_quantity_breaks",
    # 🚩 SAME FIELD NAME AS THE PRODUCTS STUB, DIFFERENT MODULE. A field name is
    # not a key: the first census mapped field -> file by first match and this
    # declaration stayed hidden behind the Products one. Map (module, field).
    "bd_deleted_erp_sync_key.php": "bd_deleted_erp_sync_key",
}

# NOT retired — RESTORED. The connector writes these on every quote sync from
# transformers/quotes.py, which IS reachable from the app.py core imports. The
# package stopped declaring them at rc43 and never stopped needing them.
RESTORED = {"bd_quoted", "bd_date_quoted"}  # historical: both RETIRED 2026-09-19,
# owner ruling, after PR #8 reduced the Bench connector to two written fields.
# Kept as a NAMED SET so the inverted assertions below read against something
# concrete, and so a future reinstatement has the original pair to restore.


def strip_comments(source: str) -> str:
    """Remove /* */ and // comments, so a stub of pure prose reads as empty."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    source = re.sub(r"(?m)^\s*//.*$", "", source)
    return source


class ProductsVardefOrphansTest(unittest.TestCase):
    def test_the_stub_paths_never_ship_again(self):
        """Absence is not neutral here: a path nobody removes stays on the
        tenant. Through rc68 the package shipped these paths EMPTY; from rc69
        the one-off deleted them, until 🔒2173b withdrew it."""
        for filename, field in sorted(ORPHANS.items()):
            with self.subTest(filename):
                assert_not_shipped(self, str((VARDEFS / filename).relative_to(PACKAGE)),
                                   f"the stale Product.{field} declaration")

    def test_the_declaration_does_not_reappear_anywhere_in_the_package(self):
        """🛑 DO NOT RE-ADD. Catches a re-declaration under any filename - the
        first investigation went wrong by trusting one filename's absence."""
        # Either quote style. A mutation that re-declared the field with double
        # quotes slipped past the single-quoted form of this regex, which would
        # have let the exact regression this file exists to catch back in.
        pattern = re.compile(
            r"""\[['"]fields['"]\]\[['"](bd_governing_origin|bd_deleted_erp_sync_key)['"]\]"""
        )
        offenders = []
        for php in PACKAGE.rglob("*.php"):
            # Comments are stripped first: the stubs themselves quote the
            # declaration they retired, and that prose is the evidence, not a
            # regression. Only live code counts.
            hit = pattern.search(
                strip_comments(php.read_text(encoding="utf-8", errors="replace"))
            )
            if hit:
                offenders.append(f"{php.relative_to(PACKAGE)} declares {hit.group(1)}")
        self.assertEqual(offenders, [], "; ".join(offenders))

    def test_the_opportunities_twin_is_still_retired(self):
        """The half that DID land, pinned so a later cleanup cannot undo it."""
        assert_not_shipped(
            self, "custom/Extension/modules/Opportunities/Ext/Vardefs/bd_governing_origin.php",
            "Opportunity.bd_governing_origin")


class QuotesVardefOrphansTest(unittest.TestCase):
    """G38 — the same defect on Quotes, nine files.

    FAILS ON THE OLD BEHAVIOUR: before this change none of these files shipped."""

    def test_no_quote_stub_ships_again(self):
        for filename, field in sorted(QUOTE_ORPHANS.items()):
            with self.subTest(filename):
                assert_not_shipped(self, str((QUOTE_VARDEFS / filename).relative_to(PACKAGE)),
                                   f"the stale Quote.{field} declaration")

    def test_the_retired_quote_fields_are_declared_nowhere(self):
        pattern = re.compile(
            r"""\[['"]fields['"]\]\[['"](%s)['"]\]""" % "|".join(sorted(QUOTE_ORPHANS.values()))
        )
        offenders = []
        for php in PACKAGE.rglob("*.php"):
            hit = pattern.search(strip_comments(php.read_text(encoding="utf-8", errors="replace")))
            if hit:
                offenders.append(f"{php.relative_to(PACKAGE)} declares {hit.group(1)}")
        self.assertEqual(offenders, [], "; ".join(offenders))


class LiveKpiFieldsMustKeepShippingTest(unittest.TestCase):
    """The half of G38 that was NOT a retirement at first - and then was.

    `bd_quoted` / `bd_date_quoted` were written on every quote sync by the Bench
    connector, so the vardef had to keep shipping. Then OWNER RULING (2026-09-19):
    *"make sure you retire this bd_quoted / bd_date_quoted"*, after PR #8 reduced
    the Bench connector to exactly TWO written fields. The file shipped EMPTY
    through rc68; from rc69 the one-off deleted it (withdrawn, 🔒2173b)."""

    VARDEF = QUOTE_VARDEFS / "bd_erp_kpi_inputs.php"

    def test_the_kpi_vardef_never_ships_again(self):
        assert_not_shipped(self, str(self.VARDEF.relative_to(PACKAGE)),
                           "bd_quoted / bd_date_quoted / bd_erp_stage_code")

    def test_nothing_in_the_package_declares_them_now(self):
        """Neither they nor 🔒 1045's bd_erp_stage_code may come back under any
        filename."""
        pattern = re.compile(r"""\[['"]fields['"]\]\[['"](bd_quoted|bd_date_quoted|bd_erp_stage_code)['"]\]""")
        offenders = []
        for php in PACKAGE.rglob("*.php"):
            hit = pattern.search(strip_comments(php.read_text(encoding="utf-8", errors="replace")))
            if hit:
                offenders.append(f"{php.relative_to(PACKAGE)} declares {hit.group(1)}")
        self.assertEqual(offenders, [], "; ".join(offenders))

    def test_bd_quoted_is_not_resurrected_as_a_bool(self):
        """INVERTED 2026-09-19 — the reason it mattered is why it is kept.

        bd_quoted is retired, so there is no type to assert. But if it is ever
        reinstated, the ORIGINAL hazard is still live and still subtle: a Sugar
        bool is forced required, defaulted to 0 by MySQL, reported not-nullable
        and flattened by fixUpFormatting — FOUR ways for "the source did not
        answer" to become "the source said no". It was a varchar for exactly
        that reason. Carried forward for whoever brings it back.
        """
        for php in PACKAGE.rglob("*.php"):
            code = strip_comments(php.read_text(encoding="utf-8", errors="replace"))
            self.assertNotIn("['bd_quoted']", code, f"{php.name}: bd_quoted is retired")


if __name__ == "__main__":
    unittest.main()
