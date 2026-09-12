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

            self.assertIn(
                "custom/modules/bd01_ERP_Quote_Line/BdQuoteLineRefreshHook.php",
                archive.namelist(),
            )
            self.assertIn(
                "custom/Extension/modules/bd01_ERP_Quote_Line/Ext/LogicHooks/"
                "bd_quote_line_refresh.php",
                archive.namelist(),
            )

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
        previous = PACKAGE / "releases/sugarai_benchdogs_ext-0.9.42-rc9.zip"
        version = (PACKAGE / "version").read_text().strip()
        candidate = PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        retired = {
            "custom/Extension/modules/RevenueLineItems/Ext/Vardefs/"
            "bd_deliverable_key.php",
            "custom/Extension/modules/bd01_ERP_Quote_Line/Ext/LogicHooks/"
            "bd_rli_refresh.php",
            "custom/modules/bd01_ERP_Quote_Line/BdRliRefreshHook.php",
        }
        with zipfile.ZipFile(previous) as old, zipfile.ZipFile(candidate) as new:
            self.assertTrue(retired.issubset(set(old.namelist())))
            self.assertTrue(retired.isdisjoint(set(new.namelist())))
            self.assertNotIn(
                "custom/Extension/application/Ext/DropdownsStyle/"
                "sales_stage_dom_style.php",
                old.namelist(),
            )
            self.assertIn(
                "custom/Extension/application/Ext/DropdownsStyle/"
                "sales_stage_dom_style.php",
                new.namelist(),
            )


if __name__ == "__main__":
    unittest.main()
