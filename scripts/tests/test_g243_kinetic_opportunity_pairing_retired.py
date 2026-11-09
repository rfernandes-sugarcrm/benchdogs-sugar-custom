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

🔁 RE-POINTED 0.9.42-rc69 (G280 / 🔒 1567, 🔒 1521). Both files did their job:
every QA tenant took a build carrying them, and then the one-off
ONEOFF-RetireBdResidue DELETED the registration and BLANKED the tombstone on all
three (et 2026-09-22 22:22Z; stock and Ophir 2026-09-23 00:58Z; failed 0). So
rc69 ships neither. 🔒2173b (2026-09-30) withdrew the one-off and deleted its
code. A tenant that still carries either path keeps it; nothing in this
repository removes it any more.

🔁 rc98: the cases that held the two retired paths out of the source and the
built zip are gone (test_g280_minimal_footprint pins the whole package to its
kept list, source and zip, so either path coming back fails there). What this
suite still holds is the live rule: NOTHING this package ships registers a hook
beyond the allowed two, or reaches the Opportunities bean layer at all, and the
seller's button (ERP-Epicor's) still creates its Opportunity.

MUTATION-VERIFIED at rc60-rc68 (each applied, suite re-run, failure observed):
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
from pathlib import Path

import shared_sugar

ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "sugar-sell/BenchDogs-Ext"

#: No shipped file may create an Opportunity any more. Until rc68 the one
#: exemption was BdBenchDogsActionsApi.php (the retired account action's
#: endpoint); rc69 ships that path EMPTY, so the exemption is gone too.

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


#: ERP-Epicor's own action API - the CONTROL: the seller's "Create Opportunity
#: & Quote" button must still create AND save its Opportunity, or this fix has
#: broken the thing it was protecting. Read from the pin under
#: fixtures/shared-sugar so it runs in CI too (the sibling repo is private);
#: test_shared_fixture_drift.py fails the moment the pin and core diverge.
EPICOR_ACTIONS = shared_sugar.resolve("AccountsErpActionsApi.php")


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

    #: The hooks this package may register, both BdAdmRules methods that set
    #: fields on the bean they are handed and create nothing:
    #:  - G380/G381 (🔒 1705b), G809: fills EMPTY ADM values on an unsent quote;
    #:  - G804 (🔒 2081b): on an Account not in the ERP, the Cust. Group name
    #:    follows the picked Group Code.
    #: In the order the harness sees them (Accounts' fragment first).
    # rc86 (MLP024): the namespaced class, no file - Sugar autoloads it from custom/src/BenchDogs.
    #:  - rc99: an Opportunity or ERP order saved with no owner takes the default
    #:    owner (BdDefaultOwner); it sets the owner field and creates nothing.
    #:  - rc121: a new Opportunity or Quote takes its account's owner (BdAccountOwner); it sets the owner field on
    #:    the bean it is handed, reads the account and the user, and creates nothing.
    ALLOWED = [["before_save", None, "Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules", "accountBeforeSave"],
               ["before_save", None, "Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdDefaultOwner", "beforeSave"],
               ["before_save", None, "Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAccountOwner", "beforeSave"],
               ["before_save", None, "Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdDefaultOwner", "beforeSave"],
               ["before_save", None, "Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAccountOwner", "beforeSave"],
               ["before_save", None, "Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules", "beforeSave"]]

    #: rc121: BdAccountOwner names the Opportunities module as a key (which field holds its account), so the bare
    #: word is not held against it; what it may read is held instead: Accounts only (users through BdDefaultOwner).
    NAMES_THE_MODULE = {"Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAccountOwner"}

    def test_no_shipped_fragment_registers_a_hook(self):
        """Every Extension fragment this package ships, INCLUDED into a harness
        that pre-seeds $hook_array - not grepped, because retirement prose names
        pairOnSave and after_save for the reader. Stronger than the rc60 case,
        which executed only bd_kinetic_opportunity.php: a re-registration under
        any other filename is caught too.

        🔁 G380/G381 (🔒 1705b): exactly the ALLOWED registrations (G804 added
        the second, 🔒 2081b), each named in full (event, file, class, method),
        and their one class is held below to creating and saving nothing - the
        property 🔒 1499 is about. Any other registration, under any file name,
        still fails here."""
        fragments = sorted(PACKAGE.glob("custom/Extension/**/*.php"))
        self.assertTrue(fragments, "no Extension fragment found - PACKAGE points at nothing")
        harness_body = (
            "$hook_array = array();\n"
            "foreach (array_slice($argv, 1) as $f) { require $f; }\n"
            "$entries = array();\n"
            "foreach ($hook_array as $event => $list) {\n"
            "    foreach ((array) $list as $e) { $entries[] = array($event, $e[2], $e[3], $e[4]); }\n"
            "}\n"
            "echo json_encode(array('entries' => $entries));\n")
        with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
            fh.write("<?php\n$dictionary = array(); $mod_strings = array(); $app_list_strings = array();\n"
                     + harness_body)
            harness = fh.name
        done = subprocess.run([_php(), harness] + [str(f) for f in fragments],
                              capture_output=True, text=True, check=False)
        self.assertEqual(0, done.returncode, (done.stderr or "")[:600])
        result = json.loads(done.stdout[done.stdout.find("{"):])
        self.assertEqual(
            self.ALLOWED, result["entries"],
            f"this package registers {result['entries']}. Only the ALLOWED hooks are "
            "allowed. 🔒 1499: the sync must NEVER create an Opportunity.")

    def test_the_one_allowed_hook_creates_and_saves_nothing(self):
        """The allowed hook's class, code only: it may set fields on the bean
        it is handed (before_save), and must never create or save a record."""
        # rc86: the class is registered with no file; Sugar's PSR-4 rule for custom/ names its path.
        # rc99: every allowed hook's class, not only the first.
        classes = sorted({entry[2] for entry in self.ALLOWED})
        self.assertEqual(len(classes), 3, classes)
        for name in classes:
            cls = name.removeprefix("Sugarcrm\\Sugarcrm\\custom\\").replace("\\", "/")
            body = (PACKAGE / "custom/src" / f"{cls}.php").read_text(encoding="utf-8")
            code = re.sub(r"/\*.*?\*/", "", body, flags=re.S)
            code = re.sub(r"(?m)(^|\s)//[^\n]*", r"\1", code)
            forbidden = ["->save(", "::newBean(", "Opportunit"]
            if name in self.NAMES_THE_MODULE:
                forbidden.remove("Opportunit")
                with self.subTest(cls=cls, reads="Accounts only"):
                    self.assertEqual(sorted(set(re.findall(r"(?:retrieveBean|getBean|newBean)\(\s*'(\w+)'", code))),
                                     ["Accounts"])
            elif name.endswith("BdAdmRules") or name.endswith("BdDefaultOwner"):
                forbidden = ["->save(", "::newBean('Opportunities'", "Opportunit"]
            for word in forbidden:
                with self.subTest(cls=cls, forbidden=word):
                    self.assertNotIn(word, code)


# ── 4. Nothing else in the package creates one, and the control still does ──


class NoOtherCreatorAndTheButtonSurvives(unittest.TestCase):
    def test_no_other_creator(self):
        """The whitelist, so the writer cannot simply move house."""
        offenders = []
        for path in PACKAGE.rglob("*.php"):
            if "releases" in path.parts:
                continue
            rel = path.relative_to(PACKAGE).as_posix()
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
            # PRECISE, because the loose form ("names Opportunities anywhere AND
            # calls newBean anywhere") flagged post_install.php the moment it
            # gained an unrelated BeanFactory::newBean('Administration') for the
            # rc65 stage config, while its only 'Opportunities' is a
            # RepairAndClear module list. What matters is a bean of THAT module.
            if re.search(r"(newBean|getBean|retrieveBean)\(\s*['\"]Opportunities['\"]", code):
                offenders.append(rel)
        self.assertEqual(
            [], sorted(offenders),
            "these shipped files reach the Opportunities bean layer: "
            f"{sorted(offenders)}. None may (🔒 1499; since rc69 not even the "
            "retired account action, whose api file ships empty).",
        )

    def test_the_sellers_button_still_creates_its_opportunity(self):
        """🛑 THE CONTROL. A fix that disabled the button's creation too has
        FAILED. The button is ERP-Epicor's - Bench's duplicate was retired by
        🔒 1044 / G15 - so this reads the sibling checkout."""
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
