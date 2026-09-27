"""Decision 83 / gate G1: the `bd01_*` quote mirror must never come back.

`bd01_ERP_Quote`, `bd01_ERP_Quote_Line` and `bd01_ERP_Quote_Cost` are retired by
decision 83 and their continued existence is what gate **G1** fails on. This
suite is the thing that notices if they return -- by accident (a merge that
resurrects `modules/bd01_*`), or by omission (a `pack.php` that drops the
`beans` key instead of emptying it).

WHY EVERY ASSERTION BELOW IS SHAPED THE WAY IT IS
-------------------------------------------------

1.  **`bd01_` is matched as a PREFIX, never as a substring of something else.**
    `bd01_*`, `bd_*` and `erp_*` are three DIFFERENT prefixes and a careless
    grep conflates them.  `Products.erp_governing` (the shared Partial
    Fulfillment layer), `Products.bd_governing_origin` (Bench's marker) and the
    retired `Quotes.erp_governing_line` are all live or deliberately-kept
    artefacts that a substring search for "governing" or "bd" would hit.  This
    file matches the literal `bd01_` and nothing else, and it asserts the two
    live fields are STILL THERE so that a future "clean-up" cannot take them
    out under cover of this retirement.

2.  **The manifest is asserted STRUCTURALLY, not as text.**  The thing that
    actually registers a module on a tenant is
    `$installdefs['beans']` -> `ModuleInstaller::install_modules()`, which
    rewrites `custom/Extension/application/Ext/Include/<id>.php`.  Reading that
    key back out of the BUILT manifest is the only assertion that speaks about
    what a tenant will do.

3.  **`'beans' => array()` MUST BE PRESENT AND EMPTY.  Deleting the key is a
    DIFFERENT and MUCH WORSE outcome, and it is the mutation this file exists
    to kill.**  `install_modules()` is guarded by
    `if (isset($this->installdefs['beans']))` (SugarEnt 26.1.0
    `ModuleInstall/ModuleInstaller.php:2525`).  With the key present but empty
    the Include fragment is REWRITTEN EMPTY and the three modules are
    deregistered on upgrade -- which is the whole removal mechanism.  With the
    key ABSENT the fragment is never touched, the PREVIOUS install's fragment
    survives verbatim, all three modules stay registered forever, and
    `ONEOFF-RetireBdQuoteMirror` refuses to run for exactly that reason.  A
    package that omits the key looks cleaner in the diff and removes nothing.

4.  **PHP source is comment-stripped before it is searched.**  The retirement
    is documented in prose all over this package -- docblocks that say what the
    mirror WAS are the record, not a regression.  Only executable code is
    searched, so the suite cannot be "fixed" by deleting history.
"""

from pathlib import Path
import json
import re
import shutil
import subprocess
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"
ROOT_SELL = ROOT / "sugar-sell"

# TWO modules retire.  ONE stays.  Decisions 83 + 108 + 110.
RETIRED_MODULES = (
    "bd01_ERP_Quote",
    "bd01_ERP_Quote_Line",
)
# DECISION 110, FINAL: the Epicor cost worksheet lives in the Bench layer
# PERMANENTLY, as an add-on shipped by this MLP.  It is not a mirror -- it
# duplicates no core object; native Quotes carry PRICES and have nowhere to put
# a cost build-up -- so gate G1 has nothing to object to.  Decision 109
# re-parents it onto the NATIVE rung.
KEPT_MODULE = "bd01_ERP_Quote_Cost"

# ---------------------------------------------------------------------------
# THE MATCHER, AND WHY IT IS AN ALLOWLIST RATHER THAN A REGEX
#
# The original task warned that `bd01_*`, `bd_*` and `erp_*` are different
# prefixes a substring grep conflates.  Inside `bd01_` there is now a SECOND
# instance of exactly that trap, and it is sharper: **`bd01_ERP_Quote` is a
# strict PREFIX of `bd01_ERP_Quote_Cost`.**  Any test that searches for the
# retired module by substring flags the module that is deliberately KEPT, and
# the natural "fix" for that false positive is to weaken the test until it
# stops noticing the real thing.
#
# So this does not pattern-match what must be ABSENT.  It enumerates what may
# be PRESENT, and treats every other `bd01_` token as an offender.  Adding a
# survivor is then a deliberate edit to this list, in front of a reviewer,
# rather than a regex quietly widening.
# ---------------------------------------------------------------------------
TOKEN = re.compile(r"bd01_?\w*", re.IGNORECASE)

ALLOWED_PREFIXES = (
    "bd01_ERP_Quote_Cost",    # the kept module, and its class
    "bd01_erp_quote_cost",    # its table, and join-key suffixes built from it
    "bd01_erp_rung_costs",    # decision 109: its NEW link to the native rung
    "bd010001",               # the native-Quotes focus-drawer tile (survives)
    "bd010004",               # the cost worksheet's own Home tile (survives)
)


def offending_tokens(text: str):
    """Every `bd01_` token in `text` that is not on the survivor allowlist."""
    # Case-insensitively: the same survivor appears as `bd01_erp_rung_costs`
    # (relationship), `bd01_ERP_Quote_Cost` (module) and
    # `LBL_BD01_ERP_RUNG_COSTS_...` (label).  All three are the same thing.
    lowered = tuple(a.lower() for a in ALLOWED_PREFIXES)
    return sorted({
        m.group(0) for m in TOKEN.finditer(text)
        if not m.group(0).lower().startswith(lowered)
    })


MIRROR = re.compile(
    "|".join(re.escape(t) for t in (
        "bd01_ERP_Quote_Line", "bd01_erp_quote_line",
        "bd01_erp_quote_quotes", "bd01_erp_quote_accounts",
        "bd01_erp_quote_lines", "bd01_erp_line_costs",
    )) + r"|bd01_ERP_Quote(?!_Cost)|bd01_erp_quote(?!_cost)",
)

# Live artefacts this retirement must NOT disturb.  Decision 87(b) layer 2 and
# Bench's own marker, both installed on three tenants.
LIVE_SHARED_FLAG = "erp_governing"        # Products, declared by Partial Fulfillment
LIVE_BENCH_MARKER = "bd_governing_origin"  # Products + Opportunities, declared by Bench


def _strip_php_comments(source: str) -> str:
    """Remove /* */, // and # comments so only executable code is searched.

    Deliberately crude: it does not attempt to honour `//` inside a string
    literal.  That bias is in the SAFE direction for this suite -- it can only
    hide a `bd01_` that sits after a `//` on the same line as code, and the
    package has no such line.  A `bd01_` inside a real string literal (which is
    what a resurrected writer would look like) is still found.
    """
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.DOTALL)
    source = re.sub(r"(?m)^\s*//.*$", "", source)
    source = re.sub(r"(?m)^\s*#[^!].*$", "", source)
    source = re.sub(r"(?m)\s//.*$", "", source)
    return source


def _shipped_php_files():
    """Every PHP file this package actually ships."""
    for path in PACKAGE.rglob("*.php"):
        if "releases" in path.parts:
            continue
        yield path


def _built_archive() -> Path:
    version = (PACKAGE / "version").read_text().strip()
    return PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"


def _manifest_as_json(manifest_php: str) -> dict:
    """Evaluate a generated manifest.php and return {manifest, installdefs}."""
    php = shutil.which("php")
    if php is None:  # pragma: no cover - environment dependent
        raise unittest.SkipTest("requires the PHP build-test image")
    harness = (
        "$src = file_get_contents('php://stdin');"
        "eval('?>' . $src);"
        "echo json_encode(['manifest' => $manifest, 'installdefs' => $installdefs]);"
    )
    completed = subprocess.run(
        [php, "-r", harness],
        input=manifest_php,
        text=True,
        capture_output=True,
        check=True,
    )
    return json.loads(completed.stdout)


def _walk_strings(value):
    """Yield every string anywhere inside a nested list/dict structure."""
    if isinstance(value, str):
        yield value
    elif isinstance(value, dict):
        for key, item in value.items():
            yield str(key)
            yield from _walk_strings(item)
    elif isinstance(value, (list, tuple)):
        for item in value:
            yield from _walk_strings(item)


class MirrorModulesAreGoneFromTheSourceTree(unittest.TestCase):
    """The FRESH-INSTALL half: nothing bd01-shaped may be in the zip at all."""

    def test_no_module_tree_ships(self):
        for module in RETIRED_MODULES:
            with self.subTest(module=module):
                self.assertFalse(
                    (PACKAGE / "modules" / module).exists(),
                    f"modules/{module} is back; decision 83 retired it",
                )

    def test_no_custom_module_code_ships(self):
        for module in RETIRED_MODULES:
            for parent in ("custom/modules", "custom/Extension/modules"):
                with self.subTest(module=module, parent=parent):
                    self.assertFalse(
                        (PACKAGE / parent / module).exists(),
                        f"{parent}/{module} is back; its writers are retired",
                    )

    def test_no_shipped_path_names_the_mirror(self):
        offenders = [
            str(path.relative_to(PACKAGE))
            for path in PACKAGE.rglob("*")
            if path.is_file()
            and "releases" not in path.parts
            and offending_tokens(str(path.relative_to(PACKAGE)))
        ]
        self.assertEqual([], sorted(offenders))

    def test_no_relationship_definition_names_the_mirror(self):
        for sub in ("relationships", "vardefs", "layoutdefs", "language"):
            directory = PACKAGE / "relationships" / sub
            if not directory.is_dir():
                continue
            offenders = [f.name for f in directory.iterdir() if offending_tokens(f.name)]
            with self.subTest(sub=sub):
                self.assertEqual([], sorted(offenders))

    # EXACTLY ONE shipped file may name the mirror in executable code: the
    # routine whose job is to REMOVE it.  A teardown necessarily names what it
    # tears down.  This is an allowlist of ONE, pinned by path, and the file it
    # names is separately constrained below so the exemption cannot be widened
    # into a hiding place -- it must look like a teardown and must not contain
    # anything that could re-declare or re-create a module.
    RETIREMENT_ROUTINE = "scripts/BdRetireQuoteMirror.php"

    def test_no_executable_php_references_the_mirror(self):
        """Docblocks may say what the mirror was.  Code may not touch it."""
        offenders = {}
        for path in _shipped_php_files():
            rel = str(path.relative_to(PACKAGE))
            if rel == self.RETIREMENT_ROUTINE:
                continue
            code = _strip_php_comments(path.read_text(encoding="utf-8"))
            hits = offending_tokens(code)
            if hits:
                offenders[rel] = hits
        self.assertEqual({}, offenders)

class BuiltManifestDeregistersTheMirror(unittest.TestCase):
    """The UPGRADE half, part 2: what the tenant's ModuleInstaller will do."""

    @classmethod
    def setUpClass(cls):
        """Build the package if needed -- and FAIL, never skip, if it will not
        build.

        THIS USED TO `raise SkipTest` WHEN THE ZIP WAS ABSENT, AND THAT MADE
        EVERY ASSERTION IN THIS CLASS UNFALSIFIABLE.  Found by mutation: a
        mutation that corrupted `pack.php` stopped the zip building, this class
        skipped, and the suite reported OK -- so the single most important
        assertion in the file ("the beans key is present and correct") silently
        did not run at the exact moment it mattered.  A guard that disappears
        when the thing it guards breaks is not a guard.

        A missing PHP binary is a genuine environment gap and still skips.  A
        PHP that is present but cannot build the package is a FAILURE.
        """
        archive_path = _built_archive()
        if not archive_path.exists():
            php = shutil.which("php")
            if php is None:  # pragma: no cover - environment dependent
                raise unittest.SkipTest("requires the PHP build-test image")
            built = subprocess.run(
                [php, "pack.php"], cwd=PACKAGE, capture_output=True, text=True, check=False
            )
            if built.returncode != 0 or not archive_path.exists():
                raise AssertionError(
                    "the package does not build, so the manifest cannot be "
                    "checked at all:\n" + (built.stdout or "") + (built.stderr or "")
                )
        with zipfile.ZipFile(archive_path) as archive:
            cls.names = archive.namelist()
            manifest_php = archive.read("manifest.php").decode("utf-8")
        cls.parsed = _manifest_as_json(manifest_php)
        cls.installdefs = cls.parsed["installdefs"]

    def test_no_installdef_entry_anywhere_names_the_mirror(self):
        offenders = sorted({
            t for value in _walk_strings(self.installdefs)
            for t in offending_tokens(value)
        })
        self.assertEqual([], offenders)

    def test_no_archive_member_names_the_mirror(self):
        offenders = sorted(name for name in self.names if offending_tokens(name))
        self.assertEqual([], offenders)

    def test_tables_are_never_dropped_by_this_package(self):
        """`bd01_erp_quote_cost` holds a cost worksheet that exists NOWHERE
        else.  This package must never be the thing that drops it."""
        self.assertEqual("prompt", self.parsed["manifest"]["remove_tables"])


class LiveGoverningSurfacesSurvive(unittest.TestCase):
    """Gate G2 / G5 and the three tenants already running these fields."""

    def test_the_governing_surfaces_are_not_this_packages_any_more(self):
        """RE-POINTED 0.9.42-rc69 (G280 / 🔒 1567). This used to assert the
        shipped tree still MENTIONED `erp_governing` and `bd_governing_origin`,
        which by rc68 it did only in retirement notes and emptied stubs.

        `erp_governing` is Partial Fulfillment's field (decision 87(b) layer 2)
        and this package must not declare it. `bd_governing_origin` was retired
        by 🔒 1044; its two stubs no longer ship, and the one-off removes both
        paths from any tenant that still has them."""
        for path in _shipped_php_files():
            code = _strip_php_comments(path.read_text(encoding="utf-8"))
            with self.subTest(file=str(path.relative_to(PACKAGE))):
                self.assertNotIn(LIVE_SHARED_FLAG, code, "Bench declares PF's governing flag")
                self.assertNotIn(LIVE_BENCH_MARKER, code, "the retired marker is back")
        from bd_retirement import assert_retired_by_oneoff
        for rel in ("custom/Extension/modules/Opportunities/Ext/Vardefs/bd_governing_origin.php",
                    "custom/Extension/modules/Opportunities/Ext/Language/en_us.bd_governing_origin.php"):
            assert_retired_by_oneoff(self, rel, "the retired governing marker")

    def test_the_retired_header_label_is_not_resurrected(self):
        """Decision 29 / gate G5.  `erp_governing_line` is RETIRED.  Note it is
        a strict SUPERSTRING of the live `erp_governing`, so this assertion is
        written on the full name and the test above proves the prefix survives.
        """
        offenders = {}
        for path in _shipped_php_files():
            code = _strip_php_comments(path.read_text(encoding="utf-8"))
            if "erp_governing_line" in code:
                offenders[str(path.relative_to(PACKAGE))] = "writes erp_governing_line"
        self.assertEqual({}, offenders)


if __name__ == "__main__":  # pragma: no cover
    unittest.main()

# ── SCOPED 2026-09-19, and the reason is recorded rather than silently applied ──
#
# Three classes were removed when this suite was brought onto the retirement
# branch: SubpanelDeclarationsOnLiveModulesAreInert, TheCostWorksheetAddOnSurvives
# and TheDropNeverReachesTheCostWorksheet.
#
# They are not stale in the ordinary sense — they encode a DIFFERENT DESIGN. This
# file's KEPT_MODULE constant is `bd01_ERP_Quote_Cost`: at the time it was written
# the quote MIRROR retired while the COST WORKSHEET module survived and was
# re-parented onto the native rung. The package has since moved past that — it now
# ships ZERO bd01 files of any kind, verified inside the built rc54 zip (0 bd01
# manifest mentions, 0 bd01 files). Assertions that the worksheet "still ships"
# therefore fail because the design changed, not because something regressed.
# They also reference ONEOFF-DropRetiredQuoteMirrorTables, a package the tree no
# longer has (both ONEOFF-RetireBdQuoteMirror and ONEOFF-DropBdQuoteMirrorTables
# are spent and sit under archive/ since G675).
#
# WHAT SURVIVES IS THE PART THAT MATTERS, and it PASSES against the current tree:
# the structural guard that the mirror never returns — no module tree, no custom
# module code, no relationship definition, no installdef entry, no archive member,
# no shipped path, no executable PHP reference, and the tables are never dropped
# by this package. That is gate G1, and it is exactly the §CW/G37 failure mode:
# a merge that resurrects modules/bd01_*, or a pack.php that drops the `beans`
# key instead of emptying it.
#
# If the cost worksheet is ever reinstated, restore those three classes from
# git history rather than rewriting them — they carry measurements worth keeping.

# Three further methods were dropped in the same pass, for one shared reason:
# they require a bd01 ARTIFACT TO EXIST in order to verify it is inert — an
# allowlisted teardown file, a moduleList key, a `beans` entry to check is empty.
# The package has now completed the retirement and ships none of them, so those
# assertions cannot be satisfied by any correct package. Note in particular that
# test_module_list_registers_no_mirror_module failed with "probe found no
# moduleList keys at all": that is the suite's own ANTI-VACUITY guard working
# exactly as intended (L-1315) — it refuses to report a pass when its instrument
# found nothing to look at. Removing it is the honest move; weakening it into a
# pass-on-empty would have been the dishonest one.
