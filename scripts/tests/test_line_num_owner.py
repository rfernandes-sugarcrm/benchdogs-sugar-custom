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

#: 🛑 BOTH READERS ARE GONE (0.9.42-rc66, G280 / 🔒 1508). The release-stage
#: provider and the quoted-lines grid class were the two files this decision
#: repointed onto core's field; rc66 deletes them, so there is no Bench reader
#: of `erp_quote_line_num` left to pin. `test_the_surviving_readers_still_read_
#: something` below became `test_no_reader_survives_and_none_may_return`, which
#: is the same anti-vacuity duty pointed the other way: the risk is no longer
#: "the retirement deleted the feature", it is "a reader comes back".
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

    def test_the_vardef_is_retired_off_the_tenant(self):
        """Through rc68 the file was KEPT, emptied, so an upgrade overwrote the
        old declaration on a tenant that already had it - dropping it would
        leave that tenant declaring a field with no writer and no reader. From
        rc69 (G280 / 🔒 1567, 🔒 1521) the one-off deletes the path instead, so
        the package stops shipping it."""
        from bd_retirement import assert_retired_by_oneoff
        assert_retired_by_oneoff(self, str(VARDEF.relative_to(PKG)),
                                 "the tenant would keep declaring bd_erp_line_num")

    def test_the_retired_label_is_gone(self):
        from bd_retirement import assert_retired_by_oneoff
        assert_retired_by_oneoff(self, str(LABELS.relative_to(PKG)), "LBL_BD_ERP_LINE_NUM")
        offenders = [
            str(p.relative_to(ROOT))
            for p in _php_sources()
            if RETIRED_LABEL in _code_without_comments(p)
        ]
        self.assertEqual(offenders, [], offenders)

    def test_no_reader_survives_and_none_may_return(self):
        """Anti-vacuity, INVERTED for rc66 (G280 / 🔒 1508).

        🛑 WHAT THIS TEST USED TO GUARD AND WHY IT FLIPPED. A suite that only
        checks ABSENCE passes just as well on a package that deleted the feature
        outright, so this case existed to prove the two surviving readers still
        read CORE's field. rc66 deletes both of them: the release-stage provider
        (Partial Fulfillment decides the stage) and the quoted-lines grid class
        (the fetch injection was a core column no viewdef draws). "Deleted the
        feature outright" is now the RULING, not the accident.

        So the duty turns around. The live risk is a Bench reader of a core
        field coming back - which is the shape 🔒 1508 forbids and the shape
        🔒 1032 spent a release repointing. Executable code is scanned;
        comments are not, because the retirement notes have to be free to name
        the field they retired.
        """
        offenders = []
        for php in _php_sources():
            code = _code_without_comments(php)
            if CORE_FIELD in code:
                offenders.append(str(php.relative_to(ROOT)))
        self.assertEqual(
            offenders, [],
            f"this package reads or injects core's {CORE_FIELD} again: {offenders}")

    def test_the_two_deleted_readers_do_not_ship(self):
        """Named rather than inferred: these are the two files 🔒 1032 repointed
        and 🔒 1508 removed, and a build that ships either has undone one of
        them. Shipping the grid class again would also re-arm the fatal
        `BaseErpLayout::loadView()` call rc65's install logged."""
        for gone in ("custom/modules/Quotes/BdQliColumnsLayout.php",
                     "custom/modules/Quotes/BdQliColumnTemplate.php",
                     "custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php"):
            with self.subTest(file=gone):
                self.assertFalse((PKG / gone).exists(), f"{gone} is shipping again")


if __name__ == "__main__":
    unittest.main()
