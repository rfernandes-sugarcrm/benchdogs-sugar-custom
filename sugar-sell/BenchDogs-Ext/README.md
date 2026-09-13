# BenchDogs-Ext

Bench Dogs MLP package for Sugar Sell: the bd01 ERP reflection modules
(`bd01_ERP_Quote`, `bd01_ERP_Quote_Line`, `bd01_ERP_Quote_Cost`), their
relationships, the `bd_*` reflection fields on Quotes, and the logic hooks
that make the reflected data drive the pipeline. Extension-only: every file
installs through Module Loader into `custom/` or as new modules — nothing
overrides a stock Sugar file.

## Logic hooks

| Module | Hook class | Fires on | Does |
|---|---|---|---|
| `bd01_ERP_Quote` | `BdQuoteReflectionHook` | after_save | Reflects ERP fields onto the linked Quote and maintains Bench stage/forecast behavior; shared ERP-Core owns Opportunity headline amount and its currency conversion in Opportunities-only mode |
| `bd01_ERP_Quote_Line` | `BdGoverningLineHook` | after_save | Demotes governing siblings and requests a reflection refresh; installed single-session governing-amount behavior passes, while concurrent uniqueness remains unverified |
| `Quotes` | `BdEstimatingNotificationHook` | after_save | Creates a Notifications record when `bd_erp_stage` enters `in_estimating` |

### Governing-line rollup (REQ-5 / REQ-6)

`bd01_ERP_Quote_Line.governing` is editable on the module's record view,
list view, and the lines subpanel under `bd01_ERP_Quote`. Marking a line
governing:

1. clears the flag on sibling lines of the same ERP quote through sequential
   saves (`BdGoverningLineHook`, after_save); this is not an atomic guarantee
   against concurrent edits;
2. requests `BdQuoteReflectionHook::refreshOpportunityAmount`.

Both QA profiles use **Opportunities-only**, with Quote line items and no
Opportunity Revenue Line Items. This is a package invariant, not a runtime
branch: rc12 ships no Opportunity-line vardef, hook, module lookup or fallback
projection. Opportunity amount stays with shared ERP-Core's primary-Quote hook,
using the stored native Quote total. Bench reflection must not overwrite that
amount with a different subtotal.
Stage derivation remains Bench-specific. System-managed Opportunity Best and
Worst consume the shared writer's already converted headline amount, so they
follow the same selected production + prototype + tax + shipping policy.
Hidden provenance fields track each case independently: changing Best marks
only Best human-owned, for example, while Worst continues to converge. An
upgrade adopts only zero, current-headline or legacy-deliverable values;
anything else is conservatively classified as a human value.

The owner-confirmed contribution is the selected governing production line
plus prototype value, tax and shipping. Other production quantity options stay
visible and contribute zero. `ErpQuoteOpportunityContribution` provides that
number through ERP-Core's neutral contract; ERP-Core remains the sole writer.
Missing/multiple selection, multiple prototypes and multiple linked ERP quotes
refuse instead of guessing or reverting to the summed display total.

The exact governing field on the Kinetic Quote header is still unknown, so the
candidate uses the explicit Sugar line flag. Automated ERP-driven selection,
clearing, concurrency and revision lineage remain release acceptance gaps.

The shared amount writer must run before the Bench forecast refresh on a
governing-line trigger. Reversing that order leaves Best/Worst one selection
behind and also tempts Bench code to duplicate shared currency semantics.

### Release-stage ownership

Bench supplies `ErpOpportunityReleaseStagePolicy` through Partial
Fulfillment's fixed neutral seam. It returns Prototype Closed/80 when the only
ordered release is the prototype, and Partial Production Closed/90 once any
production option is ordered. The provider never saves an Opportunity.
Partial Fulfillment 1.0.13 validates and performs the only release-stage write.
ERP-Epicor 1.1.24-rc9 returns that writer's neutral outcome as
`release_stage_status` on the order action, allowing hosted acceptance to
distinguish an update from a preserved stage without exposing record ids,
exception text or customer-policy internals.

The coordinated rc9 candidate and its exact dependency/artifact hashes are
recorded in [release evidence](docs/release-0.9.42-rc9.md). Offline passing
tests are not hosted installation or prototype-stage acceptance.

Bench rc12 adds required post-install application-language compilation and
metadata refresh, followed by uncached stage/probability verification. A
missing required domain fails installation with a neutral error rather than
requiring a manual `repair-ui` call. It also supplies append-only native
DropdownsStyle entries for the two customer stage keys: Sugar's formatted
`enum-cascade` renders a value blank when the domain label exists but its style
entry does not. Existing tenant styles for either exact key are preserved. It
does not change stage policy or the shared writer. See
[rc12 candidate evidence](docs/release-0.9.42-rc12.md).

Because Sugar upgrades do not remove files omitted by a newer ZIP, moving from
rc9 to rc12 requires a Module Loader uninstall with tables retained, followed
by the rc12 install. This is what removes the retired Opportunity-line files;
an in-place upgrade is not accepted. Rollback uses the same supported boundary:
uninstall rc12 with tables retained, then install the saved rc9 artifact.

Classification uses the ordered Quote line item's `bd_erp_line_num` identity
against exactly one linked ERP quote. Multiple revisions, duplicate ERP line
numbers, multiple prototypes, or an ordered line without that identity refuse
instead of guessing. A Kinetic-originated order that reconciliation newly
marks ordered is sent through the same shared `AfterLinesOrdered` dispatcher.
Bench's direct reflection retains only pre-order Proposal initialization and
forecast maintenance; it no longer advances a post-order Opportunity stage.

The action may have loaded relationship beans before stamping the release.
The current candidate therefore treats the entire role-bearing graph as
snapshots: it
resolves relationship IDs, then re-retrieves the single ERP quote, every ERP
line and every Product with `use_cache=false`. `getBeans()` can retain an old
snapshot even after its focus bean is retrieved again. A linked Bench quote
with no freshly visible ordered line throws a deterministic refusal for the
shared logger rather than silently claiming the policy is not applicable.
This observation repair still requires a hosted prototype-first rerun.

### Headline-owner regression evidence

`scripts/tests/test_headline_valuation_owner.py` calls both repositories' real
public PHP hooks with isolated beans. It verifies trigger-order convergence,
repricing, currency conversion, primary/nonprimary guards and no RLI access.
It deliberately does not emulate native SugarLogic: materialized Quote
tax/shipping calculations still require installed-instance verification.
Passing this fixture is not evidence that a new MLP has been installed.

### Estimating notification (REQ-13)

The `Quote Estimate` header action is a customer-owned entry point, not a
second ERP writer. `BdBenchDogsActionsApi::sendToEstimating` delegates the
`advanced_quote` action to shared `QuotesErpActionsApi::runErpAction`. Only a
shared success can move a freshly re-read Quote to `in_estimating` and start
the existing turnaround clock; the endpoint proves the persisted stage with a
second uncached read and otherwise returns an actionable error while retaining
the ERP identity. Validation, the ordinary existing-quote re-click guard, ERP
communication and write-back status stay with ERP-Epicor. A re-click on an
existing ERP quote preserves its current customer stage.

The Sidecar field rejects a second click while the first request or its success
refresh is pending and unlocks after an error for an explicit retry. That guard
is local to one browser field instance. It does not serialize concurrent tabs,
users or a lost-response retry; durable `quote_to_quote` idempotency remains a
shared-layer acceptance gap and is not claimed by this package.

The turnaround start is stamped only from the exact scoped
`Quotes.erp_sync_key` returned by Core (`<COMPANY>__<QuoteNum>`). The bare
display number is never joined to an arbitrary company; missing, mismatched or
duplicate scoped identity leaves an explicit pending/ambiguous timestamp
status. This fixes hand-off identity and now keeps estimating completion
separate from classification. All 90 open EPIC06 QuoteHed rows inspected
report `CurrentStage=QUOT`, but only 11 report `Quoted=true`. The ERP mirror
therefore stores nullable `QuoteHed.Quoted` and `DateQuoted` with no defaults,
and the reflection advances to `priced` only for true with its business date.
Unknown preserves the current lifecycle, false preserves an active hand-off,
and an observed true-to-false change after priced marks revision. Closed and
linked-order facts outrank
completion. See `docs/quote-completion-source-contract.json` for the sanitized
live source contract. The paired core null-omission repair is a hard
deployment dependency.

Endpoint success proves the shared ERP action and persisted outbound stage.
Notification delivery is a separate result: `erp_handoff_status=completed`
remains successful while `notification_status` reports `created`,
`already_created`, or a named warning such as `recipient_unavailable`,
`configured_recipient_invalid`, `save_failed`, `persistence_unconfirmed` or
`not_observed`. A secondary notification can never roll back the ERP hand-off
or make its non-idempotent create action retryable. The seller receives an
amber, persistent message when the hand-off succeeded but the notification did
not, with the In Estimating view as the safe operational fallback.

When a Quote's `bd_erp_stage` transitions into `in_estimating` (the closest
`bd_erp_stage_list` key to "ready for estimating" — the list deliberately
has no separate `ready_for_estimating` value), `BdEstimatingNotificationHook`
creates a Sugar **Notifications** record. Because `bd_erp_stage` is normally
written by `BdQuoteReflectionHook`, the notification fires on the ERP sync
path as well as on manual stage edits.

Outbound recipient, in order:

1. `$sugar_config['benchdogs_ext']['estimating_notify_user_id']` — the explicit
   estimating coordinator. Set it in `config_override.php`:

   ```php
   $sugar_config['benchdogs_ext']['estimating_notify_user_id'] = '<user id>';
   ```

2. the quote's assigned user's manager (`Users.reports_to_id`);
3. the quote's assigned user (last resort).

Return-leg recipient, in order:

1. `$sugar_config['benchdogs_ext']['pricing_notify_user_id']` — an optional
   quote desk or sales coordinator;
2. the quote's assigned user;
3. the quote's creator.

Only existing, active internal users with `Users.sugar_login` enabled can
receive either notification. A missing or disabled login fails closed even if
the Users row itself says Active. An
explicitly configured recipient is authoritative: if it is missing, inactive,
a login-disabled user, a group user or a portal-only user, Sugar reports
`configured_recipient_invalid` and does not silently route the message to a
different person. When no explicit recipient is configured, inactive dynamic
candidates are skipped in the order above.

rc15 is one half of the estimating-return repair and must not be built or
installed independently. Pair it with the reviewed lifecycle candidate that
preserves and consumes Kinetic `QuoteHed.Quoted` and `DateQuoted`; install and
accept them together. Until that paired signal is present, do not guess from
`CurrentStage`, suppress the return leg, or claim that estimating completion
has been proven.

Each hand-off uses the native, uniquely indexed `Notifications.sync_key`,
derived from Quote identity, direction, stage transition and modification
timestamp. A repeated callback or stale concurrent save therefore reuses the
same notification, while a later genuine estimating cycle gets a new key.
`save(false)` suppresses assignment-email side effects; the hook then performs
an uncached read and verifies the recipient, parent and sync key before it
reports `created`.

**SugarBPM**: this is deliberately a logic hook, not a shipped SugarBPM
process definition — the package does not pretend to have designed a BPM
process with the customer. If the customer prefers SugarBPM, disable this
hook post-install (remove its registration from
`custom/Extension/modules/Quotes/Ext/LogicHooks/bd_estimating_notification.php`
and run Quick Repair) and model the same trigger in Process Definitions:
start event "Quotes updated", criteria `bd_erp_stage changes to
In Estimating`, action "Add Related Record → Notifications" (or an email).

## Build

```bash
# from sugar-sell/ (uses each package's own version file):
bash buildPackages.sh BenchDogs-Ext

# or directly, from this directory (php 8.2 via docker if none local):
docker run --rm -v "$(pwd)":/work -w /work composer:2 php pack.php
```

The installable zip lands in `releases/sugarai_benchdogs_ext-<version>.zip`.
`scripts/post_install.php` runs a Quick Repair on the affected modules and
writes the Quotes layout extensions.
