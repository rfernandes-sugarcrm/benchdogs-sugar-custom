"""REQ-15 option (c) is CORE's now: ERP-Core's ErpAccountCountryGuard refuses an
Account billing country no Epicor country matches.

This package registers no guard and, from 0.9.42-rc65, ships no guard class
either (G280 / 🔒 1507). What is left to pin is the RETIREMENT: every path this
package ever installed the guard or its label at must be off the tenant.

Until rc68 that meant "keep shipping an empty stub", because dropping a
custom/Extension file from the build leaves the installed copy live on a hosted
tenant (§CW / G37). From rc69 (G280 / 🔒 1567, 🔒 1521) the stubs are gone from
the package and the one-off ONEOFF-RetireBdResidue deletes those paths instead;
see bd_retirement.assert_retired_by_oneoff for the three halves checked.
"""

from pathlib import Path

import shared_sugar
from bd_retirement import assert_retired_by_oneoff, oneoff_worklist
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
        """INVERTED 2026-09-19; re-pointed at the one-off 0.9.42-rc69.

        This used to assert the hook registered BdAccountCountryGuard. ERP-Core
        owns the billing-country guard (ErpAccountCountryGuard), and two guards
        on one field can disagree with the seller seeing whichever ran last, so
        this package registers none. Through rc68 that was an emptied stub the
        package kept shipping; from rc69 the one-off deletes the path.
        """
        assert_retired_by_oneoff(self, str(HOOK.relative_to(PKG)),
                                 "the tenant would keep the old guard registration")

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
        """INVERTED 2026-09-20 (G50); re-pointed at the one-off 0.9.42-rc69.

        This used to assert the fragment shipped
        `$app_list_strings['erp_lookup_type_list']['bd_country']`. Nothing reads
        those rows any more, so the label was the last thing making a retired
        lookup type look supported in the ERP_LookupValues list view, its
        filters and the report field chooser.

        🚩 THIS PIN WAS ONCE THE BLOCKER. G50 was filed as "the code is gone".
        It was not: the fragment still shipped the label and this test pinned
        it. The retirement is what has to be asserted, not the absence of a
        string in a file that may not exist.
        """
        assert_retired_by_oneoff(self, str(LANG.relative_to(PKG)),
                                 "the tenant would keep 'Country (Bench Dogs)'")

    def test_the_retirement_names_the_exact_path_the_label_was_installed_at(self):
        # A removal only reaches the copy at its own path - so the name cannot
        # drift, retired or not. The prefix carried the original fix (rc23):
        # Sugar 26.1 sorts language fragments by is_override, then by an
        # order-map mtime refreshed only when a file's md5 changes, and
        # ERP-Epicor's accumulated whole-array erp_lookup_type_list kept wiping
        # this key until `_override*` put it last.
        self.assertEqual(LANG.name, "_override_en_us.bd_country_lookup.php")
        self.assertIn(str(LANG.relative_to(PKG)), oneoff_worklist())

    def test_no_fragment_anywhere_republishes_the_bd_country_label(self):
        offenders = sorted(str(p.relative_to(PKG)) for p in PKG.rglob("*.php")
                           if "bd_country" in re.sub(r"/\*.*?\*/", "", p.read_text(), flags=re.S))
        self.assertEqual(offenders, [], "the bd_country label is retired")


    def test_every_path_the_label_was_ever_installed_at_is_retired(self):
        """G50, 2026-09-21. Retiring the CURRENT path was not enough.

        Up to rc22 the label lived at en_us.bd_country_lookup.php; rc23 renamed
        it to the _override_ path and rc57 emptied only that one. The original
        copy was never overwritten, so it stayed on every tenant that had it,
        and once ERP-Core stopped assigning the whole list it published
        'Country (Bench Dogs)' again. Bench showed it live under rc58.

        BOTH paths must be off the tenant - from rc69, by the one-off.
        """
        for name in ("en_us.bd_country_lookup.php", "_override_en_us.bd_country_lookup.php"):
            with self.subTest(name=name):
                assert_retired_by_oneoff(self, f"custom/Extension/application/Ext/Language/{name}",
                                         "the tenant keeps its copy of the label")


if __name__ == "__main__":
    unittest.main()
