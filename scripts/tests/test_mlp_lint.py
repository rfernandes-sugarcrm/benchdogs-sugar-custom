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

import sys
import tempfile
import unittest
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

    def rules(self) -> list[str]:
        return [f.rule for f in self.lint()]

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
