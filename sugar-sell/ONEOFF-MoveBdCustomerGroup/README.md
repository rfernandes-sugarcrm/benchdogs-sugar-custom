# ONEOFF-MoveBdCustomerGroup 1.0.1 (G507)

> **1.0.1** (MLP026, 🔒2161b): its post_execute header comment is one line of why. Comments only: 1.0.0's zip stays its own tree, and a tenant that already ran 1.0.0 needs nothing.

A **disposable** layout repair for a tenant **with Bench Dogs**. Install it, read its line in the
install log, uninstall it. It is not part of the shipped Bench Dogs package and must never become
part of it (🔒 1724b: BenchDogs-Ext ships no layout code).

## What it fixes

Owner, on Ophir, account ADDISON WB I85L06 (2026-09-24 17:43Z): *"can we move this fields into
the overview?"* The Bench Dogs customer group (`bd_customer_group`, e.g. "Distribution") and its
code (`bd_customer_group_code`, e.g. "DIST") render **unlabelled in the Account header**, beside
the name, where sellers read them as buttons.

**Measured (read-only, Ophir, SugarEnt 26.1.0, the served Accounts record view):** `panel_header`
lists both fields, each with a baked `type: text`. The view has **no `panel_body`**; its first tab
is `panel_overview` ("Overview"). BenchDogs-Ext rc69's writer appended the pair to `panel_body`,
else to the first panel that holds fields — the header. `scripts/tests/bd_customer_group_move_test.php`
R1 reproduces that with rc69's real writer.

## Why BenchDogs-Ext rc72 alone does not fix it

rc72 marks the pair for `panel_overview` after Industry, and ERP-Core's
`ErpLayoutExtraFields::sync()` places marked fields — but only fields that are on **no** panel. A
header entry is on the view, so `sync()` leaves it (the same rule that keeps an admin's placement).
Test L1 pins exactly that.

## What it does (scripts/post_execute.php), on the Accounts record view only

1. **Evicts** both names from every panel with `'header' => true`. Studio cannot place a field in
   the header, so a header entry is a package artefact, never an admin's choice.
2. **Places what it evicted** as new `['name', 'label']` entries (the old entries are not moved:
   their baked `type: text` has no place on a body panel) on the **first tab** — the first
   non-header panel with `newTab` (`panel_overview` on Ophir, `panel_body` on a stock view that
   ERP-Epicor has made a tab), else the first non-header panel with fields — **after `industry`**
   when that panel has it, else at its end; the code follows the name. These are the slots rc72's
   marker gives a fresh install (test L4: identical entries).
   - A field that is **also** on a non-header panel (an admin put it there) is not placed again;
     only its header duplicate goes.
   - A field the module does **not define** (Bench Dogs absent) is retired from the header and
     placed nowhere — never an orphaned placement (the G116 shape).
3. **Writes once**, and clears the Accounts view/layout/template caches, only if 1 or 2 changed
   something. A second run says `ALREADY CLEAN` and writes nothing.

If no panel can take a field it would evict, it changes **nothing** and says `SKIPPED`. If the
Accounts vardefs look unread (no `id`/`name`), `SKIPPED`. Any error: `FAILED`, caught, never raised
(an uncaught throw on the post_execute path is a failed install).

**It installs no file** (no `copy` key, so uninstalling it restores nothing and is a genuine no-op),
creates or drops no table, writes no record and no config row, and touches no other view, panel,
field or button. No `dependencies`: it works under Bench Dogs rc69 or rc72.

## Where and when

- **Only on tenants with Bench Dogs** (🔒 1557: Bench packages go on Ophir only on QA; then
  benchdogs-dev / benchdogs-sandbox under 🔒 1615's conditions). On a tenant without Bench Dogs it is
  harmless (nothing in the header; nothing placed) but pointless.
- **Either order with BenchDogs-Ext rc72** (tests L2 and L5 end on the same view).
- benchdogs-dev / sandbox layouts were **not** read for this build (not signed in). The one-off
  handles both a `panel_overview` view and a stock `panel_body` view (tests M1, M7); run a
  read-only metadata read first to see whether their headers carry the pair at all.

## Proof after install (read-only)

`App.metadata.getView('Accounts','record').panels`: `panels[0]` (the header) lists neither
`bd_customer_group` nor `bd_customer_group_code`; the first tab lists both, after `industry`, each
`{name, label}`. On screen: the header shows the name, the credit badge (+ inactive badge) and the
buttons; the Overview tab shows "Customer Group" and "Customer Group Code" with values, not
editable in Edit (the `readonly` comes from rc72's vardef). The install log line:
`ONEOFF-MoveBdCustomerGroup 1.0.1 (G507): MOVED - …` (the version is read from the manifest) in `package_install.log`.

**If it were broken** the header would still list the pair, or the first tab would not.

## Uninstall

Uninstall the one-off right after it runs; it takes nothing back. Uninstalling **Bench Dogs** later
takes the pair off whichever panel it is on (ERP-Core's `sync()` retires recorded marked fields from
every panel) — test L6/L7.
