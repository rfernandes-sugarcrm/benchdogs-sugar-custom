#!/usr/bin/env python3
"""G268 - Bench serves decision 314's RETIRED stage names; rc64 takes them out.

🛑 THE DEFECT, AS MEASURED. After Bench Dogs rc61 was installed LAST on Bench
(2026-09-22 11:32:30Z), a fresh-tab read of ``sales_stage_dom`` returned eight
keys, in this order:

    Prospecting, Proposal/Price Quote, Closed Won, Closed Lost,
    Prototype Closed, Partial Production Closed,
    Prototype Ordered, Partial Production Ordered

Decision 314 renamed the ``...Closed`` pair to ``...Ordered`` (a1f0276 /
8b32a60, 2026-09-15 21:07). et, carrying the SAME rc61, served six keys, and
rc61's shipped ``en_us.bd_stage_doms.php`` declares only the ``...Ordered``
pair. So the retired names are not shipped by rc61.

🚩 WHERE THEY ACTUALLY LIVE - found in history, then in the 26.1 source.
From 0.7.3 (1fd545f) to rc37 (84edda2) ``custom/dropdowntemplates/
bd_stage_doms.append.php`` declared the ``...Closed`` pair, and post_install
hands that template to ``ModuleInstaller::install_languages()`` with id_name
``zz_bd_stage_doms``. When ``en_us.zz_bd_stage_doms.php`` already exists,
install_languages() does NOT overwrite it - it concatenates the old file and
the new template (SugarEnt 26.1.0 ``ModuleInstall/ModuleInstaller.php:1227-1235``
and ``getExtensionFileContents()`` at ``:2471``). Every pre-rename Bench install
therefore left its ``...Closed`` lines in that file permanently. et's file was
born after the rename.

The live sequence corroborates it: after the ERP-Epicor 1.1.104 install Bench
served FOUR keys, and after the Bench Dogs rc61 install it served EIGHT. A
fragment whose md5 did not change would have stayed ahead of ERP-Core's
whole-array replace and been wiped; the retired pair came back, so it lives in
the one file whose md5 moves on every Bench Dogs install - this one.

📌 THE RETIREMENT. rc64's template ends with ``unset()`` of the four retired
entries (two in ``sales_stage_dom``, two in ``sales_probability_dom``). It is
APPENDED after everything already in that file, so it runs after every
historical assignment. A stub SHIPPED at the zz path would freeze the file's
md5 and with it its merge position (``ModuleInstaller.php:2426-2438``), killing
the "reinstall Bench Dogs after ERP-Epicor" lever G220/G273 depend on - so the
suite also pins that the file keeps changing on every install and that the
package ships nothing at that path.

HOW THIS IS MEASURED, NOT GREPPED. The harness rebuilds the zz file with a
verbatim copy of 26.1's append branch and ``getExtensionFileContents()``,
compiles the language fragments in merge order exactly as ``cacheExtensionFiles``
does, and includes the result with the accumulated ``$app_list_strings`` in
scope, as ``_mergeCustomAppListStrings()`` (``include/utils.php:1415``) does.
The assertions are on the SERVED lists.

MUTATION-VERIFIED (each applied, suite re-run, failure observed):
  drop the unset() from the template        -> retires_both_names, studio_copy,
                                               preserving_replace fail
  unset an '...Ordered' key by mistake      -> ordered_pair_survives fails
  ship a stub at en_us.zz_bd_stage_doms.php -> nothing_shipped_at_the_zz_path fails
"""

from __future__ import annotations

import json
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
PKG = ROOT / "sugar-sell/BenchDogs-Ext"
TEMPLATE = PKG / "custom/dropdowntemplates/bd_stage_doms.append.php"
STATIC = PKG / "custom/Extension/application/Ext/Language/en_us.bd_stage_doms.php"
ZZ_REL = "custom/Extension/application/Ext/Language/en_us.zz_bd_stage_doms.php"
FIX = HERE / "fixtures/g268"
PRE_RENAME = FIX / "bd_stage_doms.append.rc37.php"   # 84edda2, last '...Closed'
RC61 = FIX / "bd_stage_doms.append.rc61.php"         # c4e8874, installed on Bench
SUGAR_261 = Path.home() / "Documents/Code/SugarEnt-Full-26.1.0"

CORE4 = ["Prospecting", "Proposal/Price Quote", "Closed Won", "Closed Lost"]
ORDERED = ["Prototype Ordered", "Partial Production Ordered"]
RETIRED = ["Prototype Closed", "Partial Production Closed"]

# ERP-Core's sales_stage_dom.replace.php, both shapes it has shipped. LEGACY is
# the whole-array assignment G220 measured wiping add-on keys; PRESERVING is
# the later one (fix/g220-replace-templates-preserve-addon-keys) that keeps any
# key it does not own.
ERP_LEGACY = r"""<?php
$app_list_strings['sales_stage_dom'] = array(
    'Prospecting' => 'Prospecting',
    'Proposal/Price Quote' => 'Proposal-Quoting',
    'Closed Won' => 'Closed Won',
    'Closed Lost' => 'Closed Lost',
);
"""
ERP_PRESERVING = r"""<?php
$app_list_strings['sales_stage_dom'] = array(
    'Prospecting' => 'Prospecting',
    'Proposal/Price Quote' => 'Proposal-Quoting',
    'Closed Won' => 'Closed Won',
    'Closed Lost' => 'Closed Lost',
) + array_diff_key(
    $app_list_strings['sales_stage_dom'] ?? array(),
    array(
        'Qualification' => true,
        'Needs Analysis' => true,
        'Value Proposition' => true,
        'Id. Decision Makers' => true,
        'Perception Analysis' => true,
        'Negotiation/Review' => true,
    )
);
"""

# Verbatim from SugarEnt 26.1.0 ModuleInstall/ModuleInstaller.php:2471-2489
# (protected there; a free function here). test_merge_function_matches_sugar
# re-reads the source whenever the tree is present, so this cannot drift.
GET_EXTENSION_FILE_CONTENTS = r'''
    function getExtensionFileContents($files)
    {
        $contents = "<?php\n// WARNING: The contents of this file are auto-generated.\n?>\n";

        foreach ($files as $path) {
            $file = file_get_contents($path);

            // remove the 1st opening tag <?php, <?PHP or <?
            $replaced = preg_replace('/^\s*<\?(php|PHP)?/', '', $file);

            // replace the closing tag and the trailing whitespace if any
            $replaced = preg_replace('/\?>\s*$/', '', $replaced);

            // each file is merged with the added open and close tags
            $contents .= "<?php\n// Merged from $path\n" . $replaced . "\n?>\n";
        }

        return $contents;
    }
'''

HARNESS = r'''<?php
$problems = [];
set_error_handler(function ($no, $str, $file, $line) use (&$problems) {
    $problems[] = "$no $str @ " . basename($file) . ":$line";
    return true;
});
GET_EXTENSION_FILE_CONTENTS

// ModuleInstaller::install_languages()'s file branch (26.1.0 :1227-1235):
// copy when absent, otherwise concatenate [existing, template] and write back.
function bd_install_languages($langFile, $from) {
    if (!file_exists($langFile)) {
        copy($from, $langFile);
        return;
    }
    $temp = dirname($langFile) . '/temp.php';
    copy($from, $temp);
    file_put_contents($langFile, getExtensionFileContents([$langFile, $temp]));
}

// cacheExtensionFiles() + _mergeCustomAppListStrings(): the sorted fragments
// are concatenated into one compiled file, which is then included with the
// ACCUMULATED global $app_list_strings in scope.
function bd_serve(array $files, array $seed) {
    global $app_list_strings;
    $compiled = getcwd() . '/compiled.' . count($GLOBALS['served']) . '.php';
    file_put_contents($compiled, getExtensionFileContents($files));
    $app_list_strings = $seed;
    include $compiled;
    return [
        'sales_stage_dom' => $app_list_strings['sales_stage_dom'] ?? null,
        'sales_probability_dom' => $app_list_strings['sales_probability_dom'] ?? null,
        'quote_stage_dom' => $app_list_strings['quote_stage_dom'] ?? null,
    ];
}

$plan = json_decode(file_get_contents('plan.json'), true);
$served = [];
$md5 = [];
foreach ($plan['steps'] as $step) {
    if ($step[0] === 'install') {
        bd_install_languages($plan['zz'], $step[1]);
        $md5[] = md5_file($plan['zz']);
    } else {
        $served[] = bd_serve($step[1], $plan['seed']);
    }
}
echo json_encode(['served' => $served, 'md5' => $md5, 'problems' => $problems]);
'''.replace("GET_EXTENSION_FILE_CONTENTS", GET_EXTENSION_FILE_CONTENTS)

# Stock 26.1.0 include/language/en_us.lang.php, the lists this touches.
STOCK = {
    "sales_stage_dom": {
        "Prospecting": "Prospecting", "Qualification": "Qualification",
        "Needs Analysis": "Needs Analysis", "Value Proposition": "Value Proposition",
        "Id. Decision Makers": "Id. Decision Makers",
        "Perception Analysis": "Perception Analysis",
        "Proposal/Price Quote": "Proposal/Price Quote",
        "Negotiation/Review": "Negotiation/Review",
        "Closed Won": "Closed Won", "Closed Lost": "Closed Lost",
    },
    "sales_probability_dom": {
        "Prospecting": "10", "Qualification": "20", "Needs Analysis": "25",
        "Value Proposition": "30", "Id. Decision Makers": "40",
        "Perception Analysis": "50", "Proposal/Price Quote": "65",
        "Negotiation/Review": "80", "Closed Won": "100", "Closed Lost": "0",
    },
    "quote_stage_dom": {
        "Draft": "Draft", "Negotiation": "Negotiation", "Delivered": "Delivered",
        "On Hold": "On Hold", "Confirmed": "Confirmed",
        "Closed Accepted": "Closed Accepted", "Closed Lost": "Closed Lost",
        "Closed Dead": "Closed Dead",
    },
}


def run(steps, seed=None):
    """Execute a plan. Paths in steps are symbolic and resolved here:
    'pre' / 'rc61' / 'now' name a template; 'static' / 'zz' / 'erp_legacy' /
    'erp_preserving' name a language fragment."""
    with tempfile.TemporaryDirectory(prefix="g268-") as tmp:
        t = Path(tmp)
        lang = t / "custom/Extension/application/Ext/Language"
        lang.mkdir(parents=True)
        tpl = {"pre": t / "tpl.pre.php", "rc61": t / "tpl.rc61.php", "now": t / "tpl.now.php"}
        shutil.copy2(PRE_RENAME, tpl["pre"])
        shutil.copy2(RC61, tpl["rc61"])
        shutil.copy2(TEMPLATE, tpl["now"])
        frag = {
            "static": lang / "en_us.bd_stage_doms.php",
            "zz": lang / "en_us.zz_bd_stage_doms.php",
            "erp_legacy": lang / "en_us.sales_stage_dom_legacy.php",
            "erp_preserving": lang / "en_us.sales_stage_dom_preserving.php",
        }
        shutil.copy2(STATIC, frag["static"])
        frag["erp_legacy"].write_text(ERP_LEGACY)
        frag["erp_preserving"].write_text(ERP_PRESERVING)
        resolved = []
        for step in steps:
            if step[0] == "install":
                resolved.append(["install", str(tpl[step[1]])])
            else:
                resolved.append(["serve", [str(frag[f]) for f in step[1]]])
        (t / "plan.json").write_text(json.dumps({
            "zz": str(frag["zz"]),
            "seed": seed if seed is not None else STOCK,
            "steps": resolved,
        }))
        out = subprocess.run(["php"], input=HARNESS, text=True, cwd=t,
                             capture_output=True, check=True)
        return json.loads(out.stdout)


# Bench's history, as far as this file is concerned: pre-rename installs, then
# post-rename ones (rc38..rc61). Two of each is enough - append is append.
BENCH_HISTORY = [["install", "pre"], ["install", "pre"], ["install", "rc61"], ["install", "rc61"]]
# Bench Dogs installed LAST, so its zz file merges after ERP-Core's replace.
BENCH_ORDER = ["static", "erp_legacy", "zz"]


@unittest.skipUnless(shutil.which("php"), "requires php")
class RetiredStageNames(unittest.TestCase):

    def test_control_the_model_reproduces_benchs_eight_keys_under_rc61(self):
        """Not a claim about rc64: proof the harness models what Bench served.
        Same eight keys, same order, as the 11:38Z fresh-tab read."""
        out = run(BENCH_HISTORY + [["serve", BENCH_ORDER]])
        self.assertEqual(out["problems"], [])
        self.assertEqual(list(out["served"][0]["sales_stage_dom"]), CORE4 + RETIRED + ORDERED)

    def test_rc64_install_retires_both_names_on_a_bench_shaped_file(self):
        out = run(BENCH_HISTORY + [["install", "now"], ["serve", BENCH_ORDER]])
        served = out["served"][0]
        self.assertEqual(out["problems"], [])
        for name in RETIRED:
            self.assertNotIn(name, served["sales_stage_dom"])
            self.assertNotIn(name, served["sales_probability_dom"])
        self.assertEqual(list(served["sales_stage_dom"]), CORE4 + ORDERED,
                         "Bench should serve exactly the 4 core keys + the Ordered pair")

    def test_ordered_pair_survives_with_its_probabilities(self):
        out = run(BENCH_HISTORY + [["install", "now"], ["serve", BENCH_ORDER]])
        served = out["served"][0]
        for name in ORDERED:
            self.assertEqual(served["sales_stage_dom"].get(name), name)
        self.assertEqual(served["sales_probability_dom"].get("Prototype Ordered"), 80)
        self.assertEqual(served["sales_probability_dom"].get("Partial Production Ordered"), 90)
        self.assertEqual(served["quote_stage_dom"].get("Partially Fulfilled"), "Partially Fulfilled")

    def test_a_fresh_tenant_file_is_clean_and_the_unset_is_silent(self):
        """et / stock: the file does not exist yet, so the template is COPIED.
        unset() of keys that were never there must raise nothing."""
        out = run([["install", "now"], ["serve", BENCH_ORDER], ["serve", ["zz"]]])
        self.assertEqual(out["problems"], [])
        self.assertEqual(list(out["served"][0]["sales_stage_dom"]), CORE4 + ORDERED)
        stock_only = out["served"][1]["sales_stage_dom"]
        self.assertEqual(list(stock_only), list(STOCK["sales_stage_dom"]) + ORDERED)

    def test_an_earlier_layer_copy_is_removed_too(self):
        """custom/include/language (Studio) and earlier-sorting fragments are
        already in the accumulated $app_list_strings when the compiled file is
        included, so the tail unset() reaches them as well."""
        seed = json.loads(json.dumps(STOCK))
        seed["sales_stage_dom"]["Prototype Closed"] = "Prototype Closed"
        seed["sales_probability_dom"]["Partial Production Closed"] = "90"
        out = run([["install", "now"], ["serve", ["static", "zz"]]], seed=seed)
        served = out["served"][0]
        self.assertNotIn("Prototype Closed", served["sales_stage_dom"])
        self.assertNotIn("Partial Production Closed", served["sales_probability_dom"])

    def test_preserving_replace_merging_after_still_serves_six(self):
        """ERP-Core's newer replace keeps add-on keys; if it merges AFTER the
        zz file (ERP-Epicor installed last) nothing retired comes back."""
        out = run(BENCH_HISTORY + [["install", "now"],
                                   ["serve", ["static", "zz", "erp_preserving"]]])
        self.assertEqual(list(out["served"][0]["sales_stage_dom"]), CORE4 + ORDERED)

    def test_every_install_still_changes_the_file(self):
        """G220/G273's lever: the zz file must keep growing, so its md5 moves and
        its recorded merge position refreshes on every Bench Dogs install."""
        out = run(BENCH_HISTORY + [["install", "now"], ["install", "now"], ["install", "now"]])
        tail = out["md5"][-4:]
        self.assertEqual(len(set(tail)), len(tail), "an install left the zz file byte-identical")

    def test_nothing_shipped_at_the_zz_path(self):
        """A copied stub there would freeze the md5 (above) and hand the file to
        uninstall_copy()'s backup/restore. The path belongs to install_languages."""
        self.assertFalse((PKG / ZZ_REL).exists(), f"{ZZ_REL} must not be shipped")
        self.assertIn("'zz_bd_stage_doms'", (PKG / "scripts/post_install.php").read_text())

    def test_post_install_verifies_the_retired_names_are_gone(self):
        src = (PKG / "scripts/post_install.php").read_text()
        self.assertTrue("BenchDogs-Ext: retired stage names still served" in src,
                        "post_install no longer logs when a retired name is still served")

    @unittest.skipUnless((SUGAR_261 / "ModuleInstall/ModuleInstaller.php").is_file(),
                         "SugarEnt 26.1.0 source tree not present")
    def test_merge_function_matches_sugar(self):
        src = (SUGAR_261 / "ModuleInstall/ModuleInstaller.php").read_text()
        m = re.search(r"protected function getExtensionFileContents\(\$files\)\s*(\{.*?\n    \})", src, re.S)
        self.assertIsNotNone(m)
        ours = re.search(r"function getExtensionFileContents\(\$files\)\s*(\{.*?\n    \})",
                         GET_EXTENSION_FILE_CONTENTS, re.S)
        norm = lambda s: re.sub(r"\s+", " ", s).strip()
        self.assertEqual(norm(ours.group(1)), norm(m.group(1)))
        self.assertIn("$contents = $this->getExtensionFileContents([$lang_file, $temp_lang_file]);", src)


if __name__ == "__main__":
    unittest.main()
