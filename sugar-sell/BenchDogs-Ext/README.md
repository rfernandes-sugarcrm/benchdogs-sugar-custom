# BenchDogs-Ext

Bench Dogs MLP package for Sugar Sell. Extension-only: every file installs
through Module Loader into `custom/` — nothing overrides a stock Sugar file,
and the package ships no module of its own.

> ### 🔒 1567 — MINIMAL: override only where it must (0.9.42-rc69)
>
> Owner, 2026-09-23: *"Benchdog MLP shoudl be mininal with mininal foot print of
> overide"*, *"just if we have to"* — on top of 🔒 1508 / 🔒 1520 (customer
> category only; removal is the default, retention needs the owner's per-item
> consent). What ships, all of it, and why each cannot live upstream:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php` | `bd_customer_group_code` (Epicor `Customer.GroupCode`) and `bd_customer_group` (`CustGrup.GroupDesc`) on Account | Bench's customer category (REQ-19). Core has no such field, and core's schema enforcement dead-letters the Bench connector's `erp_customers` writes without the vardef |
> | `custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php` | their two labels | — |
> | `custom/modules/Accounts/BdAccountsLayoutExtensions.php` | appends the two fields to the DEPLOYED Accounts record view (only if neither is already on it), and takes them off on uninstall | ERP-Epicor's `AccountsLayout` owns that view in replace mode; an append to the deployed view is the one placement that survives it |
> | `custom/clients/base/api/BdBenchDogsActionsApi.php` | **EMPTY** — declares no class, registers no route | the one retirement stub left: an api file is loaded by path on every REST dictionary rebuild, so dropping it would leave rc68's `bd-tools/repair-ui` registered on upgraded tenants. Droppable once every tenant carrying Bench Dogs has taken rc69 |
> | `scripts/post_execute.php`, `scripts/bd_pre_uninstall.php`, `scripts/post_uninstall.php` | the lifecycle: place the fields + rebuild Accounts + the G294 step report; undo the placement; rebuild caches | — |
>
> `scripts/tests/test_g280_minimal_footprint.py` pins this list against the
> source tree AND the built zip, so it cannot grow silently.

## What rc69 removed, and where each piece lives now

| Removed | Now owned by | Evidence the removal changes nothing a seller sees |
|---|---|---|
| `custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php` | **Partial Fulfillment ≥ 1.0.41** — same path, same class, same all-alternative preserve gate (G282 / 🔒 1511) | `scripts/tests/test_governing_contribution.py` runs every scenario through rc68's Bench body and PF 1.0.43's; the amount ERP-Core writes is identical in all of them. One recorded divergence: a failed line-role READ (rc68 preserved, PF answers from the quote total) |
| post_install's write of `erp_integration.partial_order_sales_stage` | **Partial Fulfillment ≥ 1.0.43** — the same value, `'Partial Production Ordered'`, as a reader-side default (G305 / 🔒 1519) | a tenant that already has the row keeps it; one without it behaves as though it had been written |
| the Bench Dogs Quotes panel removal (K-2), the `bd_governing_origin` marker removal (K-3), the `zz_bd_stage_doms` fragment removal (K-5), and their helper classes `BdQuotesLayoutExtensions` / `BdOpportunitiesLayoutExtensions` | **the one-off `ONEOFF-RetireBdResidue`** (🔒 1521) | spent: the one-off ran on every QA tenant — et 1.0.0 2026-09-22 22:22Z; stock and Ophir 1.0.1 2026-09-23 00:58Z; failed 0 (G234 CLOSED) — and nothing re-adds any of them |
| the 39 emptied `custom/Extension` stubs (retired `bd_*` vardefs, labels, hook registrations, the stage style, the country lookup label, the orphan `LBL_RECORDVIEW_PANEL_BENCHDOGS`) and the `BdKineticOpportunityHook` tombstone | **the one-off** — it DELETES each Extension path and BLANKS the tombstone | every path is still on the one-off's worklist (asserted by `scripts/tests/bd_retirement.py`) |
| the `bd-tools/repair-ui` admin route | nothing — it re-ran K-2/K-3 (spent) and the placement `post_execute.php` already runs | the file ships empty, so the route is unregistered on upgraded tenants too |
| the relationship / TableDictionary rebuild and the language rebuild in the lifecycle scripts | Module Loader itself (`install_extensions()` rebuilds languages before `post_execute`) | this package declares no relationship and no longer empties any language fragment |

### Taking an UPGRADED tenant from rc68 to rc69

Module Loader never deletes a file a later build stops shipping (§CW / G37), and
`unlink()` is denied to package code. So after installing rc69:

1. **Reinstall Partial Fulfillment** (1.0.44, an unspent version of 1.0.43's
   code) so PF's body is the one at
   `custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php`. Until then
   rc68's Bench body stays on disk; the test above shows it writes the same
   amounts, so this is hygiene, not a fix. Dropping the path also closes G282's
   uninstall hazard: a Bench Dogs uninstall can no longer delete PF's body.
2. **Re-run the one-off `ONEOFF-RetireBdResidue`** if the tenant took any Bench
   Dogs build AFTER the one-off last ran (rc67/rc68 re-copied the empty stubs).
   It removes them and reports `REMOVED`/`already gone` per path; a second run
   reads `NOTHING LEFT TO REMOVE`.
3. Not covered by either, and inert: `BdQuotesLayoutExtensions.php` and
   `BdOpportunitiesLayoutExtensions.php` stay on an upgraded tenant's disk with
   nothing calling them. The one-off's owner can add both to its blank list.

Install order is unchanged: **ERP-Epicor → Partial Fulfillment ≥ 1.0.43 → Bench
Dogs last.** The manifest refuses an older PF (`ERR_UW_NO_DEPENDENCY`).

Earlier revisions of this README described the retired quote mirror, the
estimating notification hook, the governing-line rollup and the release-stage
policy. All of that code is gone; the history is in git.

## Build

```bash
# from sugar-sell/ (uses each package's own version file):
bash buildPackages.sh BenchDogs-Ext

# or directly, from this directory (php 8.2 via docker if none local):
docker run --rm -v "$(pwd)":/work -w /work composer:2 php pack.php
```

The installable zip lands in `releases/sugarai_benchdogs_ext-<version>.zip`.
`scripts/post_execute.php` places the two customer-group fields and rebuilds
Accounts. It runs once per install, as the `post_execute` installdef; it is not
named `scripts/post_install.php` because Sugar runs that reserved path a second
time, outside the installer (G294).
