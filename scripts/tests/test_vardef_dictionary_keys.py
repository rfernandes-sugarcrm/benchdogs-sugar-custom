#!/usr/bin/env python3
"""A vardef extension is filed under the module's OBJECT name, never its module name.

Sugar merges custom/Extension/modules/<Module>/Ext/Vardefs/*.php into
$dictionary[<object_name>] and silently ignores a key it does not know: rc129 and
rc130 shipped the ERP_OrderLines category fields under $dictionary['ERP_OrderLines']
(the module name) while ERP-Core's bean is ERP_OrderLine, so the fields installed
cleanly and never loaded.

The table is hard-asserted (not read from an ERP-Core checkout) so the test stays
hermetic. ERP-Core's dictionary keys are ERP_Order and ERP_OrderLine (vardefs.php
and the bean's $object_name); Sugar core's are the singular beans below. A module
this package starts extending must be added here with its object name.
"""
import re
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2] / "sugar-sell" / "BenchDogs-Ext" / "custom" / "Extension" / "modules"

OBJECT_NAMES = {
    "Accounts": {"Account"},
    "Opportunities": {"Opportunity"},
    "Products": {"Product"},
    "Quotes": {"Quote"},
    "ERP_Orders": {"ERP_Order"},
    "ERP_OrderLines": {"ERP_OrderLine"},
    "ERP_LookupValues": {"ERP_LookupValue"},
    "ERP_Companies": {"ERP_Company"},
    "ProductCategories": {"ProductCategory"},
}

KEY = re.compile(r"\$dictionary\[\s*['\"]([A-Za-z_]+)['\"]\s*\]")


class VardefDictionaryKeys(unittest.TestCase):
    def test_every_vardef_uses_its_modules_object_name(self):
        files = sorted(ROOT.glob("*/Ext/Vardefs/*.php"))
        self.assertTrue(files, "no vardef extension files found: the glob is vacuous")
        for f in files:
            module = f.relative_to(ROOT).parts[0]
            self.assertIn(module, OBJECT_NAMES, f"{module}: add its object name to OBJECT_NAMES")
            keys = set(KEY.findall(f.read_text()))
            self.assertTrue(keys, f"{f.name} writes no $dictionary key")
            self.assertLessEqual(keys, OBJECT_NAMES[module],
                                 f"{f.name}: {sorted(keys - OBJECT_NAMES[module])} is not an object name of {module}")


if __name__ == "__main__":
    sys.exit(unittest.main())
