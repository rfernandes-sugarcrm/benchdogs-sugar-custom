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
Opportunity Revenue Line Items. The current headline-owner repair leaves
Opportunity amount with shared ERP-Core's primary-Quote hook, using the stored
native Quote total. Bench reflection must not overwrite that amount with a
different subtotal, or access/create/delete Opportunity RLIs in this mode.
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
Partial Fulfillment 1.0.10 validates and performs the only release-stage write.

Classification uses the ordered Quote line item's `bd_erp_line_num` identity
against exactly one linked ERP quote. Multiple revisions, duplicate ERP line
numbers, multiple prototypes, or an ordered line without that identity refuse
instead of guessing. A Kinetic-originated order that reconciliation newly
marks ordered is sent through the same shared `AfterLinesOrdered` dispatcher.
Bench's direct reflection retains only pre-order Proposal initialization and
forecast maintenance; it no longer advances a post-order Opportunity stage.

The action may have loaded `Quote.products` before stamping the release.
Therefore the policy takes relationship IDs but re-retrieves every Product
with `use_cache=false`; `getBeans()` can retain the pre-order snapshot even
after `Quote::retrieve()`. A linked Bench quote with no freshly visible ordered
line throws a deterministic refusal for the shared logger rather than silently
claiming the policy is not applicable.

### Headline-owner regression evidence

`scripts/tests/test_headline_valuation_owner.py` calls both repositories' real
public PHP hooks with isolated beans. It verifies trigger-order convergence,
repricing, currency conversion, primary/nonprimary guards and no RLI access.
It deliberately does not emulate native SugarLogic: materialized Quote
tax/shipping calculations still require installed-instance verification.
Passing this fixture is not evidence that a new MLP has been installed.

### Estimating notification (REQ-13)

When a Quote's `bd_erp_stage` transitions into `in_estimating` (the closest
`bd_erp_stage_list` key to "ready for estimating" — the list deliberately
has no separate `ready_for_estimating` value), `BdEstimatingNotificationHook`
creates a Sugar **Notifications** record. Because `bd_erp_stage` is normally
written by `BdQuoteReflectionHook`, the notification fires on the ERP sync
path as well as on manual stage edits.

Recipient, in order:

1. `$sugar_config['benchdogs_ext']['estimating_notify_user_id']` — the one
   config knob this package reads. Set it in `config_override.php`:

   ```php
   $sugar_config['benchdogs_ext']['estimating_notify_user_id'] = '<user id>';
   ```

2. the quote's assigned user's manager (`Users.reports_to_id`);
3. the quote's assigned user (last resort).

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
