"""Resolve the shared Sugar files these tests require.

The sibling `erp-integration-sugar` checkout wins whenever it is present, so a
developer with both trees keeps testing against core's REAL current file and
nothing about their workflow changes. Where it is absent -- CI, which cannot
check out a private repo in another org -- the pinned copy under
fixtures/shared-sugar/ is used instead, so the tests RUN rather than skip.

test_shared_fixture_drift.py is the other half: where both exist it proves the
pin still matches, so the fallback can never quietly drift away from core.

THE PIN TREE MIRRORS THE SIBLING (0.9.42-rc83, ERP-Epicor 1.2.0). Each pin sits
at fixtures/shared-sugar/<its path in erp-integration-sugar>, not flat. 1.2.0
(the Rafael review's T2) moved ERP-Core's and ERP-Epicor's classes into
custom/src/Erp as Sugarcrm\\Sugarcrm\\custom\\Erp\\*, found only by Sugar's
autoloader, and a class there loads its neighbours the same way. ERP-Core's
own PSR-4 stand-in (tests/support/sugar_autoloader.php, pinned here too)
registers its checkout's two package roots relative to itself, so the pinned
copy finds the pinned classes exactly as the sibling's finds the real ones:
one rule, no second copy of Sugar's autoload rule written here.
"""
from __future__ import annotations

import pathlib
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[2]


def _workspace() -> pathlib.Path:
    """The directory the two checkouts sit in.

    ROOT.parent is right for a normal clone and WRONG for a git worktree, which
    lives under /private/tmp or .claude/worktrees - and that silently turned the
    drift guard off for every worktree run (the same trap
    test_create_opp_quote_button_retired documents). Git's common dir points at
    the MAIN checkout's .git wherever the worktree happens to be, so ask git
    first and keep ROOT.parent as the fallback.
    """
    try:
        common = subprocess.run(
            ["git", "-C", str(ROOT), "rev-parse", "--git-common-dir"],
            capture_output=True, text=True, check=True,
        ).stdout.strip()
        candidate = (ROOT / common).resolve().parent.parent
        if (candidate / "erp-integration-sugar").is_dir():
            return candidate
    except (OSError, subprocess.CalledProcessError):
        pass
    return ROOT.parent


WORKSPACE = _workspace()
SIBLING = WORKSPACE / "erp-integration-sugar"
FIXTURES = pathlib.Path(__file__).resolve().parent / "fixtures/shared-sugar"

# name -> path inside the sibling checkout (and, under FIXTURES, of the pin)
SOURCES = {
    # 0.9.42-rc83: ERP-Epicor 1.2.0 (erp-integration-sugar 280e0929) moved this
    # and the three below into custom/src/Erp, namespaced and autoloaded; the
    # old custom/modules/... files no longer ship. Load them through
    # "sugar_autoloader.php", never by require: QuoteOpportunityAmount no
    # longer require_once's its enforcer, it autoloads it.
    "QuoteOpportunityAmount.php":
        "sugar-sell/ERP-Core/src/custom/src/Erp/QuoteOpportunityAmount.php",
    "ErpOpportunityValuation.php":
        "sugar-sell/ERP-Epicor-PartialFulfillment/custom/modules/Quotes/"
        "ErpOpportunityValuation.php",
    "ErpQuoteLineRollup.php":
        "sugar-sell/ERP-Epicor-PartialFulfillment/custom/modules/Quotes/"
        "ErpQuoteLineRollup.php",
    # Added 0.9.42-rc65 for G276: the Quotes record-view buttons are CORE's to
    # add back after this package stopped stripping them, and the proof has to
    # run core's REAL add-if-absent over core's REAL button definitions. Pinned
    # rather than sibling-gated, for the reason this whole mechanism exists: a
    # test that is collected and then skipped is documentation, not a guard.
    # QuoteOpportunityAmount.php require_once's this NEIGHBOUR at load time
    # (__DIR__ . '/QuotePrimaryQuoteSoleEnforcer.php'), so pinning one without
    # the other is a fatal the moment the sibling checkout is absent - i.e. in
    # CI, and only in CI. (From 1.2.0 the neighbour is AUTOLOADED, which the
    # mirrored pin tree and the pinned autoloader provide.)
    "QuotePrimaryQuoteSoleEnforcer.php":
        "sugar-sell/ERP-Core/src/custom/src/Erp/QuotePrimaryQuoteSoleEnforcer.php",
    # Pinned 0.9.42-rc65 so the CORE-side controls run in CI rather than
    # skipping: core's billing-country guard (this package ships none),
    # ERP-Epicor's createOppQuote (G243's control - the seller's button must
    # still create its Opportunity) and the label that collides with the
    # retired Bench button (G15's root cause).
    "ErpAccountCountryGuard.php":
        "sugar-sell/ERP-Core/src/custom/src/Erp/ErpAccountCountryGuard.php",
    "AccountsErpActionsApi.php":
        "sugar-sell/ERP-Epicor/src/custom/clients/base/api/AccountsErpActionsApi.php",
    "en_us.erp_create_opp_quote.php":
        "sugar-sell/ERP-Epicor/src/custom/Extension/modules/Accounts/Ext/Language/en_us.erp_create_opp_quote.php",
    "BaseErpLayout.php":
        "sugar-sell/ERP-Core/scripts/BaseErpLayout.php",
    "QuotesLayout.php":
        "sugar-sell/ERP-Epicor/scripts/Modules/QuotesLayout.php",
    # 0.9.42-rc70 (G380 / 🔒 1724b): what the slimmed Bench package now ASKS of
    # ERP-Epicor >= 1.1.125 instead of carrying itself - the quote facts
    # (company, product group), the marker-driven field placement, and the
    # generic erp_reference field whose marker the Bench pickers are placed
    # after. Pinned from the LANDED Sugar target a0f6b632, so rc70's tests run
    # against lane D's real code, not stand-ins. From 0.9.42-rc83 this is
    # ERP-Epicor 1.2.0's Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts (in
    # ERP-EPICOR's custom/src, not ERP-Core's). The GLOBAL class every ERP-Epicor
    # before 1.2.0 ships is gone from the sibling tree, so it is pinned to its
    # last commit under fixtures/erp-epicor-1.1/ instead (see
    # test_erp_epicor_1_1_fixture_pin.py): BdAdmRules must keep working on both.
    "ErpQuoteFacts.php":
        "sugar-sell/ERP-Epicor/src/custom/src/Erp/ErpQuoteFacts.php",
    "ErpLayoutExtraFields.php":
        "sugar-sell/ERP-Core/src/custom/include/ErpLayoutExtraFields.php",
    "erp_reference.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/Quotes/Ext/Vardefs/erp_reference.php",
    # 0.9.42-rc71 (G450): ERP-Core's REPLACE-mode account_type_dom, a WHOLE-ARRAY
    # assignment {Prospect, Customer}. The hostile case the Bench Suspect fragment
    # must survive (test_g450_suspect_account_type.py). Byte-identical at a0f6b632
    # and at the Sugar target c9b74508 (last changed f4a3036d).
    "account_type_dom.replace.php":
        "sugar-sell/ERP-Core/src/custom/dropdowntemplates/account_type_dom.replace.php",
    # 0.9.42-rc83: ERP-Core's test-only stand-in for SugarAutoLoader (the PSR-4
    # rule Sugarcrm\Sugarcrm\custom\X\Y -> custom/src/X/Y.php, and the custom
    # include/ and clients/base/api/ dirs). Required, it registers ERP-Epicor's
    # and ERP-Core's src/custom RELATIVE TO ITSELF - the sibling's copy the
    # sibling's packages, this pin the pinned ones - and defines
    # ERP_TEST_SUPPORT, the directory any further upstream stub is required
    # relative to (never by an absolute path).
    "sugar_autoloader.php":
        "sugar-sell/ERP-Core/tests/support/sugar_autoloader.php",
}


def sibling_path(name: str) -> pathlib.Path:
    """Where the file lives in a real erp-integration-sugar checkout."""
    return SIBLING / SOURCES[name]


def pinned_path(name: str) -> pathlib.Path:
    """Where the pin lives: the same relative path, under FIXTURES."""
    return FIXTURES / SOURCES[name]


def resolve(name: str) -> pathlib.Path:
    """The sibling file when present, else the pinned copy.

    Never raises on a missing sibling - that is the whole point. The returned
    path is always a real file, so callers do not need a skip guard.
    """
    live = sibling_path(name)
    return live if live.is_file() else pinned_path(name)


def using_pin(name: str) -> bool:
    return not sibling_path(name).is_file()
