"""REQ-15 option (c) is CORE's now: ERP-Core's ErpAccountCountryGuard refuses an
Account billing country no Epicor country matches.

This package registers no guard (the hook fragment is an emptied stub) and, from
0.9.42-rc65, ships no guard class either (G280 / 🔒 1507). What is left to pin is
the PACKAGING: every path this package ever installed must keep shipping an empty
stub, because dropping a custom/Extension file from the build leaves the installed
copy live on a hosted tenant (§CW / G37).
"""

from pathlib import Path

import shared_sugar
import re
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "sugar-sell/BenchDogs-Ext"
GUARD = PKG / "custom/modules/Accounts/BdAccountCountryGuard.php"
HOOK = PKG / "custom/Extension/modules/Accounts/Ext/LogicHooks/bd_account_country_guard.php"
LANG = PKG / "custom/Extension/application/Ext/Language/_override_en_us.bd_country_lookup.php"

class AccountCountryGuardPackagingTest(unittest.TestCase):
    def test_the_hook_is_RETIRED_and_registers_nothing(self):
        """INVERTED 2026-09-19, not deleted.

        This used to assert the hook registered BdAccountCountryGuard. ERP-Core
        owns the billing-country guard now and its hook is registered and live
        (ErpAccountCountryGuard, 21 refs). Two guards on one field can disagree
        and the seller sees whichever ran last, so this package registers none.

        The assertion is INVERTED rather than removed because the file must keep
        SHIPPING as an emptied stub: §CW / G37 — on Sugar Cloud, dropping a
        custom/Extension file from the build leaves the installed copy in place
        and the package inert. Deleting this test would stop noticing if the
        stub itself were dropped.
        """
        hook = re.sub(r"/\*.*?\*/", "", HOOK.read_text(), flags=re.S)
        self.assertTrue(HOOK.exists(), "the stub must still ship, or the tenant keeps the old hook")
        self.assertNotIn("$hook_array", hook, "this package must register no hook")
        self.assertNotRegex(hook, r"\bclass\s+\w+")

    def test_the_guard_class_no_longer_ships_and_core_owns_the_check(self):
        """0.9.42-rc65, G280 / 🔒 1507. The class had no registration left - the
        hook stub above registers nothing - so it was 900 lines of unreachable
        Bench copy of a check ERP-Core performs (ErpAccountCountryGuard). It is
        DELETED rather than emptied because nothing can load it: a class file
        with no hook entry is never required. The stub that matters is the hook
        registration, asserted above, and it keeps shipping."""
        self.assertFalse(GUARD.exists(), f"{GUARD.name} is back; ERP-Core owns the billing-country guard")
        core = shared_sugar.resolve("ErpAccountCountryGuard.php")
        self.assertIn("class ErpAccountCountryGuard", core.read_text(encoding="utf-8", errors="replace"),
                      "core's guard is gone too - then nothing checks the billing country")

    def test_the_type_label_is_RETIRED_and_declares_nothing(self):
        """INVERTED 2026-09-20 (G50), not deleted.

        This used to assert the fragment shipped
        `$app_list_strings['erp_lookup_type_list']['bd_country']`. Nothing in
        this package reads those rows any more — the hook above registers no
        guard, and ERP-Core owns the billing-country check — so the label was
        the last thing making a retired lookup type look supported in the
        ERP_LookupValues list view, its filters and the report field chooser.

        The assertion is INVERTED rather than removed for the same reason as
        the hook's: the file must keep SHIPPING as an emptied stub (§CW / G37),
        and deleting this test would stop anyone noticing if the stub were
        dropped from the build — which leaves the label live on every tenant
        that has it.

        🚩 THIS PIN WAS THE BLOCKER. G50 was filed as "the code is gone, soft
        delete the 12 stale bd_country rows". The code was not gone: this
        fragment still shipped the label and this test still pinned it, so the
        rows could not be retired first without the next install republishing
        the name over them.
        """
        lang = re.sub(r"/\*.*?\*/", "", LANG.read_text(), flags=re.S)
        lang = re.sub(r"(?m)^\s*//.*$", "", lang)
        self.assertTrue(LANG.exists(), "the stub must still ship, or the tenant keeps the label")
        self.assertNotIn("$app_list_strings", lang, "this package must publish no lookup type label")
        self.assertEqual(lang.replace("<?php", "").strip(), "")

    def test_the_stub_keeps_the_exact_path_the_label_was_installed_at(self):
        # Overwriting is the ONLY thing that retires an installed
        # custom/Extension file, and a file only overwrites the copy at its own
        # path - so the name cannot drift, retired or not.
        #
        # The prefix also carried the original fix (rc23): Sugar 26.1 sorts
        # language fragments by is_override, then by an order-map mtime
        # refreshed only when a file's md5 changes, and ERP-Epicor's
        # accumulated whole-array erp_lookup_type_list kept wiping this key
        # until `_override*` put it last. `en_us` in the name is what joins
        # that merge at all.
        self.assertTrue(LANG.name.startswith("_override_"), LANG.name)
        self.assertIn("en_us", LANG.name)
        self.assertEqual(LANG.name, "_override_en_us.bd_country_lookup.php")

    def test_no_fragment_anywhere_republishes_the_bd_country_label(self):
        offenders = sorted(p.name for p in LANG.parent.glob("*.php")
                           if "bd_country" in re.sub(r"/\*.*?\*/", "", p.read_text(), flags=re.S))
        self.assertEqual(offenders, [], "the bd_country label is retired")


    def test_every_path_the_label_was_ever_installed_at_ships_an_empty_stub(self):
        """G50, 2026-09-21. Retiring the CURRENT path was not enough.

        Up to rc22 the label lived at en_us.bd_country_lookup.php; rc23 renamed
        it to the _override_ path and rc57 emptied only that one. The original
        copy was never overwritten, so it stayed on every tenant that had it,
        and once ERP-Core stopped assigning the whole list it published
        'Country (Bench Dogs)' again. Bench showed it live under rc58.

        Both paths must keep shipping, empty. Dropping either from the build
        hands the tenant's installed copy back.
        """
        for name in ("en_us.bd_country_lookup.php", "_override_en_us.bd_country_lookup.php"):
            path = LANG.parent / name
            self.assertTrue(path.exists(), f"{name} must ship as a stub, or the tenant keeps its copy")
            body = re.sub(r"/\*.*?\*/", "", path.read_text(), flags=re.S)
            body = re.sub(r"(?m)^\s*//.*$", "", body)
            self.assertEqual(body.replace("<?php", "").strip(), "", f"{name} must define nothing")


if __name__ == "__main__":
    unittest.main()
