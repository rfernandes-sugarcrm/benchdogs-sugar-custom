"""USER DECISION 55/59: shipped is a QUANTITY, not money — on Bench too.

THE PROPERTY THIS FILE DEFENDS
==============================
There is exactly ONE shipped surface on an order line, it belongs to ERP-Core,
and it is a QUANTITY: ``ERP_OrderLines.shipped_quantity``, rendered by core's
``erp-fulfillment`` field as "12 of 15". This package must not ship a second
one, in either direction:

* not a MONEY field — decision 55 removed ``shipped_value`` /
  ``shipped_value_total`` from ERP-Core, and this package used to duplicate
  both as ``bd_shipped_value`` / ``bd_shipped_value_total`` on the very same
  two modules;
* not a rebuilt ``bd_shipped_quantity`` either — decision 59's refinement is
  that core owns the quantity, so a Bench copy of it is the same defect
  wearing a better name.

AND THE ZERO THAT MEANS UNKNOWN
===============================
``bd_shipped_value`` carried ``'default' => 0.0``. A vardef default on a field
whose only writer is the ERP sync MANUFACTURES DATA at row creation: Module
Loader adds the column with that default, so every row reads 0.00 before the
connector has said anything at all. "Nothing shipped" and "we never measured
this" then render identically to a seller. It was worse here than in core: the
Bench connector module that wrote the field was gated OFF on the QA tenants,
so the record view rendered a fabricated $0.00 with NO WRITER AT ALL.
Measured on Bench before removal: **994 of 994 rows (304 order lines + 690
orders) held exactly 0.00 — not one non-zero value and not one null.** The
fabrication is proved, not inferred.

WHY THIS TEST WAS INVERTED — READ BEFORE CHANGING IT
====================================================
An earlier version of this file asserted the six fragments were ABSENT, and
banned the token ``bd_shipped`` in FILE NAMES as well as contents. That was
wrong, and shipping it cost a release candidate.

Deleting a file does not remove it from an installed tenant. TWO mechanisms,
both measured on Bench (ophirsx177) on 2026-09-14 rather than assumed:

1. An in-place upgrade copies what the new manifest ships and leaves
   everything else alone — lesson BD-L-0005 — and Sugar Cloud's package
   scanner denylists every file-removal call, so no install script can delete
   it either.
2. **NEITHER DOES AN UNINSTALL.** Bench Dogs Ext ``0.9.42-rc23`` was
   uninstalled cleanly (16/16 steps, no error, tables retained) and **not one
   copied ``custom/Extension`` file was removed** — every ``bd_*`` field this
   package ships, on every module, survived the uninstall AND two full Quick
   Repairs. The uninstall log contains no file-removal line at all.

``0.9.42-rc24`` deleted these six files from the build and was therefore
**INERT**: it installed 19/19 with a clean scan and a synced Quick Repair, and
``bd_shipped_value`` was still in vardefs and still on the record view.

So the rule is wider than BD-L-0005 states: **on Sugar Cloud, neither omitting
a file nor uninstalling the package removes a copied ``custom/Extension``
file. Only OVERWRITING it does.** The retirement is therefore delivered by
shipping the six paths as stubs that declare nothing — the mechanism
``Contacts/Ext/Vardefs/bd_contact_sync_fields.php`` already used and proved
live, where all three fields it retired read absent on the tenant.

🔁 AND WHY IT WAS RE-POINTED AT rc69 (G280 / 🔒 1567, 🔒 1521). The six stubs
did their job: every QA tenant has taken a build that overwrote them, and the
one-off ONEOFF-RetireBdResidue - which DELETES each path through platform code
(ModuleInstaller::uninstallExt(), from post_execute, no copy list) - ran on all
three. So the package stops shipping them. Through 2026-09-30 "retired" was
asserted as three halves: not in the source, not in the built zip, and still on
the one-off's worklist.

🔁 🔒2173b (2026-09-30) WITHDREW the one-off and deleted its code, so the third
half is gone and bd_retirement.assert_not_shipped checks the first two. Said
plainly: "absent" alone is exactly what passed on rc24's defect. A tenant that
still carries one of these paths keeps it, and nothing in this repository
removes it any more. That is the owner's accepted state, not a property this
file proves.

A filename ban would forbid exactly the mechanism that delivers the fix. So
the ban is now on **DECLARATIONS, not names**, and emptiness is asserted
**positively and behaviourally**: each stub is executed the way Sugar's
Extension compile would execute it, with the superglobal seeded, and must
contribute **zero** fields, layout entries or strings
(``bench_shipped_stub_probe.php``). Pattern-matching a name is what the old
test did; running the file is strictly stronger.

``test_the_probe_itself_detects_a_non_empty_fragment`` guards the instrument:
it runs the probe against rc23's real fragments and requires it to FIND them.
A check that cannot fail proves nothing — an assertion in the previous version
of this file passed vacuously for exactly that reason, with all six files
still present.

THE LANGUAGE FRAGMENTS ARE NOT DECORATION
=========================================
``git grep bd_shipped_value`` does NOT find ``en_us.bd_shipped_value.php``,
whose only content was ``LBL_BD_SHIPPED_VALUE``. A footprint survey that greps
the field name alone misses the labels and reports a clean removal that left
two files behind. They get stubs too, and their own probe.
"""

from pathlib import Path
import json
import re
import subprocess
import tempfile
import unittest
import zipfile

from bd_retirement import assert_not_shipped


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"
PROBE = Path(__file__).resolve().parent / "bench_shipped_stub_probe.php"

#: ERP-Core owns these two modules.
CORE_ORDER_MODULES = ("ERP_OrderLines", "ERP_Orders")

#: The six fragments decision 59 retired, each mapped to the probe mode that
#: proved it inert while it shipped as a stub (through rc68). From rc69 they are
#: not shipped at all (the one-off that deleted them was withdrawn, 🔒2173b).
RETIRED_STUBS = {
    "custom/Extension/modules/ERP_OrderLines/Ext/Vardefs/"
    "bd_shipped_value.php": "vardefs",
    "custom/Extension/modules/ERP_OrderLines/Ext/Language/"
    "en_us.bd_shipped_value.php": "language",
    "custom/Extension/modules/ERP_OrderLines/Ext/clients/base/views/"
    "record/bd_shipped_value.php": "viewdefs",
    "custom/Extension/modules/ERP_Orders/Ext/Vardefs/"
    "bd_shipped_value_total.php": "vardefs",
    "custom/Extension/modules/ERP_Orders/Ext/Language/"
    "en_us.bd_shipped_value_total.php": "language",
    "custom/Extension/modules/ERP_Orders/Ext/clients/base/views/"
    "record/bd_shipped_value_total.php": "viewdefs",
}

#: Field names, label constants and the dictionary/viewdef keys built from
#: them. ``bd_shipped`` deliberately covers ``bd_shipped_quantity`` too.
#: Applied ONLY to comment-stripped executable code — never to file names,
#: which the stubs must be free to carry.
FORBIDDEN = ("bd_shipped", "BD_SHIPPED", "shipped_value", "SHIPPED_VALUE")

#: ``'default' => 0``, with any spacing. Matches the zero only.
DEFAULT_ZERO = re.compile(r"""['"]default['"]\s*=>\s*0(?:\.0+)?\s*[,)]""")


def _probe(mode, path):
    """Run the PHP probe and return its parsed JSON."""
    out = subprocess.run(
        ["php", str(PROBE), mode, str(path)],
        capture_output=True, text=True, check=True,
    )
    return json.loads(out.stdout)


def _code(path):
    """The file's executable code, with every comment removed.

    Uses PHP's own tokenizer, so a field name MENTIONED in a docblock — which
    every stub does, at length, to explain why it exists — is never mistaken
    for a declaration.
    """
    return _probe("strip", path)["code"]


def _source_php():
    """Every PHP file this package ships, minus the built archives."""
    return [p for p in sorted(PACKAGE.rglob("*.php")) if "releases" not in p.parts]


class BenchShippedIsAQuantityNotMoneyTest(unittest.TestCase):

    # ------------------------------------------ the retirement must HAPPEN

    def test_the_six_retired_fragments_never_ship_again(self):
        """Absence alone was a NON-delivery - rc24's defect.

        Deleting these files from the build leaves them on every installed
        tenant, where Quick Repair recompiles the field straight back. Through
        rc68 shipping them as stubs is what retired them; from rc69 the one-off
        deleted them. 🔒2173b withdrew it, so what is left to hold is that the
        package never ships them again.
        """
        for rel in sorted(RETIRED_STUBS):
            with self.subTest(path=rel):
                assert_not_shipped(self, rel, "bd_shipped_value money on an order")

    #: A real declaration of each shape, written here rather than recovered from
    #: a historical archive. Each one is what the corresponding stub looked like
    #: BEFORE decision 59 emptied it, reduced to the single contribution the
    #: probe is supposed to see.
    CONTROL_FRAGMENTS = {
        "vardefs": """<?php
$dictionary['ERP_OrderLines']['fields']['bd_shipped_value'] = array(
    'name' => 'bd_shipped_value',
    'vname' => 'LBL_BD_SHIPPED_VALUE',
    'type' => 'currency',
);
""",
        "language": """<?php
$mod_strings['LBL_BD_SHIPPED_VALUE'] = 'Shipped Value';
""",
        "viewdefs": """<?php
foreach ($viewdefs['ERP_OrderLines']['base']['view']['record']['panels'] as $i => $panel) {
    if (($panel['name'] ?? '') === 'LBL_RECORDVIEW_PANEL_LINE_ITEM_DETAIL') {
        $viewdefs['ERP_OrderLines']['base']['view']['record']['panels'][$i]['fields'][] =
            array('name' => 'bd_shipped_value');
    }
}
""",
    }

    def test_the_probe_itself_detects_a_non_empty_fragment(self):
        """GUARDS THE INSTRUMENT. A check that cannot fail proves nothing.

        Runs the probe against a REAL declaration of each shape and requires it
        to find one. Without this, a broken probe would report every stub inert
        and the suite would go green with the field still shipping. An assertion
        in an earlier version of this file passed vacuously for exactly that
        reason.

        🛑 THIS TEST ITSELF USED TO BE THE SAME DEFECT IT EXISTS TO PREVENT. It
        took its controls from `releases/sugarai_benchdogs_ext-0.9.42-rc23.zip`
        and called `self.skipTest()` when that archive was absent. `releases/` is
        gitignored and CI rebuilds only the CURRENT version, so rc23 has never
        been present in a fresh checkout: the guard on the instrument was itself
        an instrument that never ran. The controls below are written inline so it
        always runs.

        ⚠️ WHAT THE INLINE CONTROLS DO NOT COVER, and it is not nothing. The
        archive controls were real fragments, so they also proved the probe's
        `PANEL_NAMES` list was COMPLETE - and they once caught it omitting
        `LBL_RECORDVIEW_PANEL_ORDER_DETAIL`, which had made the probe declare a
        genuine fragment empty. A control written here can only exercise a panel
        name this file already knows. So the archive comparison is still run when
        the archive happens to be present; it is simply no longer the only path.
        """
        for mode, source in sorted(self.CONTROL_FRAGMENTS.items()):
            with self.subTest(mode=mode, control="inline"):
                with tempfile.TemporaryDirectory() as tmp:
                    out = Path(tmp) / "original.php"
                    out.write_text(source, encoding="utf-8")
                    self.assertGreater(
                        _probe(mode, out)["count"], 0,
                        f"probe failed to detect a real {mode} declaration - "
                        "the instrument is broken, not the package",
                    )

        control = PACKAGE / "releases" / "sugarai_benchdogs_ext-0.9.42-rc23.zip"
        if not control.is_file():
            return
        with zipfile.ZipFile(control) as archive:
            names = set(archive.namelist())
            for rel, mode in sorted(RETIRED_STUBS.items()):
                with self.subTest(path=rel, mode=mode, control="rc23"):
                    self.assertIn(rel, names, "control archive must carry the original")
                    with tempfile.TemporaryDirectory() as tmp:
                        out = Path(tmp) / "original.php"
                        out.write_bytes(archive.read(rel))
                        self.assertGreater(
                            _probe(mode, out)["count"], 0,
                            f"probe failed to detect the ORIGINAL {rel} - "
                            "the instrument is broken, not the package",
                        )

    # ---------------------------------------------------------------- source

    def test_no_source_file_declares_a_bench_shipped_field_or_label(self):
        """Banned in CODE, not in file names.

        The stubs' own names carry ``bd_shipped`` by necessity and their
        docblocks discuss the field at length; neither is a declaration. This
        checks comment-stripped executable code across the whole package,
        because a resurrection is as likely to arrive as a report, a dashlet
        or a post_install metadata write as it is to arrive as a vardef.
        """
        for path in _source_php():
            code = _code(path)
            for token in FORBIDDEN:
                with self.subTest(path=path.relative_to(PACKAGE), token=token):
                    self.assertNotIn(token, code)

    def test_no_javascript_declares_a_bench_shipped_field_or_label(self):
        """JS has no docblock-stripping problem: ban the raw token."""
        for path in sorted(PACKAGE.rglob("*.js")):
            if "releases" in path.parts:
                continue
            source = path.read_text(encoding="utf-8", errors="replace")
            for token in FORBIDDEN:
                with self.subTest(path=path.relative_to(PACKAGE), token=token):
                    self.assertNotIn(token, source)

    def test_this_package_ships_nothing_for_cores_order_modules(self):
        """Decision 59: one shipped surface, owned by core.

        Through rc68 the package shipped, for these two modules, EXACTLY the six
        retired stubs. From rc69 it ships nothing for them at all. Anything new
        is a deliberate act that has to change this test and explain itself.
        """
        for module in CORE_ORDER_MODULES:
            # ``**/*`` and not ``*``: the fragments would live several
            # directories down, so a single-level glob matches only the ``Ext``
            # DIRECTORY, which ``is_file()`` rejects. Measured: it once did.
            found = sorted(
                str(p.relative_to(PACKAGE))
                for p in PACKAGE.rglob(f"Extension/modules/{module}/**/*")
                if p.is_file()
            )
            with self.subTest(module=module):
                self.assertEqual([], found)

    def test_no_order_module_fragment_defaults_a_field_to_zero(self):
        """A vardef default manufactures data before the connector speaks.

        Now meaningful rather than vacuous: the directories hold files again.
        """
        for module in CORE_ORDER_MODULES:
            for path in PACKAGE.rglob(f"Extension/modules/{module}/**/*.php"):
                with self.subTest(path=path.relative_to(PACKAGE)):
                    self.assertIsNone(DEFAULT_ZERO.search(_code(path)))

    def test_no_fragment_shadows_cores_shipped_quantity(self):
        """Core's field must reach the seller unmodified.

        Redeclaring ``shipped_quantity`` in a Bench vardef, or naming it in a
        Bench viewdef, would let this package put a default back on it or move
        it off a layout without ever using a ``bd_`` name. Checked against
        stripped code: the stubs name it in prose to explain who owns it.
        """
        for path in _source_php():
            with self.subTest(path=path.relative_to(PACKAGE)):
                self.assertNotIn("shipped_quantity", _code(path))

    # ------------------------------------------------------------- built zip

    def _archive(self):
        version = (PACKAGE / "version").read_text().strip()
        return PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"

    def test_built_archive_ships_none_of_the_six(self):
        """The zip is where a half-removal survives: a path gone from source but
        still inside a stale archive would ship the old body anyway."""
        with zipfile.ZipFile(self._archive()) as archive:
            names = set(archive.namelist())
        for rel in sorted(RETIRED_STUBS):
            with self.subTest(path=rel):
                self.assertNotIn(rel, names)

    def test_built_archive_declares_no_bench_shipped_field_or_label(self):
        """Every PHP file in the zip, comment-stripped, across all modules.

        ``manifest.php`` is EXEMPT, and the exemption is the point rather than
        a concession: the manifest's ``copy`` array must NAME all six stub
        paths, or they are never written to the tenant and the original files
        survive — which is precisely how rc24 came to install clean and change
        nothing. The manifest names file paths, not field declarations, and it
        has its own dedicated assertion in
        ``test_manifest_carries_a_copy_entry_for_every_stub``, which requires
        the very strings this test would otherwise forbid. Exempting it here
        keeps the two from contradicting each other.
        """
        import tempfile
        with zipfile.ZipFile(self._archive()) as archive:
            for name in archive.namelist():
                if name == "manifest.php":
                    continue
                if not name.endswith(".php"):
                    if name.endswith(".js"):
                        source = archive.read(name).decode("utf-8", errors="replace")
                        for token in FORBIDDEN:
                            with self.subTest(name=name, token=token):
                                self.assertNotIn(token, source)
                    continue
                with tempfile.TemporaryDirectory() as tmp:
                    out = Path(tmp) / "packaged.php"
                    out.write_bytes(archive.read(name))
                    code = _code(out)
                for token in FORBIDDEN:
                    with self.subTest(name=name, token=token):
                        self.assertNotIn(token, code)

    def test_manifest_carries_no_copy_entry_for_them(self):
        """THE MECHANISM, turned around at rc69. Through rc68 a stub that was
        not in the manifest's ``copy`` array was never written to the tenant,
        so the ORIGINAL stayed on disk - rc24's defect. From rc69 the one-off
        removed the path (withdrawn since, 🔒2173b), and a copy entry here would
        put a body BACK (and give Module Loader a backup to restore on
        uninstall)."""
        with zipfile.ZipFile(self._archive()) as archive:
            manifest = archive.read("manifest.php").decode("utf-8")
        for rel in sorted(RETIRED_STUBS):
            with self.subTest(path=rel):
                self.assertFalse(f"'to' => '{rel}'" in manifest,
                                 f"{rel} is copied again")


if __name__ == "__main__":
    unittest.main()
