# Bench Dogs 0.9.42-rc18 — account action requires the same access as the shared one

Release candidate. Not approved for production. SDK packages remain `1.18`;
requires shared ERP-Epicor `>= 1.1.24-rc9` and Partial Fulfillment `1.0.13`,
exactly as rc17.

## Why

A read-only access-control inventory for rev-6 REQ-10 (2026-09-13) compared the
two "create Opportunity + Quote from an Account" routes:

- Shared ERP-Epicor `AccountsErpActionsApi::createOppQuote` requires
  `$account->ACLAccess('edit')` and `ACLAccess('save')` on new Opportunities and
  Quotes before it writes anything.
- Bench `BdBenchDogsActionsApi::createOppQuote` (`POST
  Accounts/:record/bd-create-opp-quote`) required only
  `$account->ACLAccess('view')`, then saved an Opportunity, a Quote, a
  ProductBundle and a Products placeholder line with plain `save()` calls.

So a Sugar user whose role allowed viewing an account, but not editing it or
creating Opportunities/Quotes, could create sales records by calling the route
directly. The record-view button's `acl_action=edit` only hides the button; it
is not server-side enforcement.

## Change

- `createOppQuote()` now throws `SugarApiExceptionNotAuthorized` unless the
  caller has edit access on the account and save access on Opportunities and
  Quotes. Both checks run before the first save, so a refused call creates
  nothing. Wording and order match the shared route.
- `scripts/tests/test_create_opp_quote_acl.py` runs the real endpoint in a PHP
  harness with per-module ACL grants: view-only account, missing Opportunity
  create access and missing Quote create access each refuse with zero saves.
  A fully authorized caller saves exactly Opportunity, Quote, ProductBundle and
  Products once. A source contract pins the gate ahead of the first save.

No field, layout, dropdown, hook, relationship or dependency changes. Callers
who already had edit/create access see no difference.

## Evidence

Offline, recorded in the commit that introduced this document:

- `php -l` clean for `BdBenchDogsActionsApi.php` (composer:2 image).
- `scripts/tests/test_create_opp_quote_acl.py`: 5 tests OK. The same test
  against the previous source (`git archive` of `1e2d886`) fails all 5 on
  assertions: the view-only caller's call returned instead of being refused, and
  only `['Accounts', 'view']` was checked.
- `scripts/tests/test_estimating_entrypoint.py`: 12 tests OK.
- `scripts/tests/test_mlp_lint.py`: 59 tests OK (1 skipped).
- `scripts/mlp_lint.py` source audit: 0 blocker, 0 required, 0 advisory.

Still required before any acceptance claim: fresh authorization for this new
artifact, then upload, install and verification on the Bench QA tenant only.
A live denial check needs a non-admin Sugar user whose role lacks account edit
or Opportunity/Quote create access; none exists on the QA tenant today (only
Sugar's stock roles are defined).
