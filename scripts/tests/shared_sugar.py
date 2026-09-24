"""Resolve the shared Sugar files these tests require.

The sibling `erp-integration-sugar` checkout wins whenever it is present, so a
developer with both trees keeps testing against core's REAL current file and
nothing about their workflow changes. Where it is absent -- CI, which cannot
check out a private repo in another org -- the pinned copy under
fixtures/shared-sugar/ is used instead, so the tests RUN rather than skip.

test_shared_fixture_drift.py is the other half: where both exist it proves the
pin still matches, so the fallback can never quietly drift away from core.
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

# name -> path inside the sibling checkout
SOURCES = {
    "QuoteOpportunityAmount.php":
        "sugar-sell/ERP-Core/src/custom/modules/Quotes/QuoteOpportunityAmount.php",
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
    # CI, and only in CI.
    "QuotePrimaryQuoteSoleEnforcer.php":
        "sugar-sell/ERP-Core/src/custom/modules/Quotes/QuotePrimaryQuoteSoleEnforcer.php",
    # Pinned 0.9.42-rc65 so the CORE-side controls run in CI rather than
    # skipping: core's billing-country guard (this package ships none),
    # ERP-Epicor's createOppQuote (G243's control - the seller's button must
    # still create its Opportunity) and the label that collides with the
    # retired Bench button (G15's root cause).
    "ErpAccountCountryGuard.php":
        "sugar-sell/ERP-Core/src/custom/modules/Accounts/ErpAccountCountryGuard.php",
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
    # against lane D's real code, not stand-ins.
    "ErpQuoteFacts.php":
        "sugar-sell/ERP-Epicor/src/custom/modules/Quotes/ErpQuoteFacts.php",
    "ErpLayoutExtraFields.php":
        "sugar-sell/ERP-Core/src/custom/include/ErpLayoutExtraFields.php",
    "erp_reference.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/Quotes/Ext/Vardefs/erp_reference.php",
}


def sibling_path(name: str) -> pathlib.Path:
    """Where the file lives in a real erp-integration-sugar checkout."""
    return SIBLING / SOURCES[name]


def resolve(name: str) -> pathlib.Path:
    """The sibling file when present, else the pinned copy.

    Never raises on a missing sibling - that is the whole point. The returned
    path is always a real file, so callers do not need a skip guard.
    """
    live = sibling_path(name)
    return live if live.is_file() else FIXTURES / name


def using_pin(name: str) -> bool:
    return not sibling_path(name).is_file()
