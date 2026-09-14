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
  wearing a better name. That is why the forbidden token is ``bd_shipped``
  and not ``bd_shipped_value``.

AND THE ZERO THAT MEANS UNKNOWN
===============================
``bd_shipped_value`` carried ``'default' => 0.0``. A vardef default on a field
whose only writer is the ERP sync MANUFACTURES DATA at row creation: Module
Loader adds the column with that default, so every existing and every future
``ERP_OrderLines`` row reads 0.00 before the connector has said anything at
all. "Nothing shipped" and "we never measured this" then render identically,
and the zero silently understates fulfilment to a seller — the exact failure
REQ-21 was disabled to prevent, and the exact default REQ-21 removed from
ERP-Core's copy. It was worse here than in core: the Bench connector module
that wrote the field was gated OFF on the QA tenants
(``SUGARAI_BD_MLP_FIELDS: "account_group"``, with an explicit "Do not enable
shipped_value here"), so the record view rendered a fabricated $0.00 with no
writer behind it at all.

So the guard is two-part and neither half is redundant:

1. no ``bd_shipped*`` field/label/layout entry may come back, in source OR in
   the built zip — a file removed from source but still inside a stale zip is
   exactly the half-removal this test exists to catch;
2. no fragment this package ships for ``ERP_OrderLines`` / ``ERP_Orders`` may
   declare a ``'default' => 0`` at all, whatever the field is called.

WHY THE ZIP IS CHECKED SEPARATELY FROM THE SOURCE
=================================================
The schema store (``PostgresSchemaStore.put``) is upsert-only and never
deletes, so a removed field survives in the tenant's snapshot with its old
stamp and ``schema-validate`` passes straight over it. Nothing downstream of
the build will notice a field that was deleted from source but still shipped.
The package is the last place a removal can be proven, so it is proven here.

The language-fragment check is not decoration: ``git grep bd_shipped_value``
does NOT find ``en_us.bd_shipped_value.php``, whose only content is
``LBL_BD_SHIPPED_VALUE``. A footprint survey that greps the field name alone
misses the labels and reports a clean removal that left two files behind.
"""

from pathlib import Path
import re
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"

#: Field names, label constants and the dictionary/viewdef keys built from
#: them. ``bd_shipped`` deliberately covers ``bd_shipped_quantity`` too.
FORBIDDEN = (
    "bd_shipped",
    "BD_SHIPPED",
    "shipped_value",
    "SHIPPED_VALUE",
)

#: ERP-Core owns these two modules. This package is allowed to ship nothing
#: for them at all after decision 59 — but the assertion is written against
#: the shipped surface rather than the module, so an unrelated future
#: fragment is not gratuitously forbidden.
CORE_ORDER_MODULES = ("ERP_OrderLines", "ERP_Orders")

#: ``'default' => 0``, ``'default' => 0.0``, ``"default" => 0.00``, with any
#: spacing. Matches the zero only — a genuine non-zero default is a different
#: argument and this test does not pretend to have it.
DEFAULT_ZERO = re.compile(r"""['"]default['"]\s*=>\s*0(?:\.0+)?\s*[,)]""")


def _source_php():
    """Every PHP file this package ships, minus the built archives."""
    return [p for p in sorted(PACKAGE.rglob("*.php")) if "releases" not in p.parts]


class BenchShippedIsAQuantityNotMoneyTest(unittest.TestCase):

    # ---------------------------------------------------------------- source

    def test_no_source_file_declares_a_bench_shipped_field_or_label(self):
        """The whole package, not just the two module directories.

        A resurrection is as likely to arrive as a report, a dashlet or a
        post_install metadata write as it is to arrive as a vardef.
        """
        for path in _source_php() + sorted(PACKAGE.rglob("*.js")):
            if "releases" in path.parts:
                continue
            source = path.read_text(encoding="utf-8", errors="replace")
            for token in FORBIDDEN:
                with self.subTest(path=path.relative_to(PACKAGE), token=token):
                    self.assertNotIn(token, source)

    def test_no_source_path_names_a_bench_shipped_fragment(self):
        """Catches the language fragments, whose CONTENT is only the label.

        ``en_us.bd_shipped_value.php`` contains no occurrence of the field
        name; only the file name and ``LBL_BD_SHIPPED_VALUE`` betray it.
        """
        for path in sorted(PACKAGE.rglob("*")):
            if not path.is_file() or "releases" in path.parts:
                continue
            for token in FORBIDDEN:
                with self.subTest(path=path.relative_to(PACKAGE), token=token):
                    self.assertNotIn(token, path.name)

    def test_this_package_ships_nothing_for_cores_order_modules(self):
        """Decision 59: one shipped surface, owned by core.

        Recorded as a positive assertion rather than left implicit, so that
        adding anything back to these two modules is a deliberate act that
        has to change this test and explain itself.
        """
        for module in CORE_ORDER_MODULES:
            # ``**/*`` and not ``*``: the fragments live several directories
            # down (``Ext/clients/base/views/record/...``), so a single-level
            # glob matches only the ``Ext`` DIRECTORY, which ``is_file()``
            # rejects — and the assertion would pass vacuously with all six
            # files still present. Measured: it did, before this was fixed.
            found = sorted(
                str(p.relative_to(PACKAGE))
                for p in PACKAGE.rglob(f"Extension/modules/{module}/**/*")
                if p.is_file()
            )
            with self.subTest(module=module):
                self.assertEqual([], found)

    def test_no_order_module_fragment_defaults_a_field_to_zero(self):
        """A vardef default manufactures data before the connector speaks.

        Scoped to the two core order modules, where a fabricated zero is a
        shipped or money figure. Kept even though the directories are now
        empty: the point is the rule, and an empty directory passes it
        vacuously today and meaningfully the moment someone adds a file.
        """
        for module in CORE_ORDER_MODULES:
            for path in PACKAGE.rglob(f"Extension/modules/{module}/**/*.php"):
                source = path.read_text(encoding="utf-8", errors="replace")
                with self.subTest(path=path.relative_to(PACKAGE)):
                    self.assertIsNone(DEFAULT_ZERO.search(source))

    def test_no_fragment_shadows_cores_shipped_quantity(self):
        """Core's field must reach the seller unmodified.

        Redeclaring ``shipped_quantity`` in a Bench vardef fragment, or
        naming it in a Bench viewdef fragment, would let this package put a
        default back on it or move it off a layout without ever using a
        ``bd_`` name — the removal would look complete and would not be.
        """
        for path in _source_php():
            source = path.read_text(encoding="utf-8", errors="replace")
            with self.subTest(path=path.relative_to(PACKAGE)):
                self.assertNotIn("shipped_quantity", source)

    # ------------------------------------------------------------- built zip

    def _archive(self):
        version = (PACKAGE / "version").read_text().strip()
        return PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"

    def test_built_archive_carries_no_bench_shipped_field_label_or_layout(self):
        with zipfile.ZipFile(self._archive()) as archive:
            for name in archive.namelist():
                for token in FORBIDDEN:
                    with self.subTest(name=name, token=token):
                        self.assertNotIn(token, name)
                if not name.endswith((".php", ".js")):
                    continue
                source = archive.read(name).decode("utf-8", errors="replace")
                for token in FORBIDDEN:
                    with self.subTest(name=name, token=token):
                        self.assertNotIn(token, source)

    def test_built_archive_has_no_installdef_for_cores_order_modules(self):
        """The manifest is where a dangling copy entry would survive.

        ``pack.php`` globs ``custom/``, so a deleted file leaves no installdef
        behind — but that is a property of the current builder, and this
        asserts the outcome rather than trusting the mechanism.
        """
        with zipfile.ZipFile(self._archive()) as archive:
            manifest = archive.read("manifest.php").decode("utf-8")
        for module in CORE_ORDER_MODULES:
            with self.subTest(module=module):
                self.assertNotIn(f"modules/{module}/", manifest)

    def test_the_retired_fragments_are_absent_from_the_upgrade_boundary(self):
        """Named file by file, so the removal is legible in the test itself.

        These are the six files decision 59 retired. An in-place Module Loader
        upgrade replaces the package's files, so their absence from the new
        zip is what takes them off the instance.
        """
        retired = {
            "custom/Extension/modules/ERP_OrderLines/Ext/Vardefs/"
            "bd_shipped_value.php",
            "custom/Extension/modules/ERP_OrderLines/Ext/Language/"
            "en_us.bd_shipped_value.php",
            "custom/Extension/modules/ERP_OrderLines/Ext/clients/base/views/"
            "record/bd_shipped_value.php",
            "custom/Extension/modules/ERP_Orders/Ext/Vardefs/"
            "bd_shipped_value_total.php",
            "custom/Extension/modules/ERP_Orders/Ext/Language/"
            "en_us.bd_shipped_value_total.php",
            "custom/Extension/modules/ERP_Orders/Ext/clients/base/views/"
            "record/bd_shipped_value_total.php",
        }
        with zipfile.ZipFile(self._archive()) as archive:
            names = set(archive.namelist())
        for name in sorted(retired):
            with self.subTest(name=name):
                self.assertNotIn(name, names)


if __name__ == "__main__":
    unittest.main()
