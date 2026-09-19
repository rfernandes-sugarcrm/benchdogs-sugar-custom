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

These tests pin the correction. Each one FAILS on the old behaviour, where the
files are absent: that is the point of them.
"""

import re
import unittest
from pathlib import Path


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
}

# NOT retired — RESTORED. The connector writes these on every quote sync from
# transformers/quotes.py, which IS reachable from the app.py core imports. The
# package stopped declaring them at rc43 and never stopped needing them.
RESTORED = {"bd_quoted", "bd_date_quoted"}


def strip_comments(source: str) -> str:
    """Remove /* */ and // comments, so a stub of pure prose reads as empty."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    source = re.sub(r"(?m)^\s*//.*$", "", source)
    return source


class ProductsVardefOrphansTest(unittest.TestCase):
    def test_the_stub_files_are_shipped(self):
        """FAILS ON THE OLD BEHAVIOUR: before this change neither file existed.

        Absence is not neutral here. The installer can only overwrite a path it
        actually ships, so a missing stub means the tenant keeps its copy."""
        for filename in sorted(ORPHANS):
            with self.subTest(filename):
                self.assertTrue(
                    (VARDEFS / filename).exists(),
                    f"{filename} must ship - it is what overwrites the stale declaration",
                )

    def test_each_stub_declares_nothing(self):
        for filename, field in sorted(ORPHANS.items()):
            with self.subTest(filename):
                source = (VARDEFS / filename).read_text(encoding="utf-8")
                self.assertIn("RETIRED", source.upper())
                self.assertIn(field, source, "the stub must say what it retired")
                self.assertNotIn("$dictionary", strip_comments(source), filename)

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
        twin = (
            PACKAGE / "custom" / "Extension" / "modules" / "Opportunities"
            / "Ext" / "Vardefs" / "bd_governing_origin.php"
        )
        self.assertTrue(twin.exists(), "the Opportunities stub must keep shipping")
        self.assertNotIn("$dictionary", strip_comments(twin.read_text(encoding="utf-8")))


class QuotesVardefOrphansTest(unittest.TestCase):
    """G38 — the same defect on Quotes, nine files.

    FAILS ON THE OLD BEHAVIOUR: before this change none of these files shipped."""

    def test_every_quote_stub_ships_and_declares_nothing(self):
        for filename, field in sorted(QUOTE_ORPHANS.items()):
            with self.subTest(filename):
                path = QUOTE_VARDEFS / filename
                self.assertTrue(path.exists(), f"{filename} must ship - it is the overwrite")
                source = path.read_text(encoding="utf-8")
                self.assertIn("RETIRED", source.upper())
                self.assertIn(field, source, "the stub must say what it retired")
                self.assertNotIn("$dictionary", strip_comments(source), filename)

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
    """The half of G38 that is NOT a retirement, and the reason the whole set
    could not be stubbed in one batch.

    `bd_quoted` / `bd_date_quoted` are written on every quote sync by
    connector_ext_benchdogs/transformers/quotes.py. The package stopped
    declaring them at rc43 while the connector kept writing them, so a FRESH
    tenant would take those writes against fields that do not exist. Bench only
    kept working because it still holds the rc41 copy of the file.

    FAILS ON THE OLD BEHAVIOUR: before this change the file did not ship."""

    VARDEF = QUOTE_VARDEFS / "bd_erp_kpi_inputs.php"

    def test_the_kpi_vardef_ships(self):
        self.assertTrue(self.VARDEF.exists(), "the connector writes these fields every sync")

    def test_it_declares_both_live_fields(self):
        code = strip_comments(self.VARDEF.read_text(encoding="utf-8"))
        declared = set(re.findall(r"""\[['"]fields['"]\]\[['"](bd_\w+)['"]\]""", code))
        self.assertEqual(declared, RESTORED, f"expected exactly {sorted(RESTORED)}, got {sorted(declared)}")

    def test_it_does_not_resurrect_the_retired_stage_code(self):
        """🔒 1045 retired bd_erp_stage_code; it shared this file with the two live
        fields, so restoring the file must not bring it back."""
        code = strip_comments(self.VARDEF.read_text(encoding="utf-8"))
        self.assertNotIn("bd_erp_stage_code", code)

    def test_bd_quoted_stays_a_varchar(self):
        """A Sugar bool is forced required, defaulted to 0 by MySQL, reported
        not-nullable and flattened by fixUpFormatting - four ways for "the source
        did not answer" to become "the source said no". The connector sends
        '1'/'0'/None and depends on unknown staying reachable."""
        code = strip_comments(self.VARDEF.read_text(encoding="utf-8"))
        block = code.split("['bd_quoted']", 1)[1].split("$dictionary", 1)[0]
        self.assertIn("'type' => 'varchar'", block)
        self.assertNotIn("'type' => 'bool'", block)


if __name__ == "__main__":
    unittest.main()
