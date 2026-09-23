"""How a file Bench Dogs used to ship leaves a tenant, now that the package does not ship it.

G280 / 🔒 1567 (0.9.42-rc69). Until rc68 the rule these tests encoded was "a
path the platform loads BY PATH must keep shipping, EMPTIED, or the tenant keeps
its copy" - Module Loader copies and never deletes (§CW / G37). That rule was
right, and it is still why a DROPPED path is not a retirement by itself.

🔒 1521 moved the retirement OUT of the shipped package and into the disposable
one-off sugar-sell/ONEOFF-RetireBdResidue, which deletes each retired
custom/Extension path through ModuleInstaller::uninstallExt() and blanks each
orphaned class file through copy_path() - from post_execute, with no copy list,
so its own uninstall cannot restore anything. It ran on every QA tenant (et
1.0.0 2026-09-22 22:22Z; stock and Ophir 1.0.1 2026-09-23 00:58Z; failed 0
everywhere; G234 CLOSED), so rc69 stops shipping the stubs.

So "retired" now means three things, and `assert_retired_by_oneoff` checks all
three, because any one alone is a claim about the repo rather than the tenant:

  1. the path is not in the package source,
  2. it is not in the BUILT zip (what the loader is actually handed),
  3. the one-off's own worklist still names it - i.e. a tenant that somehow
     still carries it (an upgrade from an older build, or rc68's re-copy on a
     tenant that took rc68 after the one-off ran) has a route off it.

The worklist is read out of the one-off's post_execute.php itself, not restated
here, so this cannot agree with a list the one-off does not actually run.
"""
from __future__ import annotations

import os
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PKG = Path(os.environ.get("BD_PKG", ROOT / "sugar-sell/BenchDogs-Ext"))
ONEOFF_POST_EXECUTE = ROOT / "sugar-sell/ONEOFF-RetireBdResidue/scripts/post_execute.php"

_ENTRY = re.compile(r"array\('to_module'\s*=>\s*'([^']+)',\s*'name'\s*=>\s*'([^']+)'\)")
_GROUP = re.compile(r"^\s*'([^']+)'\s*=>\s*array\(\s*$")


def _block(source: str, opener: str) -> str:
    start = source.index(opener)
    end = source.index("\n);", start)
    return source[start:end]


def oneoff_worklist() -> dict[str, str]:
    """Every tenant path the one-off removes, mapped to how: 'deleted' or 'blanked'."""
    source = ONEOFF_POST_EXECUTE.read_text(encoding="utf-8")
    out: dict[str, str] = {}

    group = None
    for line in _block(source, "$bdExtensionGroups = array(").splitlines():
        header = _GROUP.match(line)
        if header:
            group = header.group(1)
            continue
        for module, name in _ENTRY.findall(line):
            if module == "application":
                path = f"custom/Extension/application/Ext/{group}/{name}.php"
            else:
                path = f"custom/Extension/modules/{module}/Ext/{group}/{name}.php"
            out[path] = "deleted"

    for path in re.findall(r"'(custom/[^']+\.php)'", _block(source, "$bdOrphanClasses = array(")):
        out[path] = "blanked"
    return out


def built_zip() -> Path:
    version = (PKG / "version").read_text().strip()
    return PKG / "releases" / f"sugarai_benchdogs_ext-{version}.zip"


def zip_names() -> set[str]:
    with zipfile.ZipFile(built_zip()) as zipped:
        return set(zipped.namelist())


def assert_retired_by_oneoff(test, rel: str, why: str = "") -> None:
    """The three halves of a retirement under 🔒 1521 - see the module docstring."""
    suffix = f" - {why}" if why else ""
    test.assertFalse((PKG / rel).exists(), f"{rel} ships again{suffix}")
    test.assertNotIn(rel, zip_names(), f"{rel} is in the built zip{suffix}")
    test.assertIn(rel, oneoff_worklist(),
                  f"{rel} is no longer shipped AND the one-off does not remove it, so a "
                  f"tenant that has it keeps it{suffix}")
