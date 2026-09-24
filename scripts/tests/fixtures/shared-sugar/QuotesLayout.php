<?php

require_once('custom/include/scripts/BaseErpLayout.php');

class QuotesLayout extends BaseErpLayout
{
    /** Quotes-only; ERP_PANEL_NAME is inherited from BaseErpLayout. */

    /** REQ-30's Comments tab was retired by G517 - see COMMENTS_PANEL_NAME. */
    /** The OTHER grand-totals view. The helpers default to the footer. */
    private const TOTALS_HEADER_VIEW = 'quote-data-grand-totals-header';

    /**
     * G229 — why this quote's discounts no longer fit what it is worth. Named
     * once, because install() has to place it on an UPGRADED tenant as well as
     * declare it in erpPanel(), and two spellings of one row is how a field
     * comes to be added twice.
     */
    const ERP_DISCOUNT_REFUSAL_FIELD = [
        'name' => 'erp_discount_refusal',
        'label' => 'LBL_ERP_DISCOUNT_REFUSAL',
        'readonly' => true,
    ];

    /**
     * G402 — the ERP panel's read-only datetimes, on the package field type that
     * renders them as a formatted datetime in EVERY mode (ERP-Core
     * custom/clients/base/fields/erp-readonly-datetime). Named once so the panel
     * definition and the test that proves the type reaches an upgraded tenant
     * (scripts/tests/test_g402_erp_datetimes_read_only_in_edit.py) read the same
     * rows. Labels and readonly are unchanged from 1.1.123.
     */
    const ERP_READONLY_DATETIME_FIELDS = [
        'erp_priced_at' => [
            'name' => 'erp_priced_at',
            'label' => 'LBL_ERP_PRICED_AT',
            'readonly' => true,
            'type' => 'erp-readonly-datetime',
        ],
        'erp_writeback_at' => [
            'name' => 'erp_writeback_at',
            'readonly' => true,
            'label' => 'LBL_ERP_WRITEBACK_AT',
            'type' => 'erp-readonly-datetime',
        ],
    ];

    /**
     * G380 (d) / 🔒 1724b — the seller's Reference for the ERP quote header
     * (QuoteHed.Reference; core sends it on Send to Estimation when it is
     * filled). EDITABLE, and ALWAYS SHOWN: it is an input, and a
     * hide-when-empty rule (erpPanelEmptyFieldDependencies) would hide it
     * exactly when it still needs filling - so it is deliberately NOT in that
     * list. erpPanel() declares it for a fresh install; on an UPGRADED tenant
     * its vardef's `erp_layout` marker places it (ErpLayoutExtraFields::sync(),
     * the last step of install()), which - unlike addFieldsToRecordView(),
     * whose add-if-absent looks at ONE panel - leaves it wherever an admin
     * already put it instead of adding a second copy.
     */
    const ERP_REFERENCE_FIELD = [
        'name' => 'erp_reference',
        'label' => 'LBL_ERP_REFERENCE',
    ];

    /**
     * REQ-30's Comments tab - RETIRED by G517 (🔒 1778b). The name stays: it is
     * how install() finds the deployed panel to take off every upgraded tenant,
     * and how the retired dependency shape below still names its first target.
     */
    const COMMENTS_PANEL_NAME = 'LBL_RECORDVIEW_PANEL_ERP_COMMENTS';
    const DISCOUNT_PANEL_NAME = 'LBL_RECORDVIEW_PANEL_ERP_DISCOUNT';

    /**
     * G517 — every field this package has ever put on the Comments tab. When
     * the tab is retired these leave with it; ANY OTHER field found on it (an
     * admin's Studio placement, another package's field - whatever its prefix)
     * is moved to Quote Settings first, never dropped. An explicit list rather
     * than isErpField(): a stray erp_ field someone else placed there is still
     * someone's field.
     *
     *   erp_quote_comment         "What Epicor holds", the raw QuoteComment
     *                             thread (REQ-30; read-only since G396)
     *   erp_comment_log_status    the G396 status line - it MOVES to the ERP
     *                             panel, see COMMENT_LOG_STATUS_FIELD
     *   erp_comment_text,
     *   update_erp_comment_button,
     *   erp_comment_queue         the write surface G396 retired (1.1.123 and
     *                             earlier still carry it - benchdogs-sandbox)
     *   erp_comment_requested_at  never placed there, listed so it can never be
     *                             "carried" as someone else's
     */
    const COMMENTS_TAB_PACKAGE_FIELDS = [
        'erp_quote_comment',
        'erp_comment_log_status',
        'erp_comment_text',
        'update_erp_comment_button',
        'erp_comment_queue',
        'erp_comment_requested_at',
    ];

    /** G517 — where an admin's field on the retired Comments tab goes: Sugar's stock Quote Settings panel. */
    const COMMENTS_TAB_FOREIGN_FIELDS_TARGET = 'panel_setting_body';

    /**
     * G396 — the Comment Log status line, which replaced the Add Comment box,
     * the Update button and the queue receipt (🔒 1702b Q1). It says what the
     * Comment Log dashlet cannot: on an unsent Advanced Quote, how many entries
     * go at Send to Estimation; on a sent one, any entry that has NOT reached
     * Epicor and why.
     *
     * G517 — it now sits on the ERP panel, under the ERP status rows, because
     * the Comments tab it lived on is retired. Named once: install() PLACES it
     * on an upgraded tenant (add-if-absent after erp_writeback_msg) and
     * erpPanel() declares it for a fresh or replace build, and two spellings of
     * one row is how a field comes to be added twice.
     *
     * related_fields is what makes Sidecar FETCH the ledger it counts from
     * (view.js getFieldNames() plucks related_fields from the panels, and from
     * nothing else); the field renders no value of its own.
     */
    const COMMENT_LOG_STATUS_FIELD = [
        'name' => 'erp_comment_log_status',
        'type' => 'erp-comment-log-status',
        'label' => 'LBL_ERP_COMMENT_LOG_STATUS',
        'dismiss_label' => true,
        'readonly' => true,
        'span' => 12,
        'related_fields' => ['erp_commentlog_sync', 'erp_display_sync_key', 'erp_quote_type'],
    ];

    public function install(): void
    {
        $this->setPanelBodyAsNewTab('Quotes');
        // mainPanelFields() is empty since 🔒 1413 removed order_stage from the
        // quote; the call stays so a future main-panel field has a home.
        if ($this->replace) {
            $this->addFieldsToRecordView('Quotes', $this->mainPanelFields());
        }
        // Insert ERP panel as 2nd panel (before panel_shipping_body)
        $this->addPanelToRecordViewBefore('Quotes', $this->erpPanel(), 'panel_shipping_body');
        // Outside replace mode an already-deployed ERP panel is kept as-is, so
        // a field retired from erpPanel() would stay on every upgraded tenant.
        // Remove it from this package's own panel only (L-0009); a placement an
        // admin made on another panel is theirs to keep.
        $this->removeFieldsFromRecordViewPanel('Quotes', ['name' => self::ERP_PANEL_NAME], $this->retiredErpPanelFields());
        // 🛑 AND BRING THE PANEL'S OWN FIELD DEFINITIONS UP TO DATE (G114).
        // addPanelToRecordViewBefore() above RETURNED EARLY on every tenant
        // that already has this panel and is not a replace build, so a changed
        // field definition inside it could never reach an upgrade - 🔒 774 one
        // level down from the button case. This matters because
        // erp_quote_type now carries 'related_fields' => ['order_stage'],
        // which is the ONLY thing fetching the Submit Order gate's input onto
        // the model (see erpPanel()/FETCH_ONLY_RECORD_VIEW_FIELDS). Without
        // this line the key ships in the zip, never reaches the served
        // metadata, and the gate stays silently constant - which is precisely
        // how the 1.1.39 attempt was judged "inert" and deleted.
        $this->reconcileFieldsInRecordViewPanel('Quotes', ['name' => self::ERP_PANEL_NAME], $this->erpPanel()['fields']);
        // G75: the reconcile above only updates fields a tenant ALREADY has, and
        // addPanelToRecordViewBefore() returned early wherever the ERP panel
        // exists - so on an upgraded tenant the revision row is PLACED here,
        // after the ERP ID it refers to. Add-if-absent by name.
        $this->addFieldsToRecordView(
            'Quotes',
            [self::ERP_PARENT_QUOTE_NUM_FIELD],
            ['name' => self::ERP_PANEL_NAME],
            'erp_display_sync_key'
        );
        // Its OWN rule, not a new entry in erpPanelEmptyFieldDependencies():
        // that one is a single serialized shape, and adding a target would land
        // a second copy beside the deployed one on every tenant (G78).
        $this->addDependenciesToRecordView('Quotes', $this->erpParentQuoteNumDependency());
        // G229 — THE DISCOUNT WARNING, PLACED THE SAME WAY AND FOR THE SAME
        // REASON THE REVISION ROW ABOVE IS.
        //
        // 🛑 A NEW FIELD IN erpPanel() DOES NOT REACH AN UPGRADED TENANT.
        // addPanelToRecordViewBefore() returns early wherever the ERP panel
        // already exists, and reconcileFieldsInRecordViewPanel() only updates
        // fields a tenant ALREADY has. All three QA tenants are upgrades, so
        // without this add-if-absent placement the warning would be stamped on
        // the quote and rendered nowhere - which is the 1.1.39 "inert" shape.
        $this->addFieldsToRecordView(
            'Quotes',
            [self::ERP_DISCOUNT_REFUSAL_FIELD],
            ['name' => self::ERP_PANEL_NAME],
            'erp_quote_type'
        );
        // Its OWN rule as well, never a target appended to
        // erpPanelEmptyFieldDependencies() - G78 again: that shape is
        // serialized once, so a new target lands a SECOND copy beside the
        // deployed one on every tenant that already has it.
        $this->addDependenciesToRecordView('Quotes', $this->erpDiscountRefusalDependency());

        // QUOTE SETTINGS STAYS A TAB. panel_setting_body is Sugar's stock
        // "Quote Settings" panel (assigned user, teams; in tabs mode
        // panel_hidden is grouped under it). This call has been here since the
        // package's first commit and has nothing to do with quantity breaks.
        //
        // 🛑 G315 WAS FILED AGAINST THIS LINE FROM A STALE COMMENT. 3f6cba6
        // placed the ladder PANEL just above this call under the note
        // "newTab => false is the point"; c7b583b moved the ladder out and
        // deleted that call, leaving the note orphaned over this unrelated
        // line, where it read as a contradiction. The ladder is no record-view
        // panel now: QuotesQuantityAlternativesLayout REMOVES the old panel
        // (🔒 693) and erp-break-select injects the rungs as a full-width row
        // directly beneath their line (🔒 672/676).
        //
        // DO NOT FLIP THIS TO INLINE "FOR THE LADDER". It moves nothing about
        // the ladder, and it folds Quote Settings and panel_hidden into the tab
        // before it (the Business Card tab, now that G517 retired the Comments
        // tab that used to sit between them). test_g315_ladder_is_not_a_tab.py
        // pins both halves by running this installer.
        $this->setPanelAsNewTab('Quotes', 'panel_setting_body');
        // 🛑 G517 (🔒 1778b) — THE COMMENTS TAB IS RETIRED, ON EVERY TENANT.
        //
        // Owner, 2026-09-24: "we should remove the comment tab we dont need it
        // anymore". Comments live in the Comment Log (G396: each entry goes to
        // Epicor, estimator replies come back as entries, G421 notifies the
        // owner - all server-side hooks, wherever the entry is typed), and the
        // Comment Log dashlet is on the quote's record dashboard (G515, the step
        // below). REQ-30 built this tab with "newTab => true" at the owner's
        // word; this is the owner's word taking it back.
        //
        // NOT by dropping the addPanelToRecordViewBefore() call alone: that
        // retires the panel on a FRESH install and on no upgraded tenant - the
        // add-if-absent trap this file has recorded four times (🔒 774). The
        // deployed panel is taken off the view here, after any field someone
        // else put on it has been moved to Quote Settings - see
        // retireCommentsTab(). What leaves the screen with it:
        //   - the G396 status line, which MOVES to the ERP panel (below), so
        //     "N entries go at Send to Estimation" / "an entry has not reached
        //     Epicor: <reason>" stays in front of the seller;
        //   - erp_quote_comment ("What Epicor holds", the raw thread). Its data
        //     stays, reportable and on the bean; what the seller loses is the
        //     on-screen copy of the thread from before the G396 rollout, which
        //     the Comment Log never received (G396 Q4 copied no history).
        $this->retireCommentsTab();
        // The status line's new home, on an UPGRADED tenant: add-if-absent,
        // right under the ERP status rows. erpPanel() declares it for a fresh
        // or replace build; the reconcile above keeps its definition current.
        $this->addFieldsToRecordView(
            'Quotes',
            [self::COMMENT_LOG_STATUS_FIELD],
            ['name' => self::ERP_PANEL_NAME],
            'erp_writeback_msg'
        );
        // G396 Q4 — THE ROLLOUT COPIES NO HISTORY. Every quote's thread is
        // recorded as already reflected in the Comment Log, in one UPDATE with
        // no bean save, so installing creates no entries and no notifications.
        // Here rather than in post_execute*.php because this installer runs in
        // both (append-only and replace) and those two files are packaging's.
        // include_once, not require_once: the layout suites run this installer
        // with only BaseErpLayout on the path, and a missing file there must
        // not be a fatal. It is LOUD instead, so a package that ships without
        // it cannot pass for one that ran it.
        if (!class_exists('QuoteCommentMirrorBaseline', false)) {
            @include_once 'custom/include/scripts/Modules/QuoteCommentMirrorBaseline.php';
        }
        if (class_exists('QuoteCommentMirrorBaseline', false)) {
            (new QuoteCommentMirrorBaseline())->install();
        } elseif (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
            $GLOBALS['log']->fatal('[QuotesLayout] QuoteCommentMirrorBaseline is missing: the Comment Log mirror '
                . 'baseline was NOT set, so the first ERP comment change on an existing quote is diffed against '
                . 'the value before that save instead (ErpCommentNotificationHook).');
        }
        // G515 (🔒 1776b) — SUGAR'S STOCK COMMENT LOG DASHLET ON THE QUOTE'S
        // RECORD DASHBOARD: the shared default (what a new user sees) and every
        // user's own Quotes record dashboard, APPENDED where absent, offered
        // once per dashboard, never removing or moving a tile. Loaded exactly
        // like QuoteCommentMirrorBaseline above, for the same reasons; see the
        // class docblock for why it edits dashboard rows and not a layout file.
        if (!class_exists('QuotesCommentLogDashlet', false)) {
            @include_once 'custom/include/scripts/Modules/QuotesCommentLogDashlet.php';
        }
        if (class_exists('QuotesCommentLogDashlet', false)) {
            (new QuotesCommentLogDashlet())->install();
        } elseif (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
            $GLOBALS['log']->fatal('[QuotesLayout] QuotesCommentLogDashlet is missing: the Comment Log dashlet was '
                . 'NOT added to the Quotes record dashboards (G515).');
        }
        $this->addFieldsToListView('Quotes', $this->listViewFields());
        $this->moveRecordViewFieldsToHidden('Quotes', $this->recordViewCleanupFields());
        // Every tenant installed before 687.1 carries order_stage in panel_hidden,
        // i.e. ON the "Quote Settings" tab. Hiding it was never enough; remove it.
        // Idempotent: a no-op where it is already absent.
        $this->removeFieldsFromRecordView('Quotes', ['order_stage']);
        // erp_display_sync_key's erp-id-link type reads epicor_deeplink_url off
        // this.model - but Sidecar's record view only populates the model with
        // fields present somewhere in its own metadata field list (confirmed via
        // live debugging: the record fetch's `fields=` param never includes a
        // field absent from every panel). Declaring it in panel_hidden fetches
        // it without rendering a second visible field.
        $this->addFieldsToRecordView(
            'Quotes',
            $this->hiddenRecordViewFields(),
            ['name' => 'panel_hidden'],
            null,
            $this->hiddenPanelDefinition()
        );
        // Must run after addPanelToRecordViewBefore(), which unsets 'buttons' on every deploy.
        // Inserted before 'main_dropdown' so they sit next to the Edit button.
        $this->addButtonsToRecordView('Quotes', $this->erpActionButtons(), 'main_dropdown');
        // G422: "Sync Now with ERP" in the Edit menu. ADD-IF-ABSENT (the helper
        // appends and never updates), which is why the entry carries no rule of
        // its own: when it shows, greys and what it does all live in the field
        // type (fields/sync-with-erp), which reaches every tenant on upgrade.
        // Its place in the menu is set at runtime (record.js _erpPlaceSyncEntry).
        $this->addButtonsToDropdown('Quotes', 'main_dropdown', [$this->syncWithErpMenuEntry()]);
        // erp_display_sync_key only applies to advanced quotes, not sales orders;
        // hide it for sales orders via a real Sidecar dependency (the
        // 'depends_on' key on those fields in erpPanel() does nothing - nothing
        // in the client ever reads it).
        $this->addDependenciesToRecordView('Quotes', $this->erpFieldVisibilityDependencies());
        // The ERP quote mirror's reflected fields only ever carry a value on
        // an advanced quote (the ERP's quote module) - a sales order never has
        // an ERP_Quotes row. A second, separate dependency rather than a
        // change to erpFieldVisibilityDependencies(): add/remove match a
        // dependency by exact serialized shape, so editing the existing one
        // would leave the pre-1.0.88 copy stranded on an upgraded instance.
        // For the same reason, retiring a target means removing the previous
        // shape by value before adding the current one.
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyErpEstimateVisibilityDependencies());
        // G78: and the FOUR-target shape of that same rule, which the five-target
        // removal above cannot match. Measured live on Bench, where it had survived
        // every upgrade and was forcing blank ERP panel rows visible.
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyErpEstimateVisibilityDependenciesFourTarget());
        // G78: delete the isEmpty() forms 1.1.53 deployed BEFORE adding the
        // corrected equal($f,"") ones. Leaving them would put two
        // SetVisibility rules on one target — the very defect this fixes.
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyIsEmptyEstimateVisibilityDependencies());
        $this->addDependenciesToRecordView('Quotes', $this->erpEstimateVisibilityDependencies());
        // ADVANCED QUOTES ONLY (REQ-30, owner: "update button on advanced
        // quote only"). A sales order has no ERP quote behind it, so an ERP
        // comment control on one would post a comment nowhere.
        //
        // G517: the Comments tab this rule gated is retired, so its SHIPPED
        // shape is removed BY VALUE (G78 - never edited, see the method) and
        // the status line, the one comment row left on the record, gets its own
        // rule in a NEW shape. A REAL SIDECAR DEPENDENCY, not only the field's
        // own JS toggle: that hides the field's element, this hides its cell
        // and row, so a sales order shows no empty band in the ERP panel.
        $this->removeDependenciesFromRecordView('Quotes', $this->erpCommentVisibilityDependencies());
        $this->addDependenciesToRecordView('Quotes', $this->erpCommentLogStatusVisibilityDependency());
        // 🛑 THE DISCOUNT PANEL (owner decision 701: "instead of dosciontun
        // as a button can it be a pannel"). It shipped first as a header button
        // opening a modal; the owner's screenshots found three faults in that
        // shape in minutes — the modal could not see the quote's lines (G104),
        // the button forced itself visible in EDIT mode (G105), and it carried
        // no advanced-quote gate so it was the ONLY ERP control left on a sales
        // order (G106). A panel fixes all three by construction.
        // 🛑 RETIRE THE OLD HEADER BUTTON ON UPGRADE, NOT ONLY ON UNINSTALL (🔒 774).
        // 1.1.57 shipped `erp_discount_button` to Bench, stock AND et. Removing
        // it only in uninstall() would leave every upgraded tenant with the
        // retired button sitting beside the new panel — two discount controls,
        // one of them the exact one the owner asked to replace. This is the
        // same shape as 774: an install path that only ever ADDS cannot retire
        // anything, and the tenant keeps the old surface for ever.
        $this->removeButtonsFromRecordView('Quotes', ['erp_discount_button']);
        $this->addPanelToRecordView('Quotes', $this->erpDiscountPanel());
        // 🛑 G212: THE DISCOUNT PANEL IS OFFERED ON SALES ORDERS TOO (owner,
        // quote 287). The rule this package deployed was advanced-quote-only
        // (G106 / decision 702), and dependencies match by EXACT SERIALIZED
        // SHAPE - so adding the new rule alone would land it BESIDE the old one
        // on every upgraded tenant, two SetVisibility rules on one target, the
        // G78 defect. The old shape is therefore removed BY VALUE first, then
        // the new one added. erpDiscountVisibilityDependencies() is kept
        // byte-for-byte as that removal target and must never be edited.
        $this->removeDependenciesFromRecordView('Quotes', $this->erpDiscountVisibilityDependencies());
        $this->addDependenciesToRecordView('Quotes', $this->erpDiscountTypeVisibilityDependencies());
        // 🔒 638: blank/diagnostic ERP-panel rows hidden until the ERP fills them.
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyIsEmptyPanelEmptyFieldDependencies());
        $this->addDependenciesToRecordView('Quotes', $this->erpPanelEmptyFieldDependencies());
        // 🔒 1393 / G79: once an advanced quote has been sent to estimating, the
        // Send to Estimation button is a route to a SECOND Kinetic quote.
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyIsEmptySendToEstimationRatchetDependency());
        $this->addDependenciesToRecordView('Quotes', $this->sendToEstimationRatchetDependency());
        // The quote record view's own 'bundles' -> 'product_bundle_items'
        // nested collection field carries an explicit sub-field allowlist -
        // fields missing from that list never reach the client, no matter
        // what quote-data-group-list's own viewdefs say.
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->priceAvailabilityLineItemFields());
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->erpLineDeeplinkLineItemFields());
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->unitOfMeasureLineItemFields());
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->orderPriceGuardLineItemFields());
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->lineDateLineItemFields());
        // Epicor's OWN stated tax, on the totals footer the seller actually
        // reads. Shipping is deliberately absent here: ErpNativeShippingMirror
        // assigns the ERP freight to the NATIVE `shipping` field, so the
        // footer's existing Shipping row already shows it and the enforced
        // `total` formula already adds it. Tax has no such home - `tax` is
        // enforced-calculated from a TaxRates RATE and Epicor states an
        // AMOUNT - so it is shown rather than written.
        // Anchored on `shipping`, so the two tax rows sit ADJACENT and the
        // column reads as arithmetic the seller can follow:
        //   Subtotal 50.00 / Tax 0.00 / ERP Tax 12.19 / Shipping 10.00 / Total 72.19.
        // Sugar's own Tax row is a structural zero on an ERP quote (taxrate_value
        // is "" on all 131 measured), and erp_total_carries_erp_tax.php makes the
        // total use the ERP figure INSTEAD of it - so the pair is one fact shown
        // beside the field it supersedes, not two competing taxes.
        // 🚩 UPGRADE PATH — WHY THIS REMOVES BEFORE IT ADDS.
        // addFieldsToTotalsFooterBefore is ADD-IF-ABSENT, never update:
        // BaseErpLayout.php skips any field whose NAME is already present
        // (`if (!in_array($field['name'] ?? '', $present, true))`). So on a
        // tenant that already carries these rows, a CHANGE to one of their
        // definitions - type, label, css_class - is silently discarded, and
        // the row keeps whatever shape it was first installed with, forever.
        //
        // MEASURED ON BENCH 2026-09-21, which is how this was found. The
        // tenant's quote-data-grand-totals-footer carried
        //   {"name":"erp_tax_amount","label":"LBL_ERP_TAX_AMOUNT",
        //    "type":"currency","css_class":"quote-footer-currency",...}
        // - type `currency`, NOT the `erp-tax-advanced-only` controller this
        // file declares below. The package installed 19/19, the controller
        // shipped and served 200, and the row still rendered on a sales order
        // because the viewdef never named it. `erp-tax-advanced-only` appeared
        // ZERO times in the whole rendered page.
        //
        // Removing first makes the add authoritative. Position is unchanged:
        // the add re-anchors before `shipping`.
        $this->removeFieldsFromTotalsFooter(
            'Quotes',
            array('erp_tax_amount', 'erp_document_discount_amount')
        );
        $this->addFieldsToTotalsFooterBefore('Quotes', $this->erpTotalsFooterFields(), 'shipping');

        // 🚩 G165 — SUGAR'S OWN TAX ROW COMES OFF, IN BOTH TOTALS VIEWS.
        // Owner, 2026-09-21: "show tax only on advancedquotes since ERP
        // calcaultaes it" and "and dont use sugar tax at all !".
        //
        // THE COMMENT ABOVE THIS ONE WAS RIGHT ABOUT ITS OWN DESIGN AND WRONG
        // ABOUT THE DATA. It shows the two tax rows adjacent on the premise that
        // "Sugar's own Tax row is a structural zero on an ERP quote (taxrate_value
        // is '' on all 131 measured)". Measured on quote 273 (EPIC06__1265) on
        // 2026-09-21: taxrate_value 8.250000, taxable_subtotal 1,927.35,
        // tax 159.006375, against erp_tax_amount 0.00. So the seller reads
        // "Total Tax $159.01" directly above "Grand Total $5,860.00" — a figure
        // the enforced total deliberately excluded. One stale census fed both the
        // layout and erp_total_carries_erp_tax.php.
        //
        // AND THE HEADER WAS NEVER PART OF THAT DESIGN. Read off the served
        // metadata: quote-data-grand-totals-HEADER carries
        // [deal_tot, new_sub, tax, shipping, total] — Sugar's tax and NO ERP row
        // at all — while the footer carries both. The adjacency only ever existed
        // in the footer; the header just showed Sugar's number alone. That is the
        // row the owner saw. Both are removed, and the header needs its view name
        // passed because the helper defaults to the footer.
        $this->removeFieldsFromTotalsFooter('Quotes', array('tax'));
        $this->removeFieldsFromTotalsFooter('Quotes', array('tax'), self::TOTALS_HEADER_VIEW);

        // G274 — THE HEADER STRIP SHOWS THE SAME TAX THE FOOTER DOES. Owner,
        // 2026-09-22 ~15:20Z: "add a tax header next to the grand total at the
        // top between disoscunted and grand total that shoudl be a tax header
        // showing the same field you use for ERP tax". Measured on Bench
        // (advanced quote, 140 x $6.00): header Order Discount $0.00 ·
        // Discounted Subtotal $840.00 · Shipping $0.00 · Grand Total $840.23;
        // footer ... · Tax (via ERP) $0.23 · ... - the $0.23 that makes the
        // header's own Grand Total add up was nowhere in the header.
        //
        // SAME FIELD, SAME LABEL, SAME PLACE, SAME GATE as the footer row:
        // erp_tax_amount, "Tax (via ERP)", before `shipping` (so after
        // Discounted Subtotal once G165 took Sugar's `tax` out above), and
        // shown on advanced quotes only by the header view override
        // (clients/base/views/quote-data-grand-totals-header/), exactly as the
        // footer override does it.
        //
        // REMOVE-THEN-ADD, for the footer's reason above: the add helper is
        // add-if-absent by name, so a later change to this row's definition
        // would never reach a tenant whose stored header already holds it.
        // Both helpers load the tenant's STORED header viewdef (the custom
        // file, or stock where there is none) and write it back - which is
        // how this reaches an upgraded tenant at all.
        //
        // G321 / 🔒 1544a — THE STRIP READS LEFT TO RIGHT AS A SUM. Owner,
        // 2026-09-23: *"first order Discounted Subtotal and hte Order Level
        // Disocunt (show only the oreder level dissocunt amonut and % from the
        // Discounted Subtotal) so the top line make sense if you do the math"*.
        // Stock puts deal_tot FIRST; it is moved to sit after new_sub, and it is
        // relabelled with the footer row's own key, LBL_ERP_DOCUMENT_DISCOUNT_AMOUNT
        // ("Order Level Discount"). What it DISPLAYS is decided by the ERP-Epicor
        // Quotes currency field (the order-level discount and its % of new_sub).
        // The strip becomes
        //   [new_sub, deal_tot, erp_tax_amount, shipping, total]
        //   156.80  - 47.04   + 5.21          + 0.00   = 114.97
        //
        // 🚩 ONE remove, ONE add, both rows together. The add helper appends at
        // the END when its anchor is missing, so deal_tot is anchored on
        // `shipping` (always present) in the same call as erp_tax_amount rather
        // than on erp_tax_amount. Removing deal_tot first is also what MOVES it
        // on an upgraded tenant: add-if-absent would otherwise leave stock's
        // first-position deal_tot exactly where it is, with stock's label.
        $this->removeFieldsFromTotalsFooter(
            'Quotes',
            array('deal_tot', 'erp_tax_amount'),
            self::TOTALS_HEADER_VIEW
        );
        $this->addFieldsToTotalsFooterBefore(
            'Quotes',
            $this->erpTotalsHeaderFields(),
            'shipping',
            self::TOTALS_HEADER_VIEW
        );

        // G380 (f) — LAST: every package's `erp_layout`-marked Quotes field
        // (erp_reference, and a customer package's own) is placed on the view
        // this install just wrote, so reinstalling ERP-Epicor never drops them.
        self::syncExtraFields('Quotes');
    }

    /**
     * G380 (f): ErpLayoutExtraFields::sync($module), loaded the
     * QuoteCommentMirrorBaseline way - class-guarded (MLP001), include_once
     * rather than require_once so a harness that runs this installer with only
     * BaseErpLayout on the path is not a fatal, and LOUD when it is missing so
     * a package shipped without it cannot pass for one that ran it.
     * AccountsLayout carries the same few lines: it runs BEFORE this class is
     * loaded in post_execute, so it cannot borrow this method.
     */
    private static function syncExtraFields(string $module): void
    {
        if (!class_exists('ErpLayoutExtraFields', false)) {
            @include_once 'custom/include/ErpLayoutExtraFields.php';
        }
        if (class_exists('ErpLayoutExtraFields', false)) {
            ErpLayoutExtraFields::sync($module);
        } elseif (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
            $GLOBALS['log']->fatal('[' . $module . 'Layout] custom/include/ErpLayoutExtraFields.php is missing: '
                . 'fields other packages mark with erp_layout were NOT placed on the ' . $module . ' record view.');
        }
    }

    public function uninstall(): void
    {
        $this->removePanelFromRecordView('Quotes');
        $this->removeErpFieldsFromListView('Quotes');
        $this->removeButtonsFromRecordView('Quotes', ['create_erp_order_button', 'advanced_quote_button', 'refresh_price_availability_button', 'erp_discount_button']);
        // G422: the Edit menu entry this package added.
        $this->removeButtonsFromDropdown('Quotes', 'main_dropdown', [$this->syncWithErpMenuEntry()['name']]);
        // G212's rule. The legacy advanced-only shape is still removed below,
        // for a tenant that never ran the install that swapped it.
        $this->removeDependenciesFromRecordView('Quotes', $this->erpDiscountTypeVisibilityDependencies());
        // G75's rule. The row itself leaves with the ERP panel (removePanelFromRecordView).
        $this->removeDependenciesFromRecordView('Quotes', $this->erpParentQuoteNumDependency());
        $this->removeDependenciesFromRecordView('Quotes', $this->erpFieldVisibilityDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->erpEstimateVisibilityDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->erpCommentVisibilityDependencies());
        // G517's rule. The status line itself leaves with the ERP panel above.
        $this->removeDependenciesFromRecordView('Quotes', $this->erpCommentLogStatusVisibilityDependency());
        $this->removeDependenciesFromRecordView('Quotes', $this->erpDiscountVisibilityDependencies());
        $this->removePanelsFromRecordView('Quotes', [self::DISCOUNT_PANEL_NAME]);
        // G189 / G517: a field this package PLACED, this package takes back. On
        // a tenant this version installed on, the Comments tab is already gone
        // (install() retired it) and this is a no-op; it stays for a tenant
        // whose view still carries the tab. Nothing here RESTORES the tab -
        // the owner retired it (🔒 1778b). Scoped to this package's own panel
        // (L-0009).
        $this->removeFieldsFromRecordViewPanel(
            'Quotes',
            ['name' => self::COMMENTS_PANEL_NAME],
            ['erp_comment_queue', self::COMMENT_LOG_STATUS_FIELD['name']]
        );
        $this->removeDependenciesFromRecordView('Quotes', $this->erpPanelEmptyFieldDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->sendToEstimationRatchetDependency());
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyErpEstimateVisibilityDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyErpEstimateVisibilityDependenciesFourTarget());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->priceAvailabilityLineItemFields());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->erpLineDeeplinkLineItemFields());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->unitOfMeasureLineItemFields());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->lineDateLineItemFields());
        $this->removeFieldsFromTotalsFooter('Quotes', array('erp_tax_amount', 'erp_document_discount_amount'));
        // G274: the header's copy of the ERP tax row leaves with the package too.
        $this->removeFieldsFromTotalsFooter('Quotes', array('erp_tax_amount'), self::TOTALS_HEADER_VIEW);

        // 🔒 1544a: deal_tot is a STOCK header cell that install() moved and
        // relabelled, so it goes back to stock's first position with stock's
        // own definition, verbatim - before new_sub, where stock has it.
        $this->removeFieldsFromTotalsFooter('Quotes', array('deal_tot'), self::TOTALS_HEADER_VIEW);
        $this->addFieldsToTotalsFooterBefore(
            'Quotes',
            $this->stockDealTotHeaderField(),
            'new_sub',
            self::TOTALS_HEADER_VIEW
        );

        // G165: `tax` is a STOCK Sugar row, so removing it in install() obliges
        // us to put it back — otherwise uninstalling this package leaves Sugar's
        // own totals footer permanently missing a field it shipped, on a tenant
        // that may go back to using native tax. Restored before `shipping`, which
        // is where stock has it, with stock's own label and css_class.
        $this->addFieldsToTotalsFooterBefore('Quotes', $this->stockTaxFooterField(), 'shipping');
        $this->addFieldsToTotalsFooterBefore(
            'Quotes',
            $this->stockTaxFooterField(),
            'shipping',
            self::TOTALS_HEADER_VIEW
        );
    }

    // -------------------------------------------------------------------------
    // Field definitions
    // -------------------------------------------------------------------------

    /**
     * The ERP-stated tax, as a row on the quote's grand-totals footer.
     *
     * convertToBase false: the seller is reading the document Epicor will
     * invoice, in that document's currency, not a base-currency twin computed
     * at some other moment.
     *
     * No 'default'. The vardef deliberately has none either, because "the ERP
     * did not state a tax" (null) and "the ERP stated zero" (0.00) are
     * different facts and most quotes genuinely carry a stated zero.
     */
    /**
     * G165: stock Sugar's own `tax` row, verbatim from
     * modules/Quotes/clients/base/views/quote-data-grand-totals-header/
     * quote-data-grand-totals-header.php, so uninstall() restores exactly what
     * install() removed rather than an approximation of it.
     */
    private function stockTaxFooterField(): array
    {
        return array(
            array(
                'name' => 'tax',
                'label' => 'LBL_TAX_TOTAL',
                'css_class' => 'quote-totals-row-item',
            ),
        );
    }

    private function erpTotalsFooterFields(): array
    {
        return array(
            // G166: the document-level discount, as its own row. It is NOT the
            // same figure as Sugar's `Total Discount`, which is the roll-up of
            // LINE discounts - both are legitimate and a seller needs to see them
            // separately, which is the owner's "show in the table".
            array(
                'name' => 'erp_document_discount_amount',
                'label' => 'LBL_ERP_DOCUMENT_DISCOUNT_AMOUNT',
                'type' => 'currency',
                'css_class' => 'quote-footer-currency',
                'convertToBase' => false,
            ),
            // G165 — ADVANCED QUOTES ONLY. Owner, 2026-09-20: "if i change a
            // quote to sales roder I shoudl not see tax via ERP". This
            // SUPERSEDES the earlier "say TAX (Via ERP) Not Avilable Yet",
            // which shipped as a LABEL; the ruling is now about VISIBILITY.
            //
            // The gate is a field controller rather than a dependency because a
            // record-view dependency cannot reach the totals viewdefs - the
            // reason the four sibling erp_quote_type controls in this same file
            // work while this row did not.
            array(
                'name' => 'erp_tax_amount',
                'label' => 'LBL_ERP_TAX_AMOUNT',
                // `currency`, NOT a custom type: this footer's template renders
                // a type only if it ships a template for the footer's action,
                // and erp-tax-advanced-only did not -- measured on Bench
                // 2026-09-21, the row drew on NO quote, advanced included.
                // Visibility is decided by the view override instead:
                // clients/base/views/quote-data-grand-totals-footer/.
                'type' => 'currency',
                'css_class' => 'quote-footer-currency',
                'convertToBase' => false,
            ),
        );
    }

    /**
     * 🔒 1544a: stock Sugar's own `deal_tot` header cell, verbatim from
     * modules/Quotes/clients/base/views/quote-data-grand-totals-header/
     * quote-data-grand-totals-header.php, so uninstall() restores exactly what
     * install() moved and relabelled.
     */
    private function stockDealTotHeaderField(): array
    {
        return array(
            array(
                'name' => 'deal_tot',
                'label' => 'LBL_LIST_DEAL_TOT',
                'css_class' => 'quote-totals-row-item',
                'related_fields' => array('deal_tot_discount_percentage'),
            ),
        );
    }

    /**
     * The header strip's ERP cells, in the order they sit before `shipping`.
     *
     * 🔒 1544a — deal_tot, as the ORDER LEVEL DISCOUNT tile. Stock's cell,
     * moved to follow new_sub and labelled with the footer row's key. It stays
     * the deal_tot field because the stock header template prints a percentage
     * only for a field NAMED deal_tot; the ERP-Epicor Quotes currency field
     * makes it show erp_document_discount_amount and its % of new_sub.
     * related_fields kept from stock, so the record still fetches the stored
     * percentage reports use.
     *
     * G274 — the footer's "Tax (via ERP)" row, with the HEADER's cell class:
     * stock's header template draws its dividers from `quote-totals-row-item`
     * (modules/Quotes/clients/base/views/quote-data-grand-totals-header/
     * quote-data-grand-totals-header.hbs:18), and every stock header cell
     * carries it. `currency`, not a custom type, for G165's measured reason:
     * a type with no template for the view's action renders nothing.
     */
    private function erpTotalsHeaderFields(): array
    {
        return array(
            array(
                'name' => 'deal_tot',
                'label' => 'LBL_ERP_DOCUMENT_DISCOUNT_AMOUNT',
                'css_class' => 'quote-totals-row-item',
                'related_fields' => array('deal_tot_discount_percentage'),
            ),
            array(
                'name' => 'erp_tax_amount',
                'label' => 'LBL_ERP_TAX_AMOUNT',
                'type' => 'currency',
                'css_class' => 'quote-totals-row-item',
                'convertToBase' => false,
            ),
        );
    }

    /**
     * 🔒 1413 — NOTHING. `order_stage` IS NO LONGER PLACED ON THE QUOTE.
     *
     * Owner, 2026-09-20, verbatim: *"thenlets remove this form the quote such a
     * wasted of mental energy for the seller clean this !!!!!!"* and *"we dont
     * need the drop down adn we dont need the logic!!!!!"*.
     *
     * It was rendered as an EDITABLE enum, and every value past 'CRM Only' --
     * Sent to ERP, ERP Confirmed, In Fulfillment, Manufacturing, Commitment
     * Final, ERP Error -- is written by the Submit Order action and the
     * connector. A seller could pick one and fabricate an ERP outcome that
     * three shipped rules then trust (OrderStageOpportunityCascade,
     * QuoteAcceptSiblingReject, QuoteOpportunityLinkBlockAcceptedSibling).
     *
     * The method is kept rather than deleted so the call site in install() and
     * this reasoning stay together; a future main-panel field goes here.
     */
    private function mainPanelFields(): array
    {
        return [];
    }

    private function erpPanel(): array
    {
        return [
            'name' => self::ERP_PANEL_NAME,
            'label' => self::ERP_PANEL_NAME,
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => [
                // 🛑 order_stage IS FETCHED HERE AND RENDERED NOWHERE (G114) —
                // see erpPanelFetchOnlyFields() below for the whole reasoning,
                // which is the one thing in this panel that is not about what
                // a seller sees.
                [
                    'name' => 'erp_quote_type',
                    'label' => 'LBL_ERP_QUOTE_TYPE',
                    'required' => true,
                    'related_fields' => self::FETCH_ONLY_RECORD_VIEW_FIELDS,
                ],
                // Set by the connector when this Quote is Accepted (sibling
                // Quotes on the same Opportunity get auto-unset); added to the
                // ERP panel rather than mainPanelFields() since this panel is
                // deployed unconditionally, not only under $this->replace.
                ['name' => 'erp_is_primary_quote', 'label' => 'LBL_ERP_IS_PRIMARY_QUOTE'],
                ['name' => 'erp_companies_quotes_name', 'label' => 'LBL_ERP_COMPANIES_QUOTES_FROM_ERP_COMPANIES_TITLE'],
                ['name' => 'erp_quotes_billing_terms_name', 'label' => 'LBL_ERP_QUOTES_BILLING_TERMS_FROM_ERP_LOOKUPVALUES_TITLE'],
                ['name' => 'erp_quotes_fob_name', 'label' => 'LBL_ERP_QUOTES_FOB_FROM_ERP_LOOKUPVALUES_TITLE'],
                ['name' => 'erp_quotes_ship_via_name', 'label' => 'LBL_ERP_QUOTES_SHIP_VIA_FROM_ERP_LOOKUPVALUES_TITLE'],
                // G380 (d): the seller's Reference, beside the other values the
                // ERP document carries. Placed on upgraded tenants by install().
                self::ERP_REFERENCE_FIELD,
                // 🛑 erp_quoted_value IS REMOVED FROM THIS PANEL (🔒 640). It is a
                // DUPLICATE THAT DISAGREES, and the owner named both halves:
                // "WE ALREADY SHOW TOTAL ON THE QUOTE" and "WHY DO WE EVEN HAVE
                // THAT FIELD????".
                //
                // MEASURED on 138 Bench quotes: 71 carry a value and 43 of those
                // 71 DIFFER from the quote's own total - 1061 total 12,100 vs
                // 3,025; 1250 total 237.30 vs 2,085.30; 1036 total 635 vs 17,050,
                // each with tax and shipping at 0.00, so `total` IS the grand
                // total and there is no reading where those agree.
                //
                // WHY IT DRIFTS: the connector NEVER writes this field - zero
                // files across connector_core/base/epicor reference it, and
                // connector_core/transformers/quotes.py:345 says outright "the
                // connector shouldn't be writing erp_quoted_value either". Its
                // only writer is this package's own PHP stamping $bean->total at
                // submission (runErpAction and orderLines). `total` then keeps
                // recomputing as lines change; the stamp never moves again. It is
                // a STALE SNAPSHOT WEARING A LIVE MONEY LABEL, rendered beside
                // the correct total.
                //
                // NOT merely hidden-when-empty like the fields around it: an
                // empty field says nothing, and a wrong money figure says
                // something false. Hiding it would be the display-only fix over a
                // wrong stored value that the quality bar forbids. The COLUMN is
                // left in place and still stamped - retiring the writer is a
                // separate owner call (🔒 640 states the recommendation) - but
                // nothing shows a seller this number any more.
                // Hyperlinks to epicor_deeplink_url - see custom/clients/base/fields/erp-id-link/.
                ['name' => 'erp_display_sync_key', 'label' => 'LBL_ERP_DISPLAY_SYNC_KEY', 'type' => 'erp-id-link', 'readonly' => true],
                // G75: "Revision of ERP quote <n>", beside the ERP ID it revises.
                // Shown only when set - see erpParentQuoteNumDependency().
                self::ERP_PARENT_QUOTE_NUM_FIELD,
                // Fields the ERP quote mirror used to reflect onto the Quote. The
                // mirror (ERP_Quotes and its ErpQuoteReflectionHook) is retired;
                // see ERP-Epicor/docs/quote-mirror.md. All readonly.
                // erp_governing_line is no longer placed here: see
                // retiredErpPanelFields().
                // G229 — why this quote's discounts no longer fit what it is
                // worth. Shown ONLY when set (erpPanelEmptyFieldDependencies),
                // so it is a warning that appears rather than a row that is
                // usually blank: quote 314 reached -$26.43 in Closed Accepted
                // with nothing on the page objecting, and this is the objection.
                self::ERP_DISCOUNT_REFUSAL_FIELD,
                ['name' => 'erp_estimate_stage', 'label' => 'LBL_ERP_ESTIMATE_STAGE', 'readonly' => true],
                // G402 — a read-only datetime that reads as one in EDIT mode too.
                // As a stock datetimecombo it rendered "[object Object]" on the
                // quote's edit view (owner screenshot, #1023): readonly keeps the
                // detail template while the action can still become 'edit', and
                // stock format() returns an object for 'edit'. The package field
                // pins both to detail - see erp-readonly-datetime.js. The TYPE
                // reaches an upgraded tenant through
                // reconcileFieldsInRecordViewPanel() below (G114), which merges
                // every key of these definitions onto the deployed field.
                self::ERP_READONLY_DATETIME_FIELDS['erp_priced_at'],
                // G172 — render the RESOLVED label, falling back to the code.
                // The label was computed and stored correctly on every quote
                // that has a code (43/43 on Bench) and appeared in NONE of the
                // 35 served Quotes views, so the panel showed "CDATE" where
                // REQ-14 exists to show "Couldn't meet delivery date".
                // related_fields is load-bearing: without it the record view
                // never REQUESTS erp_reason_label, and the field type would
                // fall back to the code on every quote - a fix that renders
                // exactly like the bug.
                [
                    'name' => 'erp_reason_code',
                    'label' => 'LBL_ERP_REASON_CODE',
                    'type' => 'erp-reason',
                    'related_fields' => ['erp_reason_label'],
                    'readonly' => true,
                ],
                ['name' => 'erp_writeback_status', 'readonly' => true, 'label' => 'LBL_ERP_WRITEBACK_STATUS'],
                // G402 — "ERP Status At", the same fix as erp_priced_at above.
                self::ERP_READONLY_DATETIME_FIELDS['erp_writeback_at'],
                ['name' => 'erp_writeback_msg', 'readonly' => true, 'label' => 'LBL_ERP_WRITEBACK_MSG', 'span' => 12],
                // G517: the Comment Log status line, moved here from the retired
                // Comments tab - see COMMENT_LOG_STATUS_FIELD.
                self::COMMENT_LOG_STATUS_FIELD,
            ],
        ];
    }

    /**
     * 🛑 FETCHED ONTO THE MODEL, RENDERED ON NO PANEL (G114).
     *
     * THE PROBLEM. The order-button rule (🔒 1540: the ERP-Core
     * QuotesErpAction plugin's `_erpOrderingIsOpen()`, read by Submit Order
     * and Order Selected Lines; before it, `create-erp-order.js::_isSubmittable()`)
     * reads `this.model.get('order_stage')` and treats ABSENT as not-yet-sent:
     *
     *     PRE_HANDOFF_ORDER_STAGES = ['', 'CRM Only', 'ERP Error']
     *
     * 🔒 1413 took order_stage off the quote entirely ("we dont need the drop
     * down adn we dont need the logic"), so on a model that never fetched it
     * `!stage` is TRUE on every quote and the Submit Order gate is a constant.
     * Confirmed live on Ophir: `model.has('order_stage') === false`. That is
     * the duplicate-order route REQ-22 spent the campaign closing, re-opened
     * by a field removal. The guard must read a value the seller never sees.
     *
     * THE TENSION, AND WHY THE OBVIOUS ANSWER IS WRONG. `panel_hidden` is the
     * mechanism this package uses elsewhere (epicor_deeplink_url,
     * erp_sent_to_estimating_at) and it does NOT satisfy "not rendered": on
     * the Sugar Sell quote panel_hidden is drawn — collapsed behind "Show
     * more" and, once the view is in tabs mode, grouped under the last newTab
     * panel, which is **"Quote Settings"**. That is exactly where the owner
     * found the dropdown after the first attempt and objected twice. Moving a
     * field is not hiding it.
     *
     * THE MECHANISM THAT DOES BOTH, READ OUT OF THE SUGAR SOURCE. A record
     * view seeds its fetch from its own metadata:
     *
     *     sidecar/src/view/view.js:145   this.context.addFields(this.getFieldNames());
     *     sidecar/src/view/view.js:433   getFieldNames: function(module) {
     *                                        if (this.meta && this.meta.panels) { ...
     *                                          _.flatten(_.compact(_.pluck(panel.fields, 'related_fields')))
     *
     * `related_fields` on a PANEL FIELD is added to the requested field list
     * and lands on the model; it is not a row, has no label, and occupies no
     * cell. Stock Sugar ships exactly this: Quotes `panel_body`'s
     * `opportunity_name` carries subtotal / discount / new_sub / tax /
     * shipping, none of which is a rendered field anywhere on that view.
     *
     * 🚩 AND IT IS READ FROM `meta.panels` ALONE. The same key on a BUTTON —
     * which is where 1.1.4x put it, on create_erp_order_button and
     * advanced_quote_button — is never read by anything, because buttons live
     * in `meta.buttons`. It looked like the fix, it passed a test that grepped
     * for the string, and the gate stayed constant. See erpActionButtons().
     *
     * THE CARRIER IS erp_quote_type because 🔒 642 makes it one of the three
     * fields on this panel that are ALWAYS visible, so no emptiness rule can
     * ever take the carrier off the view. (Visibility would not matter anyway —
     * getFieldNames() runs over metadata at initialize, before any
     * SetVisibility action — but a carrier that cannot be hidden needs no such
     * argument.)
     *
     * ⚠️ THE DECLARATION ALONE STILL DOES NOT SHIP. addPanelToRecordViewBefore()
     * returns early when the ERP panel already exists outside replace mode, so
     * an upgraded tenant keeps its old panel and never sees this key — 🔒 774
     * one level below the button case. install() calls
     * BaseErpLayout::reconcileFieldsInRecordViewPanel() for exactly that, and
     * OrderStageOffTheQuoteTest asserts all three parts.
     */
    const FETCH_ONLY_RECORD_VIEW_FIELDS = [
        'order_stage',
        // 🔒 921 / G121 — "Create Opportunity from Quote" is now HIDDEN rather
        // than greyed once the quote has an Opportunity, and the custom
        // convert-to-opportunity field decides that from
        // `this.model.get('opportunity_id')`. Today that value arrives only as
        // a side effect: opportunity_name is a stock panel_body relate field
        // and getFieldNames() adds a relate's id_name (view.js:459). Naming it
        // here makes the guard's input a DECLARED input instead of a borrowed
        // one, so a future layout change to panel_body cannot silently unhide
        // the control. Harmless if it is fetched twice — getFieldNames()
        // uniques the list.
        'opportunity_id',
        // G422 — "Sync Now with ERP" is offered when erp_sync_key is set, and the
        // field (fields/sync-with-erp) reads it off the model. No panel shows
        // the key, so without this line it is never fetched, reads empty, and
        // every SENT Advanced Quote shows the entry greyed with "Use Send to
        // Estimation first". Carried to upgraded tenants by the
        // reconcileFieldsInRecordViewPanel() call in install(), like the two
        // above. sync-with-erp-menu.test.js pins it.
        'erp_sync_key',
    ];

    private function listViewFields(): array
    {
        $fields = [
            ['name' => 'erp_quote_type', 'label' => 'LBL_ERP_QUOTE_TYPE', 'enabled' => true, 'default' => true],
            ['name' => 'erp_display_sync_key', 'label' => 'LBL_ERP_DISPLAY_SYNC_KEY', 'type' => 'erp-id-link', 'enabled' => true, 'default' => true],
            ['name' => 'erp_companies_quotes_name', 'label' => 'LBL_ERP_COMPANIES_QUOTES_FROM_ERP_COMPANIES_TITLE', 'enabled' => true, 'id' => 'ERP_COMPANIES_QUOTESERP_COMPANIES_IDA', 'link' => true, 'sortable' => false, 'default' => true],
            ['name' => 'quote_stage', 'label' => 'LBL_QUOTE_STAGE', 'enabled' => true, 'default' => true],
            // 🔒 1413: available to admins and reports, but NOT a default column --
            // the owner removed this from the seller's surface, and a list column is
            // that surface too. 'enabled' keeps it selectable; 'default' => false
            // stops it arriving unasked.
            ['name' => 'order_stage', 'label' => 'LBL_ORDER_STAGE', 'enabled' => true, 'default' => false],
            ['name' => 'erp_is_primary_quote', 'label' => 'LBL_ERP_IS_PRIMARY_QUOTE', 'enabled' => true, 'default' => false],
            ['name' => 'erp_estimate_stage', 'label' => 'LBL_ERP_ESTIMATE_STAGE', 'enabled' => true, 'readonly' => true, 'default' => false],
            ['name' => 'erp_priced_at', 'label' => 'LBL_ERP_PRICED_AT', 'enabled' => true, 'readonly' => true, 'default' => false],
            ['name' => 'erp_writeback_status', 'label' => 'LBL_ERP_WRITEBACK_STATUS', 'enabled' => true, 'readonly' => true, 'default' => false],
            // Appended last, not near erp_display_sync_key - not a default
            // column, just kept enabled so the value is fetched (erp-id-link
            // needs it), and pushed to the end so it doesn't clutter the
            // "manage columns" picker near the top.
            ['name' => 'epicor_deeplink_url', 'label' => 'LBL_EPICOR_DEEPLINK_URL', 'enabled' => true, 'default' => false],
        ];

        // Replace mode wipes every non-ERP field from the list view, so the
        // stock Quotes list columns have to be re-supplied here.
        if ($this->replace) {
            $fields = array_merge($this->baseListViewFields(), $fields);
        }

        return $fields;
    }

    private function hiddenRecordViewFields(): array
    {
        return [
            ['name' => 'epicor_deeplink_url', 'label' => 'LBL_EPICOR_DEEPLINK_URL'],
            // 🔒 1393 / G79: the Send-to-Estimation ratchet reads this stamp, so
            // it must REACH THE CLIENT MODEL. Sidecar's record view populates
            // the model only with fields present somewhere in its own metadata
            // field list (the note on epicor_deeplink_url above records how that
            // was confirmed), and erp_sent_to_estimating_at was in NO panel.
            //
            // 🛑 GETTING THIS WRONG INVERTS THE FEATURE RATHER THAN DISABLING IT.
            // An absent field reads as empty, isEmpty() is therefore TRUE on
            // every quote, and a rule written as "hide once sent" would instead
            // SHOW the button forever -- or, written the other way, hide it on
            // quotes never sent at all. panel_hidden fetches the value without
            // rendering a row a seller has no use for.
            ['name' => 'erp_sent_to_estimating_at', 'label' => 'LBL_ERP_SENT_TO_ESTIMATING_AT'],
        ];
    }

    private function baseListViewFields(): array
    {
        return [
            ['name' => 'quote_num', 'enabled' => true, 'default' => true],
            ['name' => 'name', 'link' => true, 'enabled' => true, 'default' => true],
            ['name' => 'billing_account_name', 'enabled' => true, 'default' => true],
            ['name' => 'total', 'enabled' => true, 'default' => true],
            ['name' => 'total_usdollar', 'enabled' => true, 'default' => true],
            ['name' => 'date_quote_expected_closed', 'enabled' => true, 'default' => true],
            ['name' => 'assigned_user_name', 'enabled' => true, 'default' => true],
            ['name' => 'date_modified', 'enabled' => true, 'default' => true],
            ['name' => 'date_entered', 'enabled' => true, 'default' => true],
        ];
    }

    private function recordViewCleanupFields(): array
    {
        // 🛑 order_stage IS NO LONGER PLACED ON THE QUOTE AT ALL (owner 687.1).
        //
        // THE PREVIOUS REASONING WAS SOUND AND ITS PREMISE WAS FALSE. It ran:
        // moveRecordViewFieldsToHidden() shifts panel_body -> panel_hidden, so
        // the value is still FETCHED onto the model while the seller sees no
        // control. The second half is simply untrue — on the Sugar Sell quote,
        // **panel_hidden RENDERS AS THE "Quote Settings" TAB**. Nothing was
        // hidden. The owner found the dropdown there and said so twice:
        // "I thoght we agreed to remove" / "is shows on quote setting the order
        // status", against 🔒 1413's "we dont need the drop down adn we dont
        // need the logic". Every "VISIBLE = 0" reading that blessed the old
        // approach was taken with that tab inactive.
        //
        // The guard the old comment protected is real and still is:
        //
        //   QuotesErpAction.js _erpOrderingIsOpen() (🔒 1540; was
        //   create-erp-order.js _isSubmittable()):
        //     var orderStage = model.get('order_stage') || '';  // '' = not yet sent
        //
        // An unfetched field reads empty, empty reads "not yet sent", and the
        // Submit control returns on an already-ordered quote — the
        // duplicate-order route REQ-22 spent the campaign closing. So the field
        // is not merely dropped: the ERP panel's erp_quote_type declares
        // 'related_fields' => ['order_stage'] (see FETCH_ONLY_RECORD_VIEW_FIELDS),
        // which is the mechanism that actually fetches a field the view does
        // not render — `getFieldNames()` reads related_fields from
        // `this.meta.panels` and from nowhere else.
        //
        // ⚠️ THE SAME KEY ON THE TWO BUTTONS WAS A NO-OP AND SHIPPED AS THE FIX
        // (G114). Buttons live in `meta.buttons`, which getFieldNames() never
        // looks at, so 🔒 774's reconcile correctly delivered a key with no
        // reader and `model.has('order_stage')` stayed false. The reconcile is
        // still required — it is now applied to the PANEL FIELD instead, via
        // reconcileFieldsInRecordViewPanel() in install().
        //
        // The column, the vardef and every server-side writer are untouched
        // (L-0009: retiring from the UI must never destroy data).
        $fields = ['renewal', 'renewal_opp_name', 'tag', 'category_name'];

        // payment_terms is superseded by the ERP billing-terms lookup — only
        // hide it in the replace build, where the layout is meant to fully
        // supersede the stock panels rather than sit alongside them.
        if ($this->replace) {
            $fields[] = 'payment_terms';
        }

        return $fields;
    }

    // Top-level header buttons (next to Edit/main_dropdown), backed by custom
    // Rowaction field types — see custom/modules/Quotes/clients/base/fields/
    // {create-erp-order,advanced-quote,refresh-price-availability}/.
    //
    // BUTTON WEIGHT IS A DECISION, NOT A DEFAULT. All three of these used to
    // carry the same 'btn-ocean', so the one control that files a real,
    // irreversible Epicor sales order looked exactly like the read-only price
    // lookup beside it. Sugar reserves 'btn-primary' for the primary action —
    // note the theme's own selector is '.btn-ocean:not(.btn-primary)' — and
    // that is what Submit Order is on this screen. The other two are
    // supporting actions and take 'btn-secondary', which is themed
    // (#fff on a light border) rather than 'btn-ocean', which is not the
    // hierarchy signal it was being used as.
    private function erpActionButtons(): array
    {
        return [
            [
                // 'btn' is required for real dimensions, not just styling.
                'type' => 'refresh-price-availability',
                'event' => 'button:refresh_price_availability_button:click',
                'name' => 'refresh_price_availability_button',
                'label' => 'LBL_REFRESH_PRICE_AVAILABILITY_BUTTON',
                'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-secondary ml-2',
                'showOn' => 'view',
                'acl_action' => 'edit',
            ],
            [
                'type' => 'create-erp-order',
                'event' => 'button:create_erp_order_button:click',
                'name' => 'create_erp_order_button',
                'label' => 'LBL_CREATE_ERP_ORDER_BUTTON',
                'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-primary ml-2',
                'showOn' => 'view',
                'acl_action' => 'edit',
                // 🛑 THE GATE'S INPUT IS **NOT** DECLARED HERE, AND IT USED TO BE
                // (G114). `'related_fields' => ['order_stage']` sat on this
                // button and on advanced_quote_button for three versions. It
                // is A NO-OP. Sidecar reads related_fields from ONE place:
                //
                //   sidecar/src/view/view.js:433  getFieldNames()
                //     if (this.meta && this.meta.panels) { ... _.pluck(panel.fields, 'related_fields') }
                //
                // `this.meta.panels` — never `this.meta.buttons`. A button's
                // related_fields is read by nothing, so the key added nothing
                // to `context.addFields(this.getFieldNames())` and
                // `model.has('order_stage')` stayed FALSE on every quote
                // (measured live on Ophir). The reconcile fix of 🔒 774 was
                // real and necessary and delivered a key with no reader.
                //
                // The carrier is a PANEL FIELD instead — see erpPanel(), where
                // erp_writeback_at declares it, and install(), which reconciles
                // that definition onto tenants whose ERP panel already exists.
                // Stock Sugar uses exactly this shape: Quotes panel_body's
                // `opportunity_name` carries related_fields subtotal/discount/
                // new_sub/tax/shipping, none of which is a rendered row.
            ],
            [
                'type' => 'advanced-quote',
                'event' => 'button:advanced_quote_button:click',
                'name' => 'advanced_quote_button',
                'label' => 'LBL_ADVANCED_QUOTE_BUTTON',
                'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-secondary ml-2',
                'showOn' => 'view',
                'acl_action' => 'edit',
                // Same lifecycle, same input, same carrier as the button above:
                // the fetch is declared on erpPanel()'s erp_writeback_at, not
                // here, because a button's related_fields is read by nothing.
            ],
        ];
    }

    /**
     * G422 — the Edit menu's "Sync Now with ERP" (owner, 2026-09-23). A rowaction
     * of the package type `sync-with-erp`, which owns the rule: offered when
     * erp_sync_key is set or on a Sales Order quote with a linked ERP order,
     * greyed with "Use Send to Estimation first" on an unsent Advanced Quote.
     * 'edit' because a sync writes the quote (the connector's read-back).
     */
    private function syncWithErpMenuEntry(): array
    {
        return [
            'type' => 'sync-with-erp',
            'event' => 'button:sync_with_erp_button:click',
            'name' => 'sync_with_erp_button',
            'label' => 'LBL_ERP_SYNC_WITH_ERP_BUTTON',
            'acl_action' => 'edit',
        ];
    }

    // erp_display_sync_key shows only for advanced quotes.
    private function erpFieldVisibilityDependencies(): array
    {
        return [
            [
                'hooks' => ['all'],
                'trigger' => 'true',
                'triggerFields' => ['erp_quote_type'],
                'onload' => true,
                'actions' => [
                    [
                        'action' => 'SetVisibility',
                        'params' => [
                            'target' => 'erp_display_sync_key',
                            'value' => 'equal($erp_quote_type, "advanced_quote")',
                        ],
                    ],
                ],
            ],
        ];
    }

    // The ERP quote mirror's reflected fields follow erp_display_sync_key:
    // shown for advanced quotes only. Kept as its own rule (see install()).
    private function erpEstimateVisibilityDependencies(): array
    {
        // 🔒 638: advanced-quote AND non-empty, in ONE expression. These four
        // were populated on 0 of 138 Bench quotes because each fills only at a
        // specific lifecycle moment (an estimator prices it; a quote closes
        // won/lost). Two SEPARATE SetVisibility rules on the same target fight
        // each other, so the emptiness test is ANDed into the existing gate
        // rather than added beside it.
        return $this->visibleWhenNotEmptyDependency(
            [
                'erp_estimate_stage',
                'erp_estimate_total',
                'erp_priced_at',
                'erp_reason_code',
            ],
            'equal($erp_quote_type, "advanced_quote")'
        );
    }

    // REQ-30's Comments tab is meaningful only on an advanced quote: the
    // comment it shows and appends to lives on QuoteHed, and a sales order has
    // no QuoteHed row. The Update button is gated with the fields rather than
    // alone, so a seller is never shown an ERP comment box on a record that can
    // never have one.
    //
    // 🛑 THE PANEL ITSELF IS GATED TOO, AND THAT LINE IS THE POINT (🔒 670).
    // Gating only the FIELDS left the TAB behind: on a sales order the seller
    // still saw a "Comments" tab, clicked it, and got the line-item grid with
    // no comment box anywhere -- a tab that promises something it does not
    // have. Reported by the owner on ERP Quote 1068 (Quote Type: Sales Order).
    //
    // WHY NOT SHOW THE BOX ON SALES ORDERS INSTEAD. That was considered and
    // ruled out by the owner after one measurement: the comment does NOT reach
    // the order. stampCommentRequestIfPending() sets erp_comment_requested_at,
    // and the only sweeper of that stamp is QuoteCommentWriteBack, which
    // PATCHes Erp.BO.QuoteSvc/Quotes keyed on QuoteNum and writes QuoteComment.
    // So on Submit Order / Order Selected Lines the text lands on the ERP
    // QUOTE, never on OrderHed.OrderComment. A comment box on a sales order
    // would therefore be a drafting surface with no destination. Owner:
    // "show it only on advnaced quotes".
    //
    // The order-side writer that would change this is scoped in 🔒 668:
    // OrderHed.OrderComment exists (measured live, order 11622) and already
    // carries the Sugar link marker, so it needs its own append-only handler
    // on SalesOrderSvc keyed by OrderNum. Until that ships, this gate is
    // correct rather than conservative.
    /**
     * G75 — the revision row, as placed in the ERP panel. One definition, read
     * by erpPanel() (fresh installs) and by install()'s placement call
     * (upgraded tenants), so the two can never disagree.
     */
    const ERP_PARENT_QUOTE_NUM_FIELD = [
        'name' => 'erp_parent_quote_num',
        'label' => 'LBL_ERP_PARENT_QUOTE_NUM',
        'readonly' => true,
    ];

    /**
     * G75 — "Revision of ERP quote <n>" when this quote IS a revision, and no
     * row at all when it is not.
     *
     * 🛑 NULL MUST READ AS "NOT A REVISION", NEVER AS 0. Measured in Sidecar's
     * own SugarLogic (sidecar/lib/sugarlogic/sidecarExpressionContext.js
     * getValue): a null value falls to the final branch and becomes `""`, so
     * `not(equal($f, ""))` is false and the row hides; a NUMBER is cast to a
     * string first, so a 0 becomes "0" and the row SHOWS. That is why the
     * vardef carries no default - a 0 written for "none" would put "Revision
     * of ERP quote 0" on every quote.
     *
     * Its own dependency rather than a target added to
     * erpPanelEmptyFieldDependencies(), whose single serialized shape is
     * already deployed; changing it would leave the old copy beside the new.
     */
    private function erpParentQuoteNumDependency(): array
    {
        return $this->visibleWhenNotEmptyDependency(['erp_parent_quote_num']);
    }

    /**
     * G229 — the Discount Warning row is shown ONLY when it has something to
     * say.
     *
     * A quote whose discounts still fit carries an empty string here, and an
     * always-present "Discount Warning" row that is usually blank teaches
     * sellers to stop reading it - 🔒 642's whole point about which rows are
     * worth having.
     */
    private function erpDiscountRefusalDependency(): array
    {
        return $this->visibleWhenNotEmptyDependency(['erp_discount_refusal']);
    }

    /**
     * 🛑 G212 — THE DISCOUNT PANEL'S GATE, NOW BOTH QUOTE TYPES.
     *
     * Owner, on sales-order quote 287: the control to apply a discount is
     * offered only on Advanced Quotes, and it is wanted on sales orders too.
     * The server already agrees - `POST erp-discount` applied a 5% quote
     * discount to 287 - so this is visibility alone.
     *
     * 📌 THIS REVERSES G106 / DECISION 702 FOR THIS ONE CONTROL, ON THE
     * OWNER'S WORD. 702's rule was "a new control inherits its siblings'
     * gates, or it is a leak", and the siblings are advanced-only. The owner
     * has now ruled that the discount control is the exception; its siblings
     * are untouched.
     *
     * NAMED TYPES, NOT "ANY TYPE". erp_quote_type_list has exactly two keys
     * today, so `not(equal($erp_quote_type, ""))` would be equivalent - until
     * a third type is added, when it would silently offer the panel there
     * too. Naming both keeps it fail-CLOSED for a type nobody has ruled on,
     * and for the unfetched value while the record loads (G205's lesson).
     *
     * `or(equal(), equal())`, never isEmpty(): the isEmpty form parsed to
     * nothing on a tenant (sendToEstimationRatchetDependency's note).
     *
     * The stage half - an accepted quote is settled - is NOT here. It never
     * was: erp-discount.js's _isEligibleQuote() owns it, and the server
     * refuses the edit outright.
     */
    private function erpDiscountTypeVisibilityDependencies(): array
    {
        $actions = [];
        foreach ([self::DISCOUNT_PANEL_NAME, 'erp_discount_panel'] as $target) {
            $actions[] = [
                'action' => 'SetVisibility',
                'params' => [
                    'target' => $target,
                    'value' => 'or(equal($erp_quote_type, "advanced_quote"), '
                        . 'equal($erp_quote_type, "sales_order"))',
                ],
            ];
        }

        return [
            [
                'hooks' => ['all'],
                'trigger' => 'true',
                'triggerFields' => ['erp_quote_type'],
                'onload' => true,
                'actions' => $actions,
            ],
        ];
    }

    /**
     * The seller's discount panel — one field that draws the whole control.
     *
     * It is NOT a newTab panel: 🚩 a `newTab` panel's TAB HEADER escapes
     * SetVisibility entirely (measured on the Comments tab, 2026-09-20 — the
     * fields hid, the pane emptied, the tab stayed). An inline panel is
     * governed by the dependency below and needs no client-side tab hack.
     *
     * 🛑 G510 — `columns => 2`, LIKE EVERY OTHER PANEL ON THIS VIEW. Without
     * it the owner saw "Apply a discount" pushed toward the middle of the row
     * (x 267-437 of 2000) while Currency / Display Line Numbers sat at the left
     * edge. Measured live on benchdogs-sandbox (Sugar 26.1): with labels on
     * the side (the user's `field_name_placement`), the grid builder gives a
     * label `labelSpan = floor(4 / columns)`, reading a missing `columns` as
     * 1 - so this label got span4, a 19vw column, and Sugar floats a side
     * label RIGHT inside its column. erpPanel() and the stock panels say 2 and
     * get span2 (9vw), which is the rail the owner reads as "the left edge".
     * The field keeps `span => 12`, so it still fills its row alone; with
     * labels on top nothing moves (the label span there follows the field).
     *
     * Not `labelsOnTop`: record.js overwrites a panel's labelsOnTop with the
     * user's preference on every render, so that key is inert here (the
     * Comments panel declares it and still renders its labels on the side).
     * It reaches upgraded tenants because both post_execute variants build
     * this view with replace = true, which rebuilds this panel from this
     * definition; an admin's own fields in it are carried (G452). Studio's
     * parser writes `columns` (the view's maxColumns, 2 by default) on every
     * panel it saves, so this is also the shape a Studio-saved tenant already
     * had until a reinstall took it away.
     * DiscountLabelRidesTheLabelColumnTest pins it.
     */
    private function erpDiscountPanel(): array
    {
        return [
            'name' => self::DISCOUNT_PANEL_NAME,
            'label' => self::DISCOUNT_PANEL_NAME,
            'columns' => 2,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => [
                [
                    'name' => 'erp_discount_panel',
                    'type' => 'erp-discount',
                    'label' => 'LBL_ERP_DISCOUNT_TITLE',
                    'span' => 12,
                    'readonly' => true,
                ],
            ],
        ];
    }

    /**
     * 🛑 THE GATE ITS SIBLINGS ALREADY HAD (G106 / decision 702). Every
     * other ERP control on this view gates on `advanced_quote`; this one did
     * not, so on stock quote 1236 (`sales_order`) the four siblings correctly
     * vanished and the discount control was the only one left on screen.
     */
    private function erpDiscountVisibilityDependencies(): array
    {
        return $this->advancedQuoteOnlyDependency([
            self::DISCOUNT_PANEL_NAME,
            'erp_discount_panel',
        ]);
    }

    /**
     * REQ-30's advanced-quote gate for the Comments tab, IN ITS SHIPPED SHAPE.
     *
     * 🛑 G517: A REMOVAL TARGET ONLY - NEVER EDIT IT, NEVER ADD IT AGAIN.
     * install() removes it by value on every tenant (the tab it gated is
     * retired) and uninstall() still removes it for a tenant this version never
     * installed on. Add and remove match a dependency by EXACT SERIALIZED
     * SHAPE (G78), so a single changed target here would strand the deployed
     * copy on every tenant carrying it. The status line that outlived the tab
     * has its own rule: erpCommentLogStatusVisibilityDependency().
     */
    private function erpCommentVisibilityDependencies(): array
    {
        return $this->advancedQuoteOnlyDependency([
            // The PANEL first: hides the whole tab, not just its contents.
            self::COMMENTS_PANEL_NAME,
            'erp_quote_comment',
            'erp_comment_text',
            'update_erp_comment_button',
            'erp_comment_requested_at',
        ]);
    }

    // The shape installed before erp_governing_line was retired. Dependencies
    // match by exact serialized shape, so an upgraded tenant keeps this one
    // until it is removed by value. Never edit it.
    private function legacyErpEstimateVisibilityDependencies(): array
    {
        return $this->advancedQuoteOnlyDependency([
            'erp_estimate_stage',
            'erp_estimate_total',
            'erp_priced_at',
            'erp_reason_code',
            'erp_governing_line',
        ]);
    }

    /**
     * THE OLDER FOUR-TARGET SHAPE OF THE SAME LEGACY RULE (G78).
     *
     * 🛑 MEASURED ON THE RUNNING BENCH TENANT, NOT INFERRED. The Quotes record
     * view carried BOTH of these against erp_estimate_stage / erp_priced_at /
     * erp_reason_code:
     *
     *   i:1  triggerFields [erp_quote_type]  equal($erp_quote_type, "advanced_quote")
     *   i:3  triggerFields [ ..5.. ]         and(equal(..), not(isEmpty($..)))
     *
     * and the owner saw the result: "we discussed the ERP fields why do I see
     * them all thi time ??? we said not to show what is nto populated".
     *
     * 🚩 WHY THE EXISTING REMOVAL COULD NOT REACH IT. removeDependencies-
     * FromRecordView matches BY EXACT SERIALIZED SHAPE - the note on
     * legacyErpEstimateVisibilityDependencies() says so itself. That method
     * builds FIVE targets (it gained erp_governing_line later); the rule
     * actually stranded on the tenant has FOUR. Different shape, no match, and
     * the removal has been a silent no-op on every upgrade since. The rule it
     * failed to delete carries NO emptiness test, so it fought decision 638's
     * gate and won - the field rendered blank on every advanced quote.
     *
     * This is precisely the hazard that comment predicted - "two SEPARATE
     * SetVisibility rules on the same target fight each other" - arrived at by
     * a path it did not cover: not an edit to the RULE, but an edit to the
     * REMOVER's target list, which silently orphans every tenant already
     * carrying the previous shape.
     *
     * 🛑 NEVER EDIT THIS LIST, for the same reason its five-target sibling says
     * so. A shape that has shipped can only ever be deleted by value; change it
     * and the tenants carrying it are stranded again. A new shape gets a NEW
     * method, and the old one stays here forever.
     */
    private function legacyErpEstimateVisibilityDependenciesFourTarget(): array
    {
        return $this->advancedQuoteOnlyDependency([
            'erp_estimate_stage',
            'erp_estimate_total',
            'erp_priced_at',
            'erp_reason_code',
        ]);
    }

    // Fields erpPanel() once placed that nothing writes any more.
    //
    // erp_governing_line was the retired quote mirror's reflection of its
    // governing ERP_QuoteLines row. Decision 29 (2026-09-13) makes governing
    // quantity an explicit Sugar selection read fail-closed by the customer's
    // contribution provider, so there is no ERP-side governing line to show.
    // The vardef is kept for existing data.
    /**
     * ONE field, which renders every ladder the quote carries.
     *
     * A single field rather than a column set because the content is not a
     * fixed list of values: a quote holds one ladder per part number, each with
     * its own number of rungs, and a Sugar panel cannot express that shape.
     */
    /**
     * G517 — the status line's own advanced-quote rule, in a NEW shape (the
     * Comments tab's rule it used to ride is retired - see
     * erpCommentVisibilityDependencies()). Never edit it once shipped: G78.
     */
    private function erpCommentLogStatusVisibilityDependency(): array
    {
        return $this->advancedQuoteOnlyDependency([
            self::COMMENT_LOG_STATUS_FIELD['name'],
        ]);
    }

    /**
     * G517 (🔒 1778b) — TAKE THE COMMENTS TAB OFF THE DEPLOYED RECORD VIEW,
     * LOSING NOBODY'S FIELD.
     *
     * The panel's fields are split in two:
     *   - this package's own (COMMENTS_TAB_PACKAGE_FIELDS) leave with the tab -
     *     the status line has already been declared on the ERP panel;
     *   - EVERY OTHER named field (an admin's Studio placement, another
     *     package's) is moved to the END of Quote Settings first, as it was
     *     deployed, and the move is logged. Studio's unnamed padding cells are
     *     not fields and are not moved.
     *
     * The panel is removed ONLY once every such field is verified to be on the
     * view outside it. If the move could not be made (a view with no Quote
     * Settings panel), the tab STAYS and the install log says which fields held
     * it there: a Comments tab still showing is a visible, fixable outcome; a
     * field silently gone is not.
     *
     * Idempotent: with no Comments panel deployed it reads, finds nothing and
     * writes nothing. Uninstall does not put the tab back.
     *
     * The deployed view is read through ViewdefManager directly because
     * BaseErpLayout's loadView() is private; every WRITE goes through the
     * BaseErpLayout helpers, which deploy and clear the caches.
     */
    private function retireCommentsTab(): void
    {
        $panel = $this->deployedRecordPanel(self::COMMENTS_PANEL_NAME);
        if ($panel === null) {
            return;
        }

        $foreign = [];
        foreach ((array) ($panel['fields'] ?? []) as $field) {
            $name = is_array($field) ? (string) ($field['name'] ?? '') : (is_string($field) ? $field : '');
            if ($name === '' || $name[0] === '(' || in_array($name, self::COMMENTS_TAB_PACKAGE_FIELDS, true)) {
                continue;
            }
            $foreign[] = $field;
        }

        if ($foreign !== []) {
            $this->addFieldsToRecordView('Quotes', $foreign, ['name' => self::COMMENTS_TAB_FOREIGN_FIELDS_TARGET]);
            $stranded = [];
            foreach ($this->collectFieldNames($foreign) as $name) {
                if (!$this->isOnRecordViewOutside($name, self::COMMENTS_PANEL_NAME)) {
                    $stranded[] = $name;
                }
            }
            if ($stranded !== []) {
                $this->logInstall(sprintf(
                    'ERP layout: the Quotes Comments tab was NOT removed - %s could not be moved to %s (G517)',
                    implode(', ', $stranded),
                    self::COMMENTS_TAB_FOREIGN_FIELDS_TARGET
                ));
                return;
            }
            $this->logInstall(sprintf(
                'ERP layout: moved %d field(s) from the retired Quotes Comments tab to %s: %s (G517)',
                count($foreign),
                self::COMMENTS_TAB_FOREIGN_FIELDS_TARGET,
                implode(', ', $this->collectFieldNames($foreign))
            ));
        }

        $this->removePanelsFromRecordView('Quotes', [self::COMMENTS_PANEL_NAME]);
    }

    /** The deployed Quotes record view's panel of that name, or null. */
    private function deployedRecordPanel(string $panelName): ?array
    {
        foreach ($this->deployedRecordPanels() as $panel) {
            if (is_array($panel) && ($panel['name'] ?? '') === $panelName) {
                return $panel;
            }
        }

        return null;
    }

    /** Is the field placed on the deployed Quotes record view, in any panel but that one? */
    private function isOnRecordViewOutside(string $fieldName, string $panelName): bool
    {
        foreach ($this->deployedRecordPanels() as $panel) {
            if (!is_array($panel) || ($panel['name'] ?? '') === $panelName) {
                continue;
            }
            if (in_array($fieldName, $this->collectFieldNames($panel['fields'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The guard is NOT an off switch on a tenant: class_exists() autoloads
     * (ViewdefManager is namespaced under src/), and BaseErpLayout's own
     * constructor already THROWS when it is missing, so no QuotesLayout that
     * reaches this line on Sugar can take the early return. It exists for the
     * installer harnesses that fake BaseErpLayout without a viewdef store
     * (scripts/tests/test_quote_governing_line_retired.py), where there is no
     * deployed view to read.
     */
    private function deployedRecordPanels(): array
    {
        if (!class_exists(\Sugarcrm\Sugarcrm\MetaData\ViewdefManager::class)) {
            return [];
        }
        $defs = (new \Sugarcrm\Sugarcrm\MetaData\ViewdefManager())->loadViewdef('base', 'Quotes', 'record');

        return is_array($defs) && isset($defs['panels']) && is_array($defs['panels']) ? $defs['panels'] : [];
    }

    /** fatal: the level sugarcrm.log keeps by default, so the install record says what happened. */
    private function logInstall(string $message): void
    {
        if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
            $GLOBALS['log']->fatal($message);
        }
    }

    private function retiredErpPanelFields(): array
    {
        // RETIRED THIS WAY, not by deleting the erpPanel() entry alone: outside
        // replace mode an already-deployed ERP panel is kept as-is, so a field
        // merely dropped from erpPanel() "would stay on every upgraded tenant"
        // (L-0009). The vardef and the COLUMN deliberately remain -- retiring a
        // field from the UI must never destroy data someone may still read.
        //
        // erp_governing_line -- superseded; the governing-rung selection belongs
        // on the LINE (Products), not one Quote-level pointer (decision 536).
        //
        // erp_estimate_total -- owner-ruled. Its documented source is
        // ERP_Quotes.quote_total and ERP_Quotes IS THE RETIRED MIRROR MODULE, so
        // it has no source; decision 539's controlled census found no writer
        // either (controls in the same sweep fired at 10 and 14). It is NOT
        // being replaced: core already carries quote money through
        // erp_stated_charge / quote_charges, and a SECOND total is the
        // duplicate-ownership shape cb5ec60 retired the Bench shipped-quantity
        // duplicate for. An always-empty MONEY field is worse than no field --
        // a seller reads blank as zero.
        return ['erp_governing_line', 'erp_estimate_total'];
    }

    /**
     * HIDE A FIELD THAT HAS NOTHING TO SAY (🔒 638).
     *
     * The owner's verdict on the ERP panel was "its very messy do we need to
     * see all this fields why??", and the measurement agreed: on 138 Bench
     * quotes, ERP Company and FOB were populated on ZERO, and the three
     * write-back stamps on 4 (2.9%). Eight of fourteen rows were permanently
     * blank.
     *
     * 🛑 HIDDEN, NOT DELETED, AND THE DIFFERENCE MATTERS. ERP-Core ships to
     * every Epicor customer. FOB is a real commercial term - who pays the
     * freight, and at what point the goods become the buyer's - carried by the
     * same relate plumbing as Billing Terms, which IS populated on 131 of 138.
     * It is blank here because THIS customer's Epicor data never sets it, on
     * any quote or any account. Deleting it would take a live term away from a
     * customer who uses it; hard-coding it leaves Bench with blank rows.
     * Hiding on empty makes the panel self-adapt to whatever the tenant's ERP
     * actually sends. Every field stays declared, audited and written - it is
     * only unrendered when it holds nothing.
     *
     * @param string[] $targets fields to show only when non-empty
     * @param string|null $and an extra expression ANDed with the emptiness
     *        test - used where a field is ALSO advanced-quote-only, because two
     *        separate SetVisibility rules on one target fight each other.
     */
    /**
     * 🛑 THE isEmpty() SHAPES 1.1.53 DEPLOYED. FROZEN — NEVER EDIT.
     *
     * `isEmpty()` IS NOT A SUGARLOGIC FUNCTION. Sugar ships no *Empty*
     * expression class at all, and its registered operation list (every
     * getOperationName() under include/Expressions/Expression/**) does not
     * contain it; core writes a blank test as equal($f,""), e.g.
     * equal($product_template_name,""). An unknown function does not warn —
     * the expression fails to parse and the dependency silently does nothing.
     * That is why decision 638's hide-when-empty never once worked (G78).
     *
     * 🚩 CORRECTING THE EXPRESSION IS NOT ENOUGH. removeDependenciesFrom-
     * RecordView matches BY EXACT SERIALIZED SHAPE, so a tenant already
     * carrying the isEmpty form keeps it forever unless it is deleted BY
     * VALUE — and then two SetVisibility rules fight over one target, which
     * IS the G78 defect, reintroduced by the fix for it. These three methods
     * reproduce exactly what 1.1.53 wrote so install() can remove them first.
     *
     * A shape that has shipped can only ever be deleted by value. Editing any
     * of these strands every tenant carrying it; a new shape gets a NEW method.
     */
    private function legacyIsEmptyNotEmptyDependency(array $targets, ?string $and = null): array
    {
        $actions = [];
        foreach ($targets as $target) {
            $notEmpty = 'not(isEmpty($' . $target . '))';
            $actions[] = [
                'action' => 'SetVisibility',
                'params' => [
                    'target' => $target,
                    'value' => $and === null ? $notEmpty : 'and(' . $and . ', ' . $notEmpty . ')',
                ],
            ];
        }

        return [
            [
                'hooks' => ['all'],
                'trigger' => 'true',
                'triggerFields' => array_values(array_unique(
                    array_merge($targets, ['erp_quote_type'])
                )),
                'onload' => true,
                'actions' => $actions,
            ],
        ];
    }

    /** The isEmpty form of erpPanelEmptyFieldDependencies(). FROZEN. */
    private function legacyIsEmptyPanelEmptyFieldDependencies(): array
    {
        return $this->legacyIsEmptyNotEmptyDependency([
            'erp_companies_quotes_name',
            'erp_quotes_billing_terms_name',
            'erp_quotes_ship_via_name',
            'erp_display_sync_key',
            'erp_writeback_status',
            'erp_writeback_at',
            'erp_writeback_msg',
        ]);
    }

    /** The isEmpty form of erpEstimateVisibilityDependencies(). FROZEN. */
    private function legacyIsEmptyEstimateVisibilityDependencies(): array
    {
        return $this->legacyIsEmptyNotEmptyDependency(
            [
                'erp_estimate_stage',
                'erp_estimate_total',
                'erp_priced_at',
                'erp_reason_code',
            ],
            'equal($erp_quote_type, "advanced_quote")'
        );
    }

    /** The isEmpty form of sendToEstimationRatchetDependency(). FROZEN. */
    private function legacyIsEmptySendToEstimationRatchetDependency(): array
    {
        return [
            [
                'hooks' => ['all'],
                'trigger' => 'true',
                'triggerFields' => [
                    'erp_quote_type',
                    'erp_display_sync_key',
                    'erp_sent_to_estimating_at',
                    'erp_priced_at',
                ],
                'onload' => true,
                'actions' => [
                    [
                        'action' => 'SetVisibility',
                        'params' => [
                            'target' => 'advanced_quote_button',
                            'value' => 'and(equal($erp_quote_type, "advanced_quote"), '
                                . 'and(isEmpty($erp_display_sync_key), '
                                . 'and(isEmpty($erp_sent_to_estimating_at), '
                                . 'isEmpty($erp_priced_at))))',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function visibleWhenNotEmptyDependency(array $targets, ?string $and = null): array
    {
        $actions = [];
        foreach ($targets as $target) {
            // 🛑 equal($f, "") — NOT isEmpty(). THERE IS NO isEmpty() IN SUGARLOGIC.
            // Sugar's registered operation list (include/Expressions/Expression/**,
            // getOperationName) is: abs addDays and charAt concat contains
            // currentUserField date dayofmonth dayofweek daysUntil doBothExist
            // equal false floor formatName getListWhere greaterThan hourOfDay
            // hoursUntil indexOf isAfter isAlpha isAlphaNumeric isAssigned
            // isBefore isForecastClosed* isNumeric isOwner isRequiredCollection
            // isValid* isWithinRange link ln log max median min monthofyear
            // negate not now number or pow prorateValue sentimentScoreToStr
            // stddev strlen strReplace strToLower strToUpper subStr time
            // timestamp today valueAt year. No *Empty* expression class exists
            // at all, and core Sugar writes a blank test as equal($f,"") —
            // e.g. equal($product_template_name,"").
            //
            // An unknown function does not warn: the expression fails to parse,
            // the dependency silently does nothing, and the field just stays
            // visible. That is why decision 638's hide-when-empty NEVER worked
            // from the day it was written (G78), and why nothing in the panel
            // ever hid.
            $notEmpty = 'not(equal($' . $target . ', ""))';
            $actions[] = [
                'action' => 'SetVisibility',
                'params' => [
                    'target' => $target,
                    'value' => $and === null ? $notEmpty : 'and(' . $and . ', ' . $notEmpty . ')',
                ],
            ];
        }

        return [
            [
                'hooks' => ['all'],
                'trigger' => 'true',
                // Every gated field is its own trigger: the panel must react
                // when the connector fills one mid-session, not only on load.
                'triggerFields' => array_values(array_unique(
                    array_merge($targets, ['erp_quote_type'])
                )),
                'onload' => true,
                'actions' => $actions,
            ],
        ];
    }

    /**
     * The always-blank and diagnostic fields, hidden until the ERP fills them.
     * Quote Type (138/138), ERP ID (133/138), Quoted Value (71/138) and
     * Billing Terms (131/138) are NOT here - they are populated often enough
     * that a blank one is itself information.
     */
    private function erpPanelEmptyFieldDependencies(): array
    {
        // 🔒 642 — THE OWNER SET THIS LIST, VERBATIM:
        //   "I walays want to see  Quote Type Primary Quote FOB the rest only
        //    if there are not empty"
        //
        // So exactly THREE fields are unconditional and everything else in this
        // panel is conditional on having a value:
        //
        //   ALWAYS      erp_quote_type · erp_is_primary_quote · erp_quotes_fob_name
        //   WHEN SET    everything below
        //
        // 📌 FOB MOVES THE OTHER WAY FROM WHERE THE MEASUREMENT POINTED, AND THAT
        // IS THE OWNER'S CALL TO MAKE. 🔒 638 measured FOB at 0 of 138 and put it
        // in this list. The owner has now ruled it ALWAYS VISIBLE - a blank FOB is
        // itself the answer to "who pays the freight on this quote", the same way
        // an unticked Primary Quote box is informative. Measurement decides what
        // is EMPTY; the owner decides what is WORTH A ROW.
        return $this->visibleWhenNotEmptyDependency([
            'erp_companies_quotes_name',            // ERP Company      - 0/138
            'erp_quotes_billing_terms_name',        // Billing Terms    - 131/138, so shows on nearly all
            'erp_quotes_ship_via_name',             // Ship Via
            'erp_display_sync_key',                 // ERP ID           - 133/138
            'erp_writeback_status',                 // 4/138 - plumbing; wanted only when a send failed
            'erp_writeback_at',                     // 4/138
            'erp_writeback_msg',                    // 4/138
        ]);
    }

    /**
     * THE SEND-TO-ESTIMATION RATCHET (🔒 1393, G79).
     *
     * Owner: "after an advance qutoe is sent to esitmation dont show this
     * button". NOT tidiness -- 🔒 946 established that this action is what
     * CREATES the Kinetic quote (1260, 1261 and 1262 were each created by a
     * press), so a button still offered on an already-sent quote is a live
     * route to a SECOND KINETIC QUOTE: the same class of hazard REQ-22 spent
     * the campaign closing on the order side.
     *
     * 📌 "SECOND KINETIC QUOTE", NOT THE OTHER PHRASE, AND DELIBERATELY SO.
     * `test_create_quote_durable_modes::RetiredClaimsStayRetiredInProse` holds
     * a proximity rule over the words "duplicate Epicor quote": that phrase
     * belongs to a RETIRED claim about a retried CLICK, which core 835f8dd3
     * made false, and it may only stand beside the commit that ended it. This
     * hazard is a different one -- a second PRESS of a different action, still
     * live, and closed by the ratchet below. Borrowing the retired claim's
     * words would put this paragraph at the top of every grep for it and
     * re-arm a dead sentence with a true one.
     *
     * 🚩 KEYED ON THE STAMP, NEVER ON quote_stage, AND THAT IS NOT A STYLE
     * CHOICE. 🔒 1183 measured that Send to Estimation moves an advanced quote
     * to `order_submitted`, which is NOT A VALID quote_stage_dom KEY, and
     * 🔒 43581 ruled it must write "In Estimating". A predicate reading the
     * stage would inherit a bug this estate has recorded twice.
     * erp_sent_to_estimating_at is stamped by ErpEstimatingStamps on the
     * transition INTO "In Estimating" and never cleared, so it is the honest
     * "this has been sent" signal and it ratchets.
     *
     * 🚩 THE STAMP ALONE IS NOT ENOUGH, AND THE CENSUS IS WHY. Measured on
     * Bench 2026-09-20 over all 157 quotes / 146 advanced:
     *
     *     erp_sent_to_estimating_at set .......   7
     *     erp_priced_at set ..................    4
     *     either stamp set ...................    8
     *     erp_display_sync_key set ........... 140   <- an Epicor quote EXISTS
     *     no Epicor quote at all .............   6   <- the only ones to offer it on
     *
     * Quote 225 is the counter-example that killed the stamp-only rule: stage
     * "Priced", erp_priced_at set, ERP quote 1257 behind it, and
     * erp_sent_to_estimating_at EMPTY. A stamp-only predicate would still have
     * offered the button on 132 quotes that already have an Epicor quote --
     * i.e. it would have closed almost none of the hazard it exists for.
     *
     * So the primary term is erp_display_sync_key: the action CREATES the
     * Kinetic quote (🔒 946), so the honest question is "does one already
     * exist?", not "did this Sugar record observe the transition?".
     *
     * 📌 THE STAMPS STAY IN, AS RACE COVER, NOT REDUNDANCY. The key is written
     * by the RETURN sync; the stamp is written by before_save the moment the
     * stage changes. Between the press and the next sync the key is still
     * empty, and that window is exactly when an impatient second press happens.
     *
     * ANDed with the advanced-quote test rather than added beside it: two
     * SetVisibility rules on one target fight each other, which is exactly the
     * defect G78 was.
     */
    private function sendToEstimationRatchetDependency(): array
    {
        return [
            [
                'hooks' => ['all'],
                'trigger' => 'true',
                'triggerFields' => [
                    'erp_quote_type',
                    'erp_display_sync_key',
                    'erp_sent_to_estimating_at',
                    'erp_priced_at',
                ],
                'onload' => true,
                'actions' => [
                    [
                        'action' => 'SetVisibility',
                        'params' => [
                            'target' => 'advanced_quote_button',
                            // Show ONLY while this quote has no Epicor quote and
                            // neither estimating stamp. Census below explains why
                            // all three terms are needed.
                            // equal($f,"") not isEmpty() — see the note in
                            // visibleWhenNotEmptyDependency(). The isEmpty form
                            // shipped in 1.1.53 parsed to nothing, so this gate
                            // was inert and the button was hidden ONLY by
                            // advanced-quote.js::_hasErpQuoteAlready().
                            'value' => 'and(equal($erp_quote_type, "advanced_quote"), '
                                . 'and(equal($erp_display_sync_key, ""), '
                                . 'and(equal($erp_sent_to_estimating_at, ""), '
                                . 'equal($erp_priced_at, ""))))',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function advancedQuoteOnlyDependency(array $targets): array
    {
        $actions = [];
        foreach ($targets as $target) {
            $actions[] = [
                'action' => 'SetVisibility',
                'params' => [
                    'target' => $target,
                    'value' => 'equal($erp_quote_type, "advanced_quote")',
                ],
            ];
        }

        return [
            [
                'hooks' => ['all'],
                'trigger' => 'true',
                'triggerFields' => ['erp_quote_type'],
                'onload' => true,
                'actions' => $actions,
            ],
        ];
    }

    // Populated by the Refresh Price & Availability button; must be added to
    // the record view's product_bundle_items sub-field allowlist (see
    // addFieldsToNestedCollection()) or the client never receives them at all,
    // regardless of quote-data-group-list's own viewdefs.
    private function priceAvailabilityLineItemFields(): array
    {
        return [
            'erp_available_qty',
            'erp_price_availability_synced_at',
            // 🔒 1436 / G176 — the catalog stock figure the grid shows, now a
            // STORED field (🔒 1438 / G180: a read-only relate could not be
            // written, which is why the refresh could never update it).
            // Without this entry it never reaches the client and the column
            // renders empty on every line — the exact failure this list exists
            // to prevent.
            'erp_stock_availability',
            // 🛑 G187 — THE LINK TARGET, AND WITHOUT IT THE LINK IS NOT DRAWN.
            // Owner: "when i clock the -50 inteh quote it it should take me to
            // the prodicut not the quote line item". The erp-onhand-qty field
            // reads the LINE'S OWN product id off its own row model to build
            // #ProductTemplates/<id>; a missing value renders the figure
            // un-linked (G187 clause 3), which is a correct degradation and
            // therefore an invisible one. So the allowlist entry is load-bearing
            // in the quiet way this list has already been load-bearing twice.
            //
            // 🚩 AND IT MUST NOT BE ASSUMED PRESENT. product_template_name is
            // on the grid and is a relate whose id_name is this field, which
            // makes it look as though the id must already be served. The
            // nested-collection allowlist is a POSITIVE whitelist and does not
            // follow id_name — 🔒 673 cost this package a release on exactly
            // that assumption.
            'product_template_id',
        ];
    }

    // Same allowlist requirement as priceAvailabilityLineItemFields() above -
    // custom/modules/Products/clients/base/views/quote-data-group-list/
    // quote-data-group-list.js renders these via view.leftColumns, but the
    // values still need to be on this list to actually reach the client.
    private function erpLineDeeplinkLineItemFields(): array
    {
        return ['epicor_line_deeplink_url', 'epicor_eto_deeplink_url'];
    }

    // The quote line's own unit of measure (user decision 211). Same allowlist
    // requirement as the two above: ERP-Core's ProductsLayout adds the COLUMN,
    // but without this the value never reaches the client, so the column would
    // render empty on every line and a seller could not change a unit.
    //
    // The field itself and its default-from-catalog hook live in ERP-Core
    // (both profiles get them); only this allowlist entry is here, because the
    // Quotes nested-collection allowlist is this package's to own - the same
    // split erp_available_qty already uses.
    private function unitOfMeasureLineItemFields(): array
    {
        return ['erp_unit_of_measure'];
    }

    // G463 (🔒 1758b) — the line's own Need By / Ship By. ERP-Core's
    // ProductsLayout adds the COLUMNS; without this allowlist entry the values
    // never reach the client, so the columns would render empty and a date the
    // seller typed would look unsaved (🔒 673 cost a release on exactly that).
    // A new name on an add-if-absent list, so an upgraded tenant gets it.
    private function lineDateLineItemFields(): array
    {
        return ['erp_need_by_date', 'erp_ship_by_date'];
    }

    // G415 — the order buttons refuse a seller line with no Unit Price
    // (QuotesErpAction::_erpRefuseUnpricedOrder, ERP-Core) with G379 (c)'s
    // exemptions, one of which is the stamped estimation placeholder. The
    // client reads the rest of what it needs from lists already here or in
    // stock (discount_price, mft_part_num, name; erp_sync_key and
    // erp_ladder_group via QuotesQuantityAlternativesLayout), and fails OPEN
    // on a line whose flag was never fetched - so without this entry the
    // client half would stand down on every line and only the server would
    // refuse. A new name on an add-if-absent list, so an upgraded tenant gets it.
    private function orderPriceGuardLineItemFields(): array
    {
        return ['erp_estimation_placeholder'];
    }
}
