#!/usr/bin/env python3
"""Assert every built package is something Module Loader can actually accept.

These are the checks that only mean anything against a finished zip, and each
one corresponds to a way an upload fails without telling you much:

  manifest at the root   PackageZipFile::getPackageManifestFile reads only the
                         top-level manifest.php. One directory down and the
                         upload fails with NoPackageManifestFileException.

  version agreement      pack.php stamps the manifest from the package's
                         `version` file. When they disagree - and
                         buildPackages.sh's --bump-version bumps AFTER the
                         build, so they disagree easily - the zip carries a
                         version equal to what is already installed, and
                         Module Loader treats it as not an upgrade and
                         declines with no useful message. This repo lost a
                         deploy cycle to exactly that.

  id present             `name` or installdefs['id'] must exist or the
                         manifest throws before anything installs.

  no nested archive      A zip inside the zip is almost always an accident of
                         a build run twice into the same releases directory.

Run from the repository root:

    python3 scripts/check_built_packages.py [area ...]
"""

from __future__ import annotations

import re
import sys
import zipfile
from pathlib import Path

AREAS = ("sugar-sell", "sugar-predict", "sugar-market", "sugar-discover")


def manifest_value(text: str, key: str) -> str | None:
    m = re.search(r"['\"]" + key + r"['\"]\s*=>\s*['\"]([^'\"]*)['\"]", text)
    return m.group(1) if m else None


def check_zip(path: Path, expected_version: str | None) -> list[str]:
    problems: list[str] = []
    with zipfile.ZipFile(path) as zf:
        names = zf.namelist()

        if "manifest.php" not in names:
            nested = [n for n in names if n.endswith("/manifest.php")]
            where = f" (found one at {nested[0]})" if nested else ""
            problems.append(
                "no manifest.php at the archive root, so the upload fails "
                "outright" + where
            )
            return problems

        text = zf.read("manifest.php").decode("utf-8", errors="replace")

        version = manifest_value(text, "version")
        if not version:
            problems.append("manifest declares no version")
        elif expected_version and version != expected_version:
            problems.append(
                f"manifest says version {version} but the package's version "
                f"file says {expected_version}; Module Loader would see a "
                f"version that is not an upgrade"
            )

        if not manifest_value(text, "name") and not manifest_value(text, "id"):
            problems.append("manifest has neither a name nor an installdefs id")

        if not manifest_value(text, "type"):
            problems.append("manifest declares no type")

        if "acceptable_sugar_versions" not in text:
            problems.append("manifest declares no acceptable_sugar_versions")

        nested_zips = [n for n in names if n.lower().endswith(".zip")]
        if nested_zips:
            problems.append(f"contains a nested archive: {nested_zips[0]}")

    return problems


def main(argv: list[str]) -> int:
    repo = Path(__file__).resolve().parent.parent
    areas = argv or list(AREAS)

    checked = 0
    failures: list[tuple[str, str]] = []

    for area in areas:
        base = repo / area
        if not base.is_dir():
            continue
        for pkg in sorted(base.iterdir()):
            releases = pkg / "releases"
            if not releases.is_dir():
                continue
            version_file = pkg / "version"
            expected = (
                version_file.read_text(encoding="utf-8").strip()
                if version_file.is_file() else None
            )
            for zip_path in sorted(releases.glob("*.zip")):
                checked += 1
                rel = zip_path.relative_to(repo)
                problems = check_zip(zip_path, expected)
                if problems:
                    for p in problems:
                        failures.append((str(rel), p))
                    print(f"FAIL  {rel}")
                    for p in problems:
                        print(f"        {p}")
                else:
                    print(f"ok    {rel}  (version {expected})")

    if checked == 0:
        print("No built packages found. Did the build step run?", file=sys.stderr)
        return 2

    print(f"\n{checked} artifact(s) checked, {len(failures)} problem(s).")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
