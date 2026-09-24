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
> | `custom/clients/base/api/BdBenchDogsActionsApi.php` | **EMPTY** — declares no class, registers no route | the one retirement stub left: an api file is loaded by path on every REST dictionary rebuild, so dropping it would leave rc68's `bd-tools/repair-ui` registered on upgraded tenants. Droppable once every tenant carrying Bench Dogs has taken rc69 |
> | `scripts/post_execute.php`, `scripts/bd_pre_uninstall.php`, `scripts/post_uninstall.php` | the lifecycle: rebuild + have ERP-Core place the marked fields + the G294 step report; proof of life; rebuild + have ERP-Core retire the marked fields | — |
>
> **rc70 (🔒 1724b): this package ships NO layout code.** Its fields carry
> ERP-Epicor's `erp_layout` vardef marker, and ERP-Core's
> `ErpLayoutExtraFields::sync()` places them on the views ERP-Epicor owns (and
> takes them off once their vardef is gone). `BdAccountsLayoutExtensions.php`
> stays on an upgraded tenant as an INERT orphan - Module Loader never deletes
> a file a later build stops shipping (§CW / G37), and nothing rc70 ships
> requires it; the one-off does not blank it yet (flagged for its owner).
>
> **rc72 (G507): the Account pair's marker is `panel_overview` after Industry,
> and both fields are `readonly`.** Moving the pair OUT of the header rc69 put it
> in (on a view with no `panel_body`, e.g. Ophir) is not this package's job - a
> placed field is never moved by `sync()` and this package writes no layout - it
> is the disposable one-off `sugar-sell/ONEOFF-MoveBdCustomerGroup`.
>
> `scripts/tests/test_g280_minimal_footprint.py` pins this list against the
> source tree AND the built zip, so it cannot grow silently.
>
> **Grown by G380 / G381, with the owner's per-item consent (🔒 1705b; build
> authorised by 🔒 1704b), and cut to ADM's own by 🔒 1724b ("Bench keeps ONLY
> ADM config + ADM rules").** Six files, every one a value or rule of Bench
> Dogs' ADM company that ERP-Epicor must not carry (gate G2), and every one
> gated on the quote's ERP company. See the section below.
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php` + `.../Language/en_us.bd_adm_required_fields.php` | Quote pickers `bd_lead_source`, `bd_lead_type`, `bd_project_id`, and (G460) `bd_marketing_campaign`, `bd_marketing_event` (each `erp_layout`-marked for the ERP panel, after Reference), and their labels | ADM's own UD columns (`LeadSrc_c`, `LeadType_c`), ADM's one-project-per-quote rule, and ADM's required marketing pair; no other company asks for them |
> | `custom/Extension/modules/Quotes/Ext/LogicHooks/bd_adm_quote_defaults.php` | before_save: fills an EMPTY `erp_reference` (ship-to city + state, shortened to the ERP's limit, G530) and an EMPTY Project (product-group default) on an unsent ADM quote; exits before loading anything for a quote it cannot touch | ADM-only defaults; creates nothing (🔒 1499 still holds, `test_g243…` pins it) |
> | `custom/Extension/application/Ext/Language/en_us.bd_adm_lists.php` | labels for the five `ERP_LookupValues` types, and ONE tenant list: `bd_adm_project_by_group_list` | Bench data, edited in Dropdown Editor |
> | `custom/modules/Quotes/BdAdmRules.php`, `BdAdmLookupOptions.php` | the rules (which companies are ADM, the two defaults) and the pickers' option functions | — |

> **Grown by G450 (0.9.42-rc74), with the owner's per-item consent** (his Yes to
> a Bench-only third account type "Suspect" beside the stock Customer/Prospect,
> never renaming them; GAPS.md G450; voided with rc71 by 🔒1775b, revived by the
> customer's 🔒1783b). One file:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/application/Ext/Language/_override_en_us.bd_account_type_suspect.php` | ONE key, `account_type_dom['Suspect']`, set only when absent | Epicor types a customer CUS / PRO / SUS; ERP-Core's dom has two values, so an Epicor SUS shows as Prospect (ADM: 1,022 of 1,132 "Prospects"). Core writes `Suspect` for SUS customers once the ADM connection's `customer_type_extra` is `{"SUS": "Suspect"}`; the value is Bench's (a dom key core cannot add). `_override` so it merges after ERP-Core's whole-array REPLACE assignment (the rc23 lesson) |

## G380 / G381 — what Bench Dogs' ADM company requires (🔒 1705b, 🔒 1724b)

Measured on stage t7 (benchdogs-dev → ADM), 2026-09-23: ADM refuses Send to
Estimation with *"Reference is required. Expected Close is required. Lead Source
is required. Lead Type is required."* and Submit Order with *"Part is required.
Group is required. Project ID is required."* EPIC06 enforces none of it.

**Who does what since 🔒 1724b ("Bench keeps ONLY ADM config + ADM rules"):**

| ADM requires | Supplied by | Where |
|---|---|---|
| Reference | the generic `Quotes.erp_reference` field | ERP-Epicor ≥ 1.1.125 (field), core (sends it); **this package defaults it** |
| Expected Close | the Opportunity's close date | core |
| Lead Source / Lead Type | the seller's picks, `bd_lead_source` / `bd_lead_type` | **this package** (fields); the Bench connector extension (sends `LeadSrc_c` / `LeadType_c`) |
| Part | core's own part-number resolution; a part-less line is refused per company | core; ERP-Epicor (`ERP_Companies.erp_order_requires_part_number`, set on ADM) |
| Group | the catalog part's product group | core |
| Project ID | `bd_project_id`, one per quote | **this package** (field + default); the Bench connector extension (sends it) |
| Marketing Campaign / Marketing Event (G460; quote header AND every order line) | the seller's picks, `bd_marketing_campaign` / `bd_marketing_event` | **this package** (fields, no default); the Bench connector extension (publishes the lists, sends `MktgCampaignID` / `MktgEvntSeq`) |

**What this package does (Sugar side):**

- **Lead Source / Lead Type** are pickers the SELLER fills. Their options are
  ADM's own ACTIVE codes (user-code types `LEADSRC` / `LEADTYPE`, 🔒 1710b),
  which core publishes into `ERP_LookupValues` (types `BdLeadSources` /
  `BdLeadTypes`) from the ADM connection's own `lookup_code_lists` config.
  Never defaulted.
- **Reference** (`erp_reference`, ERP-Epicor's field) defaults to the ship-to's
  city and state (`WAYNE NJ`) when empty, on an ADM quote not yet sent; the
  seller may change it. The ship-to is the QUOTE's (`shipping_address_city` /
  `_state`, which ERP-Epicor copies from the account's ERP default ship-to
  record), not the Account's own shipping columns. **G530:** Epicor takes at
  most 10 characters in `QuoteHed.Reference` (the field's `erp_max_length`,
  read through ERP-Epicor's `ErpQuoteFacts::referenceMaxLength()`), so a
  longer default is SHORTENED: the state is kept and the city cut to fit
  (`HARRISBURG PA` → `HARRISB PA`, `SALT LAKE CITY UT` → `SALT LA UT`); a cut
  ending on a space drops it; with no state, or no room beside it, the first
  10 characters. An ERP-Epicor that does not state the limit leaves the
  default whole (Send to Estimation's own refusal, or the ERP, then names it).
- **Project** is a picker of ADM's active projects (`BdProjects`), pre-filled
  when every line's product group maps to the same project in
  `bd_adm_project_by_group_list`.
- **Marketing Campaign / Marketing Event (G460)** are pickers the SELLER fills,
  NEVER defaulted (ADM defines no default event: `DefMktgEvntSeq` 0 and
  `isDefault` false everywhere, measured 2026-09-25). Their rows are ADM's own
  `MktgCamps` / `MktgEvnts`, published by the Bench connector extension (types
  `BdMarketingCampaigns` / `BdMarketingEvents`, ADM connection only). Only
  ACTIVE PAIRS are offered: a campaign with at least one active event (25 of
  ADM's 42 active campaigns have none today) and an event of an active
  campaign. The event is stored as `<campaign>/<seq>` (`26DISCNV/2`) because
  ADM reuses seq 1..4 and the same descriptions under every campaign; the
  event list is ordered by campaign, then seq. There is no dependent-picker
  code (🔒 1724b): an event of another campaign is refused by name at Send to
  Estimation / Submit Order, before anything reaches the ERP. Without the
  pair ADM refuses both ("A valid Marketing Campaign is required / A valid
  Marketing Event is required"; "You must select an active Marketing
  Campaign.", an ORDER LINE rule).

**Which quotes are ADM — no company list of its own.** A quote is ADM when its
ERP company (ERP-Epicor's `ErpQuoteFacts::companyCode`) has published
`BdLeadSources` rows. Only the ADM connection's code-list config publishes that
type, so this side follows core's config; the connector extension's
`SUGARAI_BD_ADM_COMPANIES` is the one place the connector names ADM.

**The defaults hook runs on every Quote save**, so it exits, cheapest first,
before loading anything for a quote it cannot touch: already in the ERP; both
values set; no company has published lead sources (one query per request);
ERP-Epicor without `ErpQuoteFacts` (logged); not an ADM company.

**Tenant data (Admin → Dropdown Editor), no code change needed:**

| List | Key | Label | Shipped |
|---|---|---|---|
| `bd_adm_project_by_group_list` | product group (Epicor ProdCode) | ADM ProjectID | `CMI → 20065` only: the owner's rule (🔒 1712b) is to pre-fill only where history is ≥ 95 % one project (1,281 of 1,299 CMI lines, 🔒 1710b). Every other group: the seller picks. Bench Dogs extends it here. |

It ships as a GUARDED default, so an admin's Dropdown Editor edit survives every
reinstall of this package (Sugar merges Ext fragments in mtime order;
`scripts/tests/bd_adm_rules_test.php` F1/F2 run both orders).

**Prerequisites, in order (the manifest enforces the first two):** ERP-Epicor
≥ 1.1.131 (G380 (d)–(g): `erp_reference`, the part-number switch,
`ErpLayoutExtraFields`, `ErpQuoteFacts`, all from 1.1.125; since rc73, G530's
`erp_reference.erp_max_length` and `ErpQuoteFacts::referenceMaxLength()`,
from 1.1.131); Partial Fulfillment ≥ 1.0.50; core
carrying G380 (a)–(c); the Bench connector extension ≥ 0.3.0; on the ADM
connection, `lookup_code_lists` (and, until core's short-page fix G424 is in,
`extraction_page_size: 100`); on ADM's `ERP_Companies` record,
`erp_order_requires_part_number` on.

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
