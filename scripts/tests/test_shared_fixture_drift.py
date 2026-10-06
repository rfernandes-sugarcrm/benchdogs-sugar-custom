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
import re
import shutil
import subprocess
import unittest

import shared_sugar


MANIFEST = shared_sugar.FIXTURES / "PINNED.json"

#: 0.9.42-rc83 (ERP-Epicor 1.2.0): the pins that are namespaced classes in a
#: package's custom/src/, which Sugar - and ERP-Core's stand-in - AUTOLOAD.
NAMESPACED = sorted(n for n, rel in shared_sugar.SOURCES.items()
                    if "/src/custom/src/Erp/" in rel)


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
                self.assertEqual(
                    meta["source"], shared_sugar.SOURCES[name],
                    f"{name} was pinned from another path than the one the "
                    f"tests resolve; refresh with scripts/refresh_shared_fixtures.py",
                )
                pinned = shared_sugar.pinned_path(name)
                self.assertTrue(pinned.is_file(), f"{name} not pinned")
                got = hashlib.sha256(pinned.read_bytes()).hexdigest()
                self.assertEqual(
                    got, meta["sha256"],
                    f"{name} was edited in place. These files are COPIES of "
                    f"core's; never hand-edit them - change core and re-pin "
                    f"with scripts/refresh_shared_fixtures.py",
                )

    def test_the_pin_names_one_commit_in_full(self):
        """A pin is one commit's tree, named in full - not a branch, not
        'unknown' (what the old script recorded when git failed)."""
        commit = json.loads(MANIFEST.read_text())["upstream_commit"]
        self.assertRegex(commit, r"^[0-9a-f]{40}$")

    def test_the_pin_tree_holds_exactly_the_manifest(self):
        """Each pin sits at its sibling path under FIXTURES, and nothing else
        does: a stale copy left beside them (the flat pre-rc83 layout, or a
        class a later re-pin dropped) would still be found by the pinned
        autoloader, which searches the tree, not the manifest."""
        on_disk = {p.relative_to(shared_sugar.FIXTURES).as_posix()
                   for p in shared_sugar.FIXTURES.rglob("*") if p.is_file()}
        expected = set(shared_sugar.SOURCES.values()) | {"PINNED.json"}
        self.assertEqual(on_disk, expected)

    def test_the_moved_classes_are_pinned_where_sugar_autoloads_them(self):
        """ERP-Epicor 1.2.0's four moved classes - and only a class of that
        shape - sit at <package>/src/custom/src/Erp/<Class>.php declaring
        namespace Sugarcrm\\Sugarcrm\\custom\\Erp and class <Class>: the
        PSR-4 pair the autoloader resolves."""
        self.assertEqual(NAMESPACED, [
            "ErpAccountCountryGuard.php", "ErpQuoteFacts.php",
            "QuoteOpportunityAmount.php", "QuotePrimaryQuoteSoleEnforcer.php"])
        for name in NAMESPACED:
            with self.subTest(file=name):
                text = shared_sugar.pinned_path(name).read_text()
                self.assertIn("\nnamespace Sugarcrm\\Sugarcrm\\custom\\Erp;\n", text)
                self.assertRegex(text, r"\nclass " + re.escape(name[:-4]) + r"\n")

    @unittest.skipUnless(shutil.which("php"), "requires a PHP CLI")
    def test_the_pinned_autoloader_finds_each_pinned_class_and_only_the_pin(self):
        """The pinned stand-in, required from where it is pinned, resolves each
        namespaced class to ITS PIN - its own relative roots land on the
        mirrored package trees - and defines ERP_TEST_SUPPORT as its own
        directory. Broken looks like: a class resolving to nothing (a fatal in
        every harness that loads it) or to a file outside the pin tree."""
        loader = shared_sugar.pinned_path("sugar_autoloader.php")
        classes = ["Sugarcrm\\Sugarcrm\\custom\\Erp\\" + n[:-4] for n in NAMESPACED]
        code = ("require $argv[1]; $out = ['support' => realpath(ERP_TEST_SUPPORT)];"
                " foreach (array_slice($argv, 2) as $c) { $f = erp_test_class_file($c);"
                " $out[$c] = $f === null ? null : realpath($f); } echo json_encode($out);")
        run = subprocess.run(["php", "-r", code, str(loader), *classes],
                             cwd=shared_sugar.FIXTURES, capture_output=True, text=True, check=True)
        self.assertEqual(run.stderr, "")
        got = json.loads(run.stdout)
        self.assertEqual(got.pop("support"), str(loader.parent.resolve()))
        self.assertEqual(got, {c: str(shared_sugar.pinned_path(n).resolve())
                               for c, n in zip(classes, NAMESPACED)})


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
                pinned = shared_sugar.pinned_path(name)
                self.assertEqual(
                    hashlib.sha256(live.read_bytes()).hexdigest(),
                    hashlib.sha256(pinned.read_bytes()).hexdigest(),
                    f"\n{name} has CHANGED in erp-integration-sugar since it was "
                    f"pinned.\nCI is currently testing Bench against a stale copy "
                    f"of core.\nFix: python3 scripts/refresh_shared_fixtures.py "
                    f"<commit>, then re-run the suite and commit the refreshed "
                    f"fixture.",
                )


if __name__ == "__main__":
    unittest.main()
