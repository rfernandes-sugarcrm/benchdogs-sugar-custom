#!/usr/bin/env python3
"""🔒 1032 — core owns the ERP quote-line number; Bench keeps no copy.

``Product.bd_erp_line_num`` was a Bench-owned column carrying the Kinetic
QuoteLine a native quote line came from. It is retired: the number is now
``Products.erp_quote_line_num``, an ERP-Core vardef written by connector-core's
``QuoteLineCoreTransformer``, and both Bench readers were repointed onto it.

It was never a value only Bench could compute. Core's ``rung_key()`` has always
built ``<company>__<QuoteNum>_<QuoteLine>_<QtyNum>``, so the line number sat
inside the sync key of every connector-owned row; the Bench column existed only
to make it readable.

MEASURED on sugar.local.dev 2026-09-18, before the promotion:

    bd_erp_line_num    > 0 :   6 of 655 native quote line items
    erp_quote_line_num > 0 :   0 of 655   (declared in ERP-Core, never written)
    connector-owned rows whose key already yields a line number : 515 of 521

Bench's writer had gone with the retired quote mirror, so the copy was already
failing the guard that reads it: 13 of the 14 ORDERED lines on that tenant had
no value and reached the refusal.

These tests fail on the pre-retirement package and pass after it. They are
deliberately file-level rather than behavioural — a re-added vardef is invisible
to a behavioural test until something writes it, which is exactly how this
column survived unnoticed with no writer for weeks.
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "sugar-sell/BenchDogs-Ext"
RETIRED_FIELD = "bd_erp_line_num"
RETIRED_LABEL = "LBL_BD_ERP_LINE_NUM"
CORE_FIELD = "erp_quote_line_num"

POLICY = PKG / "custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php"
GRID = PKG / "custom/modules/Quotes/BdQliColumnsLayout.php"
VARDEF = PKG / "custom/Extension/modules/Products/Ext/Vardefs/bd_line_order_fields.php"
LABELS = PKG / "custom/Extension/modules/Products/Ext/Language/en_us.bd_line_order.php"

#: NOT SCANNED, and the exclusion is the point rather than an oversight.
#: ``custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php`` still reads AND
#: writes the retired field. That file belongs to the bd01 mirror retirement,
#: a separate thread, and is deliberately not edited here.
#:
#: 🛑 CORRECTION, AND IT IS NOT COSMETIC. This comment first said those writes
#: "become no-ops once the vardef is gone … so it is inert rather than wrong."
#: THAT WAS WRONG, and it was wrong in the direction that hides a regression.
#: The WRITE is indeed dropped, but ``joinErpLinesToQlis()`` also READS the
#: field, and that read is PASS 1 — the join its own docstring calls "the only
#: join that is certain, because something deliberately wrote it". With the
#: vardef gone the read is always null, ``$lineNum`` is always 0, the exact
#: map is always empty, and EVERY ERP line falls through to PASS 2's tolerant
#: ``(part number, quantity)`` match. PASS 2 refuses an ambiguous pair on
#: purpose, so those lines are never placed at all. The stamp-back that used to
#: heal the xref for the next run dies with the write, so the tolerant match is
#: redone from scratch every time and never converges. Two live callers
#: (BdQuoteReflectionHook.php:863 and :1149).
#:
#: ➡️ THE FIX IS ONE LINE AND IT MAKES PASS 1 STRONGER, NOT WEAKER: point that
#: read at ``erp_quote_line_num``. Core populates it on 515 of 521
#: connector-owned rows against the 6 this column ever reached, so the "certain"
#: join becomes certain far more often — and the stamp-back stops being needed,
#: because core rewrites the value every sync.
#:
#: ➡️ SEQUENCING: the RETIREMENT commit on this branch must not land until that
#: hook is repointed or the bd01 directory is retired. The reader-repoint commit
#: before it is safe on its own.
UNOWNED = PKG / "custom/modules/bd01_ERP_Quote"


def _php_sources():
    """Every PHP file this package ships, minus the bd01 mirror (see UNOWNED)."""
    for path in PKG.rglob("*.php"):
        if UNOWNED in path.parents:
            continue
        yield path


def _code_without_comments(source) -> str:
    """Source with ``//`` and ``/* */`` comments stripped.

    Takes a ``Path`` or a raw string, so a whole file and a single extracted
    function body go through the SAME stripper. They used to go through two
    near-identical ones, which is how a comment-handling difference between
    them would have gone unnoticed.

    The retirement NOTES name the retired field on purpose — they are what tell
    the next author why it went. Scanning raw text would make those notes fail
    the test and pressure someone into deleting the explanation.
    """
    text = source.read_text(encoding="utf-8") if isinstance(source, Path) else source
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    text = re.sub(r"(?m)//.*$", "", text)
    text = re.sub(r"(?m)^\s*#(?!\[).*$", "", text)
    return text


class BenchKeepsNoCopyOfTheErpLineNumber(unittest.TestCase):
    def test_no_php_in_this_package_reads_or_writes_the_retired_field(self):
        offenders = {
            str(p.relative_to(ROOT)): [
                line.strip()
                for line in _code_without_comments(p).splitlines()
                if RETIRED_FIELD in line
            ]
            for p in _php_sources()
            if RETIRED_FIELD in _code_without_comments(p)
        }
        self.assertEqual(offenders, {}, offenders)

    def test_the_vardef_declares_nothing(self):
        """The file is KEPT so an upgrade overwrites the old declaration on a
        tenant that already has it; deleting it would leave that tenant
        declaring a field with no writer and no reader."""
        self.assertTrue(VARDEF.exists(), "keep the file — it overwrites the stale declaration")
        code = _code_without_comments(VARDEF)
        self.assertNotIn("$dictionary", code, code)
        self.assertNotIn(RETIRED_FIELD, code)

    def test_the_retired_label_is_gone(self):
        self.assertNotIn(RETIRED_LABEL, _code_without_comments(LABELS))
        offenders = [
            str(p.relative_to(ROOT))
            for p in _php_sources()
            if RETIRED_LABEL in _code_without_comments(p)
        ]
        self.assertEqual(offenders, [], offenders)

    def test_both_readers_now_read_cores_field(self):
        """Anti-vacuity for every test above: the two readers must still be
        reading SOMETHING. A suite that only checks absence passes just as well
        on a package that deleted the feature outright."""
        policy = _code_without_comments(POLICY)
        self.assertIn(f"$product->{CORE_FIELD}", policy)
        self.assertIn("no unambiguous ERP quote-line identity", POLICY.read_text())

        # THE REFUSAL THAT MAKES AN UNKNOWN LINE SAFE MUST SURVIVE THE REPOINT,
        # and it has to be pinned on the WHOLE condition, not on `<= 0` alone.
        # `$lineNum <= 0` appears TWICE in this file — once in the ERP-line loop
        # above and once in the ordered-line guard here — so a bare substring
        # check passes while either survives. Measured: softening the FIRST one
        # to `< 0` left that check green. The `!array_key_exists` half is what
        # makes this occurrence unique.
        self.assertIn(
            "$lineNum <= 0 || !array_key_exists($lineNum, $lineKinds)", policy
        )
        # And the ERP-line loop's own duplicate/identity refusal, the other
        # `<= 0`, which the above deliberately does not stand in for.
        self.assertIn(
            "$lineNum <= 0 || array_key_exists($lineNum, $lineKinds)", policy
        )

        grid = _code_without_comments(GRID)
        self.assertIn(f"'{CORE_FIELD}'", grid)
        self.assertIn("bdOrderFieldNames", grid)

    def test_the_grid_injects_exactly_the_core_column(self):
        """Pins the list itself, not merely that the name appears: an extra
        Bench field smuggled back into the allowlist is the re-add this guard
        exists to stop."""
        grid = GRID.read_text(encoding="utf-8")
        match = re.search(
            r"private function bdOrderFieldNames\(\): array\s*\{(.*?)\}", grid, re.S
        )
        self.assertIsNotNone(match, "bdOrderFieldNames not found")
        names = re.findall(r"'([a-z0-9_]+)'", _code_without_comments(match.group(1)))
        self.assertEqual(names, [CORE_FIELD], names)


if __name__ == "__main__":
    unittest.main()
