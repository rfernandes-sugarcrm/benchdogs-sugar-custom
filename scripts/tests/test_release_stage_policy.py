"""Bench release-stage policy: identity-safe classification, no writes.

THE POLICY NOW READS NATIVE QUOTE LINES, AND HAS ONE OUTCOME. It used to walk the
`bd01_*` quote mirror to build a `line_num => 'prototype'|'production'` lookup and
could answer either `Prototype Ordered` (80) or `Partial Production Ordered` (90).
Decision 901/903 retired the mirror; the policy's own header records that the
lookup was ALREADY dead - the mirror's `prototype` boolean lost its writer at D16,
so every ordered line had been reading as production since then.

So `Prototype Ordered` is not a branch the code can reach any more, and the two
tests that asserted it (`test_prototype_only_release`,
`test_preloaded_erp_graph_cannot_hide_line_identity_or_prototype_role`) were
deleted rather than repointed. Restoring that milestone needs a real writer on the
native line, which is a decision about `custom/`, not a test that can be made to
pass here.

What remains is what the shipped `resolve()` actually decides:

  * any committed (`erp_ordered`) native line stages the Opportunity at
    `Partial Production Ordered` / 90;
  * nothing committed REFUSES rather than guessing a stage;
  * every line is re-read with `use_cache => false`, because Order Selected Lines
    loads `products` before it stamps the selected Product and a Link2 snapshot
    once showed `erp_ordered=false` after the order had actually succeeded;
  * the policy never writes - it classifies.
"""

from pathlib import Path
import json
import shutil
import subprocess
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
PROVIDER = (
    ROOT / "sugar-sell/BenchDogs-Ext/custom/modules/Quotes/ErpQuoteHooks"
    / "OpportunityReleaseStagePolicy.php"
)

ORDERED = {"sales_stage": "Partial Production Ordered", "probability": 90}

FIXTURE = r'''
#[AllowDynamicProperties]
class SugarBean {
    public $saves = 0;
    public function load_relationship($name) { return isset($this->$name); }
    public function save() { $this->saves++; }
}
class BeanFactory {
    public static $beans = [];
    public static $retrievals = [];
    public static function retrieveBean($module, $id, $options = []) {
        self::$retrievals[] = [$module, $id, $options];
        return self::$beans[$id] ?? null;
    }
}
class TestLink {
    public function __construct(public $beans = []) {
        foreach ($beans as $bean) {
            if (!empty($bean->id)) { BeanFactory::$beans[$bean->id] = $bean; }
        }
    }
    public function getBeans() { return $this->beans; }
    public function get() {
        return array_values(array_map(function ($bean) { return $bean->id; }, $this->beans));
    }
}
require '__PROVIDER__';
$make = function ($id, $ordered = false, $deleted = false) {
    $row = new SugarBean();
    $row->id = $id;
    $row->erp_ordered = $ordered;
    $row->deleted = $deleted;
    return $row;
};
$quote = new SugarBean();
$quote->id = 'owned-quote';
'''.replace("__PROVIDER__", PROVIDER.as_posix())


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class ReleaseStagePolicyTest(unittest.TestCase):
    def execute(self, scenario):
        result = subprocess.run(
            ["php", "-r", FIXTURE + scenario + r'''
try { $decision = (new ErpOpportunityReleaseStagePolicy())->resolve($quote); }
catch (UnexpectedValueException $e) { $error = $e->getMessage(); }
echo json_encode(['decision' => $decision ?? null, 'error' => $error ?? null,
    'quote_saves' => $quote->saves, 'retrievals' => BeanFactory::$retrievals]);
'''], cwd=ROOT, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def test_a_committed_line_stages_the_opportunity_and_writes_nothing(self):
        observed = self.execute(
            "$quote->products = new TestLink([$make('qli-production', true)]);"
        )
        self.assertEqual(observed["decision"], ORDERED, observed)
        self.assertEqual(observed["retrievals"], [
            ["Products", "qli-production", {"use_cache": False}],
        ])
        self.assertEqual(observed["quote_saves"], 0)

    def test_one_committed_line_among_uncommitted_ones_is_enough(self):
        observed = self.execute(r'''
$quote->products = new TestLink([
    $make('qli-a', false), $make('qli-b', true), $make('qli-c', false)
]);
''')
        self.assertEqual(observed["decision"], ORDERED, observed)

    def test_no_visible_release_refuses_and_logs_upstream(self):
        observed = self.execute(
            "$quote->products = new TestLink([$make('qli-production', false)]);"
        )
        self.assertIsNone(observed["decision"])
        self.assertIn("No committed Quote line", observed["error"])

    def test_a_deleted_line_is_not_a_committed_release(self):
        observed = self.execute(
            "$quote->products = new TestLink([$make('qli-gone', true, true)]);"
        )
        self.assertIsNone(observed["decision"])
        self.assertIn("No committed Quote line", observed["error"])

    def test_preloaded_relationship_snapshot_cannot_hide_committed_release(self):
        """The stale Link2 bean says uncommitted; the committed row says ordered.
        Reading through BeanFactory with `use_cache => false` is what makes the
        second one win."""
        observed = self.execute(r'''
$stale = $make('qli-production', false);
$quote->products = new TestLink([$stale]);
$fresh = clone $stale;
$fresh->erp_ordered = true;
BeanFactory::$beans[$fresh->id] = $fresh;
''')
        self.assertEqual(observed["decision"], ORDERED, observed)
        self.assertEqual(observed["retrievals"], [
            ["Products", "qli-production", {"use_cache": False}],
        ])

    def test_unreadable_lines_refuse_rather_than_classify_on_a_partial_read(self):
        scenarios = {
            # The relationship will not load at all.
            "no products link": "",
            # A line identity that resolves to no row: a partial read, not an
            # empty release.
            "unresolvable line": r'''
$quote->products = new TestLink([$make('qli-present', true)]);
unset(BeanFactory::$beans['qli-present']);
''',
        }
        for name, scenario in scenarios.items():
            with self.subTest(scenario=name):
                observed = self.execute(scenario)
                self.assertTrue(observed["error"], observed)
                self.assertIsNone(observed["decision"])
                self.assertEqual(observed["quote_saves"], 0)

    def test_built_package_contains_policy_and_partial_dependency(self):
        package = ROOT / "sugar-sell/BenchDogs-Ext"
        version = (package / "version").read_text().strip()
        archive = package / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        with zipfile.ZipFile(archive) as zipped:
            path = "custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php"
            self.assertEqual(zipped.read(path), (package / path).read_bytes())
            manifest = zipped.read("manifest.php").decode()
            self.assertIn("sugarai_erp_epicor_partialfulfillment", manifest)
            self.assertRegex(manifest, r"'version'\s*=>\s*'1\.0\.13'")


if __name__ == "__main__":
    unittest.main()
