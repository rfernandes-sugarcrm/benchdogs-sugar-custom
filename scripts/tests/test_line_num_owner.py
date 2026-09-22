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

#: RESOLVED. This used to carve `custom/modules/bd01_ERP_Quote/` out of the scan,
#: because `BdQuoteReflectionHook.php` still READ and WROTE the retired field and
#: repointing it belonged to the bd01 mirror retirement rather than to 🔒 1032.
#: That sequencing obligation - "the RETIREMENT commit must not land until that
#: hook is repointed or the bd01 directory is retired" - was discharged by the
#: retirement: decision 901/903 removed the directory outright, so there is no
#: longer a file to exclude and the scan below now covers everything the package
#: ships. The exclusion was deleted rather than left in place pointing at
#: nothing, because a filter that matches no path reads as coverage of a risk
#: that is no longer being managed.


def _php_sources():
    """Every PHP file this package ships. Nothing is excluded — see above."""
    return PKG.rglob("*.php")


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

    def test_the_surviving_readers_still_read_something(self):
        """Anti-vacuity for every test above: a suite that only checks ABSENCE
        passes just as well on a package that deleted the feature outright.

        🛑 THIS USED TO ASSERT *BOTH* READERS READ THE CORE FIELD, and that is no
        longer true of one of them. When 🔒 1032 repointed Bench off
        ``bd_erp_line_num``, the release-stage policy read a line NUMBER to map
        each ordered line onto a prototype/production role. Decision 901/903
        retired the ``bd01_*`` mirror that supplied the roles, and the policy was
        rewritten to COUNT COMMITTED LINES instead - it no longer needs an
        identity per line, so it reads no line number at all. Re-adding one to
        satisfy this test would put back a read the code has no use for. The grid
        remains the reader of the core field, and the policy is pinned on what it
        actually reads now.
        """
        grid = _code_without_comments(GRID)
        self.assertIn(f"'{CORE_FIELD}'", grid)
        self.assertIn("bdOrderFieldNames", grid)

        # The policy still reads the native line, and still refuses rather than
        # classifying off a partial read. Both halves are pinned so that the
        # retirement above cannot quietly become "reads nothing".
        policy = _code_without_comments(POLICY)
        # 0.9.42-rc65 (G280 / 🔒 1507): the provider is a stub that returns null
        # and Partial Fulfillment decides the stage from tenant config, so there
        # is no erp_ordered read left here to pin. What must stay true is that
        # this package reads the ORDERED FLAG NOWHERE ELSE either - if a reader
        # comes back, it belongs in core.
        self.assertNotIn("$product->erp_ordered", policy)
        self.assertIn("return null", policy)
        self.assertNotIn("BeanFactory::", policy, "the retired provider is reading beans again")
        self.assertNotIn(RETIRED_FIELD, policy)

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
