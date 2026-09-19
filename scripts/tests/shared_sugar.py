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

ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKSPACE = ROOT.parent
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
