#!/usr/bin/env python3
"""The default owner link sits inside Admin -> ERP Integration, beside ERP Sales Reps.

0.9.44-rc99 drew 'Default owner for ERP-created records' in a Bench Dogs box of
its own. The owner asked for it inside ERP-Core's ERP Integration group
(``LBL_ERP_INTEGRATION_SECTION_HEADER``, ERP-Core's
``erp_integration_setup.php``), next to ERP Sales Reps. The setting itself stays
this package's.

HOW THE ORDER IS GUARANTEED. Sugar concatenates a module's Administration
fragments in ``sortExtensionFiles`` order: every plain fragment, then every
``_override`` one, each group by the mtime Sugar first saw (SugarEnt 25.2.0 and
26.1.0; the rule is ``test_g450_suspect_account_type.sugar_order``, pinned
against both trees there). The file NAME does not order plain fragments, and an
ERP-Epicor upgrade gives ERP-Core's file a newer mtime than this package's. So
the link lives in ``_override_bd_default_owner.php``: it runs after ERP-Core's
group whichever package was installed last, adds the link to that group when it
is there, and otherwise keeps a Bench Dogs box.

THE OLD PATH SHIPS EMPTY. An upgrade keeps a copied file a later build stops
shipping, so rc99's ``bd_default_owner.php`` would still draw its box beside the
new link. The path ships again, with no code.

The cases EXECUTE the merge in PHP: the fragments in Sugar's order, concatenated
with the verbatim ``getExtensionFileContents`` (the G268 harness) and included
with a stock group already in ``$admin_group_header``. ERP-Core's file is a pinned
copy (fixtures/merge-order/), compared with the sibling checkout where it exists.

WHAT BROKEN LOOKS LIKE, and which case sees it:
  * the link file named without ``_override``       -> test_an_erp_epicor_upgrade_after_bench
  * the link appended to the local option array      -> test_the_link_joins_the_erp_group
  * the old rc99 body left in place                  -> test_the_old_path_draws_nothing
  * no ERP-Core group and no fallback                -> test_without_erp_core_it_keeps_its_own_box
"""
from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

from bd_retirement import PKG
from test_g268_retired_stage_names import GET_EXTENSION_FILE_CONTENTS, _sibling_repo
from test_g450_suspect_account_type import sugar_order

HERE = Path(__file__).resolve().parent
PINS = HERE / "fixtures/merge-order"
PROVENANCE = json.loads((PINS / "PROVENANCE.json").read_text())
ADMIN = PKG / "custom/Extension/modules/Administration/Ext/Administration"
LINK = ADMIN / "_override_bd_default_owner.php"
OLD = ADMIN / "bd_default_owner.php"
ERP_CORE = PINS / "erp_integration_setup.php"

#: ERP-Core's group, in its file's own order, then the link.
ERP_GROUP_LINKS = [
    "erp_integration_setup",
    "erp_admin_erp_companies",
    "erp_admin_erp_lookupvalues",
    "erp_admin_erp_salesreps",
    "bd_default_owner",
]
#: rc99's own body for this path, as it shipped (sugar-sell/BenchDogs-Ext at 81057cba).
RC99_BODY = """<?php

// A Bench Dogs section in Admin with its one page: the default owner for ERP-created records.
$admin_option_defs = array();
$admin_option_defs['Administration']['bd_default_owner'] = array(
    'Administration',
    'LBL_BD_DEFAULT_OWNER',
    'LBL_BD_DEFAULT_OWNER_DESCRIPTION',
    'javascript:parent.SUGAR.App.router.navigate("Administration/layout/bd-default-owner", {trigger: true});',
);

$admin_group_header[] = array(
    'LBL_BD_ADMIN_SECTION',
    '',
    false,
    $admin_option_defs,
    'LBL_BD_ADMIN_SECTION_DESCRIPTION',
);
"""

HARNESS = r'''<?php
$problems = [];
set_error_handler(function ($no, $str, $file, $line) use (&$problems) {
    $problems[] = "$no $str @ " . basename($file) . ":$line";
    return true;
});
GET_EXTENSION_FILE_CONTENTS
$plan = json_decode(file_get_contents('plan.json'), true);
$served = [];
foreach ($plan['serves'] as $i => $files) {
    $compiled = getcwd() . "/compiled.$i.php";
    file_put_contents($compiled, getExtensionFileContents($files));
    // What Sugar's admin page holds before it includes administration.ext.php.
    $admin_option_defs = ['Administration' => ['stock' => ['Administration', 'LBL_STOCK', '', '']]];
    $admin_group_header = [['LBL_ADMINISTRATOR_SETTINGS', '', false, $admin_option_defs, '']];
    include $compiled;
    $groups = [];
    foreach ($admin_group_header as $group) {
        $groups[] = [$group[0], array_keys($group[3]['Administration'] ?? [])];
    }
    $served[] = $groups;
}
echo json_encode(['served' => $served, 'problems' => $problems]);
'''.replace("GET_EXTENSION_FILE_CONTENTS", GET_EXTENSION_FILE_CONTENTS)


def serve(*orders: list[Path]) -> list[list[list]]:
    with tempfile.TemporaryDirectory(prefix="bd-admin-") as tmp:
        t = Path(tmp)
        (t / "plan.json").write_text(json.dumps({"serves": [[str(p) for p in o] for o in orders]}))
        out = subprocess.run(["php"], input=HARNESS, text=True, cwd=t,
                             capture_output=True, check=True)
        result = json.loads(out.stdout)
    assert result["problems"] == [], result["problems"]
    return result["served"]


def ordered(files: dict[Path, int]) -> list[Path]:
    """The files in Sugar's merge order, each at the mtime given."""
    by_name = {str(path): path for path in files}
    return [by_name[name] for name in sugar_order([(str(p), m) for p, m in files.items()])]


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheLinkJoinsTheErpGroup(unittest.TestCase):
    def assert_in_the_erp_group(self, served):
        groups = dict((label, links) for label, links in served)
        self.assertEqual(groups.get("LBL_ERP_INTEGRATION_SECTION_HEADER"), ERP_GROUP_LINKS, served)
        self.assertNotIn("LBL_BD_ADMIN_SECTION", groups, "a Bench Dogs box is drawn as well")
        self.assertEqual(groups["LBL_ADMINISTRATOR_SETTINGS"], ["stock"], "a stock group changed")

    def test_the_link_joins_the_erp_group(self):
        """ERP-Epicor installed first (the manifest's dependency order)."""
        (served,) = serve(ordered({ERP_CORE: 100, OLD: 200, LINK: 200}))
        self.assert_in_the_erp_group(served)

    def test_an_erp_epicor_upgrade_after_bench(self):
        """ERP-Core's file is newer than this package's: still last, still inside."""
        (served,) = serve(ordered({OLD: 100, LINK: 100, ERP_CORE: 300}))
        self.assert_in_the_erp_group(served)

    def test_the_link_file_merges_after_every_plain_fragment(self):
        self.assertEqual(LINK.name[:9], "_override")
        self.assertEqual(ordered({LINK: 1, ERP_CORE: 999})[-1], LINK)

    def test_without_erp_core_it_keeps_its_own_box(self):
        (served,) = serve(ordered({OLD: 100, LINK: 100}))
        self.assertEqual(served, [["LBL_ADMINISTRATOR_SETTINGS", ["stock"]],
                                  ["LBL_BD_ADMIN_SECTION", ["bd_default_owner"]]])

    def test_the_old_path_draws_nothing(self):
        """rc99's body left beside the new link would draw the link twice; the
        shipped path adds nothing to the page."""
        with tempfile.TemporaryDirectory(prefix="bd-rc99-") as tmp:
            rc99 = Path(tmp) / "bd_default_owner.php"
            rc99.write_text(RC99_BODY)
            (with_rc99,) = serve(ordered({ERP_CORE: 100, rc99: 200, LINK: 200}))
        self.assertIn("LBL_BD_ADMIN_SECTION", dict(with_rc99), "the control does not show the box")
        (alone,) = serve([OLD])
        self.assertEqual(alone, [["LBL_ADMINISTRATOR_SETTINGS", ["stock"]]])


class TheErpCorePinIsCurrent(unittest.TestCase):
    def test_the_pins_are_the_copies_they_claim(self):
        for name, meta in PROVENANCE["files"].items():
            with self.subTest(file=name):
                body = (PINS / name).read_bytes()
                self.assertEqual(hashlib.sha256(body).hexdigest(), meta["sha256"],
                                 f"{name} was edited in place; re-copy it from the sibling")

    def test_the_pins_match_the_sibling_where_it_is_reachable(self):
        """Staleness is loud: where erp-integration-sugar is checked out, each pin
        equals the newest commit that touches its path. Where it is not (CI), the
        pins are what the cases use."""
        repo = _sibling_repo()
        if repo is None:
            return
        for name, meta in PROVENANCE["files"].items():
            found = subprocess.run(["git", "-C", str(repo), "log", "--all", "--format=%H", "-1",
                                    "--", meta["source"]], capture_output=True, text=True)
            shas = found.stdout.strip().splitlines()
            if not shas:
                continue
            live = subprocess.run(["git", "-C", str(repo), "show", f"{shas[0]}:{meta['source']}"],
                                  capture_output=True)
            if live.returncode != 0:
                continue
            with self.subTest(file=name):
                self.assertEqual((PINS / name).read_bytes(), live.stdout,
                                 f"{name} drifted from ERP-Core ({shas[0][:12]}); re-pin it")


if __name__ == "__main__":
    os.chdir(HERE)
    unittest.main()
