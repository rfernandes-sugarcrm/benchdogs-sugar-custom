"""How a file Bench Dogs used to ship is held off the package, now that the package does not ship it.

G280 / 🔒 1567 (0.9.42-rc69). Until rc68 the rule these tests encoded was "a
path the platform loads BY PATH must keep shipping, EMPTIED, or the tenant keeps
its copy" - Module Loader copies and never deletes (§CW / G37). That rule was
right, and it is still why a DROPPED path is not a retirement by itself.

🔒 1521 moved the retirement OUT of the shipped package and into the disposable
one-off sugar-sell/ONEOFF-RetireBdResidue, and rc69 stopped shipping the stubs.
Through 2026-09-30 a third check here held every retired path on that one-off's
worklist, as the tenant's route off it.

🔒2173b (owner, 2026-09-30: "remove all the one offs I dont want that code")
WITHDREW every one-off and deleted its code, here and upstream
(erp-integration-sugar #172, whose MLP023 now refuses any ONEOFF-* package). So
there is no route off a tenant any more: a tenant that still carries a retired
path keeps it, because Module Loader never deletes a file a later build stops
shipping. That is the accepted state under the ruling, not a gap this file
closes.

So "retired" now means two things, and `assert_not_shipped` checks both, because
the source alone is a claim about the repo rather than about what the loader is
handed:

  1. the path is not in the package source,
  2. it is not in the BUILT zip (what the loader is actually handed).
"""
from __future__ import annotations

import os
import shutil
import subprocess
import zipfile
from functools import cache
from pathlib import Path
from tempfile import TemporaryDirectory

ROOT = Path(__file__).resolve().parents[2]
PKG = Path(os.environ.get("BD_PKG", ROOT / "sugar-sell/BenchDogs-Ext"))


@cache
def _private_build(package: Path) -> tuple[TemporaryDirectory[str], Path]:
    """Keep one real build per source tree alive for this test process.

    Other packaging tests delete/rebuild the checkout's release ZIP. Copy only
    source, never a previous release, so readers cannot race those writers or
    silently accept an old ZIP when the current builder is broken. Retaining
    the TemporaryDirectory with the cached path owns its lifetime and cleanup.
    """
    # A with block would delete the build before its callers can read it.
    # pylint: disable-next=consider-using-with
    scratch = TemporaryDirectory(prefix="bd-retirement-")
    try:
        source = Path(scratch.name) / "package"
        shutil.copytree(
            package, source, ignore=shutil.ignore_patterns("releases")
        )
        subprocess.run(
            ["php", "pack.php"],
            cwd=source,
            capture_output=True,
            text=True,
            check=True,
        )
        version = (source / "version").read_text().strip()
        archive = source / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        if not archive.is_file():
            raise AssertionError("the package builder did not produce a ZIP")
        return scratch, archive
    except BaseException:
        scratch.cleanup()
        raise


def built_zip() -> Path:
    """Return the retirement readers' private, process-owned package ZIP."""
    return _private_build(PKG.resolve())[1]


def zip_names() -> set[str]:
    """Read the members from the private build."""
    with zipfile.ZipFile(built_zip()) as zipped:
        return set(zipped.namelist())


def assert_not_shipped(test, rel: str, why: str = "") -> None:
    """The two halves of a retirement since 🔒2173b - see the module docstring."""
    suffix = f" - {why}" if why else ""
    test.assertFalse((PKG / rel).exists(), f"{rel} ships again{suffix}")
    test.assertNotIn(rel, zip_names(), f"{rel} is in the built zip{suffix}")
