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

# filename -> the field name whose stale declaration it must overwrite
ORPHANS = {
    "bd_governing_line_fields.php": "bd_governing_origin",
    "bd_deleted_erp_sync_key.php": "bd_deleted_erp_sync_key",
}


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


if __name__ == "__main__":
    unittest.main()
