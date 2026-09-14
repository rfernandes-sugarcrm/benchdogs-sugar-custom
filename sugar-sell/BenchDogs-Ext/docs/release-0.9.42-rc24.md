# Bench Dogs 0.9.42-rc24 — shipped is a quantity, not money (user decision 55/59)

Release candidate. Not approved for production. **Built and scanned, NOT
installed** — installation is sequenced with the ERP-Epicor `1.1.24-rc23` core
package and must not happen before it. SDK packages remain `1.18`; requires
shared ERP-Epicor `>= 1.1.24-rc5` and Partial Fulfillment `1.0.13`, exactly as
rc23.

## Why

**User decision 55 (2026-09-14):** *"lets change shiped as quanmitity not
money"*. ERP-Core acted on it in `1.1.24-rc23`: `ERP_OrderLines.shipped_value`
and `ERP_Orders.shipped_value_total` are retired there, and the only shipped
surface core now offers is the line-level **quantity**,
`ERP_OrderLines.shipped_quantity`, rendered by core's `erp-fulfillment` field
as "12 of 15".

This package shipped its own money fields on **the same two core modules**, so
decision 55 landed only half-delivered on a Bench tenant:

| file | field |
|---|---|
| `custom/Extension/modules/ERP_OrderLines/Ext/Vardefs/bd_shipped_value.php` | `bd_shipped_value` |
| `custom/Extension/modules/ERP_OrderLines/Ext/Language/en_us.bd_shipped_value.php` | `LBL_BD_SHIPPED_VALUE` |
| `custom/Extension/modules/ERP_OrderLines/Ext/clients/base/views/record/bd_shipped_value.php` | record-view entry |
| `custom/Extension/modules/ERP_Orders/Ext/Vardefs/bd_shipped_value_total.php` | `bd_shipped_value_total` |
| `custom/Extension/modules/ERP_Orders/Ext/Language/en_us.bd_shipped_value_total.php` | `LBL_BD_SHIPPED_VALUE_TOTAL` |
| `custom/Extension/modules/ERP_Orders/Ext/clients/base/views/record/bd_shipped_value_total.php` | record-view entry |

**User decision 59 (2026-09-14):** *"thats a problem clean it"*, refined to
*"they shoudl both be based on quanity and if core does a good job you dont
need the extension"*. So the fields are **removed**, not re-expressed as a
`bd_shipped_quantity`: core owns the quantity, and a Bench copy of it would be
the same duplication under a better name.

### The defect was worse than "a seller can still see a value"

Both vardefs carried **`'default' => 0.0`** — the exact default REQ-21
deliberately removed from ERP-Core's copy. Module Loader adds the column with
that default, so every existing and every future `ERP_OrderLines` row reads
`0.00` before the connector has said anything at all.

And on the Bench QA tenants **there was no writer behind it**. The connector
module that computed the figure is gated by
`SUGARAI_BD_MLP_FIELDS`, and the QA overlay sets it to `account_group` with an
explicit *"Do not enable shipped_value here"*. The record-view fragment (shipped
since 0.9.41) therefore rendered **"Shipped Value (not invoiced): $0.00" on
essentially every order line, fabricated by the vardef default**. "Nothing
shipped" and "we never measured this" rendered identically — the property
REQ-21 was disabled to protect, failing on the seller's screen.

## Change

- The six files above are **deleted**. After this release the package ships
  **nothing at all** for `ERP_OrderLines` or `ERP_Orders`; both directories are
  gone. The line-level shipped quantity from ERP-Core is the only shipped
  surface on a Bench tenant, exactly as on a stock one.
- No other field, layout, dropdown, hook, relationship, script or dependency
  changes. `pack.php` globs `custom/`, so no installdef edit was needed — and
  the new test asserts that outcome rather than trusting the mechanism.

### What is NOT changed here, and must land with it

`connector_ext_benchdogs` is the writer. Its removal is a **separate, already
written, unmerged** commit — `feat/req21-retire-shipped-duplicate`
(`cb5ec60`), which retires the `erp_order_line_shipped` and
`erp_order_shipped_total` modules, their models, the `SHIPPED_LINES` reference
table and the `shipped_value` feature flag. **rc24 and `cb5ec60` must ship
together.** rc24 alone leaves a connector that declares two modules writing
fields the package no longer creates; `cb5ec60` alone leaves the fabricated
`$0.00` on the record view.

## Evidence

Offline, recorded in the commit that introduces this document. Nothing
installed, no tenant touched.

- `scripts/tests/test_bench_shipped_is_a_quantity_not_money.py` — the
  regression guard. It forbids `bd_shipped*` (so a `bd_shipped_quantity`
  resurrection fails too), forbids any `'default' => 0` in a fragment for the
  two core order modules, forbids shadowing core's `shipped_quantity`, and
  checks **the built zip as well as the source** — a file deleted from source
  but still inside a stale archive is precisely the half-removal the test
  exists to catch. Both the field names and the `LBL_BD_SHIPPED_*` labels are
  forbidden, because `git grep bd_shipped_value` does not find the language
  fragments at all.
- **Red proof at the parent commit `4968d74` (rc23)**: exit 1 — **78 failing
  subtests across 7 of the 8 methods**, run against rc23's own source and
  rc23's own built zip. The eighth
  (`test_no_fragment_shadows_cores_shipped_quantity`) is a forward-looking
  guard with nothing to catch there, and is recorded as such rather than
  counted as a proof. Green here: **8 passed, 2277 subtests passed, exit 0**.
- Suite, measured in this worktree before and after the change:
  **2 failed / 165 passed / 2 skipped / 1648 subtests** →
  **2 failed / 173 passed / 2 skipped / 3853 subtests**. The two failures are
  **identical by name** before and after (`test_quoted_completion_lifecycle`,
  a PHP 8.5 `ReflectionMethod::setAccessible` deprecation in the harness) and
  are pre-existing environment noise, diffed rather than counted.
- Package scanned with `mlp_lint` and the **real Sugar ENT 26.1.0
  ModuleScanner**, with the rc23 zip as a known-good control and a planted
  `eval()`/`system()` file as a negative control, in the same run.
- Provenance by content: the rc24 zip was unpacked and diffed file-by-file
  against rc23 and against the commit.

## Operator notes

- **Module Loader does not drop columns.** `erp_orderlines.bd_shipped_value`
  and `erp_orders.bd_shipped_value_total` survive the upgrade with their stored
  `0.00`s. They become invisible in the UI (no vardef, no layout entry) but
  stay reachable by SQL and by any saved report built on them, where they would
  silently return zeros. Grep the tenant's `saved_reports` for
  `bd_shipped_value` before installing.
- **The schema store never deletes.** `PostgresSchemaStore.put` is upsert-only,
  so the removed fields persist in the connector's CRM snapshot with their old
  stamp and `schema-validate` passes straight over them. After any refresh the
  **stale-row check is the only detector of this removal.**
- **Check the tenant's own record view.** The proof that core's
  `erp-fulfillment` still renders is read from ERP-Core's shipped `record.php`.
  If `ERP_OrderLines` has been Studio-customised on the tenant, the live layout
  is in `custom/modules/ERP_OrderLines/clients/base/views/record/record.php`
  and belongs to no package — that is the one check that has to be made live.
