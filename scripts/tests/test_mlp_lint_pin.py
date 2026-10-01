"""The MLP linter CI runs is upstream's, byte for byte (G318).

Through rc67 this repository linted with a hand-synced copy that had fallen to 17
of upstream's 19 rules, so pull requests went green without MLP018 or MLP019
ever being checked. MLP019 is the rule whose violation makes SugarCloud refuse
a package mid-install. Nothing noticed the copy was stale, because nothing
compared it with anything.

The linter is now vendored and pinned (scripts/mlp_lint.PINNED.json, written by
scripts/refresh_mlp_lint.py). This file is the comparison, in two halves that
mirror test_shared_fixture_drift.py:

  PinnedLinterIntegrityTest - runs everywhere, and FIRST in CI's `rules` job,
      before the linter is trusted with anything. A vendored file that is not
      the upstream file at the pinned commit fails here.
  PinnedLinterDriftTest - runs where the erp-integration-sugar checkout is
      present. The pin must still be upstream's branch head.

Run:  python3 scripts/tests/test_mlp_lint_pin.py
"""
from __future__ import annotations

import hashlib
import json
import pathlib
import subprocess
import sys
import unittest

import shared_sugar

ROOT = pathlib.Path(__file__).resolve().parents[2]
PIN = ROOT / "scripts/mlp_lint.PINNED.json"
FIXTURE_DIR = "scripts/tests/fixtures/mlp002"
REFRESH = "python3 scripts/refresh_mlp_lint.py"

# A floor, not a mirror. Re-pinning to an older upstream commit would pass the
# hash check, since the hashes are re-recorded with it; this is what refuses
# that. MLP018 and MLP019 are exactly the two rules G318 was about.
# 🔒2161b: Rafael's rules MLP020-MLP026 (refactor/rafael-review) apply on Bench too.
REQUIRED_RULES = frozenset(f"MLP{n:03d}" for n in range(1, 27))

# The files CI actually executes. Pinning a subset that leaves one of these out
# would leave that one free to drift.
MUST_BE_PINNED = frozenset({
    "scripts/mlp_lint.py",
    "scripts/check_built_packages.py",
    "scripts/tests/test_mlp_lint.py",
})


def load_pin() -> dict:
    return json.loads(PIN.read_text())


def upstream_bytes(vendored: bytes, meta: dict) -> bytes:
    """The vendored file with its declared local delta reversed, if it has one."""
    delta = meta.get("local_delta")
    if not delta:
        return vendored
    return vendored.replace(delta["local"].encode(), delta["upstream"].encode(), 1)


class PinnedLinterIntegrityTest(unittest.TestCase):
    """True everywhere, including CI. No sibling checkout needed."""

    def test_the_pin_names_every_file_ci_runs(self):
        files = set(load_pin()["files"])
        self.assertLessEqual(MUST_BE_PINNED, files,
                             "a linter file CI executes is not pinned")
        on_disk = {p.relative_to(ROOT).as_posix()
                   for p in (ROOT / FIXTURE_DIR).glob("*")}
        pinned = {f for f in files if f.startswith(FIXTURE_DIR + "/")}
        self.assertEqual(on_disk, pinned,
                         f"{FIXTURE_DIR} holds files the pin does not name, or "
                         f"is missing ones it does; re-vendor with {REFRESH}")

    def test_every_vendored_file_is_upstreams_at_the_pinned_commit(self):
        pin = load_pin()
        commit = pin["upstream"]["commit"]
        for path, meta in sorted(pin["files"].items()):
            with self.subTest(file=path):
                dest = ROOT / path
                self.assertTrue(dest.is_file(), f"{path} is pinned but missing")
                vendored = dest.read_bytes()
                delta = meta.get("local_delta")
                if delta:
                    self.assertEqual(
                        vendored.count(delta["local"].encode()), 1,
                        f"{path}: its declared local delta is not present "
                        f"exactly once, so the file has been edited")
                got = hashlib.sha256(upstream_bytes(vendored, meta)).hexdigest()
                self.assertEqual(
                    got, meta["sha256"],
                    f"\n{path} is not erp-integration-sugar@{commit[:12]}'s file"
                    f"{' plus its one declared delta' if delta else ''}.\n"
                    f"It is VENDORED: never edit it here. Change it upstream, "
                    f"then re-vendor with {REFRESH}.")

    def test_the_linter_has_every_rule(self):
        sys.path.insert(0, str(ROOT / "scripts"))
        try:
            import mlp_lint
        finally:
            sys.path.pop(0)
        missing = sorted(REQUIRED_RULES - set(mlp_lint.RULES))
        self.assertEqual(missing, [],
                         f"the vendored linter lacks {missing}; G318 was exactly "
                         f"this, a copy CI trusted that had lost rules")


def _sibling_git(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(["git", "-C", str(shared_sugar.SIBLING), *args],
                          capture_output=True, check=False)


@unittest.skipUnless(
    shared_sugar.SIBLING.is_dir(),
    "drift can only be measured where erp-integration-sugar is checked out",
)
class PinnedLinterDriftTest(unittest.TestCase):
    """Only runs where upstream is reachable. That is the point."""

    def setUp(self):
        self.pin = load_pin()
        ref = f"origin/{self.pin['upstream']['branch']}"
        r = _sibling_git("rev-parse", "--verify", "-q", f"{ref}^{{commit}}")
        if r.returncode != 0:
            self.skipTest(f"{ref} not fetched in {shared_sugar.SIBLING}")
        self.ref = ref
        self.head = r.stdout.decode().strip()

    def test_the_pin_is_upstreams_and_still_current(self):
        """One method, so CI (where this whole class skips) pays one skip.

        Part 1: the hashes in the pin are claims about a commit; check them.
        Part 2: that commit is still upstream's head for every pinned file.
        """
        commit = self.pin["upstream"]["commit"]
        with self.subTest(part="pinned commit"):
            if _sibling_git("cat-file", "-e", f"{commit}^{{commit}}").returncode != 0:
                self.fail(f"pinned commit {commit} is not in {shared_sugar.SIBLING}; "
                          f"it was never pushed, or the history was rewritten")
            for path, meta in sorted(self.pin["files"].items()):
                blob = _sibling_git("show", f"{commit}:{path}")
                self.assertEqual(blob.returncode, 0, f"{path} absent at {commit}")
                self.assertEqual(hashlib.sha256(blob.stdout).hexdigest(),
                                 meta["sha256"],
                                 f"{path}: the pin misstates {commit[:12]}'s file")

        listing = _sibling_git("ls-tree", "--name-only", f"{self.ref}:{FIXTURE_DIR}")
        upstream_fixtures = {f"{FIXTURE_DIR}/{n}"
                             for n in listing.stdout.decode().split()}
        pinned_fixtures = {f for f in self.pin["files"]
                           if f.startswith(FIXTURE_DIR + "/")}
        with self.subTest(part="fixture set"):
            self.assertEqual(upstream_fixtures, pinned_fixtures,
                             f"upstream's {FIXTURE_DIR} changed; re-vendor with {REFRESH}")
        for path, meta in sorted(self.pin["files"].items()):
            with self.subTest(part="head", file=path):
                blob = _sibling_git("show", f"{self.ref}:{path}")
                if blob.returncode != 0:
                    self.fail(f"{path} has MOVED or been deleted upstream at "
                              f"{self.head[:12]}; the vendored copy is testing a "
                              f"file upstream no longer ships")
                self.assertEqual(
                    hashlib.sha256(blob.stdout).hexdigest(), meta["sha256"],
                    f"\n{path} has CHANGED upstream since it was pinned "
                    f"({commit[:12]} -> {self.head[:12]}).\n"
                    f"CI is enforcing a stale linter.\n"
                    f"Fix: {REFRESH}, re-run the suite, commit the result.")


if __name__ == "__main__":
    unittest.main()
