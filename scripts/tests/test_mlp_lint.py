#!/usr/bin/env python3
"""Self-tests for scripts/mlp_lint.py.

A linter nobody trusts gets switched off, and the way it loses trust is a false
positive on correct code. So every rule is tested twice: once on a fixture that
must fire, and once on the fixed version of the same fixture that must not.

The MLP001 fixture is the real ERP-Epicor defect, reduced: pre_execute loads a
class from __DIR__, post_execute loads the installed copy of the same class,
and the install dies. That case is the reason this file exists, so it is also
tested against the actual shipped scripts at the bottom.

    python3 scripts/tests/test_mlp_lint.py
"""

from __future__ import annotations

import json
import re
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import mlp_lint  # noqa: E402


class Fixture:
    """A throwaway package directory."""

    def __init__(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name)
        (self.root / "pack.php").write_text("<?php\n", encoding="utf-8")

    def write(self, relpath: str, text: str) -> Path:
        p = self.root / relpath
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(text, encoding="utf-8")
        return p

    def lint(self) -> list[mlp_lint.Finding]:
        return mlp_lint.lint_package(mlp_lint.load_package(self.root))

    def lint_as_zip(self) -> list[mlp_lint.Finding]:
        """Lint the same tree as if it had been unpacked from a built archive.

        The distinction matters for any rule that asks whether a file EXISTS
        rather than whether it is correct: from inside a zip the source tree is
        not there to consult, so those rules have to sit out.
        """
        pkg = mlp_lint.load_package(self.root)
        pkg.from_zip = True
        return mlp_lint.lint_package(pkg)

    def rules(self) -> list[str]:
        return [f.rule for f in self.lint()]

    def zip_rules(self) -> list[str]:
        return [f.rule for f in self.lint_as_zip()]

    def close(self) -> None:
        self._tmp.cleanup()


class RuleTest(unittest.TestCase):
    def setUp(self) -> None:
        self.fx = Fixture()
        self.addCleanup(self.fx.close)

    def assertFires(self, rule: str) -> None:
        self.assertIn(rule, self.fx.rules(), f"{rule} should have fired")

    def assertQuiet(self, rule: str) -> None:
        self.assertNotIn(rule, self.fx.rules(), f"{rule} should not have fired")


class TestClassRedeclare(RuleTest):
    """MLP001 — the defect that took ossugarcube2 down."""

    CLASS_FILE = "<?php\nclass ErpDashboardReconcile\n{\n    public function go() {}\n}\n"

    def _unguarded(self) -> None:
        self.fx.write("scripts/ErpDashboardReconcile.php", self.CLASS_FILE)
        self.fx.write(
            "scripts/pre_execute.php",
            "<?php\nrequire_once __DIR__ . '/ErpDashboardReconcile.php';\n"
            "(new ErpDashboardReconcile())->go();\n",
        )
        self.fx.write(
            "scripts/post_execute.php",
            "<?php\nrequire_once('custom/include/scripts/ErpDashboardReconcile.php');\n"
            "(new ErpDashboardReconcile())->go();\n",
        )

    def test_two_paths_one_class_fires(self) -> None:
        self._unguarded()
        self.assertFires("MLP001")

    def test_it_is_a_blocker(self) -> None:
        self._unguarded()
        hit = [f for f in self.fx.lint() if f.rule == "MLP001"]
        self.assertTrue(hit)
        self.assertEqual(hit[0].severity, mlp_lint.BLOCKER)
        self.assertIn("Cannot redeclare", hit[0].message)

    def test_class_exists_guard_silences_it(self) -> None:
        self.fx.write("scripts/ErpDashboardReconcile.php", self.CLASS_FILE)
        self.fx.write(
            "scripts/pre_execute.php",
            "<?php\nif (!class_exists('ErpDashboardReconcile', false)) {\n"
            "    require_once __DIR__ . '/ErpDashboardReconcile.php';\n}\n",
        )
        self.fx.write(
            "scripts/post_execute.php",
            "<?php\nif (!class_exists('ErpDashboardReconcile', false)) {\n"
            "    require_once('custom/include/scripts/ErpDashboardReconcile.php');\n}\n",
        )
        self.assertQuiet("MLP001")

    def test_a_path_that_contains_the_word_include_is_not_an_include_site(self) -> None:
        """The Partial Fulfillment 1.0.20/1.0.21 false positive.

        An uninstall script must check a file exists before requiring it -- an
        uninstall that fatals blocks removal of the package the admin is trying
        to delete -- so it assigns the path to a variable first. That path
        lives under custom/include/, this estate's standard destination for
        packaged scripts. With a bare \b the rule matched the word `include`
        inside the literal, called the tail of the string a second include
        path, and reported a redeclare fatal for a class required exactly once.
        """
        self.fx.write("scripts/ErpDashboardReconcile.php", self.CLASS_FILE)
        self.fx.write(
            "scripts/post_execute.php",
            "<?php\nrequire_once('custom/include/scripts/ErpDashboardReconcile.php');\n"
            "(new ErpDashboardReconcile())->go();\n",
        )
        self.fx.write(
            "scripts/pre_uninstall.php",
            "<?php\n$target = 'custom/include/scripts/ErpDashboardReconcile.php';\n"
            "if (!file_exists($target)) {\n    return;\n}\n"
            "require_once($target);\n(new ErpDashboardReconcile())->uninstall();\n",
        )
        self.assertQuiet("MLP001")

    def test_a_directory_named_require_is_not_an_include_site(self) -> None:
        """Same defect, the other keyword. `require` is a plausible directory
        name and the rule must not read one as a statement."""
        self.fx.write("scripts/ErpDashboardReconcile.php", self.CLASS_FILE)
        self.fx.write(
            "scripts/post_execute.php",
            "<?php\nrequire_once('custom/require/ErpDashboardReconcile.php');\n",
        )
        self.fx.write(
            "scripts/pre_uninstall.php",
            "<?php\n$target = 'custom/require/ErpDashboardReconcile.php';\n"
            "require_once($target);\n",
        )
        self.assertQuiet("MLP001")

    def test_a_genuine_second_path_under_custom_include_still_fires(self) -> None:
        """The narrowing above must not cost the rule its actual job. This is
        the ossugarcube2 defect with the installed copy under custom/include/,
        i.e. the shape closest to the false positive that is still real: two
        DIFFERENT files, both loaded in one request."""
        self.fx.write("scripts/ErpDashboardReconcile.php", self.CLASS_FILE)
        self.fx.write(
            "scripts/pre_execute.php",
            "<?php\nrequire_once __DIR__ . '/ErpDashboardReconcile.php';\n",
        )
        self.fx.write(
            "scripts/post_execute.php",
            "<?php\nrequire_once('custom/include/scripts/ErpDashboardReconcile.php');\n",
        )
        self.assertFires("MLP001")

    def test_single_path_is_fine(self) -> None:
        self.fx.write("scripts/ErpDashboardReconcile.php", self.CLASS_FILE)
        self.fx.write(
            "scripts/pre_execute.php",
            "<?php\nrequire_once __DIR__ . '/ErpDashboardReconcile.php';\n",
        )
        self.fx.write(
            "scripts/post_execute.php",
            "<?php\nrequire_once __DIR__ . '/ErpDashboardReconcile.php';\n",
        )
        self.assertQuiet("MLP001")


class TestDeniedFunctions(RuleTest):
    """MLP002 — the functions that got 1.1.0 rejected at upload."""

    def test_unlink_fires(self) -> None:
        self.fx.write(
            "scripts/Cleanup.php",
            "<?php\nif (is_file($p)) {\n    unlink($p);\n}\n",
        )
        self.assertFires("MLP002")

    def test_ignore_comment_silences_it(self) -> None:
        self.fx.write(
            "scripts/Cleanup.php",
            "<?php\nunlink($p); // mlp-lint: ignore MLP002\n",
        )
        self.assertQuiet("MLP002")

    def test_method_named_like_a_denied_function_is_not_flagged(self) -> None:
        self.fx.write(
            "scripts/Thing.php",
            "<?php\nclass Thing {\n    public function go() {\n"
            "        return $this->copy($a);\n    }\n}\n",
        )
        self.assertQuiet("MLP002")


class TestHtmlSinks(RuleTest):
    """MLP003 — stored XSS through the progress popup."""

    def test_concatenated_markup_fires(self) -> None:
        self.fx.write(
            "custom/modules/Quotes/clients/base/views/p/p.js",
            "({\n  show: function(message) {\n"
            "    this.$el.append('<b>' + message + '</b>');\n  },\n})\n",
        )
        self.assertFires("MLP003")

    def test_text_is_fine(self) -> None:
        self.fx.write(
            "custom/modules/Quotes/clients/base/views/p/p.js",
            "({\n  show: function(message) {\n"
            "    this.$el.text(message);\n  },\n})\n",
        )
        self.assertQuiet("MLP003")

    def test_static_markup_is_fine(self) -> None:
        self.fx.write(
            "custom/modules/Quotes/clients/base/views/p/p.js",
            "({\n  show: function() {\n"
            "    this.$el.append('<div class=\"row\"></div>');\n  },\n})\n",
        )
        self.assertQuiet("MLP003")


class TestHookGuards(RuleTest):
    """MLP004 — an exception in a hook fails somebody else's save."""

    MANIFEST = (
        "<?php\n$installdefs = array(\n  'logic_hooks' => array(\n"
        "    array('module' => 'Quotes', 'hook' => 'after_save',\n"
        "          'class' => 'Cascade', 'function' => 'run'),\n"
        "  ),\n);\n"
    )

    def test_unguarded_hook_fires(self) -> None:
        self.fx.write("manifest.php", self.MANIFEST)
        self.fx.write(
            "custom/modules/Quotes/Cascade.php",
            "<?php\nclass Cascade\n{\n    public function run($bean)\n    {\n"
            "        $bean->save();\n    }\n}\n",
        )
        self.assertFires("MLP004")

    def test_an_UNREGISTERED_method_of_the_same_NAME_is_not_a_hook(self) -> None:
        """G128 regression. The check used to do `wanted = {m for _, m in pairs}`
        and match on the METHOD NAME alone, so any same-named method in any
        class in the package was reported as an unguarded hook.

        Live cost: absorbing the quantity-break ladder gave ERP-Epicor a hook
        registered as `apply`, and the linter then raised two REQUIRED findings
        against ERP-Core's PRIVATE ErpEstimatingStamps::apply and
        ErpNativeShippingMirror::apply. Neither is registered -- the fragments
        name stamp() and mirror(), and both of those already catch Throwable.
        Two false REQUIRED findings, enough to block a merge.
        """
        self.fx.write("manifest.php", self.MANIFEST)
        self.fx.write(
            "custom/modules/Quotes/Cascade.php",
            "<?php\nclass Cascade\n{\n    public function run($bean)\n    {\n"
            "        try {\n            $bean->save();\n"
            "        } catch (\\Throwable $e) {\n"
            "            $GLOBALS['log']->error($e->getMessage());\n        }\n"
            "    }\n}\n",
        )
        # Same method name, DIFFERENT class, never registered anywhere.
        self.fx.write(
            "custom/modules/Quotes/Bystander.php",
            "<?php\nclass Bystander\n{\n    private function run($bean)\n    {\n"
            "        $bean->touch();\n    }\n}\n",
        )
        self.assertQuiet("MLP004")

    def test_guarded_hook_is_fine(self) -> None:
        self.fx.write("manifest.php", self.MANIFEST)
        self.fx.write(
            "custom/modules/Quotes/Cascade.php",
            "<?php\nclass Cascade\n{\n    public function run($bean)\n    {\n"
            "        try {\n            $bean->save();\n"
            "        } catch (\\Throwable $e) {\n"
            "            $GLOBALS['log']->error($e->getMessage());\n        }\n"
            "    }\n}\n",
        )
        self.assertQuiet("MLP004")

    def test_unregistered_method_is_not_checked(self) -> None:
        self.fx.write("manifest.php", self.MANIFEST)
        self.fx.write(
            "custom/modules/Quotes/Other.php",
            "<?php\nclass Other\n{\n    public function helper($bean)\n    {\n"
            "        $bean->save();\n    }\n}\n",
        )
        self.assertQuiet("MLP004")


class TestSqlConcat(RuleTest):
    """MLP005 — the pattern this repo's standards forbid."""

    def test_concatenated_delete_fires(self) -> None:
        self.fx.write(
            "scripts/Reconcile.php",
            "<?php\n$sql = 'DELETE FROM dashboards WHERE id IN (' . $ids . ')';\n",
        )
        self.assertFires("MLP005")

    def test_interpolated_select_fires(self) -> None:
        self.fx.write(
            "scripts/Reconcile.php",
            '<?php\n$sql = "SELECT id FROM dashboards WHERE name = {$name}";\n',
        )
        self.assertFires("MLP005")

    def test_static_sql_is_fine(self) -> None:
        self.fx.write(
            "scripts/Reconcile.php",
            "<?php\n$sql = 'SELECT id FROM dashboards WHERE deleted = 1';\n",
        )
        self.assertQuiet("MLP005")


class TestConfigAdminGate(RuleTest):
    """MLP006 — the orchestrator token exposed to every logged-in user."""

    def test_ungated_getter_fires(self) -> None:
        self.fx.write(
            "custom/clients/base/api/ErpIntegration_Api.php",
            "<?php\nclass ErpIntegration_Api\n{\n"
            "    public function getConfig($api, $args)\n    {\n"
            "        return $this->settings();\n    }\n}\n",
        )
        self.assertFires("MLP006")

    def test_gated_getter_is_fine(self) -> None:
        self.fx.write(
            "custom/clients/base/api/ErpIntegration_Api.php",
            "<?php\nclass ErpIntegration_Api\n{\n"
            "    public function getConfig($api, $args)\n    {\n"
            "        if (!$api->user->isAdmin()) {\n"
            "            throw new SugarApiExceptionNotAuthorized();\n        }\n"
            "        return $this->settings();\n    }\n}\n",
        )
        self.assertQuiet("MLP006")

    def test_getter_outside_api_is_not_checked(self) -> None:
        self.fx.write(
            "custom/include/scripts/Helper.php",
            "<?php\nclass Helper\n{\n"
            "    public function getConfig()\n    {\n        return array();\n    }\n}\n",
        )
        self.assertQuiet("MLP006")


class TestSecretLogging(RuleTest):
    """MLP007 — the token written to sugarcrm.log in full."""

    def test_logged_token_fires(self) -> None:
        self.fx.write(
            "custom/clients/base/api/Cfg.php",
            "<?php\n$GLOBALS['log']->info(\"saving orchestrator_api_token: $value\");\n",
        )
        self.assertFires("MLP007")

    def test_masked_token_is_fine(self) -> None:
        self.fx.write(
            "custom/clients/base/api/Cfg.php",
            "<?php\n$GLOBALS['log']->info('token: ' . substr($value, -4));\n",
        )
        self.assertQuiet("MLP007")


class TestManifestClaims(RuleTest):
    """MLP008 — ERP_Quotes promised, never shipped."""

    def test_missing_bean_module_fires(self) -> None:
        self.fx.write(
            "manifest.php",
            "<?php\n$installdefs = array(\n  'beans' => array(\n"
            "    array('module' => 'ERP_Quotes', 'class' => 'ERP_Quotes',\n"
            "          'path' => 'modules/ERP_Quotes/ERP_Quotes.php'),\n"
            "  ),\n);\n",
        )
        self.assertFires("MLP008")

    def test_shipped_bean_module_is_fine(self) -> None:
        self.fx.write(
            "manifest.php",
            "<?php\n$installdefs = array(\n  'beans' => array(\n"
            "    array('module' => 'ERP_Orders', 'class' => 'ERP_Orders',\n"
            "          'path' => 'modules/ERP_Orders/ERP_Orders.php'),\n"
            "  ),\n);\n",
        )
        self.fx.write("modules/ERP_Orders/ERP_Orders.php", "<?php\nclass ERP_Orders {}\n")
        self.assertQuiet("MLP008")

    def test_description_naming_an_absent_module_fires(self) -> None:
        self.fx.write(
            "manifest.php",
            "<?php\n$manifest = array(\n"
            "  'description' => 'Adds ERP_Orders and ERP_QuoteCosts modules.',\n);\n",
        )
        self.fx.write("modules/ERP_Orders/ERP_Orders.php", "<?php\nclass ERP_Orders {}\n")
        hits = [f for f in self.fx.lint() if f.rule == "MLP008"]
        self.assertTrue(any("ERP_QuoteCosts" in f.message for f in hits))
        self.assertFalse(any("ERP_Orders" in f.message for f in hits))


class TestUndeclaredDependency(RuleTest):
    """MLP009 — requiring a sibling package's file with nothing declared."""

    def test_foreign_require_fires(self) -> None:
        self.fx.write("manifest.php", "<?php\n$manifest = array('version' => '1.0.0');\n")
        self.fx.write(
            "scripts/post_execute.php",
            "<?php\nrequire_once('custom/modules/ERP_Quotes/Hook.php');\n",
        )
        self.assertFires("MLP009")

    def test_own_file_is_fine(self) -> None:
        self.fx.write("manifest.php", "<?php\n$manifest = array('version' => '1.0.0');\n")
        self.fx.write("scripts/Modules/Layout.php", "<?php\nclass Layout {}\n")
        self.fx.write(
            "scripts/post_execute.php",
            "<?php\nrequire_once('custom/include/scripts/Modules/Layout.php');\n",
        )
        self.assertQuiet("MLP009")

    def test_declared_dependency_is_fine(self) -> None:
        self.fx.write(
            "manifest.php",
            "<?php\n$manifest = array(\n  'dependencies' => array(\n"
            "    array('id_name' => 'sugarai_erp_quotes', 'version' => '1.0'),\n"
            "  ),\n);\n",
        )
        self.fx.write(
            "scripts/post_execute.php",
            "<?php\nrequire_once('custom/modules/ERP_Quotes/Hook.php');\n",
        )
        self.assertQuiet("MLP009")


class TestAdvisories(RuleTest):
    def test_dangling_test_reference_fires(self) -> None:
        self.fx.write(
            "custom/modules/Quotes/Rollup.php",
            "<?php\n/**\n * @see tests/ErpQuoteLineRollupTest.php\n */\nclass Rollup {}\n",
        )
        self.assertFires("MLP010")

    def test_present_test_is_fine(self) -> None:
        self.fx.write(
            "custom/modules/Quotes/Rollup.php",
            "<?php\n/**\n * @see tests/RollupTest.php\n */\nclass Rollup {}\n",
        )
        self.fx.write("tests/RollupTest.php", "<?php\nclass RollupTest {}\n")
        self.assertQuiet("MLP010")

    def test_cited_test_is_not_reported_missing_when_linting_a_zip(self) -> None:
        # Tests are deliberately never shipped, so from inside an archive every
        # cited test looks absent and this rule reports correct behaviour as a
        # defect. It did exactly that to the Partial Fulfillment zip, which was
        # flagged for citing ErpQuoteLineRollupTest.php while that test sat
        # present and passing in the package's own tests/ directory. The source
        # pass can see both halves; the zip pass cannot, so it must stay quiet.
        self.fx.write(
            "custom/modules/Quotes/Rollup.php",
            "<?php\n/**\n * @see tests/ErpQuoteLineRollupTest.php\n */\nclass Rollup {}\n",
        )
        self.assertIn("MLP010", self.fx.rules(), "source pass should still fire")
        self.assertNotIn("MLP010", self.fx.zip_rules(), "zip pass must stay quiet")

    def test_short_comment_containing_braces_fires(self) -> None:
        # The defect this rule was written for, reproduced exactly. A {{! }}
        # comment explaining that other values are escaped mentions {{ }}, the
        # parser closes the comment there, and ". }}" is drawn on the page.
        self.fx.write(
            "badge.hbs",
            "{{! badgeStyle is unescaped on purpose. Every value below it\n"
            "    uses escaped {{ }}. }}\n"
            '<span style="{{{badgeStyle}}}">{{label}}</span>\n',
        )
        self.assertFires("MLP018")

    def test_long_form_comment_containing_braces_also_fires(self) -> None:
        # The first fix for this used the long form and shipped. The page then
        # read ". --}}" instead of ". }}", which is the parser closing the long
        # comment at the first }} inside it exactly as it does the short one.
        # So the long form is not a remedy here and must not be treated as one.
        self.fx.write(
            "badge.hbs",
            "{{!-- badgeStyle is unescaped on purpose. Every value below it\n"
            "    uses escaped {{ }}.\n--}}\n"
            '<span style="{{{badgeStyle}}}">{{label}}</span>\n',
        )
        self.assertFires("MLP018")

    def test_a_comment_with_no_braces_is_fine(self) -> None:
        self.fx.write(
            "badge.hbs",
            "{{! badgeStyle is unescaped on purpose. Every value below it is\n"
            "    escaped normally. No braces in this comment, deliberately. }}\n"
            '<span style="{{{badgeStyle}}}">{{label}}</span>\n',
        )
        self.assertQuiet("MLP018")

    def test_plain_short_comment_is_fine(self) -> None:
        # The short form is not wrong in itself, only when it carries braces.
        self.fx.write(
            "badge.hbs",
            "{{! badgeStyle comes from a fixed palette, never from a record. }}\n"
            '<span style="{{{badgeStyle}}}">{{label}}</span>\n',
        )
        self.assertQuiet("MLP018")

    def test_the_remedy_for_mlp011_does_not_recreate_the_defect(self) -> None:
        # MLP011's fix text is what produced six broken templates in this
        # repository: it asked for a {{! ... }} comment, and a comment about
        # escaping mentions braces. A remedy that recreates another rule's
        # defect is worse than no remedy, so the wording is pinned.
        self.fx.write("badge.hbs", '<span style="{{{style}}}"></span>\n')
        remedies = [f.remedy for f in self.fx.lint() if f.rule == "MLP011"]
        self.assertTrue(remedies, "MLP011 should have fired")
        for remedy in remedies:
            self.assertIn("{{!--", remedy)

    def test_triple_mustache_without_comment_fires(self) -> None:
        self.fx.write("badge.hbs", '<span style="{{{style}}}"></span>\n')
        self.assertFires("MLP011")

    def test_triple_mustache_with_comment_is_fine(self) -> None:
        self.fx.write(
            "badge.hbs",
            "{{! style comes from ErpTone's fixed hex table, never record data }}\n"
            '<span style="{{{style}}}"></span>\n',
        )
        self.assertQuiet("MLP011")

    def test_pii_logged_at_info_fires(self) -> None:
        self.fx.write(
            "custom/clients/base/api/Base.php",
            "<?php\n$GLOBALS['log']->info('payload: ' . $account->billing_address_street);\n",
        )
        self.assertFires("MLP012")


class TestGeneratedFilesAreSkipped(RuleTest):
    """Studio output is the large majority of a real package."""

    def test_generated_file_is_not_style_reviewed(self) -> None:
        self.fx.write(
            "custom/Extension/modules/Quotes/Ext/Vardefs/f.php",
            "<?php\n// created: 2026-01-01 00:00:00\n"
            "$sql = 'DELETE FROM x WHERE id = ' . $id;\n",
        )
        self.assertQuiet("MLP005")


class TestRemoveTablesIsNeverFlagged(unittest.TestCase):
    """The one finding the Mango review says never to attribute to a package.

    The audit of sugarai_erp_epicor 1.0.96 flagged remove_tables => 'prompt'
    and should not have. This is a regression guard on that judgement, not a
    style preference.
    """

    def test_no_rule_mentions_remove_tables(self) -> None:
        for r in mlp_lint.RULES.values():
            self.assertNotIn("remove_tables", r.explanation)
            self.assertNotIn("remove_tables", r.title)

    def test_prompt_manifest_is_clean(self) -> None:
        fx = Fixture()
        self.addCleanup(fx.close)
        fx.write(
            "manifest.php",
            "<?php\n$manifest = array(\n  'version' => '1.0.0',\n"
            "  'remove_tables' => 'prompt',\n);\n",
        )
        self.assertEqual(fx.rules(), [])


class TestFalsePositivesThisLinterAlreadyHad(RuleTest):
    """Every case here was a real finding on the first run against this repo.

    The first run reported 178 findings, of which 156 were wrong. Each of
    those mistakes had the same shape: a pattern that reads like code but is
    not. They are guarded individually because a linter earns its place by
    being quiet about correct code, and each of these would have sent somebody
    to read a file that was already fine.
    """

    def test_a_docblock_naming_denied_functions_is_not_a_blocker(self) -> None:
        # ERP-Epicor documents the denylist in prose, in several places. Ten of
        # the first run's blockers were this comment.
        self.fx.write(
            "scripts/Notes.php",
            "<?php\n/**\n * unlink(), glob(), is_dir(), rmdir(), touch(), fopen()\n"
            " * and file_put_contents() are all on ModuleScanner's denylist.\n */\n"
            "class Notes {}\n",
        )
        self.assertQuiet("MLP002")

    def test_a_method_named_after_a_denied_function_is_not_a_call(self) -> None:
        # ImpexApi declares `private function eval(...)`.
        self.fx.write(
            "custom/src/wsystems/Impex/Formula.php",
            "<?php\nclass Formula\n{\n"
            "    private function eval($formula, $bean) : mixed\n    {\n"
            "        return null;\n    }\n}\n",
        )
        self.assertQuiet("MLP002")

    def test_requiring_sugars_own_tree_is_not_a_missing_dependency(self) -> None:
        # Reaching for DeployedMetaDataImplementation is how a package is
        # supposed to talk to the platform. 17 of the first run's findings
        # were install scripts doing exactly that.
        self.fx.write("manifest.php", "<?php\n$manifest = array('version' => '1.0.0');\n")
        self.fx.write(
            "scripts/Layout.php",
            "<?php\nrequire_once('modules/ModuleBuilder/parsers/views/"
            "DeployedMetaDataImplementation.php');\n"
            "require_once('include/SugarQuery/SugarQuery.php');\n",
        )
        self.assertQuiet("MLP009")

    def test_vendored_sql_is_not_style_reviewed_but_is_still_gated(self) -> None:
        # PS-Impex-Seed-Loader vendors a library whose job is generating SQL.
        self.fx.write(
            "custom/src/wsystems/Impex/Gen.php",
            "<?php\n$sql = 'INSERT INTO x VALUES (' . $v . ')';\nunlink($p);\n",
        )
        rules = self.fx.rules()
        self.assertNotIn("MLP005", rules, "vendored SQL should not be style-reviewed")
        self.assertIn("MLP002", rules, "a denied call still rejects the upload")

    def test_build_tooling_is_not_part_of_the_package(self) -> None:
        # pack.php calls mkdir/glob/file_get_contents and is never in the zip.
        self.fx.write(
            "pack.php",
            "<?php\nmkdir($dir);\n$files = glob($dir . '/*');\n"
            "$m = file_get_contents('manifest.php');\n",
        )
        self.assertQuiet("MLP002")

    def test_a_test_file_cannot_collide_with_an_install_script(self) -> None:
        # tests/ never runs during an install, so a class it includes can
        # never be the second copy in one request.
        self.fx.write("custom/modules/Quotes/Rollup.php", "<?php\nclass Rollup {}\n")
        self.fx.write(
            "custom/modules/Quotes/Uses.php",
            "<?php\nrequire_once('custom/modules/Quotes/Rollup.php');\n",
        )
        self.fx.write(
            "tests/RollupTest.php",
            "<?php\nrequire_once __DIR__ . '/../custom/modules/Quotes/Rollup.php';\n",
        )
        self.assertQuiet("MLP001")


class TestDuplicateDestinations(RuleTest):
    """MLP013 — exact path repetition, not directory coincidence."""

    def test_one_path_named_twice_fires(self) -> None:
        self.fx.write(
            "manifest.php",
            "<?php\n$installdefs = array(\n"
            "  'copy' => array(\n"
            "    array('from' => '<basepath>/p.php',\n"
            "          'to' => 'custom/Extension/application/Ext/Platforms/p.php'),\n"
            "  ),\n"
            "  'platforms' => array(\n"
            "    array('from' => '<basepath>/p.php',\n"
            "          'to' => 'custom/Extension/application/Ext/Platforms/p.php'),\n"
            "  ),\n);\n",
        )
        self.assertFires("MLP013")

    def test_different_files_in_one_ext_directory_are_fine(self) -> None:
        # The bug this guards: an earlier version matched a copy into an
        # Ext/Language/ directory against the unrelated `language` section and
        # reported 129 findings on one real manifest.
        self.fx.write(
            "manifest.php",
            "<?php\n$installdefs = array(\n"
            "  'copy' => array(\n"
            "    array('from' => '<basepath>/a.php',\n"
            "          'to' => 'custom/Extension/modules/Quotes/Ext/Language/a.php'),\n"
            "  ),\n"
            "  'language' => array(\n"
            "    array('from' => '<basepath>/b.php', 'to_module' => 'Quotes',\n"
            "          'language' => 'en_us'),\n"
            "  ),\n);\n",
        )
        self.assertQuiet("MLP013")


class TestDynamicDispatch(RuleTest):
    """MLP017 — what ModuleScanner calls a dynamically-named call.

    The reason this rule matters beyond the upload gate: it is what makes the
    obvious refactor unavailable. A trait whose method takes a callable and
    invokes it, to share a try/catch across classes, cannot ship.
    """

    def test_dynamic_instantiation_fires(self) -> None:
        self.fx.write(
            "scripts/pre_uninstall.php",
            "<?php\nforeach ($steps as $class) {\n"
            "    (new $class())->uninstall();\n}\n",
        )
        self.assertFires("MLP017")

    def test_dynamic_method_call_fires(self) -> None:
        self.fx.write(
            "scripts/post_install.php",
            "<?php\n$obj->$method($bean);\n",
        )
        self.assertFires("MLP017")

    def test_brace_dynamic_method_call_fires(self) -> None:
        # The exact shape SugarCloud rejected in a shipped hook.
        self.fx.write(
            "custom/modules/Quotes/Hook.php",
            "<?php\nclass Hook {\n    private function log($level, $message) {\n"
            "        $GLOBALS['log']->{$level}($message);\n    }\n}\n",
        )
        self.assertFires("MLP017")

    def test_dynamic_static_method_call_fires(self) -> None:
        self.fx.write(
            "scripts/post_install.php",
            "<?php\nInstaller::$step($bean);\nInstaller::{$other}($bean);\n",
        )
        self.assertFires("MLP017")

    def test_brace_property_read_is_fine(self) -> None:
        self.fx.write(
            "custom/modules/Quotes/Hook.php",
            "<?php\nclass Hook {\n    public function read($bean, $field) {\n"
            "        return trim((string) ($bean->{$field} ?? ''));\n    }\n}\n",
        )
        self.assertQuiet("MLP017")

    def test_calling_a_closure_fires(self) -> None:
        # The trait-with-callable shape, which is why it cannot be used here.
        self.fx.write(
            "custom/include/scripts/Guard.php",
            "<?php\ntrait Guard {\n"
            "    private function guarded(callable $body) {\n"
            "        $body();\n    }\n}\n",
        )
        self.assertFires("MLP017")

    def test_longhand_instantiation_is_fine(self) -> None:
        self.fx.write(
            "scripts/pre_uninstall.php",
            "<?php\n(new InstallDashlets())->uninstall();\n"
            "(new InstallJobs())->uninstall();\n",
        )
        self.assertQuiet("MLP017")

    def test_a_normal_method_call_is_fine(self) -> None:
        self.fx.write(
            "custom/modules/Quotes/Thing.php",
            "<?php\nclass Thing {\n    public function go() {\n"
            "        return $this->helper($a, $b);\n    }\n}\n",
        )
        self.assertQuiet("MLP017")


class TestUninstallProtocol(RuleTest):
    """MLP015 and MLP016 — a package has to be removable."""

    def test_installer_without_uninstall_fires(self) -> None:
        self.fx.write(
            "custom/src/Setup/InstallDashboards.php",
            "<?php\nclass InstallDashboards {\n"
            "    public function install(): void {}\n}\n",
        )
        self.assertFires("MLP015")

    def test_installer_with_uninstall_is_fine(self) -> None:
        self.fx.write(
            "custom/src/Setup/InstallDashboards.php",
            "<?php\nclass InstallDashboards {\n"
            "    public function install(): void {}\n"
            "    public function uninstall(): void {}\n}\n",
        )
        self.assertQuiet("MLP015")

    def test_permanently_uninstallable_package_fires(self) -> None:
        self.fx.write(
            "manifest.php",
            "<?php\n$manifest = array(\n  'version' => '1.0.0',\n"
            "  'is_uninstallable' => false,\n);\n",
        )
        self.assertFires("MLP016")

    def test_uninstallable_package_is_fine(self) -> None:
        self.fx.write(
            "manifest.php",
            "<?php\n$manifest = array(\n  'version' => '1.0.0',\n"
            "  'is_uninstallable' => true,\n);\n",
        )
        self.assertQuiet("MLP016")


class TestMetadataParserUsage(RuleTest):
    """MLP019 — the hosted Rector refusal that cost ERP-Epicor rc8 to rc11.2.

    The fixtures are the exact shapes a SugarCloud tenant rejected with
    `Class "AbstractMetaDataImplementation" not found`.
    """

    def test_class_exists_on_a_parser_class_fires(self) -> None:
        # rc11's BaseErpLayout constructor, line 19 of the refused report.
        self.fx.write(
            "scripts/BaseErpLayout.php",
            "<?php\nabstract class BaseErpLayout\n{\n"
            "    public function __construct()\n    {\n"
            "        if (!class_exists('DeployedMetaDataImplementation')) {\n"
            "            throw new RuntimeException('unavailable');\n        }\n"
            "    }\n}\n",
        )
        self.assertFires("MLP019")

    def test_instantiating_a_parser_fires(self) -> None:
        self.fx.write(
            "scripts/Modules/AccountsLayout.php",
            "<?php\n$deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, 'Accounts', 'base');\n",
        )
        self.assertFires("MLP019")

    def test_literal_parser_include_fires(self) -> None:
        # Bench Dogs' layout helpers, and rc8 to rc10's guarded includes.
        self.fx.write(
            "custom/modules/Quotes/BdQuotesLayoutExtensions.php",
            "<?php\nrequire_once 'modules/ModuleBuilder/parsers/views/"
            "AbstractMetaDataImplementation.php';\n",
        )
        self.assertFires("MLP019")

    def test_subpanel_parser_fires(self) -> None:
        self.fx.write(
            "scripts/Modules/OpportunitiesLayout.php",
            "<?php\n$deploy = new DeployedSidecarSubpanelImplementation('quotes', 'Opportunities', 'base');\n",
        )
        self.assertFires("MLP019")

    def test_it_is_a_blocker(self) -> None:
        self.fx.write("scripts/x.php", "<?php\nParserFactory::getParser('recordview', 'Accounts');\n")
        found = [f for f in self.fx.lint() if f.rule == "MLP019"]
        self.assertTrue(found)
        self.assertEqual(found[0].severity, mlp_lint.BLOCKER)

    def test_viewdef_manager_is_fine(self) -> None:
        self.fx.write(
            "scripts/BaseErpLayout.php",
            "<?php\nuse Sugarcrm\\Sugarcrm\\MetaData\\ViewdefManager;\n"
            "$defs = (new ViewdefManager())->loadViewdef('base', 'Accounts', 'record');\n",
        )
        self.assertQuiet("MLP019")

    def test_a_comment_naming_the_class_is_fine(self) -> None:
        self.fx.write(
            "scripts/BaseErpLayout.php",
            "<?php\n// Never use DeployedMetaDataImplementation here (MLP019).\n"
            "/* ParserFactory is refused by hosted Rector. */\n$ok = true;\n",
        )
        self.assertQuiet("MLP019")

    def test_ignore_comment_silences_it(self) -> None:
        self.fx.write(
            "scripts/x.php",
            "<?php\n// mlp-lint: ignore MLP019\n"
            "$deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, 'Accounts', 'base');\n",
        )
        self.assertQuiet("MLP019")


class TestRuleMetadata(unittest.TestCase):
    def test_every_rule_has_an_origin_and_explanation(self) -> None:
        self.assertTrue(mlp_lint.RULES)
        for ident, r in mlp_lint.RULES.items():
            self.assertTrue(r.origin, f"{ident} has no origin")
            self.assertTrue(r.title, f"{ident} has no title")
            self.assertGreater(len(r.explanation), 80, f"{ident} barely explains itself")
            self.assertIn(r.severity, (mlp_lint.BLOCKER, mlp_lint.REQUIRED, mlp_lint.ADVISORY))

    def test_explain_returns_zero_for_a_known_rule(self) -> None:
        self.assertEqual(mlp_lint.main(["--explain", "MLP001"]), 0)

    def test_explain_rejects_an_unknown_rule(self) -> None:
        self.assertEqual(mlp_lint.main(["--explain", "MLP999"]), 2)


class TestMd5FileIsNeverShipped(unittest.TestCase):
    """MLP014, which this repository once had backwards.

    The rule used to report the ABSENCE of files.md5 as something worth fixing.
    Acting on that added one to all thirteen archives here and made every one
    of them unloadable: ModuleScanner checks each shipped file's extension
    against an allow-list, `md5` is not on it, and SugarCloud refuses the
    package with "File Issues / files.md5 / Invalid file extension".

    So the assertion is pinned in both directions. A zip carrying the file must
    be a blocker, and a zip without it must be silent. If anyone ever flips this
    back, this test is what stops it reaching a customer.
    """

    def _zip(self, names: dict[str, str]) -> Path:
        tmp = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, tmp, True)
        path = Path(tmp) / "pkg-1.0.0.zip"
        with zipfile.ZipFile(path, "w") as zf:
            for name, body in names.items():
                zf.writestr(name, body)
        return path

    MANIFEST = (
        "<?php\n$manifest = array('key' => 'p', 'version' => '1.0.0',\n"
        "  'type' => 'module', 'acceptable_sugar_versions' => array('26.*'));\n"
        "$installdefs = array('id' => 'p');\n"
    )

    def test_a_shipped_md5_file_is_a_blocker(self) -> None:
        path = self._zip({
            "manifest.php": self.MANIFEST,
            "files.md5": "<?php\n$md5_string = array();\n",
        })
        _pkg, findings = mlp_lint.lint_zip(path)
        blockers = [f for f in findings if f.rule == "MLP014"]
        self.assertEqual(len(blockers), 1, "shipping files.md5 must be reported")
        self.assertEqual(blockers[0].severity, mlp_lint.BLOCKER)

    def test_a_package_without_one_is_silent(self) -> None:
        path = self._zip({"manifest.php": self.MANIFEST})
        _pkg, findings = mlp_lint.lint_zip(path)
        self.assertEqual(
            [f for f in findings if f.rule == "MLP014"], [],
            "the absence of files.md5 is correct and must not be reported",
        )


# ---------------------------------------------------------------------------
# G222. The scanner's deny-lists, in --zip mode, matched the way it matches.
#
# ERP-Epicor 1.1.100 linted 0/0/0 and SugarCloud refused it for
# stream_resolve_include_path, which is on ModuleScanner's
# $unsafeHttpClientFunctions: a second list, merged into $blackList by
# EnhancedModuleChecks, that this linter had never carried. Every verdict below
# about what the scanner does and does not flag - comments, strings, methods,
# namespaced names - was taken from the real scanner (scanner_oracle.php), not
# assumed.
# ---------------------------------------------------------------------------

HERE = Path(__file__).resolve().parent
MLP002_FIXTURES = HERE / "fixtures" / "mlp002"
SCANNER_ORACLE = HERE / "scanner_oracle.php"


def _sugar_roots() -> list:
    """SugarEnt source trees available on this machine, for the live checks."""
    env = os.environ.get("MLP_LINT_SUGAR_ROOT")
    candidates = [Path(env)] if env else [
        Path.home() / "Documents" / "Code" / "SugarEnt-Full-26.1.0",
        Path.home() / "Documents" / "Code" / "SugarEnt-Full-25.2.0",
    ]
    return [c for c in candidates if (c / "ModuleInstall" / "ModuleScanner.php").is_file()]


_ORACLE_KINDS = [
    (re.compile(r'call denylisted function "([^"]+)"'), "function"),
    (re.compile(r"use eval\(\)"), "eval"),
    (re.compile(r'instantiate denylisted class "([^"]+)"'), "class_new"),
    (re.compile(r'extend denylisted class "([^"]+)"'), "class_extends"),
    (re.compile(r'call denylisted method "([^"]+)"'), "method"),
    (re.compile(r'call denylisted static method "([^"]+)"'), "static"),
    (re.compile(r"execute command via shell"), "shell_exec"),
    (re.compile(r"halt compiler"), "halt_compiler"),
]


def _key(line: int, kind: str, name: str) -> tuple:
    """One hit, reduced to what both the scanner and MLP002 can agree on."""
    name = (name or "").lower().split("\\")[-1]
    if kind == "unsafe_function":
        kind = "function"
    if kind == "static":
        kind = "class_method" if "::" in name else "method"
    if kind == "class_method":
        name = name.split("::")[-1]
    if kind in ("eval", "shell_exec", "halt_compiler"):
        name = ""
    return (line, kind, name)


def _oracle_keys(issues) -> set:
    """The deny-list issues among a scanner run's (line, message) pairs."""
    out = set()
    for line, msg in issues:
        for rx, kind in _ORACLE_KINDS:
            m = rx.search(msg)
            if m:
                out.add(_key(line, kind, m.group(1) if m.groups() else ""))
                break
    return out


def _linter_keys(text: str) -> set:
    return {_key(*hit) for hit in mlp_lint.scanner_denylist_hits(text)}


class _ZipCase(unittest.TestCase):
    """Builds a package zip and lints it the way `--zip` does."""

    MANIFEST = (
        "<?php\n$manifest = array('key' => 'p', 'version' => '1.0.0',\n"
        "  'type' => 'module', 'acceptable_sugar_versions' => array('26.*'));\n"
        "$installdefs = array('id' => 'p');\n"
    )
    PATH = "src/custom/clients/base/api/ProbeApi.php"

    def make_zip(self, files: dict) -> Path:
        tmp = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, tmp, True)
        path = Path(tmp) / "pkg-1.0.0.zip"
        with zipfile.ZipFile(path, "w") as zf:
            zf.writestr("manifest.php", self.MANIFEST)
            for name, body in files.items():
                zf.writestr(name, body)
        return path

    def zip_findings(self, php: str, rule: str = "MLP002") -> list:
        _pkg, findings = mlp_lint.lint_zip(self.make_zip({self.PATH: php}))
        return [f for f in findings if f.rule == rule]

    def assertFiresAt(self, php: str, lines: list) -> None:
        got = self.zip_findings(php)
        self.assertEqual(
            sorted(f.line for f in got), sorted(lines),
            "MLP002 should fire on exactly these lines:\n" + php,
        )
        for f in got:
            self.assertEqual(f.severity, mlp_lint.BLOCKER)
            self.assertEqual(f.path, self.PATH)

    def assertQuiet(self, php: str) -> None:
        got = self.zip_findings(php)
        self.assertEqual(
            got, [], "the real scanner does not flag this, so MLP002 must not:\n" + php
        )


class TestUnsafeHttpClientFunctionsInAZip(_ZipCase):
    """The G222 defect itself, in the mode that let it through."""

    def test_the_shape_sugarcloud_refused_is_a_blocker(self) -> None:
        php = (MLP002_FIXTURES / "g222_refused_shape.php").read_text(encoding="utf-8")
        got = self.zip_findings(php)
        self.assertEqual(len(got), 1, [f"{f.path}:{f.line} {f.message}" for f in got])
        f = got[0]
        self.assertEqual((f.path, f.line, f.severity), (self.PATH, 13, mlp_lint.BLOCKER))
        self.assertIn("stream_resolve_include_path", f.message)
        self.assertIn("$unsafeHttpClientFunctions", f.message)

    def test_the_fixed_package_is_clean(self) -> None:
        # What 373ef0f did: the fallback became a literal include.
        php = (MLP002_FIXTURES / "g222_refused_shape.php").read_text(encoding="utf-8")
        php = php.replace(
            ": stream_resolve_include_path('custom/modules/Quotes/ErpQuoteCommentQueue.php');",
            ": 'custom/modules/Quotes/ErpQuoteCommentQueue.php';",
        )
        _pkg, findings = mlp_lint.lint_zip(self.make_zip({self.PATH: php}))
        self.assertEqual([f for f in findings if f.severity == mlp_lint.BLOCKER], [])

    def test_the_cli_exits_nonzero_on_it(self) -> None:
        php = "<?php\n$p = stream_resolve_include_path('x');\n"
        self.assertEqual(mlp_lint.main(["--zip", str(self.make_zip({self.PATH: php}))]), 1)

    def test_a_clean_zip_stays_clean(self) -> None:
        php = "<?php\nclass ProbeApi\n{\n    public function go()\n    {\n        return 1;\n    }\n}\n"
        _pkg, findings = mlp_lint.lint_zip(self.make_zip({self.PATH: php}))
        self.assertEqual(findings, [])

    def test_every_family_on_the_list_fires(self) -> None:
        for fn in ("curl_init", "curl_exec", "socket_create", "fsockopen",
                   "pfsockopen", "stream_context_create", "stream_socket_client",
                   "stream_get_contents", "stream_resolve_include_path"):
            with self.subTest(fn=fn):
                self.assertFiresAt(f"<?php\n$x = {fn}($a);\n", [2])

    # --- what the real scanner does NOT flag (verified with scanner_oracle.php)

    def test_a_comment_naming_it_is_quiet(self) -> None:
        self.assertQuiet(
            "<?php\n// stream_resolve_include_path('x');\n# curl_init();\n"
            "/* fsockopen('h', 80); */\n/**\n * socket_create(1, 2, 3)\n */\n$a = 1;\n"
        )

    def test_a_string_naming_it_is_quiet(self) -> None:
        self.assertQuiet(
            "<?php\n$a = 'stream_resolve_include_path(\"x\")';\n"
            "$b = \"curl_exec($ch)\";\n"
            "$c = <<<EOT\nstream_get_contents(\\$fp)\nEOT;\n"
            "$d = <<<'EOT'\nfsockopen('h')\nEOT;\n"
            "$e = function_exists('curl_init');\n"
        )

    def test_a_method_or_a_namespaced_function_of_that_name_is_quiet(self) -> None:
        self.assertQuiet(
            "<?php\nnamespace Acme;\n$a = $obj->stream_get_contents();\n"
            "$b = Foo::curl_init();\n$c = Sub\\curl_init();\n$d = $o?->curl_init();\n"
            "function socket_create() {}\n$e = new stream_filter();\n$f = CURL_INIT;\n"
            # A qualified name whose FIRST segment is denied names a function
            # in namespace `file`, not file(). Scanner: quiet on all three.
            "$g = file\\helper();\n$h = \\get\\thing();\n$i = namespace\\curl_init();\n"
        )

    def test_an_attribute_named_like_one_is_quiet(self) -> None:
        # `get` is on $blackList; an attribute's name is a class, not a call.
        self.assertQuiet("<?php\n#[Get('/x'), Stream_is_local('y')]\nfunction f() {}\n")

    # --- what the real scanner DOES flag that a per-line regex did not

    def test_forms_the_scanner_catches(self) -> None:
        cases = {
            "fully qualified": ("<?php\n$a = \\curl_init();\n", [2]),
            "upper case": ("<?php\n$a = CURL_INIT();\n", [2]),
            "( on the next line": ("<?php\n$a = stream_resolve_include_path\n    ('x');\n", [2]),
            "after a URL string": ("<?php\n$u = 'https://x'; $c = curl_init($u);\n", [2]),
            "after a '/*' string": ("<?php\n$g = '/*.php';\n$s = socket_create(1, 2, 3);\n", [3]),
            "after a '#' string": ("<?php\n$h = '#'; $c = curl_multi_init();\n", [2]),
            "inside {$...}": ("<?php\n$s = \"x {$o->m(stream_is_local('y'))}\";\n", [2]),
            "in a heredoc's {$...}": ("<?php\n$s = <<<EOT\n{$o->m(fsockopen('h'))}\nEOT;\n", [3]),
            "first-class callable": ("<?php\n$f = stream_get_meta_data(...);\n", [2]),
            "string as the callee": ("<?php\n$f = 'fsockopen'('h');\n", [2]),
            "in an arrow fn": ("<?php\n$f = fn() => socket_close($s);\n", [2]),
            "after ?> and <?=": ("<?php $a = 1; ?>\nfsockopen() is html\n<?= curl_init() ?>\n", [3]),
            "imported with use function": ("<?php\nnamespace A;\nuse function curl_init;\ncurl_init();\n", [4]),
        }
        for label, (php, lines) in cases.items():
            with self.subTest(label):
                self.assertFiresAt(php, lines)

    def test_a_use_function_alias_to_another_function_is_quiet(self) -> None:
        self.assertQuiet(
            "<?php\nnamespace A;\nuse function Other\\curl_init as myinit;\n"
            "use function Other\\{fsockopen};\n$a = myinit();\n$b = fsockopen('h');\n"
        )

    def test_a_generated_file_is_not_exempt(self) -> None:
        # The scanner skips nothing; a Studio header does not make a call safe.
        self.assertFiresAt("<?php\n// created: 2026-09-21 10:00:00\n$a = curl_init();\n", [3])


class TestScannerClassAndMethodDenylists(_ZipCase):
    """$classBlackList, SecureSmarty's additions and $methodsBlackList."""

    def test_denied_classes_and_methods_fire(self) -> None:
        cases = {
            "new \\ZipArchive": "<?php\nnamespace A;\n$z = new \\ZipArchive();\n",
            "new ZipArchive, global": "<?php\n$z = new ZipArchive();\n",
            "imported class": "<?php\nnamespace A;\nuse ZipArchive;\n$z = new ZipArchive();\n",
            "extends Smarty (SecureSmarty)": "<?php\nclass V extends \\Smarty {}\n",
            "new Sugar_Smarty": "<?php\n$s = new Sugar_Smarty();\n",
            "namespaced Filesystem": "<?php\n$f = new \\Symfony\\Component\\Filesystem\\Filesystem();\n",
            "->setLevel()": "<?php\n$log->setLevel('fatal');\n",
            "->unserialize()": "<?php\n$x = $s->unserialize($v);\n",
            "$cls::setLevel()": "<?php\n$cls::setLevel(1);\n",
            "SugarAutoLoader::put": "<?php\n\\SugarAutoLoader::put('a', 'b');\n",
            "SugarMin::minify": "<?php\nSugarMin::minify('x');\n",
            "eval": "<?php\neval('1;');\n",
            "backticks": "<?php\n$x = `ls`;\n",
        }
        for label, php in cases.items():
            with self.subTest(label):
                self.assertEqual(len(self.zip_findings(php)), 1, php)

    def test_look_alikes_the_scanner_lets_through_stay_quiet(self) -> None:
        cases = {
            "unimported class in a namespace": "<?php\nnamespace A;\n$z = new ZipArchive();\n",
            "interface extends": "<?php\ninterface I extends Reflector {}\n",
            "nullsafe ->setLevel()": "<?php\n$log?->setLevel('fatal');\n",
            "->put() on an object": "<?php\n$cache->put('a', 'b');\n",
            "put() on another class": "<?php\nCache::put('a', 'b');\n",
            "unserialize() as a function": "<?php\n$x = unserialize($v);\n",
            "::class constant": "<?php\n$c = \\ZipArchive::class;\n",
        }
        for label, php in cases.items():
            with self.subTest(label):
                self.assertQuiet(php)


class TestScannerListsArePinned(unittest.TestCase):
    """Sentinels for CI, which has no Sugar tree to re-sync against."""

    def test_the_second_list_is_carried_and_merged(self) -> None:
        self.assertEqual(len(mlp_lint.SCANNER_UNSAFE_HTTP_CLIENT_FUNCTIONS), 100)
        self.assertEqual(len(mlp_lint.SCANNER_BLACKLIST), 250)
        self.assertEqual(
            mlp_lint.DENIED_FUNCTIONS,
            mlp_lint.SCANNER_BLACKLIST | mlp_lint.SCANNER_UNSAFE_HTTP_CLIENT_FUNCTIONS,
        )
        self.assertLessEqual(
            {"stream_resolve_include_path", "curl_init", "curl_exec", "fsockopen",
             "pfsockopen", "socket_create", "stream_context_create"},
            mlp_lint.DENIED_FUNCTIONS,
        )

    def test_nothing_the_scanner_denies_is_called_advisory(self) -> None:
        self.assertEqual(mlp_lint.DENIED_ADVISORY_ONLY & mlp_lint.DENIED_FUNCTIONS, set())
        self.assertNotIn("curl_exec", mlp_lint.DENIED_ADVISORY_ONLY)

    def test_class_and_method_lists(self) -> None:
        self.assertEqual(len(mlp_lint.SCANNER_CLASS_BLACKLIST), 27)
        self.assertEqual(
            mlp_lint.SCANNER_SECURE_SMARTY_CLASSES, {"smarty", "sugar_smarty", "sugarpdfsmarty"}
        )
        self.assertEqual(
            mlp_lint.SCANNER_METHOD_BLACKLIST, {"setlevel", "openuri", "unserialize", "extractto"}
        )
        self.assertEqual(set(mlp_lint.SCANNER_CLASS_METHOD_BLACKLIST), {"put", "unlink", "minify"})

    def test_every_list_names_where_it_came_from(self) -> None:
        for key in ("validExt", "classBlackList", "blackList", "unsafeHttpClientFunctions",
                    "methodsBlackList", "enhancedModuleChecksMerge", "secureSmartyMerge"):
            self.assertIn(key, mlp_lint.SCANNER_SOURCE)
        self.assertEqual(mlp_lint.SCANNER_SOURCE["unsafeHttpClientFunctions"], (428, 532))
        self.assertEqual(mlp_lint.SCANNER_SOURCE["enhancedModuleChecksMerge"], (610, 611))


class TestFileNamesTheScannerRefuses(_ZipCase):
    """MLP014 over $validExt, not just files.md5."""

    def mlp014(self, files: dict) -> list:
        _pkg, findings = mlp_lint.lint_zip(self.make_zip(files))
        return [f.path for f in findings if f.rule == "MLP014"]

    def test_an_extension_off_the_list_is_a_blocker(self) -> None:
        for name in ("icons/erp.svg", "x/.DS_Store", "README", "a/.gitkeep", "app.js.map"):
            with self.subTest(name=name):
                self.assertEqual(self.mlp014({name: "x"}), [name])

    def test_names_the_scanner_accepts_are_quiet(self) -> None:
        self.assertEqual(
            self.mlp014({"LICENSE": "x", "a/B.PHP": "<?php\n", "c.hbs": "", "d.json": "{}",
                         "e.less": "", "f.png": ""}),
            [],
        )


class TestFixturesMatchTheRealScannersVerdicts(unittest.TestCase):
    """expected.json was produced by the REAL scanner. MLP002 must agree with it.

    This is the half of the cross-check that runs in CI. The other half,
    TestAgainstTheRealScanner, re-derives expected.json wherever a Sugar tree
    exists, so the recording cannot quietly go stale.
    """

    def test_each_fixture(self) -> None:
        expected = json.loads((MLP002_FIXTURES / "expected.json").read_text())["issues"]
        self.assertGreaterEqual(len(expected), 5)
        for name, issues in expected.items():
            with self.subTest(fixture=name):
                text = (MLP002_FIXTURES / name).read_text(encoding="utf-8")
                self.assertEqual(_linter_keys(text), _oracle_keys(issues))

    def test_the_fixtures_are_not_trivial(self) -> None:
        # A fixture set the scanner finds nothing in proves nothing.
        expected = json.loads((MLP002_FIXTURES / "expected.json").read_text())["issues"]
        keys = set().union(*(_oracle_keys(v) for v in expected.values()))
        kinds = {k[1] for k in keys}
        self.assertGreaterEqual(len(keys), 40)
        self.assertLessEqual(
            {"function", "class_new", "class_extends", "method", "class_method",
             "shell_exec", "halt_compiler", "eval"},
            kinds,
        )


@unittest.skipUnless(_sugar_roots() and shutil.which("php"),
                     "needs a SugarEnt source tree and php; set MLP_LINT_SUGAR_ROOT")
class TestAgainstTheRealScanner(unittest.TestCase):
    """Runs Sugar's own scanner. Skipped in CI, which has neither."""

    def oracle(self, target: Path) -> list:
        r = subprocess.run(
            ["php", str(SCANNER_ORACLE), str(_sugar_roots()[0]), str(target.resolve())],
            capture_output=True, text=True, check=True,
        )
        return [json.loads(line) for line in r.stdout.splitlines()]

    def test_expected_json_is_what_the_scanner_says_today(self) -> None:
        recorded = json.loads((MLP002_FIXTURES / "expected.json").read_text())["issues"]
        fresh = {}
        for f in sorted(MLP002_FIXTURES.glob("*.php")):
            fresh[f.name] = sorted([d["line"], d["msg"]] for d in self.oracle(f))
        self.assertEqual(
            fresh, recorded,
            "the scanner's verdicts on the fixtures changed; fresh expectations:\n"
            + json.dumps(fresh, indent=1),
        )

    def test_mlp002_agrees_with_the_scanner_on_every_php_file_here(self) -> None:
        repo = HERE.parent.parent
        checked = 0
        for area in ("sugar-sell", "sugar-predict", "sugar-market", "sugar-discover"):
            base = repo / area
            if not base.is_dir():
                continue
            want: dict = {}
            for d in self.oracle(base):
                want.setdefault(d["file"], []).append((d["line"], d["msg"]))
            for f in sorted(base.rglob("*.php")):
                if "node_modules" in f.parts:
                    continue
                rel = str(f.relative_to(base))
                checked += 1
                ours = _linter_keys(f.read_text(encoding="utf-8", errors="replace"))
                theirs = _oracle_keys(want.get(rel, []))
                self.assertEqual(ours, theirs, f"{area}/{rel}: linter vs real scanner")
        self.assertGreater(checked, 12)  # Bench Dogs delta, declared in scripts/mlp_lint.PINNED.json

    def test_the_transcription_matches_the_source(self) -> None:
        sys.path.insert(0, str(HERE.parent))
        import regen_denylist
        for root in _sugar_roots():
            with self.subTest(root=root.name):
                self.assertEqual(regen_denylist.check(root), [])

    def test_the_check_notices_drift(self) -> None:
        sys.path.insert(0, str(HERE.parent))
        import regen_denylist
        real = _sugar_roots()[0] / "ModuleInstall" / "ModuleScanner.php"
        tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, tmp, True)
        (tmp / "ModuleInstall").mkdir()
        fake = tmp / "ModuleInstall" / "ModuleScanner.php"
        text = real.read_text()
        # A NEW name on the second list, line count unchanged.
        fake.write_text(text.replace("        //sockets\n", "        'socket_brand_new',\n", 1))
        problems = regen_denylist.check(tmp)
        self.assertTrue(any("socket_brand_new" in p for p in problems), problems)
        # A shifted array: the recorded line ranges must stop holding.
        fake.write_text(text.replace("class ModuleScanner\n", "// moved\nclass ModuleScanner\n", 1))
        problems = regen_denylist.check(tmp)
        self.assertTrue(any("unsafeHttpClientFunctions" in p and "SCANNER_SOURCE" in p
                            for p in problems), problems)


class TestShippedPackagesStayClean(unittest.TestCase):
    """The repository's own packages, as they stand.

    This is the test that keeps the linter honest in both directions: it must
    not fire on the fixed install scripts, and it must keep passing as the
    packages change. It asserts on blockers only, so an advisory backlog does
    not wedge the build.
    """

    def test_erp_epicor_install_scripts_are_guarded(self) -> None:
        repo = Path(__file__).resolve().parent.parent.parent
        pkg_dir = repo / "sugar-sell" / "ERP-Epicor"
        if not pkg_dir.is_dir():
            self.skipTest("ERP-Epicor not present in this checkout")
        findings = mlp_lint.lint_package(mlp_lint.load_package(pkg_dir))
        redeclare = [f for f in findings if f.rule == "MLP001"]
        self.assertEqual(
            redeclare, [],
            "ERP-Epicor has an unguarded two-path class include again: "
            + "; ".join(f"{f.path}:{f.line}" for f in redeclare),
        )


if __name__ == "__main__":
    unittest.main(verbosity=2)
