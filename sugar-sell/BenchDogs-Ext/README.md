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
>
> **Grown by G380 / G381, with the owner's per-item consent (🔒 1705b; build
> authorised by 🔒 1704b).** Nine files, every one a rule of Bench Dogs' ADM
> company that ERP-Epicor must not carry (gate G2), and every one gated on the
> quote's ERP company. See the section below.
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php` + `.../Language/en_us.bd_adm_required_fields.php` | Quote fields `bd_reference`, `bd_lead_source`, `bd_lead_type`, `bd_project_id`, and their labels | ADM refuses the quote without Reference / Lead Source / Lead Type and the order without a Project; no other company asks for them |
> | `custom/Extension/modules/Quotes/Ext/LogicHooks/bd_adm_quote_defaults.php` | before_save: fills an EMPTY Reference (ship-to city + state) and an EMPTY Project (product-group default) on an unsent ADM quote | ADM-only defaults; creates nothing (🔒 1499 still holds, `test_g243…` pins it) |
> | `custom/Extension/application/Ext/Language/en_us.bd_adm_lists.php` | labels for the three `ERP_LookupValues` types, and two TENANT lists: `bd_adm_companies_list`, `bd_adm_project_by_group_list` | Bench data, edited in Dropdown Editor |
> | `custom/modules/Quotes/BdAdmRules.php`, `BdAdmLookupOptions.php`, `BdAdmQuoteFieldsLayout.php` | the rules, the pickers' option functions, the record-view placement and its undo | — |
> | `custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php`, `OrderSelectedLinesPolicy.php` | ERP-Epicor's two ordering hook points, answered: a part-less line on an ADM quote is blocked | ERP-Epicor offers these seams for exactly this; the rule is one customer's |

## G380 / G381 — what Bench Dogs' ADM company requires (🔒 1705b)

Measured on stage t7 (benchdogs-dev → ADM), 2026-09-23: ADM refuses Send to
Estimation with *"Reference is required. Expected Close is required. Lead Source
is required. Lead Type is required."* and Submit Order with *"Part is required.
Group is required. Project ID is required."* EPIC06 enforces none of it.

**What this package does (Sugar side):**

- **Lead Source / Lead Type** are pickers the SELLER fills. Their options are
  ADM's own ACTIVE codes (user-code types `LEADSRC` / `LEADTYPE`, 🔒 1710b),
  which the Bench Dogs connector extension publishes into `ERP_LookupValues`
  (types `BdLeadSources` / `BdLeadTypes`). Never defaulted.
- **Reference** defaults to the ship-to's city and state (`WAYNE NJ`) when
  empty, on an ADM quote not yet sent; the seller may change it.
- **Project** is a picker of ADM's active projects (`BdProjects`), pre-filled
  when every line's product group maps to the same project in
  `bd_adm_project_by_group_list`.
- **A line with no ERP part number** (e.g. the smoke fixture's "PALLET", whose
  part 49000450 never reached Sugar, G382) is **blocked from Submit Order and
  Order Selected Lines** on an ADM quote, with a seller message naming it.
- **Expected Close** needs no field: it is the Opportunity's close date.

**Tenant data (Admin → Dropdown Editor), no code change needed:**

| List | Key | Label | Shipped |
|---|---|---|---|
| `bd_adm_project_by_group_list` | product group (Epicor ProdCode) | ADM ProjectID | `CMI → 20065` only: the owner's rule (🔒 1712b) is to pre-fill only where history is ≥ 95 % one project (1,281 of 1,299 CMI lines, 🔒 1710b). Every other group: the seller picks. Bench Dogs extends it here. |
| `bd_adm_companies_list` | ERP company code | same | `ADM` |

Both ship as GUARDED defaults, so an admin's Dropdown Editor edit survives
every reinstall of this package (Sugar merges Ext fragments in mtime order;
`scripts/tests/bd_adm_rules_test.php` F1/F2 run both orders).

🛑 **What this package CANNOT do yet: send these values to ADM.** Core builds
both ERP payloads (`quote_to_quote`, `quote_to_order`) and, at core staging
`cbd3053`, offers no seam for an extension to add payload fields or refuse the
send. The Bench Dogs connector extension carries the builders and refusals
(`connector_ext_benchdogs/adm_rules.py`); they go live when core adds that
seam. Until then ADM still answers Send to Estimation and a real-part Submit
Order with its 400. The non-part block above works today.

⚠️ **Install prerequisite on a tenant that ever had BenchDogs-Ext rc37–rc40**
(Ophir, stock, et): run `ONEOFF-RetireBdResidue` 1.0.2 **before** this build.
This build installs a NEW `ErpQuoteHooks/ResolveOrderableLines.php`; over the
retired rc37 adapter, Module Loader would back that body up, and a later Bench
Dogs uninstall would RESTORE it — the orphan that refused every Submit Order on
Ophir (quote 368). The one-off deletes only the rc37 body (md5) and leaves this
one (pinned by `test_oneoff_retire_bd_residue.py`).

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
