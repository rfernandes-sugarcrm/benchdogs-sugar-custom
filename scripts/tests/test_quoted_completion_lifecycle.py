"""Exact Kinetic completion signal and Bench lifecycle state matrix.

The PHP harness invokes the production private helpers through reflection. It
does not duplicate their decisions in Python, and it never connects to Sugar.
"""

from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
HOOK = (
    ROOT
    / "sugar-sell/BenchDogs-Ext/custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php"
)
VARDEFS = (
    ROOT
    / "sugar-sell/BenchDogs-Ext/custom/Extension/modules/bd01_ERP_Quote"
    / "Ext/Vardefs/bd_quote_completion.php"
)
HARNESS = ROOT / "scripts/tests/quoted_completion_lifecycle_harness.php"
COMPOSED_HARNESS = ROOT / "scripts/tests/quoted_completion_composed_harness.php"
NOTIFICATION_HOOK = (
    ROOT
    / "sugar-sell/BenchDogs-Ext/custom/modules/Quotes/BdEstimatingNotificationHook.php"
)


@unittest.skipUnless(shutil.which("php") or shutil.which("docker"), "requires PHP 8.2")
class QuotedCompletionLifecycleTest(unittest.TestCase):
    def execute_php(self, harness: Path, *sources: Path) -> subprocess.CompletedProcess:
        with tempfile.TemporaryDirectory(prefix="bench-quoted-lifecycle-") as tmp:
            target = Path(tmp)
            copied_harness = target / harness.name
            shutil.copy2(harness, copied_harness)
            copied_sources = []
            for source in sources:
                copied = target / source.name
                shutil.copy2(source, copied)
                copied_sources.append(copied.name)

            if shutil.which("php"):
                command = ["php", copied_harness.name, *copied_sources]
                cwd = target
            else:
                command = [
                    "docker", "run", "--rm",
                    "-v", f"{target}:/work", "-w", "/work",
                    "php:8.2-cli", "php", copied_harness.name, *copied_sources,
                ]
                cwd = None
            return subprocess.run(
                command,
                cwd=cwd,
                capture_output=True,
                text=True,
                check=True,
            )

    def run_harness(self) -> dict:
        result = self.execute_php(HARNESS, HOOK)
        self.assertEqual(result.stderr, "", result.stderr)
        return json.loads(result.stdout)

    def run_composed_harness(self) -> dict:
        result = self.execute_php(COMPOSED_HARNESS, HOOK, NOTIFICATION_HOOK)
        self.assertEqual(result.stderr, "", result.stderr)
        return json.loads(result.stdout)

    def test_exact_completion_state_matrix(self):
        observed = self.run_harness()
        self.assertEqual(observed["unknown_preserves_handoff"], "in_estimating")
        self.assertEqual(observed["unknown_preserves_priced"], "priced")
        self.assertEqual(observed["false_preserves_handoff"], "in_estimating")
        self.assertEqual(observed["false_preserves_revision"], "revision")
        self.assertEqual(observed["false_reopens_priced"], "revision")
        self.assertEqual(observed["unchanged_false_does_not_invent_revision"], "priced")
        self.assertEqual(observed["false_initializes_draft"], "draft")
        self.assertEqual(observed["true_with_date_prices"], "priced")
        self.assertEqual(observed["true_without_date_fails_closed"], "in_estimating")
        self.assertEqual(observed["repeat_true_is_stable"], "priced")
        self.assertEqual(observed["requoted_after_revision"], "priced")
        self.assertEqual(observed["order_outranks_incomplete"], "ordered")
        self.assertEqual(observed["closed_won_outranks_incomplete"], "accepted")
        self.assertEqual(observed["closed_lost_outranks_complete"], "lost")

    def test_nullable_bool_parser_preserves_three_states_and_rejects_garbage(self):
        observed = self.run_harness()
        self.assertEqual(
            observed["signals"],
            [None, None, False, False, False, True, True, True, None],
        )
        self.assertEqual(observed["warning_count"], 1)

    def test_fields_are_nullable_and_sugar_observation_owns_elapsed_stamps(self):
        vardefs = VARDEFS.read_text(encoding="utf-8")
        hook = HOOK.read_text(encoding="utf-8")
        self.assertNotIn("'default'", vardefs)
        self.assertIn("['fields']['quoted']", vardefs)
        self.assertIn("['fields']['date_quoted']", vardefs)
        self.assertIn("$bean->bd_priced_back_at = $completionObservedAt;", hook)
        self.assertIn("$quote->bd_priced_at = $completionObservedAt", hook)
        self.assertIn("&& $dateQuoted !== ''", hook)
        self.assertIn("$completionObservedAt = TimeDate::getInstance()->nowDb();", hook)

    def test_reflection_quote_save_and_return_notification_compose(self):
        observed = self.run_composed_harness()
        states = observed["states"]

        # False preserves the explicit hand-off. The first corroborated true
        # transition uses Sugar's observation time, not DateQuoted midnight,
        # and the actual Quote save creates exactly one return notification.
        self.assertEqual(states["false_in_estimating"]["stage"], "in_estimating")
        first = states["first_priced"]
        self.assertEqual(first["stage"], "priced")
        self.assertEqual(first["priced_at"], "2026-09-12 14:23:45")
        self.assertEqual(first["priced_back_at"], "2026-09-12 14:23:45")
        self.assertEqual(first["quote_save_count"], 1)
        self.assertEqual(first["notification_count"], 1)

        # An unchanged connector replay does not save the Quote or create a
        # second notification for the same completion event.
        repeated = states["repeat_first_priced"]
        self.assertEqual(repeated["quote_save_count"], 1)
        self.assertEqual(repeated["notification_count"], 1)

        # Kinetic false reopens a priced quote as revision. True without the
        # corroborating date fails closed: no Quote save and no notification.
        revision = states["reopened_revision"]
        self.assertEqual(revision["stage"], "revision")
        self.assertEqual(revision["quote_save_count"], 2)
        self.assertEqual(revision["notification_count"], 1)
        without_date = states["true_without_date"]
        self.assertEqual(without_date["stage"], "revision")
        self.assertEqual(without_date["quote_save_count"], 2)
        self.assertEqual(without_date["notification_count"], 1)

        # A later DateQuoted observation completes the revision and produces
        # one new event identity. Original turnaround timestamps are immutable.
        repriced = states["revision_priced"]
        self.assertEqual(repriced["stage"], "priced")
        self.assertEqual(repriced["quote_save_count"], 3)
        self.assertEqual(repriced["notification_count"], 2)
        self.assertEqual(repriced["priced_at"], first["priced_at"])
        self.assertEqual(repriced["priced_back_at"], first["priced_back_at"])
        replay = states["repeat_revision_priced"]
        self.assertEqual(replay["quote_save_count"], 3)
        self.assertEqual(replay["notification_count"], 2)

        stage_changes = [
            (change["before"], change["after"])
            for save in observed["quote_save_changes"]
            for change in save
            if change["field_name"] == "bd_erp_stage"
        ]
        self.assertEqual(
            stage_changes,
            [
                ("in_estimating", "priced"),
                ("priced", "revision"),
                ("revision", "priced"),
            ],
        )

        notifications = observed["notifications"]
        self.assertEqual(len(notifications), 2)
        self.assertEqual(len({row["sync_key"] for row in notifications}), 2)
        for row in notifications:
            self.assertEqual(row["recipient"], "sales-user")
            self.assertEqual(row["parent_type"], "Quotes")
            self.assertEqual(row["parent_id"], "quote-1")
            self.assertTrue(row["sync_key"].startswith("bdh:"))
            self.assertEqual(len(row["sync_key"]), 68)
        self.assertTrue(any("Quoted=true without DateQuoted" in row[1]
                            for row in observed["warnings"]))


if __name__ == "__main__":
    unittest.main()
