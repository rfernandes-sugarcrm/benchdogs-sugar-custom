"""The ERP-Epicor 1.1 copy of ErpQuoteFacts is the real file, byte for byte.

bd_adm_rules_test.php's first run (test_php_suites.py) proves BdAdmRules keeps
the ADM quote defaults working on a tenant whose ERP-Epicor predates 1.2.0: the
GLOBAL ErpQuoteFacts, included by its literal path. 1.2.0 (erp-integration-sugar
280e0929) no longer ships that file, so it cannot be a shared-sugar pin; the
copy is held to fixtures/erp-epicor-1.1/PROVENANCE.json instead - its sha256
always (CI included), and the named commit's own object wherever the sibling
checkout has it. A hand edit to the copy, or a copy of the wrong file, fails
here. Its 1.2.0 twin (the namespaced class) is shared-sugar's, drift-checked.
"""
from __future__ import annotations

import hashlib
import json
import pathlib
import shutil
import subprocess
import unittest

import shared_sugar

FIX = pathlib.Path(__file__).resolve().parent / "fixtures/erp-epicor-1.1"
PROVENANCE = json.loads((FIX / "PROVENANCE.json").read_text())
GLOBAL_FACTS = FIX / "custom/modules/Quotes/ErpQuoteFacts.php"


class TheOneOneCopyIsTheRealClass(unittest.TestCase):
    def test_each_copy_matches_its_provenance(self):
        for name, meta in PROVENANCE["files"].items():
            with self.subTest(file=name):
                data = (FIX / name).read_bytes()
                self.assertEqual(hashlib.sha256(data).hexdigest(), meta["sha256"])
                self.assertEqual(len(data), meta["bytes"])

    def test_the_copy_is_the_global_class_at_its_literal_path(self):
        """No namespace, `class ErpQuoteFacts`, at custom/modules/Quotes/ - the
        file and class BdAdmRules's literal include reaches on a 1.1 tenant.
        The three methods BdAdmRules calls are all there."""
        text = GLOBAL_FACTS.read_text()
        self.assertNotIn("\nnamespace ", text)
        self.assertIn("\nclass ErpQuoteFacts\n", text)
        for method in ("companyCode", "productGroup", "referenceMaxLength"):
            with self.subTest(method=method):
                self.assertIn(f"public static function {method}(", text)

    def test_the_1_2_pin_is_the_namespaced_twin(self):
        """The other side of the version boundary: shared-sugar's ErpQuoteFacts
        is 1.2.0's, namespaced at the PSR-4 path Sugar autoloads. If a re-pin
        ever brought the global class back there, the namespaced run would be
        testing the wrong version."""
        text = shared_sugar.pinned_path("ErpQuoteFacts.php").read_text()
        self.assertIn("\nnamespace Sugarcrm\\Sugarcrm\\custom\\Erp;\n", text)
        self.assertIn("\nclass ErpQuoteFacts\n", text)
        self.assertTrue(shared_sugar.SOURCES["ErpQuoteFacts.php"].endswith(
            "/src/custom/src/Erp/ErpQuoteFacts.php"))

    def test_each_copy_matches_its_source_commit_where_reachable(self):
        """Loud wherever the commit is reachable. In CI (no sibling, no git in
        the php:8.2 image) and in a sibling that has not fetched the commit,
        this passes and the sha256 case above is what holds the copy - the g280
        pins' rule (test_governing_contribution), so it spends none of CI's
        skip ceiling."""
        git = shutil.which("git")
        if git is None or not shared_sugar.SIBLING.is_dir():
            return
        for name, meta in PROVENANCE["files"].items():
            shown = subprocess.run(
                [git, "-C", str(shared_sugar.SIBLING), "show", f"{meta['commit']}:{meta['source']}"],
                capture_output=True)
            if shown.returncode != 0:
                continue
            with self.subTest(file=name):
                self.assertEqual(shown.stdout, (FIX / name).read_bytes(), f"{name} differs from {meta['commit']}")


if __name__ == "__main__":
    unittest.main()
