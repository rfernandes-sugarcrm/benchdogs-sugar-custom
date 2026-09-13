"""REQ-28 under user decisions 18 and 29, with the real PHP hook.

Core creates the native Quote for a Kinetic-born quote (one line per quantity
break, all unselected). The Bench mirror hook only adopts it: it never creates
a Quote, never copies mirror lines onto an adopted Quote, and gives it an
Opportunity only above the stored Opportunity floor.
"""

import json
import shutil
import subprocess
import unittest

from test_headline_valuation_owner import FIXTURE, WORKSPACE


_CREATE = "        throw new Exception('Unexpected record creation: ' . $module);"
_CONFIG = "        return $key === 'opps.view_by' ? 'Opportunities' : $default;"
assert FIXTURE.count(_CREATE) == 1 and FIXTURE.count(_CONFIG) == 1
ADOPTION_FIXTURE = FIXTURE.replace(_CREATE, r"""        if ($module === 'Administration') {
            return new AdminSettingsDouble();
        }
        $GLOBALS['created'][] = $module;
        if (in_array($module, $GLOBALS['creatable'] ?? [], true)) {
            $bean = new SugarBean();
            $bean->id = 'new-' . strtolower($module);
            return $bean;
        }
        throw new Exception('Unexpected record creation: ' . $module);""").replace(
    _CONFIG, r"""        if (array_key_exists($key, $GLOBALS['config'] ?? [])) {
            return $GLOBALS['config'][$key];
        }
        return $key === 'opps.view_by' ? 'Opportunities' : $default;""")

SCAFFOLD = r'''
class AdminSettingsDouble {
    public $settings = [];
    public function retrieveSettings($category) { $this->settings = $GLOBALS['admin_settings'] ?? []; }
    public function saveSetting($category, $key, $value, $platform = '') {
        $GLOBALS['admin_settings'][$category . '_' . $key] = $value;
        $GLOBALS['saved_settings'][] = [$category, $key, $value];
    }
}
class SugarQuery {
    private $fields = [];
    public function select($fields) { $this->fields = $fields; return $this; }
    public function notEquals($field, $value) { return $this; }
    public function from($bean) { return $this; }
    public function where() { return $this; }
    public function equals($field, $value) { $GLOBALS['lookups'][] = [$field, $value]; return $this; }
    public function limit($n) { return $this; }
    public function execute() {
        if (($this->fields[0] ?? '') === 'erp_display_sync_key') {
            return $GLOBALS['quote_keys'] ?? [];
        }
        return $GLOBALS['query_rows'] ?? [];
    }
}
class AddLink extends TestLink {
    public $added = [];
    public function add($record, $fields = []) { $this->added[] = is_object($record) ? $record->id : $record; }
}
$GLOBALS['created'] = [];
$GLOBALS['creatable'] = ['Quotes'];   // SugarQuery::from() needs a seed bean
$erp->sugar_quote_id = '';
$erp->bd_materialized_quote_id = '';
$erp->quote_num = 1250;
$erp->bd01_erp_quote_lines = new TestLink([$line]);
$account = new SugarBean();
$account->id = 'owned-account';
$account->name = 'Owned Account';
$account->assigned_user_id = 'owned-user';
$erp->bd01_erp_quote_accounts = new TestLink([$account]);
$quote->total = 9750;               // core's native lines, one per break
$quote->erp_is_primary_quote = false;
$quote->opportunities = new AddLink([], []);
$quote->product_bundles = new TestLink([]);
// Fields every real bean carries from its vardefs; the doubles need them set.
foreach ([$quote, $erp] as $record) {
    foreach (['bd_erp_stage' => '', 'bd_reason_code' => '', 'bd_erp_total' => 0, 'quote_total' => 9750] as $field => $value) {
        if (!isset($record->$field)) {
            $record->$field = $value;
        }
    }
}
'''


@unittest.skipUnless(shutil.which("php"), "requires PHP 8.2 build-test image")
class KineticQuoteAdoptionTest(unittest.TestCase):
    def execute(self, scenario):
        result = subprocess.run(
            ["php", "-r", ADOPTION_FIXTURE + SCAFFOLD + scenario + r'''
$bench->reflect($erp, 'after_save', ['dataChanges' => []]);
echo json_encode([
    'status' => $erp->bd_materialize_status ?? null,
    'adopted_id' => $erp->bd_materialized_quote_id,
    'created' => $GLOBALS['created'],
    'lookups' => $GLOBALS['lookups'] ?? [],
    'opportunities_added' => $quote->opportunities->added,
    'primary' => $quote->erp_is_primary_quote,
    'quote_total' => $quote->total,
    'saved_settings' => $GLOBALS['saved_settings'] ?? [],
    'errors' => $GLOBALS['log']->errors,
]);
'''], cwd=WORKSPACE, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def assertNothingInvented(self, observed):
        for message in observed["errors"]:
            self.assertNotIn("Unexpected record creation", message, observed)
        self.assertNotIn("ProductBundles", observed["created"], observed)
        self.assertNotIn("Products", observed["created"], observed)

    def test_waits_when_core_has_not_created_the_quote(self):
        observed = self.execute("$GLOBALS['query_rows'] = [];")
        self.assertEqual(observed["status"], "waiting_native_quote", observed)
        self.assertEqual(observed["adopted_id"], "", observed)
        self.assertEqual(observed["lookups"], [["erp_display_sync_key", "1250"]], observed)
        self.assertEqual(set(observed["created"]), {"Quotes"}, observed)  # query seed only
        self.assertEqual(observed["opportunities_added"], [], observed)
        self.assertNothingInvented(observed)

    def test_adopts_core_quote_without_touching_its_lines_or_pipeline(self):
        observed = self.execute("$GLOBALS['query_rows'] = [['id' => 'owned-quote']];")
        self.assertEqual(observed["status"], "adopted", observed)
        self.assertEqual(observed["adopted_id"], "owned-quote", observed)
        self.assertEqual(observed["opportunities_added"], [], observed)
        self.assertFalse(observed["primary"], observed)
        self.assertEqual(observed["quote_total"], 9750, observed)
        self.assertNothingInvented(observed)

    def test_history_below_configured_start_gets_no_opportunity(self):
        observed = self.execute(r'''
$GLOBALS['query_rows'] = [['id' => 'owned-quote']];
$GLOBALS['config'] = ['benchdogs_ext.materialize_from_quote_num' => 1300];
$GLOBALS['creatable'][] = 'Opportunities';
''')
        self.assertEqual(observed["status"], "adopted", observed)
        self.assertNotIn("Opportunities", observed["created"], observed)
        self.assertFalse(observed["primary"], observed)
        self.assertNothingInvented(observed)

    def test_new_quote_above_configured_start_gets_one_primary_opportunity(self):
        observed = self.execute(r'''
$GLOBALS['query_rows'] = [['id' => 'owned-quote']];
$GLOBALS['config'] = ['benchdogs_ext.materialize_from_quote_num' => 1200];
$GLOBALS['creatable'][] = 'Opportunities';
''')
        self.assertEqual(observed["status"], "adopted", observed)
        self.assertEqual(observed["created"].count("Opportunities"), 1, observed)
        self.assertEqual(observed["opportunities_added"], ["new-opportunities"], observed)
        self.assertTrue(observed["primary"], observed)
        self.assertEqual(observed["quote_total"], 9750, observed)
        self.assertNothingInvented(observed)

    def test_quote_that_already_has_an_opportunity_keeps_it(self):
        observed = self.execute(r'''
$GLOBALS['query_rows'] = [['id' => 'owned-quote']];
$GLOBALS['config'] = ['benchdogs_ext.materialize_from_quote_num' => 1200];
$GLOBALS['creatable'][] = 'Opportunities';
$quote->opportunities = new AddLink([], ['existing-opportunity']);
''')
        self.assertNotIn("Opportunities", observed["created"], observed)
        self.assertEqual(observed["opportunities_added"], [], observed)
        self.assertNothingInvented(observed)


    def test_first_adoption_stores_the_floor_and_gives_history_no_opportunity(self):
        observed = self.execute(r"""
$GLOBALS['query_rows'] = [['id' => 'owned-quote']];
$GLOBALS['quote_keys'] = [['erp_display_sync_key' => '1100'], ['erp_display_sync_key' => '1250'],
                          ['erp_display_sync_key' => 'not-a-number']];
$GLOBALS['creatable'][] = 'Opportunities';
""")
        self.assertEqual(observed["status"], "adopted", observed)
        self.assertEqual(observed["saved_settings"], [["benchdogs", "materialize_from_quote_num", "1250"]], observed)
        self.assertNotIn("Opportunities", observed["created"], observed)
        self.assertFalse(observed["primary"], observed)
        self.assertNothingInvented(observed)

    def test_quote_above_the_stored_floor_gets_one_primary_opportunity(self):
        observed = self.execute(r"""
$GLOBALS['query_rows'] = [['id' => 'owned-quote']];
$GLOBALS['admin_settings'] = ['benchdogs_materialize_from_quote_num' => '1200'];
$GLOBALS['creatable'][] = 'Opportunities';
""")
        self.assertEqual(observed["saved_settings"], [], observed)
        self.assertEqual(observed["created"].count("Opportunities"), 1, observed)
        self.assertTrue(observed["primary"], observed)
        self.assertNothingInvented(observed)

    def test_no_numbered_quote_yet_means_no_opportunity_and_nothing_stored(self):
        observed = self.execute(r"""
$GLOBALS['query_rows'] = [['id' => 'owned-quote']];
$GLOBALS['creatable'][] = 'Opportunities';
""")
        self.assertEqual(observed["saved_settings"], [], observed)
        self.assertNotIn("Opportunities", observed["created"], observed)
        self.assertNothingInvented(observed)


if __name__ == "__main__":
    unittest.main()
