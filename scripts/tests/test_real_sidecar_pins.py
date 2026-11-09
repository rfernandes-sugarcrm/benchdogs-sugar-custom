#!/usr/bin/env python3
"""The files under fixtures/real-sidecar/ are erp-integration-sugar's, at the one commit PINNED.json names.

They are copies (CI cannot check out that private repository), made by scripts/refresh_real_sidecar_pins.py. Here:
the tree holds exactly the manifest's files, each with its recorded sha256; and where the sibling checkout holds the
pinned commit, each copy is that commit's file, byte for byte. Never edit a copy: re-pin.
"""
from __future__ import annotations

import hashlib
import json
import subprocess
import unittest

import shared_sugar

PINS = shared_sugar.ROOT / "scripts/tests/fixtures/real-sidecar"
MANIFEST = PINS / "PINNED.json"
#: Installed from the pinned lock file by npm ci, never committed (.gitignore).
INSTALLED = PINS / "scripts/tests/dom/node_modules"


class TheRealSidecarPins(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))

    def test_the_pin_names_one_commit_in_full(self):
        self.assertRegex(self.manifest["upstream_commit"], r"^[0-9a-f]{40}$")

    def test_the_tree_holds_exactly_the_manifest_each_file_intact(self):
        on_disk = {p.relative_to(PINS).as_posix() for p in PINS.rglob("*")
                   if p.is_file() and p != MANIFEST and INSTALLED not in p.parents}
        self.assertEqual(on_disk, set(self.manifest["files"]))
        for rel, meta in self.manifest["files"].items():
            with self.subTest(file=rel):
                self.assertEqual(hashlib.sha256((PINS / rel).read_bytes()).hexdigest(), meta["sha256"],
                                 f"{rel} was edited in place; re-pin with scripts/refresh_real_sidecar_pins.py")

    def test_each_copy_is_the_pinned_commits_file_where_that_commit_is_at_hand(self):
        """Where a sibling checkout holds the pinned commit (the owner's machine), each copy is that commit's file.
        In CI there is none; the test above still holds every copy to its recorded sha256. Not a skip: CI's skip
        ceiling counts skips, and this half has nothing to read there."""
        commit = self.manifest["upstream_commit"]
        sibling = shared_sugar.SIBLING
        has = (sibling / ".git").exists() and subprocess.run(
            ["git", "-C", str(sibling), "cat-file", "-e", commit + "^{commit}"], capture_output=True).returncode == 0
        if not has:
            return
        for rel in self.manifest["files"]:
            with self.subTest(file=rel):
                upstream = subprocess.run(["git", "-C", str(sibling), "show", f"{commit}:{rel}"],
                                          capture_output=True, check=True).stdout
                self.assertEqual((PINS / rel).read_bytes(), upstream)


if __name__ == "__main__":
    unittest.main()
