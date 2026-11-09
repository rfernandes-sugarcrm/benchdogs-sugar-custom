"""Retirement readers own a build independent of release ZIP writers."""

import shutil
import subprocess
import unittest
import zipfile
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch

import bd_retirement


class RetirementBuildIsolationTest(unittest.TestCase):
    """Exercise cold, concurrent, stale and failed real builds."""

    def setUp(self) -> None:
        # unittest owns this context through the end of each test.
        # pylint: disable-next=consider-using-with
        scratch = self.enterContext(TemporaryDirectory(prefix="g590-"))
        self.package = Path(scratch) / "package"
        shutil.copytree(
            bd_retirement.PKG,
            self.package,
            ignore=shutil.ignore_patterns("releases"),
        )
        version = (self.package / "version").read_text().strip()
        self.shared = (
            self.package / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        )
        self.enterContext(patch.object(bd_retirement, "PKG", self.package))

    def build_shared(self) -> None:
        """Seed the artifact the competing tests use."""
        subprocess.run(
            ["php", "pack.php"],
            cwd=self.package,
            capture_output=True,
            text=True,
            check=True,
            env=bd_retirement.scratch_build_env(),
        )

    def test_cold_reader_builds_current_source_without_writing_releases(
        self,
    ) -> None:
        """A fresh checkout builds actual source into an owned ZIP."""
        archive = bd_retirement.built_zip()
        with zipfile.ZipFile(archive) as zipped:
            self.assertIn("manifest.php", zipped.namelist())
            for source in self.package.joinpath("custom").rglob("*.php"):
                self.assertEqual(
                    zipped.read(source.relative_to(self.package).as_posix()),
                    source.read_bytes(),
                )
        self.assertFalse(self.shared.exists())
        self.assertEqual(archive, bd_retirement.built_zip())

    def test_reader_ignores_a_replaced_shared_archive(self) -> None:
        """Replacing the public release cannot alter a reader's artifact."""
        self.build_shared()
        before = bd_retirement.zip_names()
        with zipfile.ZipFile(self.shared, "w") as archive:
            archive.writestr("unrelated-build.txt", "a different build")
        self.assertEqual(bd_retirement.zip_names(), before)

    def test_stale_release_cannot_hide_changed_source(self) -> None:
        """An old release must not substitute for current source."""
        self.build_shared()
        source = next(self.package.joinpath("custom").rglob("*.php"))
        source.write_bytes(source.read_bytes() + b"\n// G590 changed source\n")
        with zipfile.ZipFile(bd_retirement.built_zip()) as archive:
            self.assertEqual(
                archive.read(source.relative_to(self.package).as_posix()),
                source.read_bytes(),
            )

    def test_broken_builder_fails_even_when_a_shared_zip_exists(self) -> None:
        """Build errors cannot be hidden behind a previously successful ZIP."""
        self.build_shared()
        (self.package / "pack.php").write_text("<?php exit(42);\n")
        with self.assertRaises(subprocess.CalledProcessError):
            bd_retirement.zip_names()


if __name__ == "__main__":
    unittest.main()
