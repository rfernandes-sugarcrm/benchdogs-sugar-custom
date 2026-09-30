"""The T2 copy of ERP-Epicor's ErpQuoteFacts is the real file, byte for byte.

bd_adm_rules_test.php's third run (test_php_suites.py) proves BdAdmRules keeps
the ADM quote defaults working once ERP-Epicor's classes move to custom/src
under Sugarcrm\\Sugarcrm\\custom\\Erp (the Rafael review's T2,
erp-integration-sugar #158). That run is only as good as the class it loads, so
the copy is held to fixtures/erp-t2/PROVENANCE.json: its sha256 always (CI
included), and the named commit's own object wherever the sibling checkout has
it. A hand edit to the copy, or a copy of the wrong file, fails here.
"""
from __future__ import annotations

import hashlib
import json
import pathlib
import shutil
import subprocess
import unittest

import shared_sugar

FIX = pathlib.Path(__file__).resolve().parent / "fixtures/erp-t2"
PROVENANCE = json.loads((FIX / "PROVENANCE.json").read_text())


class TheT2CopyIsTheRealClass(unittest.TestCase):
    def test_each_copy_matches_its_provenance(self):
        for name, meta in PROVENANCE["files"].items():
            with self.subTest(file=name):
                data = (FIX / name).read_bytes()
                self.assertEqual(hashlib.sha256(data).hexdigest(), meta["sha256"])
                self.assertEqual(len(data), meta["bytes"])

    def test_the_copy_is_the_namespaced_class_where_sugar_autoloads_it(self):
        """custom/src/Erp/ErpQuoteFacts.php declaring
        Sugarcrm\\Sugarcrm\\custom\\Erp\\ErpQuoteFacts: the PSR-4 pair Sugar's
        autoloader resolves, and the one the test's autoloader mirrors."""
        text = (FIX / "custom/src/Erp/ErpQuoteFacts.php").read_text()
        self.assertIn("\nnamespace Sugarcrm\\Sugarcrm\\custom\\Erp;\n", text)
        self.assertIn("\nclass ErpQuoteFacts\n", text)

    def test_each_copy_matches_its_source_commit_where_reachable(self):
        """Loud wherever the commit is reachable. In CI (no sibling, no git in
        the php:8.2 image) and in a sibling that has not fetched
        refactor/rafael-review, this passes and the sha256 case above is what
        holds the copy - the g280 pins' rule (test_governing_contribution), so
        it spends none of CI's skip ceiling."""
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
