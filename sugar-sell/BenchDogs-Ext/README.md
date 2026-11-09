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
> | ~~`custom/clients/base/api/BdBenchDogsActionsApi.php`~~ | **no longer shipped (rc87)** | the emptied REST stub; ONEOFF-RetireBdResidue 1.0.6+ deleted the tenant's copy (*withdrawn, 🔒2173b: a tenant it never ran on keeps its copy*) |
> | `scripts/post_execute.php`, `scripts/bd_pre_uninstall.php`, `scripts/post_uninstall.php` | the lifecycle: rebuild + have ERP-Core place the marked fields + the G294 step report; proof of life; rebuild + have ERP-Core retire the marked fields | — |
>
> **rc70 (🔒 1724b): this package ships NO layout code.** Its fields carry
> ERP-Epicor's `erp_layout` vardef marker, and ERP-Core's
> `ErpLayoutExtraFields::sync()` places them on the views ERP-Epicor owns (and
> takes them off once their vardef is gone). `BdAccountsLayoutExtensions.php`
> stays on an upgraded tenant as an INERT orphan - Module Loader never deletes
> a file a later build stops shipping (§CW / G37), and nothing rc70 ships
> requires it; nothing removes it (the one-offs were withdrawn, 🔒2173b).
> **rc82 (G458) adds no layout WRITER either:** one sidecar record-view overlay
> for five fields a CUSTOMER package owns (below), merged at metadata build; it
> writes nothing to a deployed view.
> **rc84 (G848) adds none either:** five sidecar view overlays that stop a
> quote DRAWING a discount (below), merged at metadata build; they write
> nothing to a deployed view and change no stored value.
> **rc85 (G848, 🔒2159b) adds none either:** a sixth overlay (the Quotes list
> preview) and two en_us language overrides of existing ERP-Core keys.
>
> **rc72 (G507): the Account pair's marker is `panel_overview` after Industry,
> and both fields are `readonly`.** Moving the pair OUT of the header rc69 put it
> in (on a view with no `panel_body`, e.g. Ophir) is not this package's job - a
> placed field is never moved by `sync()` and this package writes no layout. It
> was the disposable one-off `ONEOFF-MoveBdCustomerGroup`, *withdrawn and deleted
> by 🔒2173b (2026-09-30)*: nothing in this repository moves the pair any more.
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
> | `custom/Extension/modules/Quotes/Ext/LogicHooks/bd_adm_quote_defaults.php` | before_save: fills an EMPTY `erp_reference` (ship-to city + state, shortened to the ERP's limit, G530) on an unsent ADM quote, and on a NEW quote an EMPTY Lead Source, Lead Type and Campaign + Event pair from the account's newest quotes still offering them (rc78/rc81); never the Project (rc117: it is each line's own; rc110 to rc116 filled it on a create that sent none); exits before loading anything for a quote it cannot touch | ADM-only defaults; creates nothing (🔒 1499 still holds, `test_g243…` pins it) |
> | `custom/Extension/application/Ext/Language/en_us.bd_adm_lists.php` | labels for the `ERP_LookupValues` types (rc108: the product group -> project list is gone) | — |
> | `custom/src/BenchDogs/BdAdmRules.php` (`Sugarcrm\Sugarcrm\custom\BenchDogs\BdAdmRules`, autoloaded; before rc86 `custom/modules/Quotes/BdAdmRules.php`) | the rules (which companies are ADM, the quote defaults) and, since rc87, the pickers' option sources as static methods (`BdAdmRules::leadSourceOptions` etc., named by the vardefs with no include; `BdAdmLookupOptions.php` is retired) | — |

> **Grown by G450 (0.9.42-rc74), with the owner's per-item consent** (his Yes to
> a Bench-only third account type "Suspect" beside the stock Customer/Prospect,
> never renaming them; GAPS.md G450; voided with rc71 by 🔒1775b, revived by the
> customer's 🔒1783b). One file:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/application/Ext/Language/_override_en_us.bd_account_type_suspect.php` | ONE key, `account_type_dom['Suspect']`, set only when absent | Epicor types a customer CUS / PRO / SUS; ERP-Core's dom has two values, so an Epicor SUS shows as Prospect (ADM: 1,022 of 1,132 "Prospects"). Core writes `Suspect` for SUS customers once the ADM connection's `customer_type_extra` is `{"SUS": "Suspect"}`; the value is Bench's (a dom key core cannot add). `_override` so it merges after ERP-Core's whole-array REPLACE assignment (the rc23 lesson) |

> **Grown by G458 (0.9.42-rc82)** (🔒2102b: the Bench contact Function / Role /
> primary flags, READ-ONLY Epicor → Sugar; the Bench connector extension 0.3.6
> writes them, level L807). One file, and it is not a field:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/modules/Contacts/Ext/clients/base/views/record/bd_epicor_contact_fields.php` | a sidecar overlay on the Contacts record view (rc117: `role_c` drawn editable, the other four read-only): `epicor_function_c`, `role_c`, `primary_billing_c`, `primary_purchasing_c`, `primary_shipping_c` at the end of ERP-Core's **ERP** panel (`LBL_RECORDVIEW_PANEL_ERP`, after ERP Contact ID; else `panel_body`; else the first non-header panel with fields), every entry of the five `readonly`, **only the ones the tenant's merged Contact vardefs hold** | The five are Bench Dogs' OWN fields (their package `Bench_Dogs_Account_Contact_Fields` 1.0.0, `custom_fields`), and it ships no layout. The `erp_layout` marker cannot carry them: ERP-Core's `ErpLayoutExtraFields::sync()` supports Quotes/Accounts only, and a marker on a field another package owns is included BEFORE `fields_meta_data` merges (SugarEnt 26.1.0 `VardefManager::refreshVardefs`), so it can neither see the field nor avoid leaving a typeless phantom def where that package is absent. The overlay is merged each metadata build (`MetaDataFiles::getClientFileContents`), follows a Studio-edited view, reaches upgraded tenants, and leaves with uninstall. On Ophir / stock / et (no customer package) it changes nothing |

> **Grown by G848 (0.9.42-rc84)** (owner 🔒2151b, 2026-09-30: *"benchdog dont
> want seller to apply discount so both the discount pannel and the line item
> doscounts should be not vissible on benchdog MLP"*). Five files, one body
> (`scripts/tests/bd_seller_discounts_hidden_test.php` holds them byte-equal),
> none a field or a setting:
>
> | Path (`custom/Extension/modules/…/Ext/clients/base/views/…/_override_zz_bd_hide_seller_discounts.php`) | Not drawn | Left alone |
> |---|---|---|
> | `Quotes/…/record/` (also the create form, which is built from the record meta) | ERP-Epicor's **Discount** panel `LBL_RECORDVIEW_PANEL_ERP_DISCOUNT` and its one field `erp_discount_panel` ("Apply a discount / Whole order / % / Apply"); any quote-level discount figure an admin placed | the nested fetch list (lines still READ `discount_amount` / `discount_select`), the ERP panel's Discount Warning, ERP-Epicor's SetVisibility rules |
> | `Quotes/…/quote-data-grand-totals-header/` | `deal_tot` ("Order Level Discount") | Line Items Discounted Subtotal, Tax (via ERP), Shipping, Grand Total |
> | `Quotes/…/quote-data-grand-totals-footer/` | `erp_document_discount_amount` ("Order Level Discount", shown by ERP-Epicor only when non-zero) | every other row; the stored figure and its recalculation |
> | `Products/…/quote-data-group-list/` (rows, edit row, column headers) | `discount_field` ("Line Discount": `discount_amount` + the % / amount toggle `discount_select`) and the line-discount figures derived from them | `discount_price` (Unit Price), `discount_usdollar`, Extended Price, Discounted Total |
> | `Products/…/record/` (the line page the line number opens) | the same `discount_field` | everything else |
> | `Quotes/…/preview/` (rc85, 🔒2159b: the Quotes LIST preview) | `deal_tot` ("Order Discount") | Subtotal, Tax, Shipping, Grand Total and every other entry |
>
> rc85 also relabels two ERP-Core captions on Bench only, as en_us overrides of the EXISTING keys (no new label, no field):
> `custom/Extension/modules/Quotes/Ext/Language/_override_en_us.bd_hide_seller_discounts.php` sets `LBL_NEW_SUB` to
> **"Subtotal"** (ERP-Core: "Line Items Discounted Subtotal"; the Bench quote PDF's subtotal caption reads the same key),
> and `custom/Extension/modules/Products/Ext/Language/_override_en_us.bd_hide_seller_discounts.php` sets
> `LBL_ERP_DISCOUNTED_TOTAL` to **"Total"** (ERP-Core: "Discounted Total", the grid column). `_override` merges them after
> ERP-Core's plain files whichever package is installed last (ModuleInstaller sorts `_override*` last, then mtime).
> Other languages keep ERP-Core's text. Uninstall returns ERP-Core's captions.
>
> rc87: the six overlays are three lines each (rc88 adds one comment line on why the `class_exists` guard stays: the compiled ext outlives the class for one step of an uninstall); the shared rule is `custom/src/BenchDogs/BdHiddenFields.php` (`BdHiddenFields::strip()`, which keeps its own try/catch so a bad panel never breaks a view). An overlay runs only on a panel list, and only when the class autoloads; otherwise the view is served as read.
>
> **Admin > Quotes Configuration.** That screen saves the Quotes summary columns into the deployed views, but these overlays strip the discount fields at render. An admin who re-adds Discount there sees the setting saved and never drawn on a Bench tenant. That is by design (🔒2151b); nothing stored changes.
>
> Why an overlay and not a layout write (decision 803): Sugar includes these
> right after whatever viewdef is deployed, at every metadata build, so they
> follow ERP-Epicor's layout in either install order, and nothing ever saves
> the hidden view (`ViewdefManager::loadViewdef()` skips `.ext.` paths).
> `_override` merges them after any plain fragment another package adds. A
> tenant without this package keeps every discount field; uninstall deletes the
> files and Module Loader's rebuild gives the fields back. A discount Epicor
> sends still lands in the line and quote totals; nothing here refuses a
> discount sent through the API (🔒2151b (d)).

> **Grown by G860 (0.9.42-rc89)** (owner 🔒2179b, 2026-10-01: no new field, *"it's a
> Bench Dogs thing"*: the Bench package supplies ADM's Ship Via rule, so ERP-Epicor's
> one pre-send order refusal names the FOB AND the Ship Via). Two files, no field:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/modules/Quotes/ErpQuoteHooks/OrderRequirements.php` | the adapter at ERP-Epicor 1.2.4's `OrderRequirements` slot: the global class `ErpQuoteOrderRequirementsHook` ERP-Epicor instantiates by name; any failure answers "nothing missing" and is logged (the connector extension checks again before the send) | the slot is ERP-Epicor's generic contract (`ERP-Epicor/docs/order-requirements-hook.md`); the rule is ADM's |
> | `custom/src/BenchDogs/BdAdmOrderRequirements.php` | on an ADM quote whose order CONVERTS the ADM ERP quote (a line keyed `<CO>__<QuoteNum>_<QuoteLine>_<QtyNum>`, core's G304 route) and whose quote has no Ship Via (its relate is the read-back of the ERP quote's ShipViaCode): *"This quote has no Ship Via and its ERP quote N has none either, so it cannot be ordered. Pick a Ship Via on the quote, then submit again. Nothing was sent to the ERP."*  **Since rc93, a Sales Order too** (no line converts an ERP quote): Kinetic takes the CUSTOMER's Ship Via there, which the connector keeps on the billing account's Ship Via relate (`erp_ship_viaerp_lookupvalues_idb`, from `Customer.ShipViaCode`, SDK 1.20+). A quote with no Ship Via whose account has none either: *"This quote has no Ship Via and its customer has no default Ship Via in the ERP, so it cannot be ordered. Pick a Ship Via on the quote, then submit again. Nothing was sent to the ERP."* (the words ERP-Epicor's own test pins for this case) | the connector extension's own rule (G829 for a Sales Order, G852 for a converted order), asked before the send instead of after the FOB is fixed; the extension's write-back check stays as the second judge of both routes |

> **Grown by the lead baseline (0.9.42-rc90)** (the owner approved one hidden
> field on 2026-10-02 to replace the connector extension's own state file).
> Two files, one field on no layout:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/modules/Quotes/Ext/Vardefs/bd_lead_baseline.php` + `.../Language/en_us.bd_lead_baseline.php` | hidden `Quotes.bd_lead_baseline` (JSON): per Lead Source, Lead Type, Mktg Campaign and Marketing Event, the value Sugar and the ERP last agreed and the last ERP value. The quote before_save (`BdAdmRules::stampLeadBaseline`) stamps it on the connector's save that first gives the quote its ERP key (Send to Estimation; told by the `sugarai_erp_connector` platform, never by user); the Bench connector extension (0.3.9) rewrites it with each value it reads back from the ERP | the four fields and their ERP read-back are Bench's; the read-back keeps a seller's edit over a later ERP change only by knowing what was agreed |

> **Grown by the new product's Part Number (0.9.42-rc94)** (Bench Dogs' ask, relayed by
> the owner on 2026-10-14: *"only their MLP or extension"*). One file, no field, no
> setting, no layout:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/modules/Products/Ext/Dependencies/_override_zz_bd_new_product_part_number.php` | rewrites ERP-Core's ONE Part Number rule (`erp_part_number_read_only`, read-only on every line) in place: read-only `or(not(equal($product_template_id, "")), equal($category_id, ""), not(equal($erp_sync_key, "")))`, so the Part Number is typeable only on a line with no catalog product whose Product Group is picked and that the ERP does not hold yet (a new product before Send to Estimation, or one added after it before Sync Now). Adds `category_id` and `erp_sync_key` to the rule's triggers. Where ERP-Core's rule is absent it changes nothing. `_override` so it merges after ERP-Core's file (Sugar merges `_override` files last) | ERP-Core's rule (G758: a quote line's Part Number is never typed) stays for every other customer. The typed value is what ERP-Epicor sends (`ErpQuoteFacts::partNumber` returns a catalog-less line's own `mft_part_num`; the part-number stamp renames only the `TO-BE-CREATED` / `NEW-Q…` placeholders). **Known consequences, all ERP-Epicor's and unchanged here:** a typed part is no longer a "new product" to ERP-Epicor (it recognises one by the placeholder), so Send to Estimation asks for its Unit Price, the Product Group picker hides once the placeholder is typed over (hence the group must be picked first), and Submit Order no longer waits for the estimator to create the part (the connector orders it as an on-the-fly part) |

> **Grown by the order and quote Opportunities (0.9.42-rc95)** (the owner approved
> exactly these two fields on 2026-10-14, in this package only, for Bench Dogs' ask that
> an Epicor order with no Sugar quote, and an Epicor-born quote, carry one Opportunity).
> Four files, two fields, one relationship, no layout. The Bench connector extension
> (1.0.18) creates the Opportunity; this package only gives it the key and the link:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/modules/Opportunities/Ext/Vardefs/bd_erp_sync_key.php` + `.../Language/en_us.bd_erp_sync_key.php` | `Opportunities.erp_sync_key` (varchar 255, unique index `idx_opportunities_erp_sync_key`, not copied on a record copy, no default, not required, on no layout) and the link `bd_erp_orders` | core upserts and links every record by `erp_sync_key`, and no package defines it on Opportunities, so without it Sugar refuses every Opportunity the extension sends. Owner: Bench only |
> | `custom/Extension/modules/ERP_Orders/Ext/Vardefs/bd_opportunity.php` + `.../Language/en_us.bd_opportunity.php` | `ERP_Orders.bd_opportunity_id` (a real column, indexed, read-only; ERP_Orders refuses a non-connector edit of a read-only field), its relate `bd_opportunity_name`, the link `bd_opportunity`, and the one-to-many relationship `bd_opportunity_erp_orders` (one Opportunity, many orders, on that column, no join table) | no relationship joins an order to an Opportunity in any package; the order sits under its Opportunity through this one |
>
> **How the columns are created:** Module Loader's own database repair at the end of the
> install (SugarEnt 26.1 `ModuleInstaller::install()` runs `RepairAndClear::repairAndClearAll`
> for all modules with execute on, which runs `repairDatabase`, and that adds every vardef
> field that is not `non-db` and every declared index: `opportunities.erp_sync_key` and
> `erp_orders.bd_opportunity_id`). No script here writes SQL. Neither field has a default,
> so that repair writes nothing into existing rows. Neither is on any view: no `erp_layout`
> marker (so ERP-Core's layout step never places them) and no viewdef names them.

> **Grown by the FOB and Ship Via markers (0.9.42-rc96; FOB's moved to ERP-Epicor in 0.9.46-rc102; rc104 to rc112 Ship Via required on every quote; since 0.9.46-rc113 empty)** (the owner's ask that the quote
> show the fields Submit Order refuses for, before the seller presses it; from rc104, "required the Sugar way": red on a
> refused Save, no "Required" text; from rc113, owner 2026-10-22: Ship Via and FOB are checked only when the seller
> presses Submit Order or Order Selected Lines, where Kinetic needs them). One file, and it
> is not a field, a setting or a layout; it was the first JavaScript this package shipped:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/modules/Quotes/clients/base/fields/erp-lookup/erp-lookup.js` | since 0.9.46-rc113 an EMPTY Quotes override of ERP-Core's `erp-lookup` field (`extendsFrom: 'ErpLookupField'` and nothing else): Ship Via is not required on a quote, at any stage, on the create form or the record view. rc104 to rc112 marked Ship Via's definition required on the view on every quote, so a Save with no Ship Via was refused. The file keeps shipping because Module Loader never deletes a file a later build stops shipping: only a file at this path takes rc112's rule off an upgraded tenant. FOB is ERP-Epicor's, never required on a quote from ERP-Epicor 1.2.118. A module field, served with the module's metadata: an install reaches the browser with no Quick Repair (nothing is in a script bundle) | Ship Via is checked where Kinetic needs it: this package's `BdAdmOrderRequirements`, named in ERP-Epicor's one pre-send refusal beside the FOB on Submit Order and Order Selected Lines. rc96 to rc103 drew a `(Required)` label marker instead and never blocked Save |

> **Grown by the default owner for ERP-created records (0.9.44-rc99)** (the owner approved one setting on
> 2026-10-16, in this package only, not in ERP-Epicor). Sugar fills no owner on a record created through its REST
> API (SugarEnt 26.1: `ModuleApi::createBean`, `IntegrateApi::upsertByField` and `SugarBean::save` set none; only the
> browser's create view puts the signed-in user there), so the Opportunities and ERP orders the integration creates
> without an owner stay unowned. One setting, a small Admin page, one save hook. Ten files, no field, no record-view layout:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/src/BenchDogs/BdDefaultOwner.php` | the rule: the setting (`benchdogs_ext` / `default_owner_user_id`, base platform), who may own records (found, not deleted, status Active), and the before_save hook: on a save the integration makes (the connector's platform, `BdAdmRules::CONNECTOR_PLATFORM`), an empty owner (absent, null or blank) takes that user; a seller's own save is never filled; an owner that is set is never changed and costs no read; an empty setting does nothing; a user who is not Active is left out with a warning; a failed read leaves the owner empty, logs an error, and the save goes on | the owner ruled it Bench only |
> | `custom/Extension/modules/Opportunities/Ext/LogicHooks/bd_default_owner.php`, `custom/Extension/modules/ERP_Orders/Ext/LogicHooks/bd_default_owner.php` | the hook, registered once on each module | — |
> | `custom/Extension/modules/Administration/Ext/Administration/bd_default_owner.php` + `.../Language/en_us.bd_default_owner.php` | a **Bench Dogs** section in Admin with one link, **Default owner for ERP-created records**, and the page's words | — |
> | `custom/modules/Administration/clients/base/layouts/bd-default-owner/`, `.../views/bd-default-owner/` (php, js, hbs) | the page (`#Administration/layout/bd-default-owner`): Sugar's own relate field over Users (a lookup, never a text box; Sugar's own search leaves out Inactive users), the saved user by name, Save and Cancel, and a word when the saved user is no longer active. Save sends the picked user's id; a cleared box sends empty (off). Module client files, served with the metadata: no Quick Repair | — |
> | `custom/modules/Administration/clients/base/api/BdDefaultOwnerApi.php` | GET and PUT `BenchDogs/default-owner`, administrators only; PUT stores an active user's id or empty and refuses any other id with nothing stored | a module api folder, so the retired `custom/clients/base/api` stays absent |
>
> An owner a seller clears in the browser stays empty: only the integration's saves are filled. Records already
> saved without an owner get it the next time the integration saves them. Sugar decides its assignment email and
> notification after the before_save hooks run, so the default owner is notified for each record the hook fills (a
> re-read that fills many sends that many), as for any assignment, unless it is the user the integration signs in as.

> **Grown by Bench Dogs' additional mapping, the stage list and the admin link (0.9.45-rc100)** (the owner's
> request approves exactly the mapping's fields; the stage list and the link are the owner's rulings of 2026-10-17):
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/src/BenchDogs/BdKineticFields.php`, `BdStudioFields.php` | the 24 Kinetic fields (4 on Quotes since rc119's Due date, 5 since rc122's Project Name, 17 on the quoted line item, 2 on ERP orders) and the post_install step that creates each one a tenant lacks, as a Studio custom field through Studio's own field code, with the existing Studio fields' types and sizes, the mapping's places and its Kinetic field names as labels (the Due date's label is "Due", as Bench Dogs asked); none is required. A field a tenant has is never altered or created again. rc111 retires the order lines total `e_total_charges_c` (ERP-Epicor's own `erp_line_total` holds it): no longer created, relabelled "Line Total (old)" by the install while it reads "Charges" or "ERP Line Total", never deleted | Bench Dogs' own fields |
> | `custom/src/BenchDogs/BdFieldPlacement.php` + `custom/Extension/modules/{Quotes,Products,ERP_Orders}/Ext/clients/base/views/record/bd_kinetic_fields.php` + `ERP_Orders/Ext/clients/base/views/list/bd_kinetic_fields.php` + `Products/Ext/Language/en_us.bd_kinetic_fields.php` | a view overlay: the quote's header fields in the ERP panel above the status block (rc120: the Due date right after Expires Date wherever that is drawn; rc121: Expires Date no longer drawn on any quote view, `takenOff()` with `QUOTE_TAKEN_OFF`, and the Due right after Valid Until, the Bench UAT layout's body included; above the status block only when none of Valid Until, Best Case or Confidence is drawn; the create form draws the record view's panels, so the same); the line fields in a **Cost & capacity** panel in the mapping's order, directly under ERP-Epicor's Epicor Cost Worksheet panel (rc107; last on a view without it), holding none of that panel's values; the order's Project Manager and Engineer. rc111: the retired `e_total_charges_c` off the order's record and list views wherever a stored layout holds it, and ERP-Epicor's own `erp_line_total` right after ERP Misc. Charges where the stored record view lacks it (an upgraded tenant's does), never where the tenant has no such field. Only fields the tenant has; a field already on the view stays where it is, except that a line field found inside the cost worksheet panel (a Studio save put it there) is drawn in the section (rc108), and a row of that panel it leaves holding only blank cells goes, as Studio's own field removal drops it (rc109); Kinetic's own fields read-only (on the line, all but sales category and quantity quoted, rc107) | ERP-Epicor's layout files are never written |
> | `custom/Extension/application/Ext/Language/_override_en_us.bd_sales_category_list.php` | the Sales Category list, defined only where a tenant has none of its own, from ADM's Kinetic sales categories | — |
> | `custom/Extension/application/Ext/Language/_override_en_us.bd_sales_stages.php` + `DropdownsStyle/bd_sales_stage_style.php` | Bench Dogs' Opportunity stages and probabilities, in order, over the integration's keys (the table below; On Hold since rc125), merged since rc126 with an administrator's Dropdown Editor list: a stage, label or probability an admin added or changed is kept, and each of these stages is added at its place only where missing; a style for its four new stages | the owner ruled the list Bench only |
> | `custom/Extension/modules/Administration/Ext/Administration/_override_bd_default_owner.php` | the default owner link inside ERP-Core's ERP Integration group, after ERP Sales Reps; a Bench Dogs box only when that group is absent. rc99's `bd_default_owner.php` ships empty | — |
>
> Bench Dogs' Opportunity stages, as `_override_en_us.bd_sales_stages.php` serves them (rc100; On Hold added in rc125).
> Each probability is used by one stage only; the rows are in the Sales Stage list's order. This is the list on a tenant
> where no admin edited it; since rc126 an admin's own stages sit where the admin put them, with the admin's labels.
>
> | # | key | label | probability | from |
> |---|---|---|---|---|
> | 1 | Prospecting | Qualifying_Designing | 10 | Sugar's key, relabelled |
> | 2 | Estimating | Estimating | 20 | this package |
> | 3 | Proposal/Price Quote | Quote Delivered | 25 | Sugar's key, relabelled |
> | 4 | Negotiation/Revision | Negotiation/Revision | 50 | this package |
> | 5 | Verbal commit/PO Pending | Verbal commit/PO Pending | 75 | this package |
> | 6 | Prototype Ordered | Prototype Ordered | 80 | Partial Fulfillment's key and values |
> | 7 | Partial Production Ordered | Partial Production Ordered | 90 | Partial Fulfillment's key and values |
> | 8 | On Hold | On Hold | 5 | this package (rc125), the Quote stage's key; Forecasts Exclude under Sugar's default ranges |
> | 9 | Closed Won | Closed Won | 100 | Sugar's key |
> | 10 | Closed Lost | Closed Lost | 0 | Sugar's key |

> **Grown by the customer's quote layout (0.9.46-rc112; price breaks, long description and the sample's formats since
> 0.9.46-rc114; the "Page: 1 of 2" footer since 0.9.46-rc115)** (the owner's request of 2026-10-21, with Bench Dogs'
> Kinetic quote form as the sample):
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/src/BenchDogs/BdQuotePdf.php` + `custom/themes/default/images/bd_quote_logo.jpg` | Bench Dogs' quote layout as a PDF Manager template on Quotes (`template_name` `erp_customer_quote`, named "Bench Dogs Quote"), created or brought to this release's body by post_install (step `quote_pdf_template`), unpublished by pre_uninstall (the record stays), and the logo it prints, taken from the sample. Top to bottom: logo, Quote / Quote Date / Expires; Quote To and Ship To; Project (since rc122 the quote's Project Name, `e_project_name_c`; empty before), Sales Person with email and mobile, Terms; the contact; the line table (line, Part #, Customer Part #, Description, Qty, Unit Price, Ext Price), each line's long description (its own description where it says more than its name, then `e_line_comment_c`) and, since rc114, its price breaks: every quoted line item of its quantity ladder (ERP-Epicor's `ErpLineRole::ladderGroupOf` rule: the key less its last part, else the `erp_ladder_group` a copy kept), quantity with unit and unit price, and no table for a ladder of one; the Terms & Conditions, signature and Purchase Order block; the closing line. Since rc114, prices print without the currency symbol (341.00, 25,575.00) and Expires as M/D/YYYY where the user's date format allows it without guessing. Since rc115 the body opens with ERP-Epicor's footer page label marker (`{* ERP_PAGE_LABEL: Page: %s of %s *}`, a Smarty comment that prints nothing), so with ERP-Epicor 1.2.120 or later every page's footer reads "Page: 1 of 2" instead of PDF Manager's " 1 / 2". Every value is a stored field; Sugar's own Quote template is never touched | one customer's document layout and boilerplate |

> **Grown by the quote line Product Group (0.9.46-rc116, G1338)** (the owner's ruling of 2026-10-25: yes to showing
> Category as "Product Group" on quote lines, only in this package, no new field). Bench Dogs could not see Epicor's
> Product Group on a quote. The sync already stores it: Epicor's product groups are Sugar's product categories, a
> product's category is its group, and a quote line carries its product's category. Three files, no field, list or
> vardef:
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list/bd_product_group_column.php` | a sidecar overlay on the quote line grid: stock `category_name` as a column right after Part Number (else after the product name, else last), read-only and plain text (no link to the category's admin record), captioned by the category's own label. A column an admin already placed stays where it is and turns read-only. The rule is `BdFieldPlacement::gridColumn()` (its own try/catch: a grid it cannot read is served as read). The value comes from ERP-Epicor's own fetch of each line's `category_id` / `category_name` for the grid (G854) | one customer's grid column |
> | `custom/Extension/modules/Products/Ext/Language/_override_en_us.bd_product_group.php` | the quoted line item's `LBL_CATEGORY_NAME` and `LBL_CATEGORY` read **"Product Group"** (stock: "Category Name:", "Category:") | one customer's word for the category |
> | `custom/Extension/modules/ProductTemplates/Ext/Language/_override_en_us.bd_product_group.php` | the product's `LBL_CATEGORY_NAME`, `LBL_CATEGORY` and `LBL_LIST_CATEGORY` read **"Product Group"** (record, list, catalog and filter captions) | the same |
>
> `_override` so the captions merge after any plain fragment with the same keys. Other languages keep Sugar's text;
> uninstall gives the stock captions and the stock grid back. ERP-Core's own `LBL_ERP_PRODUCT_GROUP` (the new
> product's group picker in the name cell) is left as it is.

> **Grown by the quote line Product column width, and the Cust. Group rule widened (0.9.46-rc120)** (two Bench Dogs
> UAT asks; no field, label or list is added; the quote's Due date also moves next to Expires Date, see the
> `BdFieldPlacement` row above).
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list/bd_product_column_width.php` | a sidecar overlay on the quote line grid: the Product column (`product_template_name`) drawn with Sugar's widest list column class, `cell-xxlarge` (460px), instead of stock's `cell-large` (180px). Sugar sets a grid column's width on its header cell from that class and the line's cells follow it, the edit row's product picker included (select2 at 100% of its cell). Nothing else of the column changes (its picker, required flag, label, related fields), nor any other column; the grid scrolls sideways as before. The rule is `BdFieldPlacement::gridColumnWidth()` (its own try/catch: a grid it cannot read is served as read) | one customer's grid width |
> | `custom/Extension/modules/Accounts/Ext/Dependencies/bd_adm_customer_group_required.php` (since rc79) | the view rule that makes Cust. Group (`bd_customer_group_code`) required in the Accounts edit views on an account not yet in the ERP (no ERP key) whose ERP company is ADM. Since rc120 of **any type**: a Prospect or Suspect needs a group too, because Send to Estimation creates the account in Kinetic and ADM refuses one with no group. Edit views only: the field is never served `required`, so the sync's own account writes are never refused | ADM's own rule |

> **Grown by Expires Date off the quote and the account owner on new records (0.9.46-rc121)** (two Bench Dogs UAT
> asks; no field, label, list or setting is added; Expires Date's field and data stay, the Bench connector extension
> still writes it).
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/Extension/modules/Quotes/Ext/clients/base/views/{recorddashlet,preview,list}/bd_expires_date_off.php` | sidecar overlays: Expires Date (`e_expiration_date_c`) off the quote's dashlet view, the Quotes list preview and the Quotes list view with its column chooser (`BdFieldPlacement::takenOff`); the record view's comes from its `bd_kinetic_fields.php` | Valid Until is Kinetic's expiry for Bench Dogs |
> | `custom/src/BenchDogs/BdAccountOwner.php` + `custom/Extension/modules/{Opportunities,Quotes}/Ext/LogicHooks/bd_account_owner.php` | the rule and its before_save hook: a new Opportunity or Quote owned by the user saving it, or by no one, takes its account's owner when that is another Active user (a Quote's account: its Billing Account, else, since rc123, its top Account Name `account_id`); never an update, the integration's own save (the connector's platform), a pick the create form reports (`bd_owner_chosen`), or another owner. ERP-Epicor's Create Opportunity & Quote is such a save | one customer's ownership rule |
> | `custom/Extension/modules/{Opportunities,Quotes}/Ext/clients/base/layouts/create/bd_account_owner.php` + `custom/modules/{Opportunities,Quotes}/clients/base/views/bd-account-owner/bd-account-owner.js` | the create form's half: a view that draws nothing (one controller, the same file in each module, served with its metadata), put right after Sugar's create view in the two create layouts (`BdAccountOwner::withView`); on a new record it puts the account's Active owner in Assigned To whenever the account is set (on a quote, since rc123, the Billing Account or the top Account Name, the Billing Account deciding when both are), until the person picks an owner, and reports that pick on the save | ERP-Epicor ships the Quotes create view and ERP-Core the Quotes relate field; neither is replaced |

> **Grown by the quote's Project Name (0.9.46-rc122)** (one Bench Dogs UAT ask; one new field, approved by the owner;
> no new file).
>
> | Path | What it is | Why it is here and not in core |
> |---|---|---|
> | `custom/src/BenchDogs/BdKineticFields.php` | one more Kinetic quote field: `Quotes.e_project_name_c`, a **text** field (Studio's TextArea, MySQL TEXT, the type ERP-Core gives `erp_quote_comment`) labelled **Project Name**, not required, created by the install where a tenant lacks it and never altered where it has it. It holds Kinetic's `QuoteHed.QuoteComment`, which the Bench connector extension maps both ways; `erp_quote_comment` is not reused, as on this connection it holds Kinetic's Web comment (`ECCComment`) | Bench Dogs' own field |
> | `custom/src/BenchDogs/BdFieldPlacement.php` + `custom/Extension/modules/Quotes/Ext/clients/base/views/record/bd_kinetic_fields.php` | the record view (so the create form) draws it once, editable, on a row of its own (`FULL_ROW`, `span` 12) right after the Due (`FOLLOWS`): Valid Until \| Due, then Project Name, then Best Case \| Confidence. A row of its own takes no filler cell. One a stored layout already draws stays where it is | ERP-Epicor's layout files are never written |
> | `custom/src/BenchDogs/BdQuotePdf.php` | the PDF's Project row prints it (`$fields.e_project_name_c`, line breaks kept); empty for a quote with none | one customer's document layout |

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
| Project ID | `Products.bd_project_id`, one per quote LINE (since rc117; `Quotes.bd_project_id` keeps the old quote-level values, on no quote view) | **this package** (field, blank by default); the Bench connector extension (sends it per line, and names it when missing) |
| Marketing Campaign / Marketing Event (G460; quote header AND every order line) | the seller's picks, `bd_marketing_campaign` / `bd_marketing_event` | **this package** (fields; a new quote copies the pair from the account's newest quote, rc81); the Bench connector extension (publishes the lists, sends `MktgCampaignID` / `MktgEvntSeq`) |

**What this package does (Sugar side):**

- **Lead Source / Lead Type** are pickers the SELLER fills. Their options are
  ADM's own ACTIVE codes (user-code types `LEADSRC` / `LEADTYPE`, 🔒 1710b),
  which core publishes into `ERP_LookupValues` (types `BdLeadSources` /
  `BdLeadTypes`) from the ADM connection's own `lookup_code_lists` config.
  A NEW quote copies each from the account's newest quote holding a value the
  picker still offers (rc78, per field since rc81); otherwise the seller picks.
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
- **Project** is each quote LINE's own since rc117 (owner ruling, 2026-10-26:
  parts on one quote can be for different projects): `Products.bd_project_id`,
  a picker of ADM's active projects (`BdProjects`), blank by default, editable
  in the quote line grid and on the line's record view, and never filled by a
  save. The quote-level `Quotes.bd_project_id` is on no quote view and no form
  prefills it; its column and values stay. The install that upgrades from below
  rc117 copied each quote's own Project, once, onto its lines whose Project was
  blank (never over a line's own). Before rc117 it was one picker per quote
  (rc106 optional, rc108 never refilled on save, rc110 the button's quote took
  the account's newest one). ADM needs a Project on each order line, so the
  Bench connector extension names a missing one at Submit Order.
- **Marketing Campaign / Marketing Event (G460)** are pickers the SELLER fills.
  ADM defines no default event (`DefMktgEvntSeq` 0 and `isDefault` false
  everywhere, measured 2026-09-25), so the only prefill is the account's
  history: a NEW quote copies the pair from the account's newest quote holding
  an offered campaign and its own event (rc81). Their rows are ADM's own
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
ERP company (ERP-Epicor's `ErpQuoteFacts::companyCode`) is published by the Bench
connector extension as an active `ERP_LookupValues` row of type `BdAdmCompanies`
(rc97, extension 1.0.26+). The extension publishes exactly the companies its
`SUGARAI_BD_ADM_COMPANIES` names, the same setting that registers its own ADM
refusals, so the quote defaults, the Ship Via refusal, the FOB / Ship Via
markers and the extension's send-time refusals decide from one answer. Before
rc97 this side inferred ADM from the companies with published `BdLeadSources`
rows, which core publishes for any connection with those codes. The type has no
label in `erp_lookup_type_list`: nothing picks it, and Sugar stores and filters
an enum value without one. **Install after the extension has published:** rc97
on a tenant where no `BdAdmCompanies` row exists yet treats no company as ADM's
(no ADM defaults, no Ship Via marker or refusal on the Sugar side) until the
extension's hourly `benchdogs_adm_companies_<co>` run lands the row.

**The defaults hook runs on every Quote save**, so it exits, cheapest first,
before loading anything for a quote it cannot touch: already in the ERP; nothing
to fill (Reference set, and not a new quote); the extension publishes no ADM company (one query per request);
ERP-Epicor without `ErpQuoteFacts` (logged); not an ADM company.

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

> **🔒2173b (owner, 2026-09-30): "remove all the one offs I dont want that code".** Every one-off package
> (`ONEOFF-RetireBdResidue`, `ONEOFF-MoveBdCustomerGroup`, and the three under `archive/`) was withdrawn and
> its code deleted, mirroring erp-integration-sugar #172, whose `mlp_lint` MLP023 now refuses any `ONEOFF-*`
> package. The rows and steps below that name the one-off are kept as the record of what was done. Module
> Loader never deletes a file a later build stops shipping, so a tenant the one-off never ran on keeps
> whatever it left; that is the accepted state under the ruling.

| Removed | Now owned by | Evidence the removal changes nothing a seller sees |
|---|---|---|
| `custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php` | **Partial Fulfillment ≥ 1.0.41** — same path, same class, same all-alternative preserve gate (G282 / 🔒 1511) | `scripts/tests/test_governing_contribution.py` runs every scenario through rc68's Bench body and PF 1.0.43's; the amount ERP-Core writes is identical in all of them. One recorded divergence: a failed line-role READ (rc68 preserved, PF answers from the quote total) |
| post_install's write of `erp_integration.partial_order_sales_stage` | **Partial Fulfillment ≥ 1.0.43** — the same value, `'Partial Production Ordered'`, as a reader-side default (G305 / 🔒 1519) | a tenant that already has the row keeps it; one without it behaves as though it had been written |
| the Bench Dogs Quotes panel removal (K-2), the `bd_governing_origin` marker removal (K-3), the `zz_bd_stage_doms` fragment removal (K-5), and their helper classes `BdQuotesLayoutExtensions` / `BdOpportunitiesLayoutExtensions` | **the one-off `ONEOFF-RetireBdResidue`** (🔒 1521; *withdrawn with its code, 🔒2173b*) | spent: the one-off ran on every QA tenant — et 1.0.0 2026-09-22 22:22Z; stock and Ophir 1.0.1 2026-09-23 00:58Z; failed 0 (G234 CLOSED) — and nothing re-adds any of them |
| the 39 emptied `custom/Extension` stubs (retired `bd_*` vardefs, labels, hook registrations, the stage style, the country lookup label, the orphan `LBL_RECORDVIEW_PANEL_BENCHDOGS`) and the `BdKineticOpportunityHook` tombstone | **the one-off** — it DELETED each Extension path and BLANKED the tombstone (*withdrawn, 🔒2173b*) | every path was on the one-off's worklist until 🔒2173b; `scripts/tests/bd_retirement.py` now asserts only that none ships again |
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
   *Withdrawn (owner 🔒2173b): the one-off's code is deleted. Skip this step.*
3. Not covered by either, and inert: `BdQuotesLayoutExtensions.php` and
   `BdOpportunitiesLayoutExtensions.php` stay on an upgraded tenant's disk with
   nothing calling them. (*The one-off was withdrawn, 🔒2173b; nothing removes them.*)

Install order is unchanged: **ERP-Epicor → Partial Fulfillment ≥ 1.0.43 → Bench
Dogs last.** The manifest refuses an older PF (`ERR_UW_NO_DEPENDENCY`).

Earlier revisions of this README described the retired quote mirror, the
estimating notification hook, the governing-line rollup and the release-stage
policy. All of that code is gone; the history is in git.

## Build

```bash
# from this directory, in a full git clone (uses the version file):
php pack.php

# or with php 8.2 via docker if none is local: the container sees no git
# history, so it is given the date git would give
docker run --rm -e SOURCE_DATE_EPOCH="$(git log -1 --format=%ct -- version)" \
  -v "$(pwd)":/work -w /work composer:2 php pack.php
```

The installable zip lands in `releases/sugarai_benchdogs_ext-<version>.zip`.
Release zips come from CI's `mlp-package` artifact, never a local build.

The zip is dated by the commit that last changed `version`, the commit that
released the version: the manifest's `published_date` (UTC) and every entry's
stored time. Entries are in name order and stored as 0644 files, so every build
of one version from an unchanged tree is the same bytes and the same sha256.
A shipped change made without a version bump shows up as a second sha256 for
that version. When git cannot give the date (no history, a shallow clone, a
version file that differs from its commit, a version typed on the command line)
`pack.php` refuses; set `SOURCE_DATE_EPOCH` to the seconds since 1970 to date
the build yourself.
`scripts/post_execute.php` places the two customer-group fields and rebuilds
Accounts. It runs once per install, as the `post_execute` installdef; it is not
named `scripts/post_install.php` because Sugar runs that reserved path a second
time, outside the installer (G294).
