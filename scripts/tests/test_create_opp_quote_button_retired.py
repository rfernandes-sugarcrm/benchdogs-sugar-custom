#!/usr/bin/env python3
"""G15: the Bench "Create Opportunity & Quote" button is RETIRED, and stays retired.

🛑 THE DEFECT THIS PINS. Under rc49 the Accounts record view rendered the button
TWICE. The header's own text read:

    ADDISON WB I85L06 ... Distribution DIST
    Create Opportunity & Quote  Create Opportunity & Quote  Edit

🚩 WHY THE ORIGINAL GUARD COULD NOT SEE IT, which is the whole lesson. This class
refused to inject when a button named ``bd_create_opp_quote_button`` was already
present. ERP-Epicor's AccountsLayout ships one named
``erp_create_opp_quote_button`` - a DIFFERENT name carrying an IDENTICAL LABEL.
A guard keyed on what the CODE sees (the name) cannot see a duplicate keyed on
what the USER sees (the label), so both packages correctly concluded they were
the only one and both injected. ``test_the_two_packages_still_share_one_label``
below pins that collision so nobody "fixes" this by renaming a label and
quietly reopening the hole.

📌 WHY CORE'S SURVIVES AND THIS ONE GOES. 🔒 1044: the Bench layer is TWO fields,
``bd_customer_group{,_code}``, plus the connector code that writes them. And
core's ``AccountsErpActionsApi`` is the SUPERSET, not an equivalent - it owns
``DEFAULT_PLACEHOLDER_PART = 'ETO-PENDING'`` (the exact placeholder REQ-20's
oracle names), is tenant-configurable, and types the quote for Advanced Quote.
Keeping Bench's instead would show one button and LOSE all three.

🛑 SUPERSEDED IN 0.9.42-rc64 BY G276 / 🔒 1504 - THE RETIREMENT CODE IS GONE TOO.
Before rc64 ``BdAccountsLayoutExtensions::writeButtons()`` kept running on every
install to strip ``bd_create_opp_quote_button`` from the deployed view ("ONLY
OVERWRITING RETIRES"). The owner then ruled *"remove now all button logic from
bench"*, so the method is deleted and ``remove()`` no longer reads the buttons
array. Every tenant that ran rc49..rc61 already had the Bench button stripped
(Bench and et included), so nothing is left for it to do there. What this file
still pins: the Bench button is never INJECTED again, the two packages still
share one label (the root cause), the customer group fields are untouched, and
neither the install nor the uninstall path of this class touches a button.

🔁 0.9.42-rc70 (🔒 1724b): THE CLASS ITSELF IS GONE. BdAccountsLayoutExtensions
was this package's writer on the Accounts record view; the two customer-group
fields now carry ERP-Epicor's `erp_layout` marker and ERP-Core's
ErpLayoutExtraFields places them. So the guard is stronger than "this class
leaves buttons alone": NO shipped file of this package touches a record view -
no ViewdefManager, no saveViewdef, no `buttons` key - and so none can inject or
strip a button.
"""

import re
import unittest
from pathlib import Path

import shared_sugar

ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "sugar-sell/BenchDogs-Ext"
LAYOUT = PKG / "custom/modules/Accounts/BdAccountsLayoutExtensions.php"
BD_LABELS = PKG / "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_action_buttons.php"
#: ERP-Epicor lives in a sibling checkout; the label collision is only assertable
#: when it is present, so that one case skips rather than fails when it is not.
_EPICOR_REL = (
    "erp-integration-sugar/sugar-sell/ERP-Epicor/src/custom/Extension"
    "/modules/Accounts/Ext/Language/en_us.erp_create_opp_quote.php"
)


#: ERP-Epicor's label file, from the pin under fixtures/shared-sugar, so this
#: runs in CI instead of skipping. The old walk-up finder could not see the
#: sibling from a git worktree either.
EPICOR_LABELS = shared_sugar.resolve("en_us.erp_create_opp_quote.php")

BD_BUTTON = "bd_create_opp_quote_button"
ERP_BUTTON = "erp_create_opp_quote_button"

#: Anything a shipped file would need in order to read or write a record view's
#: buttons (or any of it). None may appear in this package since rc70.
VIEW_WRITERS = ("ViewdefManager", "saveViewdef", "loadViewdef", "'buttons'", "writeButtons",
                "'type' => 'bd-create-opp-quote'", "DeployedMetaDataImplementation")


def shipped_php():
    return sorted(p for top in ("custom", "scripts") for p in (PKG / top).rglob("*.php"))


def code_only(path) -> str:
    """PHP with comments stripped: the retirement NOTES name what is gone."""
    body = re.sub(r"/\*.*?\*/", "", path.read_text(encoding="utf-8"), flags=re.S)
    return re.sub(r"(?m)(^|\s)(//|#)[^\n]*", r"\1", body)


class CreateOppQuoteButtonRetiredStatic(unittest.TestCase):
    def test_the_layout_writer_is_gone(self):
        self.assertFalse(LAYOUT.exists(), "BdAccountsLayoutExtensions ships again (🔒 1724b)")

    def test_no_shipped_file_touches_a_record_view(self):
        """The shape that ADDED the button - and every shape that could - is gone:
        no shipped file loads, saves or edits a record view."""
        files = shipped_php()
        self.assertGreaterEqual(len(files), 8, "the scan reached too few files to mean anything")
        offenders = [f"{p.relative_to(PKG)}: {w}" for p in files for w in VIEW_WRITERS
                     if w in code_only(p)]
        self.assertEqual(offenders, [])

    def test_the_customer_group_fields_are_untouched(self):
        """🔒 1044: these two are the whole Bench Accounts layer. They are still
        declared, and still placed on the view - by their `erp_layout` marker."""
        src = (PKG / "custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php").read_text(
            encoding="utf-8")
        for field in ("bd_customer_group", "bd_customer_group_code"):
            self.assertIn(f"$dictionary['Account']['fields']['{field}']", src)
        self.assertEqual(src.count("'erp_layout' => array("), 2)

    def test_the_bench_label_is_gone_and_cores_is_not(self):
        """THE ROOT CAUSE, retired rather than merely pinned.

        The duplicate reached a seller because the two packages' buttons had
        DIFFERENT names and the SAME label, so a name-keyed dedupe guard could
        not see it. rc64 empties this package's label file (G276 / 🔒 1504): with
        no Bench button and no Bench button logic, a Bench label could only make
        a retired action read as supported. From rc69 (G280 / 🔒 1567) the file
        no longer ships at all and the one-off deletes the tenant's copy. Core
        keeps its own label, so the action the seller actually presses is still
        named.
        """
        from bd_retirement import assert_retired_by_oneoff
        assert_retired_by_oneoff(self, str(BD_LABELS.relative_to(PKG)),
                                 "the Bench button label; core's button owns this action")
        for php in PKG.rglob("*.php"):
            self.assertNotIn("LBL_BD_CREATE_OPP_QUOTE_BUTTON'] =", php.read_text(encoding="utf-8"),
                             f"{php.name} labels the retired Bench button again")
        erp = EPICOR_LABELS.read_text(encoding="utf-8")
        self.assertRegex(erp, r"\$mod_strings\['LBL_ERP_CREATE_OPP_QUOTE_BUTTON'\]\s*=\s*'[^']+'",
                         "core no longer labels its own Create Opportunity & Quote button")


if __name__ == "__main__":
    unittest.main()
