# 0.9.42-rc84 — G848: a Bench Dogs seller sees no discount on a quote (🔒2151b)

Built on rc83 (#40, 2ad8de2, open against #38's branch; #39 is merged into
#38, and #38 and #36 are open against main): rc84 = rc83 + this change only.
**Requires** ERP-Epicor ≥ 1.1.134 and Partial Fulfillment ≥ 1.0.50
(unchanged). Every name hidden here was read on ERP-Epicor 1.1.179
(erp-integration-sugar staging-2) and 1.2.0 (280e0929); a name a tenant does
not serve is simply not there to hide.

**The order** (owner, 2026-09-30 21:17Z, 🔒2151b): *"benchdog dont want seller
to apply discount so both the discount pannel and the line item doscounts
should be not vissible on benchdog MLP"*. His screenshot (benchdogs-sandbox,
quote "1-800-FLOWERS - Sep 30, 2026") showed ERP-Epicor's **DISCOUNT** panel
("Apply a discount", "Whole order", % / amount, 0.00, Apply) and the totals
strip's **"Order Level Discount $0.00"**; the grid's **"Line Discount"** column
sits right of Stock Availability (G175's served order).

**Measured before building** (erp-integration-sugar staging-2 fa3a861a and the
1.2.0 pin 280e0929 agree on every name; SugarEnt 26.1.0 for stock):

| Surface (view) | Field / panel | Label a seller reads | Written by |
|---|---|---|---|
| Quotes `record` (and create) | panel `LBL_RECORDVIEW_PANEL_ERP_DISCOUNT`, field `erp_discount_panel` (type `erp-discount`) | "Discount" / "Apply a discount" | ERP-Epicor `QuotesLayout::erpDiscountPanel()` |
| Quotes `quote-data-grand-totals-header` | `deal_tot` | "Order Level Discount" (`LBL_ERP_DOCUMENT_DISCOUNT_AMOUNT`) | ERP-Epicor `erpTotalsHeaderFields()` (🔒 1544a) |
| Quotes `quote-data-grand-totals-footer` | `erp_document_discount_amount` (drawn only when ≠ 0) | "Order Level Discount" | ERP-Epicor `erpTotalsFooterFields()` |
| Products `quote-data-group-list` (rows, edit row, column headers) | fieldset `discount_field` = `discount_amount` + `discount_select` | "Line Discount" (`LBL_ERP_LINE_DISCOUNT`) | stock, relabelled by ERP-Core (🔒 1439) |
| Products `record` (the line page the line number opens, G605) | fieldset `discount_field` | "Discount Amount" | stock |

- **Five new files, one body**, each
  `custom/Extension/modules/<Module>/Ext/clients/base/views/<view>/_override_zz_bd_hide_seller_discounts.php`:
  sidecar view overlays that take those entries out of the served view (from
  every panel, and from a fieldset one level down), plus - defensively - the
  same quote-level or line-level discount figures if an admin placed one
  (`deal_tot_usdollar`, `deal_tot_discount_percentage`, `discount`,
  `erp_document_discount_percent`; `discount_rate_percent`,
  `discount_amount_usdollar`, `discount_amount_signed`, `deal_calc`,
  `deal_calc_usdollar`). The Discount panel goes once it is empty; an admin's
  own field in it keeps it. **Never** `discount_price` or `discount_usdollar`:
  those are the Unit Price.
- **Hide only.** No field, setting or dropdown value (🔒1810b); no deployed
  viewdef written (Sugar includes the overlay after the deployed viewdef at
  every metadata build, and `ViewdefManager::loadViewdef()` skips `.ext.`
  paths, so ERP-Epicor's installer, Quotes Configuration and Studio never save
  the hidden view). The quote's nested fetch list is never entered, so lines are
  still read with `discount_amount` / `discount_select`, and a discount Epicor
  sends still lands in the line and quote totals. ERP-Epicor's SetVisibility
  rules for the panel are left as deployed (Sugar's action returns at a missing
  target). The ERP panel's Discount Warning (`erp_discount_refusal`, read-only)
  stays. No server-side refusal of an API-typed discount (🔒2151b (d)).
- **Either install order; uninstall restores.** `_override` merges after any
  plain fragment in the same directory. Module Loader rebuilds every module's
  extensions on install and on uninstall and clears the API metadata cache, so
  the lifecycle scripts are unchanged (their Accounts/Quotes rebuild is for the
  vardef sync, not for these). Stock and et (no Bench package) keep every
  discount field.
- **Tests.** `scripts/tests/bd_seller_discounts_hidden_test.php` (109 checks,
  wired into `test_php_suites.py`): each overlay run the way
  `MetaDataFiles::getClientFileContents()` runs it (method scope, up to three
  inclusions), on the views a Bench tenant serves built from ERP-Epicor's own
  definitions read off the pinned `QuotesLayout` by reflection, and compiled
  the way `ModuleInstaller::mergeExtensionFiles()` compiles a sidecar directory
  (tags stripped, `_override` last) beside a sibling fragment that puts a
  discount back. **Red first** on rc83: 75 of 109 fail (A1 the Discount panel
  is there, B1 `deal_tot`, C1, D1 `discount_field`, E1). **Mutations, 14 of 14
  killed** (applied to all five bodies): removal short-circuited; fieldset
  members kept; panels or fields left keyed (a JSON object, not a list);
  the sweep widened into the fetch list; the empty Discount panel kept;
  `deal_tot` / `erp_document_discount_amount` / `erp_discount_panel` /
  `discount_select` dropped from a list; `$viewdefs` assigned when nothing
  changed; no `unset`; the panel name dropped; a plain (non-`_override`)
  name. Sugar 26.1.0's own ModuleScanner: no finding on the five;
  `mlp_lint.py` source and built zip clean.
- **Proposed, not done:** with no discount visible, "Line Items Discounted
  Subtotal" (ERP-Core `LBL_NEW_SUB`) and the grid's "Discounted Total"
  (`LBL_ERP_DISCOUNTED_TOTAL`) still say "Discounted". Relabelling them is a
  language override this package would have to own; left for the owner.

# 0.9.42-rc83 — G840: the ADM quote defaults keep working on ERP-Epicor 1.2.0, whose ErpQuoteFacts is namespaced

Built on rc82 (#39, bc2d24a; stacked on rc81 #38; neither on main yet): rc83 =
rc82 + #40 (c0b7030, the one shipped change) + the test re-pin below.
**Requires** ERP-Epicor ≥ 1.1.134 and Partial Fulfillment ≥ 1.0.50 (unchanged).
It runs on BOTH sides of ERP-Epicor 1.2.0, so it goes on a tenant BEFORE 1.2.0
(🔒2142b); on 1.1.x it behaves exactly as rc82.

**The defect** (G840): ERP-Epicor 1.2.0 (erp-integration-sugar
`refactor/rafael-review` @ 280e0929, tree 206337c9; T2 of the Rafael review,
#158) moves ErpQuoteFacts to `custom/src/Erp` as
`Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts`, autoloaded, deletes the old
`custom/modules/Quotes/ErpQuoteFacts.php` on the tenant
(ONEOFF-RemoveErpLeftovers) and ships no global alias. rc82's BdAdmRules asked
only for the global class (autoload off), then the old literal path - so on
1.2.0 every ADM quote default (Reference, Project, G809's Lead Source / Lead
Type / Project / Campaign + Event) would silently stop, logging
"custom/modules/Quotes/ErpQuoteFacts.php is missing" on each save.

- **`BdAdmRules::quoteFactsAvailable()`** asks for the namespaced class first,
  WITH autoload, and only then the global class at its literal path, so a
  leftover old file beside the namespaced class is never included. The three
  calls (`companyCode`, `productGroup`, `referenceMaxLength`) go through
  helpers that name one class or the other literally: no `class_alias`
  (ModuleScanner blacklist), no class held in a variable (MLP017).
- **Nothing else here names what 1.2.0 moved.** All 74 classes 1.2.0 puts in
  `custom/src/Erp`, and every `custom/...` path this package names, were
  checked against Sugar staging (a7737052, ERP-Epicor 1.1.179) and 280e0929:
  only ErpQuoteFacts. No field type, view or layout this package names is
  removed; the provider paths under `custom/modules/Quotes/ErpQuoteHooks/` are
  unchanged; the package id `sugarai_erp_epicor` is the same, and 1.2.0
  satisfies the ≥ 1.1.134 dependency.
- **Tests re-pinned to 1.2.0.** `fixtures/shared-sugar/` is now taken from ONE
  commit (280e0929, named in full in `PINNED.json`) and mirrors the sibling's
  paths; the four moved pins (QuoteOpportunityAmount,
  QuotePrimaryQuoteSoleEnforcer, ErpAccountCountryGuard - ERP-Core's - and
  ErpQuoteFacts - ERP-Epicor's) are loaded through ERP-Core's own autoloader
  stand-in (`tests/support/sugar_autoloader.php`, pinned too), never by
  require. The global ErpQuoteFacts a 1.1 tenant still runs is pinned to its
  last commit (a7737052, 1.1.179) under `fixtures/erp-epicor-1.1/`.
  `bd_adm_rules_test.php` runs three times: 1.1's global class (121 checks),
  no class (4), 1.2.0's namespaced class through the stand-in (124).
- **Red first.** Against a sibling checkout of 280e0929 the drift guard failed
  12 of its 13 pins (4 moved, 8 changed). With rc82's BdAdmRules (bc2d24a) and
  the 280e0929 pin: 1.2.0 run 25 of 124 fail, 1.1 run 2 of 121, no-class run 1
  of 4; with rc83, 0 in all three. Five controls fire and revert: a byte flipped
  in a 1.2.0 pin and in the 1.1 pin, a pin path put back to the old location,
  the harness naming the global class, the pinned enforcer removed.
- Whole suite (`-m "not sugarent_tree"`): with the 280e0929 sibling 363
  passed, 4 skipped; pins only (CI's shape) 358 passed, 9 skipped (the nine the
  workflow names).

# 0.9.42-rc82 — G458: the Epicor contact Function, Role and primary flags are SHOWN, read-only, on the Contacts record view

Built on rc81 (#38, a14f18f - the tree installed on benchdogs-dev / sandbox;
stacked on rc80 #36; neither on main yet): rc82 = rc81 + this change only.
**Requires** ERP-Epicor ≥ 1.1.134 and Partial
Fulfillment ≥ 1.0.50 (unchanged; nothing here needs a newer ERP-Epicor). The
fields themselves come from Bench Dogs' OWN package
`Bench_Dogs_Account_Contact_Fields` 1.0.0 (installed on benchdogs-dev and
benchdogs-sandbox 2026-09-24); where it is absent this build changes nothing on
Contacts.

**The defect** (benchdogs-dev, 2026-09-30): the Bench connector extension 0.3.6
(level L807, live 11:13Z) wrote Epicor CustCnt Func / RoleCode / PrimaryBilling
/ PrimaryPurchasing / PrimaryShipping onto 1,510 contacts (e.g. JEFFREY SENN,
ADM__834_3: CONTROLLER / ACPY / Primary Billing), and Codex read "Not shown" for
all five on five contacts (11:20–11:22Z). Measured read-only through the stage
core the same day: the Contacts record view on dev AND sandbox has
`panel_header`, `panel_body` (9 fields) and ERP-Core's `LBL_RECORDVIEW_PANEL_ERP`
("ERP": write-back status / at / message, ERP Contact ID) and names none of the
five, while the Contacts vardefs serve all five (`custom_fields`; the three
primaries readonly by the customer's override, Function and Role editable).

- **One new file, not a field:**
  `custom/Extension/modules/Contacts/Ext/clients/base/views/record/bd_epicor_contact_fields.php`,
  a sidecar record-view overlay. Sugar concatenates it into the Contacts
  `record.ext.php` and includes it after the record viewdef (base, ERP-Core's
  or Studio's) at every metadata build, so nothing is written to a deployed
  view. It appends Function, Role, Primary Billing, Primary Purchasing, Primary
  Shipping (that order) to the **ERP** panel after ERP Contact ID; without that
  panel, the end of `panel_body`; without either, the first non-header panel
  with fields; never the header. Every entry of the five on the view is
  `readonly` (🔒2102b: Epicor → Sugar only; L807 would overwrite a Sugar edit),
  including one an admin placed, which is not moved.
- **Guarded:** a field is placed only when the Contact vardefs MERGED with
  `fields_meta_data` (`VardefManager::loadVardef`) hold it with a type; unread
  vardefs, a malformed list or any Throwable leave the view exactly as read. On
  Ophir / stock / et (no customer package) the overlay is a no-op.
- **Why not the `erp_layout` marker** this package uses for its own fields:
  ERP-Core's `ErpLayoutExtraFields::sync()` supports Quotes and Accounts only,
  and a marker on a field ANOTHER package owns cannot be guarded — Ext vardefs
  are included before `fields_meta_data` merges (SugarEnt 26.1.0), so a guard
  there is always false, and without the customer's package the marker would
  leave a typeless phantom Contact field. Extending sync() to Contacts is an
  ERP-Core change + ERP-Epicor release; not needed for this.
- No lifecycle change: `install_extensions()` rebuilds every module's
  extensions and the install ends with `MetaDataManager::clearAPICache()`;
  uninstall deletes the file (`uninstall_copy`) and rebuilds/clears the same way.
- Tests: `scripts/tests/bd_contact_fields_test.php` (44 checks, wired into
  `test_php_suites.py`) runs the file the way `getClientFileContents` does —
  twice and three times per build — on the view benchdogs-dev serves. Red on an
  empty overlay (today's behaviour): 11 of 42 fail, A1 showing the ERP panel
  ending at `erp_display_sync_key`. Mutants 16/16 killed. `test_g280` KEPT
  gains the one path.

# 0.9.42-rc81 — G809: each quote default comes from the account's newest quote holding a value ADM still offers; Project is defaulted too

Built on rc80 (#36, 43ec7f1, not yet on main). **Requires** ERP-Epicor ≥ 1.1.134
and Partial Fulfillment ≥ 1.0.50 (unchanged). The create-form half needs
**ERP-Epicor 1.1.175** (ERP-Core's `erp-dependent-enum`, same G809 rule,
erp-integration-sugar `fix/g809-per-field-prefill`). On ERP-Epicor 1.1.173 /
1.1.174 the create form reads Project's new key too, but with the one-row read
(a retired newest value still blocks a field); before 1.1.173 the key is inert.
The server half below applies at save either way.

**Graded on rc80 + 1.1.174** (benchdogs-dev, account BENCHMARK CONSTRUCTION
COMPANY. INC, 2026-09-30T00:26Z): a new quote got Lead Source BIDINVTE and Lead
Type CASEGDS, but Mktg Campaign and Marketing Event stayed EMPTY although older
quotes of the account hold a pair. Each field already came from the newest quote
holding IT (the Lead Source read and the pair read are separate; tests Q1, Q16b).
What failed: each read took ONE row, and the newest quote holding a pair holds
24CGINST / 24CGINST/1, which the pickers do not offer (inferred: the create form
would otherwise have filled it) - so nothing was filled.

- **One rule, both writers.** `BdAdmRules::applyAccountHistory()` (before_save,
  a quote created without the form) now reads the account's newest
  `HISTORY_SCAN` = 20 quotes holding the field, newest `date_entered` first, and
  takes the FIRST whose value the picker still offers; a retired code is skipped,
  never copied. The pair: the first quote holding both whose event is its
  campaign's and whose campaign and event are both offered; with the seller's
  campaign already chosen, the newest quote holding THAT campaign gives its own
  event (a quote with another campaign is skipped, never mixed; Q9's first case
  changed accordingly). ERP-Core's create form reads the same 20 by the same rule.
- **Project (owner scope, 2026-09-29T21:05Z).** `bd_project_id` gets
  `erp_prefill_from_account_latest` (metadata on the existing field; no new field,
  column or setting, 🔒 1810b), and the server half copies it too
  (`ACCOUNT_HISTORY_FIELDS`). 🔒 1712b's product-group default runs first and
  keeps precedence where it applies; history fills only what it left empty.
  ERP quotes do not carry a Project (it is OrderDtl.ProjectID; nothing reads it
  back), so the history is the account's quotes raised in Sugar.
- **Not changed, and worth a ruling:** "newest" is Sugar's `date_entered`. On the
  reseeded tenants the older ERP quotes were inserted AFTER the recent ones
  (benchdogs-dev: ADM__7178, Sugar #7407, entered 13:23:45Z; ADM__8663 with
  26CGCOMM, Sugar #1213, earlier), so for such an account the newest by
  `date_entered` is not the customer's latest ERP quote.
- Tests (`bd_adm_rules_test.php`, 119 checks): Q14-Q18 new, Q2 (four reads, up to
  20 rows), Q9 and M9 updated. Red on rc80 43ec7f1: 8 fail (Q16 reproduces the
  graded symptom: BIDINVTE / CASEGDS filled, the pair empty). Mutants 7 run, 6
  killed; the survivor (history before the product-group default) is equivalent:
  that default overwrites the value either way.

# 0.9.42-rc80 — G817: the quote's create prompt asks for Cust. Group instead of offering a Create the ERP refuses

Built on rc79 (#35, main fe97ab2). **Requires** ERP-Epicor ≥ 1.1.134 and
Partial Fulfillment ≥ 1.0.50 (unchanged). The behaviour needs **ERP-Epicor
1.1.174** (ERP-Core's G817 route and prompt, erp-integration-sugar #125). On
an older ERP-Epicor the key is inert: the prompt offers Create as on rc79, and
the Bench connector extension's refusal names the field (the backstop).

**G817** (owner, benchdogs-sandbox, account SAIQA-G805-Bench-2 with no Cust.
Group, 2026-09-29T21:25:27Z: "did not create an account why did it offer to
create an account with no group then in the message????"):
`bd_customer_group_code` carries ERP-Core's `erp_customer_create_required_formula`
= `equal(related($erp_companies_accounts,"erp_sync_key"),"ADM")` - the 🔒2086b
ADM gate, verbatim from rc79's view rule. Before the G805 prompt ("Create
<account> in the ERP now to get customer pricing?") offers Create, ERP-Core
evaluates it; on an ADM account with no group the seller reads "To create
<account> in the ERP, set: Cust. Group." with the link to the account, and no
Create is offered. EPIC06 accounts (Ophir, stock) are never asked.

- Only the create prompt reads the key: the field is still served neither
  `required` nor with a `required_formula` (the connector's schema check, G809),
  and ERP-Epicor's G496 order check never reads it.
- The type is not part of it: the prompt only runs for an account with no ERP
  key, and types it Customer on Create, so an ADM Prospect is asked too.
- A vardef key on an existing field: no new field or setting (🔒 1810b).
- Tests (`test_g809_connector_schema_safe.py`): the served key is exactly the
  view rule's ADM clause and nothing is served required (runs in CI); the
  formula in SugarCRM's own SugarLogic Parser - ADM Customer and ADM Prospect
  asked, EPIC06 and no company not (a SugarEnt-tree test, named in CI's list,
  now 15). Red on main fe97ab2: 2 fail. Mutants 6/6.

# 0.9.42-rc79 — 🔒2085b: Cust. Group required for an ADM Customer not yet in the ERP; the rc78 Account-button text corrected

Built on rc78 (#33, main 75b982f) plus #34's text fix (f79913a). **Requires**
ERP-Epicor ≥ 1.1.134 and Partial Fulfillment ≥ 1.0.50 (unchanged).

**🔒 2085b** (owner YES; the ADM gate ruled Bench-specific by 🔒 2086b, "no its
benchdogs specific"): `Ext/Dependencies/bd_adm_customer_group_required.php`
makes Cust. Group (`bd_customer_group_code`) required in the Accounts record and
create views when the account type is Customer, the account has no ERP key, and
its ERP company is ADM (`related($erp_companies_accounts,"erp_sync_key")`).
Leads, prospects and suspects kept in Sugar, accounts the ERP holds, and every
EPIC06 account (Ophir, stock: no customer groups to pick) are never blocked.

- A VIEW dependency ('hooks' edit): the field's SERVED `required` stays false and
  it serves no `required_formula`, so the connector's schema check never refuses
  an Account write without a group, and ERP-Epicor's G496 order check and G805
  price-step create (which read a served Customer formula, with no ADM gate) are
  unchanged. The G805 prompt saves over REST without a view, so it is not covered:
  the Bench connector extension's refusal names the field there (the backstop).
- Tests: `test_g809_connector_schema_safe.py` - the dependency's shape, the
  connector's real `describe_module` + `check_payload` passing an Account without
  a group (the served-required CONTROL refused), and the formula in SugarCRM's own
  SugarLogic parser for seven cases (ADM Customer required; ADM Prospect / Suspect,
  ADM Customer in the ERP by either key, EPIC06 Customer, Customer with no company:
  not required). Red on rc78+#34: 2 fail. Mutants 7/7.

**#34 folded in:** the rc78 comments and release note said the server-side
defaults do not reach a quote raised from the Account page's button; they do
(`createOppQuote` sets `billing_account_id` before its first save). Text only.

**The empty Group Code picker on benchdogs-sandbox (reported 2026-09-29):** no
code change. The server answers the picker live (`GET
/Accounts/enum/bd_customer_group_code`: the blank + 23 groups, measured); the
browser keeps the loaded list for the page (stock enum.js context cache), so a
page opened before the groups were published (21:10:18Z) shows an empty list
until it is reloaded.

# 0.9.42-rc78 — G809: the five ADM quote values are required until the quote is in the ERP, and defaulted; G804: Cust. Group is pickable before the account is in the ERP

Built on rc77 (PR #32, text only). **Requires** ERP-Epicor ≥ 1.1.134 and
Partial Fulfillment ≥ 1.0.50 (unchanged). The browser half of G809 (required
until synced, prefill on the create form) is ERP-Core's `erp-dependent-enum`
keys `erp_required_until_synced` / `erp_prefill_from_account_latest`
(erp-integration-sugar `fix/g809-required-until-synced`, rides ERP-Epicor
1.1.173). On an older ERP-Epicor both keys are ignored: the four pickers stay
optional and unfilled in the browser, as on rc77. Nothing else depends on the
order.

**G809** (owner, benchdogs-sandbox quote 8972, 2026-09-29: "if these are
required fields it should not let me save the quote and maybe we should put
defaults"):

| Change | Why |
|---|---|
| Lead Source, Lead Type, Mktg Campaign, Marketing Event: `erp_required_until_synced` and the `erp-dependent-enum` view type on all four (Project and Event had it) | Save refuses while the quote has no ERP key and the picker has ADM options; a quote the ERP holds is never blocked (all 1,246 on benchdogs-sandbox hold these EMPTY today). Ophir/EPIC06 (empty lists) stays optional |
| Lead Source, Lead Type, Event: `erp_prefill_from_account_latest` (billing_account_id) | the create form fills them from the account's newest quote holding them (the Event fills the Campaign + Event pair from ONE quote), only into empty fields, only with a value the picker still offers |
| `BdAdmRules::applyDefaults()`: the same account-history rule on a NEW quote in before_save | a quote created without the form (API, the Account button). Pilot ADM: consecutive quotes of one customer repeat Lead Source 93 %, Lead Type 95 %, Campaign 74 % |
| `Ext/Dependencies/bd_adm_reference_required.php`: Reference required in the edit views when not in the ERP, a Lead Source is picked (ADM), and the ship-to has neither city nor state | only then can the existing CITY ST default (G380/G530) not fill it. A view dependency, NOT vardef `required`: the connector's schema check refuses a Quote create without a SERVED-required field |

Every one of the five stays SERVED `required: false` (pinned by
`test_g809_connector_schema_safe.py`, with the connector's real
`describe_module` + `check_payload` where that code is present).

**G804** (🔒 2081b): `bd_customer_group_code` becomes an `enum` over ADM's
customer groups (ERP_LookupValues `BdCustomerGroups`, published by the Bench
connector extension), read-only once the account has an ERP key
(`readonly_formula`), and the new Accounts before_save sets
`bd_customer_group` to the group's name when the picked code changes. Same
column; no new field.

# Unreleased — G606: Bench claims Reference placement

`_override_bd_erp_reference.php` marks core's existing `Quotes.erp_reference`
for the record view's ERP panel, after Ship Via. The field definition, storage,
validation and sync remain core's. Version unchanged; the coordinator cuts the release.

On an existing Bench installation meeting the current dependency floors, either
upgrade order is safe: this Bench build then ERP-Epicor with Sugar commit
`a1fa64a9`, or that ERP-Epicor build then this Bench build. In the latter order,
core keeps Reference because the existing Lead Source marker anchors after it.
The `_override` fragment then wins regardless of file modification times.
For a fresh installation, install the required ERP-Epicor and Partial Fulfillment
dependencies first, then Bench; Bench's post-install sync places Reference.

The four shared test fixtures `BaseErpLayout.php`, `QuotesLayout.php`,
`ErpLayoutExtraFields.php` and `erp_reference.php` are re-pinned byte-for-byte
from held Sugar commit `a1fa64a92d55d54bf95323b2ec18dc6fe9fb2160`, using
`refresh_shared_fixtures.py` over those four files. `PINNED.json` records their
per-file commit; the other pins retain the top-level commit. A normal full
refresh after core lands will also pick up unrelated upstream changes.

# 0.9.42-rc75 — build: G574 + G578 + G571/G570 keys on rc74

The "Unreleased (on 0.9.42-rc74)" section below ships as **0.9.42-rc75**, cut
on `main` 27997c0 (rc74 c978f39 + G574/G578 b7828e6 + the G437 one-off merge,
which touches no file of this package) plus two commits on `release/rc75`: the
shared test fixtures re-pinned from the Sugar target f5c5eecd (test-only, not
packed; the `ErpLayoutExtraFields.php` pin is byte-identical to the one lane
D19's patch carried), and lane D19's G571 / G570 vardef keys + floor.

**Requires:** ERP-Epicor **≥ 1.1.134** (was 1.1.131: the first release with
ERP-Core's `erp-dependent-enum` and the marker `type`), Partial Fulfillment
**≥ 1.0.50** (unchanged). Install ERP-Epicor 1.1.134 first; Module Loader
refuses this package on an older ERP-Epicor (ERR_UW_NO_DEPENDENCY).

Shipped files changed since rc74 (zip diff = git diff c978f39..this build on
`custom/` + `scripts/`): `custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php`
and `custom/Extension/modules/Quotes/Ext/Language/en_us.bd_adm_required_fields.php`;
the manifest's ERP-Epicor floor. No script, no layout code, no new file.

# Unreleased (on 0.9.42-rc74) — G574: no pre-picked Project; G578: the campaign label; G571 + G570: picker keys

Version not bumped (branch convention): the landing picks the rc. No new file,
no field, no layout, no script change; vardef/language edits and the ERP-Epicor
floor.

**G574 — a new quote started with ANOTHER customer's Project.** Measured on
benchdogs-dev (SALES ORDER smoke 2026-09-25, `#Quotes/create`): Project read
"17879 - LGH EXPANSION" before any account was chosen. Cause, read from
SugarEnt-Full 26.1.0 (and 25.2.0, identical): the stock EnumField's
`_checkForDefaultValue()` sets an empty enum to its FIRST option on a create or
edit form unless the def says `defaultToBlank`, and the first option is not the
blank one because a JavaScript object lists integer-like keys ("17879",
"18126") before every other key once the REST answer is `JSON.parse`d.

| Change | Why |
|---|---|
| `'defaultToBlank' => true` on all five Bench quote pickers (`bd_adm_required_fields.php`) | sidecar builds a field's def as the vardef extended by the viewdef (`sidecar/src/view/field.js`), so the flag works from the vardef with no layout code (stock DataArchiver's `process_type` does the same). Only Project has integer-like codes today; none of the five may be defaulted by the browser (ADM names no default). Side effect, intended: the pre-pick made Project non-empty, so the before_save CMI -> 20065 default (🔒 1712b), which fills only an EMPTY Project, could never apply to a quote created in the browser |
| Tests: `bd_adm_rules_test.php` M7; `scripts/tests/test_g574_g578_bench_quote_pickers.py` + `g574_stock_enum_default.cjs` | M7 pins the flag on all five (CI). The new file runs the premise in CI (PHP's `optionsFromRows()` order vs the browser's `JSON.parse` order; the build container now installs `nodejs` for it) and, where a SugarEnt tree exists, drives Sugar's OWN EnumField on the create form: the shipped def sets no default, and the control (the same def without the flag) reproduces "17879". Named in `mlp-lint.yml`'s left-out list (now 11) |

**G578 (the Bench half) — "Marketing Ca…".** On the Quotes ERP panel the
18-character "Marketing Campaign" was cut while "Marketing Event" showed in full.
`LBL_BD_MARKETING_CAMPAIGN` is now **"Mktg Campaign"**: narrower than "Marketing
Event" (13 px Helvetica/Arial 91.0 vs 93.9 px; the old text 119.9), key unchanged,
so an upgraded tenant gets it through the language extension.
`bd_adm_rules_test.php` M5b pins the text, M5c that no Bench quote label is
longer than "Marketing Event". **Not here:** G578's "No data" status cell in edit
mode is ERP-Epicor's `erp-comment-log-status` field, and the sticky success toast
is ERP-Epicor's too (lane D).

**G571 + G570 — keys only; the client code is ERP-Core's (coordinator ruling
2026-09-25, 🔒 1520 / 🔒 1567 / 🔒 1514; lane D19 `fix/g571-erp-dependent-enum`).**
Measured 2026-09-25: the Marketing Event picker listed all 68 events with
26DISCNV chosen, quote #4 saved 26DISCNV with 26BRECLN/1, and an empty Project
was found only at Submit Order. ERP-Core now ships the `erp-dependent-enum`
field type, and ErpLayoutExtraFields writes an `erp_layout['type']` onto the
record-view entry (also on a tenant where the field is already placed). This
package only declares:

| Change | Why |
|---|---|
| `bd_marketing_event`: `erp_lookup_parent` = `bd_marketing_campaign`, separator `/`, `erp_lookup_parent_empty_label` = `LBL_BD_MARKETING_EVENT_PICK_CAMPAIGN` ("Pick a campaign first"), marker `type` = `erp-dependent-enum` | only the chosen campaign's events (the key split at the LAST `/`, exactly the campaign: 26DISC never gets 26DISCNV's); a seller's campaign change clears an event that no longer belongs. A pair saved before this stays shown until the campaign next changes; opening, editing or cancelling a quote clears nothing |
| `bd_project_id`: `erp_required_when_options` = true, marker `type` = `erp-dependent-enum` | required in the browser while the tenant has ADM projects (Bench); an empty list (Ophir) stays optional. The ext's refusal at send stays the server guard. Side effect: a browser-saved quote now always carries the seller's Project, so the before_save CMI -> 20065 default (🔒 1712b, fills only an EMPTY Project) applies only to quotes created without the form |
| ERP-Epicor floor 1.1.131 -> **1.1.134** (`pack.php`) | the first release with the field type and the marker `type`; below it the keys are inert (plain enums, the gap stays open). 1.1.133 was built without it (4584d74f); adjust if the carrying release is numbered otherwise |
| Tests: `bd_adm_rules_test.php` M4 (the marker types), M5/M5c (the hint label is counted, the width rule applies to field labels), M8 (the keys, and no `custom_type`); `bd_erp_layout_test.php` T6f (an rc72 tenant upgraded: the already-placed Project entry and the new Event entry carry the type, red on the 1.1.132 placer); `test_g280_minimal_footprint.py` (the floor); the `ErpLayoutExtraFields.php` pin refreshed from lane D19's branch | |

The filter, clear, required and placeholder behaviour is tested in the Sugar
repo (`scripts/tests/test_erp_dependent_enum.cjs`, on SugarCRM's own 26.1.0 and
25.2.0 EnumField).

# 0.9.42-rc74 — build: G450 (the "Suspect" account type) on rc73

The "Unreleased (on 0.9.42-rc73)" section below ships as **0.9.42-rc74**, cut
from `fix/g450-suspect-account-type-r2` on `release/rc73` 51e8e40 (so it carries
rc73's G460 + G530 + G532 unchanged). rc71 stays VOID and is never reused.

**Requires (unchanged from rc73):** ERP-Epicor **≥ 1.1.131**, Partial
Fulfillment **≥ 1.0.50**. The Suspect key needs no ERP-Epicor change: it merges
over ERP-Core's `account_type_dom` whatever the ERP-Epicor version.

# Unreleased (on 0.9.42-rc73) — G450 revived: the "Suspect" account type (🔒1783b)

The customer reversed 🔒1775b (Layne, relayed by the owner 2026-09-25 02:19Z):
*"The Bench Dogs team said they want all 3 record types to sync to Sugar,
including Suspects."* This re-applies the VOIDed rc71 change (`fix/g450-suspect-account-type`
ae3cd4a) on rc73 unchanged in substance; only the WRITER it names has moved.

**Measured read-only (2026-09-25 02:30Z, stage core's stored ADM connection +
each tenant's stored Sugar destination, GET only):** ADM holds 1,511 customers:
SUS 1,022 (846 active, 176 inactive), PRO 110 (97 / 13), CUS 379 (329 / 50).
benchdogs-dev AND benchdogs-sandbox each hold all 1,511 as ADM-keyed Accounts
(0 missing, 0 duplicate keys, 0 orphans), every SUS typed **Prospect**; both
serve `account_type_dom` = {Customer, Prospect}; neither ADM connection sets
`customer_type_extra`. So Suspects already sync; they only lack their own type.

| Change | Why |
|---|---|
| **Added** `custom/Extension/application/Ext/Language/_override_en_us.bd_account_type_suspect.php`: `account_type_dom['Suspect'] = 'Suspect'`, only when absent | the value core's accounts step writes for an Epicor SUS customer once the ADM connection's `customer_type_extra` is `{"SUS": "Suspect"}` (core `connector_epicor.normalize.customer_type_extra`, live since c54). The key is byte-exact: core writes the configured value as given, and a key the dom lacks renders BLANK. Customer / Prospect are untouched; an admin's relabel in Dropdown Editor survives a reinstall |
| The `_override` name | SugarEnt 25.2/26.1 merge `_override*` fragments after every plain one, whatever the mtime; ERP-Core's REPLACE install assigns `account_type_dom` as a whole array, which would otherwise wipe the key after an ERP-Epicor REPLACE upgrade (rc23 measured this for the lookup-type label) |
| Tests: `scripts/tests/test_g450_suspect_account_type.py` | executes Sugar's fragment merge in PHP over a stock seed with ERP-Core's REAL template (pinned under `fixtures/shared-sugar/`; byte-identical at the pin 6ed6f1b1, at release/1.1.131 and at the Sugar target 31a7ad05), incl. the control that a plain name loses the key; the order rule is pinned against both SugarEnt trees (named in `mlp-lint.yml`) |

No field, no layout, no script change. `account_type` locks only on a keyed
CUSTOMER (ERP-Core's `account_type_readonly_formula`, G495), so a keyed Suspect
is seller-editable: Suspect -> Customer writes `CUS` back to Epicor; Suspect ->
Prospect over an Epicor SUS sends nothing (coordinator ruling on G450, accepted).

**Rollout order (each step its own owner yes):**
1. Install this package; verify `GET /Accounts/enum/account_type` serves
   Customer, Prospect AND Suspect. **Not before this:** otherwise step 2 writes
   an out-of-dom value that renders blank on ~1,022 accounts.
2. God's View: PATCH the ADM connection with `{"customer_type_extra": {"SUS": "Suspect"}}` (byte-exact).
3. One FULL accounts run (L300, `sync_mode: full`), not a delta: the accounts
   delta selects only customers whose `SysRevID` moved, so it would re-type
   almost none of the existing Suspects. Only on a core carrying G546's fix
   (7f6ffcf45).

The Bench connector's own `account_suspect` feature (ext 0.3.1+) must stay OFF
(never named in `SUGARAI_BD_MLP_FIELDS`): core is now the only writer of the
type, and naming it would make two writers of one field.

# 0.9.42-rc73 — build: G460 + G530 + G532 on rc72

The two "Unreleased (on 0.9.42-rc72)" sections below ship together as
**0.9.42-rc73**, cut from `fix/g460-marketing-campaign-event` bfc81ea2 (which
carries G530/G532 from 8acec196). Lane E release cut, branch `release/rc73`.

**Requires (the manifest refuses otherwise):** ERP-Epicor **≥ 1.1.131** (was
1.1.125) and Partial Fulfillment **≥ 1.0.50** (unchanged). 1.1.131 is the
release carrying G530's limit on the field, `Quotes.erp_reference`
`erp_max_length` (10), read through `ErpQuoteFacts::referenceMaxLength()`,
which this package's Reference default is shortened to. Install ERP-Epicor
1.1.131 first; rc73 uploaded onto 1.1.130 is refused before any file copies.

# Unreleased (on 0.9.42-rc72) — G460 Marketing Campaign + Marketing Event

Version NOT bumped on this branch (as G530/G532 below): the landing picks the
next unspent rc. Built on `fix/g530-g532-bench-reference-labels` (8acec19), so
it carries G530/G532 too.

**Measured on benchdogs-dev (install session smokes, 2026-09-24):** once G380's
columns were accepted, ADM refused Send to Estimation with *"A valid Marketing
Campaign is required / A valid Marketing Event is required"* and Submit Order
with *"You must select an active Marketing Campaign."* Sugar had no field for
either, so no seller could fix it: every Bench Dogs ERP write was refused.
Read-only on ADM (2026-09-25): the order carrier is `OrderDtl` (69 of 69 lines
on the last 11 real orders carry the pair; `OrderHed` has no such column);
ADM defines no default event.

| Change | Why |
|---|---|
| `bd_adm_required_fields.php`: `bd_marketing_campaign`, `bd_marketing_event` (enum, function options, `erp_layout` after `bd_project_id`, not `required`) | the seller's pair; placed by ERP-Core's `ErpLayoutExtraFields` on install and upgrade (an rc72 tenant: after Project, nothing placed moves; `bd_erp_layout_test.php` T6) |
| `en_us.bd_adm_required_fields.php`: "Marketing Campaign" / "Marketing Event" | ADM's own words |
| `en_us.bd_adm_lists.php`: `BdMarketingCampaigns` / `BdMarketingEvents` labels | the two lookup types the Bench connector extension (0.3.3+) publishes |
| `BdAdmRules::marketingOptions()` + `BdAdmLookupOptions.php`'s two functions | ACTIVE PAIRS only (a campaign with an active event; an event of an active campaign); events keyed `<campaign>/<seq>`, ordered by campaign then seq as a number (sorted by a composed key and `ksort()`: `usort()` is on ModuleScanner's blacklist, MLP002) |
| no default, no before_save change | ADM names no default; the customer decides any default later |

**Needs, in this order:** (1) this package on the tenant (its pickers are empty
until step 3); (2) the Bench connector extension **0.3.3** (publishes the lists,
sends the pair on the quote and on every order line, refuses a blank or
mismatched pair by name), explicitly approved per tenant; (3) one Run Now of its
`benchdogs_marketing_adm` pipeline, read back as 211 campaigns / 811 events
(42 / 68 active today). This package goes FIRST so the lookup types are
labelled before any row is published.

**Tests:** `bd_adm_rules_test.php` K1–K8 (the pairing, the key, the order, no
default), G2/M1/M3/M4/M5/M5b (five types, five fields, markers, labels);
`bd_erp_layout_test.php` T1b/T1c/T1e/T3 (five pickers) and T6 (upgrade from
rc72's real vardef, `fixtures/rc72/`).

# Unreleased (on 0.9.42-rc72) — G530 Reference default fits; G532 Customer Group labels

Version NOT bumped on this branch: the landing picks the next unspent rc.

**G530 (benchdogs-sandbox r7, quote #36, 2026-09-24 21:08:21Z):** the ADM
default Reference "HARRISBURG PA" (13) was refused by the ERP, *"The maximum
number of characters allowed for Reference is 10"*, and no ERP quote was
created. Measured: 10 is Epicor's shipped width (EPIC06 data dictionary,
`QuoteHed.Reference` DefaultFormat `x(10)`), so it is ERP-Epicor's number, on
the field (`erp_reference.erp_max_length`); this package only SHORTENS its own
default to it.

| Change | Why |
|---|---|
| `BdAdmRules::defaultReference($city, $state, $max)`: state kept, city cut to fit; no state / no room: first `$max` characters; `$max` 0 = unchanged | the documented rule (README "Reference"); `HARRISBURG PA` → `HARRISB PA` |
| `BdAdmRules::referenceMaxLength($bean)`: asks `ErpQuoteFacts::referenceMaxLength()` behind `method_exists` | an ERP-Epicor older than G530 is tolerated: no cut, no error (the ERP answers as before), and the reason is logged |
| `en_us.bd_customer_group.php`: "Cust. Group" / "Group Code" (G532) | both labels were cut to "Customer Gro…" on the Business Card; about 12 characters show, each is now ≤ 11. Keys unchanged, so an upgraded tenant gets the text |
| `fixtures/shared-sugar/` re-pinned from `erp-integration-sugar` `fix/g530-g531-reference-length-catalog-search` (6ed6f1b1 = target 65413736 + G531 + G530) | the tests run against ERP-Epicor's REAL `ErpQuoteFacts::referenceMaxLength()` and ERP-Core's REAL `erp_reference` vardef. Six other pins were already stale against the target (a0f6b632 → 65413736); refreshed with them, suites green |

**Full G530 needs the ERP-Epicor build carrying 6ed6f1b1** (the field's limit,
the save-time check, Send to Estimation's up-front refusal). This package alone
on an older ERP-Epicor changes nothing about Reference.

**Tests:** `bd_adm_rules_test.php` B5–B13 (the rule) and H13–H17 (end to end
through the real `ErpQuoteFacts` and the real vardef; the older-ERP-Epicor
case in a child process, which must also LOG why the default was not cut) — mutants 9/9 killed. `test_g280_minimal_footprint.py`
pins the two labels and the 11-character bound (2/2 killed).

# 0.9.42-rc72 — G507: the customer group leaves the header for the Overview tab

Owner, on Ophir (ADDISON WB I85L06), 2026-09-24 17:43Z: *"can we move this fields
into the overview?"* — "Distribution" and "DIST" sat beside the account name,
unlabelled, where sellers read them as buttons.

**Measured before building** (Ophir, SugarEnt 26.1.0, served Accounts record
view, read-only): `panel_header` carried `bd_customer_group` and
`bd_customer_group_code` (each with a baked `type: text`). That view has **no
`panel_body`**: its tabs are `panel_overview` ("Overview"), Sugar Predict, Record
Information and ERP. rc69's writer placed the pair on `panel_body`, else on *the
first panel that holds fields* — which there is the header. rc70/rc71's vardef
comment ("rc69 put them on panel_body on every live tenant") was wrong for
Ophir; its marker would also have resolved `panel_body` to the ERP tab there.

| Change | Why |
|---|---|
| `bd_customer_group.php` vardef: `erp_layout` panel `panel_body` → **`panel_overview`, after `industry`** (the code after the name) | the first tab of the measured Bench tenant, beside Type / Industry. A FIRST-EVER placement (fresh install) lands there; `sync()` never moves a placed field |
| same vardef: **`'readonly' => true`** on both | `sync()` writes only name + label, so read-only has to come from the vardef: Sidecar's `isFieldAlwaysReadOnly()` falls back to it (26.1.0 `utils.js`), so the pair renders in detail mode even in Edit. Client-side only on Accounts: `populateFromApi()` checks field ACLs, not `readonly`, and ERP-Core's `SugarACLErpOwnedFields` is registered on ERP_* modules only — the connector's REST writes are unaffected |
| **NEW one-off** `sugar-sell/ONEOFF-MoveBdCustomerGroup` 1.0.0 (separate package, id `oneoff_move_bd_customer_group`) | rc72 ALONE DOES NOT MOVE THEM on an upgraded tenant: a header entry is "on the view", and `sync()` leaves placed fields alone. 🔒 1724b forbids layout code in this package (`test_no_shipped_file_touches_a_record_view`), so the one-time move is a disposable one-off, like ONEOFF-RetireBdResidue. It evicts the pair from HEADER panels only, recreates them labelled on the first tab after Industry, keeps an admin's placement elsewhere, places nothing without a vardef, writes once |
| No script change | `post_execute.php` comments corrected (the marker panel, and whose job the header is) |

**Built on rc70, not rc71.** rc71 (G450, the Bench-only "Suspect" account type) is VOID (🔒 1775b: the customer does not need it; Customer Type is left alone) and was never shipped; rc72 does NOT carry `_override_en_us.bd_account_type_suspect.php`, and rc71's number is never reused.

**Close path on Ophir: rc72 + the one-off, either order** (pinned both ways in
`scripts/tests/bd_customer_group_move_test.php` L2/L5). **Known limit:** on a view
with `panel_body` and no `panel_overview` (stock 26.1.0 GA), a first-ever placement
by the marker falls back to the ERP tab (ERP-Core's order: named → ERP panel →
`panel_body`); pinned in `bd_erp_layout_test.php` T3. The one-off itself uses the
first tab on either shape. benchdogs-dev / sandbox layouts were NOT read (not
signed in).

**Tests:** NEW `bd_customer_group_move_test.php` (29 checks: rc69's REAL writer
reproduces the header placement on the Ophir-shaped view; the one-off's unit cases;
the tenant's life with rc72's real scripts and ERP-Core's real `sync()`; the vardef).
`bd_erp_layout_test.php` T3/T5a re-pinned for the GA-shape fallback and its rc69
stand-in replaced by the real rc69 file (`fixtures/rc69/`); `bd_adm_rules_test.php`
M6 re-pinned. Mutants 17/17 killed.

# 0.9.42-rc70 — G380 / G381 slim (🔒 1724b): ADM config + ADM rules only

Owner ruling 🔒 1724b: *Bench keeps ONLY ADM config + ADM rules; the generic
parts move out.* This build is the Sugar half of that split.

**`bd_reference` (and the other fields of the unreleased G380/G381 branch) was
never installed on any tenant.** rc69 - the build on every tenant - was cut from
`main` (`9496b7e`, 2026-09-23 03:18Z) and carries no Quotes field at all; the
G380/G381 branch began 19 hours later (`a74bf19`, 22:04Z), was never merged,
never took a version of its own (it kept rc69's, which Module Loader declines
as an upgrade), and the decision register records no install of it. That
matters because ERP-Core's `ErpLayoutExtraFields::sync()` only ever retires a
field it has seen MARKED: an unmarked `bd_reference` on a Quotes panel would
never be taken off by it (pinned in `bd_erp_layout_test.php` T4e). Evidence is
documentary; a read-only check of a tenant is one metadata read (Quotes fields,
look for `bd_reference`).

**Verified against the LANDED ERP-Epicor code** (Sugar target `a0f6b632`, lane D),
not stand-ins - pinned under `scripts/tests/fixtures/shared-sugar/`:

- `ErpQuoteFacts` (real) answers the company and each line's group for the
  defaults hook. Its `companyCode` has NO `erp_sync_key`-prefix fallback, so an
  ADM-keyed account with no company relate is not ADM - the same answer the
  payload gives (`bd_adm_rules_test.php` A8). `partNumber` (untrimmed) is not
  used here at all.
- `ErpLayoutExtraFields::sync()` (real) runs through rc70's real
  `post_execute.php` / `bd_pre_uninstall.php` / `post_uninstall.php`
  (`bd_erp_layout_test.php`): upgrade from rc69 (the two Account fields rc69
  placed unmarked stay put and become RECORDED, so an uninstall can retire
  them), reinstall, ERP-Epicor reinstall, and uninstall both fresh and over a
  restored rc69 backup.
- Uninstall ORDER (lane D rule 3): `post_uninstall.php` rebuilds the extensions
  and only then calls `sync()`. Sugar's own `ModuleInstaller::uninstall()` also
  runs `uninstall_extensions()` (which rebuilds) before `post_uninstall`
  (SugarEnt 26.1.0), and the test's extension compiler is deliberately
  stricter - it changes only on rebuild - so removing the rebuild from
  `post_uninstall.php` fails the test (mutant S19).
- ERP-Core ships INSIDE the ERP-Epicor package (`buildPackages.sh` merges it),
  so the ERP-Epicor floor also covers `ErpLayoutExtraFields` and `erp_reference`.

**Requires (the manifest refuses otherwise):** ERP-Epicor **≥ 1.1.125** (G380
(d)–(g): `Quotes.erp_reference`, `ERP_Companies.erp_order_requires_part_number`,
ERP-Core's `ErpLayoutExtraFields`, `ErpQuoteFacts`) and Partial Fulfillment
**≥ 1.0.50**.

| Change | Why |
|---|---|
| **Removed** `bd_reference` (and its label) | Reference is ERP-Epicor's generic `erp_reference`; this package only DEFAULTS it (ship-to "CITY ST") on an ADM quote |
| **Removed** `BdAccountsLayoutExtensions.php`, and the unreleased `BdAdmQuoteFieldsLayout.php` | no layout code: the fields carry ERP-Epicor's `erp_layout` marker and ERP-Core's `ErpLayoutExtraFields::sync()` places / retires them. The Account fields stay on `panel_body`, where rc69 put them. An upgraded tenant KEEPS the old `BdAccountsLayoutExtensions.php` (Module Loader never deletes a file a later build stops shipping, §CW / G37) - inert, nothing requires it |
| **Removed** the unreleased `ErpQuoteHooks/ResolveOrderableLines.php` + `OrderSelectedLinesPolicy.php` | the part-number refusal is ERP-Epicor's per-company switch |
| **Removed** `bd_adm_companies_list` | "ADM" from one source: a quote is ADM when its company has published `BdLeadSources` rows (core, from the ADM connection's `lookup_code_lists`) |
| `BdAdmRules` asks ERP-Epicor's `ErpQuoteFacts` for the company and each line's group | its private copies had already drifted from what ERP-Epicor sends (footprint SB8) |
| The before_save hook exits cheapest-first | it runs on every Quote save; a quote it cannot touch loads no record |
| post_execute: `repair_rebuild` → `accounts_erp_layout` → `quotes_erp_layout`; post_uninstall retires the marked fields | same G294 step report |
| pack.php no longer has the scripts copy loop | it copied nothing (footprint S7) |
| Repo: `ONEOFF-DropBdQuoteMirrorTables` moved to `archive/`; empty `sugar-predict/` placeholder removed | a data-deleting one-off does not sit beside shippable packages (S11); S14 |

Kept, deliberately: the empty `BdBenchDogsActionsApi.php` stub (S3) until every
tenant has taken rc69+.

# 0.9.42-rc66 — G280 / 🔒 1508: only the customer-category code is left

Owner, 2026-09-22 ~17:5xZ, verbatim: *"from all the non vustomer category code we
should not ahve other stuff there..."*. Five items were named. **Four are
removed here. The fifth is REFUSED, with the evidence, below.**

**Install order is unchanged and still matters: ERP-Epicor → Partial Fulfillment
≥ 1.0.40 → Bench Dogs LAST.**

🛑 **AND ONE NEW ORDERING RULE: INSTALL rc65 BEFORE rc66.** Module Loader never
deletes a file a later build stops shipping (§CW / G37) and `unlink()` is denied
to package code (MLP002), so a build can only retire a file by SHIPPING OVER IT.
rc65 is the build that overwrote `OpportunityReleaseStagePolicy.php` with a
provider returning `null`. A tenant that jumps rc64-or-earlier → rc66 never
takes that overwrite and **keeps the OLD deciding provider on disk for good** —
it would go on stamping `Partial Production Ordered` from Bench code, including
on a FINAL release. Tenant 1 took rc65 at **17:17:35Z**, so this is a rule for
any other instance, not a problem here. Stock tenants carry no Bench Dogs by
design (`RELEASE-CONTROL.md:757`).

| Item | Why it may go | Evidence it is safe |
|---|---|---|
| `BdQliColumnsLayout` + `BdQliColumnTemplate` | the grid is core's | **the ordering step was already dead on the tenant** — see below |
| `BdAutoSelectedReport` | a one-shot removal that has run | rc65's install log: it deleted nothing, because there was nothing left |
| the `Prototype Closed → Prototype Ordered` migration | a one-shot that has drained | rc65's install log: **`applied to 0 row(s)`**, both renames |
| `OpportunityReleaseStagePolicy.php` (rc65's null stub) | PF decides it | PF's own resolver, run both ways, reaches the SAME decision |
| `OpportunityContribution.php` | — | 🛑 **NOT REMOVED — [[G282]] is open. See "What rc66 refused".** |

### 1. The quoted-line grid — the ordering had already stopped working

rc65's own install on Bench logged, at 17:16:59Z:

```
BenchDogs-Ext: QLI columns failed: Call to private method
               BaseErpLayout::loadView() from scope BdQliColumnsLayout
```

`loadView()` is `private` (ERP-Core `BaseErpLayout.php:1778`), so
`applyColumnOrder()` could not run on that tenant and could not run on a fresh
one either. **The grid a seller sees is therefore unchanged by this deletion**:
whatever is already deployed, plus core's own appends. Two further effects, both
stated rather than assumed:

* **`erp_quote_line_num` stays in the deployed `product_bundle_items` allowlist**
  on tenants that have it. It is CORE's field and **no viewdef in this package,
  ERP-Core, ERP-Epicor or Partial Fulfillment draws it**, so nothing rendered it
  and nothing loses it. The `uninstall()` that used to take it back out goes
  with the class — this package should not be editing a core field's fetch list
  on the way out either.
* **The `bd_to_order` / `bd_ordered` legacy column sweep goes.** It wrote to
  DEPLOYED METADATA, which persists, and it has run on every install since
  0.9.21 — through rc65 on the only instance carrying this package. Spent where
  it was armed; never armed anywhere else.

**No SHIPPED viewdef is touched.** Decision 803 moved the authored column list
to a path Sugar does not read as a viewdef, precisely so this package could
never overwrite another package's columns again — so the add-if-absent upgrade
trap does not apply to any of this, and no tenant needs an uninstall/reinstall
to pick rc66 up.

⚠️ **`bd-tools/repair-ui` had a BARE `require_once` on the deleted class** (no
`file_exists`), inside a try/catch that could never have caught it: a missing
`require` is a compile error. Left alone, rc66 would have turned that admin route
into a fatal on any tenant without the file. The step is removed.

### 2. `BdAutoSelectedReport` — a removal that has already run

The class did not build decision 72's review report any more; since G116 it only
took it back off the instance, on install and on uninstall. Deleting a REMOVAL
needs proof it is spent, and the proof is the tenant's own
`package_install.log` for the rc65 install (PID 2069085, the window that carries
post_install's `running` 17:16:59 and `finished` 17:17:35): it contains
**neither** `removed retired saved report "..."` **nor** `missing, retired review
report left behind`, while every other `BenchDogs-Ext:` line of that install is
present. So `remove()` executed and found no row — and `SugarQuery` excludes
soft-deleted rows, so a row it once deleted can never be re-found.

The guard that mattered to a seller is KEPT and WIDENED:
`test_governing_marker.py` now fails if **any** file in the package builds a
saved report again.

### 3. The stage-rename migration — drained

`UPDATE opportunities SET sales_stage …` for the two decision-314 renames. The
same install log, 17:17:35Z:

```
BenchDogs-Ext: stage migration Prototype Closed -> Prototype Ordered applied to 0 row(s)
BenchDogs-Ext: stage migration Partial Production Closed -> Partial Production Ordered applied to 0 row(s)
```

Zero on both, and nothing writes the old literals any more: rc65 took the retired
pair out of the served vocabulary ([[G268]]), and the only writer left is PF,
from the config key `post_install` sets — which holds the NEW literal.

### 4. The release-stage provider stub — PF reaches the same decision

`scripts/tests/test_release_stage_absent_equals_null.py` RUNS Partial
Fulfillment's own `ErpOpportunityValuation::releaseStageDecision()` over one
quote, three ways, rather than asserting the file is gone:

| provider at PF's hardcoded path | PF's lookup status | decision for a partial release |
|---|---|---|
| rc65's null stub | `policy_provider_null` | `Partial Production Ordered` / 90 |
| **nothing** | `policy_provider_absent` | `Partial Production Ordered` / 90 |
| a provider that decides (the mutation) | — | `Prototype Ordered` / 80 — **visibly different** |

The third row is in the suite, not in a comment: without it, rows 1 and 2 being
equal could just as well mean the probe never reached PF at all.

🛑 **What must stay unreachable:** a file that EXISTS at that path and does not
define the class. PF then returns `policy_provider_invalid`, PRESERVES the stage
and never reads the config — the Opportunity stage would silently stop being
written. Absent is fine; present-and-empty is not.
`test_release_stage_policy.py` is inverted to guard exactly that shape.

`scripts/post_install.php` still writes
`erp_integration.partial_order_sales_stage` when absent, and **must** — a config
row is tenant data and does not arrive with a package. The FINAL-release delta
rc65 recorded (PF's generic path fires only while lines remain open) is
unchanged by rc66.

### What rc66 REFUSED to remove, and why

**`custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php` STAYS.**
🔒 1507's own wording makes the removal conditional — *"deleted if core's default
produces the same number, otherwise moved to core first"* — and **it does not**.
Partial Fulfillment ships a class of the SAME NAME at the SAME INSTALLED PATH,
and the two compute different figures:

| case | Bench's class | PF's class |
|---|---|---|
| quote with ERP-stated charges | `$quote->total` (Sugar's native tax + shipping) | `rollup.ordered + rollup.open + ERP-stated charges` |
| every ERP line still an unselected ladder rung | **throws** → ERP-Core PRESERVES the Opportunity's existing amount | returns `null` → ERP-Core writes `$quote->total`, which is **0.00 by construction** |

The second row is the live one: quote 1049 has four `alternative` lines and an
admin-set amount of 23.00. Under PF's copy that 23.00 is overwritten with 0.00 —
the fabricated zero 🔒 362 forbids. **Which of the two implementations survives
is [[G282]], which is OPEN** (derived with `validation/tools/check_gap_ledger.py`)
**and scheduled in Wave 5 by 🔒 1509.** Deleting Bench's copy decides G282 by
default, in favour of the number the owner has not chosen.

A second reason, independent of the first: because both packages ship the same
path and Bench installs LAST, dropping the file from rc66 **would not retire it
on Bench anyway** — Bench's copy stays on disk until PF is next installed, at
which point the number changes silently. The fix site is Partial Fulfillment,
which is not this lane's to edit.

`OpportunityContribution.php` is also the one file here that may **never** become
an empty stub: ERP-Core's `QuoteOpportunityAmount::contribution()` guards with
`file_exists` and then calls `new ErpQuoteOpportunityContribution()`
unconditionally, so an empty file at that path is a fatal, not a fallback.

### Leftovers on disk, stated rather than hidden

rc65's copies of all four deleted files remain on tenant 1 after rc66 and are
**inert**: nothing `require`s them any more, and none sits at an
Extension-framework path Sugar loads by convention. Same reading rc65 applied to
`BdDemoDashboards`.

### Tests

* NEW `test_release_stage_absent_equals_null.py` — 6 cases, runs PF's real
  resolver three ways (above).
* INVERTED `test_release_stage_policy.py` — the provider must NOT ship, and no
  file in the package may define the class. Mutation-checked: an empty `<?php`
  at the path turns it red.
* INVERTED `test_line_num_owner.py` — both readers of core's
  `erp_quote_line_num` are gone, and no code in the package may name it again
  (comments excluded, so a retirement note can still say what it retired).
* WIDENED `test_governing_marker.py` — no file may build a saved report; the
  spent remover must not ship or be called.
* REPLACED in `test_g280_package_cleanup.py` — rc65's "the legacy sweep still
  names its columns" becomes "the grid logic that backed this stub is gone".
* DELETED with its subject: `test_qli_column_merge.py`.

Suite: **209 passed / 1 skipped**; sibling-free (what CI runs) **208 passed /
2 skipped**, 210 collected against the workflow's floor of 160 and skip ceiling
of 3 — unchanged from rc65, so no CI floor was moved to accommodate this.

---

# 0.9.42-rc65 — G280 / 🔒 1507: the package stops shipping what core owns

**Install order is unchanged and still matters: ERP-Epicor → Partial Fulfillment
→ Bench Dogs LAST.** New in rc65: **Partial Fulfillment must be ≥ 1.0.40** — the
manifest now requires it, because this package no longer declares the stage
vocabulary at all.

| Item | What leaves Bench Dogs | Shape |
|---|---|---|
| REST `bd-create-opp-quote`, `bd-send-to-estimating` | core owns both (🔒 1044/G15, 🔒 531); no live caller anywhere | routes unregistered, **file still ships** |
| their three `bd-*` field controllers | already `({})`, no viewdef names the types | deleted |
| `BdAccountCountryGuard`, `BdContactSyncHook` | hook fragments are emptied stubs, so nothing loads them | deleted (fragments still ship) |
| `en_us.bd_line_order.php` labels | fields are stubs; the grid sweep removes the columns | **emptied** |
| `en_us.bd_erp_stage_list.php`, `RevenueLineItems/.../bd_deliverable_key.php` | retired earlier by being DROPPED, which retires nothing | **stubs added** |
| `OpportunityReleaseStagePolicy` | PF decides the stage from config | **stub returning `null`** |
| `BdDemoDashboards` (705 lines) | a demo layout made of other packages' dashlets | deleted |
| the stage vocabulary + styles | PF 1.0.40 ships them in `_override_` fragments (G278 / 🔒 1506) | **emptied**, plus a one-shot removal |
| `OpportunityContribution` | untouched on purpose — G282 | — |

### The rule that decides delete vs. empty stub

Module Loader copies a package's files and **never deletes** the previous
version's (§CW / G37), and `unlink()` is denied to package code (MLP002). So a
file the platform loads BY PATH — a `custom/Extension` fragment, a vardef, a
language or style file — must keep shipping, emptied. A file reachable only
through a `require_once` this package also ships can be deleted once the caller
is gone. Two files retired earlier by simply dropping them had therefore never
been retired at all, and ship as stubs here for the first time.

### Two traps this release had to read the source to avoid

1. **The release-stage provider could not be deleted, and must not be empty.**
   PF finds it by a hardcoded path (`ErpOpportunityValuation.php:288`), so
   deleting it leaves the OLD provider running on every tenant that has it. And
   a file that exists but defines no class is `policy_provider_invalid`
   (`:297-301`) — PF then PRESERVES the stage and never reads the config, so the
   stage would silently stop being written. A provider that exists and returns
   `null` is the only shape that hands over. `post_install` writes
   `erp_integration.partial_order_sales_stage` FIRST, and only when absent,
   because config is tenant data and does not arrive with the package.
2. **The stage keys needed removing, not just not-declaring.** Until rc64
   `post_install` APPENDED them to `en_us.zz_bd_stage_doms.php` through
   `install_languages()`, which concatenates rather than overwrites, so every
   past version's keys are still in that file — including decision 314's retired
   `…Closed` pair (G268). rc65 calls `uninstall_languages()` once, the exact
   mirror of the install that built it.

### Behaviour change, recorded rather than hidden

PF's generic stage path fires only while lines remain open. The retired Bench
provider also answered on a FINAL release, stamping `Partial Production Ordered`
on a quote with nothing left to order. That stage is core's decision now.

### Post-install reads

- `sales_stage_dom`: `Prototype Ordered` and `Partial Production Ordered` still
  served (from PF), no `…Closed` pair, and `quote_stage_dom` still has
  `Partially Fulfilled`. sugarcrm.log: `release stages served by core after this
  install: yes` and `removed the zz_bd_stage_doms language fragment`.
- `erp_integration.partial_order_sales_stage` = `Partial Production Ordered`
  (Admin → config), and a partial order still moves the Opportunity to that
  stage at 90.
- `GET <tenant>/rest/v11/metadata?type_filter=...`: no `bd-create-opp-quote` or
  `bd-send-to-estimating` route; `bd-tools/repair-ui` still answers for an admin.
- The Quotes record view still shows both order buttons (rc64's G276), and the
  quoted-line-items grid still has no per-line ERP row actions.

# 0.9.42-rc64 — G243 + G234 + G268 + G276: no Opportunity from the sync, a clean uninstall, no retired stage names, no button logic

**Base.** Built on `c4e8874` (rc60 = rc61 content, what Bench and et run), NOT on
main. Four items, each its own commit; the version moves only in the last one.
Manifest `dependencies` are unchanged from rc61 (ERP-Epicor `1.1.24-rc9`, PF `1.0.13`
minimums).

| Item | Commit(s) | What changes on a tenant |
|---|---|---|
| **G243** (🔒 1499) | `fde1b53` | rc39's `BdKineticOpportunityHook` registration and class are OVERWRITTEN with a registration that registers nothing and a tombstone class. A connector-created quote no longer gets an Opportunity. Existing ones are not deleted (🔒 1502(b)). Section below. |
| **G234** | `68eab4a`, `7af763f` | `pre_uninstall` removes the stage keys `install_languages` installed (the `en_us.zz_bd_stage_doms.php` file) unless records still hold them. |
| **G268** | `b9bcd4d` | The two RETIRED stage names `Prototype Closed` / `Partial Production Closed` (decision 314) stop being served. |
| **G276** (🔒 1503, 🔒 1504) | `326a676`, `74e09e6` | Bench Dogs no longer adds, removes, reorders or stashes ANY record-view button, on any module; it also stops re-adding the per-line ERP row actions, and its orphan button labels are emptied. |
| integration | `ee5c254` | Test-only: G243's "no other Opportunity creator" check vs G234's read-only SugarQuery count. |

### G268 — where the retired names came from, and how they go

From 0.7.3 to rc37 `custom/dropdowntemplates/bd_stage_doms.append.php` declared the
`…Closed` pair, and `post_install` hands that template to
`ModuleInstaller::install_languages()` with id_name `zz_bd_stage_doms`. When
`custom/Extension/application/Ext/Language/en_us.zz_bd_stage_doms.php` already exists,
Sugar APPENDS the template to it rather than overwriting it (26.1.0
`ModuleInstaller.php:1227-1235`). Every pre-rename Bench install therefore left its
`…Closed` lines in that file; et's file was born after the rename. The static
`en_us.bd_stage_doms.php` is not the leftover: it has been overwritten by every
install since rc38.

rc64's template ENDS with an `unset()` of the four retired entries
(`sales_stage_dom` ×2, `sales_probability_dom` ×2). It is appended after every
historical assignment in that file, so it wins. No file is removed, and nothing is
shipped at the zz path: a stub there would make the file identical on every install,
freeze its merge position (recorded mtime, refreshed only on an md5 change) and
break G220/G273's "reinstall Bench Dogs after ERP-Epicor" lever. The `…Ordered` pair
and `quote_stage_dom['Partially Fulfilled']` are untouched. `post_install` now also
logs `BenchDogs-Ext: retired stage names still served` if any survives.

### G276 — the buttons come back through CORE

Removed: `BdQuotesLayoutExtensions::writeButtons()`, its stash (`benchdogs` /
`removed_quote_buttons`) and the uninstall replay, `BdAccountsLayoutExtensions::
writeButtons()`, both `post_install` calls, the `clearStash()` call in
`pre_uninstall`, and both button steps of the `bd-tools/repair-ui` route.

Also removed under the same ruling: `BdQliColumnTemplate.php` no longer names the
`erp_line_links` fieldset, so the per-line **Open Line in ERP / Engineering** row
actions — which 🔒 687 ordered gone and ERP-Core removes on every install
(`ProductsLayout::install()` → `removeFieldsFromDataGroupListView()`, 🔒 709) — are
no longer put back by every Bench Dogs install. And both `en_us.bd_action_buttons.php`
label files (10 Quotes + 2 Accounts labels, all orphans) are emptied to stubs, so a
retired action stops reading as supported in Studio and the report builder.

rc64 does not put Submit Order back by itself, and must not (🔒 1504). **ERP-Epicor
does**: every ERP-Epicor install runs `QuotesLayout::install()` →
`addButtonsToRecordView()`, which adds a missing button and reconciles an existing
one to core's current definition; PF then re-anchors Order Selected Lines next to it
(`QuotesOrderSelectedLayout.php`). So on Bench and et:

1. **ERP-Epicor** (a version above the installed one — a same-version re-upload is
   refused), then
2. **Partial Fulfillment**, then
3. **Bench Dogs rc64 LAST** (the G273 order, for the sales stages).

A fresh tab then shows `refresh_price_availability_button`,
`erp_order_selected_button`, `create_erp_order_button`, `advanced_quote_button`.
Installing rc64 alone restores nothing — it only stops the stripping. After rc64
nothing strips the buttons again, so the order stops mattering for them. The stash
row stays in config as orphaned tenant data; nothing reads it.

### Post-install reads

- `sales_stage_dom` (fresh tab): Prospecting, Proposal/Price Quote, Closed Won,
  Closed Lost, Prototype Ordered, Partial Production Ordered — six, no `…Closed`;
  `sales_probability_dom` has no `…Closed` key. sugarcrm.log has neither
  `required stage language verification failed` nor `retired stage names still served`.
- `custom/modules/Quotes/Ext/LogicHooks/logichooks.ext.php` has no
  `BdKineticOpportunityHook` / `pairOnSave` / `pairOnAccountLink` entry, and a
  connector-created quote gets no Opportunity.
- An accepted, un-ordered quote renders BOTH Submit Order and Order Selected Lines,
  as stock quote `df86be2c` does.
- The quoted-line-items grid has no per-line **Open Line in ERP** / **Engineering**
  icons (they are core's to remove; rc64 only stops re-adding them).

## 0.9.42-rc64 / G243 — the quote → Opportunity PAIRING WRITER is retired (🔒 1499)

Shipped in 0.9.42-rc64 (fde1b53, cherry-picked from 37d4485 onto c4e8874).

🛑 **A VERSION BUMP IS NOT OPTIONAL FOR THIS ONE, IT IS THE DELIVERY.** The
defect is a file that outlived the package that shipped it, so the fix only
reaches a tenant when Module Loader copies the replacements over it — and
Module Loader refuses the same or a lower version under one `id_name`
(`RELEASE-CONTROL.md` §11). Bench carries **0.9.42-rc60**, so the number that
delivers this is **above rc60**; anything at or below it installs nowhere and
the sync keeps creating Opportunities with no diff to point at.

**What was wrong.** `BdKineticOpportunityHook` — `BdQuoteReflectionHook::
ensureOpportunity()` relocated onto the native Quote by `fe18b38` — created and
linked a brand-new Opportunity for every Kinetic-born quote above a history
floor. It shipped in **0.9.42-rc39** (what Bench had installed; the rc40 install
died at 13/19 and rolled back) from a build branch that was never merged to
main. 🔒 1473 said the sync never touches Opportunities; 🔒 1499 ruled on the
measurement in the owner's own words: *"YES — the sync must never create one"*.
An Opportunity is forecastable, so one per synced ERP quote inflates the
pipeline with deals no seller made and no seller owns.

**Why "we stopped shipping it" was not the fix, and is the whole lesson here.**
rc60 already ships no `custom/Extension/modules/Quotes/Ext/LogicHooks/*` at all,
and the pairing kept running on Bench regardless: **Module Loader copies a
package's files and never deletes the previous version's.** rc39's registration
and class sat on disk and stayed compiled into
`custom/modules/Quotes/Ext/LogicHooks/logichooks.ext.php`. This is rc24's
lesson, already written down in `test_create_opp_quote_button_retired.py` —
**ONLY OVERWRITING RETIRES** — and deleting a file is not available to us
anyway: `ModuleScanner` blacklists `unlink`/`rmdir` and `SugarAutoLoader::
unlink`, so a package that sweeps does not install.

**So two files now ship, both empty of behaviour:**

- `custom/Extension/modules/Quotes/Ext/LogicHooks/bd_kinetic_opportunity.php`
  registers **nothing**. `install_copy` overwrites rc39's copy and
  `install_extensions` — which runs *before* `post_execute` — rebuilds the
  compiled hook file without `pairOnSave`/`pairOnAccountLink`.
- `custom/modules/Quotes/BdKineticOpportunityHook.php` is a **tombstone**: same
  class, same three public entry points, none of which reach the bean layer.
  This is the half that holds when the first half does not run, which is
  precisely the failure being fixed.

**What did NOT change, deliberately.** The seller's "Create Opportunity &
Quote" button is ERP-Epicor's (`AccountsErpActionsApi::createOppQuote`; Bench's
duplicate was retired by 🔒 1044 / G15). It never routed through this hook, it
is in another package, and it still creates and saves its Opportunity —
asserted as a control in the new suite.

⚠️ **The Opportunities this hook already created on Bench are untouched** by
this change and by everything in this package. Leaving them keeps the forecast
inflated; deleting them strands their synced quotes. 🔒 1499 explicitly does not
cover it, and it is tenant data, not code.

⚠️ **rc39 left FIVE more Quotes logic-hook registrations on any tenant that had
it** — `bd_primary_quote`, `bd_sync_key_release`, `bd_estimating_turnaround`,
`bd_quote_kpi`, `bd_quote_carrier_canonical` — each still pointing at an rc39
class that rc60 no longer ships. Only the Opportunity creator is retired here,
because it is the one 🔒 1499 ruled on. The other five are the same shape and
are still live on Bench; they are named here so the sweep is a decision someone
takes, not something nobody noticed.

New: `scripts/tests/test_g243_kinetic_opportunity_pairing_retired.py` (9 cases,
8 mutations killed) — it *includes* the registration and *calls* the tombstone
rather than reading either, because this file is mostly prose and a text search
could be fooled in both directions.

# Unreleased — the bd01 quote mirror is RETIRED (decisions 901/903/904/905)

No version bump and no artifact yet: this entry records the package change so
the sections below are read as history.

`bd01_ERP_Quote`, `bd01_ERP_Quote_Line` and `bd01_ERP_Quote_Cost` are gone —
module trees, `custom/Extension` and `custom/modules` subtrees, all four
relationship definitions, the three subpanel declarations on the live Quotes
and Accounts layouts, the `beans` installdef, the `moduleList` entries and the
`relationships`/`vardefs`/`layoutdefs` build plumbing in `pack.php`. The
package now installs no bean, no table and no module tab.

What replaced them:

- `ErpQuoteOpportunityContribution` and `ErpOpportunityReleaseStagePolicy` read
  the **native Sugar quote lines** (`erp_total_role` for what counts toward the
  money, `erp_governing` for the one winning production option) instead of
  walking `$quote -> mirror quote -> mirror lines`. Rewriting them was a
  prerequisite, not a follow-up: their first guard was a `load_relationship`
  that every quote satisfies once the module is gone, so shipping the deletion
  without the rewrite would have silently stopped valuing the pipeline.

What was removed with no replacement in this package, deliberately:

- **`POST Quotes/:record/bd-sync-quote-tiers`** (`BdBenchDogsActionsApi::
  syncQuoteTiers`) and its `pickErpQuote()` helper. It backfilled a Sugar line
  item for every Kinetic quantity break that had none and reconciled the
  `erp_ordered` flags, entirely by reading the mirror and calling
  `BdQuoteReflectionHook::backfillMissingQuoteLines` /
  `refreshOpportunityAmount`. With the mirror gone it could only return a false
  "not linked to a Kinetic quote" error or fatal on a `require_once` of a
  deleted file, so the endpoint is removed rather than stubbed. No UI in this
  package or its siblings called it.
- **The REQ-13 turnaround stamp.** `stampSentToEstimating()` and
  `findErpQuotesByScopedKey()` created or found the mirror row for the scoped
  Kinetic key and wrote `bd_sent_to_estimating_at` on it. That field lived on
  the mirror, so nothing writes it now. `bd-send-to-estimating` no longer
  returns `estimating_timestamp_status`, and `bd-send-estimating.js` no longer
  shows "Turnaround timing is pending exact ERP mirror verification".
- **`BdDemoDashboards::uninstall()`**, `removeOne()` and `doomedModules()`,
  plus the call to them in `pre_uninstall.php`. Their only job was stripping
  tiles that listed a module this uninstall deletes, and this package now
  deletes no module. An instance that ran `0.9.42-rc44` or earlier keeps three
  Home tiles ("ERP Quotes", "ERP Quote Lines", "ERP Quote Costs") pointing at
  the retired modules; they must now be deleted from the dashboard by hand.
- **`scripts/bd_governing_backfill.php`**, a one-off backfill for the retired
  mirror, deleted. It was wired to no installdef, hook or schedule.
- The three Home dashlets that listed the mirror modules. The Account "ERP
  Quote Pipeline" tile stays — it lists native Quotes.

Also gone with the mirror, and worth knowing before reading older entries:
`BdQuoteReflectionHook` was the ERP-sync writer of `bd_erp_stage` and
`bd_erp_total`. The only write left anywhere in the package is
`bd-send-to-estimating` setting `bd_erp_stage = in_estimating`;
**nothing writes `bd_erp_total` at all any more**, so the `bd_erp_total` column
on the Account "ERP Quote Pipeline" tile shows only values a previous install
already stored. `BdEstimatingNotificationHook` reads both fields and writes
neither. This ended with the mirror deletion, not with the reference cleanup.

# 0.9.42-rc59 — G50: the `bd_country` label's ORIGINAL path is retired too

rc57 emptied `_override_en_us.bd_country_lookup.php`, yet Bench under rc58
still served `erp_lookup_type_list['bd_country'] = 'Country (Bench Dogs)'`.
The source is the label's pre-rc23 path, `en_us.bd_country_lookup.php`.
rc23 renamed it away, and a renamed file is left on the tenant (§CW / G37).
It stayed hidden while ERP-Epicor assigned the list as a whole array. ERP-Core
now adds its types key by key, so nothing wipes the key and the label came back.

How it was found: `bd_country` is the FIRST key live, ahead of ERP-Core's `''`.
The `_override_` file always merges last, so the key comes from an
earlier-sorting fragment.

This release ships an emptied stub at that original path. A new test pins BOTH
paths as shipped, empty stubs (mutations: stub dropped → 1 failure, label
restored → 2 failures).

The 12 `bd_country` ERP_LookupValues rows are data, not package files; this
release does not touch them. They are read by nothing: core's guard reads only
`erp_country_lookup_type_list`, which registers `Country` alone, and `Country`
holds the same 12 countries and 36 spellings.

# 0.9.42-rc58 — G97/G123: the Opportunity headline amount is the primary quote's total

**Decision 708, owner verbatim 2026-09-20:** *"now opprtuntiy rollup is simple
no need for cgoveringing line its what ever is in the quote right???/ no more
selected wired logic...."* and *"add taht as a gap to fix on how to simply
calaualted oppetunity roll up now form the primairy quote."*

`ErpQuoteOpportunityContribution::resolve()` now returns the primary quote's
own stored `total`. It no longer counts `erp_governing` lines, no longer sums
`subtotal` over the `counts` lines, and no longer adds `$quote->tax` /
`$quote->shipping` on top.

## The defect this closes: −$1,848.00 on one Opportunity (G97)

Measured live on Ophir 2026-09-19, Opportunity `3e9ef3f8` / quote 1250:

    2026-09-19 21:16:48  erp_open_amount  237.30 -> 2,085.30   (PF rollup)  ✅
    2026-09-19 21:16:48  amount           — NO ROW —           (this file)  ❌

Quote 1250 has TWO ladder groups, `EPIC06__1250_1_1` and `EPIC06__1250_2_1`,
each with its own legitimately governing rung: 2,016.00 + 69.30 = 2,085.30,
exactly the quote total. The old per-QUOTE rule was "exactly one governing
production option is required"; 2 > 1 threw, `QuoteOpportunityAmount::refresh()`
caught and logged, and `amount` stayed frozen at 237.30.

**A quote with N price-break parts legitimately has N governing lines.** The
quote total was correct throughout, so 708 deletes the check rather than
re-scoping it to the ladder group — §DG, make the illegal state unreachable
rather than detectable.

## Why "the quote total" does not re-admit the unselected rungs

`ERP-Epicor-QuantityAlternatives` sets, in
`custom/Extension/modules/Products/Ext/Vardefs/erp_total_role.php:110`:

    $dictionary['Product']['fields']['subtotal']['formula'] =
        'ifElse(equal($erp_total_role, "alternative"), 0, …)'

calculated and enforced, and `Quotes.total` is an equally enforced
`currencyAdd(rollupCurrencySum($product_bundles,"new_sub"), $tax, $shipping)`.
An unselected rung therefore contributes zero to the quote total by the
platform's own arithmetic. Verified in the vardef, not assumed.

Taking the stored total is also strictly more correct than the sum it replaces:
`ProductBundles.new_sub` is `subtotal - deal_tot`, so the old per-line sum
over-stated every quote carrying a bundle discount.

## The one refusal that survives — quote 1049

A quote whose every ERP line is still `alternative` totals **0.00 by
construction**. Quote 1049 is the live control: 4 lines, all alternative, and
an admin typed `amount = 23.00` on 09-18. A naive "amount = total" overwrites
that with 0.00, so the provider **throws** ("this quote is not yet priced")
rather than returning null — throwing is what makes ERP-Core catch, log and
write nothing, preserving the 23.00. Returning null would fall through to
`(float) $quote->total` and destroy it.

A line that *counts* at 0.00 is a measurement (a free-of-charge item) and still
publishes 0.00. Only an all-`alternative` quote is "not yet priced".

## Tests

`scripts/tests/test_governing_contribution.py` rebuilt onto the new rule: 11
scenarios, including the 1250 two-ladder-group shape, the 1049 all-alternative
refusal, a genuinely zero-priced quote, a bundle-discounted quote, and a
source-level anti-resurrection pin that fails if `erp_governing` or a
`->subtotal` sum returns to the file.

Mutation-checked, three ways:

| mutation | result |
|---|---|
| reinstate `if ($governingCount > 1) throw` | 2 red (the G97 scenario throws again; the source pin sees `erp_governing`) |
| delete the not-yet-priced refusal | 1 red (1049 publishes 0.00 over the admin's 23.00) |
| restore the per-line `subtotal` + tax + shipping sum | 7 red |

Suite: 185 passed, 1 skipped (was 182 passed, 1 skipped at rc57).

# 0.9.42-rc57 — G116: the retired `bd_governing_origin` stops re-arming itself, and the `bd_country` label is retired

## G116 — a 🔒 1044 retirement that re-armed on EVERY install

🔒 1044 retired `Opportunities.bd_governing_origin`: both
`custom/Extension/modules/Opportunities/Ext/Vardefs/bd_governing_origin.php`
and `.../Ext/Language/en_us.bd_governing_origin.php` were overwritten with
stubs that declare nothing. Two surfaces went on PLACING the field anyway, once
per install:

| surface | what a tenant saw |
| --- | --- |
| `scripts/post_install.php:191` → `BdOpportunitiesLayoutExtensions::writeGoverningOriginField()` | a row on the Opportunity record view headed with the raw key `LBL_BD_GOVERNING_ORIGIN`, reading "No data" |
| `scripts/post_install.php:210` → `(new BdAutoSelectedReport())->install()` | a saved report filtering on a column with no vardef — it cannot error, it renders EMPTY, and on a review queue that reads as "nothing to review" |

This is the same shape as the G96/G99 raw-label defect that rc55/rc56 closed on
Quotes. It survived that closure because the "bd_* fully retired" census read
**Quotes** module metadata only, and this placement is on **Opportunities**.

**Removing the calls would not have been enough.** Deployed metadata is covered
by no installdef and a saved report is a row, so every tenant that installed
rc26..rc56 would have kept both for good. The retirement has to RUN:

- `BdOpportunitiesLayoutExtensions` no longer has `writeGoverningOriginField()`
  or its `indexOf()` helper. `post_install.php` calls `remove()` — which sweeps
  EVERY panel, so a field an admin moved is cleaned up too, and writes nothing
  when there is nothing to remove.
- `BdAutoSelectedReport` no longer has `reportDef()` or `install()`. Its single
  method is `remove()`, called from `post_install.php` as well as
  `pre_uninstall.php`. It matches the exact report name and nothing else, so an
  admin who renamed or copied the report keeps theirs, and `mark_deleted()` is
  a soft delete.
- Both 1044 stubs keep shipping, unchanged: §CW / G37 — dropping a copied
  `custom/Extension` file from the build leaves the installed copy in place and
  the retirement inert.

**On a tenant.** After installing rc57, the Opportunity record view loses the
`LBL_BD_GOVERNING_ORIGIN` row and the saved report "Opportunities Valued From
an Auto-Selected Quote Line" is soft-deleted. The `bd_governing_origin` COLUMN
stays in the database, unread; with no writer left every row is null.

## G50 — the `bd_country` type label is retired, in the right order

`custom/Extension/application/Ext/Language/_override_en_us.bd_country_lookup.php`
still shipped
`$app_list_strings['erp_lookup_type_list']['bd_country'] = 'Country (Bench Dogs)'`,
and `scripts/tests/test_account_country_guard.py:133` pinned it — so G50's
premise ("the code is gone, soft-delete the stale rows") was false and the rows
could not go first: the next install would have republished the name over them.

Nothing in this package reads those rows. Bench's guard is registered by
nothing (`.../Accounts/Ext/LogicHooks/bd_account_country_guard.php` is itself a
retirement stub) because ERP-Core owns the billing-country check. The fragment
is now an emptied stub at the SAME PATH — the `_override_` prefix and the
`en_us` in the name are what make it overwrite the installed copy — and the two
test pins are inverted rather than deleted.

**Still to do, on the tenant, not in this package:** the 12 `ERP_LookupValues`
rows of type `bd_country`. Until they are removed they render with the raw type
value `bd_country` instead of a name. They are read by nothing either way.

## The PHP test suites now run in CI

`.github/workflows/mlp-lint.yml` runs `pytest scripts/tests`, which does not
collect `*.php` — so `scripts/tests/bench_panel_retired_test.php` (18 checks,
written after the owner found a raw `LBL_RECORDVIEW_PANEL_BENCHDOGS` on a live
quote) had never executed on a pull request. `scripts/tests/test_php_suites.py`
wraps every `*_test.php`, asserts `checks, 0 failed` as well as the exit code,
and fails if a PHP suite exists that nothing runs.

New: `scripts/tests/bench_governing_origin_retired_test.php` — 17 checks that
RUN the removal against a view in the state rc26..rc56 left it in.

# 0.9.42-rc26 — `post_install.php` actually runs (the post_execute unwrap), and it can no longer force-uninstall itself

Built from `0577bc0` (rc25 + the D29-R1 atomic governing-selection fix). One file
changes behaviour; nothing is added to or removed from the payload.

### The defect

`scripts/post_install.php` wrapped its entire body in

```php
if (function_exists('post_execute') === false) { function post_execute() { ... } }
```

and **nothing ever called it**. `manifest.php` registers the file under the
`post_execute` installdef, but `ModuleInstaller::post_execute()`
(`ModuleInstall/ModuleInstaller.php:426-440`, read in SugarEnt-Full 25.2.0 and
26.1.0) only `require_once`s each registered file — it never calls a global
function named after the installdef key. So on `0.9.41` and the whole `0.9.42`
line, every layout write, button write, QLI-column write, customer-group
placement, demo dashboard and dropdown install in that file was dead code, and
Module Loader reported a clean 19/19 either way.

`scripts/pre_uninstall.php:40-53` had already reasoned this out in full and both
uninstall scripts were converted to top-level code. This one finally follows
them. The control that proves the mechanism rather than asserting it:
ERP-Epicor's `scripts/post_execute.php` is plain top-level code and its work
lands, on the same tenant, with the same installer, in the same install cycle.

### The unwrap is fail-safe, which is not optional here

On the `post_execute` path an uncaught throw is a **failed install**, and Module
Loader's failure path **force-uninstalls the package**. The final block used to
rethrow a `RuntimeException` when the stage domains did not verify. While the
body was unreachable that was harmless; live it would mean a missing dropdown
key deletes the package and its deployed metadata. So:

- every block keeps its own `try`/`catch (Throwable)` and its own
  `file_exists`/`class_exists` guard, as `pre_uninstall.php` does;
- the stage-language verification **logs and returns** instead of rethrowing.
  The five keys it checks are also shipped declaratively by the copy'd
  `custom/Extension/application/Ext/Language/en_us.bd_stage_doms.php`, which is
  the route that has actually been working on SugarCloud all along;
- `scripts/tests/test_post_install_stage_languages.py` asserts both properties
  **statically** — no `function post_execute`, no `function_exists('post_execute')`,
  no `throw` anywhere in the file — as well as behaviourally.

### Proof of life, at fatal

A successful Module Loader run is not evidence that this file ran, and
`->error()` lines do not survive an instance whose log level is `fatal`. The
script now logs `BenchDogs-Ext: post_install running - writing deployed metadata`
on entry and `... post_install finished` on exit, and **every per-step failure
line is at `fatal` too**, so "it ran but `DeployedMetaDataImplementation` threw"
is distinguishable from "it never ran". Those two produce identical deployed
metadata and were confused for a whole release cycle.

### Every local is `bd`-prefixed

`require_once` inside `ModuleInstaller::post_execute()` executes this file in
that **method's** scope, which has already run `extract($data)` over the
manifest. An unprefixed `$manifest` / `$installdefs` / `$modules` would overwrite
the installer's own locals mid-install. `pre_uninstall.php` prefixes for the same
reason.

### Known consequence, stated before installing

`writeButtons()` **removes** `advanced_quote_button`, `create_erp_order_button`
and `refresh_price_availability_button` from the deployed Quotes record view.
All three are live on a Bench tenant today **only because this code never ran**.
Partial Fulfillment 1.0.15's proven button placement anchors on
`create_erp_order_button` with `refresh_price_availability_button` as its left
neighbour, so a successful rc26 install **invalidates that standing PASS**.

### Also stale, corrected in the same pass

The comment block that justified the relationship rebuild still described a
`quotes_erp_orders` cardinality override. The package ships no TableDictionary
file and no `zzz_` file any more (`post_install.php`'s own note at the Accounts
block says so); what remains is the generic cache/relationship rebuild.

# 0.9.42-rc24 — shipped is a quantity, not money (user decisions 55 / 59)

Not installed on QA and not approved for production. Sequenced with ERP-Epicor
`1.1.24-rc23` and with `connector_ext_benchdogs` `cb5ec60`.

rc24 is rc23 with six files deleted and nothing added to the payload.

### The Bench shipped-money duplicate is retired

ERP-Core retired `ERP_OrderLines.shipped_value` and
`ERP_Orders.shipped_value_total` in `1.1.24-rc23` (user decision 55, *"lets
change shiped as quanmitity not money"*). This package carried its own
`bd_shipped_value` / `bd_shipped_value_total` on the **same two core modules**,
so the decision landed half-delivered on Bench. User decision 59 —
*"they shoudl both be based on quanity and if core does a good job you dont need
the extension"* — settles it as a **removal**, not a rename to
`bd_shipped_quantity`.

- Deleted: the vardef, language fragment and record-view fragment for each of
  the two fields. The package now ships **nothing** for `ERP_OrderLines` or
  `ERP_Orders`; both Extension directories are gone.
- The only shipped surface on a Bench tenant is core's line-level
  **quantity**, `ERP_OrderLines.shipped_quantity`, rendered by core's
  `erp-fulfillment` field as "12 of 15" — unchanged by this release, and
  never touched by this package (the retired record-view fragments appended
  by panel name and removed nothing).
- Both retired vardefs carried `'default' => 0.0`, and the connector module
  that would have written them is gated OFF on the QA tenants
  (`SUGARAI_BD_MLP_FIELDS: "account_group"`). So since 0.9.41 the record view
  has been rendering **"Shipped Value (not invoiced): $0.00" with no writer at
  all** — a fabricated zero, indistinguishable from a measured one.
- Guarded by `scripts/tests/test_bench_shipped_is_a_quantity_not_money.py`
  (source, built zip and manifest; `bd_shipped*` and `LBL_BD_SHIPPED*`; any
  `'default' => 0` on those two modules; shadowing `shipped_quantity`).
- No `pack.php` change: every file under `custom/` is still a `copy`
  installdef, so the six `copy` entries left the manifest by themselves.

**This is not an in-place upgrade.** BD-L-0005: omitting a copied file does not
remove it on upgrade. Uninstall rc23 with data tables RETAINED, verify the six
paths are gone, then install rc24. See `docs/release-0.9.42-rc24.md`.

# 0.9.42-rc23 — `bd_country` label survives ERP upgrades; retired governing label hidden

Not installed on QA and not approved for production.

rc23 is rc22 with two display fixes.

### `bd_country` type label (REQ-15)

The Bench-owned `ERP_LookupValues`
type label `erp_lookup_type_list.bd_country` ("Country (Bench Dogs)") stopped
being served on Bench after the ERP-Epicor `1.1.24-rc16` install. It stayed
gone after the additive rc17 and the Bench Dogs rc21 upgrade. Offline diagnosis:
`validation/bd-country-label-diagnosis.md` (2026-09-13).

- Cause, in two Sugar 26.1 behaviours:
  - An upgrade without `uninstall_before_upgrade` only appends to
    ERP-Epicor's application language fragment. The fragment still holds the
    rc16-and-older whole-array `erp_lookup_type_list` assignments ahead of
    rc17's additive keys.
  - Language fragments merge by the order map's stored mtime, and that mtime
    is refreshed only when the file's md5 changes. Bench's fragment was
    byte-identical in rc19, rc21 and rc22, so it kept its old slot. ERP's
    fragment changed on each upgrade, so it merged after Bench and wiped the key.
- Fix: the fragment is renamed to
  `custom/Extension/application/Ext/Language/_override_en_us.bd_country_lookup.php`.
  Its content is unchanged and still additive. Sugar always merges `_override*`
  fragments after the others, whatever their mtime or md5. The name still
  contains `en_us`, so it stays in the en_us merge. Partial Fulfillment already
  ships `_override_en_us.partial_fulfillment_quote_stage.php` the same way.
- No `pack.php` change: every file under `custom/` is still a `copy` installdef.
- On a tenant upgraded in place, the old `en_us.bd_country_lookup.php` stays on
  disk (an upgrade never deletes an omitted file; BD-L-0005). That is harmless,
  because it assigns the same single key.
- Display only. `BdAccountCountryGuard` reads stored type values, and nothing
  else changes.

### Retired `bd_governing_line` removed from view (decision 29)

- Nothing writes `Quotes.bd_governing_line`. Under decision 29 the governing
  selection is the explicit `bd01_ERP_Quote_Line.governing` flag, which
  `ErpQuoteOpportunityContribution` reads fail-closed. (That flag was retired
  with the mirror — see decision 901 at the top of this file; the selection is
  now `erp_governing` on the native quote line.) The Quote-level label
  therefore only ever showed an empty or stale value.
- `BdQuotesLayoutExtensions::write()` now removes it from the Bench Dogs Quotes
  panel on every install. That includes upgraded tenants, where the append path
  used to return early.
  - The removal touches only that panel, so an admin's own placement elsewhere
    survives.
  - The packaged panel no longer lists it.
- The Account "ERP Quote Pipeline" dashlet no longer lists it. `BdDemoDashboards`
  rewrites its own tiles on install, so upgraded tenants follow.
- The vardef and column stay for existing data, now marked retired and hidden
  from Studio. Nothing in core, the SDK or either Sugar repo maps or reads the
  field.
- Unchanged: `BdGoverningLineHook`, `bd01_ERP_Quote_Line.governing` and the
  contribution itself. (Both the hook and the flag were retired later — see
  decision 901 at the top of this file.)

Verification still owed on Bench after install:
- The served `/lang/en_us` `app_list_strings.erp_lookup_type_list.bd_country`
  is "Country (Bench Dogs)".
- The 7 ERP keys are still present.
- The Quotes record view and the Account dashlet no longer show "ERP Governing
  Line".

Tests:
- `test_account_country_guard.py` reads the renamed fragment.
- New `test_governing_line_retired.py` covers:
  - the packaged panel, the dashlet and the retired vardef;
  - a PHP harness of `write()` for upgrade, fresh and replace installs.

## Previous candidates

## 0.9.42-rc22 — adopt Kinetic quotes whose Sugar marker names a deleted Quote

Not installed on QA and not approved for production.

rc22 is rc21 with one REQ-28 fix found live on Bench QA (2026-09-13). All 30 ERP
quotes that carried a Sugar-origin marker (`sugar_quote_id`, parsed from the
Kinetic QuoteComment) pointed at Quotes that no longer exist. Rule 1 treated
them as Sugar-born and never adopted them, so their connector-created Quotes
had no mirror link and the governing contribution answered "not applicable".

- A `sugar_quote_id` whose Quote Sugar no longer has is treated as absent. The
  ERP quote is then adopted by its Kinetic number like any Kinetic-born quote.
- A live marker still means Sugar-born and is left alone.
- A read that fails for any other reason keeps the marker, so a transient error
  never re-routes a real Sugar-born quote.
- Existing rows adopt on their next save or line link.

Tests: `test_kinetic_quote_adoption.py` now has 11 PHP-backed cases.

## 0.9.42-rc21 — Opportunity floor stored as an Administration setting

Not installed on QA and not approved for production. Supersedes rc20, which was
built and scanned but never installed.

rc21 is rc20 with one deployment fix. rc20 read the Opportunity floor only from
`$sugar_config['benchdogs_ext']['materialize_from_quote_num']`, which
SugarCloud administrators cannot set. rc21 still honours that override, but
otherwise stores the floor as the Administration setting
`benchdogs / materialize_from_quote_num`. It is seeded once, on the first
adoption, with the highest Kinetic quote number any Sugar Quote already
carries. The Bench ERP `benchdogs` pipeline now runs after core's
(`depends_on=("core",)`), so core's initial load has created every historical
Quote by then. Historical quotes are adopted and never get an Opportunity; later
quotes do. When no numbered Quote exists, or the setting cannot be read, nothing
is created. No manual deployment step is needed.

Tests: `test_kinetic_quote_adoption.py` now has 8 PHP-backed cases.

## 0.9.42-rc20 — Kinetic-born quotes adopt core's native Quote

Not installed on QA and not approved for production.

rc20 is rc19 with the REQ-28 hook brought under user decision 18 (core owns
native Quote creation) and decision 29 (every Kinetic quantity break arrives as
an unselected native line; only a person's governing selection counts).

- `BdQuoteReflectionHook` adopts the Quote core created, by Kinetic quote
  number, before anything else. Adoption creates nothing.
- It never creates a Quote any more. Until core delivers one the ERP quote
  shows `waiting_native_quote`.
- It never copies mirror lines onto an adopted Quote. Core's per-break lines
  are left alone; only legacy `materialized` quotes are still re-copied.
- An adopted quote gets its Opportunity (and `erp_is_primary_quote`) only when
  the account is matched by ERP key and the quote number is above
  `$sugar_config['benchdogs_ext']['materialize_from_quote_num']`. Without that
  setting no Opportunity is created. **Deployment step:** set it to the last
  Kinetic quote number that predates go-live.

Tests: new `scripts/tests/test_kinetic_quote_adoption.py` (5 PHP-backed cases).

## 0.9.42-rc18 — account action requires the same access as the shared one

Not installed on QA and not approved for production.

rc18 is rc17 with one access-control fix. `POST Accounts/:record/bd-create-opp-quote`
(`BdBenchDogsActionsApi::createOppQuote`) checked only **view** access on the
account and then saved an Opportunity, a Quote, its bundle and a placeholder
line with no create-access check, so a user who could only view an account
could create sales records through the API. It now applies the same gate as
ERP-Epicor's `AccountsErpActionsApi::createOppQuote`: edit access on the account
and save access on Opportunities and Quotes, all checked before the first save.
A refused caller gets `SugarApiExceptionNotAuthorized` and nothing is created.
See `docs/release-0.9.42-rc18.md`.

## 0.9.42-rc17 — SugarCloud scanner-safe estimating notifications

Not installed on QA and not approved for production.

rc17 is rc16 with one scanner-forced change. SugarCloud's ModuleScanner
refused rc16 at upload on QA (2026-09-13): `BdEstimatingNotificationHook.php`
"Code attempted dynamically-named method call on line 521", the logging helper
`$GLOBALS['log']->{$level}($message)`. The helper now calls `error()`,
`warn()` or `info()` by literal name. Notification behavior, redaction and
every other rc16 change are unchanged.

The repository pre-flight missed it because `MLP017` matched
`$obj->$name()` but not the brace form `$obj->{$name}()` or dynamic static
calls; that gap is closed here and upstream in `erp-integration-sugar`.
See `docs/release-0.9.42-rc17.md`.


## 0.9.42-rc16 — exact Kinetic estimating completion

Not uploaded successfully to QA (rejected by the hosted scanner) and not
approved for production.

rc16 retains rc15's independently reviewed notification reliability and
replaces the unsafe `CurrentStage=QUOT` completion inference with the exact
nullable Kinetic facts `QuoteHed.Quoted` and `DateQuoted`. The customer module
stores both without defaults. Unknown preserves lifecycle state, explicit
false preserves an active hand-off, an observed true-to-false change after
priced marks revision, and only true with its business date advances to
priced. Linked-order and closed
outcomes outrank completion.

`DateQuoted` corroborates completion but is not treated as a precise clock:
the observed EPIC06 values carry midnight/date precision. Sugar's first
observed priced transition therefore owns the elapsed-time endpoints. Repeat
true is stable; a false-to-true revision can create a new stage-edge
notification without rewriting the first cycle's immutable timestamps. Shared core must first deliver the paired
container-boundary repair that omits unknown `null` values while preserving
false, zero and blank clears. SDK `1.18`, Quote-line-only behavior, shared
ERP-Epicor `1.1.24-rc9`, and Partial Fulfillment `1.0.13` remain unchanged.

See `docs/release-0.9.42-rc16.md` and
`docs/quote-completion-source-contract.json`.

## Previous candidate

## 0.9.42-rc15 — honest and deduplicated estimating notifications

Not installed on QA and not approved for production.

rc15 keeps the shared ERP action and persisted Bench stage as the primary
handoff result. The secondary Sugar notification now validates active internal
recipients, uses the native unique `Notifications.sync_key` to deduplicate one
stage transition, calls `save(false)`, and reads the record back before
claiming delivery. Missing, inactive, invalid, failed and unobserved delivery
paths remain named warnings and never undo or retry the ERP create.

The action response carries separate `erp_handoff_status` and
`notification_status` values. Sidecar keeps the successful action guarded and
shows an amber operational message when the Kinetic handoff succeeded without
a proven notification. Both configured recipient keys and their authoritative
failure semantics are documented. Shared ERP-Epicor `1.1.24-rc9`, Partial
Fulfillment `1.0.13`, SDK `1.18`, and the Quote-line-only model are unchanged.

Do not build or install rc15 independently. It must be reviewed, built and
accepted together with the paired Kinetic `Quoted`/`DateQuoted` lifecycle
candidate. The notification hook deliberately retains the return leg; it does
not guess completion from `CurrentStage` or hide the missing lifecycle input.

See `docs/release-0.9.42-rc15.md`.

## Previous candidate

## 0.9.42-rc14 — callable and fail-visible estimating handoff

Not installed on QA and not approved for production.

rc14 repairs the advertised Bench `Send to Estimating` action. The customer
route now delegates `advanced_quote` to the shared ERP-Epicor API owner,
applies only the Bench lifecycle transition after shared success, and proves
that transition through an uncached read before reporting completion. Existing
ERP quotes retain their current stage. The Sidecar action rejects repeated
events while a request is pending and does not invite a retry when ERP identity
is ambiguous or absent.

The turnaround start uses only the exact company-scoped Quote key already
stamped by core; it never borrows a company prefix or joins on bare QuoteNum.
The withdrawn best-pricing route is no longer advertised. ERP-Epicor
`1.1.24-rc9` and Partial Fulfillment `1.0.13` are pinned; SDK `1.18` and the
Quote-line-only model are unchanged.

This candidate does not claim durable cross-tab `quote_to_quote` idempotency,
notification delivery, or a valid estimating-completion signal: EPIC06 reports
`CurrentStage=QUOT` on every sampled open quote, so that value alone cannot
prove estimator work is finished. See `docs/release-0.9.42-rc14.md`.

## Previous candidate

### 0.9.42-rc13 — fail-closed commercial charges

rc13 requires explicit native Quote tax and shipping values before the Bench
contribution provider returns an amount. Missing values fail closed; genuine
numeric zero remains valid. It was staged but not installed. rc14 supersedes
it without reusing its version or bytes.

## Earlier candidate

# 0.9.42-rc12 — Quote-line-only visible-stage candidate

Not installed on QA and not approved for production.

rc12 makes the user's Opportunities-only decision a permanent package
boundary. It removes the old Opportunity-line vardef, runtime projection,
record creation and line-refresh implementation rather than hiding them behind
the current tenant configuration. Native Quote lines remain the only itemized
sales records; shared ERP-Core remains the sole Opportunity amount writer and
Partial Fulfillment remains the sole release-stage writer.

The stage styles are now strictly append-only at the key level: an existing
tenant style for either Bench stage wins, while an absent style receives the
Bench default. A regression proves both same-key preservation and preservation
of unrelated styles and `applyFormatting`.

This is a clean replacement, not an in-place upgrade. Module Loader must first
uninstall rc9 with data tables retained, then install rc12; otherwise files the
old archive shipped can remain on disk. Rollback is the reverse supported
sequence—uninstall rc12 with tables retained, then install the saved rc9
artifact—not a simple reinstall over rc12.

See `docs/release-0.9.42-rc12.md` for evidence, gates and rollback.

## Previous candidate

### 0.9.42-rc11 — visible native release-stage candidate

The rc9 hosted action reached `Prototype Closed`/80 in the API after native
language repair, while the Opportunity record view displayed a blank Sales
Stage. A disposable live diagnostic ruled out action timing and Sidecar model
staleness: route-away/back and hard reload both loaded the correct model value,
but the formatted `enum-cascade` remained empty. Client metadata had
`applyFormatting: true` and no `sales_stage_dom_style` entries for either
Bench stage.

rc11 appends those two entries through Sugar's native application
DropdownsStyle extension, preserving every shared and tenant-owned style. It
also includes rc10's installer language compile/refresh/verification and
removes Opportunity RLI repair references from both install and manual repair
lifecycle paths. Stage decision/write ownership, amount arithmetic, SDK and
shared dependencies do not change. See
`docs/release-0.9.42-rc11.md` for evidence, gates and rollback.

## Earlier candidate

### 0.9.42-rc8 — canonical stage-validation candidate

Not installed on QA and not approved for production.

The rc7 hosted run proved the complete persisted policy graph was correct and
still returned `policy_preserved`, disproving stale Link2 beans as the complete
cause. rc8 pins Partial Fulfillment 1.0.12, whose shared sole writer validates
customer stage keys against Sugar's freshly loaded current-language application
strings rather than relying only on a request-global copy that may predate a
language-extension rebuild. Loader failure falls back safely and unknown stage
keys remain rejected.

Bench policy behavior is otherwise unchanged: it decides only, never saves the
Opportunity, never accesses Opportunity Revenue Line Items, and refuses
ambiguous release identity. The rc8 archive is loadable and pins Partial
Fulfillment 1.0.12; SHA-256:
`9f3dd4b1769c46fd440469a09b3938522981863d5191e832f8b958cb8cd4e7e5`.
Hosted scan, installation, and the full prototype-to-production journey remain
required. Roll back to rc7 and Partial Fulfillment 1.0.11 without uninstalling
modules or removing tables.

## Previous candidate

### 0.9.42-rc7 — fresh release-policy graph candidate

Not installed on QA and not approved for production.

The coordinated rc6 hosted run returned
`release_stage_status=policy_preserved` even though post-request reads proved
the prototype Product was ordered, all four Product-to-ERP line numbers
matched, and exactly one ERP quote with one prototype line was linked. The
order action can carry already-loaded Link2 beans at more than one level, so a
clean REST read after the request does not prove what the policy observed
inside that request.

rc7 keeps Partial Fulfillment as the sole Opportunity-stage writer. The Bench
decision-only policy resolves relationship IDs and re-retrieves the ERP quote,
every ERP quote line, and every Product outside BeanFactory's request cache
before classifying the release. Missing, duplicate, or contradictory identity
still refuses rather than guessing. It does not save an Opportunity or access
Opportunity Revenue Line Items.

The source suite passes 85 tests with two declared skips, plus four independent
stale-graph/cardinality controls. Source and built-package preflight are clean
apart from the package line's known `files.md5` advisory. The rc7 archive is
loadable, and the packaged policy is byte-identical to source. Archive SHA-256:
`7022040a7af0da7e7cbab2eba7e911e8bad8329197bba9857b00883184381647`.
Hosted scan, installation, and a fresh prototype-first journey remain required.
Roll back to rc6 without uninstalling modules or removing tables.

## Earlier candidate

### 0.9.42-rc6 — observable shared-stage candidate

Installed on Bench QA for controlled diagnosis; not approved for production.

The installed rc5 package still created exactly one prototype order while the
Opportunity remained Proposal for 60 seconds. That disproved stale Product
beans as the complete cause. rc6 retains rc5's provider behavior but requires
the coordinated ERP-Epicor 1.1.24-rc4 / Partial Fulfillment 1.0.11 repair.
Those shared packages normalize associative Opportunity relationship id arrays
and return a neutral `release_stage_status` in the order response. The hosted
run returned `policy_preserved`, localizing the skip to the policy decision.
The status
contains no record ids, exception text or customer-policy internals and does
not alter the seller's truthful order-success message.

Offline network-none validation passes 88 tests with two declared skips. Source
preflight is clean; ZIP preflight has zero blocker/required findings and retains
the known `files.md5` advisory. The packaged provider and reflection hook are
byte-identical to source, and the manifest pins both coordinated dependencies.
SHA-256:
`f4b6ea37f16ef22bd95d800a6187a290b70739e203afd8167b8fef4a1897081e`.

That controlled run created exact Epicor order 11574, then removed and
absence-verified it and the complete owned Sugar/ERP graph with no cleanup
failures. Roll back to rc3 without uninstalling modules or removing tables.

## Earlier candidate

### 0.9.42-rc5 — fresh release-stage observation candidate

Installed on Bench QA for one controlled prototype-first run; not approved for
production.

The rc4 prototype action created one exact Epicor order and stamped the native
Quote line, but Opportunity remained Proposal for 60 seconds. The action had
loaded `Quote.products` before stamping `erp_ordered`; `Quote::retrieve()` did
not discard the loaded relationship bean snapshot, so policy observed the old
unordered Product and returned no decision.

rc5 retains the one-writer architecture and changes only observation: resolve
linked Product IDs, retrieve each with `use_cache=false`, then classify against
the same ERP-line identity. A linked Bench quote with no committed line now
throws a deterministic refusal for the shared logger. Amount, governing
formula, Quote lines, RLI boundary and release creation are unchanged.

Offline network-none validation passes 88 tests with two declared skips. Source
preflight is clean; ZIP preflight has zero blocker/required findings and retains
the known `files.md5` advisory. The packaged provider and reflection hook are
byte-identical to source. SHA-256:
`82989bf888f5a864b3ef8695c2dc2979e869e32aaf2bcd899556a5e3f409a2c3`.

Hosted scan passed, but the fresh prototype action failed identically to rc4:
exact Epicor order 11572 was created and the Opportunity remained Proposal for
60 seconds. The production leg was withheld and exact cleanup passed. Roll back
to rc3, the last candidate that preceded release-stage policy; do not uninstall
modules or remove tables.

## Previous candidate

### 0.9.42-rc4 — shared release-stage ownership candidate

Not installed on QA and not approved for production.

Bench now provides release-stage policy as plain data through Partial
Fulfillment 1.0.10's fixed neutral seam. Prototype-only release resolves to
Prototype Closed/80; any ordered production option resolves to Partial
Production Closed/90. Missing or contradictory ERP-line identity refuses.
The provider never writes Opportunity.

`BdQuoteReflectionHook` no longer advances post-order Opportunity stage. It
retains pre-order Proposal initialization and system/human forecast provenance.
When Kinetic reconciliation newly marks a Quote line ordered, it invokes the
same shared `AfterLinesOrdered` dispatcher used by Order Selected Lines, so
trigger order cannot choose a different writer. The package now requires
Partial Fulfillment 1.0.10.

Offline network-none validation passes 87 tests with two declared skips. The
composed real-PHP lifecycle keeps one Opportunity and zero RLI reads while
moving prototype release to Prototype Closed/80 and later production release
to Partial Production Closed/90. Policy cases cover no release, production
precedence, multiple ERP revisions, duplicate line identity, missing identity
and multiple prototypes. Source preflight is clean; ZIP preflight has zero
blocker/required findings and retains the known `files.md5` advisory. Both
changed packaged PHP files are byte-identical to source. SHA-256:
`dbfa731f1d05bda2b45a6bf46eff12634656e69aaf210c1ca9decbe085bf3d80`.

Hosted ModuleScanner and installed prototype-then-production release journeys
remain required before QA approval. Roll back to 0.9.42-rc3 without uninstalling
modules or removing tables.

## Previous candidate

### 0.9.42-rc3 — governing forecast alignment candidate

Installed on Bench QA as package
`7e19d778-aee9-11f1-8749-060ab0eed8b1`; not approved for production.

System-managed Opportunity Best and Worst now equal the shared writer's
Opportunity-currency amount: selected governing production + prototype +
native Quote tax + shipping. Unselected production alternatives remain
visible but contribute zero. The Bench hook invokes the shared writer first
on governing/ERP refresh, then consumes the resulting headline; it does not
duplicate contribution arithmetic or currency conversion.

Three hidden Opportunity fields retain explicit ownership provenance. Best
and Worst can be taken over independently by a person; once a field diverges
from the exact last system value it is marked human-owned and is never
reclaimed by value coincidence. On upgrade, only zero, current-headline or
legacy-deliverable values are adopted as system-owned. This migrates the QA
all-options values while conservatively preserving genuinely different human
forecasts.

The ERP Quote Line primary record panel now renders the editable Governing
Line boolean. rc2 exposed the field through the model/filter and used it in
the calculation, but omitted it from the actual detail layout, so a seller
could not inspect or change the choice in the supported UI.

Offline evidence: 79 repository tests pass with two declared skips (native
SugarLogic and sibling shared package unavailable in the isolated checkout).
The focused hook tests cover one-pass repricing, shared currency conversion,
legacy adoption and independent human takeover. PHP syntax and the source
preflight pass. The rc3 ZIP is structurally valid and has no blocker/required
findings; it retains the known `files.md5` advisory. SHA-256:
`1b9d90b0c9b853abfcc792ce26e005c266def5a602c5a60960731038ceab9f41`.
The repository-wide artifact-version checker also reports retained rc1/rc2
ZIPs as historical mismatches; the targeted rc3 artifact check passes.

Installed QA evidence passes: the 50x80 and75x70 governing options produced
Opportunity amount and system-managed Best/Worst of5850.63 and7100.63,
including prototype500, native tax1340.625 and shipping10. A manual Best
override of999 survived two later governing changes while system-owned Worst
continued to follow the headline. The real browser displayed amount7100.63,
all four Quote lines and the checked, enabled-in-Edit Governing control, with
no page errors.
Zero Opportunity RLIs were created. All12 owned records were deleted and
independently read back absent; browser/API logouts succeeded. Currency beyond
base USD, automatic Kinetic-header selection and concurrent governing edits
remain open. Roll back to 0.9.42-rc2; do not uninstall modules or remove
tables.

## Previous candidate

### 0.9.42-rc2 — governing contribution candidate

Installed on Bench Dogs QA as package
`9dcac8be-aee1-11f1-abd9-060ab0eed8b1`; not approved for production. The QA
site now has shared ERP-Epicor 1.1.24-rc3, satisfying this package's neutral
contribution contract dependency.

Only the selected governing production option contributes to Opportunity
amount; alternatives remain visible. Prototype value, Quote tax and shipping
also contribute. The provider never saves Opportunity or changes Quote lines;
the shared hook remains the sole writer and owns currency conversion. Missing
or ambiguous selection/revision/prototype data refuses without falling back to
the all-options total. Bench governing refresh now invokes that shared writer.

The exact Kinetic header field is unknown, so this candidate uses the explicit
Sugar governing flag and does not claim automated ERP selection. Native
SugarLogic/tax behavior, revisions, concurrency, package scanning and QA/UI
acceptance remain required. Roll back both coordinated MLP candidates to their
saved prior versions; do not uninstall modules or remove tables.

Offline evidence: 73 repository tests pass with two declared skips (native
SugarLogic and sibling shared package unavailable in the isolated checkout).
Five additional composed shared/Bench hook acceptance methods pass from the
workspace validation suite. The rc2 ZIP matches provider/reflection source and
declares the shared dependency; preflight has no blocker/required findings and
the known `files.md5` advisory. SHA-256:
`f2b938949a48a85e115134645698f3c1a6bab3db4ff145578c742f66b74dacaa`.
The retained rc1 ZIP is historical and therefore does not match the current
version file; the targeted rc2 artifact check passes.

Installed QA evidence (2026-09-12): one owned Quote retained all production
options (50×80, 75×70, 100×65) plus a $500 prototype. With native tax
$1,340.625 and shipping $10, Opportunity amount was $5,850.63 for the selected
50-unit option and $7,100.63 after selecting the 75-unit option. A later native
Quote save and repeated selection write both remained $7,100.63. Quote total
stayed $17,600.625 and no Opportunity RLIs were created. All 12 exact owned
records were individually confirmed absent after cleanup; API logout returned
200.

This proves the installed downstream provider, uniqueness hook, shared-writer
convergence and arithmetic. It does not prove automatic selection from the
unknown Kinetic header field. The run also found that Bench `best_case` and
`worst_case` became the all-options subtotal ($16,250) while headline amount
was $7,100.63. That measured inconsistency motivated rc3; it remains open in
the installed QA environment until rc3 passes its deployment gates.

## Earlier candidate

### 0.9.42-rc1 — local validation candidate

Not installed on QA or approved for production.

## Change

In Opportunities-only mode, Bench reflection no longer overwrites the
Opportunity headline amount calculated by the shared primary-Quote hook.
It retains its existing stage/forecast behavior and does not access or purge
Opportunity Revenue Line Items in this path. Historical cleanup is not part
of an ordinary reflection save.

This fixes competing writers: a Quote save could calculate 280 (250 in lines,
20 tax, 10 shipping), then a Bench reflection could replace it with 250.
It does not implement governing quantity alternatives or change their policy.

## Offline evidence

- Real public PHP hook fixture: 11 passing cases; one native
  SugarLogic/materialization case explicitly unverified.
- Source MLP preflight: no blockers, required findings or advisories.
- Built manifest/version/archive checks: pass.
- Built ZIP preflight: no blockers or required findings; existing builder
  omits root `files.md5` (MLP014 advisory). Real ModuleScanner remains required.
- Packaged reflection-hook bytes match the tested source; PHP syntax passes.

## Remaining QA gates

Install only on the Bench Dogs profile after the coordinated candidate gates
pass; stock must not receive this package. Verify Module Loader history,
native tax/shipping and currency calculations, refresh/repricing convergence,
and zero Opportunity RLI access. Keep governing quantity, revisions,
prototype/production and selected-line ordering open until separately proven.

## Rollback boundary

Retain the installed 0.9.41 package and a recoverable QA backup before changing
the instance. Do not uninstall the module or drop its tables to roll back a
hook change. The rollback procedure and restored installed-version evidence
must be rehearsed before approval; this local build does not prove them.
