"""The pinned shared-Sugar copies must still match core.

CI runs the cross-package tests against fixtures/shared-sugar/ because the
sibling repository is private and in another GitHub org. That buys real
coverage at the cost of a snapshot, and a snapshot that silently rots turns a
green check into a lie - the worst of the three outcomes, worse than the skip
it replaced.

So: wherever BOTH trees exist (the owner's machine, and every local run these
tests have ever had) this compares the pin against the live file byte for
byte. It is the alarm that makes the snapshot honest.
"""
from __future__ import annotations

import hashlib
import json
import unittest

import shared_sugar


MANIFEST = shared_sugar.FIXTURES / "PINNED.json"


class PinnedFixtureManifestTest(unittest.TestCase):
    """True everywhere, including CI - no sibling checkout needed."""

    def test_every_required_file_is_pinned_and_intact(self):
        self.assertTrue(MANIFEST.is_file(), "PINNED.json missing")
        manifest = json.loads(MANIFEST.read_text())

        self.assertEqual(
            set(manifest["files"]), set(shared_sugar.SOURCES),
            "PINNED.json and shared_sugar.SOURCES disagree about which files "
            "are required; refresh with scripts/refresh_shared_fixtures.py",
        )

        for name, meta in manifest["files"].items():
            with self.subTest(file=name):
                pinned = shared_sugar.FIXTURES / name
                self.assertTrue(pinned.is_file(), f"{name} not pinned")
                got = hashlib.sha256(pinned.read_bytes()).hexdigest()
                self.assertEqual(
                    got, meta["sha256"],
                    f"{name} was edited in place. These files are COPIES of "
                    f"core's; never hand-edit them - change core and re-pin "
                    f"with scripts/refresh_shared_fixtures.py",
                )


@unittest.skipUnless(
    shared_sugar.SIBLING.is_dir(),
    "drift can only be measured where erp-integration-sugar is checked out",
)
class PinnedFixtureDriftTest(unittest.TestCase):
    """Only runs where the real file is reachable - that is the point."""

    def test_the_pin_still_matches_core(self):
        for name in shared_sugar.SOURCES:
            with self.subTest(file=name):
                live = shared_sugar.sibling_path(name)
                if not live.is_file():
                    self.fail(
                        f"{name} has MOVED or been deleted in "
                        f"erp-integration-sugar ({shared_sugar.SOURCES[name]}). "
                        f"The tests that require it are now testing a file core "
                        f"no longer ships."
                    )
                pinned = shared_sugar.FIXTURES / name
                self.assertEqual(
                    hashlib.sha256(live.read_bytes()).hexdigest(),
                    hashlib.sha256(pinned.read_bytes()).hexdigest(),
                    f"\n{name} has CHANGED in erp-integration-sugar since it was "
                    f"pinned.\nCI is currently testing Bench against a stale copy "
                    f"of core.\nFix: python3 scripts/refresh_shared_fixtures.py, "
                    f"then re-run the suite and commit the refreshed fixture.",
                )


if __name__ == "__main__":
    unittest.main()
