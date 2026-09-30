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
  every member installs  A zip member that no installdefs entry reaches is
                         shipped to every tenant and never written to the
                         instance: dead weight at best, and at worst a file a
                         reader cites as "the shipped copy" (G665: 27 such
                         files rode in every ERP-Epicor build — 16 duplicates
                         of the layoutdefs and 11 wireless layouts nothing
                         referenced). Evaluated by handing the real manifest
                         to php, because installdefs is a PHP array and a regex
                         over it is guesswork; without php the check FAILS by
                         name rather than passing on nothing.

Run from the repository root:

    python3 scripts/check_built_packages.py [area ...]
"""

from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

AREAS = ("sugar-sell", "sugar-predict", "sugar-market", "sugar-discover")


def manifest_value(text: str, key: str) -> str | None:
    m = re.search(r"['\"]" + key + r"['\"]\s*=>\s*['\"]([^'\"]*)['\"]", text)
    return m.group(1) if m else None


#: installdefs keys whose entries name a zip path that Module Loader copies
#: somewhere (`from`/`meta_data`/`file`/`path`), or a bare path string.
#: `beans` names the INSTALLED module path, which the `copy` entry already
#: covers, so it is deliberately absent.
_PATH_KEYS = (
    "copy", "relationships", "vardefs", "layoutdefs", "language", "sidecar",
    "platforms", "custom_fields", "dashlets", "menu", "user_page", "dcaction",
    "administration", "connectors", "scheduledefs", "layoutfields",
    "wireless_modules", "wireless_subpanels", "image_dir",
)
_SCRIPT_KEYS = (
    "pre_execute", "post_execute", "pre_install", "post_install",
    "pre_uninstall", "post_uninstall",
)
_PHP_DUMP_INSTALLDEFS = (
    "$z = new ZipArchive(); if ($z->open($argv[1]) !== true) { exit(3); }"
    " $src = $z->getFromName('manifest.php'); $z->close();"
    " $manifest = null; $installdefs = null; eval('?>' . $src);"
    " echo json_encode(is_array($installdefs) ? $installdefs : []);"
)


def installdefs_of(zip_path: Path) -> dict | str:
    """The zip's real installdefs, evaluated by php — or a string saying why
    that was not possible (no php, a manifest php cannot parse)."""
    php = shutil.which("php")
    if not php:
        return "php is not on PATH, so installdefs cannot be evaluated"
    r = subprocess.run(
        [php, "-d", "display_errors=stderr", "-r", _PHP_DUMP_INSTALLDEFS, "--", str(zip_path)],
        capture_output=True, text=True, timeout=60,
    )
    if r.returncode != 0 or not r.stdout.strip():
        return f"php could not evaluate manifest.php: {(r.stderr or r.stdout).strip()[-300:]}"
    try:
        value = json.loads(r.stdout)
    except ValueError as exc:
        return f"php printed unparseable installdefs: {exc}"
    return value if isinstance(value, dict) else {}


def _strip_basepath(value: object) -> str | None:
    if not isinstance(value, str):
        return None
    return re.sub(r"^<basepath>/", "", value).strip("/")


def installed_prefixes(installdefs: dict) -> list[str]:
    """Every zip path (file or directory prefix) some installdefs entry
    writes to the instance."""
    out: list[str] = []
    for key in _PATH_KEYS:
        value = installdefs.get(key)
        if value is None:
            continue
        if key == "image_dir":
            p = _strip_basepath(value)
            if p:
                out.append(p)
            continue
        entries = value if isinstance(value, list) else list(value.values()) if isinstance(value, dict) else [value]
        for entry in entries:
            if isinstance(entry, dict):
                for fk in ("from", "meta_data", "file", "path"):
                    p = _strip_basepath(entry.get(fk))
                    if p:
                        out.append(p)
            else:
                p = _strip_basepath(entry)
                if p:
                    out.append(p)
    for entry in installdefs.get("logic_hooks") or []:
        if isinstance(entry, dict) and isinstance(entry.get("file"), str):
            out.append(entry["file"].strip("/"))
    for key in _SCRIPT_KEYS:
        value = installdefs.get(key)
        if value is None:
            continue
        for entry in (value if isinstance(value, list) else [value]):
            p = _strip_basepath(entry)
            if p:
                out.append(p)
    return out


#: The four install scripts Module Loader runs BY NAME, with no installdefs
#: entry at all: PackageZipFile::runPackageScript includes each of these from
#: the zip when it exists (src/PackageManager/File/PackageZipFile.php:42-64,
#: Sugar 26.1.0; PackageManager.php:731/751/825/835). A package that ships one
#: and declares no key for it is the CORRECT shape (G666 removed the keys
#: that only looked like the mechanism), so they are installed-by-name here.
#: The first run of this check after G666 flagged exactly these in three
#: packages and turned the target red — this rule is that lesson.
RUN_BY_NAME = frozenset({
    "scripts/pre_install.php",
    "scripts/post_install.php",
    "scripts/pre_uninstall.php",
    "scripts/post_uninstall.php",
})


#: What a one-off cleanup package (sugar-sell/ONEOFF-*, the only packages mlp_lint
#: MLP023 lets call uninstall_new_files) ships for its post_execute to READ, not
#: install: the path list, and the placeholder tree handed to uninstall_new_files
#: as `from`, whose relative paths are the instance files it removes.
ONEOFF_INPUTS = ("scripts/leftovers.php", "leftovers/")


def members_never_installed(names: list[str], installdefs: dict, oneoff: bool = False) -> list[str]:
    """Zip members no installdefs entry reaches and Module Loader does not run
    by name. manifest.php is the loader's own; directory entries are not files."""
    prefixes = installed_prefixes(installdefs)
    dead: list[str] = []
    for m in names:
        if m == "manifest.php" or m.endswith("/") or m in RUN_BY_NAME:
            continue
        if oneoff and any(m == p or (p.endswith("/") and m.startswith(p)) for p in ONEOFF_INPUTS):
            continue
        if any(m == p or m.startswith(p.rstrip("/") + "/") for p in prefixes):
            continue
        dead.append(m)
    return dead


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

    installdefs = installdefs_of(path)
    if isinstance(installdefs, str):
        problems.append(f"cannot tell which members install: {installdefs}")
    else:
        dead = members_never_installed(names, installdefs, path.parent.parent.name.startswith("ONEOFF-"))
        if dead:
            shown = ", ".join(dead[:8]) + (f", … {len(dead) - 8} more" if len(dead) > 8 else "")
            problems.append(
                f"{len(dead)} member(s) are zipped but no installdefs entry "
                f"installs them — delete them or install them (G665): {shown}"
            )

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
