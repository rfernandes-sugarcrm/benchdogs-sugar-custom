"""Two builds of one version from an unchanged tree give the same zip bytes, dated by the version's commit.

Why this exists. CI builds the package on every run and uploads the zip as the mlp-package artifact.
Before this test, every run gave the same version a new sha256, so a recorded digest could not show
which tree an installed zip came from. A pull request run and the main push after its merge built the
same 0.9.46-rc103 tree and differed in:

  * manifest.php's published_date: the build's clock (pack.php called date());
  * every entry's stored time: its file's mtime, i.e. the moment of the checkout;
  * the stored modes: each file's mode on disk (0664 in CI) and 0666 for manifest.php;

and wrote the entries, and the manifest's copy list, in whatever order the file system listed them.

pack.php now takes one date: the committer time of the last commit that changed the package's
`version` file, the commit that released the version (SOURCE_DATE_EPOCH, when set, wins).
published_date and every entry's time come from it, entries and the copy list go in name order, and
every entry is stored as a plain 0644 file. When git cannot give that date, the build refuses.

What the two builds here differ in, on purpose: the folder they are cloned into, HEAD (the second
clone commits a change outside the package, as a merge does), every source file's mtime and mode,
the umask, the time zone (the zip format stores local time), PHP's date.timezone, the locale, and the
wall clock (over two seconds apart, the zip time resolution).

IF IT WERE BROKEN: pack.php went back to date(), stopped pinning entry times or modes, let the time
zone into the stored times, listed files in the file system's order, dated the build by HEAD rather
than by the version's commit, or built anyway, by the clock, when git cannot date it. Each moves the
digest between the two builds, breaks the match between published_date and the version's commit, or
lets a build that cannot be dated write a zip.
"""

from __future__ import annotations

import hashlib
import os
import re
import shutil
import subprocess
import time
import zipfile
from pathlib import Path

import pytest

import bd_retirement

ROOT = Path(__file__).resolve().parents[2]
# Every package CI builds: each folder under sugar-sell with a pack.php, as the workflow's build loop finds them.
PACKAGES = sorted(p.parent.relative_to(ROOT).as_posix() for p in ROOT.glob("sugar-sell/*/pack.php"))
# What the zip format stores for a plain file: the Unix regular-file bits plus rw-r--r--.
PLAIN_FILE = 0o100644
UNIX = 3
PINNED = bd_retirement.SCRATCH_SOURCE_DATE
PINNED_STAMP = time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime(int(PINNED)))
ANOTHER_VERSION = "9.9.9-not-released"


def git(*args: str, cwd: Path) -> str:
    return subprocess.run(["git", *args], cwd=cwd, check=True, capture_output=True, text=True).stdout.strip()


def commit_under_test() -> str:
    """HEAD; with uncommitted edits to tracked files, a commit of them (`git stash create` moves no ref)."""
    if subprocess.run(["git", "diff", "--quiet", "HEAD"], cwd=ROOT).returncode == 0:
        return git("rev-parse", "HEAD", cwd=ROOT)
    return git("-c", "user.name=test", "-c", "user.email=test@example.invalid", "stash", "create", cwd=ROOT)


def clone(dest: Path) -> Path:
    """A clone of the tested commit with its history, sharing this checkout's objects but not its files."""
    subprocess.run(["git", "clone", "--quiet", "--no-checkout", "--shared", str(ROOT), str(dest)], check=True)
    git("checkout", "--quiet", "--detach", commit_under_test(), cwd=dest)
    return dest


def build(tree: Path, package: str, *args: str, tz: str = "UTC", umask: int = 0o022,
          source_date: str | None = None, locale: str = "C",
          php_zone: str | None = None) -> subprocess.CompletedProcess:
    """Run pack.php the way CI does, in a clone, with no date inherited from this process."""
    env = {k: v for k, v in os.environ.items() if k != "SOURCE_DATE_EPOCH"}
    # Git must not find a repository above the clone (the no-history case removes the clone's own).
    env.update(TZ=tz, LC_ALL=locale, GIT_CEILING_DIRECTORIES=str(tree.parent))
    if php_zone is not None:
        # An extra ini folder after PHP's own (the leading ':'), as a php.ini that sets a zone would.
        ini = tree.parent / f"{tree.name}-ini"
        ini.mkdir(exist_ok=True)
        (ini / "zone.ini").write_text(f"date.timezone={php_zone}\n")
        env["PHP_INI_SCAN_DIR"] = f":{ini}"
    if source_date is not None:
        env["SOURCE_DATE_EPOCH"] = source_date
    return subprocess.run(
        ["php", "pack.php", *args], cwd=tree / package, env=env, capture_output=True, text=True,
        timeout=60, preexec_fn=lambda: os.umask(umask),
    )


def said(done: subprocess.CompletedProcess) -> str:
    return (done.stdout[-1500:] + done.stderr[-1500:]).strip()


def zips(tree: Path, package: str) -> list[Path]:
    releases = tree / package / "releases"
    return sorted(releases.glob("*.zip")) if releases.is_dir() else []


def the_zip(tree: Path, package: str) -> Path:
    built = zips(tree, package)
    assert len(built) == 1, built
    return built[0]


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def manifest_text(archive: zipfile.ZipFile) -> str:
    return archive.read("manifest.php").decode()


def published_date(archive: zipfile.ZipFile) -> str:
    found = re.findall(r"'published_date' => '([^']*)'", manifest_text(archive))
    assert len(found) == 1, f"expected one published_date in manifest.php, found {found}"
    return found[0]


def version_commit_time(tree: Path, package: str) -> int:
    """The committer time of the last commit that changed the package's version file."""
    return int(git("log", "-1", "--format=%ct", "--", "version", cwd=tree / package))


def first_difference(a: Path, b: Path) -> str:
    """The first property two zips disagree on, so a red run says what moved."""
    with zipfile.ZipFile(a) as za, zipfile.ZipFile(b) as zb:
        ia, ib = za.infolist(), zb.infolist()
        if [i.filename for i in ia] != [i.filename for i in ib]:
            return "entry names or order"
        for x, y in zip(ia, ib):
            if za.read(x) != zb.read(y):
                return f"{x.filename}: content"
            for field in ("date_time", "external_attr", "create_system", "compress_size", "extra"):
                if getattr(x, field) != getattr(y, field):
                    return f"{x.filename}: {field} {getattr(x, field)!r} vs {getattr(y, field)!r}"
    return "the zip container outside its entries"


def test_ci_builds_a_package_this_file_finds() -> None:
    # Without one, every case below would be parametrized over nothing and pass by not running.
    assert PACKAGES, "no sugar-sell/*/pack.php found"


@pytest.fixture(scope="module")
def two_builds(tmp_path_factory: pytest.TempPathFactory) -> tuple[Path, Path]:
    base = tmp_path_factory.mktemp("reproducible")
    first = clone(base / "a")
    second = clone(base / "somewhere" / "else" / "b")
    # Another HEAD with the packages unchanged, as a merge commit or an unrelated change gives.
    readme = second / "README.md"
    readme.write_text(readme.read_text() + "\nA change outside the packages.\n")
    git("-c", "user.name=test", "-c", "user.email=test@example.invalid", "commit", "--quiet", "-am",
        "A change outside the packages", cwd=second)
    assert git("rev-parse", "HEAD", cwd=second) != git("rev-parse", "HEAD", cwd=first)
    # Every package file in the second clone gets another mtime and the group-writable mode a
    # umask of 002 gives; its builds run under that umask too.
    for package in PACKAGES:
        for item in (second / package).rglob("*"):
            if item.is_file():
                item.chmod(0o775 if os.access(item, os.X_OK) else 0o664)
                os.utime(item, (981173106, 981173106))

    for package in PACKAGES:
        done = build(first, package)
        assert done.returncode == 0, said(done)
    time.sleep(2.2)
    for package in PACKAGES:
        # A POSIX zone string needs no tz database: five and a half hours east of UTC.
        done = build(second, package, tz="XST-5:30", umask=0o002, locale="en_US.UTF-8", php_zone="Asia/Kolkata")
        assert done.returncode == 0, said(done)
    return first, second


@pytest.mark.parametrize("package", PACKAGES)
def test_two_builds_of_one_version_are_the_same_bytes(two_builds: tuple[Path, Path], package: str) -> None:
    first, second = two_builds
    a, b = the_zip(first, package), the_zip(second, package)
    assert a.name == b.name
    assert sha256(a) == sha256(b), f"two builds of one version differ: {first_difference(a, b)}"


@pytest.mark.parametrize("package", PACKAGES)
def test_the_zip_is_dated_by_its_version_commit_in_name_order_as_plain_files(
    two_builds: tuple[Path, Path], package: str,
) -> None:
    first, _ = two_builds
    epoch = version_commit_time(first, package)
    # The zip format stores even seconds, rounded down.
    stored = time.gmtime(epoch - epoch % 2)[:6]
    with zipfile.ZipFile(the_zip(first, package)) as archive:
        assert published_date(archive) == time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime(epoch))
        entries = archive.infolist()
        names = [e.filename for e in entries]
        assert names == sorted(names), "entries are not in name order"
        for entry in entries:
            assert entry.date_time == stored, f"{entry.filename} stored at {entry.date_time}"
            assert entry.external_attr >> 16 == PLAIN_FILE, f"{entry.filename} mode {entry.external_attr >> 16:o}"
            assert entry.create_system == UNIX, f"{entry.filename} made on {entry.create_system}"
        copied = re.findall(r"'to' => '([^']*)'", manifest_text(archive))
        assert copied, "the manifest copies nothing"
        assert copied == sorted(copied), "the manifest's copy list is not in name order"


@pytest.mark.parametrize("package", PACKAGES)
def test_a_date_in_the_environment_dates_the_build(tmp_path: Path, package: str) -> None:
    tree = clone(tmp_path / "pinned")
    done = build(tree, package, source_date=PINNED)
    assert done.returncode == 0, said(done)
    with zipfile.ZipFile(the_zip(tree, package)) as archive:
        assert published_date(archive) == PINNED_STAMP
        assert {e.date_time for e in archive.infolist()} == {time.gmtime(int(PINNED) - int(PINNED) % 2)[:6]}


UNDATED = ["no git history", "a shallow clone", "a version file with changes no commit has",
           "a package no commit has", "a package git ignores", "a version given on the command line"]


@pytest.mark.parametrize("package", PACKAGES)
@pytest.mark.parametrize("how", UNDATED)
def test_a_build_that_cannot_be_dated_from_git_refuses(tmp_path: Path, how: str, package: str) -> None:
    tree = clone(tmp_path / "tree")
    args: tuple[str, ...] = ()
    if how == "no git history":
        shutil.rmtree(tree / ".git")
    elif how == "a shallow clone":
        # What `git clone --depth 1` leaves: the checked-out commit recorded as the history's edge,
        # where `git log -- version` would answer that commit's time, not the version's.
        (tree / ".git" / "shallow").write_text(git("rev-parse", "HEAD", cwd=tree) + "\n")
        assert git("rev-parse", "--is-shallow-repository", cwd=tree) == "true"
    elif how == "a version file with changes no commit has":
        (tree / package / "version").write_text(ANOTHER_VERSION + "\n")
    elif how in ("a package no commit has", "a package git ignores"):
        copy = "sugar-sell/Not-Yet-Committed"
        shutil.copytree(tree / package, tree / copy)
        package = copy
        if how == "a package git ignores":
            # git status reports nothing for an ignored file, and git log has no commit for it.
            with (tree / ".git" / "info" / "exclude").open("a") as exclude:
                exclude.write(f"/{copy}/\n")
    else:
        args = (ANOTHER_VERSION,)

    done = build(tree, package, *args)

    assert done.returncode != 0, said(done)
    assert "SOURCE_DATE_EPOCH" in done.stdout + done.stderr, said(done)
    assert not zips(tree, package), "a build that could not be dated wrote a zip"
    # The control: the same tree with a date given builds, so the refusal above was the date's.
    done = build(tree, package, *args, source_date=PINNED)
    assert done.returncode == 0, said(done)
    assert len(zips(tree, package)) == 1


@pytest.mark.parametrize("package", PACKAGES)
@pytest.mark.parametrize("value", ["yesterday", "100", "-5", "1.5e9"])
def test_an_unusable_date_in_the_environment_refuses(tmp_path: Path, value: str, package: str) -> None:
    tree = clone(tmp_path / "tree")
    done = build(tree, package, source_date=value)
    assert done.returncode != 0, said(done)
    assert "SOURCE_DATE_EPOCH" in done.stdout + done.stderr, said(done)
    assert not zips(tree, package)


STOPS = ["a zip that is already there", "a releases path that is not a folder", "a zip it cannot write"]


@pytest.mark.parametrize("package", PACKAGES)
@pytest.mark.parametrize("case", STOPS)
def test_the_builder_stops_rather_than_leave_a_wrong_zip(tmp_path: Path, case: str, package: str) -> None:
    # A zip already there and a zip that cannot be written used to end with a zero exit: the old zip
    # left in place under the new build's name, or "Done. Wrote" over a zip that was never written. A
    # releases path that is not a folder ended on PHP's own error; now the builder names it.
    tree = clone(tmp_path / "tree")
    releases = tree / package / "releases"
    args: tuple[str, ...] = ()
    before = None
    if case == "a zip that is already there":
        done = build(tree, package, source_date=PINNED)
        assert done.returncode == 0, said(done)
        before = sha256(the_zip(tree, package))
    elif case == "a releases path that is not a folder":
        releases.write_text("not a folder\n")
    else:
        # A version naming a folder that is not there: the zip's own folder cannot be written to.
        args = ("gone/0.0.1",)

    done = build(tree, package, *args, source_date=PINNED)

    assert done.returncode != 0, said(done)
    assert "Done. Wrote" not in done.stdout, said(done)
    if before is not None:
        assert sha256(the_zip(tree, package)) == before, "the zip already there was changed"
    elif releases.is_dir():
        assert not list(releases.rglob("*.zip")), "a zip was left behind"
