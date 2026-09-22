#!/usr/bin/env python3
"""G268 — decision 314's retired stage names, and how rc65 finishes them off.

🛑 THE DEFECT, AS MEASURED. After Bench Dogs rc61 was installed last on Bench
(2026-09-22 11:32:30Z) `sales_stage_dom` served EIGHT keys: the 4 ERP-Core ones,
then 'Prototype Closed' and 'Partial Production Closed' — renamed to the
'...Ordered' pair by decision 314 on 2026-09-15 — then the '...Ordered' pair
itself. et, carrying the same rc61, served six.

🚩 WHERE THEY LIVED. Not in any file rc61 shipped. From 0.7.3 to rc37 this
package's stage TEMPLATE declared the '...Closed' pair, and post_install handed
it to `ModuleInstaller::install_languages()` under id_name `zz_bd_stage_doms`.
When `custom/Extension/application/Ext/Language/en_us.zz_bd_stage_doms.php`
already exists, install_languages CONCATENATES the old file and the template
instead of overwriting it (SugarEnt 26.1.0 ModuleInstall/ModuleInstaller.php
:1227-1235, via getExtensionFileContents :2471). So every pre-rename install's
lines stayed in that file for good. rc64 neutralised them with an `unset()`
appended in the same fragment.

📌 rc65 ENDS IT AT THE SOURCE (G278 / 🔒 1506 + G280 / 🔒 1507). Partial
Fulfillment now owns the two release stages, their probabilities and their
styles, in `_override_` fragments that Sugar merges LAST. This package therefore
declares no stage key at all, and post_install DELETES the whole accumulated
fragment once, through `uninstall_languages()` — the exact mirror of the install
that created it, and the only removal a package is allowed (`unlink()` is denied
by the cloud scanner). The retired pair goes with the file that carried it.

The cases below EXECUTE Sugar's own merge: the fragments are concatenated with a
verbatim copy of `getExtensionFileContents()` (drift-checked against the source
tree when it is present) and included with the accumulated `$app_list_strings`
in scope, exactly as `_mergeCustomAppListStrings()` does (include/utils.php
:1415). PF's fragment is the REAL one, read from the sibling checkout.

MUTATION-VERIFIED:
  keep the zz fragment (skip the removal) -> removal_is_what_retires_them fails
  re-add a stage key to a shipped file    -> package_declares_no_stage_key fails
  drop PF's fragment from the merge       -> core_still_serves_them fails
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
PKG = Path(os.environ.get("BD_PKG", ROOT / "sugar-sell/BenchDogs-Ext"))
FIX = HERE / "fixtures/g268"
PRE_RENAME = FIX / "bd_stage_doms.append.rc37.php"   # 84edda2, the last '...Closed'
RC61 = FIX / "bd_stage_doms.append.rc61.php"         # c4e8874, what Bench installed
SUGAR_261 = Path.home() / "Documents/Code/SugarEnt-Full-26.1.0"

CORE4 = ["Prospecting", "Proposal/Price Quote", "Closed Won", "Closed Lost"]
ORDERED = ["Prototype Ordered", "Partial Production Ordered"]
RETIRED = ["Prototype Closed", "Partial Production Closed"]

PF_STAGE_REL = ("sugar-sell/ERP-Epicor-PartialFulfillment/custom/Extension/application"
                "/Ext/Language/_override_en_us.partial_fulfillment_sales_stage.php")

# ERP-Core's sales_stage_dom.replace.php, the whole-array shape G220 measured
# wiping add-on keys. Kept as the hostile case: whatever it does, nothing may
# bring the retired pair back.
ERP_LEGACY = """<?php
$app_list_strings['sales_stage_dom'] = array(
    'Prospecting' => 'Prospecting',
    'Proposal/Price Quote' => 'Proposal-Quoting',
    'Closed Won' => 'Closed Won',
    'Closed Lost' => 'Closed Lost',
);
"""

# Verbatim from SugarEnt 26.1.0 ModuleInstall/ModuleInstaller.php:2471-2489.
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

// install_languages()'s file branch (26.1.0 :1227-1235): copy when absent,
// otherwise concatenate [existing, template] and write back.
function bd_install_languages($langFile, $from) {
    if (!file_exists($langFile)) { copy($from, $langFile); return; }
    $temp = dirname($langFile) . '/temp.php';
    copy($from, $temp);
    file_put_contents($langFile, getExtensionFileContents([$langFile, $temp]));
}

// cacheExtensionFiles() + _mergeCustomAppListStrings(): the sorted fragments are
// concatenated into one compiled file, which is then included with the
// ACCUMULATED global $app_list_strings in scope.
function bd_serve(array $files, array $seed) {
    global $app_list_strings;
    static $n = 0;
    $compiled = getcwd() . '/compiled.' . ($n++) . '.php';
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
foreach ($plan['steps'] as $step) {
    if ($step[0] === 'install') { bd_install_languages($plan['zz'], $step[1]); }
    else { $served[] = bd_serve($step[1], $plan['seed']); }
}
echo json_encode(['served' => $served, 'problems' => $problems]);
'''.replace("GET_EXTENSION_FILE_CONTENTS", GET_EXTENSION_FILE_CONTENTS)

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
    "sales_probability_dom": {"Closed Won": "100", "Closed Lost": "0"},
    "quote_stage_dom": {"Draft": "Draft", "Closed Accepted": "Closed Accepted"},
}


def _sibling_repo():
    candidates = [ROOT.parent / "erp-integration-sugar"]
    try:
        common = subprocess.run(["git", "-C", str(ROOT), "rev-parse", "--git-common-dir"],
                                capture_output=True, text=True, check=True).stdout.strip()
        candidates.append((ROOT / common).resolve().parent.parent / "erp-integration-sugar")
    except (OSError, subprocess.CalledProcessError):
        pass
    for cand in candidates:
        if cand.is_dir():
            return cand
    return None


def pf_fragment_text():
    """PF's REAL stage fragment. The sibling checkout is usually parked on an
    older build branch, so fall back to git history rather than skipping — a
    control that skips whenever that is true is a control that never runs."""
    repo = _sibling_repo()
    if repo is None:
        return None
    live = repo / PF_STAGE_REL
    if live.is_file():
        return live.read_text(encoding="utf-8", errors="replace")
    found = subprocess.run(["git", "-C", str(repo), "log", "--all", "--format=%H", "-1",
                            "--", PF_STAGE_REL], capture_output=True, text=True)
    shas = found.stdout.strip().splitlines()
    if not shas:
        return None
    shown = subprocess.run(["git", "-C", str(repo), "show", f"{shas[0]}:{PF_STAGE_REL}"],
                           capture_output=True, text=True)
    return shown.stdout if shown.returncode == 0 else None


def run(steps, fragments, seed=None):
    """`steps` is a list of ('install', template-key) / ('serve', [fragment-keys])."""
    with tempfile.TemporaryDirectory(prefix="g268-") as tmp:
        t = Path(tmp)
        lang = t / "custom/Extension/application/Ext/Language"
        lang.mkdir(parents=True)
        tpl = {"pre": t / "tpl.pre.php", "rc61": t / "tpl.rc61.php"}
        shutil.copy2(PRE_RENAME, tpl["pre"])
        shutil.copy2(RC61, tpl["rc61"])
        paths = {"zz": lang / "en_us.zz_bd_stage_doms.php"}
        for name, body in fragments.items():
            path = lang / f"en_us.{name}.php" if not name.startswith("_override") \
                else lang / f"{name}.php"
            path.write_text(body)
            paths[name] = path
        resolved = []
        for step in steps:
            if step[0] == "install":
                resolved.append(["install", str(tpl[step[1]])])
            else:
                resolved.append(["serve", [str(paths[f]) for f in step[1]]])
        (t / "plan.json").write_text(json.dumps({
            "zz": str(paths["zz"]),
            "seed": seed if seed is not None else STOCK,
            "steps": resolved,
        }))
        out = subprocess.run(["php"], input=HARNESS, text=True, cwd=t,
                             capture_output=True, check=True)
        return json.loads(out.stdout)


BENCH_HISTORY = [["install", "pre"], ["install", "rc61"], ["install", "rc61"]]


@unittest.skipUnless(shutil.which("php"), "requires php")
class RetiredStageNames(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.pf = pf_fragment_text()

    def fragments(self):
        frags = {"bd_stage_doms": (PKG / "custom/Extension/application/Ext/Language"
                                   "/en_us.bd_stage_doms.php").read_text(),
                 "erp_replace": ERP_LEGACY}
        if self.pf:
            frags["_override_en_us.partial_fulfillment_sales_stage"] = self.pf
        return frags

    def test_control_the_model_reproduces_benchs_eight_keys(self):
        """Not a claim about rc65: proof the harness models what Bench served
        under rc61 — same eight keys, same order, as the 11:38Z read."""
        out = run(BENCH_HISTORY + [["serve", ["bd_stage_doms", "erp_replace", "zz"]]],
                  self.fragments())
        self.assertEqual(out["problems"], [])
        self.assertEqual(list(out["served"][0]["sales_stage_dom"]), CORE4 + RETIRED + ORDERED)

    def test_removal_is_what_retires_them(self):
        """rc65's install deletes the accumulated fragment. Serving WITHOUT it is
        what the tenant gets afterwards: no retired pair anywhere, in either dom."""
        if not self.pf:
            self.skipTest("erp-integration-sugar checkout not present beside this repo")
        out = run(BENCH_HISTORY + [["serve", ["bd_stage_doms", "erp_replace",
                                              "_override_en_us.partial_fulfillment_sales_stage"]]],
                  self.fragments())
        served = out["served"][0]
        self.assertEqual(out["problems"], [])
        for name in RETIRED:
            self.assertNotIn(name, served["sales_stage_dom"])
            self.assertNotIn(name, served["sales_probability_dom"] or {})

    def test_core_still_serves_the_surviving_pair(self):
        """The half that matters to a seller: Bench declares nothing now, so if
        PF's fragment did not carry the stages, every Bench Opportunity holding
        one would render a raw key."""
        if not self.pf:
            self.skipTest("erp-integration-sugar checkout not present beside this repo")
        out = run([["serve", ["bd_stage_doms", "erp_replace",
                              "_override_en_us.partial_fulfillment_sales_stage"]]],
                  self.fragments())
        served = out["served"][0]
        for name in ORDERED:
            self.assertEqual(served["sales_stage_dom"].get(name), name)
        self.assertEqual(served["sales_probability_dom"].get("Prototype Ordered"), 80)
        self.assertEqual(served["sales_probability_dom"].get("Partial Production Ordered"), 90)

    def test_the_package_declares_no_stage_key(self):
        """Executed: every shipped language/style fragment is included with the
        doms pre-seeded, and none of them may add or change a key."""
        seed = {"sales_stage_dom": {"SEED": "SEED"}, "sales_probability_dom": {"SEED": 1},
                "quote_stage_dom": {"SEED": "SEED"}}
        fragments = sorted(
            list(PKG.glob("custom/Extension/application/Ext/Language/*.php"))
            + list(PKG.glob("custom/Extension/application/Ext/DropdownsStyle/*.php")))
        self.assertTrue(fragments, "no application fragments found — wrong package root?")
        files = "[" + ", ".join(json.dumps(str(f)) for f in fragments) + "]"
        probe = (
            "$app_list_strings = ['sales_stage_dom' => ['SEED' => 'SEED'],"
            " 'sales_probability_dom' => ['SEED' => 1],"
            " 'quote_stage_dom' => ['SEED' => 'SEED']];"
            "$app_dropdowns_style = ['sales_stage_dom_style' => ['SEED' => 1]];"
            f"foreach ({files} as $f) {{ include $f; }}"
            "echo json_encode(['doms' => $app_list_strings, 'style' => $app_dropdowns_style]);"
        )
        out = subprocess.run(["php", "-r", probe], capture_output=True, text=True, check=True)
        observed = json.loads(out.stdout)
        self.assertEqual(observed["doms"], seed, "a shipped fragment still declares stage keys")
        self.assertEqual(observed["style"], {"sales_stage_dom_style": {"SEED": 1}},
                         "a shipped fragment still declares a stage style")

    def test_the_removal_is_wired_into_the_install(self):
        """The behaviour above only happens if post_install actually calls it.
        The call itself is asserted in test_post_install_stage_languages.py; this
        pins the id_name, because a different one deletes a different file."""
        post = (PKG / "scripts/post_install.php").read_text()
        code = re.sub(r"/\*.*?\*/", "", post, flags=re.S)
        code = re.sub(r"(^|\s)//[^\n]*", r"\1", code)
        self.assertIn("uninstall_languages", code)
        self.assertIn("'zz_bd_stage_doms'", code)
        self.assertNotIn("install_languages()", code.replace("uninstall_languages()", ""))

    @unittest.skipUnless((SUGAR_261 / "ModuleInstall/ModuleInstaller.php").is_file(),
                         "SugarEnt 26.1.0 source tree not present")
    def test_merge_function_matches_sugar(self):
        src = (SUGAR_261 / "ModuleInstall/ModuleInstaller.php").read_text()
        m = re.search(r"protected function getExtensionFileContents\(\$files\)\s*(\{.*?\n    \})",
                      src, re.S)
        ours = re.search(r"function getExtensionFileContents\(\$files\)\s*(\{.*?\n    \})",
                         GET_EXTENSION_FILE_CONTENTS, re.S)
        self.assertIsNotNone(m)
        norm = lambda s: re.sub(r"\s+", " ", s).strip()
        self.assertEqual(norm(ours.group(1)), norm(m.group(1)))
        self.assertIn("$contents = $this->getExtensionFileContents([$lang_file, $temp_lang_file]);", src)


if __name__ == "__main__":
    unittest.main()
