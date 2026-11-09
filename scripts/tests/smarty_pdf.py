#!/usr/bin/env python3
"""The template engine Sugar's PDF Manager renders a quote template with, for the quote PDF render test.

Sugar 26.1 renders a PDF Manager template with the Smarty it ships (vendor/smarty/smarty, 4.5.6), under the PDF
security policy, then hands the HTML to TCPDF. bd_quote_pdf_render_test.php renders this package's template through
that same Smarty, so what it checks is what a tenant prints, not what a reading of the template suggests.

Smarty is a public library (LGPL), so CI fetches the release itself rather than carrying a copy:

    python3 scripts/tests/smarty_pdf.py fetch /tmp/smarty      # prints the libs directory

Whichever copy is used (CI's download, or a SugarEnt tree on a developer's machine), its libs directory must hash to
LIBS_TREE_SHA256, which is the hash of Sugar 26.1.0's own vendor/smarty/smarty/libs. The release tarball itself is
not pinned: GitHub does not promise byte-stable archives, only their contents.
"""
from __future__ import annotations

import hashlib
import io
import os
import pathlib
import sys
import tarfile
import urllib.request

VERSION = "4.5.6"
URL = f"https://github.com/smarty-php/smarty/archive/refs/tags/v{VERSION}.tar.gz"

#: sha256 over "<relative path>\t<file sha256>\n" for every file under libs/, sorted by path. Measured on Sugar
#: 26.1.0's vendor/smarty/smarty/libs and on the v4.5.6 release: the same 212 files, the same hash.
LIBS_TREE_SHA256 = "1831a3f3c45116b8806bc14d9b8fdabf5bf6d3e5660b5b67597e05991ef60e44"
LIBS_FILES = 212

#: Where a developer's SugarEnt tree keeps it (the other tests read the same trees).
SUGARENT_LIBS = pathlib.Path.home() / "Documents/Code/SugarEnt-Full-26.1.0/vendor/smarty/smarty/libs"


def tree_sha256(libs: pathlib.Path) -> tuple[str, int]:
    digest = hashlib.sha256()
    files = sorted(p for p in libs.rglob("*") if p.is_file())
    for path in files:
        line = f"{path.relative_to(libs).as_posix()}\t{hashlib.sha256(path.read_bytes()).hexdigest()}\n"
        digest.update(line.encode())
    return digest.hexdigest(), len(files)


def verified(libs: pathlib.Path) -> bool:
    return (libs / "Smarty.class.php").is_file() and tree_sha256(libs) == (LIBS_TREE_SHA256, LIBS_FILES)


def resolve() -> pathlib.Path | None:
    """BD_SMARTY_LIBS when set (CI sets it after `fetch`), else a SugarEnt tree; None when neither is there.

    A copy that is there but is not Sugar's Smarty is an error, never a fallback to the next one.
    """
    named = os.environ.get("BD_SMARTY_LIBS", "")
    candidates = [pathlib.Path(named)] if named else [SUGARENT_LIBS]
    for libs in candidates:
        if not libs.is_dir():
            if named:
                raise SystemExit(f"BD_SMARTY_LIBS={named} is not a directory")
            continue
        if not verified(libs):
            raise SystemExit(f"{libs} is not Smarty {VERSION} as Sugar 26.1.0 ships it (tree hash differs)")
        return libs
    return None


def fetch(dest: pathlib.Path) -> pathlib.Path:
    """Download the release, write only its libs/ files under dest, and check them against the pin."""
    with urllib.request.urlopen(URL, timeout=60) as response:
        data = response.read()
    prefix = f"smarty-{VERSION}/libs/"
    libs = dest / "libs"
    with tarfile.open(fileobj=io.BytesIO(data), mode="r:gz") as archive:
        for member in archive.getmembers():
            if not member.name.startswith(prefix) or not member.isfile():
                continue
            rel = pathlib.PurePosixPath(member.name[len(prefix):])
            if rel.is_absolute() or ".." in rel.parts:
                raise SystemExit(f"refused archive path {member.name}")
            target = libs.joinpath(*rel.parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            source = archive.extractfile(member)
            target.write_bytes(source.read() if source else b"")
    if not verified(libs):
        raise SystemExit(f"the downloaded Smarty {VERSION} libs do not match the pin {LIBS_TREE_SHA256}")
    return libs


if __name__ == "__main__":
    if len(sys.argv) != 3 or sys.argv[1] != "fetch":
        raise SystemExit("usage: smarty_pdf.py fetch <dest>")
    print(fetch(pathlib.Path(sys.argv[2])))
