"""REQ-15 option (c): Sugar refuses an Account billing country no Epicor country matches."""

import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "sugar-sell/BenchDogs-Ext"
GUARD = PKG / "custom/modules/Accounts/BdAccountCountryGuard.php"
HOOK = PKG / "custom/Extension/modules/Accounts/Ext/LogicHooks/bd_account_country_guard.php"
LANG = PKG / "custom/Extension/application/Ext/Language/en_us.bd_country_lookup.php"

HARNESS = r'''
#[AllowDynamicProperties]
class SugarBean { public $fetched_row = []; }
class SugarApiExceptionInvalidParameter extends Exception {}
class TestLog {
    public $lines = [];
    public function warning($m) { $this->lines[] = ['warning', $m]; }
    public function error($m) { $this->lines[] = ['error', $m]; }
}
$GLOBALS['log'] = new TestLog();
require $argv[1];
class TestGuard extends BdAccountCountryGuard {
    public static $rows = [];
    protected static function acceptedSpellings(): array { return self::spellingsFrom(self::$rows); }
}
class BrokenGuard extends BdAccountCountryGuard {
    protected static function acceptedSpellings(): array { throw new RuntimeException('db down'); }
}
function attempt($guard, $platform, $new, $old) {
    if ($platform === null) { unset($_SESSION['platform']); } else { $_SESSION['platform'] = $platform; }
    $bean = new SugarBean();
    $bean->billing_address_country = $new;
    $bean->fetched_row = $old === null ? [] : ['billing_address_country' => $old];
    $GLOBALS['log']->lines = [];
    try {
        $guard->refuseUnknownCountry($bean, 'before_save', []);
        return ['refused' => null, 'log' => $GLOBALS['log']->lines];
    } catch (SugarApiExceptionInvalidParameter $e) {
        return ['refused' => $e->getMessage(), 'log' => $GLOBALS['log']->lines];
    }
}
TestGuard::$rows = [
    ['name' => 'USA', 'description' => 'USA|US|UNITED STATES|UNITED STATES OF AMERICA|AMERICA'],
    ['name' => 'France', 'description' => 'FRANCE|FR'],
];
$g = new TestGuard();
$out = [
    'unknown' => attempt($g, 'base', 'Atlantis', 'USA'),
    'alias' => attempt($g, 'base', 'United States', ''),
    'punctuated' => attempt($g, 'mobile', 'u.s.a.', null),
    'iso' => attempt($g, 'base', 'fr', 'USA'),
    'unchanged_unknown' => attempt($g, 'base', 'Atlantis', 'atlantis'),
    'cleared' => attempt($g, 'base', '', 'USA'),
    'connector' => attempt($g, 'sugarai_erp_connector', 'Atlantis', 'USA'),
    'no_platform_unknown' => attempt($g, null, 'Narnia', ''),
    'broken_loader' => attempt(new BrokenGuard(), 'base', 'Atlantis', 'USA'),
    'normalize' => [BdAccountCountryGuard::normalize('U.S.A. '), BdAccountCountryGuard::normalize("united \t  states")],
];
TestGuard::$rows = [];
$out['empty_list'] = attempt($g, 'base', 'Atlantis', 'USA');
echo json_encode($out);
'''


@unittest.skipUnless(shutil.which("php"), "requires the PHP build-test image")
class AccountCountryGuardTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        result = subprocess.run(
            ["php", "-r", HARNESS, str(GUARD)], capture_output=True, text=True, check=False,
        )
        assert result.returncode == 0, result.stderr + result.stdout
        cls.out = json.loads(result.stdout)

    def test_an_unknown_changed_country_is_refused_with_the_known_list(self):
        refused = self.out["unknown"]["refused"]
        self.assertIn("Atlantis", refused)
        self.assertIn("France, USA", refused)
        self.assertIsNotNone(self.out["no_platform_unknown"]["refused"])

    def test_every_published_spelling_is_accepted(self):
        for case in ("alias", "punctuated", "iso"):
            self.assertIsNone(self.out[case]["refused"], case)

    def test_never_blocks_unchanged_cleared_or_connector_saves(self):
        for case in ("unchanged_unknown", "cleared", "connector"):
            self.assertIsNone(self.out[case]["refused"], case)

    def test_fails_open_and_logs_when_the_list_is_empty_or_unreadable(self):
        self.assertIsNone(self.out["empty_list"]["refused"])
        self.assertEqual(self.out["empty_list"]["log"][0][0], "warning")
        self.assertIsNone(self.out["broken_loader"]["refused"])
        self.assertEqual(self.out["broken_loader"]["log"][0][0], "error")

    def test_normalize_matches_the_extension_normalizer(self):
        # connector_ext_benchdogs.reference._norm_country: 'U.S.A. ' -> 'USA'.
        self.assertEqual(self.out["normalize"], ["USA", "UNITED STATES"])


class AccountCountryGuardPackagingTest(unittest.TestCase):
    def test_hook_registers_the_class_file_and_method_only(self):
        hook = HOOK.read_text()
        self.assertIn("$hook_array['before_save'][]", hook)
        self.assertIn("'custom/modules/Accounts/BdAccountCountryGuard.php'", hook)
        self.assertIn("'refuseUnknownCountry'", hook)
        self.assertNotRegex(re.sub(r"/\*.*?\*/", "", hook, flags=re.S), r"\bclass\s+\w+")

    def test_hook_body_has_a_throwable_guard_and_refuses_with_the_passthrough_exception(self):
        guard = GUARD.read_text()
        self.assertIn("catch (\\Throwable $e)", guard)
        self.assertIn("throw new SugarApiExceptionInvalidParameter($refusal)", guard)

    def test_type_label_is_an_additive_list_entry(self):
        lang = LANG.read_text()
        self.assertIn("$app_list_strings['erp_lookup_type_list']['bd_country']", lang)
        self.assertNotRegex(lang, r"\$app_list_strings\['erp_lookup_type_list'\]\s*=")


if __name__ == "__main__":
    unittest.main()
