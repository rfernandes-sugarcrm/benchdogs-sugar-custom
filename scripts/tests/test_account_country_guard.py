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
LANG = PKG / "custom/Extension/application/Ext/Language/_override_en_us.bd_country_lookup.php"

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
    def test_the_hook_is_RETIRED_and_registers_nothing(self):
        """INVERTED 2026-09-19, not deleted.

        This used to assert the hook registered BdAccountCountryGuard. ERP-Core
        owns the billing-country guard now and its hook is registered and live
        (ErpAccountCountryGuard, 21 refs). Two guards on one field can disagree
        and the seller sees whichever ran last, so this package registers none.

        The assertion is INVERTED rather than removed because the file must keep
        SHIPPING as an emptied stub: §CW / G37 — on Sugar Cloud, dropping a
        custom/Extension file from the build leaves the installed copy in place
        and the package inert. Deleting this test would stop noticing if the
        stub itself were dropped.
        """
        hook = re.sub(r"/\*.*?\*/", "", HOOK.read_text(), flags=re.S)
        self.assertTrue(HOOK.exists(), "the stub must still ship, or the tenant keeps the old hook")
        self.assertNotIn("$hook_array", hook, "this package must register no hook")
        self.assertNotRegex(hook, r"\bclass\s+\w+")

    def test_hook_body_has_a_throwable_guard_and_refuses_with_the_passthrough_exception(self):
        guard = GUARD.read_text()
        self.assertIn("catch (\\Throwable $e)", guard)
        self.assertIn("throw new SugarApiExceptionInvalidParameter($refusal)", guard)

    def test_the_type_label_is_RETIRED_and_declares_nothing(self):
        """INVERTED 2026-09-20 (G50), not deleted.

        This used to assert the fragment shipped
        `$app_list_strings['erp_lookup_type_list']['bd_country']`. Nothing in
        this package reads those rows any more — the hook above registers no
        guard, and ERP-Core owns the billing-country check — so the label was
        the last thing making a retired lookup type look supported in the
        ERP_LookupValues list view, its filters and the report field chooser.

        The assertion is INVERTED rather than removed for the same reason as
        the hook's: the file must keep SHIPPING as an emptied stub (§CW / G37),
        and deleting this test would stop anyone noticing if the stub were
        dropped from the build — which leaves the label live on every tenant
        that has it.

        🚩 THIS PIN WAS THE BLOCKER. G50 was filed as "the code is gone, soft
        delete the 12 stale bd_country rows". The code was not gone: this
        fragment still shipped the label and this test still pinned it, so the
        rows could not be retired first without the next install republishing
        the name over them.
        """
        lang = re.sub(r"/\*.*?\*/", "", LANG.read_text(), flags=re.S)
        lang = re.sub(r"(?m)^\s*//.*$", "", lang)
        self.assertTrue(LANG.exists(), "the stub must still ship, or the tenant keeps the label")
        self.assertNotIn("$app_list_strings", lang, "this package must publish no lookup type label")
        self.assertEqual(lang.replace("<?php", "").strip(), "")

    def test_the_stub_keeps_the_exact_path_the_label_was_installed_at(self):
        # Overwriting is the ONLY thing that retires an installed
        # custom/Extension file, and a file only overwrites the copy at its own
        # path - so the name cannot drift, retired or not.
        #
        # The prefix also carried the original fix (rc23): Sugar 26.1 sorts
        # language fragments by is_override, then by an order-map mtime
        # refreshed only when a file's md5 changes, and ERP-Epicor's
        # accumulated whole-array erp_lookup_type_list kept wiping this key
        # until `_override*` put it last. `en_us` in the name is what joins
        # that merge at all.
        self.assertTrue(LANG.name.startswith("_override_"), LANG.name)
        self.assertIn("en_us", LANG.name)
        self.assertEqual(LANG.name, "_override_en_us.bd_country_lookup.php")

    def test_no_fragment_anywhere_republishes_the_bd_country_label(self):
        offenders = sorted(p.name for p in LANG.parent.glob("*.php")
                           if "bd_country" in re.sub(r"/\*.*?\*/", "", p.read_text(), flags=re.S))
        self.assertEqual(offenders, [], "the bd_country label is retired")


if __name__ == "__main__":
    unittest.main()
