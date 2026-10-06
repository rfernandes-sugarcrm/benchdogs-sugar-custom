---
id: BD-L-0007
title: A secondary delivery has a separate truth
rule: Preserve the primary handoff result and report a notification from its own read-back evidence; never turn notification failure into a retry of a non-idempotent ERP create.
severity: critical
subsystems: [benchdogs-sugar, estimating-handoff, notifications, sidecar]
paths:
  - sugar-sell/BenchDogs-Ext/custom/modules/Quotes/BdEstimatingNotificationHook.php
  - sugar-sell/BenchDogs-Ext/custom/clients/base/api/BdBenchDogsActionsApi.php
  - sugar-sell/BenchDogs-Ext/custom/modules/Quotes/clients/base/fields/bd-send-estimating/bd-send-estimating.js
---

# A secondary delivery has a separate truth

**What happened.** The estimating button created the Kinetic quote and saved
the Bench stage, while its progress text promised that estimating was being
notified. The notification hook accepted raw User IDs, ignored the return from
`save()`, did not read the record back, and logged success unconditionally.
The ERP handoff could therefore be real while the notification was missing or
assigned to an inactive user, and the response could not tell them apart.

**Rule.** A secondary notification never owns the success of the primary ERP
handoff. Validate its recipient, give one stage transition a durable identity,
save without unrelated assignment-email effects, and read the exact record
back before claiming delivery. Report failure as a named warning with a safe
queue or status fallback. Never change ERP success to an error that encourages
the seller to repeat a non-idempotent create.

**Applying it.** Bench uses the Notifications module's existing unique
`sync_key` as the event identity; it does not add another table or field. An
active Users row is not sufficient evidence that its owner can observe the
notification: `sugar_login` must be enabled, and missing/disabled values fail
closed. An explicit configured user is authoritative, so invalid configuration fails
visibly rather than silently routing the handoff to someone else. The API
reports ERP and notification outcomes independently, and Sidecar stays guarded
even when it renders the notification outcome amber.

**The check.** Drive false, exceptional and apparently successful-but-lost
notification saves. Then repeat the same transition and perform a later real
cycle. The first three must never report `created`, the duplicate must reuse
one record, and the later cycle must create another without changing the ERP
action result.
