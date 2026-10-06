# BenchDogs-Ext 0.9.42 rc16 Exact Estimating Completion

Release candidate. It has not been built, uploaded or installed. It requires
shared ERP-Epicor `1.1.24-rc9`, Partial Fulfillment `1.0.13`, SDK `1.18`, the
paired Bench ERP candidate carrying `Quoted`/`DateQuoted`, and a core candidate
whose generic container boundary omits unknown nulls. It must never be
installed on the stock tenant.

## Repair

Kinetic classification and estimating lifecycle are now separate contracts.
The customer ERP quote module stores nullable `quoted` and `date_quoted`
without defaults. `BdQuoteReflectionHook` advances a Sugar Quote to `priced`
only when `Quoted=true` is corroborated by a nonempty `DateQuoted`. A missing
source field preserves the existing lifecycle; explicit false preserves
`in_estimating`/`revision`, and an observed true-to-false change after `priced`
moves to `revision`.
Linked-order and closed won/lost evidence always wins.

`DateQuoted` corroborates the business-day completion event but does not own a
precise elapsed-time endpoint: the live sample carries midnight/date precision.
Sugar's first observation of the priced transition supplies both priced-back
stamps. Repeat true is stable and therefore cannot duplicate the stamp or the
return notification. A later false-to-true revision crosses the existing
revision-to-priced edge and may notify sales again, while the first-cycle
timestamps remain immutable.

The package retains rc15's independently reviewed notification behavior:
configured recipients are authoritative active internal login users, dynamic
fallbacks skip ineligible users, native `Notifications.sync_key` deduplicates,
readback proves persistence, and any secondary failure stays separate from the
successful ERP hand-off.

## Source Evidence

`quote-completion-source-contract.json` is a sanitized read-only capture from
EPIC06. QuoteSvc declares `Quoted` as nullable `Edm.Boolean` and `DateQuoted`
as nullable `Edm.DateTimeOffset`. All 90 open headers were `QUOT`, only 11 were
actually quoted, and all 129 headers had zero Quoted/date inconsistencies.

## Required Acceptance

- Core final-payload tests must prove unknown nulls are omitted while false,
  zero and blank remain writable; the running QA image must contain that fix.
- Bench ERP must pass raw true/false/unknown container-route tests and publish
  a new immutable extension version without changing SDK `1.18`.
- PHP tests must pass the unknown/false/true, reopen/requote, closed and ordered
  matrix and prove the first Sugar observation—not midnight `DateQuoted`—owns
  the elapsed-time stamps.
- On suspended Bench QA, install the coordinated candidates and run a real
  false-to-true estimating journey. Read back the exact scoped ERP mirror,
  stage, timestamps and one recipient-visible notification; repeat sync must
  create nothing new.
- Preserve and verify rollback artifacts before any install. Cleanup every
  owned test record and keep the stock tenant free of this customer package.

## Known Remaining Gate

This repairs completion interpretation, not durable `quote_to_quote` create
recovery across tabs, users or lost ERP responses. That shared-layer gate
remains required before production readiness. Do not interpret a passing
offline lifecycle test as authorization to install or as a production go.

## Rollback

Keep the tenant suspended. Retain the exact previously installed Bench
artifact and install log. If any coordinated package, metadata, hook or live
readback check fails, stop; do not retry a potentially successful ERP create.
Use the supported retained-artifact replacement/uninstall path, keep data
tables, repair caches, and prove the prior fields/routes/views before
reactivation.
