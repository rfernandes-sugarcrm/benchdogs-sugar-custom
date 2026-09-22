#!/usr/bin/env python3
"""G243 / 🔒 1499 — the quote sync must never create an Opportunity, and the
retirement has to RUN on a tenant that already has the writer.

🛑 THE DEFECT, AS MEASURED. Read-only on Bench at 06:51Z on 2026-09-22: the two
connector-created quotes 317 (``EPIC06__1269``) and 318 (``EPIC06__1270``) were
created 22:13:14 by ``svc erpconnector.bench`` and each carried a brand-new
Opportunity - "Dalton Manufacturing - Kinetic Quote 1269"/"1270" - created by
the SAME account at 22:13:20. 🔒 1473 said core's quote sync never touches
Opportunities; 🔒 1499 then ruled on it in the owner's own words: *"YES — the
sync must never create one"*. An Opportunity is forecastable, so one per synced
ERP quote inflates the pipeline with deals no seller made and no seller owns.

🚩 WHERE IT CAME FROM, AND WHY "we don't ship it any more" WAS NOT A FIX. The
writer is ``BdKineticOpportunityHook`` - ``BdQuoteReflectionHook::
ensureOpportunity()`` relocated onto the native Quote by ``fe18b38`` - shipped
in 0.9.42-rc39 and rc40 from build branches that were never merged to main.
**rc39 is what Bench had installed** (the rc40 install died at 13/19 and rolled
back), and rc60 ships neither the class nor its registration. It kept creating
Opportunities anyway, because **Module Loader copies a package's files and never
deletes the previous version's**. rc39's registration stayed on disk, stayed
compiled into ``custom/modules/Quotes/Ext/LogicHooks/logichooks.ext.php``, and
kept firing. That is rc24's lesson, already written down in
``test_create_opp_quote_button_retired.py``: **ONLY OVERWRITING RETIRES.**

So the fix is two shipped files that OVERWRITE rc39's, and this suite EXECUTES
both rather than reading them:

1.  ``custom/Extension/modules/Quotes/Ext/LogicHooks/bd_kinetic_opportunity.php``
    registers NOTHING. ``install_copy`` overwrites rc39's copy and
    ``install_extensions`` - which runs BEFORE ``post_execute`` (SugarEnt 26.1.0
    ``ModuleInstall/ModuleInstaller.php:261``) - rebuilds the compiled hook file
    without the two entries.
2.  ``custom/modules/Quotes/BdKineticOpportunityHook.php`` is a tombstone whose
    three public entry points write nothing. This is the half that holds when
    the first half does not run, i.e. exactly the failure mode being fixed.

📌 WHY EVERY ASSERTION IS SHAPED THE WAY IT IS.

*   **The registration is INCLUDED, not grepped.** This file is mostly docblock,
    and a docblock names ``$hook_array``, ``after_save`` and ``pairOnSave`` for
    the reader. Any text search either trips on the prose or has to be written
    loosely enough to miss a real re-registration. Including it into a harness
    that pre-seeds ``$hook_array`` and reads it back afterwards is the only read
    that cannot be fooled either way.
*   **The class is CALLED, not read.** ``pair()`` returning false is a claim
    about behaviour. The harness gives it a ``BeanFactory`` that COUNTS bean
    creation, so "creates no Opportunity" is measured at the only place an
    Opportunity can come from, not inferred from the absence of a string.
*   **The package is BUILT.** A correct source file that ``pack.php`` does not
    copy retires nothing on a tenant. The copy entries are read out of the built
    manifest, which is what ``ModuleInstaller`` actually consumes.
*   **The control is asserted in the other direction.** A fix that also stopped
    the seller's "Create Opportunity & Quote" button has FAILED, so the suite
    pins that ERP-Epicor's ``AccountsErpActionsApi::createOppQuote`` still
    creates and saves its Opportunity, and that it never routed through this
    hook.

MUTATION-VERIFIED (each applied, suite re-run, listed failure observed):
  restore either ``$hook_array[...][] = array(2, ... 'pairOnSave')``
                                              -> registers_nothing fails
  make ``pair()`` create + save an Opportunity -> creates_no_opportunity fails
  make ``pairOnAccountLink()`` call ``pair()`` on a real writer
                                              -> creates_no_opportunity fails
  delete either shipped file                   -> ships_and_overwrites fails
  drop the ``custom/`` glob from pack.php      -> ships_and_overwrites fails
  add ``newBean('Opportunities')`` to any other shipped file
                                              -> no_other_creator fails
  remove the Opportunity create from AccountsErpActionsApi
                                              -> control fails (cross-repo)
"""

import json
import re
import shutil
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"

REGISTRATION = (
    PACKAGE / "custom/Extension/modules/Quotes/Ext/LogicHooks/bd_kinetic_opportunity.php"
)
TOMBSTONE = PACKAGE / "custom/modules/Quotes/BdKineticOpportunityHook.php"

#: The two paths a tenant must end up with, exactly as `installdefs['copy']`
#: spells them. These are rc39's own paths - overwriting is the whole point, so
#: a change here that "tidies" either path silently stops retiring anything.
MUST_OVERWRITE = (
    "custom/Extension/modules/Quotes/Ext/LogicHooks/bd_kinetic_opportunity.php",
    "custom/modules/Quotes/BdKineticOpportunityHook.php",
)

#: The ONE shipped file in this package that may create an Opportunity: the
#: account-level action, which runs under a caller's ACL check and is not the
#: sync. Bench's own button is retired (🔒 1044 / G15) but the endpoint remains.
ALLOWED_CREATOR = "custom/clients/base/api/BdBenchDogsActionsApi.php"

#: ERP-Epicor lives in a sibling checkout; the control is only assertable when
#: it is present, so that case skips rather than fails when it is not.
_EPICOR_REL = (
    "erp-integration-sugar/sugar-sell/ERP-Epicor/src/custom/clients/base/api"
    "/AccountsErpActionsApi.php"
)


def _php() -> str:
    php = shutil.which("php")
    if php is None:  # pragma: no cover - environment dependent
        raise unittest.SkipTest("requires the PHP build-test image")
    return php


def _candidate_roots():
    """Every place the sibling checkout could be, MAIN CLONE FIRST.

    🚩 A LANE WORKS IN A WORKTREE, AND A WORKTREE IS NOT NEXT TO ITS SIBLINGS.
    ``test_create_opp_quote_button_retired.py`` walks up from ``ROOT``, which
    resolves from a normal clone and not from a worktree - and its own docstring
    records that it silently skipped for exactly that reason. Walking up from
    ``/private/tmp/<lane>`` finds nothing at all, so the CONTROL - the assertion
    that the seller's button still creates its Opportunity - would be reported
    as "skipped" at the one moment it matters. That is unfalsifiable, so the
    real clone is asked for by name first, through git.
    """
    try:
        common = subprocess.run(
            ["git", "-C", str(ROOT), "rev-parse", "--path-format=absolute",
             "--git-common-dir"],
            capture_output=True, text=True, check=True,
        ).stdout.strip()
        if common:
            yield Path(common).resolve().parent.parent
    except Exception:  # pragma: no cover - git absent or not a repo
        pass
    here = ROOT
    for _ in range(5):
        yield here.parent
        here = here.parent


def _find_epicor_actions() -> Path:
    for base in _candidate_roots():
        cand = base / _EPICOR_REL
        if cand.exists():
            return cand
    return ROOT.parent / _EPICOR_REL


EPICOR_ACTIONS = _find_epicor_actions()


def _strip_php_comments(path: Path) -> str:
    """Executable PHP only.

    The retirement is documented in prose all over this package - docblocks that
    say what the writer DID are the record, not a regression. Stripping comments
    with PHP's own tokenizer means the suite cannot be "passed" by deleting
    history, and cannot be "failed" by writing it down.
    """
    harness = (
        "$src = file_get_contents($argv[1]);"
        "$out = '';"
        "foreach (token_get_all($src) as $t) {"
        "  if (is_array($t)) {"
        "    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= ' '; continue; }"
        "    $out .= $t[1];"
        "  } else { $out .= $t; }"
        "}"
        "echo $out;"
    )
    done = subprocess.run(
        [_php(), "-r", harness, str(path)], capture_output=True, text=True, check=True
    )
    return done.stdout


# ── 1. The registration registers nothing ───────────────────────────────────

_REGISTRATION_HARNESS = r"""
$hook_array = array();
require $argv[1];
$total = 0;
$events = array();
foreach ($hook_array as $event => $entries) {
    if (!is_array($entries)) { continue; }
    $total += count($entries);
    if (count($entries)) { $events[] = $event; }
}
echo json_encode(array('total' => $total, 'events' => $events));
"""


class TheRegistrationRegistersNothing(unittest.TestCase):
    """The first half of the retirement, EXECUTED."""

    def test_registers_nothing(self):
        with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
            fh.write("<?php\n" + _REGISTRATION_HARNESS)
            harness = fh.name
        done = subprocess.run(
            [_php(), harness, str(REGISTRATION)],
            capture_output=True, text=True, check=False,
        )
        self.assertEqual(
            0, done.returncode,
            "the registration file does not even parse: " + (done.stderr or "")[:600],
        )
        start = done.stdout.find("{")
        self.assertGreaterEqual(start, 0, f"harness produced no JSON: {done.stdout[:300]!r}")
        result = json.loads(done.stdout[start:])
        self.assertEqual(
            0, result["total"],
            "bd_kinetic_opportunity.php registers "
            f"{result['total']} logic hook(s) on {result['events']}. 🔒 1499: the sync "
            "must NEVER create an Opportunity. Registering pairOnSave or "
            "pairOnAccountLink again re-arms G243 on every tenant.",
        )

    def test_the_file_is_still_shipped_at_rc39s_own_path(self):
        """An ABSENT file retires nothing: rc39's copy simply survives.

        This is the assertion that stops the fix being "cleaned up" into a
        deletion, which is exactly how the writer outlived the package that
        shipped it in the first place.
        """
        self.assertTrue(
            REGISTRATION.is_file(),
            "the empty registration is gone. Deleting it does NOT remove rc39's "
            "copy from a tenant - ONLY OVERWRITING RETIRES (rc24).",
        )


# ── 2. The class creates nothing ────────────────────────────────────────────

_CLASS_HARNESS = r"""
class SugarBean {
    public $id = '';
    public $module_dir = '';
    public $saved = 0;
    public function save($check_notify = false) { $this->saved++; return $this->id; }
    public function load_relationship($name) { return false; }
}

class _Opportunity extends SugarBean { public $module_dir = 'Opportunities'; }

class BeanFactory {
    public static $created = array();
    public static $retrieved = array();
    public static function newBean($module) {
        self::$created[] = $module;
        return new _Opportunity();
    }
    public static function getBean($module, $id = null) {
        self::$retrieved[] = $module;
        return new _Opportunity();
    }
    public static function retrieveBean($module, $id = null, $params = array()) {
        self::$retrieved[] = $module;
        return new _Opportunity();
    }
}

class _Log { public function __call($m, $a) {} }
$GLOBALS['log'] = new _Log();

require $argv[1];

$quote = new SugarBean();
$quote->id = 'quote-318';
$quote->module_dir = 'Quotes';
$quote->erp_sync_key = 'EPIC06__1270';

$hook = new BdKineticOpportunityHook();
$errors = array();

// The two rc39 entry points, called with rc39's own signatures, plus the
// public API the mirror's call site used to delegate to.
try { $hook->pairOnSave($quote, 'after_save', array()); }
catch (Throwable $e) { $errors[] = 'pairOnSave: ' . $e->getMessage(); }

try { $hook->pairOnAccountLink($quote, 'after_relationship_add',
    array('link' => 'billing_accounts', 'related_id' => 'acct-1')); }
catch (Throwable $e) { $errors[] = 'pairOnAccountLink: ' . $e->getMessage(); }

$paired = null;
try { $paired = $hook->pair($quote, new SugarBean()); }
catch (Throwable $e) { $errors[] = 'pair: ' . $e->getMessage(); }

echo json_encode(array(
    'created'   => BeanFactory::$created,
    'retrieved' => BeanFactory::$retrieved,
    'saved'     => $quote->saved,
    'paired'    => $paired,
    'errors'    => $errors,
));
"""


class TheClassCreatesNoOpportunity(unittest.TestCase):
    """The second half, EXECUTED - and this is the one that measures the rule.

    🚩 The stub ``BeanFactory`` counts bean creation, so a re-implementation that
    builds the Opportunity through ``getBean``/``retrieveBean`` instead of
    ``newBean`` is caught too. Only "no bean of any kind, from any door" passes.
    """

    @classmethod
    def setUpClass(cls):
        with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
            fh.write("<?php\n" + _CLASS_HARNESS)
            harness = fh.name
        done = subprocess.run(
            [_php(), harness, str(TOMBSTONE)],
            capture_output=True, text=True, check=False,
        )
        if done.returncode != 0:
            raise AssertionError(
                "the tombstone does not run: " + (done.stderr or done.stdout or "")[:800]
            )
        start = done.stdout.find("{")
        if start < 0:
            raise AssertionError(f"harness produced no JSON: {done.stdout[:400]!r}")
        cls.result = json.loads(done.stdout[start:])

    def test_the_entry_points_do_not_raise(self):
        """A tenant still carrying rc39's compiled registration calls straight
        into this class on every quote save. Throwing there would turn a silent
        forecast defect into a broken save - a worse outcome than the bug."""
        self.assertEqual([], self.result["errors"])

    def test_creates_no_opportunity(self):
        self.assertEqual(
            [], self.result["created"],
            "BdKineticOpportunityHook created " + str(self.result["created"])
            + ". 🔒 1499: the sync must never create an Opportunity.",
        )
        self.assertEqual(
            [], self.result["retrieved"],
            "BdKineticOpportunityHook loaded " + str(self.result["retrieved"])
            + " - the tombstone must not reach the bean layer at all.",
        )

    def test_writes_nothing_to_the_quote_either(self):
        """🔒 1473 is 'quote only', not 'quote plus a stamp'. The retired writer
        deliberately wrote no flag of its own; the tombstone writes nothing at
        all, so it cannot be the thing that moves erp_is_primary_quote."""
        self.assertEqual(0, self.result["saved"])

    def test_pair_reports_that_it_paired_nothing(self):
        """``pair()`` returned true when it had created and linked one. False is
        the honest answer now, and the mirror's old call site read it."""
        self.assertIs(False, self.result["paired"])


# ── 3. Both files actually ship ─────────────────────────────────────────────


class TheRetirementShipsAndOverwrites(unittest.TestCase):
    """A correct source file that pack.php does not copy retires nothing.

    FAILS, never skips, when the package will not build: a guard that disappears
    when the thing it guards breaks is not a guard
    (``test_bd01_mirror_retired.py`` learned this the expensive way).
    """

    @classmethod
    def setUpClass(cls):
        version = (PACKAGE / "version").read_text().strip()
        archive = PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        # 🚩 ALWAYS REBUILD. `pack.php` refuses to overwrite an existing zip, so
        # a "build if absent" guard reads whatever the LAST build produced - and
        # under mutation that is a stale artifact still containing the file the
        # mutation just removed, i.e. a pass that proves nothing. Found by
        # mutation: dropping the `custom/` glob from pack.php left the previous
        # zip in place and this class went green.
        if archive.exists():
            archive.unlink()
        built = subprocess.run(
            [_php(), "pack.php"], cwd=PACKAGE,
            capture_output=True, text=True, check=False,
        )
        if built.returncode != 0 or not archive.exists():
            raise AssertionError(
                "the package does not build, so the copy entries cannot be "
                "checked at all:\n" + (built.stdout or "") + (built.stderr or "")
            )
        with zipfile.ZipFile(archive) as zf:
            cls.names = set(zf.namelist())
            manifest_php = zf.read("manifest.php").decode("utf-8")
        harness = (
            "$src = file_get_contents('php://stdin');"
            "eval('?>' . $src);"
            "echo json_encode(['installdefs' => $installdefs]);"
        )
        done = subprocess.run(
            [_php(), "-r", harness], input=manifest_php,
            text=True, capture_output=True, check=True,
        )
        cls.copies = {
            entry["to"]: entry["from"]
            for entry in json.loads(done.stdout)["installdefs"].get("copy", [])
        }

    def test_ships_and_overwrites(self):
        for target in MUST_OVERWRITE:
            with self.subTest(target=target):
                self.assertIn(
                    target, self.names,
                    f"{target} is not in the built package, so rc39's copy of it "
                    "survives on every tenant that has one.",
                )
                self.assertIn(
                    target, self.copies,
                    f"{target} ships in the zip but has no installdefs['copy'] "
                    "entry, so ModuleInstaller never writes it over rc39's.",
                )


# ── 4. Nothing else in the package creates one, and the control still does ──


class NoOtherCreatorAndTheButtonSurvives(unittest.TestCase):
    def test_no_other_creator(self):
        """The whitelist, so the writer cannot simply move house."""
        offenders = []
        for path in PACKAGE.rglob("*.php"):
            if "releases" in path.parts:
                continue
            rel = path.relative_to(PACKAGE).as_posix()
            if rel == ALLOWED_CREATOR:
                continue
            code = _strip_php_comments(path)
            # A bean handed straight to SugarQuery::from() is a QUERY SEED: it is
            # never saved, so it cannot create anything. G234's pre_uninstall
            # counts Opportunities on a Bench stage exactly this way (read-only,
            # team security off) - integrating G243 onto G234 for rc64 is what
            # surfaced it. Only that idiom is set aside; any other
            # newBean('Opportunities') still fails this case.
            code = re.sub(
                r"->from\(\s*BeanFactory::newBean\(\s*['\"]Opportunities['\"]\s*\)",
                "->from(<query seed>", code)
            if "'Opportunities'" in code or '"Opportunities"' in code:
                if "newBean" in code or "getBean" in code:
                    offenders.append(rel)
        self.assertEqual(
            [], sorted(offenders),
            "these shipped files reach the Opportunities bean layer: "
            f"{sorted(offenders)}. Only {ALLOWED_CREATOR} may, and only because "
            "it is an ACL-checked account action, not the sync (🔒 1499).",
        )

    def test_the_sellers_button_still_creates_its_opportunity(self):
        """🛑 THE CONTROL. A fix that disabled the button's creation too has
        FAILED. The button is ERP-Epicor's - Bench's duplicate was retired by
        🔒 1044 / G15 - so this reads the sibling checkout."""
        if not EPICOR_ACTIONS.exists():  # pragma: no cover - environment dependent
            raise unittest.SkipTest(f"sibling checkout not present: {EPICOR_ACTIONS}")
        code = _strip_php_comments(EPICOR_ACTIONS)
        self.assertIn(
            "BeanFactory::newBean('Opportunities')", code,
            "AccountsErpActionsApi::createOppQuote no longer creates an "
            "Opportunity. The seller's button is the control 🔒 1499 names and "
            "it must keep working exactly as it did.",
        )
        self.assertIn(
            "$opp->save()", code,
            "the button builds an Opportunity bean but never saves it",
        )
        self.assertNotIn(
            "BdKineticOpportunityHook", code,
            "the seller's button must not depend on the retired pairing writer",
        )


if __name__ == "__main__":
    unittest.main()
