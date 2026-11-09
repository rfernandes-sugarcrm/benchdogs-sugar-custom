# 0.9.46-rc131 — the order-line category fields finally load (vardef filed under the right dictionary key)

rc131 = rc130 + one fix. rc129 and rc130 shipped `ERP_OrderLines` Sales Category and Product Category under
`$dictionary['ERP_OrderLines']` (the module name); ERP-Core's bean and dictionary key is `ERP_OrderLine`, so Sugar
silently ignored the vardef and the fields installed cleanly but never loaded. rc131 files all six definitions under
`ERP_OrderLine`. A guard test (`test_vardef_dictionary_keys.py`) now fails any vardef extension filed under a key that
is not its module's object name. Every other Bench vardef extension already used the right key.

# 0.9.46-rc130 — the order line's Product Category relates to the standard ProductCategories module

rc130 = rc129 + this change only. Install order on a Bench tenant: unchanged.

- **What changes.** `ERP_OrderLines.e_product_category_c` (read-only, same place in the record view) now relates to the
  standard `ProductCategories` module, which ERP Core already syncs company-scoped, through the new relationship
  `erp_orderlines_prodcat` (join table `erp_orderlines_prodcat_c`). Quote lines add nothing: Products already carry the
  out-of-box category link. Sales Category stays on ERP_LookupValues.
- **Removed.** The rc129 `BdProductCategories` lookup relate (`erp_orderlines_product_cat`), the
  `BdProductCategories` type label, and `BdAdmRules::TYPE_PRODUCT_CATEGORIES` / `productCategoryOptions`.
- **Upgrade.** The old join table `erp_orderlines_product_cat_c` is not dropped: its rows stay, unread. Order lines show an
  empty Product Category until the connector writes the ProductCategories id through the new relationship.
- **Tests:** footprint KEPT list names the new metadata file in place of the old one.

# 0.9.46-rc128 — the Sales Category picker and read-only ERP Order Line categories (SUG-17)

rc128 = rc127 + these changes only. Install order on a Bench tenant: unchanged (ERP-Epicor → PF → rc128).
Pairs with the connector extension that publishes the BdSalesCategories and BdProductCategories values
(`erp_adm_sales_categories`, `erp_adm_product_categories`, `erp_adm_order_lines`).

- **Sales Category picker:** `Products.e_sales_category_c` becomes an enum over `BdSalesCategories`
  (`BdAdmRules::salesCategoryOptions`). The field name is the one the connector reads and writes.
- **Read-only order-line categories:** `ERP_OrderLines.e_sales_category_c` and `e_product_category_c`, enums over
  `BdSalesCategories` and `BdProductCategories`, read-only in the vardef and in the record view. Kinetic is the only
  writer (the connector's `erp_adm_order_lines`). Owner-approved 2026-11-09 (the ERP_OrderLines guard exception).
- **Labels:** `BdSalesCategories`, `BdProductCategories`, and the field labels.
- **Tests:** footprint KEPT list updated for the new files; the decision-59 test names the approved ERP_OrderLines
  fragments alongside the approved ERP_Orders fragments.

Uninstall: the package adds no tables; its `_cstm` columns stay in the database (pre/post_uninstall scripts).

# 0.9.46-rc127 — the line Project copy reports ok on a Sugar that never had the quote-level Project

rc127 = rc126 + one fix: the one-time line Project copy no longer fails on a Sugar whose quotes table has no
`bd_project_id` column yet. No field, column, setting, hook, layout, list key or data change.

- **What was wrong.** A first install of rc125 on a Sugar that never had this package logged a step report of
  `8/9 applied; NOT APPLIED: line_project_copy -> FAILED: Doctrine\DBAL\Exception\InvalidFieldNameException: An
  exception occurred while executing a query: Unknown column 'quotes.bd_project_id' in 'field list'`. The quote-level
  Project (`Quotes.bd_project_id`) is this package's vardef on the quotes table, and on such a Sugar Module Loader adds
  its column only in its own repair, after the install script, so the copy read a column that did not exist yet. No
  quote could hold a Project there, so nothing needed copying, but the report alarmed whoever installed it.
- **The change.** `BdLineProject::copyFromQuotes()` now reads the quotes table's column list, after the line vardef
  check (which still refuses when that vardef is missing). When the list names the quotes' `id` and not
  `bd_project_id`, the copy reads no quote or line, writes nothing, adds no column (Module Loader's repair adds both
  columns after the script), and reports every count at 0 with its reason. package_install.log then says
  `BenchDogs-Ext: line Project copy: nothing to copy: the quotes table has no bd_project_id column yet, so no quote
  holds a Project`, and the step report reads `line_project_copy` ok, 9/9 applied.
- **Only a definitive answer skips.** An empty column list, or one that is not an array, is not taken as "no column":
  the copy goes on to the quote read as in rc126, which copies, or fails and is named in the step report. The copy is
  due once per tenant (from Module Loader's history), so refusing early would lose a copy that the read could have
  made. The products column check is different on purpose: it refuses an unreadable list, because it decides whether
  to add a column.
- **Unchanged.** On a quotes table that has the column (any Sugar that had an earlier release of this package), the
  copy, its counts, its summary and its once-per-tenant rule are rc126's. On a tenant already at rc117 or later the
  copy is not due, as before. The release named for the one-time steps stays rc117. `post_execute.php` is unchanged.
- **Install order:** rc127 as an upgrade over rc126 (ERP-Epicor, Partial Fulfillment and BenchDogs-UAT-Config
  unchanged), or as a first install. No Quick Repair, no data change, no sync.
- **What the live check must show.** On a Sugar that never had this package (no `quotes.bd_project_id` column before
  the install): after a first install of rc127, the `benchdogs_ext` `install_report` setting and package_install.log
  read 9/9 applied with no NOT APPLIED, and the log has `line Project copy: nothing to copy: ...`. On an upgrade over
  rc126 the copy is not due (`not due (it ran with an earlier install)`), and the report reads 9/9 applied.
- **Tests** (committed first, red on rc126).
  - `bd_line_project_test.php`: 57 checks (+10, section F). The fake quotes table now has its own columns, listed by
    `get_columns` in the shape Sugar's MysqlManager returns, and a quote read naming a column it lacks throws
    Doctrine's `InvalidFieldNameException` with the message the real install logged. Red on rc126: F1-F6, 6 of 57
    (the copy threw `Unknown column 'quotes.bd_project_id' in 'field list'`, added the products column first and read
    the quotes). Green on rc126 on purpose: F7-F9 (an empty or non-array column list still copies when the column is
    there, and throws when it is not) and F10 (the column there and no quote holding a Project keeps the usual
    summary). C1-C16 are unchanged and now run over a quotes table that has the column.
  - 8 source mutants (the check deleted, inverted, an empty or non-array list taken as no column, the `id` test
    dropped, the reason dropped from the summary, the summary ignoring the reason, the products column added before
    the check, the products table read instead of the quotes table): all 8 caught.

# 0.9.46-rc126 — the Opportunity stage list keeps an admin's Dropdown Editor edits

rc126 = rc125 + one fix: a stage an administrator adds or relabels in Admin > Dropdown Editor now stays. No field,
column, setting, hook, layout, list key or data change.

- **What was wrong.** On production the owner added "Priced" to the Opportunity Sales Stage list (`sales_stage_dom`)
  in the Dropdown Editor, and it never appeared. Sugar's Dropdown Editor saves the whole list it shows as a plain
  language fragment, `custom/Extension/application/Ext/Language/en_us.sugar_sales_stage_dom.php` (the probability
  list is its own dropdown and its own file, `en_us.sugar_sales_probability_dom.php`), and rebuilds the languages.
  Plain fragments run before every `_override_` one, so this package's `_override_en_us.bd_sales_stages.php` ran
  after the admin's list, and up to rc125 it assigned both whole lists, replacing the admin's at every rebuild.
- **The change.** The fragment now merges with the list already there:
  - every stage an admin added is kept, with its label, right after the nearest earlier stage of this package's (one
    that comes before the next of this package's stages, so a key another template moved to the front is not used);
  - an admin's label or probability on one of this package's stages is kept;
  - each of this package's ten stages that is missing is added at its place, with its label and probability;
  - an admin's stage gets the probability the admin gave it in the probability list, and none when it has none;
  - a probability whose key is not in the stage list is dropped, as before.
  Sugar's and ERP-Core's labels on this package's keys ("Prospecting", "Proposal/Price Quote", "Proposal-Quoting")
  and Sugar's 65 for Proposal/Price Quote are not an admin's choice, so this package's own replace them.
- **Unchanged.** On a tenant where no admin edited either list, the list and probabilities are exactly rc125's ten
  (the README table). The style fragment is unchanged: it adds a style only where a stage has none, and Sugar's own
  Dropdown Editor gives an added stage a style when it saves.
- **What this package cannot keep, and why.**
  - When ERP-Epicor is installed after the admin's edit, ERP-Core's stage template runs after the admin's list and
    moves Prospecting, Proposal/Price Quote, Closed Won and Closed Lost to the front with its own labels before this
    fragment runs. Their places come back here, but an admin's label on one of those four is gone by then and shows
    this package's label. An admin's stage placed right next to one of those four can land on its other side. An
    ERP-Epicor install appends to its language file rather than replacing it, so a tenant whose file still holds a
    copy of the template from before ERP-Epicor 1.1.102 (it assigned a closed list) also loses an admin's own
    stages at that point, until the admin saves the list again. The fix belongs in ERP-Epicor: a template that
    merges, and an install that stops the older copies from running.
  - Partial Fulfillment's fragment sets the labels and probabilities of Prototype Ordered and Partial Production
    Ordered every time, so an admin's label or probability on those two is replaced by Partial Fulfillment. A
    separate Partial Fulfillment change.
  - An admin who reorders this package's own stages gets them back in this package's order; added stages keep
    their place.
- **"Priced" on production.** After the install, Priced appears where the owner put it. It has no probability until
  one is set in the Dropdown Editor's `sales_probability_dom` list (key `Priced`); which value is the owner's call.
  Nothing moves an Opportunity to Priced.
- **Install order:** rc126 as an upgrade over rc125 (ERP-Epicor and Partial Fulfillment unchanged). The install
  rebuilds the languages and clears the language caches itself, so no Quick Repair is needed; reload the browser tab
  after it. No data change, no sync.
- **What the UAT check must show.** In Admin > Dropdown Editor, add a stage "Priced" between Estimating and Quote
  Delivered to `sales_stage_dom` and save: the Sales Stage list on an Opportunity shows it there, with the other ten
  stages, labels and order unchanged. Relabel Estimating and save: the new label shows. Give Priced a probability in
  `sales_probability_dom` and save: choosing Priced sets it. A tenant with no edit shows exactly rc125's list.
- **Tests** (committed first, red on rc125).
  - `test_stage_list_keeps_admin_edits.py` (12 cases): the admin's files written in the Dropdown Editor's exact shape
    and merged with ERP-Core's and Partial Fulfillment's real fragments in Sugar's orders (the admin's file newer or
    older than ERP-Core's, Partial Fulfillment before or after this package). Red on rc125: an added stage and its
    probability, a stage with no probability, two relabels, a changed probability, a stage after Prospecting when
    ERP-Core's template ran last, and the shipped-values table. Green on rc125 by design: no admin list gives exactly
    the rc125 list and probabilities, a deleted stage comes back at its place, Partial Fulfillment's two stages keep
    their values. The shipped-values table is derived from Sugar's list, ERP-Core's template and Partial
    Fulfillment's fragment and compared both ways.
  - `test_bench_opportunity_stages.py` (12 cases) unchanged and green.
  - 7 source mutants (no add-missing step, no shipped-value table, added stages dropped, this package's probability
    always, anchor on the nearest earlier stage only, 0 for a stage with no probability, a default probability kept
    as Sugar's string): all 7 caught.

# 0.9.46-rc125 — the Opportunity stage On Hold, at 5%, before Closed Won

rc125 = rc124 + one Bench Dogs ask (Lynn's, relayed by the owner on 2026-11-03): an Opportunity sales stage On Hold. One
stage key and its style; no field, column, setting, hook, layout or data change.

- **What Bench Dogs asked** (verbatim): "Add Opp Sales Stage 'On Hold' to match Quote Sales Stage. Since we have to
  assign a probability, and it has to be unique per stage, I'd go with 5% since that will keep it in pipeline reports
  without inflating the pipeline for an Opp that isn't progressing and might not come through. Placement in the sales
  list controls where it appears in pipeline charts, so I'd put it at the end, before Closed Won and Closed Lost, so it
  won't sit in the middle of the funnel."
- **The change.** `_override_en_us.bd_sales_stages.php` adds the key `On Hold`, label "On Hold", probability 5, to
  `sales_stage_dom` and `sales_probability_dom`, after Partial Production Ordered and before Closed Won. The key is the
  Quote stage's own: Sugar's `quote_stage_dom` key `On Hold`, which ERP-Core's quote stage template keeps. The list
  now, in order:

  | # | key | label | probability |
  |---|---|---|---|
  | 1 | Prospecting | Qualifying_Designing | 10 |
  | 2 | Estimating | Estimating | 20 |
  | 3 | Proposal/Price Quote | Quote Delivered | 25 |
  | 4 | Negotiation/Revision | Negotiation/Revision | 50 |
  | 5 | Verbal commit/PO Pending | Verbal commit/PO Pending | 75 |
  | 6 | Prototype Ordered | Prototype Ordered | 80 |
  | 7 | Partial Production Ordered | Partial Production Ordered | 90 |
  | 8 | **On Hold** | **On Hold** | **5** |
  | 9 | Closed Won | Closed Won | 100 |
  | 10 | Closed Lost | Closed Lost | 0 |

  Every probability is used by one stage only. Choosing On Hold sets Probability to 5 through Sugar's own
  `probability` formula (`getDropdownValue("sales_probability_dom", $sales_stage)`).
- **Its style.** Opportunities draws the stage as a formatted enum, which shows a key with no style blank. Sugar styles
  On Hold for quotes and orders, not as a sales stage, so `bd_sales_stage_style.php` adds one, only where the tenant has
  none for that key: slate `#E2E8F0`, text `#1E293B`, Sugar's `clock` icon, so a paused deal does not look like the
  yellow Quote Delivered or Prototype Ordered.
- **Unchanged.** Every other stage keeps its key, label, order and probability. No Opportunity is remapped and there is
  no data migration. ERP-Core's one-time stage remap names only six retired stock keys, never On Hold.
- **No automation.** Nothing moves an Opportunity to or from On Hold; it is a seller's choice only. The code that sets
  an Opportunity's stage today, all unchanged and none reading a quote's On Hold:
  - the Bench connector extension: an Opportunity it made for an Epicor-born quote moves from an open stage to Closed
    Won or Closed Lost when its quote is Closed Accepted or Closed Lost. On Hold is an open stage, so such an
    Opportunity on hold still follows its quote when the quote closes; a quote at any other stage leaves it alone;
  - Partial Fulfillment (`ErpOpportunityValuation`): an order moves the quote's Opportunity to Prototype Ordered,
    Partial Production Ordered or Closed Won, and a quote that lost every order to Closed Lost. It never reopens Closed
    Won or Closed Lost; an Opportunity on hold whose quote is ordered moves like any open one;
  - ERP-Core's order-stage cascade (`OrderStageOpportunityCascade`) is retired and runs from no hook.
- **Forecasts.** Nothing in Forecasts changes. Sugar sets an Opportunity's commit stage from its probability
  (`commit_stage` = `forecastCommitStage($probability)`), and no package of ours sets the ranges. Sugar's defaults put
  0 to 69 in **Exclude** in every range mode (binary: Include 70-100, Exclude 0-69; buckets and custom buckets: Include
  85-100, Upside 70-84, Exclude 0-69), so On Hold at 5 lands in **Exclude**, beside Closed Lost. It is not a won or
  lost stage (Forecasts' won and lost stages stay Closed Won and Closed Lost), so it counts as open. A tenant whose
  admin changed the ranges puts 5 wherever its own lowest range is; the tenants' settings were not read for this note.
- **With ERP-Epicor's Likely from Forecasted Likely** (Likely = probability / 100 x Forecasted Likely, a separate
  ERP-Epicor release): an Opportunity on hold shows Likely = 5% of its Forecasted Likely.
- **Install order:** rc125 as an upgrade over rc124 (ERP-Epicor and Partial Fulfillment unchanged). Reload the browser
  tab after the install. No data change, no sync.
- **What the UAT check must show.** On an Opportunity, the Sales Stage list shows On Hold between Partial Production
  Ordered and Closed Won; choosing it and saving gives Probability 5 and a drawn On Hold pill (slate, clock icon), not a
  blank stage; Commit Stage reads Exclude on a tenant with Sugar's default ranges; every other stage keeps its label,
  place and probability; existing Opportunities keep their stages.
- **Tests** (committed first, red on rc124).
  - `test_bench_opportunity_stages.py` (12 cases, +3): Sugar's merge executed over ERP-Core's and Partial
    Fulfillment's real fragments in three install orders. New: On Hold at 5 right after Partial Production Ordered and
    right before Closed Won then Closed Lost; every served probability unique; without On Hold the served list is
    exactly the rc124 list. The table and style cases now carry On Hold. 7 subtests red on rc124.
  - `test_stage_dropdown_style.py`: every shipped fragment executed over a seeded style map styles only this
    package's four stages, On Hold included. Red on rc124.
  - 12 source mutants (On Hold at 90 or 10, after Closed Lost, before Partial Production Ordered, key `on_hold`, label
    "Hold", no probability, no stage, Estimating at 15, Quote Delivered relabelled, two stages swapped, no style): all
    12 caught.

# 0.9.46-rc124 — same content as rc123, a new version so an install copies every file again

rc124 = rc123 with only the version changed: no code, field, label, layout, list or setting is added or changed.

- **Why.** Sugar refuses to install a version that is already installed. On a Sugar where some of this package's
  installed files went missing (for example the ERP Orders field that ties an order to its Opportunity, while the
  Opportunities side of that link is still there), Quick Repair and Rebuild cannot bring them back, because it
  rebuilds from the files on disk. Installing rc124 over rc123 is an ordinary upgrade: it copies every file of the
  package again and rebuilds, so the missing fields and their relationship come back. Data is untouched.
- **Where it is needed.** Only a Sugar that lost files. A Sugar where rc123 is whole can stay on rc123; the two are
  the same package.

# 0.9.46-rc123 — a new quote's owner also follows the top Account Name

rc123 = rc122 + one fix to rc121's account owner default (Lynn's SUG-13), found when rc121 was graded. No field,
label, list or setting is added.

- **What was wrong.** On the Quotes create form, Assigned To took the account's owner only when the seller picked the
  **Billing Account Name**. A seller who filled only the top **Account Name** (Sugar's own Quotes `account_name`
  relate: id `account_id`, link `billing_accounts`; the Bench UAT record layout draws it) stayed the owner themselves,
  on the form and on the save.
- **The change.**
  - The create form (`bd-account-owner.js`, still one file shipped in Quotes and Opportunities): a quote's account is
    its Billing Account, else its top Account Name, else the account whose subpanel the form was opened from. A change
    of either field is followed. With both set, the Billing Account decides, as on the server.
  - The server (`BdAccountOwner`, before_save on a create): on Quotes, `billing_account_id`, else `account_id` when
    the billing id is empty, else the subpanel link as before. Opportunities are unchanged (`account_id`).
  - Unchanged: a person's own pick is never replaced, an owner the form came with is kept, an update and the
    integration's own save are never touched, and only an Active owner is used.
- **Install order:** rc123 as an upgrade over rc122 (ERP-Epicor 1.2.4 or later, unchanged; BenchDogs-UAT-Config 1.0.2
  accepts it). Reload the browser tab after the install. No data change, no sync.
- **What the UAT check must show.** On a new quote, picking only the top Account Name of an account owned by another
  active seller puts that seller in Assigned To, and the saved quote is theirs; picking a Billing Account afterwards
  gives that account's owner; a seller who picks Assigned To themselves keeps it.
- **Tests** (committed first, red on rc122).
  - `bd_account_owner_test.php` (34 checks, +6): section Q, only `account_id` gives the account owner, an empty billing
    id falls back to it, the billing account decides when both are set, and the update, other owner, pick, integration
    and ineligible-owner controls. 3 red on rc122.
  - `account_owner_on_real_sidecar.cjs` (16 tests, +3) on Sugar's own Sidecar 26.1.0: the top Account Name alone fills
    its owner; a Billing Account picked later decides, and clearing it falls back; a pick stands. 2 red on rc122.

# 0.9.46-rc122 — the quote's Project Name: Kinetic's Quote Comment on the quote and its PDF

rc122 = rc121 + one Bench Dogs UAT ask (Lynn's SUG-14) and one new quote field, approved by the owner.

- **What Bench Dogs asked.** They keep a quote's project name in Kinetic's `QuoteHed.QuoteComment`, and want it on the
  Sugar quote as "Project Name" and printed on the quote PDF's "Project:" row (empty since rc112).
- **Why a new field, not `erp_quote_comment`.** On Bench Dogs' connection `erp_quote_comment` holds Kinetic's Web comment
  (`ECCComment`: the seller's Comment Log thread and the estimator's replies, and the create marker), because the Bench
  connector extension declares core's `web_comment` mapping. `QuoteComment` reaches no Sugar field before this release,
  and on this connection the connector writes nothing of its own there.
- **The field.** `Quotes.e_project_name_c`, type **`text`** (Studio's TextArea; a MySQL `TEXT` column), label
  **Project Name** (`LBL_E_PROJECT_NAME_C`), not required, no default. Created by the install where a tenant lacks it,
  the same way as the other Kinetic quote fields (`BdKineticFields`, through Studio's own field code); never altered
  where a tenant has it; uninstall leaves it with its data. Why `text`: `QuoteComment` is free text with no declared
  length (the connector's model declares it a plain `str`), ERP-Core's own copy of a quote comment
  (`erp_quote_comment`) is `text` too, and Sugar's REST API refuses a `text` value over 65,535 bytes rather than
  cutting it short, so nothing is truncated silently. A `varchar` would cut a long comment at its length.
- **Where it is drawn.** On the quote record view, so on the create form (which draws the record view's panels):
  once, editable, on a row of its own (`span` 12, `BdFieldPlacement::FULL_ROW`) right after the Due (`FOLLOWS`). On the
  Bench UAT layout the body now reads Purchase Order Num | Quote Stage, **Valid Until | Due**, **Project Name**, Best
  Case | Confidence, Primary Quote: every row rc121 laid out keeps its pair. On ERP-Epicor's own layout, the same after
  Valid Until | Due in the body. A row of its own takes no filler cell further down. Where the view draws no Due, it
  follows Valid Until (then Best Case, then Confidence); with none of them, it sits in the ERP panel above the status
  block. One a stored layout already draws stays where it is, as for the other quote fields (one stored in the ERP panel
  is placed again, as the Due is). No other quote view changes (dashlet, list preview, list), and BenchDogs-UAT-Config
  is unchanged.
- **The PDF.** The Bench Dogs Quote template's Project row prints `{$fields.e_project_name_c}` (line breaks kept, as
  PDF Manager hands a text field over through `nl2br`); empty for a quote with none. The install brings the template to
  this body (post_install step `quote_pdf_template`, as in rc112); a renamed template keeps its name.
- **The two-way sync is the connector's.** The Bench connector extension maps `QuoteHed.QuoteComment` to and from
  `e_project_name_c` (a separate change there). This package only adds the field, draws it and prints it.
- **Install order:** rc122 as an upgrade over rc121 (ERP-Epicor 1.2.4 or later, unchanged; BenchDogs-UAT-Config 1.0.2
  accepts it), **before** the extension release that maps the field: the hub refuses a record naming a field the tenant
  lacks. Reload the browser tab after the install. No data change, no sync: each quote shows an empty Project Name until
  the extension's next read of it in Kinetic.
- **What the UAT check must show.** On a saved quote and on a new one: Project Name once, under Valid Until | Due, the
  full width, editable, saved with the quote; Best Case and Confidence still side by side. Once the extension reads the
  quote, it shows Kinetic's Quote Comment, and the quote PDF's Project row prints it; a quote with none prints an empty
  row. The quote's Comment Log is unchanged.
- **Tests.**
  - `bd_kinetic_fields_test.php` (60 checks, +7): the field's spec, a tenant that has it, the counts (24, 5 on Quotes),
    the placement after the Due in the ERP-panel fallback and on the Bench UAT layout, an admin's own Due or Project
    Name, a Due in a fieldset, no filler taken. 17 red on rc121.
  - `bd_expires_date_off_test.php` (33 checks, +4): R13 to R16, the shipped overlays over the stored Bench UAT record
    view and ERP-Epicor's own layout; R5 and R12 now name the new cell. 5 red on rc121.
  - `bd_quote_pdf_test.php` (98 checks): the Project row's source, and never the quote comment. 1 red on rc121.
  - `bd_quote_pdf_render_test.php` section I (5 checks): rendered by Sugar's Smarty, the Project row reads the Project
    Name, two lines as two, an ampersand as one, empty for none. 2 red on rc121.
  - Mutants, each red: not on the record view, read-only, not after the Due (left above the status block), moved
    before the Due moves, half a row wide, a row of its own taking a filler cell, created as a varchar 255, labelled
    "Quote Comment", not created; the PDF's Project row empty again, printing the quote comment, or without its guard.

# 0.9.46-rc121 — Expires Date off the quote, the Due beside Valid Until; new Opportunities and Quotes owned by the account owner

rc121 = rc120 + two Bench Dogs UAT asks. No field, label, list or setting is added. A third ask, Kinetic's Quote Comment
shown as the quote's Project Name, is not in this release: it needs a new field (below).

- **Valid Until is Kinetic's expiry; Expires Date leaves the quote** (Lynn's SUG-12: Sugar's Valid Until and Kinetic's
  `QuoteHed.ExpirationDate` are the same date for Bench Dogs, and the Sugar Expires Date field is not wanted).
  - **Measured from the connector's code, not changed here.** Core carries Valid Until (`date_quote_expected_closed`)
    to `ExpirationDate` and back: `connector_core/erp_adapters/epicor/quote_valid_until.py` writes a seller's Valid
    Until to `ExpirationDate` once Kinetic marks the quote Quoted (Kinetic refuses an expiry before that, so the
    seller's date is held and sent then), and `transformers/quotes.py` (`quote_valid_until`) reads `ExpirationDate`
    back into Valid Until. So the second date on the quote only repeats Kinetic's.
  - **The change.** `BdFieldPlacement::QUOTE_TAKEN_OFF` names `e_expiration_date_c`, and `takenOff()` (a view overlay
    at every metadata build) takes its cells off the quote's record view (so the create form, which draws the record
    view's panels), the record dashlet, the Quotes list preview and the Quotes list view with its column chooser,
    wherever a stored layout draws it (the Bench UAT layout draws it in all four). Each cell leaves its list and the
    cells after it move up; one inside a fieldset an administrator built is left. The overlay no longer places it.
  - **The field and its data stay.** The install still creates it on a tenant that lacks it, and never relabels or
    deletes it: the Bench connector extension still writes Kinetic's `ExpirationDate` into it (its quote read-back),
    and the hub refuses a record naming a field the tenant lacks. It stays filterable (filters are not changed), and
    BenchDogs-UAT-Config's stored files are not changed (no UAT-Config release).
  - **The Due right after Valid Until** (it followed Expires Date since rc120). On the Bench UAT layout the body now
    reads Purchase Order Num | Quote Stage, then **Valid Until | Due**, then Best Case | Confidence, then Primary
    Quote; the body's trailing empty filler cell gives the Due its place. On ERP-Epicor's own layout, the same right
    after Valid Until in the body, with Best Case and Confidence above the status block. With no Valid Until drawn, the
    Due follows Best Case, else Confidence, else stays above the status block. A Due a Studio save stored where rc119
    (the ERP panel) or rc120 (right after Expires Date) drew it moves too; one an administrator placed elsewhere stays.
    Once, editable, not required, on the record view and the create form.
- **A new Opportunity or Quote is owned by its account's owner** (Lynn's SUG-13: an assistant entering a quote became
  its owner, so the PDF's Sales Person named the assistant).
  - **The server's half.** `BdAccountOwner`, a before_save hook on Opportunities and Quotes (priority 20): a record
    being created whose owner is the user saving it, or no one, takes its account's owner (the Opportunity's account;
    the Quote's billing account, or the account whose subpanel it was created from) when that owner is another
    Active user. Never on an update; never on the integration's own saves (the connector's platform: rc99's default
    owner rule stays theirs); never when the create form reports that the person picked the owner; never over
    another owner (a copy keeps its own). A failed read leaves the owner and the save goes on.
  - **The create form's half.** `bd-account-owner`, a view that draws nothing (the same controller shipped in each
    module, `custom/modules/{Quotes,Opportunities}/clients/base/views/bd-account-owner/`), added right after Sugar's
    create view in the Quotes and Opportunities create layouts (layout overlays; no stock or ERP-Epicor file is
    replaced). On a
    new record it reads the account's owner and whether that user is Active, and puts the owner in Assigned To where
    Sugar's create view put the creator: when the form opens with an account (from the account's subpanel, or a quote
    ERP-Epicor fills from its Opportunity) and whenever the account changes. Once the person picks an owner (anyone,
    themselves included) it stops, and the save adds `bd_owner_chosen=1` to its request so the server's half keeps
    that pick. Its own change is not one Cancel asks about.
  - **ERP-Epicor's Create Opportunity & Quote.** Its endpoint gives both records the presser as owner before saving;
    the hook gives both the account's owner (proven on ERP-Epicor's real `createOppQuote`). Its placeholder Revenue
    Line Item (off by default) keeps the presser. Sugar Predict's Recommendation convert gives the recommendation's
    owner, and the account's owner where that is the presser or no one.
  - **The quote PDF.** Its Sales Person, Email and Mobile are the quote's assigned user (`BdQuotePdf`), so they follow.
- **Not in this release: Kinetic's Quote Comment as Project Name** (Lynn's SUG-14). On Bench Dogs' connection the
  quote comment Sugar shows (`erp_quote_comment`) is not `QuoteHed.QuoteComment`: the Bench connector extension
  declares core's `web_comment` mapping, so `erp_quote_comment` holds Kinetic's Web comment (`ECCComment`), the
  seller's Comment Log thread and the estimator's replies, and the create marker goes there too. `QuoteComment`, where
  Bench Dogs keep the project name, reaches no Sugar field. Labelling `erp_quote_comment` "Project Name" would show the
  comment thread, so it needs a new quote field, which this release does not add: the owner's call.
- **Install order:** rc121 as an upgrade over rc120 (ERP-Epicor 1.2.4 or later, unchanged; BenchDogs-UAT-Config 1.0.2
  accepts rc121, its dependency being a minimum). Reload the browser tab after the install; the create view and the
  layouts are served with the metadata, like rc99's Admin page. No data change, no sync.
- **What the UAT check must show.** On a saved quote and on a new one, no Expires Date anywhere on the quote (record,
  dashlet, list preview, list column chooser), and Due beside Valid Until. Signed in as a user who does not own the
  account: Create Opportunity & Quote on the account gives both records the account's owner, and the quote PDF's
  Sales Person names them; a new Quote and a new Opportunity from the create form show the account's owner in
  Assigned To once the account is picked (or at once from the account's subpanel); picking another owner (or oneself)
  before saving keeps that pick after the save; an account owned by an inactive user or no one leaves Assigned To as
  Sugar sets it. An existing record's owner never changes on save.
- **Tests.**
  - `bd_expires_date_off_test.php` (new, 29 checks): the shipped record, record dashlet, list preview and list overlays
    over the views BenchDogs-UAT-Config stores, in Sugar's compile order. 17 red on rc120, all pass on rc121.
  - `bd_kinetic_fields_test.php` (53 checks): rc120's placement checks now want the Due after Valid Until, and a
    Studio save under rc120 moves. 8 red on rc120.
  - `bd_account_owner_test.php` (new, 28 checks): the two registrations, the rule's cases and controls, the two create
    layouts. `bd_button_quote_project_test.php` section O (6 checks): ERP-Epicor's real Create Opportunity & Quote.
    `account_owner_on_real_sidecar.cjs` (new, 12 cases): the create form's half on Sugar's own Sidecar 26.1.0. All red
    on rc120 (O1 the one red in section O; the others are controls).
  - Mutants, each red: the Due following Expires Date again, an rc119 or rc120 Studio save not moved, the overlay
    taking nothing off, the record overlay not taking it off; the rule without its update, platform, pick, active or
    owner guards, without the subpanel link, the layout drawing the view twice; the form's view without its pick,
    stale-answer, Active, subpanel-link, revert, copy or new-record guards, without the reported pick or always
    reporting one, without its Cancel default.

# 0.9.46-rc120 — Cust. Group for every ADM account not in the ERP; a wider Product column; the Due beside Expires Date

rc120 = rc119 + two Bench Dogs UAT asks and the owner's ruling on the quote's Due date. No field, label, list or setting
is added.

- **Cust. Group required on any ADM account not yet in the ERP** (Lynn's SUG-11; the owner's ruling of 2026-10-29:
  required on save for every ADM account not yet in the ERP, whatever its type).
  - **What the seller met.** A Prospect with an ADM company saved with no Cust. Group (the rule asked only an account
    typed Customer). Its quote's Send to Estimation then created the account in Kinetic, and the connector refused it:
    the account "requires a customer group ... Nothing was sent to the ERP".
  - **The change.** `Ext/Dependencies/bd_adm_customer_group_required.php` no longer reads the account type: Group Code
    is required in the Accounts edit views on an account with no ERP key (neither `erp_display_sync_key` nor
    `erp_sync_key`) whose ERP company is ADM, a Customer, Prospect, Suspect or untyped one alike. `account_type` is
    off its triggers (the rule no longer reads it). Unchanged: EPIC06 accounts and accounts with no ERP company are
    never asked; an account the ERP holds is never asked (its group is read-only there); it stays an edit-view rule,
    so the field is never served `required` and the sync's own account writes are never refused; the create prompt
    of Get Best Price (ERP-Core's `erp_customer_create_required_formula`) already asked any ADM account, as before.
  - **Release note.** From rc120 a seller cannot save an ADM account that is not in the ERP without a Group Code, a
    Prospect or Suspect included: an existing ADM Prospect with no group needs one at its next save. Existing data is
    not changed, and no sync is triggered. The create form follows the record view's rules in Sugar 26.1
    (`create.js` takes the record view's metadata, dependencies included, before SugarLogic starts), the reading
    rc91's fix of this rule for a new account relied on; it is not proven in a browser here, so the UAT check names
    it (below).
- **A wider Product column on the quote line grid** (Lynn's SUG-10: the column is too narrow, hovering the name does
  not show it all, and in the edit row the full name cannot be seen).
  - **Why it was narrow.** Sugar sets each grid column's width on its header cell from the column's `widthClass`
    (Quotes `quote-data-list-header.hbs`), one fixed width per class (`datatables.less`: `cell-large` 180px min and
    max). Stock draws the Product column `cell-large`; a line's cells carry no width of their own and keep their
    text on one line, cut with an ellipsis. In the edit row the product picker (select2) fills its cell.
  - **The change.**
    `custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list/bd_product_column_width.php`, a
    view overlay at every metadata build (`BdFieldPlacement::gridColumnWidth`), draws the Product column
    `cell-xxlarge`, Sugar's widest list column (460px), on the quote record view and in the line's edit row. Nothing
    else of the column changes (its quote-data-relate picker, required flag, label, related fields), nor any other
    column; the grid scrolls sideways as before when its columns are wider than the page. A grid with no Product
    column, or one the overlay cannot read, is served as read.
  - **What it does not do.** Product names on Bench Dogs' quotes run to about 130 characters, more than any one
    column shows on one line: 460px holds roughly the first 55 to 60, and the rest is still cut with an ellipsis. The
    hover is not changed. Read from Sugar 26.1's code (not measured in a browser): Sugar shows a grid cell's tooltip
    only on an element that is itself cut, with that element's own title, and the stock Product cell puts the full
    name on its outer box while the inner, cut, text has no title, so a cut name likely shows no tooltip. A title on
    the cut text, or a wrapped name, belongs to the field's own controller, which ERP-Epicor ships
    (`custom/modules/Products/clients/base/fields/quote-data-relate/quote-data-relate.js`); this package ships no CSS
    and no file in that directory.
- **The quote's Due date next to the top fields** (the owner's ruling of 2026-10-29; a tester starting a new quote
  could not find Due).
  - **What the seller met.** rc119 draws Due at the foot of ERP-Epicor's ERP panel, right above the status block. The
    Bench UAT layout (BenchDogs-UAT-Config) draws Expires Date, Best Case Percentage and Confidence Percentage in its
    body, so Due sat a long way from them.
  - **The change.** `BdFieldPlacement` (the quote record view overlay at every metadata build) draws Due right after
    Expires Date, wherever the view draws Expires Date: in the body on the Bench UAT layout (the body's empty filler
    cell at its end gives Due its place, so no row is added); in the ERP panel on a layout that draws none of the top
    fields itself, where the overlay places all four (Expires Date, Due, Best Case, Confidence). With no Expires Date
    drawn, Due follows the next top field drawn; with none, it stays above the status block as in rc119. Drawn once,
    editable, not required. A layout a Studio save stored while rc119 drew Due in the ERP panel gets the same move; a
    Due an administrator placed in any other panel, or inside a fieldset, stays where it is.
  - **The create form.** It shows the record view's panels (Sugar 26.1.0's base create view has no panels of its own
    and `CreateView` lays the record view under its own buttons; the Quotes create view and ERP-Epicor's set no
    panels), so Due is on the create form exactly where it is on the record view. It already was in rc119 (at the foot
    of the ERP panel); rc120 moves it there too.
- **Install order:** rc120 as an upgrade over rc119 (ERP-Epicor 1.2.4 or later, unchanged; BenchDogs-UAT-Config 1.0.2
  accepts rc120, its dependency being a minimum). Reload the browser tab after the install. No Quick Repair, no data
  change, no sync.
- **What the UAT check must show.** On a new quote (the create form) and on a saved one, Due is right after Expires
  Date. On an ADM account not in Kinetic typed Prospect, clear Group Code and Save: Sugar
  refuses the save and flags Group Code; pick a group and the save goes through. The same from the Accounts create
  form (a new Prospect with an ADM company and no group). An EPIC06 account and a Kinetic-held ADM account save as
  before. On a quote with a long product name, the Product column is about two and a half times as wide as before,
  in and out of the line's edit row.
- **Tests.**
  - `test_g809_connector_schema_safe.py`: `test_rc120_the_group_rule_reads_no_account_type` (new, runs in CI) reads
    the shipped rule as PHP includes it: the formula is exactly the not-in-the-ERP and ADM clauses, no account
    type, no `account_type` trigger, edit hook, applied on load. The SugarLogic parser cases (SugarEnt 26.1.0's own
    `Parser`, a SugarEnt-tree test, local only) now want an ADM Prospect, Suspect or untyped account not in the ERP
    required, and an ERP-held, EPIC06 or company-less one not. Both red on rc119, green on rc120.
  - `bd_kinetic_fields_test.php` (52 checks, 12 new): the Due right after Expires Date on the default layout and on
    the real BenchDogs-UAT-Config quote layout (once, editable, the filler giving its place, every other panel as
    stored), a Studio-saved rc119 layout, an administrator's own place and a fieldset kept, both fallbacks, twice
    changes nothing, and the create form drawing the record view's panels (the recorded Sugar 26.1.0 create views).
    7 red on rc119's placement, all pass on rc120; five mutants each red (no filler given up, a stored ERP-panel Due
    left, any stored Due moved, no fallback sibling, Due before Expires Date).
  - `bd_quote_line_product_column_test.php` (new, 33 checks, wired into `test_php_suites.py`): over stock Sugar
    26.1.0's recorded grid viewdef and relate field, the Product column drawn `cell-xxlarge` and nothing else
    changed, rebuilds, an admin's own grid, the fail-open cases, the directory's other three overlays compiled with
    it (and, as a control, without it: stock's `cell-large`). 15 of 33 red on rc119, all pass on rc120.

# 0.9.46-rc119 — the quote's Due date

rc119 = rc118 + one new field, approved by the owner's request of 2026-10-27 ("a new field Due on a quote header under
ERP ... it needs to sync both ways and it is not mandatory to fill"): `Quotes.e_due_date_c`, a date labelled "Due".

- **What it holds.** Kinetic's quote Due date (`QuoteHed.DueDate`, the date the quote needs to be quoted by). The Bench
  connector extension 1.0.47 syncs it both ways: an estimator's Kinetic Due date shows here, and a seller's Due goes to
  Kinetic while Kinetic has not changed it since the two last agreed (when both changed, Kinetic's stands and shows
  here). A blank Due saves, and a blank is never sent to Kinetic.
- **How it is made.** The same way as the other Kinetic quote fields (`e_best_case_pct_c` and the rest): the install
  creates it as a Studio custom field where a tenant lacks it (`BdKineticFields`), not required, with no default, and
  never alters it where a tenant has it. Uninstall leaves it, data included.
- **Where it shows.** On the quote record view, editable, in ERP-Epicor's ERP panel right above the ERP status block
  (`BdFieldPlacement`, the view overlay at every metadata build). The Bench UAT layout (BenchDogs-UAT-Config) draws
  the other three Kinetic quote fields in its body and has no Due, so the overlay puts the Due in that layout's ERP
  panel too; BenchDogs-UAT-Config is unchanged.
- **Install order:** the hub core with the two-way date comparison first (sugarcrm/erp-integration-core
  `fix/two-way-date-same-day`), then rc119 as an upgrade over rc118 (ERP-Epicor 1.2.4 or later, unchanged;
  BenchDogs-UAT-Config 1.0.2 accepts rc119, its dependency being a minimum), then the Bench connector extension 1.0.47. Never the extension before rc119: its quote read-back would carry a field this Sugar does not have,
  and the hub refuses such a record. The install's step report names the field under `kinetic_fields` ("created", or
  "kept" where the tenant has it). Reload the browser tab after the install.
- **Release note.** On upgrade, each quote shows an empty Due in its ERP panel until the extension's next read of the
  quote in Kinetic (or a one-time re-read of the quote read-back, the owner's call) fills it. Quotes sent to estimation
  with a blank Due show Kinetic's Due date after their first read-back: the hub's quote create puts the quote's Valid
  Until in Kinetic's Due date. No sync is triggered by the upgrade.
- **Tests.** `bd_kinetic_fields_test.php` (40 checks, 5 new): 10 red over rc118's package (the counts, the Due's type
  and label, its place and its editable entry in the ERP panel, on the real BenchDogs-UAT-Config quote layout too), all
  pass on rc119. Eight mutants, each red: the Due read-only, not placed, a datetime, labelled "Due Date", not created,
  created required, placed below the status block, placed in the body first.

# 0.9.46-rc118 — billing address required in the create form too

rc118 = rc117 + one vardef file. rc117's billing rule reaches only the Accounts record view (Sugar serves this
package's Accounts rule there and nowhere else, and Accounts has no create-view rules), so an account made in the
create form saved with no billing address. ERP-Core's own vardefs already give billing street, city, state, postal
code and country a module-wide required rule that every Accounts view applies when it opens, but only for a Customer
not yet in the ERP. `custom/Extension/modules/Accounts/Ext/Vardefs/_override_zz_bd_billing_address_required.php` sets
that rule's formula to `true` on street, city, postal code and country, and Sugar merges an `_override` file after
ERP-Core's files whichever was installed last. Nothing else changes: the `required` key stays ERP-Core's, so the hub's
schema read and the accounts sync see what they saw before and a server save is never refused; State keeps ERP-Core's
Customer rule; rc117's record-view rule stays, asking the same four. One effect outside this package: ERP-Epicor's
server-side Submit Order check picks the fields a new Customer needs by that formula's exact text, so it no longer
names these four; when an order makes an account a Customer the browser's own check still asks for all five billing
fields first, and the ERP create still refuses an account with no street, city or country (postal code has no server
check left; the browser now asks for it on every save). On upgrade (requires ERP-Epicor 1.2.4 or later, unchanged;
BenchDogs-UAT-Config 1.0.2 accepts rc118 as it is, its dependency being a minimum), run Quick Repair and Rebuild and
reload the browser tab; from then on no account, a Prospect or one Kinetic holds included, can be saved in the browser
with one of the four empty, so a seller editing a Kinetic customer with no billing address fills it in first. No sync is
triggered. Tests: `test_billing_address_required.py` compiles ERP-Core's real billing vardefs (pinned at
erp-integration-sugar 333d43be) with this package's in Sugar's extension order, both install orders; 2 of its 6 tests
are red on rc117 and all pass on rc118, and each mutant is red (no override, a plain file name instead of `_override`,
a field left out, `required` set or cleared, State added, a conditional formula).

# 0.9.46-rc117 — the Project on each quote line; billing address required; Needs Price Estimate by default; Part Number in the catalog; contact Role and Best Case % editable

rc117 = rc116 + the five Sugar-side asks of the Bench Dogs UAT call (2026-10-26). One new field, approved by the
owner's request: `Products.bd_project_id`. No new setting: the Needs Price Estimate default is ERP-Epicor's own
per-tenant setting.

**Install order on a Bench tenant:** rc117 as an upgrade over rc116 (any rc from rc112), with ERP-Epicor 1.2.4 or
later, then BenchDogs-UAT-Config 1.0.2 where the UAT package is installed (1.0.2 requires rc117). Install the Bench
connector extension's per-line Project release in the same window: until it is deployed, the extension still reads the
quote-level Project at Submit Order and Order Selected Lines, which rc117 no longer shows or fills, so an order from a
quote made after the upgrade is refused naming Project ID. No Quick Repair: Module Loader's own repair adds the new
column. Reload the browser tab after the install.

- **Project on the quote line (owner ruling, 2026-10-26).** Parts on one quote can be for different projects.
  - `Products.bd_project_id`: a picker of ADM's active projects (the same list as the quote's,
    `BdAdmRules::projectOptions`), blank by default and never pre-picked, editable, audited. Drawn in the quote line
    grid right after Product Group, and on the quoted line item's record view (its body). The quote fetches it with
    its lines, so the grid shows each line's value.
  - The quote-level Project (`Quotes.bd_project_id`) leaves the quote record, create and dashlet views: its vardef no
    longer carries the placement or prefill markers, a view overlay takes it off any stored layout that still holds
    it, and the before_save hook no longer fills it on a create (rc110's fill is gone). Its column and values stay.
  - **One-time copy.** The install that upgrades from below rc117 copies each quote's own Project onto that quote's
    lines whose Project is blank, never over a line that has one. "Below rc117" is read from Module Loader's own
    history (no setting): during an install the new version's row is still staged and the one it replaces installed,
    so a later install (rc118, or rc117 again) finds rc117 installed and does not copy again. The install adds the
    column itself first (Module Loader's repair runs only after the install script) and writes each line with
    `updateParams`: no logic hooks, no new date_modified, nothing sent to Kinetic. It is idempotent. Its counts are in
    package_install.log (`BenchDogs-Ext: line Project copy: N quotes with a Project, M of their lines: F filled, K kept
    ..., X failed`) and the step report (`line_project_copy`); a failure is named on the install's status line.
- **Billing address required.** An account's billing street, city, postal code and country are required when a
  person saves it in the record or create view (Sugar's own required marking: Save is refused with "Please complete
  all required fields before saving" and each empty field flagged). A view dependency with the edit hook only: it never
  runs on a server save and nothing is served required, so the accounts sync (Kinetic customers with no billing
  address) is never refused, by Sugar or by the hub's own payload check. State is not required.
- **Needs Price Estimate by default.** The Account's Create Opportunity & Quote is ERP-Epicor's; it types its quote
  from ERP-Epicor's own setting `erp_integration.opp_quote_type` (Sales Order when unset). The same one-time install
  step sets it to `advanced_quote` (the Needs Price Estimate switch), so an administrator who sets it back later keeps
  that choice. Uninstall leaves the setting.
- **Part Number in the Product Catalog.** The catalog list and its Search and Select list (the drawer a quote line's
  product field opens) show each product's Kinetic part number (`mft_part_num`, the stored field) as a read-only
  "Part Number" column right after the name. A search by part number already works: ERP-Core makes `mft_part_num` a
  quick-search field of ProductTemplates. Not changed: the quote's Product Catalog dashlet (a tree), whose search is
  Sugar's own ProductTemplateTreeApi over names.
- **Contact Role and quote Best Case % editable.** The Contacts overlay draws `role_c` editable (it keeps the customer
  package's own `contact_role_list`); the other four Epicor contact fields stay read-only. The quote overlay no longer
  marks `e_best_case_pct_c` Kinetic's alone. The hub still writes both in as before: until the Bench connector
  extension sends them back, a seller's edit lasts until the next Kinetic read-back changes it.
- **Release note.** On upgrade: each quote line grid shows a Project column; the quote screen no longer shows Project;
  every line with no Project of its own takes its quote's Project once; new Create Opportunity & Quote quotes start
  with Needs Price Estimate on; account saves need a billing street, city, postal code and country; the Product Catalog
  lists show Part Number; contact Role and Best Case % can be edited. No sync is triggered by the upgrade.
- **Tests.** Red on rc116 (each new or changed file run over rc116's package): `bd_contact_fields_test.php` 4 of 48,
  `bd_kinetic_fields_test.php` 2 of 35, `test_billing_address_required.py` 2 of 4 (the other two guard the failure
  direction), `bd_catalog_part_number_test.php` 11 of 21 (its quick-search check passes on rc116: ERP-Core's),
  `bd_line_project_test.php` 36 of 47, `bd_button_quote_project_test.php` 8 of 22, `bd_adm_rules_test.php` 7 of 139,
  `bd_erp_layout_test.php` 7 of 21, `project_required_on_real_sidecar.cjs` 5 of 11, and
  `test_post_install_step_report.py` does not load (the classes are missing). 34 mutants, each red: Role read-only
  (twice), Best Case Kinetic's alone, billing country or a conditional city, the rule on save, a served-required
  street, the catalog column after Type / editable / hidden, the grid Project read-only (column and gridColumn), no
  fetch, a double fetch, the quote-level Project left on the view, the line field read-only / not audited / defaulted,
  the copy over a line's own / without its column / over every quote / one page only / swallowing a failed read /
  stamping date_modified, the history gate ignored or counting the staged row, the rc110 fill back, the prefill back,
  the default never written / under another key / on another platform, the default or the copy on every install, and
  an unreadable history read as below rc117.
- **Not proven here:** anything on a tenant. The live read after install is in the hand-back.

# 0.9.46-rc116 — a quote line shows its Product Group

rc116 = rc115 + the line's Product Group as a column on the quote line grid, and Category relabelled "Product Group" on
quoted line items and products (G1338). No new field, list, setting or vardef; three files added, one class grown.

**Install order on a Bench tenant:** rc116 as an upgrade over rc115 (or any rc from rc112), with ERP-Epicor 1.2.4 or
later (the package's floor; it fetches each line's category for the grid since 1.2.2). No Quick Repair: the install
rebuilds the Products and ProductTemplates metadata and languages. Reload the browser tab after the install.

- **What Bench Dogs reported (2026-10-25).** "Product Group is not visible when creating an Opportunity/Quote", on the
  staging sandbox.
- **What was measured.** Epicor's Product Group is already synced: the connector maps Epicor's product groups to
  Sugar's product categories and a product's category to its group. A quote line carries its product's category: Sugar
  copies it from the product when a line is added from the catalog or picked by name (the populate list of stock
  `Products.product_template_name`, the Product Catalog dashlet's add, and `Product::populateFromTemplate()` on save), the connector
  sets the product on an Epicor-born line and the group itself on a line with no catalog product, and ERP-Epicor
  fetches `category_id` and `category_name` for every grid row (`QuotesLayout::productGroupLineItemFields`, G854). No
  Sugar field was labelled "Product Group". Opportunities carry no product lines in this setup, so the change is on
  quote lines only.
- **The column.** On the quote line grid (record and create view), `category_name` right after Part Number, read-only
  and as plain text, captioned "Product Group". It follows the product name where a grid has no Part Number, and is
  the last column where it has neither. A grid an admin already gave the column keeps it where it is, read-only. A new
  product's group is still picked in ERP-Core's dropdown on the product name, and the column shows it once picked.
- **The captions.** "Product Group" in place of "Category Name:" and "Category:" on quoted line items, and of
  "Category Name:", "Category:" and the list's "Category:" on products (record views, lists, the catalog drawer,
  filters), en_us only. Sugar's own keys, overridden by this package; "Category ID" is unchanged.
- **Not changed.** ERP-Core's `LBL_ERP_PRODUCT_GROUP` (the new product's group picker) and every other column; the
  Opportunity screens; the quote PDF.
- **Release note.** On upgrade every Bench quote line grid shows a Product Group column after Part Number, and product
  screens say "Product Group" where they said "Category". No data changes, and the sync is not affected.
- **Tests.** `bd_quote_line_product_group_test.php` (41 checks): the overlay included the way Sugar includes it, three
  times in a build, over the Bench grid's served column order; an admin's own column, a bare name, a grid with no Part
  Number, a field list with gaps, no grid, a broken panel and no loadable class; compiled beside the G848 discount
  overlay; the two relabels over stock Sugar 26.1's strings and over a plain fragment of another package; ERP-Epicor's
  fetch of the line's category, by reflection on its pinned QuotesLayout. Red on rc115: 29 of 41. Mutation checks,
  each red: no read-only flag, no already-drawn check (two columns after a rebuild), the column before Part Number,
  the list's keys kept as read, the link left on, the language file without `_override`.
- **Not proven here:** the grid on a tenant. The live check after install: a fresh quote with a catalog line shows the
  product's group under "Product Group", read-only in the edit row; a product's record shows "Product Group".

# 0.9.46-rc115 — the Bench Dogs quote PDF footer reads "Page: 1 of 2"

rc115 = rc114 + one line at the top of the "Bench Dogs Quote" PDF Manager template's body. No new field, label,
setting or dropdown key; no layout change; no file added or removed.

**Install order on a Bench tenant:** rc115 as an upgrade over rc114 (or rc112, rc113), and ERP-Epicor 1.2.120
(REPLACE variant), in either order. The install brings the template this package made to rc115's body and publishes
it, keeping a name an administrator gave it (post_install step `quote_pdf_template`, answer `updated`). No Quick
Repair: PDF Manager reads the stored body on every print. With an ERP-Epicor older than 1.2.120 the new line prints
nothing and the footer stays " 1 / 2".

- **What the side-by-side found (2026-10-22).** After rc114 the template matched Bench Dogs' sample (Kinetic quote
  7972) except the page label: PDF Manager's own footer (`SugarpdfPdfmanager::Footer()`) prints " 1 / 1" on every
  page, the sample "Page: 1 of 2", and a template body cannot reach that footer.
- **The change.** ERP-Epicor 1.2.120's quote PDF view prints, in place of " 1 / 2", the page label a template declares
  in its body as a Smarty comment, `{* ERP_PAGE_LABEL: Page: %s of %s *}` (the page, then the page count); a template
  without it keeps Sugar's footer. rc115's body opens with that line, directly before the logo table, so every page's
  footer reads "Page: 1 of 2" (with the rule above it, as before). A Smarty comment prints nothing on the page, and
  every character of it is one Sugar's cleaner leaves alone, so the install stores the body as it is.
- **Not changed.** Every other part of the template; the install and uninstall steps.
- **Still not like the sample, and why** (as rc114): a break's price prints without Kinetic's price-per divisor
  ("341.00 /1"), which Sugar does not store; the long description prints only where Sugar holds one (nothing maps
  Kinetic's `QuoteDtl.QuoteComment` into `e_line_comment_c` yet); Project prints empty.
- **Release note.** On upgrade, with ERP-Epicor 1.2.120, every quote printed with the "Bench Dogs Quote" template
  reads "Page: N of M" at the foot of each page instead of " N / M". No data changes, and the sync is not affected.
- **Tests.**
  - `bd_quote_pdf_test.php`, section F (3 new checks, 97 in all): the body declares the label exactly once, at the
    very start before anything that prints, with or without the logo. Red on rc114: 3.
  - `bd_quote_pdf_render_test.php`, section H (2 new checks, 37 in all), through Sugar 26.1's own Smarty: nothing of
    the marker renders (no `ERP_PAGE_LABEL`, `%s` or `{*` in the HTML), and the HTML still starts with the logo table.
    Red on rc114: H1. The cleaner check (G1) passes with the new line.
  - Mutation checks, each red: the marker as an HTML comment (Sugar's cleaner keeps only its own loop comments, so a
    save in PDF Manager would drop it), the marker at the end of the body, braces that are not a comment and print.
  - ERP-Epicor's side (erp-integration-sugar, `QuotePdfFooterPrintsTheTemplatesPageLabelTest.php`) runs its footer
    over Sugar's own recorded `SugarpdfPdfmanager` and reads this exact marker as "Page: %s of %s".
  - Not proven here: the print on a tenant. The live check after install covers it: a fresh quote's PDF reads
    "Page: 1 of N" on every page and shows no "ERP_PAGE_LABEL" text.

# 0.9.46-rc114 — the Bench Dogs quote PDF prints the price breaks, the long description and the sample's formats

rc114 = rc113 + a new body for the "Bench Dogs Quote" PDF Manager template. No new field, label, setting or dropdown
key; no layout change; no file added or removed.

**Install order on a Bench tenant:** rc114 as an upgrade over rc113 (or rc112). The install brings the template this
package made to rc114's body and publishes it, keeping a name an administrator gave it (post_install step
`quote_pdf_template`, answer `updated`). No Quick Repair: PDF Manager reads the stored body on every print. Any
ERP-Epicor from 1.2.117 (whose Download PDF and Email PDF prefer this template).

- **What the side-by-side found (2026-10-22).** A Bench quote priced in Kinetic (Epicor quote 8890, line 1 with a
  three-rung ladder 1 @ 24 / 10 @ 22 / 50 @ 20) printed through rc113 against Bench Dogs' sample (Kinetic quote 7972):
  no price-break table under the laddered line, no long description, Expires as "2026-11-21", prices as "$22.81",
  and "1 / 1" for the page.
- **Why the price breaks were missing.** In Sugar each rung of a ladder is its own quoted line item. A synced rung
  names its ladder only in its key (`erp_sync_key`, `<company>__<QuoteNum>_<QuoteLine>_<QtyNum>`); `erp_ladder_group`
  is the field a copied line keeps once its key is shed, and the connector never writes it on a synced rung. rc112 and
  rc113 matched rungs by `erp_ladder_group` alone, so a quote the ERP priced printed no breaks; only a copied quote
  would have.
- **The price-break table.** Under each line the quote counts, every quoted line item of its quantity ladder, in a
  Quantity / Unit Price table: the rung's break quantity with its unit (`erp_break_quantity`, `erp_unit_of_measure`:
  "50.00 EA") and its own unit price (`erp_break_unit_price`: "20.00"), in the quote's order. The ladder is
  ERP-Epicor's rule, `ErpLineRole::ladderGroupOf`: the line's key less its last part, else the `erp_ladder_group` a copy
  kept, else none. A break the seller did not choose (`erp_total_role` "alternative") still prints no line of its own,
  so the line numbers, the line table and the quote's totals agree. A line whose ladder has one rung prints no table.
- **The long description.** Under the line, before its breaks: the line's own description where it says more than its
  name (the connector writes the part's description into both, so an ERP line prints nothing twice), then
  `e_line_comment_c`, the customer's own field for Kinetic's line comment, line by line.
- **Formats.** Unit Price, Ext Price and a break's price print without the currency symbol and keep their thousands
  separators (341.00, 25,575.00). Expires prints as M/D/YYYY (11/21/2026), as the Quote Date does. PDF Manager hands
  the template Expires already written in the printing user's date format, so the template reads it back only where
  the order is certain: a year-first date (2026-11-21, 2026/11/21, 2026.11.21) becomes 11/21/2026; a date with
  slashes and the year last only loses its leading zeros, keeping the user's own order (11/05/2026 prints 11/5/2026, a
  day-first 05/11/2026 prints 5/11/2026); any other format prints as the user's. No date is ever swapped or invented
  (Smarty's date_format would print today's date for a format it cannot read).
- **Not changed.** Customer Part # (`e_customer_part_num_c`), the logo, the header blocks, Quote To and Ship To,
  Project, Sales Person, Terms, Contact, the columns, the Terms & Conditions, signature and Purchase Order block, the
  paragraphs and the closing line; the install and uninstall steps.
- **Still not like the sample, and why.**
  - Page numbers read "1 / 2", not "Page: 1 of 2". The page label is drawn by PDF Manager's own footer
    (`SugarpdfPdfmanager::Footer()`, on every page), which a template body cannot reach; the quote's PDF view that
    could change it is ERP-Epicor's (`custom/modules/Quotes/sugarpdf/sugarpdf.pdfmanager.php`), and it would change
    every customer's quote PDF. Not done here.
  - A break's price prints without Kinetic's price-per divisor ("341.00 /1"): Sugar does not store it.
  - The long description prints only where Sugar holds one. Nothing maps Kinetic's `QuoteDtl.QuoteComment` into
    `e_line_comment_c` yet, so a quote the ERP priced still prints none until that mapping exists (the Bench connector
    extension's, the owner's call).
  - Project prints empty, as before.
- **Release note.** On upgrade every quote printed with the "Bench Dogs Quote" template changes: prices without the
  currency symbol, Expires as M/D/YYYY, and a price-break table under each laddered line. No data changes, and the
  sync is not affected.
- **Tests.**
  - `bd_quote_pdf_render_test.php` (new, 35 checks, run by `test_php_suites.py`): the template rendered by Sugar
    26.1's own Smarty 4.5.6 under the PDF security policy, over PDF Manager's data for a quote shaped like Epicor quote
    8890 (`fixtures/rc114`), read back as rows and cells: two lines from four quoted line items; line 1's three rungs
    under it and no table under line 2; the long description; no currency symbol, separators kept; Expires in nine
    user date formats; a copied quote; two ladders on one quote; the ladder rule held to ERP-Epicor's real
    `ErpLineRole::ladderGroupOf` on every shape of line (pinned with the other shared files at the same upstream
    commit); and a stored body Sugar's cleaner leaves untouched. Red on rc113: 20 of 35. Smarty comes from
    `scripts/tests/smarty_pdf.py`: CI downloads the v4.5.6 release and checks every file against the hash of Sugar
    26.1.0's own copy; in CI the test fails rather than skip without it.
  - `bd_quote_pdf_test.php`: the source pins follow the new body (94 checks). Red on rc113: 10.
  - Mutation checks, each red in the render suite: every rung printed as a line (5 fail), the rc113 `erp_ladder_group`
    rule (7), a ladder of one printing a table (4), Ext Price keeping its symbol (4), Expires through date_format (3),
    rungs grouped by part number (1), the line's own description never printed (1), a backslash in the body (2).
  - Not proven here: the print on a tenant. The live check after install covers it.

# 0.9.46-rc113 — Ship Via is checked when the seller orders, never required on a quote

rc113 = rc112 + one field controller emptied. No new field, label, setting or dropdown key; no layout change.

**Install order on a Bench tenant:** rc113 as an upgrade over rc112, and ERP-Epicor 1.2.118 (REPLACE variant), in
either order. rc113 takes Ship Via's requirement off the quote; ERP-Epicor 1.2.118 takes FOB's off (with an older
ERP-Epicor, FOB stays required on the quote while the company's "Orders Require an FOB" switch is on). No Quick
Repair: the controller is served with the module's metadata, which the install refreshes. Reload the browser tab
after the install.

- **What the seller met (Bench Dogs, relayed by the owner, 2026-10-21).** "I noticed FOB and Ship Via were required when
  creating an Opp/Quote. I don't believe they are required in Kinetic on a Quote." From rc104 a Save of any Bench quote
  with no Ship Via was refused, red, on the create form and the record view, at every stage, a quote the ERP sent
  included; ERP-Epicor 1.2.101 to 1.2.117 did the same for FOB while the company's switch was on.
- **What the owner ruled (2026-10-22).** "Bench quotes check FOB and Ship Via only when the seller presses Submit Order
  or Order Selected Lines (where Kinetic needs them), instead of making them required from quote creation." It
  supersedes the ruling of 2026-10-19 ("Ship Via required on Bench quotes no matter what is the stage").
- **The change.** This package's Quotes erp-lookup controller no longer marks Ship Via required: it is ERP-Core's
  erp-lookup field, unchanged. A Bench quote saves with Ship Via empty, at every stage, on the create form and on the
  record view. The file still ships, extending ERP-Core's field with nothing: Module Loader never deletes a file a
  later build stops shipping, so dropping it would leave rc112's rule on every upgraded tenant.
- **Unchanged: the order refusal.** Submit Order and Order Selected Lines run the same pre-send check (ERP-Epicor's
  ErpOrderChecks, which asks this package's BdAdmOrderRequirements through its OrderRequirements slot). On an ADM quote
  with no Ship Via, it refuses before anything is sent, in one refusal beside the FOB's: "This quote has no Ship Via and
  its customer has no default Ship Via in the ERP, so it cannot be ordered. Pick a Ship Via on the quote, then submit
  again. Nothing was sent to the ERP." (a Sales Order; an order that converts an ADM ERP quote names that ERP quote
  instead). A Sales Order whose customer has a default Ship Via in the ERP goes through, as before: Kinetic takes the
  customer's.
- **Release note.** From rc113 a seller can save a Bench quote with no Ship Via (and, with ERP-Epicor 1.2.118, no
  FOB); the seller meets both at Submit Order or Order Selected Lines instead, before anything reaches Kinetic. An
  empty Ship Via is hidden on the quote's record view until Edit (ERP-Epicor's rule for an empty, optional ERP lookup;
  rc104 to rc112 kept it shown because it was required). No data changes on upgrade, and the sync is not affected.
- **Tests.**
  - `ship_via_required_on_real_sidecar.cjs` (12 cases, run by `test_ship_via_required_on_real_sidecar.py`; rewritten):
    on Sugar 26.1.0 and 26.2.0's own record and create views, Ship Via is not required, never red and shows no
    "Required" text on a saved quote of ADM and of another company, on a quote the ERP sent at Draft, In Estimation,
    Priced, Negotiation, Confirmed, Partially Fulfilled, Closed Accepted and Closed Lost, and on the create form; Sugar's
    check lets the Save through while it still refuses the quote's cleared name; FOB is not this controller's. CONTROLS:
    the rc112 controller (`fixtures/rc112`, the shipped bytes) refuses the Save, red; the rc103 one draws "(Required)".
    Red on rc112: 6 of 12. The page no longer draws ERP-Epicor's quote checks field (FOB is ERP-Epicor's rule, held by
    its own page from 1.2.118), so its three files leave the real-Sidecar pins, re-pinned at the same commit.
  - `project_required_on_real_sidecar.cjs`: an Epicor-born quote saves with Project, the pickers, FOB and Ship Via
    empty; a new ADM quote still asks for the four lead and marketing pickers, not Ship Via; the rc100 control refuses
    Project alone. Red on rc112: 8 of 11.
  - `test_ship_via_required_rule.py`: the controller still ships at rc112's path and defines nothing but its parent;
    nothing this package serves requires Ship Via; its scripts stay module client files. Red on rc112: 1 of 6.
  - Mutation checks, each red on the page: Ship Via required only on a new quote (2 fail), only at Confirmed and later
    (4), FOB required by this controller (6).
  - The order refusal is held by `bd_order_requirements_test.php` (this package's rule) and ERP-Epicor's
    `OrderNamesEveryHeaderRequirementTest.php` (the slot asked on Submit Order and Order Selected Lines, both named in
    one refusal, nothing sent), both unchanged.
  - Not proven here: ERP-Epicor's own Quotes views on a tenant, and the refusal as a seller meets it; the live read
    after install covers them.
- **Not changed.** BdAdmOrderRequirements and its words, the ADM pickers' rules (Lead Source, Lead Type, Marketing
  Campaign and Event still required until the quote is in the ERP; Project optional), Cust. Group, the Reference,
  ERP-Core and the Bench connector extension.

# 0.9.46-rc112 — Bench Dogs' quote layout as a PDF Manager template

rc112 = rc111 + one PDF Manager template that the install creates, and its logo. It adds no field, setting or
dropdown key, and it touches no layout. Sugar's own Quote template is left as it is.

**Install order on a Bench tenant:** rc112 as an upgrade over rc111. For the Customer Part # column to show a value, the
customer's own field package `Bench_Dogs_QLI_Customer_Part_Field` 1.0.0 must be installed (it adds Products
`e_customer_part_num_c`), and so must Bench connector extension 1.0.37 (it maps Kinetic `QuoteDtl.XPartNum` both ways).
Any order works: without them the column prints empty. No Quick Repair is needed.

- **What the owner asked (2026-10-21).** Bench Dogs' quotes in their own layout. The sample is their Kinetic quote form
  (quote 7972, 2 pages).
- **What the install does.** It creates a PDF Manager template on Quotes named "Bench Dogs Quote" (`template_name`
  `erp_customer_quote`, for every team) and publishes it. On an upgrade it brings the body of the template this
  package made to this release's body and publishes it again, keeping a name an administrator gave it. The
  post_install step report names it `quote_pdf_template`. An uninstall unpublishes the template and keeps the record.
  This happens before the files go, because the logo goes with them and the PDF renderer stops on an image it cannot
  open. For the same reason, an install that does not find the logo file stores the body without the image.
- **What it prints, top to bottom:**
  - the logo, then Quote (the ERP quote number once there is one), Quote Date (the day Kinetic priced the quote,
    `erp_priced_at`) and Expires (Valid Until);
  - Quote To (the billing account and address) and Ship To (the ship-to address's name, else the shipping account,
    then the shipping address);
  - Project, Sales Person (the quote's owner) with the owner's email and mobile, and Terms (the quote's billing terms)
    with "(full terms & conditions on last page)";
  - Contact (the billing contact) with the contact's phone and email;
  - the line table: line number (the ERP quote line, else a count), Part #, Customer Part #
    (`e_customer_part_num_c`), Description, Qty, Unit Price and Ext Price. A price break the seller did not choose is
    no line of its own. Under each line come its long description (`e_line_comment_c`) and its price-break table: every
    rung of its ladder, each with its quantity, unit and unit price;
  - the Terms & Conditions block (the link, Freight: FOB Bench Dogs, Payment: TBD, "Quotation is valid for 30 days.",
    Billing, Indemnification) as the sample prints it;
  - the signature block (By, Title, Date, the contact, the ship-to, Purchase Order #), and the acceptance and sales
    tax paragraphs;
  - "Thank you for the opportunity to provide this quotation." Page numbers come from PDF Manager's own footer, on
    every page.
- **Printed empty, because Sugar holds no value for them:**
  - Project. On the sample it is Kinetic's `QuoteHed.QuoteComment`, which the connector does not bring into Sugar
    for this customer's connection.
  - The long description, until something maps Kinetic's `QuoteDtl.QuoteComment` into `e_line_comment_c`.
  - The sales person's mobile, where the owner's user record has none.
  - The price-per divisor ("/1"), which Sugar does not store.
- **Not like the sample:**
  - The logo and the Quote / Date / Expires block print on page 1 only.
  - Page numbers read "1 / 2" (PDF Manager's footer).
  - Amounts carry the currency symbol.
- **How to print it.** The quote's Download PDF and Email PDF actions (ERP-Core) always print the one published
  template named "Quote". Until ERP-Core can offer another one, print this template through PDF Manager's own route,
  in the browser session signed in to Sugar:
  `index.php?module=Quotes&action=sugarpdf&sugarpdf=pdfmanager&record=<quote id>&pdf_template_id=<template id>`.
  The template id is in the address of the "Bench Dogs Quote" record under Admin > PDF Manager.

# 0.9.46-rc111 — the ERP order shows ERP-Epicor's own ERP Line Total; the Bench Dogs copy is retired

rc111 = rc110 + one field no longer created, its label changed by the install, and two view overlays. No new field,
setting or dropdown key; nothing is deleted.

**Install order on a Bench tenant:** rc111 as an upgrade over rc110, after ERP-Epicor 1.2.112 (which adds
`erp_line_total`; on an older ERP-Epicor the package installs and simply draws no ERP Line Total). No Quick Repair:
the install rebuilds the ERP_Orders metadata. Reload the browser tab after the install.

- **What the sellers met (live read, 2026-10-21, ERP-Epicor 1.2.112 and rc110).** An ERP order's record view showed
  ERP Misc. Charges and, at the end of the first panel, Bench Dogs' `e_total_charges_c`, also labelled "ERP Line
  Total". ERP-Epicor's own `erp_line_total` (the same Epicor DocTotalCharges) was not on the view.
- **The cause.** ERP-Epicor's install stores the order's record view on every install, so a tenant that had
  ERP-Epicor before 1.2.111 keeps a stored view without the new field: the 1.2.112 upgrade adds `erp_line_total` to
  the vardefs and to ERP-Core's own view, never to the stored one. rc100 to rc110 created and drew their own copy.
- **The change (owner ruling, 2026-10-21: the Bench copy is retired in steps).** A new tenant no longer gets
  `e_total_charges_c`. On a tenant that has it, the install relabels it "Line Total (old)" while it reads a label a
  release gave it ("Charges" or "ERP Line Total"), so a label set by hand is kept, and the field and its data stay.
  The record view overlay takes it off the view wherever it is drawn, a Studio-stored layout included (a row it
  leaves holding only blank cells goes, as Studio's own removal drops it), and draws ERP-Epicor's `erp_line_total`
  right after ERP Misc. Charges, read-only, where the view lacks it (in the copy's cell when the copy sat right
  there). Its company-currency twin, `erp_line_total_usdollar`, stays off the view. A new list view overlay takes
  the copy off the ERP Orders list and its column chooser. Nothing is stored: both overlays run at every metadata
  build, so an ERP-Epicor upgrade or REPLACE install cannot bring the copy back.
- **What a seller sees after the install.** On every ERP order: ERP Line Total right after ERP Misc. Charges in the
  Order Detail panel, and no second "ERP Line Total" at the end of the first panel. Project Manager and Engineer stay where they were. ERP Line Total
  is empty on an order until the connector has written it (core v0.5.28 writes it; older orders need the ERP Orders
  re-read), while the old copy keeps its value out of sight.
- **What an admin sees.** Studio lists `e_total_charges_c` as "Line Total (old)"; reports and filters keep it. If
  Studio's layout editor shows it on a stored layout, the record and list views still do not draw it. Deleting it
  is a later step, on the owner's word.
- **Before a NEW Bench tenant gets rc111.** The Bench connector extension still writes `e_total_charges_c` on ADM
  orders until its own release stops it. A tenant that has the field (both Bench Sugars today) is unaffected; on a
  new tenant, which rc111 gives no such field, that write meets an unknown field, so install rc111 there only after
  the extension release that stops writing it.
- **Tests.** `scripts/tests/bd_order_line_total_test.php` (new, 28 checks) builds the order's record view the way a
  tenant gets it: ERP-Epicor's own install (BaseErpLayout's two ERP_Orders steps) over ERP-Core's own record view and
  vardefs of 1.2.107 and 1.2.112 (`fixtures/rc111/`, byte copies held by `test_rc111_layout_pins.py`), then the
  shipped overlays as Sugar compiles and includes them. The upgraded tenant draws ERP Line Total once, right after
  ERP Misc. Charges, read-only, in a fresh tenant's rows, and no copy; controls: a fresh tenant's view as stored, an
  older ERP-Epicor (no field: nothing drawn), the copy stored by Studio right after ERP Misc. Charges or with a blank
  beside it, ERP Line Total an admin placed elsewhere, no ERP Misc. Charges on the view (nothing drawn at a guessed
  place), the list view and its column chooser, unreadable vardefs, and rebuilds. 15 of 28 fail on rc110.
  `scripts/tests/bd_kinetic_fields_test.php` (34 checks): a new tenant gets 22 fields and no `e_total_charges_c`; a
  copy reading "ERP Line Total" or "Charges" is relabelled "Line Total (old)", a hand-set one is kept; an install has
  no way to delete a field; 8 of 34 fail on rc110. 20 mutants (the copy created again, its label left "ERP Line
  Total", only "Charges" relabelled, every label relabelled, no placement, the wrong anchor, the twin placed, the copy
  back in the overlay's fields, the copy never taken off, taken off without the row rule, drawn editable, its cell
  not reused, taken off before its cell is reused, placed where the tenant lacks the field, placed twice, placed at a
  guessed place, the record overlay's step unwired, the list overlay unwired, a delete method, placement on
  unreadable vardefs) each fail a check.
- **Not changed.** ERP-Epicor and ERP-Core, the Bench connector extension, the copy's data, Project Manager and
  Engineer, the quote and line views, and the sync. No data changes on upgrade.

# 0.9.46-rc110 — a quote made with the Account's Create Opportunity & Quote button starts with the account's Project

rc110 = rc109 + one save default. No new field, label, setting or dropdown key; no layout change.

**Install order on a Bench tenant:** rc110 as an upgrade over rc109 (ERP-Epicor 1.2.109 is already installed). No
Quick Repair and no browser reload: the change is on the server.

- **What the sellers met (live read after rc108, 2026-10-21).** On an ADM account whose newest quote holds Project
  17879, the Account's Create Opportunity & Quote button made a quote with no Project, and Submit Order on it was
  refused for the missing Project. The New Quote form offers that Project; on rc107 the button's quote had it too.
- **The cause.** The button is ERP-Epicor's: it creates the quote on the server and opens it, so it never goes
  through the create form that offers the Project. rc108 stopped the Quotes before_save hook from filling an empty
  Project, so nothing gave the button's quote one.
- **The change.** When a new ADM quote is created and the create sent no Project at all, the hook takes the
  account's newest quote holding a Project the picker still offers, the same Project the create form would offer.
  The create form always sends the Project (empty when the seller cleared it), so a quote made there keeps what the
  seller chose. It happens once, at creation: every later save keeps what the seller saved, so a Project cleared on
  the quote stays clear. No Project comes back from the product group, and nothing changes for another company or
  a tenant without ADM.
- **What a seller sees after the install.** On an ADM account, Create Opportunity & Quote opens the new quote with
  Project already set to the account's newest active one (for example 17879 - LGH EXPANSION), so Submit Order is no
  longer refused for a missing Project. The seller may clear it and save, and it stays empty. An account with no
  earlier quote holding an active Project gets an empty Project, as before. The New Quote form behaves as in rc109:
  it offers the account's newest Project, and one the seller clears stays clear.
- **What an admin sees.** Nothing new. The before_save hook keeps its label "Bench Dogs: ADM quote defaults
  (Reference, account history)".
- **Tests.** `scripts/tests/bd_button_quote_project_test.php` (new, 14 checks) runs ERP-Epicor's real
  `AccountsErpActionsApi::createOppQuote()` through this package's shipped before_save registration and the real
  rules, over a stand-in for Sugar's save in SugarEnt 26.1's order: the button's quote takes 17879 (the newest quote
  holding none and the next a closed project are passed over, and another account's quote is not read), once; a
  later save of a cleared Project keeps it clear; controls: an account with no earlier quote or only a closed
  project, an EPIC06 account, a tenant without ADM, and the create form's sent empty, 17879 and 20065 Projects. 2 of
  14 fail on rc109. `scripts/tests/bd_adm_rules_test.php` section X (5 checks) and Q2, Q15, Q18: 5 of 139 fail on
  rc109; 139 of 139 pass. `scripts/tests/project_required_on_real_sidecar.cjs`: Sugar's own create view (26.1.0,
  26.2.0) sends a cleared Project as an empty one (11 of 11; a seller who unsets it instead fails). 14 mutants (the
  Project on every create, never, an empty one read as unsent, the rule inverted, every save, the old field list,
  another picker's list, no still-offered check, oldest first, no ADM gate, the hook unregistered, two stand-in
  mutants, and the create body unset) each fail a check.
- **Not changed.** The fields, their labels, lists and layouts, ERP-Core and ERP-Epicor, the Reference, Lead Source,
  Lead Type and Campaign + Event defaults, every saved quote's Project, and the sync. No data changes on upgrade.

# 0.9.46-rc109 — the Epicor Cost Worksheet panel draws its eight values with no empty cells

rc109 = rc108 + one view overlay change. No new field, label, setting or dropdown key.

**Install order on a Bench tenant:** rc109 as an upgrade over rc108 (ERP-Epicor 1.2.109 is already installed). No
Quick Repair: the install rebuilds the Products metadata. Reload the browser tab after the install.

- **What the sellers met (live read after rc108, 2026-10-21).** On the Bench Sugar that also has the customer's own
  field package, a quoted line item's Epicor Cost Worksheet panel drew 16 cells for its eight values, 8 of them
  empty, starting with a blank cell before Material Cost. The Cost & capacity section and the panel order were
  right. The other Bench Sugar drew exactly the eight.
- **The cause.** That Sugar's stored layout (a Studio save) holds Bench Dogs' line fields inside the cost panel, each
  row padded with a blank cell. Sugar 26.1's Studio stores a blank cell as an empty entry with no name, not as an
  empty string, and rc108 dropped only empty strings, so the 4 padding cells stayed after our fields left. Sugar
  draws each unnamed entry as two empty cells.
- **The change.** The overlay now reads that panel in rows, as the record view draws it, and leaves each row the way
  Studio's own field removal leaves it: a row that held only Bench Dogs' fields and blank cells goes whole; a field
  that is not ours sharing a row with one of ours stays and takes the row's width. Rows without one of our fields
  are untouched, so a blank cell an admin put between two cost values, a full-width blank row, and every blank cell
  in another panel stay where they are. Nothing is stored: the overlay runs at every metadata build, so installing
  rc109 over rc108 cleans that Sugar's panel at the next build, and an ERP-Epicor upgrade or REPLACE install cannot
  bring the empty cells back.
- **What a seller sees after the install.** On that Sugar's quoted line items the Epicor Cost Worksheet panel
  (closed at start) opens to its eight values in four rows of two: Material Cost and Labor Cost, Material Burden and
  Labor Burden, Subcontract Cost and Misc. Cost, Quoted Markup % (on cost) and Quoted Profit % (on price), with no
  empty cell. The Cost & capacity section under it still holds its 14 fields; Sales Category, Expected Quantity and
  Expected Qty UOM stay in the body. On the other Bench Sugar nothing changes.
- **What an admin sees.** Nothing new. Studio's layout for the quoted line item is not changed (it still shows Bench
  Dogs' fields where a Studio save put them; the record view draws them in the section).
- **Tests.** `scripts/tests/bd_cost_capacity_section_test.php` section U (11 checks) runs that Sugar's stored order
  with its blank cells in the form Studio 26.1 writes (`fixtures/rc109/`, pinned to the live read: kept in place
  they draw the 16 cells, 8 empty, that Sugar drew): the panel holds exactly its eight values with no blank cell,
  8 record cells, for each stored blank form ([] , a bare span, '', an empty name), also after ERP-Epicor re-applies
  its layout. Controls: the section and the body unchanged, a tenant with nothing stored unchanged, an admin's own
  field kept, blank cells outside the panel and blank cells sharing no row with ours kept, rebuilds identical.
  T10's dragged-back shape now inserts whole rows, as Studio writes them. 6 of 48 fail on rc108; 48 of 48 pass.
  15 mutants (rc108's blank rule, blank rows kept, every blank dropped, spans ignored, one column a row, no
  widening, a leading field's width lost, rows with an admin field dropped, taken out of the body too, rc108's
  neighbour rule, no move, every row rewritten, a field added twice, and two fixture mutants) each fail a check.
- **Not changed.** The fields, their labels and values, the Cost & capacity section's label, order and read-only
  fields, ERP-Epicor's cost worksheet panel definition, the stored layouts, and the sync. No data changes on upgrade.

# 0.9.46-rc108 — Cost & capacity also where a Studio save put Bench Dogs' fields in the cost worksheet panel; a cleared Project stays clear

rc108 = rc107 + one view overlay change and one save default removed. No new field, label, setting or dropdown key;
one dropdown list is no longer shipped.

**Install order on a Bench tenant:** rc108 as an upgrade over rc107 (ERP-Epicor 1.2.107 is already installed). No
Quick Repair: the install rebuilds the Products and Quotes metadata. Reload the browser tab after the install.

- **What the sellers met (live read after rc107, 2026-10-21).** On the Bench Sugar that also has the customer's own
  field package, a quoted line item showed no Cost & capacity section: an earlier Studio save had stored 14 of Bench
  Dogs' line fields inside ERP-Epicor's Epicor Cost Worksheet panel, among its eight values, with a blank cell padding
  each group, and three more in the body. rc107 leaves a field it finds on the view where it is. On the other Bench
  Sugar the section showed as built. Separately, a seller cleared the Project the create form had filled on a new ADM
  quote and saved: the save filled it again from the account's newest quote ('' to 17879, in the quote's audit).
- **The change, Cost & capacity.** When the overlay finds one of Bench Dogs' 17 line fields inside the Epicor Cost
  Worksheet panel, it draws it in the Cost & capacity section directly under that panel (in the mapping's order,
  read-only where Kinetic owns it), and drops the blank cells beside it. Everything else stays where it is: the eight
  cost values and their order, a field that is not ours (the customer's own Quote/Order Comments, for one), a blank
  cell between two cost values, and our fields anywhere else (the body's three stay in the body). Nothing is stored:
  the overlay runs at every metadata build, so the stored layout Studio edits is unchanged, and an ERP-Epicor upgrade
  or REPLACE install cannot undo it.
- **The change, Project.** The Quotes before_save hook no longer fills an empty Project: neither the product group
  default (CMI to 20065) nor, on a new quote, the account's newest quote's Project. The create form still offers the
  account's newest Project (ERP-Core's prefill, unchanged) and the seller may clear it. Reference, Lead Source, Lead
  Type and the Campaign + Event pair fill as before. A Project the seller picks is kept. ADM still needs a Project on
  every order line, so the Bench connector extension still names a missing one at Submit Order and Order Selected
  Lines.
- **What a seller sees after the install.** On that Sugar's quoted line items: the Epicor Cost Worksheet panel
  (closed at start) holds only its eight values, and the Cost & capacity section, open, sits directly under it with
  the 14 fields; Sales Category, Expected Quantity and Expected Qty UOM stay in the body. On the other Bench Sugar
  nothing moves. On a new ADM quote: a Project cleared on the create form is still empty after Save and after Send
  to Estimation; Submit Order on such a quote is refused naming the Project, as today.
- **What an admin sees.** The before_save hook's label reads "Bench Dogs: ADM quote defaults (Reference, account
  history)". The list `bd_adm_project_by_group_list` is no longer shipped, so Dropdown Editor no longer lists it; a
  copy an admin saved there stays, and nothing reads it. Studio's layout for the quoted line item is not changed.
- **Tests.** `scripts/tests/bd_cost_capacity_section_test.php` section T (11 checks) runs the two record view shapes
  read on the two Bench Sugars after rc107 (`fixtures/rc108/`): the stored shape gets the section under the panel
  and the panel only its eight; controls: the other Sugar's view drawn as read, the body's three and a field that is
  not ours left in place, a blank cell between two values kept, rebuilds, ERP-Epicor re-applying its layout, and a
  field dragged back into the panel after a Studio save. 6 of 37 fail on rc107; 37 of 37 pass. 11 mutants (no
  take-out, blank cells kept, all dropped, only after ours, only before ours, one at a time, taken out of every
  panel, every e_ field, only while no section is stored, not drawn again, copies left behind) each fail a check.
  `scripts/tests/bd_adm_rules_test.php` section W: a new ADM quote saved with Project blank keeps it blank (all-CMI
  lines, the account's newest quote holding 17879), and saved again too; controls: Reference, Lead Source, Lead Type
  and the pair still fill; a picked Project is kept. 12 of 134 fail on rc107; 134 of 134 pass. 9 mutants (Project
  back in the history fill, a fixed default on every save, on updates only, the seller's pick dropped, the list
  shipped again, the prefill key off the vardef, and three controls: no Reference default, no history fill, no lead
  pickers) each fail a check. `scripts/tests/project_required_on_real_sidecar.cjs`: on Sugar's own create form
  (26.1.0, 26.2.0) with ERP-Core's real pick list field, the account's newest Project (17879) is offered, and once
  the seller clears it it stays clear and is not asked for (11 of 11; a vardef without the prefill key fails 2).
- **Not changed.** The fields, their labels and values, the Cost & capacity section's label and order, ERP-Epicor's
  cost worksheet panel, the stored layouts, every quote's saved Project, and the sync. No data changes on upgrade.

# 0.9.46-rc107 — Cost & capacity sits directly under the Epicor Cost Worksheet panel; Expected Quantity and its unit read-only

rc107 = rc106 + one view overlay change. No new field, label, setting or dependency.

**Install order on a Bench tenant:** rc107 as an upgrade over rc106 (ERP-Epicor 1.2.107 is already installed). No
Quick Repair: the install rebuilds the Products metadata. Reload the browser tab after the install.

- **What the owner ruled (2026-10-20).** Bench Dogs' Cost and Capacity fields stay in their own section on the quoted
  line item, placed directly under ERP-Epicor's Epicor Cost Worksheet panel (always on the view since ERP-Epicor
  1.2.106, closed at start since 1.2.107), with no cost worksheet value repeated in it, read-only where Kinetic owns
  them, and still there after an ERP-Epicor upgrade.
- **What the seller met.** rc100 to rc106 drew the Cost & capacity panel last on the quoted line item's record view,
  under Sugar's hidden "show more" panel and away from the cost worksheet panel: the overlay created its panel at the
  end of the view.
- **The change.** The overlay (applied at every metadata build; nothing stored) keeps the Cost & capacity panel
  directly after the Epicor Cost Worksheet panel whenever the view has it, and moves it there when a save of the view
  (Studio, for one) stored it somewhere else. On a view without that panel it is drawn last, as before. ERP-Epicor's
  layout installer reads the stored view without overlays, so an ERP-Epicor upgrade or REPLACE install cannot move it.
- **No cost worksheet value in it.** The section's list no longer names ERP-Epicor's five cost fields (material,
  material burden, labor, labor burden, subcontract). Every ERP-Epicor this package installs over draws them in its
  own panel, so they were never drawn in the section there; now they cannot be.
- **Read-only.** Expected Quantity and Expected Qty UOM are read-only on the line. The Bench connector extension reads
  them from Kinetic (SellingExpectedQty, DspExpectedUM), Kinetic winning, and never sends them, so a seller's edit
  lasted only until the next sync. Sales Category and Quantity Quoted stay editable: they are sent to Kinetic. The
  other 13 line fields were read-only already.
- **What every user sees after the install.** On every quoted line item: the Cost & capacity section moves up to sit
  under the closed Epicor Cost Worksheet panel, and Expected Quantity and Expected Qty UOM lose their pencil.
- **Tests.** `scripts/tests/bd_cost_capacity_section_test.php` (26 checks) runs ERP-Epicor 1.2.107's own
  ProductsLayout install over Sugar's stock quoted line item view, then the shipped overlays the way Sugar builds the
  view: the section directly under the cost panel, none of its eight values in the section and each drawn once, the
  read-only fields, rebuilds, ERP-Epicor re-applying its layout (an upgrade from a release that hid the panel, a
  REPLACE install), a section stored elsewhere, and three controls. 10 of 26 fail on rc106; 26 of 26 pass on rc107.
  ERP-Epicor's two layout files are byte copies under `scripts/tests/fixtures/rc107/`, held to erp-integration-sugar
  52c2d0eb by `test_rc107_layout_pins.py`. `bd_kinetic_fields_test.php`: the read-only check fails on rc106 (1 of 32)
  and passes on rc107. Nine mutants (no anchor, a cost field back in the list, either flag flipped, a stored section
  not moved, inserted above the panel, drawn twice, only a new section moved, the panel misnamed) each fail a check.
- **Not changed.** The fields, their values and labels, the section's label and order, ERP-Epicor's cost worksheet
  panel, and the quote and order views. No data changes on upgrade, and the sync is not affected.

# 0.9.46-rc106 — Project is optional on a quote; the order lines total reads "ERP Line Total"

rc106 = rc105 + one vardef key removed and one label changed by the install. No new field, setting or dependency.

**Install order on a Bench tenant:** rc106 as an upgrade over rc105 (ERP-Epicor 1.2.107 is already installed). No
Quick Repair: the install rebuilds the Quotes and ERP_Orders metadata. Reload the browser tab after the install. The
Bench connector extension's Submit Order refusal for a missing Project goes in its own release.

- **Project (owner, 2026-10-20: "benchdogs project field should not be required").** `bd_project_id` no longer
  carries `erp_required_until_synced`: a quote saves, goes to estimation and orders with Project empty. Epicor accepts
  an order line with no ProjectID (orders entered in Kinetic carry none). A picked Project still goes onto every order
  line, and the create form's default from the account's latest quote is unchanged.
- **The order lines total (owner, 2026-10-20, on Lynn's question).** `ERP_Orders.e_total_charges_c` holds Epicor's
  DocTotalCharges (the lines total) and read "Charges" beside the product's "ERP Misc. Charges" (DocTotalMisc). New
  tenants create it as "ERP Line Total"; on a tenant that has it, the install changes the label to "ERP Line Total"
  only while it still reads "Charges", so a label someone set by hand is kept.
- **Tests.** `scripts/tests/project_required_on_real_sidecar.cjs` (Sugar's own record view and create form, 26.1.0
  and 26.2.0): an unsent quote's Save asks for Ship Via and never for Project; a new ADM quote asks for the four
  pickers and Ship Via, not Project. It failed on rc105 (4 of 9) and passes on rc106 (9 of 9).
  `scripts/tests/bd_kinetic_fields_test.php`: the label checks fail on rc105 and pass on rc106 (32 of 32); a mutant
  that relabels unconditionally fails the three controls.
- **Not changed.** The fields, their values, the Project list and its prefill. No data changes on upgrade.

# 0.9.46-rc105 — Best Case Percentage is Kinetic's: read-only on the quote

rc105 = rc104 + one value in the quote record view's field list. No new field, label, setting or dependency.

**Install order on a Bench tenant:** rc105 as an upgrade over rc104 (ERP-Epicor 1.2.104 is already installed). No
Quick Repair: the record view's metadata is rebuilt by the install. Reload the browser tab after the install.

- **What the owner ruled (2026-10-19).** Lynn's mapping sheet ("NewMapping10142026") maps Sugar's Best Case % to
  Kinetic's BestCsPctCalc both ways, but Kinetic calculates BestCsPctCalc and takes no write to it. Of the two ways
  offered (send a seller's change to the editable BestCsPct, or show Kinetic's value read-only), the owner chose
  read-only.
- **What the seller met.** The quote record view drew Best Case Percentage editable. The Bench connector extension
  never sends it (it reads BestCsPctCalc, Kinetic wins), so a seller's change stayed in Sugar until the next sync
  painted Kinetic's value over it.
- **The change.** The quote record view draws Best Case Percentage read-only, like Expires Date and every other field
  that is Kinetic's alone. Confidence Percentage stays editable (it is sent to Kinetic's ConfidencePct).
- **Tests.** `scripts/tests/bd_kinetic_fields_test.php`: the quote header check now asks for Best Case read-only; it
  failed on rc104 (1 of 27) and passes on rc105 (27 of 27).
- **Not changed.** The field, its values and the extension's read of BestCsPctCalc. No data changes on upgrade, and
  the sync is not affected.

# 0.9.46-rc104 — Ship Via is required on every quote, red on a refused Save, no "(Required)" text

rc104 = rc103 + one field controller rewritten and one vardef key. No new field, label, setting or dependency.

**Install order on a Bench tenant:**
1. ERP-Epicor 1.2.101 first (REPLACE variant). It draws no "Required" text on the fields these packages require (the
   ERP lookups, the dependent pickers, the billing address, Ship To), and makes FOB required while the ERP company's
   "Orders Require an FOB" switch is on. With an older ERP-Epicor, Ship Via is still required after rc104, but Sugar
   writes "(Required)" into it after a refused Save. The manifest floor stays ERP-Epicor >= 1.2.4.
2. rc104, as an upgrade over rc103. No Quick Repair: the field is served with the module's metadata, which the install
   refreshes, and the vardef extension is rebuilt by the install. Reload the browser tab after the install.

- **What the owner ruled (2026-10-19).** "that's not how we make fields required we show them in red if you save";
  Ship Via required on Bench quotes "no matter what is the stage"; "only UI side make them required when you edit";
  "remove the required label we dont need that everywhere".
- **What the seller met.** rc96 to rc103 drew Sugar's "(Required)" label beside Ship Via while Submit Order would refuse
  the quote for want of it, and never made it required: a Save without a Ship Via went through, and the seller learned
  at Submit Order.
- **The change.**
  - This package's Quotes lookup field marks Ship Via's definition required on the view, with Sugar's
    `no_required_placeholder` flag, on every quote, at every stage, a quote the ERP sent included. Sugar's own check
    refuses a Save with no Ship Via and turns it red with Sugar's own message. The field no longer reads the account,
    the company or the ADM probe, and draws no label marker.
  - Browser only: Ship Via's definition stays optional, so the connector's quote writes are never asked for it. The
    send-time refusal (`BdAdmOrderRequirements`, Submit Order) is unchanged.
  - Cust. Group (`bd_customer_group_code`) carries Sugar's `no_required_placeholder`: this package's rule still requires
    it on an ADM customer not yet in the ERP, without the "(Required)" text. The Reference's flag is ERP-Core's own
    (ERP-Epicor 1.2.101): this package claims only its placement.
  - The pickers (Lead Source, Lead Type, Project, Campaign, Event) are unchanged here: ERP-Core's dependent pick list
    (ERP-Epicor 1.2.101) draws no "Required" text and keeps "Pick a campaign first".
- **Release note.** From rc104 a seller cannot save a Bench quote without a Ship Via, including a quote the ERP sent
  without one: an Epicor-born quote needs its Ship Via filled before the seller's next Save. No field shows
  "(Required)". No data changes on upgrade, and the sync is not affected.
- **Tests.**
  - `ship_via_required_on_real_sidecar.cjs` (12 cases, run by `test_ship_via_required_on_real_sidecar.py`; rewritten
    from the marker page): on Sugar 26.1.0 and 26.2.0, Ship Via is required on a saved quote of any company, at every
    stage, on a quote the ERP sent and on the create form; a Save without it is refused by Sugar's own check, red, with
    no "Required" text before or after; beside ERP-Epicor 1.2.101's quote checks field, FOB is required only with the
    company switch on. CONTROL: the rc103 field (fixtures/rc103) draws "(Required)" and lets the Save through. Red on
    rc103: 8 of 12.
  - `test_ship_via_required_rule.py` (new): the id field the controller requires is ERP-Core's Ship Via relate's,
    nothing this package serves requires Ship Via, and its scripts stay module client files.
  - `project_required_on_real_sidecar.cjs`: its pins that an Epicor-born quote saves with Ship Via empty are
    rewritten: the Save is refused for Ship Via alone, and goes ahead once it is picked, with Project and the pickers
    empty.
  - Mutation checks, each red: never required (8 fail); required on every lookup, FOB too (6); only on a new quote
    (6); the old label marker drawn too (8); the controller naming FOB (1); a vardef serving Ship Via required (1).
  - The real-Sidecar pins move to erp-integration-sugar 16ec7cd3 (ERP-Epicor 1.2.101). The shared-sugar pins stay at
    4a51cc15 (re-pinning them needs Partial Fulfillment's new stage-rules class pinned too).
  - Not proven here: ERP-Epicor's own Quotes views (the stock ones are drawn here; ERP-Epicor's tests draw its views
    with a required Ship Via, an empty one shown in detail rather than hidden), the 26.2.0 record templates, CSS.

# 0.9.46-rc103 — the default owner page shows its help sentence once

rc103 = rc102 + one line removed from one page template (a comment there says why). No ERP-Epicor or ERP-Core file
changes, and no new field, label or setting.

**Install order on a Bench tenant:**
1. ERP-Epicor as for rc102 (1.2.93 or later; the manifest floor stays 1.2.4).
2. rc103, as an upgrade over rc102. No Quick Repair: the page's template is served with the Administration module's
   metadata, which the install refreshes. Reload the browser tab after the install.

- **What the admin met.** On both hosted Bench tenants (rc102 with ERP-Epicor 1.2.94, 2026-10-18), Admin > ERP
  Integration > Default owner for ERP-created records printed its help sentence ("Owner for Opportunities and ERP
  orders the integration creates without one. Leave it empty to turn this off.") twice under the field.
- **Why.** The field is Sugar's own relate field, and its definition carries the help (`'help' =>
  'LBL_BD_DEFAULT_OWNER_HELP'`). Sugar's relate field draws its definition's help under the box on an edit template
  (`clients/base/fields/relate/edit.hbs`), and the page's template (`bd-default-owner.hbs`) printed the same help again
  in a `<p class="help-block">` of its own. Neither ERP-Core nor ERP-Epicor draws anything on this page.
- **The change.** The template's own help line is removed. The help sentence is now the one Sugar's field draws, under
  the box. The field definition, the label, the help words, the link and the endpoint are unchanged. `hide_help` on the
  field was not used: Sugar 26.1.0 reads it from a different place than 26.2.0 (it hid the help on 26.2.0 only).
- **Tests.**
  - `default_owner_page_on_real_sidecar.cjs` (18 cases, was 14, run by `test_default_owner_page_on_real_sidecar.py`):
    on Sugar 26.1.0 and 26.2.0 the page shows the help sentence once, in one help line, drawn by Sugar's relate field
    from its definition, under the box. Its CONTROL is the rc102 template (fixtures/rc102, sha256-pinned) on the same
    page, which shows it twice. Red on rc102: 2 of 18 fail (the once case on each Sugar).
  - Mutation checks, each red: the definition's help removed (no sentence, 6 fail); the template's line back (two, 2
    fail); `hide_help` on the field (none on 26.2.0, 3 fail); the help drawn by the template with the field's hidden
    (one sentence, but not the field's, on 26.2.0; two on 26.1.0, 3 fail).
  - Not proven here: the page's tests run 26.1.0's recorded relate edit template on both Sugars. That 26.2.0's own
    template draws the help is shown by the tenants: the page's template drew one copy, and they showed two.

# 0.9.46-rc102 — FOB's "(Required)" is ERP-Epicor's alone; Ship Via's stays this package's

rc102 = rc101 + one change to one field controller. No ERP-Epicor file changes, and no new field, column or setting.

**Install order on a Bench tenant:**
1. ERP-Epicor 1.2.93 or later first (1.2.94 is due). From 1.2.93 ERP-Epicor draws FOB's "(Required)" itself; with an
   older ERP-Epicor, FOB shows no marker after rc102, and Submit Order still refuses a quote without one. The manifest
   floor stays ERP-Epicor >= 1.2.4: nothing this package runs needs a newer one.
2. rc102, as an upgrade over rc101. No Quick Repair: the field is served with the module's metadata, which the install
   refreshes. Reload the browser tab after the install.

- **What the seller met.** ERP-Epicor 1.2.93 draws FOB's "(Required)" while the ERP company's "Orders Require an FOB"
  switch asks for it. This package's Quotes lookup field drew its own FOB marker too: a copy for the same switch, and
  one on an ADM company whatever the switch said. On a Bench tenant with the switch off, FOB read "(Required)".
- **The change.** The field no longer draws an FOB marker. The FOB entry is removed from its order rules, with the
  company-switch handling that only FOB used. Ship Via's "(Required)" (this package's rule and the connector
  extension's, on an ADM company) is drawn exactly as in rc101, and the Project rule (`erp_required_until_synced`) is
  unchanged.
- **What it means on an ADM company.** With "Orders Require an FOB" off, FOB shows no "(Required)", but the Bench
  connector extension still refuses an ADM order with no FOB (and no customer default) at Submit Order, naming it.
  With the switch on, FOB shows one "(Required)", ERP-Epicor's.
- **Tests.**
  - `test_quote_needed_markers.py` (the shipped controller under node): FOB carries no marker on any company or
    switch value, every Ship Via case is as before, no company switch is read, and the browser copy holds the Ship
    Via rule only. Red on rc101: 18 of 28 fail.
  - `quote_needed_markers_on_real_sidecar.cjs` (20 cases, run by `test_quote_needed_markers_on_real_sidecar.py`)
    draws ERP-Epicor's quote checks field in the record view's header beside this field, on Sugar 26.1.0 and 26.2.0.
    With the switch off FOB carries no "(Required)"; with it on, exactly one, ERP-Epicor's. Its CONTROL is the rc101
    controller (fixtures/rc101) on the same page, which draws FOB's marker with the switch off. Red on rc101: 10 of 20.
  - `project_required_on_real_sidecar.cjs`: the Epicor-born quote still saves; Ship Via is still marked.
  - The real-Sidecar pins are taken again from erp-integration-sugar at ERP-Epicor 1.2.94 (d33ab0ae), adding the
    quote checks field. Every file pinned before is byte-identical.

# 0.9.46-rc101 — an Epicor-born quote saves without a Project; a new quote still asks for one

rc101 = rc100 + one change to one field's metadata. No ERP-Epicor file changes, and no new field, column or setting.

**Install order on a Bench tenant:**
1. ERP-Epicor 1.2.92 first where it is due (it is independent of this change: rc101 still needs only ERP-Epicor
   >= 1.2.4, whose pick list field reads the key rc101 uses).
2. rc101, as an upgrade over rc100. No Quick Repair: Module Loader rebuilds the modules' extensions on install and
   clears the API metadata cache, so the field's new rule is served at once. Reload the browser tab after the install.

- **What the seller met.** On the hosted Bench tenant (rc100, 2026-10-17) a quote created in Epicor and read into
  Sugar could not be edited: Save stopped with "Please complete all required fields before saving". Project was the
  field of this package that stopped it. Its metadata asked ERP-Core's pick list field to require it on every quote
  while the tenant offers ADM projects, and an ERP quote never carries a Project (it is the order lines' ProjectID;
  nothing reads it back), so every quote that came from Epicor was refused.
- **The change.** Project now uses the same rule as Lead Source, Lead Type, Campaign and Event: required on the
  quote's screens only while the quote is not in the ERP (`erp_required_until_synced` in place of
  `erp_required_when_options` on `bd_project_id`). ERP-Core's field decides that from the quote's two ERP keys: a new
  quote, or a saved one whose `erp_sync_key` and `erp_display_sync_key` are both empty, is not in the ERP yet; a
  quote either key names is. Unchanged: the create form fills Project from the product groups and the account's
  history as before, and the Bench connector extension refuses an order with no Project at send, naming it.
- **Also for quotes raised in Sugar:** once a quote has been sent to the ERP (Send to Estimation stamps its keys), the
  quote's screens no longer stop a save that leaves Project empty. Submit Order is still refused without one.
- **Not this package, and not changed here:**
  - FOB and Ship Via read "(Required)" on such a quote because Submit Order would refuse it for want of them. That
    marker never makes the field required and never stops Save (unchanged since rc96).
  - Ship-To is ERP-Epicor's rule: every quote's shipping address is required at save, unless its billing account has
    no shipping address at all. A quote that came from Epicor with no ship-to on an account that has addresses still
    needs one picked before it saves.
- **Tests.** `project_required_on_real_sidecar.cjs` (9 cases, run by `test_project_required_on_real_sidecar.py`) runs
  Sugar's own record view and create form (26.1.0 and 26.2.0) with ERP-Core's real pick list and lookup fields
  (`erp-dependent-enum.js` newly pinned in fixtures/shared-sugar, at the commit the other pins use) under this
  package's shipped vardef and marker: the Epicor-born quote (as read back, and with every picker empty) saves with
  Project, FOB and Ship Via empty, FOB and Ship Via still marked; the same quote with no ERP keys asks for Project; a
  new quote's form asks for Project and the other four pickers, never FOB or Ship Via. Red on rc100: the Epicor-born
  case fails on both Sugars, Save refusing `bd_project_id` and no other field, which the page's control (the rc100
  vardef, fixtures/rc100) keeps showing. Five vardef mutants (Project with no key, with the old key, with both; Lead
  Source required on every quote; Lead Type with no key) each fail it. `bd_adm_rules_test.php` M8/M9 hold the new keys.

# 0.9.45-rc100 — Bench Dogs' Kinetic fields, its Opportunity stages, and the default owner link in ERP Integration

rc100 = rc99 + these three changes. The fields are the ones Bench Dogs' additional mapping names (sheet
NewMapping10142026; the owner's request approves exactly those); the stage list and the link's place are the owner's
rulings of 2026-10-17. No ERP-Epicor file changes.

**Install order on a Bench tenant:**
1. rc100, as an upgrade over rc99 (ERP-Epicor >= 1.2.4 and Partial Fulfillment as before).
2. **Before the Bench connector extension 1.0.30.** 1.0.30 writes the new fields; a tenant without one refuses the
   whole record that carries it.

- **The Kinetic fields.** post_install creates each of the 23 fields a tenant lacks, as a Studio custom field (Sugar's
  own Studio field code: the fields_meta_data row, the `_cstm` column, the label), with the existing Studio fields'
  types and sizes, the mapping's places (2, or 3 for the confidence) and its Kinetic field names as labels:
  - Quotes: Expires Date (date), Best Case Percentage (decimal, 2), Confidence Percentage (decimal, 3);
  - Quoted line items: Expected Quantity, Quantity Quoted, the engineering, machine, finish, assembly, case goods
    and install hours and the material, labor and material and labor percentages (decimal, 2), Expected Qty UOM
    (text 50), Sales Category (drop-down `e_sales_category_list`), Gross Margin, Total Cost, Total Markup, Total
    Markup Price (currency, 2);
  - ERP orders: Project Manager, Engineer (text 100), Charges (currency, 2).

  **A field a tenant already has is never altered or created again**, so a tenant that holds them as Studio fields
  keeps them exactly; one with none gets all 23. The install report names what was created and how many were kept,
  and a field that could not be created, without stopping the others. Module Loader's own `custom_fields` installdef
  is not used: it updates a field that exists and deletes the fields on uninstall.
- **Where they show.** A view overlay, applied at every metadata build (no stored layout changes; ERP-Epicor's
  layout files are never written): the quote's three fields in the ERP panel above the status block; the line
  fields in a **Cost & capacity** panel on the quoted line item's record view, in the mapping's order; the order's
  Project Manager, Engineer and Charges in its ERP panel, else its body. Only fields the tenant has are drawn; a
  field already on the view stays where it is. ERP-Epicor's five cost fields (material, material burden, labor,
  labor burden, subcontract) are on the line's record view on both Bench tenants (measured), so they stay in
  ERP-Epicor's panel; the overlay adds them to Cost & capacity only where no layout draws them. The quote's line
  worksheet is unchanged. Kinetic's own fields are read-only; the two-way ones (best case, confidence, expected
  quantity and unit, sales category, quantity quoted) stay editable.
- **The Sales Category list** is defined only where a tenant has none: ADM's Kinetic sales categories (codes as
  keys, Kinetic's descriptions as labels), the codes the connector extension writes. A tenant's own list is kept.
  **Not verified:** whether one Bench tenant's existing Studio list is keyed by those codes; the live read checks it.
- **Opportunity stages.** `sales_stage_dom` and `sales_probability_dom` are Bench Dogs' list, in this order:
  Prospecting "Qualifying_Designing" 10, Estimating 20, Proposal/Price Quote "Quote Delivered" 25,
  Negotiation/Revision 50, Verbal commit/PO Pending 75, Prototype Ordered 80, Partial Production Ordered 90, Closed
  Won 100, Closed Lost 0. No record is remapped; every key the integration writes stays a key. The fragment merges
  after ERP-Core's stage template whatever installs last (Sugar merges `_override_` fragments after plain ones), and
  Partial Fulfillment's two stages keep its labels and probabilities in either order. The three new stages get a
  style each (a formatted enum draws an unstyled key blank). **A stage edited in Studio does not stick**: this list
  is served over it, as ERP-Core's was. A stage key another package or Studio adds is not served.
- **The default owner link** is now inside Admin > ERP Integration, after ERP Sales Reps (a Bench Dogs box only on
  a tenant without ERP-Core's group). rc99's file for the link ships empty, because an upgrade keeps a copied file.
- **Uninstall keeps every field and its data**: they are Studio fields, which Module Loader does not remove. The
  overlays, the stage list, the Sales Category list and the link go with the package: the fields then show only
  where a layout places them, a Sales Category value shows its code where no list of the tenant's own defines it,
  and the stages fall back to ERP-Core's and Partial Fulfillment's.
- **Tests.** `bd_kinetic_fields_test.php` (27 checks): which fields an install creates (none on a tenant holding
  all, all 23 on one holding none, never one a tenant has, none on a module whose fields cannot be read), how the
  three shipped overlays draw them, and the list; red on the rc99 tree; 6 mutants each fail a check.
  `test_bench_opportunity_stages.py` (9 cases): Sugar's merge executed over ERP-Core's and Partial Fulfillment's
  real fragments in three install orders yields exactly the list; 5 fail on rc99; 6 mutants caught.
  `test_default_owner_admin_link.py` (7 cases): the link inside ERP-Core's group in both install orders, its own
  box without it, the emptied rc99 path; 4 fail on rc99; 3 mutants caught.

# 0.9.44-rc99 — a default owner for ERP-created records

rc99 = rc98 + this change only. One new setting, approved by the owner on 2026-10-16 for this package only; nothing
in ERP-Epicor changes. No field, vardef, record-view layout or dropdown added. The minor version moves because the
package now writes a record field (the owner) on save.

**Install order on a Bench tenant:**
1. rc99, as an upgrade over rc98 (ERP-Epicor >= 1.2.4 and Partial Fulfillment as before). No Quick Repair.
2. **Before the Bench connector extension 1.0.29.** From 1.0.29 the extension creates its order and quote
   Opportunities with no owner (the field left out) and relies on this package to fill it.
3. **Then the owner picks the default user** in Admin > Bench Dogs > Default owner for ERP-created records, and
   saves, **before the order and quote re-reads run.** Until a user is picked the setting is off and those
   Opportunities and ERP orders stay unowned, as they would without this package.

- **What Sugar does without it (measured in the SugarEnt 26.1.0 source).** A record created through the REST API with
  no `assigned_user_id` keeps an empty owner: `ModuleApi::createBean`, `IntegrateApi::upsertByField` and
  `SugarBean::save` fill nothing. Only the browser's create view puts the signed-in user there. So an Opportunity or
  an ERP order the integration creates without an owner stays unowned.
- **The setting.** "Default owner for ERP-created records", on a new Bench Dogs section in Admin. It is Sugar's own
  lookup over Users (type part of a name, pick from the list; Sugar's own search leaves out Inactive users), shown by
  the saved user's name, stored as that user's id (`benchdogs_ext` / `default_owner_user_id`). Clear it and save to
  turn it off. The server refuses any id that is not an active user (Inactive, Sugar's Reserved support users,
  deleted, unknown) and stores nothing; the page shows its reason. If the saved user is later made inactive, the page
  says so and nothing is filled until another user is picked.
- **The hook.** Only on a save the integration makes, told by the request's platform (`sugarai_erp_connector`, the
  connector's own API platform, the constant rc90's lead baseline already reads), never by the user: when an
  Opportunity or an ERP order is saved with an empty owner and the setting names an active user, the owner becomes
  that user. **A seller's own save is never filled:** an owner a seller clears in the browser stays empty. An owner
  that is set is never changed. A failed read leaves the owner empty, logs an error and lets the save go on.
- **Operational side effects:**
  - records already saved with no owner get the default owner the next time the integration saves them (its next
    update of that record);
  - a seller who clears an Opportunity's owner keeps it empty; saves from the mobile app, the portal, a job or the
    seed loader (platform `epicor_seed`, not the connector's) are not filled either;
  - where a tenant's connector has its own default owner configured, the connector stamps that one first, and the
    hook leaves it;
  - **Sugar's assignment notifications follow the owner the hook writes.** Sugar 26.1 decides whom to notify after
    the before_save hooks have run (`SugarBean::saveData`: the hooks, then `_sendNotifications` and
    `sendPushNotificationOnAssignment`), a save with no owner passes the API's notify check, and the connector's
    platform keeps notifications on. So each record the hook fills notifies the default owner as any assignment
    does: an assignment email when Admin's "Notify on assignment" is on and that user receives assignment emails, and
    Sugar's own assignment notification. **A re-read that fills many records sends that many assignment
    notifications. None is sent if the default owner is the integration's own user (the admin user it signs in
    as).** Pick the default user with that in mind;
  - each save with an empty owner reads the setting and the user (Sugar caches both within a request).
- **Tests.**
  - `bd_default_owner_test.php` (90 checks): the hook on both modules, including an Opportunity created the way the
    REST path creates one with no owner in its body (the bean has no `assigned_user_id` at all) and one that names
    its owner (kept); who saves: the integration's save of an owner-less Opportunity and of an ERP order is filled,
    a seller's browser save that clears an Opportunity's owner stays empty, and mobile, portal, seed-loader and
    no-platform saves are not filled and read nothing; the admin user's id `'1'`, which Sugar's config hands back as
    the integer 1; the endpoint. Red before the class existed (2 checks, 2 failed), before the endpoint existed
    (class not found), and, for who saves, on the first rc99 hook (90 checks, 11 failed: the seller's cleared owner
    was refilled).
  - `default_owner_page_on_real_sidecar.cjs` (14 cases, Sugar 26.1.0 and 26.2.0): Sugar's own Sidecar, relate field
    and select2 draw the page; the picker searches Sugar's Users with Sugar's own "not Inactive" term and lists them
    by name; Save stores the picked user's id through the real endpoint; a cleared box stores empty; a refused user
    shows the endpoint's reason and nothing is stored. Red without the page. Sugar's Filters model and collection,
    the relate pill template, the Users filter definitions and five Sugar words are recorded from SugarEnt 26.1.0
    under `scripts/tests/fixtures/stock-sugar/` (26.1.0's run on the 26.2.0 page too).
  - 31 mutants, all killed (11 on the hook, 5 on who saves, 8 on the endpoint, 7 on the page). Who saves: no
    platform check, the check inverted, the browser's platform in its place, any platform accepted, and the check
    moved after the reads. One equivalent pair noted: the string return type and the cast in `savedUserId` overlap;
    removing both is killed.
  - The hook census that guards "the sync never creates an Opportunity" lists the two new registrations and now holds
    every allowed hook's class to creating and saving nothing.
- **Not proven here:** a tenant's Admin panel drawing the link, the 26.2.0 Filters code (26.1.0's runs), CSS, and the
  connector's real writes. After the install: open Admin > Bench Dogs > Default owner for ERP-created records, pick a
  user, Save, reload the page (the name stays); then have the connector create an Opportunity with no owner and read
  its owner back, and clear an Opportunity's owner in the browser, save, and read that it stays empty.

# 0.9.43-rc98 — FOB and Ship Via show (Required) on the create form too, from the account's company

rc98 = rc97 + this change and a test cleanup. **Install order on a Bench tenant:** unchanged from rc97 (the Bench
connector extension 1.0.26+ with its ADM companies row landed, ERP-Epicor >= 1.2.4, PF as before), as an upgrade over
rc97; nothing else to run. No field, setting, vardef, layout or dropdown added; nothing stored changes. The minor
version moves because the marker now reads a different record first (below).

- **What the seller sees.** A new quote's create form, opened from an ADM account that holds no default FOB, reads
  "FOB (Required)" and "Ship Via (Required)" while Submit Order would refuse that quote for both, as the saved quote's
  record view already did. Seen on benchdogs-dev on 2026-10-16: a plain "FOB" on that form (account EAST PENN
  MANUFACTURING), the ADM companies row present and active.
- **Why rc97 drew nothing there, two reasons.** (1) It drew only on the record view of a saved quote. (2) It took
  the ERP company from the quote's own company field, while every server rule it restates reads the billing
  account's (ERP-Epicor's ErpQuoteFacts: the "order requires FOB" switch, and the company code the ADM rules ask
  about), so a new quote, or a saved one whose own field is empty, read as no company. On benchdogs "FOB (Required)"
  showed only because that company's switch is on and the quote was a saved one.
- **Now** the marker draws on the record view of a saved quote and on the create form of a new one, and reads the
  billing account first (its ERP company and its two defaults), then that company and the ADM probe: still three
  reads, shared by both fields. The rules are unchanged: on an ADM company FOB shows whatever "order requires FOB"
  says; Ship Via shows only on an ADM company.
- **Nothing waits for a Quick Repair.** The marker is a module field controller, which Sugar serves with the module's
  metadata, so this install reaches the browser on its next page load. The package adds nothing to a script bundle
  (a new test holds every script it ships to module client files). It still rides ERP-Core's erp-lookup field and its
  retype of the two relates, which ERP-Core ships in its own script bundle.
- **Operational:** opening a quote's create form now makes the same three reads the record view makes.
- **Tests.** The real-Sidecar page test (`quote_needed_markers_on_real_sidecar.cjs`, Sugar 26.1.0 and 26.2.0) now
  also opens Sugar's own create form (CreateView and the stock Quotes create view): a new quote from an ADM account
  shows both markers with the switch off and on; a saved quote whose own company field is empty shows both; the rc97
  controller (`fixtures/rc97/erp-lookup.js`) is the control and draws no FOB marker in either shape. Red on rc97 (4
  page cases, 6 driver cases), green here (16 of 16); 5 mutants, all killed. `test_quote_needed_markers.py` adds the
  create form, the account's company and the field name held to ErpQuoteFacts.
- **Test cleanup (no package change).** The tombstone tests that held long-retired paths out of the package are
  gone (the bd01 quote mirror suite, and the not-shipped cases of the create-opp-quote button, the Kinetic
  Opportunity pairing, the retired stage names and the Bench panel suites): the kept-list census pins every file the
  package ships, source and zip. Every case that guards what ships today stays.
- **Not proven here:** a tenant's own Quotes create view (ERP-Epicor ships one over the stock view) and CSS. After the
  install, open a new quote from an ADM account with no default FOB and read the FOB label.

# 0.9.42-rc97 — ADM's rules follow the companies the Bench connector extension publishes

rc97 = rc96 + this change only. **Install order on a Bench tenant:** the Bench connector extension 1.0.26 approved,
and one of its `benchdogs_adm_companies_<co>` runs proven (an active `ERP_LookupValues` row of type `BdAdmCompanies`,
e.g. `ADM__BdAdmCompanies_ADM`); THEN rc97 as an upgrade over rc96 (with ERP-Epicor >= 1.2.4, PF as before; this
round ships with ERP-Epicor 1.2.86). No field, setting, vardef, layout or dropdown added; nothing stored changes.
Owner approval: FIX-LEDGER #101 (2026-10-15), one source for which ERP company gets ADM's rules.

- **Which company is ADM's.** Read from the active `BdAdmCompanies` rows the extension publishes from its
  `SUGARAI_BD_ADM_COMPANIES`, the same setting that registers its own ADM refusals. Before rc97 this package treated
  every company with published lead sources as ADM's, which core publishes for any connection with those codes, so
  the two could disagree. Lead sources alone no longer make a company ADM's. Applies to the ADM quote defaults
  (Reference, Project, the account's history), the Ship Via refusal at Submit Order, and the two markers below.
- **FOB "(Required)" follows the extension's refusal too.** On an ADM company the FOB marker now shows whatever the
  company's "order requires FOB" says, because the extension refuses an ADM order with no FOB on the quote when the
  customer has no default FOB, and any order converted from its ERP quote with no FOB on the quote (seen on
  benchdogs-dev on 2026-10-15: the switch off, no marker, the order refused for FOB). On any other company the FOB
  marker follows the switch as in rc96, and Ship Via shows only on an ADM company, as in rc96.
- **Failure direction.** No published row (the extension not yet on 1.0.26, its run not yet landed, or a read that
  fails): no company is ADM's on the Sugar side, so the ADM defaults and the Ship Via marker and refusal go quiet;
  the extension's own send-time refusals still stand. Nothing is ever refused or defaulted on a guess.
- **Operational side effect:** opening a quote's record view reads one `ERP_LookupValues` probe of the new type
  instead of the lead-source probe (same count of reads). The new type has no label in `erp_lookup_type_list`, so an
  admin list of ERP Lookup Values shows its rows with an empty Type.
- Tests: `bd_adm_rules_test.php` (A3-A10: the active published rows decide, an inactive row does not count, lead
  sources alone do not), `bd_order_requirements_test.php` (the ADM rows are the published ones),
  `test_quote_needed_markers.py` (25 cases: an ADM company shows FOB whatever its switch, a non-ADM company only with
  it on, an ADM order from its ERP quote needs an FOB on the quote, lead sources alone are not ADM, the probe asks for
  active rows), and the new **real-Sidecar page test** `quote_needed_markers_on_real_sidecar.cjs` (Sugar's own
  Sidecar, RecordView and record-decor, 26.1.0 and 26.2.0, with ERP-Core's real erp-lookup field: an ADM company
  with the switch off shows both markers, a non-ADM company none or FOB only; the rc96 controller as the control draws
  no FOB marker there, as benchdogs-dev showed). Red on rc96, green on rc97; 7 mutants, all killed.

# 0.9.42-rc96 — the quote shows FOB and Ship Via as needed before Submit Order refuses for them

rc96 = rc95 + this change only. Install order on a Bench tenant: unchanged (ERP-Epicor >= 1.2.4 → PF → rc96), as an
upgrade over rc95; nothing else to run. No field, setting or layout added; nothing stored changes.

- **What the seller sees.** On a quote's record view (and in its edit mode), FOB and Ship Via read "FOB (Required)"
  and "Ship Via (Required)", Sugar's own required marker, while Submit Order would refuse the quote for want of them:
  - FOB: the quote's ERP company has "Order requires FOB" on, and neither the quote nor its billing account holds an
    FOB (ERP-Epicor's refusal);
  - Ship Via: the quote's ERP company is ADM (it has published lead sources), the quote holds no Ship Via, and either
    the quote is an Advanced Quote with an ERP quote (its order takes the ERP quote's Ship Via, never the customer's)
    or the billing account holds none (this package's refusal).
  When the account has a default, or the seller picks a value, the marker goes at once; clearing the value brings it
  back. A closed quote, a quote not saved yet, the create form, previews and lists show none.
- **A marker only: Save is never blocked.** The field is never made required, so Sugar's save check never looks at
  it; Submit Order's own refusal stays the rule and is unchanged. (Sugar's SetRequired dependency was not used: it
  makes the field required, which refuses every Save of the quote, and no dependency can read the account's
  defaults.)
- **How:** the package's first JavaScript, a Quotes override of ERP-Core's `erp-lookup` field. Per record view it
  makes three standard reads, shared by both fields: the ERP company (`erp_sync_key`, `erp_order_requires_fob`), the
  billing account (its default FOB and Ship Via ids) and one ERP_LookupValues probe (does the company have lead
  sources). A read that fails or is refused draws nothing and is asked again on the next render. Every other ERP
  lookup on Quotes is ERP-Core's field, unchanged.
- **Known limits (the refusal still decides):** the browser judges the Advanced Quote route by the quote's ERP key,
  where the server judges it by the lines that convert the ERP quote; a lookup row with no ERP code counts as a value
  in the browser; and a quote with no lines can show the marker, where Submit Order refuses it for having no lines
  first. Each can only miss or add a marker; none changes what Submit Order sends or refuses.
- **Operational side effect:** opening a quote's record view on a Bench tenant makes up to three more small reads
  (above). Nothing is written.
- Tests: `scripts/tests/test_quote_needed_markers.py`: 16 cases drive the shipped controller under node
  (`quote_needed_markers.cjs`) on the shapes read on benchdogs for quote 7069 (FOB and Ship Via empty on the quote
  and on its account, ADM, "Order requires FOB" on): both markers; each account default clears its marker; a pick
  clears it and a clear brings it back; another account is read for its own defaults; no Ship Via marker for a
  company without lead sources; no FOB marker when the switch is off; the ERP-quote route ignores the account's Ship
  Via; closed stages, failed reads, other views, unsaved quotes and other lookups show none; a re-render keeps one
  marker; an answer overtaken by the seller is not drawn; `def.required` is never set. Four more cases hold the
  browser's copy of the field names to ERP-Epicor's `ErpQuoteFacts` and this package's `BdAdmOrderRequirements` /
  `BdAdmRules`, both ways. All 20 fail on rc95. 15 mutants, all killed. `test_g280_minimal_footprint.py` KEPT gains
  the file.

# 0.9.42-rc95 — the key and the link for one Opportunity per Epicor order with no Sugar quote

rc95 = rc94 + this change only. Install order on a Bench tenant: ERP-Epicor >= 1.2.4 → PF → rc95, as an
upgrade over rc94; THEN the Bench connector extension 1.0.18 (it needs these two fields first: without them Sugar
refuses every Opportunity it sends). Owner approval: these two fields, in this package only (2026-10-14).

- **`Opportunities.erp_sync_key`** (unique, not copied when a seller copies an Opportunity, on no layout): the key the
  connector creates and finds the Bench Opportunity by. The extension keys an order's Opportunity
  `<order key>_order_opportunity` and an Epicor-born quote's `<quote key>_opportunity`, creates it once and never sends
  it again, so its amount and owner stay as a seller leaves them.
- **The Opportunity an ERP order sits under:** `ERP_Orders.bd_opportunity_id` (a real column, read-only, on no layout),
  its relate and the one-to-many relationship `bd_opportunity_erp_orders` (link `bd_erp_orders` on the Opportunity). The
  connector sets it when it creates the order's Opportunity; nothing else does.
- **Columns:** created by Module Loader's own database repair at the end of the install (all modules, execute on). No
  default on either field, so no existing order or Opportunity is written. Like every `bd_*` column, they stay in the
  database after an uninstall.
- **Not in this package:** no Opportunity is created here (the extension does it through the connector); no subpanel or
  record-view placement for either field.
- Tests: `scripts/tests/test_bench_opportunity_fields.py` (11 cases; all red without the four files; each mutant red: the
  id made non-db, the unique index dropped, the key made required, a layout marker added, the relationship on the wrong
  column, a default on the key). `test_g280_minimal_footprint.py` KEPT gains the four files.

# 0.9.42-rc94 — a new product's Part Number is the seller's to type

rc94 = rc93 + this change only. Install order on a Bench tenant: unchanged (ERP-Epicor >= 1.2.4 → PF → rc94), as an
upgrade over rc93; nothing else to run. No field, setting or layout added; nothing stored changes.

- **The seller types a new product's Part Number.** On a quote line for a product that is not in the catalog (the
  Send to Estimation flow), the Part Number is no longer the gray read-only box once the line's Product Group is
  picked: the seller types the part number the product will have, and it is saved on the line. ERP-Epicor sends a
  catalog-less line's own Part Number, so Send to Estimation puts the typed value on the Epicor quote line's PartNum
  instead of a `NEW-Q<quote>-L<line>` placeholder. A catalog product's line, a blank new row and any line the ERP
  already holds keep the read-only Part Number; every other customer keeps ERP-Core's rule as it ships.
- **What changes with a typed part (ERP-Epicor's rules, unchanged):** ERP-Epicor recognises a new product by its
  placeholder (`TO-BE-CREATED`, then `NEW-Q…`), so a line whose part number was typed is treated like any other
  line from then on: Send to Estimation asks for its Unit Price (a placeholder line may leave the price to the
  estimator), the Product Group picker hides once the placeholder is typed over (the group picked before stays and is
  sent; this is why the box opens only after the group is picked), and Submit Order does not wait for the estimator
  to create the part: the connector orders a part Epicor's Part master does not hold as an on-the-fly part. Leaving
  the Part Number as `TO-BE-CREATED` keeps the estimation flow exactly as in rc93.
- Tests: `scripts/tests/bd_new_product_part_number_test.php` (48 checks; 12 red without the rule, every mutant red:
  a catalog line opened, the group or ERP gate dropped, a second rule beside ERP-Core's, the file merged first);
  ERP-Core's rule is pinned under `scripts/tests/fixtures/shared-sugar/` at the same upstream commit as the rest, and
  the dependency-trigger test now reads each file over that rule.

# 0.9.42-rc93 — an ADM Sales Order's first Submit Order names the Ship Via with the FOB

rc93 = rc92 + this change only. Install order on a Bench tenant: unchanged (ERP-Epicor >= 1.2.4 → PF → rc93), as an
upgrade over rc92; nothing else to run. No field, setting or layout added; nothing stored changes.

- **A Sales Order with no Ship Via is now judged in Sugar.** On an ADM quote whose order converts no ERP quote line
  (a Sales Order), Kinetic fills a blank Ship Via from the customer's default, which the connector's account sync keeps
  on the billing account's Ship Via (from the ERP customer's Ship Via, SDK 1.20 and later). When the quote has no Ship
  Via and its billing account has none either, Submit Order and Order Selected Lines are refused before anything is
  sent, in the same refusal as ERP-Epicor's FOB, so the first click names both: *"This quote has no FOB and its
  customer has no default FOB in the ERP, so it cannot be ordered. Pick an FOB on the quote, then submit again.
  Nothing was sent to the ERP. This quote has no Ship Via and its customer has no default Ship Via in the ERP, so it
  cannot be ordered. Pick a Ship Via on the quote, then submit again. Nothing was sent to the ERP."* Until now the
  first click named the FOB alone, and the Ship Via came back from the connector extension 20 to 30 seconds after
  the second click. An account that holds a Ship Via is not refused; an order that converts an ERP quote is judged
  as in rc89 (on the ERP quote's Ship Via, never the customer's). The extension still checks both before the send.
- **Known exposure (the same as ERP-Epicor's FOB rule):** an account whose Ship Via is empty only because it has not
  been re-read since the account sync started carrying the customer's Ship Via is refused in Sugar although the ERP
  would complete the order. Picking a Ship Via on the quote clears it. A read that fails is not a refusal: it is
  logged (`BenchDogs-Ext: ADM order requirements skipped for quote …`) and the extension judges the order instead.
- Tests: `scripts/tests/bd_order_requirements_test.php` (48 checks; 6 red on rc92: the Sales Order route, the
  2-part key's route, the failed read's log line).

# 0.9.42-rc92 — Shipping Provider off the Bench quote screen; the lead fields read-only once the quote is ordered or closed

rc92 = rc91 + these changes only. Install order on a Bench tenant: unchanged (ERP-Epicor → PF → rc92), as an upgrade
over rc91; nothing else to run. No field added; nothing stored changes.

- **Shipping Provider is no longer shown on the quote** (record, create and edit views, and the Quotes list preview).
  It is Sugar's stock `shipper_name`: nothing sends it to Epicor (the shipping method Epicor gets is Ship Via) and
  Bench Dogs has no shippers. Only the view changes, and only where this package is installed: the field and its data
  stay, and stock/et keep it. Ship Via and every other field stay where they are.
- **Lead Source, Lead Type, Mktg Campaign and Marketing Event are read-only on a quote that is ordered or closed**
  (stage Closed Accepted, Partially Fulfilled, Closed Lost or Closed Dead). On such a quote Epicor keeps its own value
  and discards an edit, and the next read-back put Epicor's value back over the seller's. They stay editable while the
  quote is being quoted, and stay required until the quote is in the ERP, as before. Project is unchanged.
- Tests: `scripts/tests/bd_shipping_provider_hidden_test.php` and `scripts/tests/bd_lead_fields_read_only_test.php`
  (both red on rc91; wired into `test_php_suites.py`).

# 0.9.42-rc91 — the Cust. Group rule re-checks when a new account's ERP company finishes loading

rc91 = rc90 + this change only. Install order on a Bench tenant: unchanged (ERP-Epicor → PF → rc91), as an upgrade
over rc90; nothing else to run.

- **A new ADM account can no longer be saved without a Group Code.** The Cust. Group requirement reads the account's
  ERP company through `related()`, which is empty until the company record loads; the load is announced only as a
  change of the link `erp_companies_accounts`, which was not one of the rule's triggers, so the rule stayed "not
  required" and the save went through. The link is now a trigger. Scope unchanged: ADM, type Customer, not yet in
  the ERP. Sugar's own required check refuses the save and flags Group Code.
- Tests: `scripts/tests/test_dependency_triggers_cover_their_formulas.py` (every shipped dependency formula variable
  is a trigger field; red on rc90).

# 0.9.42-rc90 — the lead baseline on the quote (one hidden field, owner-approved 2026-10-02)

rc90 = rc89 + these changes only. Install order on a Bench tenant: unchanged (ERP-Epicor >= 1.2.4 → PF → rc90).
Pairs with the connector extension 0.3.9, which reads and rewrites the field; install rc90 FIRST, then refresh
core's schema snapshot for the tenant, then move the extension's old memory onto the quotes (the extension's
`scripts/migrate_lead_memory_to_quotes.py`), then roll the extension.

- **New field, hidden:** `Quotes.bd_lead_baseline` (`text`, on no layout, not audited, reported, imported,
  mass-updated, copied with the quote, nor offered in Studio), with its label. It holds, as JSON, what Sugar and the
  ERP last agreed for Lead Source, Lead Type, Mktg Campaign and Marketing Event, plus the last ERP value of each. It
  replaces the SQLite file the connector extension kept in its container.
- **Stamped here:** the quote before_save records the four values (each non-blank one, trimmed) as agreed by both
  sides on the save that first gives the quote its ERP key, which is core's create during Send to Estimation. Only
  a save on the connector's platform (`sugarai_erp_connector`) counts, never a seller's: the platform tells them
  apart, not the user, because a connector may write as the same admin user sellers use. A quote created with its
  key (made from the ERP) is not stamped; a quote keyed again after its key was cleared is stamped afresh. A failure
  here is logged (`BenchDogs-Ext: lead baseline not stamped for quote …`) and never fails the save.
- Tests: `scripts/tests/bd_adm_rules_test.php` section V (14 checks, written first: all red on rc89), including the
  exact JSON the extension's own suite pins.

# (in rc90) — wording only: the quote defaults hook label names the account-history fill

- The Quotes before_save hook's label is now `Bench Dogs: ADM quote defaults (Reference, Project, account history)`
  (was `(Reference, Project)`), and its comment names the new-quote fill from the account's newest quotes that
  rc78/rc81 added. No behaviour change: the hook's class, method and order are unchanged.
- README: Lead Source / Lead Type and the Campaign + Event pair are no longer described as never defaulted; a new
  quote copies them from the account's history (rc78/rc81).

# 0.9.42-rc89 — G860: ADM's Ship Via named in ERP-Epicor's one pre-send order refusal (🔒2179b)

rc89 = rc88 + these changes only. Install order on a Bench tenant: **ERP-Epicor >= 1.2.4** → PF → rc89 (the manifest now
refuses an ERP-Epicor below 1.2.4: earlier ones never ask the new slot, so the rule would sit installed and unread).

- **New:** `custom/modules/Quotes/ErpQuoteHooks/OrderRequirements.php` (the adapter ERP-Epicor 1.2.4 instantiates) and
  `custom/src/BenchDogs/BdAdmOrderRequirements.php` (the rule). On an ADM quote whose order converts the ADM ERP quote
  and whose quote has no Ship Via, Submit Order and Order Selected Lines are refused before anything is sent, in the
  same refusal as ERP-Epicor's FOB: *"This quote has no FOB and its customer has no default FOB in the ERP, so it
  cannot be ordered. Pick an FOB on the quote, then submit again. Nothing was sent to the ERP. This quote has no Ship
  Via and its ERP quote 8720 has none either, so it cannot be ordered. Pick a Ship Via on the quote, then submit
  again. Nothing was sent to the ERP."* A quote missing one names just that one.
- **Not judged in Sugar: a Sales Order** (no line converts an ERP quote). Kinetic takes the customer's Ship Via there,
  and Sugar does not hold it: core never syncs `Customer.ShipViaCode` onto the Account's Ship Via relate. On that route
  the seller still sees the FOB first and the extension's Ship Via refusal after (G860 stays open there until the
  customer's Ship Via reaches the Account).
- **Fails open:** a read that fails, or a class that cannot load, answers "nothing missing" and logs
  `BenchDogs-Ext: ADM order requirements skipped for quote …`; the connector extension checks the Ship Via again
  before the send (G829, G852).
- **Known direction of error:** the quote's Ship Via relate is the last read-back of the ERP quote's ShipViaCode. A
  Ship Via the estimator set in the ERP and Sugar has not synced yet is refused here, and the seller picks one or syncs.
- Tests: `scripts/tests/bd_order_requirements_test.php` (33 checks; run by `test_php_suites.py` against ERP-Epicor's
  real `ErpQuoteFacts`). Mutations: 7/7 killed (ADM gate removed, 2-part key converts, Sales Order judged, quote Ship
  Via ignored, adapter lets a failure through, a code-less row counts, a zero QuoteLine converts).

# (repository change, no release) — every one-off withdrawn (🔒2173b); BenchDogs-Ext unchanged at 0.9.42-rc88

Owner, 2026-09-30: *"remove all the one offs I dont want that code"*. Mirrors erp-integration-sugar #172.
**BenchDogs-Ext is not changed and keeps its version (0.9.42-rc88, deployed);** a zip CI builds from this tree is
rc88 with a different `published_date`, and is not a release.

- **Deleted:** `sugar-sell/ONEOFF-RetireBdResidue` (1.0.7), `sugar-sell/ONEOFF-MoveBdCustomerGroup` (1.0.1) and
  `archive/` (`ONEOFF-DropBdQuoteMirrorTables`, `ONEOFF-RetireBdActionsApi`, `ONEOFF-RetireBdQuoteMirror`). They
  remain in git history. They install no files through `copy` and ship no uninstall script, so uninstalling one
  from a tenant restores nothing.
- **Linter:** the vendored `mlp_lint.py` / `check_built_packages.py` are re-pinned to refactor/rafael-review
  `b1f7dac5`, where MLP023 refuses any `ONEOFF-*` package or `oneoff_*.zip` and the `leftovers/` exemption is gone.
- **What a tenant keeps:** Module Loader never deletes a file a later build stops shipping, so a tenant a one-off
  never ran on keeps what it would have removed (the retired stubs, the orphaned REST stub, the Bench Dogs Quotes
  panel, the governing marker, the customer-group pair in the Account header). Accepted under the ruling.
- **Tests:** the retirement checks keep their package side (not in the source, not in the built zip) and drop the
  one-off worklist half. `test_oneoff_retire_bd_residue.py` and `bench_governing_origin_retired_test.php` are
  deleted; `bench_panel_retired_test.php` and `bd_customer_group_move_test.php` keep only their BenchDogs-Ext
  checks. The rc45-rc64 release-stage policy body moved to `scripts/tests/fixtures/g594/`.
- The install steps below that name a one-off are marked withdrawn; the rest of each entry stands as shipped.

# 0.9.42-rc88 — Rafael's delta review of #41 @98034ea5; ONEOFF-RetireBdResidue 1.0.7

rc88 = rc87 (98034ea5, zip 592a8dd0…, its own tree) + these changes only. No behaviour change in this package. Install order on a Bench tenant: ERP-Epicor >= 1.2.0 → PF → rc88 (or rc87) → ONEOFF-RetireBdResidue 1.0.7.
*Withdrawn (owner 🔒2173b): the one-off's code is deleted. Skip this step.*

- Each of the six discount overlays carries one more comment line, `// Guarded: the compiled ext outlives the class for one step of an uninstall.`, above the autoloading `class_exists` it explains (kept).
- **ONEOFF-RetireBdResidue 1.0.7:**
  - **Hardcoded dependency:** it requires `sugarai_benchdogs_ext` >= **0.9.42-rc87**. That is a literal in its pack.php, changed only with a one-off version bump; the build reads nothing outside its own package (N1). rc87 is the first release that ships none of the listed paths.
  - **Separate sweep tree:** step 1 sweeps only `leftovers/custom/`. The three guarded paths live in `leftovers/if-bench/`, beside that tree, so the unconditional sweep never walks them (N3). They stay inside `leftovers/` because the vendored check_built_packages (G665) admits only `scripts/leftovers.php` and `leftovers/` in a one-off.
  - **Subtrees named by path:** each guarded subtree is `leftovers/if-bench/<path>/<basename>` (N4). Before, they were matched by position, and two orders of the list deleted another writer's policy beside the Bench adapter.
  - **No "0" segments:** the build refuses a listed path with a segment named "0". Sugar's `dir_get_files` (`while ($e = $d->read())`) stops reading a directory at such an entry, which is also why 1.0.6's sweep happened not to list `if-bench/0/`.
  - **README:** rewritten for what it does now (N5). It notes that a tenant with leftovers but no Bench Dogs package (et) can no longer run it (N2).
- CI: the suite step is main's text (#37: `shell: bash`, `set -euo pipefail`), replacing rc87's own wording of the same fix.
- Tests:
  - The harness has 62 checks. New checks: the build reads no sibling version, the dependency bounds, the sweep reaches exactly the plain list, each guarded call reaches one path, all six orders of the guarded list, and the "0" refusal.
  - Red first on 1.0.6: the build fails without `../BenchDogs-Ext`, the sweep tree holds the three guarded paths, and 4 of 6 orders delete the wrong file.
  - Mutations: 9/9 killed.

# 0.9.42-rc87 — Rafael's review of #41 (🔒2167b); ONEOFF-RetireBdResidue 1.0.6

rc87 = rc86 (12702b66, zip 627dc550…, its own tree) + the review fixes. Install order on a Bench tenant: ERP-Epicor >= 1.2.0 → PF → rc87 → ONEOFF-RetireBdResidue 1.0.6.
*Withdrawn (owner 🔒2173b): the one-off's code is deleted. Skip this step.*

- **ERP-Epicor >= 1.2.0 required (the blocker, owner 🔒2167b).** BdAdmRules imports `Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts`; the path include, `QUOTE_FACTS_FILE`, `factsAreNamespaced()`, `factsCompanyCode()`/`factsProductGroup()` and the pre-G530 `method_exists` branch are gone. Below 1.2.0 the manifest refuses the install, and if the class is ever missing at run time the ADM defaults are skipped with a logged error naming the 1.2.0 requirement (never a silent fallback to 1.1's global class).
- **The REST stub is no longer shipped** (`custom/clients/base/api/BdBenchDogsActionsApi.php`); the one-off deletes the tenant's copy.
- **One body for the six discount overlays:** `custom/src/BenchDogs/BdHiddenFields::strip(array $panels, array $names, array $panelNames): array`, with its try/catch inside. Each overlay is three lines, calls it by its full name (no `use`, the fragments are concatenated), and runs only on a panel list and only when the class autoloads.
- **Admin > Quotes Configuration** saves the summary columns into the deployed views, but the overlays strip the discount fields at render: an admin who re-adds Discount sees it saved and never drawn on a Bench tenant.
- **The picker option sources are static methods on BdAdmRules** (`'function' => array('name' => 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::leadSourceOptions')`, no include). Checked against Sugar 26.1: `getOptionsFromVardef` → `getFunctionValue` (no function_bean) resolves the string with `is_callable`/`call_user_func_array`, and ModuleScanner accepts a string name (an array name is refused as "wrong format"). `custom/modules/Quotes/BdAdmLookupOptions.php` is retired; the one-off deletes it.
- **ErpLayoutExtraFields is autoloaded** in post_execute and post_uninstall (a plain `class_exists`); the step report's MISSING and NOT-LOADED outcomes are one: "MISSING: class ErpLayoutExtraFields".
- **pack.php** narrative comments are one line each; `mlp-lint.yml` says 26 rules and explains MLP026; the carried linter is refactor/rafael-review's at cf52eace.
- **ONEOFF-RetireBdResidue 1.0.6** (Rafael's one-off layout): the zip is `scripts/post_execute.php`, `scripts/leftovers.php` and an empty placeholder under `leftovers/` per listed path; everything is DELETED through `uninstall_new_files` (nothing is blanked, `emptied.php` is gone), the three paths other writers share (the stage style, the release-stage policy, the order adapter) only when they hold a Bench body, each from its own placeholder subtree; the retired directories through `uninstall_customizations`; K-2 and K-3 inline through `ViewdefManager`, no cache calls (Module Loader rebuilds after post_execute). It requires the Bench Dogs release built from the same tree.
- Tests: the ADM suite runs its refusal checks below 1.2.0 (O1-O6) and a getFunctionValue probe (M9); the overlay suite checks the new shape (I4-I8); the one-off harness runs the real pack.php zip over a census-seeded tenant (56 checks). Mutations: item 1 4/4, overlays 11/11, option sources 5/5, one-off 15/15, autoload 1/1.

# 0.9.42-rc86 — Rafael's MLP rules on Bench (MLP020–MLP026, 🔒2161b)

rc86 = rc85 (9d9e2acc, zip 99a2aa91…, its own tree) + these changes only. No behaviour change. **Requires** unchanged; install **before** ONEOFF-RetireBdResidue 1.0.5.
*Withdrawn (owner 🔒2173b): the one-off's code is deleted. Skip this step.*

- The vendored MLP linter is re-pinned to erp-integration-sugar `refactor/rafael-review` (280e0929) with `scripts/refresh_mlp_lint.py`, and `test_mlp_lint_pin.py` floors it at MLP001–MLP026. Bench carries no baseline: its first run found 88 findings (87 × MLP026, 1 × MLP024), all fixed here.
- MLP026: every flagged PHP comment block is one line of why, keeping its G-number / 🔒 reference (as erp-integration-sugar #164 did), in BenchDogs-Ext and both one-offs. Comments only.
- MLP024: `BdAdmRules` moved from `custom/modules/Quotes/` to `custom/src/BenchDogs/BdAdmRules.php` as `Sugarcrm\Sugarcrm\custom\BenchDogs\BdAdmRules`. Sugar autoloads it: the two before_save hooks (Quotes defaults, Accounts Cust. Group name) register the class with no file, and `BdAdmLookupOptions.php` imports it instead of `require_once`-ing a path. G840's ADM quote defaults are unchanged (bd_adm_rules_test.php, all three ERP-Epicor shapes).
- An upgraded tenant keeps the old `custom/modules/Quotes/BdAdmRules.php` (Module Loader never deletes a file a later build stops shipping); nothing rc86 ships loads it. ONEOFF-RetireBdResidue **1.0.5** blanks it, only once rc86's class is on disk.
- Tests: the hook checks (R11, N1, test_g243's allowed list) now read the namespaced class at its PSR-4 path; each was shown red against a broken registration (class, method, path) before the move.

# 0.9.42-rc85 — G848 extended: the list preview, and Subtotal / Total (🔒2159b)

rc85 = rc84 (95692e90, zip d3243486…, its own tree) + these three files only. **Requires** unchanged.

- `custom/Extension/modules/Quotes/Ext/clients/base/views/preview/_override_zz_bd_hide_seller_discounts.php`: the Quotes LIST preview draws no `deal_tot` ("Order Discount"); the same body as rc84's five overlays (six now; the shared-body marker reads "every file").
- `custom/Extension/modules/Quotes/Ext/Language/_override_en_us.bd_hide_seller_discounts.php`: `LBL_NEW_SUB` = "Subtotal" (ERP-Core: "Line Items Discounted Subtotal"; also the Bench quote PDF's subtotal caption).
- `custom/Extension/modules/Products/Ext/Language/_override_en_us.bd_hide_seller_discounts.php`: `LBL_ERP_DISCOUNTED_TOTAL` = "Total" (ERP-Core: "Discounted Total", the grid column).
- en_us only (other languages keep ERP-Core's text); `_override` merges after ERP-Core's plain files in either install order.
- Tests: `bd_seller_discounts_hidden_test.php` 136 checks (rc84: 109): section J (the stock-shaped preview), section K (ERP-Core's two language files pinned from 280e0929, merged with the Bench overrides in Sugar's order, the Bench file OLDER than ERP-Core's). 20 red with the three files absent; 7/7 mutations killed.
- Not done (carried): mobile viewdefs, PDF templates' discount rows, Admin > Quotes Configuration's discount_field, no API-side refusal (🔒2151b (d)).

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
2. SugarAI Integration Hub: PATCH the ADM connection with `{"customer_type_extra": {"SUS": "Suspect"}}` (byte-exact).
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
`scripts/tests/bd_customer_group_move_test.php` L2/L5). *Withdrawn (owner 🔒2173b):
`ONEOFF-MoveBdCustomerGroup`'s code is deleted, and L2/L5 and the
one-off's unit cases with it.* **Known limit:** on a view
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
paths are gone, then install rc24. See [the rc24 notes](https://github.com/ophirsw76/benchdogs-sugar-custom/blob/7c3efcee23dee3ebcc01a7307086ab182018556c/sugar-sell/BenchDogs-Ext/docs/release-0.9.42-rc24.md) (removed from the tree, kept in git).

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
See [the rc18 notes](https://github.com/ophirsw76/benchdogs-sugar-custom/blob/7c3efcee23dee3ebcc01a7307086ab182018556c/sugar-sell/BenchDogs-Ext/docs/release-0.9.42-rc18.md) (removed from the tree, kept in git).

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
See [the rc17 notes](https://github.com/ophirsw76/benchdogs-sugar-custom/blob/7c3efcee23dee3ebcc01a7307086ab182018556c/sugar-sell/BenchDogs-Ext/docs/release-0.9.42-rc17.md) (removed from the tree, kept in git).


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

See [the rc16 notes](https://github.com/ophirsw76/benchdogs-sugar-custom/blob/7c3efcee23dee3ebcc01a7307086ab182018556c/sugar-sell/BenchDogs-Ext/docs/release-0.9.42-rc16.md) (removed from the tree, kept in git) and
[the source contract capture](https://github.com/rfernandes-sugarcrm/benchdogs-erp-custom/blob/main/docs/evidence/quote-completion-source-contract.json), kept in the extension repo.

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

See [the rc15 notes](https://github.com/ophirsw76/benchdogs-sugar-custom/blob/7c3efcee23dee3ebcc01a7307086ab182018556c/sugar-sell/BenchDogs-Ext/docs/release-0.9.42-rc15.md) (removed from the tree, kept in git).

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
prove estimator work is finished. See [the rc14 notes](https://github.com/ophirsw76/benchdogs-sugar-custom/blob/7c3efcee23dee3ebcc01a7307086ab182018556c/sugar-sell/BenchDogs-Ext/docs/release-0.9.42-rc14.md) (removed from the tree, kept in git).

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

See [the rc12 notes](https://github.com/ophirsw76/benchdogs-sugar-custom/blob/7c3efcee23dee3ebcc01a7307086ab182018556c/sugar-sell/BenchDogs-Ext/docs/release-0.9.42-rc12.md) (removed from the tree, kept in git) for evidence, gates and rollback.

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
[the rc11 notes](https://github.com/ophirsw76/benchdogs-sugar-custom/blob/7c3efcee23dee3ebcc01a7307086ab182018556c/sugar-sell/BenchDogs-Ext/docs/release-0.9.42-rc11.md) (removed from the tree, kept in git) for evidence, gates and rollback.

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
