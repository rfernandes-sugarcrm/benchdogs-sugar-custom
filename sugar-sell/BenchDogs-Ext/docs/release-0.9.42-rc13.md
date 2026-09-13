# BenchDogs-Ext 0.9.42-rc13 — fail-closed commercial charges

Release candidate. It has not yet been uploaded or installed. It requires
shared ERP-Epicor `1.1.24-rc9`, Partial Fulfillment `1.0.13`, and SDK `1.18`.
It must never be installed on the stock tenant.

## Repair

The Bench contribution provider now requires explicit native Quote tax and
shipping values. Missing or null values mean the Quote read is incomplete;
they no longer silently reduce the Opportunity amount by being fabricated as
zero. A real numeric zero remains valid. The selected governing production
line's ERP extended price remains authoritative, so negotiated/discounted
pricing is not recalculated from quantity and unit price.

The business formula is unchanged: exactly one selected governing production
option + at most one prototype + native Quote tax + native Quote shipping.
All other production options remain visible and contribute zero. ERP-Core
remains the sole writer and currency owner. No Opportunity Revenue Line Item
module, lookup, hook or fallback is introduced.

## Installation and rollback

Upgrade only after ERP-Epicor `1.1.24-rc9` has passed its single-workflow
hosted installation and metadata gates. The installed rc12 already has the
quote-line-only cleanup, so rc13 may be installed as its normal versioned
successor. Keep the tenant suspended, retain the exact rc12 archive, require a
clean hosted scanner, and verify installed version and live Quote tax/shipping
materialization before reactivation.

On any install, metadata, schema or canary failure, stop and preserve the
current package log and state. Do not install this package on the stock tenant.
Rollback uses the retained rc12 artifact and is complete only after installed
metadata and the prototype journey are reverified.

## Offline evidence

- Network-disabled package suite: 106 tests pass with two explicit environment
  skips (native SugarLogic materialization and a linter fixture that has no
  copied ERP-Epicor tree).
- Focused real-PHP valuation suite: 24 pass and one declared native SugarLogic
  skip.
- The built artifact contains the exact provider bytes and pinned shared and
  Partial Fulfillment dependencies.
- Whitespace and archive CRC checks pass.

## Artifact

`sugar-sell/BenchDogs-Ext/releases/sugarai_benchdogs_ext-0.9.42-rc13.zip`

SHA-256: `c0f6277b3388b6e43dde87d88c750623906e25eac74090946baed81efa50d2b6`

Hosted scanner, installation, native SugarLogic, live valuation, prototype to
production, recovery and rollback evidence remain required.
