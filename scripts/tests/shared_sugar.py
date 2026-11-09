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
    # ERP-Epicor-PartialFulfillment (erp-integration-sugar ee81f58a, pinned at
    # b1f6e023) moved these two into custom/src/Erp under the same namespace.
    "ErpOpportunityValuation.php":
        "sugar-sell/ERP-Epicor-PartialFulfillment/custom/src/Erp/"
        "ErpOpportunityValuation.php",
    "ErpQuoteLineRollup.php":
        "sugar-sell/ERP-Epicor-PartialFulfillment/custom/src/Erp/"
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
    # ERP-Epicor 1.2.121 line: the primary-quote enforcer reads which session is
    # the connector's (ErpConnectorSession, autoloaded), and the Opportunity
    # valuation reads the committed quote stages from one place
    # (ErpQuoteStageRules). Without these two pinned, every amount refresh
    # fails with "class not found" whenever the sibling checkout is absent.
    "ErpConnectorSession.php":
        "sugar-sell/ERP-Core/src/custom/src/Erp/ErpConnectorSession.php",
    "ErpQuoteStageRules.php":
        "sugar-sell/ERP-Core/src/custom/src/Erp/ErpQuoteStageRules.php",
    # Pinned 0.9.42-rc65 so the CORE-side controls run in CI rather than
    # skipping: core's billing-country guard (this package ships none) and
    # ERP-Epicor's createOppQuote (G243's control - the seller's button must
    # still create its Opportunity).
    "ErpAccountCountryGuard.php":
        "sugar-sell/ERP-Core/src/custom/src/Erp/ErpAccountCountryGuard.php",
    "AccountsErpActionsApi.php":
        "sugar-sell/ERP-Epicor/src/custom/clients/base/api/AccountsErpActionsApi.php",
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
    # ERP-EPICOR's custom/src, not ERP-Core's). The package refuses to install
    # below ERP-Epicor 1.2.4, so the 1.1 global class is not pinned.
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
    # 0.9.42-rc85 (G848, 🔒2159b): the two ERP-Core captions the Bench language overrides relabel, so the test
    # reads the KEY names and ERP-Core's own text from ERP-Core rather than restating them.
    "en_us.erp_quote_charges.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/Quotes/Ext/Language/en_us.erp_quote_charges.php",
    "en_us.erp_quote_grid_money_labels.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/Products/Ext/Language/en_us.erp_quote_grid_money_labels.php",
    # 0.9.42-rc94: ERP-Core's rule that a quote line's Part Number is read-only. The Bench rule rewrites it for a new
    # product that is not in the catalog, so the test merges the two the way Sugar does and reads ERP-Core's real one.
    "erp_part_number_read_only.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/Products/Ext/Dependencies/erp_part_number_read_only.php",
    # 0.9.46-rc101: ERP-Core's pick list field, which reads this package's required keys on the quote pickers and
    # decides on the page whether each one is required. The Project test runs it in Sugar's own record view.
    "erp-dependent-enum.js":
        "sugar-sell/ERP-Core/src/custom/clients/base/fields/erp-dependent-enum/erp-dependent-enum.js",
    # 0.9.46-rc114: ERP-Epicor's rule for which quantity ladder a quoted line item belongs to (ladderGroupOf). The
    # Bench quote PDF template groups a line's price breaks by the same rule, written in Smarty because a PDF Manager
    # template cannot call PHP; the render test holds the two to the same answer on every shape of line.
    "ErpLineRole.php":
        "sugar-sell/ERP-Epicor/src/custom/src/Erp/ErpLineRole.php",
    # rc117: the quick search over the Product Catalog. ERP-Core adds the part number to
    # ProductTemplates' quick-search fields, so the Bench package adds only the column.
    "erp_part_number.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/ProductTemplates/Ext/clients/base/filters/basic/"
        "erp_part_number.php",
    # rc118: ERP-Core's five billing address vardefs (required, a Customer-only required_formula, no placeholder).
    # The Bench override makes the formula of four of them 'true'; the test merges these real files with the
    # override in Sugar's extension order and checks what the merged dictionary holds.
    "billing_address_street_required_formula.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/Accounts/Ext/Vardefs/billing_address_street_required_formula.php",
    "billing_address_city_required_formula.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/Accounts/Ext/Vardefs/billing_address_city_required_formula.php",
    "billing_address_state_required_formula.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/Accounts/Ext/Vardefs/billing_address_state_required_formula.php",
    "billing_address_postalcode_required_formula.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/Accounts/Ext/Vardefs/"
        "billing_address_postalcode_required_formula.php",
    "billing_address_country_required_formula.php":
        "sugar-sell/ERP-Core/src/custom/Extension/modules/Accounts/Ext/Vardefs/"
        "billing_address_country_required_formula.php",
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
