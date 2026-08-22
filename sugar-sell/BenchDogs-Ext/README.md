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
| `bd01_ERP_Quote` | `BdQuoteReflectionHook` | after_save | Reflects ERP stage/total onto the linked Quote (`bd_erp_total`, `bd_erp_stage`, `bd_priced_at`, `bd_reason_code`) and rolls the amount up to the primary Opportunity |
| `bd01_ERP_Quote_Line` | `BdGoverningLineHook` | after_save | Keeps `governing` unique per parent ERP quote and refreshes the Opportunity rollup |
| `Quotes` | `BdEstimatingNotificationHook` | after_save | Creates a Notifications record when `bd_erp_stage` enters `in_estimating` |

### Governing-line rollup (REQ-5 / REQ-6)

`bd01_ERP_Quote_Line.governing` is editable on the module's record view,
list view, and the lines subpanel under `bd01_ERP_Quote`. Marking a line
governing:

1. clears the flag on every sibling line of the same ERP quote
   (`BdGoverningLineHook`, after_save — at most one governing line per
   quote, always);
2. refreshes the Opportunity amount through `BdQuoteReflectionHook`'s own
   rollup path.

The rollup rule (`BdQuoteReflectionHook::rollupAmount`): when a governing
line exists, the Opportunity amount is that line's `doc_ext_price`;
otherwise it is the whole `quote_total` (the original behavior). Both are
gated, as before, on the Quote being its Opportunity's primary quote
(`erp_is_primary_quote`, owned by ERP-Core). Clearing a governing flag
touches nothing — the fallback applies again on the next reflection save.

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
docker run --rm -v "$(pwd)":/work -w /work php:8.2-cli php pack.php
```

The installable zip lands in `releases/sugarai_benchdogs_ext-<version>.zip`.
`scripts/post_install.php` runs a Quick Repair on the affected modules and
writes the Quotes layout extensions.
