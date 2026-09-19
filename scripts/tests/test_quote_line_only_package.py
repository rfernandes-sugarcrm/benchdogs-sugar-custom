"""The Bench package has one permanent sales model: native Quote lines."""

from pathlib import Path
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"


class QuoteLineOnlyPackageTest(unittest.TestCase):
    FORBIDDEN = (
        "RevenueLineItems",
        "revenuelineitems",
        "BdRli",
        "bd_rli",
    )

    def test_shipped_php_has_no_opportunity_line_item_module_path_or_runtime_access(self):
        for path in PACKAGE.rglob("*.php"):
            if "releases" in path.parts:
                continue
            source = path.read_text(encoding="utf-8")
            for token in self.FORBIDDEN:
                with self.subTest(path=path.relative_to(PACKAGE), token=token):
                    self.assertNotIn(token, source)

    def test_built_archive_has_no_obsolete_module_or_hook(self):
        version = (PACKAGE / "version").read_text().strip()
        archive_path = PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        with zipfile.ZipFile(archive_path) as archive:
            for name in archive.namelist():
                for token in self.FORBIDDEN:
                    with self.subTest(name=name, token=token):
                        self.assertNotIn(token, name)
                if not name.endswith(".php"):
                    continue
                source = archive.read(name).decode("utf-8", errors="replace")
                for token in self.FORBIDDEN:
                    with self.subTest(name=name, token=token):
                        self.assertNotIn(token, source)

            # 🛑 THESE TWO PATHS USED TO BE assertIn. They were the refresh hook
            # of the bd01_* quote-line mirror, which decisions 901/903 retired
            # outright - so the package asserting it SHIPS them would now be
            # asserting the retirement did not happen. They are pinned as absent
            # instead, which is the same guard pointing the other way.
            for gone in (
                "custom/modules/bd01_ERP_Quote_Line/BdQuoteLineRefreshHook.php",
                "custom/Extension/modules/bd01_ERP_Quote_Line/Ext/LogicHooks/"
                "bd_quote_line_refresh.php",
            ):
                self.assertNotIn(gone, archive.namelist())

    def test_package_is_uninstallable_for_clean_upgrade_and_rollback(self):
        version = (PACKAGE / "version").read_text().strip()
        archive_path = PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        with zipfile.ZipFile(archive_path) as archive:
            manifest = archive.read("manifest.php").decode("utf-8")
        self.assertRegex(manifest, r"'is_uninstallable'\s*=>\s*true")
        self.assertRegex(manifest, r"'remove_tables'\s*=>\s*'prompt'")
        self.assertIn("<basepath>/scripts/pre_uninstall.php", manifest)
        self.assertIn("<basepath>/scripts/post_uninstall.php", manifest)

    def test_clean_install_boundary_removes_paths_an_in_place_upgrade_would_leave(self):
        """🛑 THE "BEFORE" CONTROL WAS DROPPED, DELIBERATELY AND WITH A COST.

        This compared the candidate against the 0.9.42-rc9 archive to prove BOTH
        that rc9 shipped the retired RevenueLineItems/BdRli paths AND that the
        candidate does not. `releases/` is gitignored - CI rebuilds from source
        on every run - so rc9 has never existed in a fresh checkout and this test
        could not pass anywhere. It was failing for that reason, not because the
        package regressed.

        Rebuilding rc9 would mean checking out a historical commit, so the old
        side is gone and only the candidate is asserted. WHAT IS LOST: if these
        paths had never shipped at all, this test would now pass vacuously. It is
        kept rather than deleted because the forward half - the shipped package
        must not carry them - is the half that can still regress. It is NOT
        skipped: a skip on an archive that can never be present is a test that
        never runs.
        """
        version = (PACKAGE / "version").read_text().strip()
        candidate = PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        retired = {
            "custom/Extension/modules/RevenueLineItems/Ext/Vardefs/"
            "bd_deliverable_key.php",
            "custom/Extension/modules/bd01_ERP_Quote_Line/Ext/LogicHooks/"
            "bd_rli_refresh.php",
            "custom/modules/bd01_ERP_Quote_Line/BdRliRefreshHook.php",
        }
        with zipfile.ZipFile(candidate) as new:
            names = set(new.namelist())
            self.assertTrue(retired.isdisjoint(names), retired & names)
            self.assertIn(
                "custom/Extension/application/Ext/DropdownsStyle/"
                "sales_stage_dom_style.php",
                names,
            )


if __name__ == "__main__":
    unittest.main()
