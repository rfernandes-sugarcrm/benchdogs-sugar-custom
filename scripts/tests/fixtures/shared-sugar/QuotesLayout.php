<?php

require_once('custom/include/scripts/BaseErpLayout.php');

class QuotesLayout extends BaseErpLayout
{
    /** Quotes-only; ERP_PANEL_NAME is inherited from BaseErpLayout. */

    /** REQ-30: the Comments tab. */
    /** The OTHER grand-totals view. The helpers default to the footer. */
    private const TOTALS_HEADER_VIEW = 'quote-data-grand-totals-header';

    const COMMENTS_PANEL_NAME = 'LBL_RECORDVIEW_PANEL_ERP_COMMENTS';
    const DISCOUNT_PANEL_NAME = 'LBL_RECORDVIEW_PANEL_ERP_DISCOUNT';

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
        // THE QUANTITY-BREAK LADDER, AS A SUB-SECTION AND NOT A TAB.
        //
        // Rendered as peer line items in the main grid the rungs read as
        // several things being bought, and the grid's Grand Total adds them up:
        // EPIC06 quote 1203 shows $24,850.00 for one prototype plus THREE
        // mutually exclusive prices for the SAME part. They are alternatives.
        //
        // newTab => false is the point. A tab is somewhere you go and find;
        // this belongs beside the lines it qualifies.
        $this->setPanelAsNewTab('Quotes', 'panel_setting_body');
        // THE COMMENTS TAB (REQ-30), AND newTab => true IS THE OWNER'S WORD.
        //
        // The quantity-break ladder above is deliberately NOT a tab, because it
        // qualifies the lines it sits beside. A comment is the opposite: it is
        // a conversation with the ERP about the whole quote, it can be long,
        // and it is somewhere a seller GOES rather than something they read in
        // passing. The owner asked for "a tab on the quote called Comments"
        // and then confirmed it: "add comemnts as a tab".
        $this->addPanelToRecordViewBefore('Quotes', $this->commentsPanel(), 'panel_setting_body');
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
        // quote only"). A sales order has no ERP quote behind it, so an Update
        // control on one would post a comment nowhere.
        //
        // A REAL SIDECAR DEPENDENCY, not the button's own JS class toggle. The
        // field component hides itself too, but that runs on initialize/render
        // and on change:erp_quote_type - a defence in depth, not the rule. This
        // is the same mechanism erp_display_sync_key uses, and the reason it
        // exists is recorded above: the 'depends_on' vardef key does nothing
        // because nothing in the client ever reads it.
        $this->addDependenciesToRecordView('Quotes', $this->erpCommentVisibilityDependencies());
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
        $this->addDependenciesToRecordView('Quotes', $this->erpDiscountVisibilityDependencies());
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
    }

    public function uninstall(): void
    {
        $this->removePanelFromRecordView('Quotes');
        $this->removeErpFieldsFromListView('Quotes');
        $this->removeButtonsFromRecordView('Quotes', ['create_erp_order_button', 'advanced_quote_button', 'refresh_price_availability_button', 'erp_discount_button']);
        $this->removeDependenciesFromRecordView('Quotes', $this->erpFieldVisibilityDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->erpEstimateVisibilityDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->erpCommentVisibilityDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->erpDiscountVisibilityDependencies());
        $this->removePanelsFromRecordView('Quotes', [self::DISCOUNT_PANEL_NAME]);
        $this->removeDependenciesFromRecordView('Quotes', $this->erpPanelEmptyFieldDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->sendToEstimationRatchetDependency());
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyErpEstimateVisibilityDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyErpEstimateVisibilityDependenciesFourTarget());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->priceAvailabilityLineItemFields());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->erpLineDeeplinkLineItemFields());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->unitOfMeasureLineItemFields());
        $this->removeFieldsFromTotalsFooter('Quotes', array('erp_tax_amount', 'erp_document_discount_amount'));

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
                // Fields the ERP quote mirror used to reflect onto the Quote. The
                // mirror (ERP_Quotes and its ErpQuoteReflectionHook) is retired;
                // see ERP-Epicor/docs/quote-mirror.md. All readonly.
                // erp_governing_line is no longer placed here: see
                // retiredErpPanelFields().
                ['name' => 'erp_estimate_stage', 'label' => 'LBL_ERP_ESTIMATE_STAGE', 'readonly' => true],
                ['name' => 'erp_priced_at', 'label' => 'LBL_ERP_PRICED_AT', 'readonly' => true],
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
                ['name' => 'erp_writeback_at', 'readonly' => true, 'label' => 'LBL_ERP_WRITEBACK_AT'],
                ['name' => 'erp_writeback_msg', 'readonly' => true, 'label' => 'LBL_ERP_WRITEBACK_MSG', 'span' => 12],
            ],
        ];
    }

    /**
     * 🛑 FETCHED ONTO THE MODEL, RENDERED ON NO PANEL (G114).
     *
     * THE PROBLEM. `create-erp-order.js::_isSubmittable()` reads
     * `this.model.get('order_stage')` and treats ABSENT as not-yet-sent:
     *
     *     return !stage || stage === 'CRM Only' || stage === 'ERP Error';
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
        //   create-erp-order.js:472  const stage = this.model.get('order_stage');
        //   _isSubmittable(): return !stage || stage === 'CRM Only' || ...
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
     * The seller's discount panel — one field that draws the whole control.
     *
     * It is NOT a newTab panel: 🚩 a `newTab` panel's TAB HEADER escapes
     * SetVisibility entirely (measured on the Comments tab, 2026-09-20 — the
     * fields hid, the pane emptied, the tab stayed). An inline panel is
     * governed by the dependency below and needs no client-side tab hack.
     */
    private function erpDiscountPanel(): array
    {
        return [
            'name' => self::DISCOUNT_PANEL_NAME,
            'label' => self::DISCOUNT_PANEL_NAME,
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
     * REQ-30's Comments tab.
     *
     * THREE FIELDS, NOT ONE, and the split is the design rather than clutter.
     * A single editable field that is also the ERP's value cannot work: the
     * next sync would overwrite whatever the seller typed, and they would have
     * no way to tell "what Epicor says" from "what I am about to send".
     *
     *   erp_quote_comment        READ-ONLY. What Epicor holds right now.
     *   erp_comment_text         Editable. What the seller wants APPENDED.
     *   erp_comment_requested_at READ-ONLY. When Update last swept it.
     *
     * labelsOnTop + span 12: these are paragraphs, not values. A comment
     * rendered in a half-width right-hand column wraps into a ribbon and is
     * the reason nobody reads it.
     *
     * THE WRITER APPENDS, NEVER OVERWRITES - QuoteHed.QuoteComment also carries
     * the Sugar<->Kinetic link marker, so an overwrite orphans the quote from
     * its Sugar record. That is why the editable field is called "Add Comment"
     * and not "Edit Comment": the label has to describe what the button does.
     */


    private function commentsPanel(): array
    {
        return [
            'name' => self::COMMENTS_PANEL_NAME,
            'label' => self::COMMENTS_PANEL_NAME,
            'columns' => 1,
            'labelsOnTop' => true,
            'placeholders' => true,
            'newTab' => true,
            'collapsed' => false,
            'fields' => [
                [
                    'name' => 'erp_quote_comment',
                    'label' => 'LBL_ERP_QUOTE_COMMENT',
                    'readonly' => true,
                    'span' => 12,
                    // A WIDE TEXT AREA, NOT A ONE-LINE INPUT, AND BIG - the
                    // owner asked for roughly a third of the screen. At the
                    // ~20px line-height Sugar renders, 14 rows is ~280px, which
                    // is about a third of a 900px record view and still leaves
                    // the Add box and the button on screen under it.
                    //
                    // NO maxlength, DELIBERATELY. I could not establish
                    // Epicor's documented cap for QuoteHed.QuoteComment: it is
                    // absent from every schema, BAQ and metadata file in this
                    // repo, and the SDK models it as an UNCONSTRAINED str
                    // (connector_base...QuoteERP.quote_comment: annotation=str,
                    // default=''), with no max_length. Inventing a Sugar-side
                    // limit would truncate a comment the ERP would have
                    // accepted, and a client-side cap on a field whose real
                    // bound is unknown is a guess that silently destroys a
                    // seller's text. The field is also already carrying a full
                    // URL (the Advanced Quoting link marker) plus appended
                    // entries, so it is demonstrably not short.
                    // An empty ERP comment must read as EMPTY, not as broken.
                    // With no placeholder the tab opened on a bare label over
                    // white space, which looks like a failed load - and on rc44
                    // it actually WAS one.
                    'displayParams' => [
                        'rows' => 14,
                        'cols' => 140,
                        'placeholder' => 'LBL_ERP_QUOTE_COMMENT_EMPTY',
                    ],
                ],
                [
                    'name' => 'erp_comment_text',
                    'label' => 'LBL_ERP_COMMENT_TEXT',
                    'span' => 12,
                    // Room to actually write a paragraph - smaller than the
                    // read-back above on purpose, so the ERP's existing comment
                    // stays the thing your eye lands on and the box you type
                    // into sits under it.
                    'displayParams' => ['rows' => 8, 'cols' => 140],
                ],
                [
                    // THE BUTTON SITS UNDER THE FIELD, which is what the owner
                    // asked for ("a button under with update"). A record-view
                    // ACTION button would have landed in the header next to
                    // Edit - far from the box the seller just typed into, and
                    // on every tab rather than this one. Declaring it as a
                    // panel FIELD with a custom type is how erp_quantity_breaks
                    // already renders a non-field control inside a panel, so
                    // this follows a pattern the package proved rather than
                    // inventing placement.
                    'name' => 'update_erp_comment_button',
                    'type' => 'update-erp-comment',
                    'label' => 'LBL_ERP_UPDATE_COMMENT_BUTTON',
                    // 🛑 dismiss_label, OR THE WORD "Update" APPEARS TWICE.
                    // The field's own detail.hbs renders {{str label}} as the
                    // button's text, so with labelsOnTop the panel ALSO drew
                    // "Update" above it - which is how the first build ended up
                    // looking like a form row with an empty box rather than a
                    // button. The label stays declared because the template
                    // reads it; only the panel's copy is suppressed.
                    'dismiss_label' => true,
                    'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-primary',
                    'acl_action' => 'edit',
                    'span' => 12,
                ],
                // 🛑 erp_comment_requested_at IS DELIBERATELY NOT ON THIS TAB.
                // It is the connector's delta watermark - the machine trigger
                // the write-back sweeps on - and rendering it as "Comment Sent
                // At" put an internal timestamp in front of a seller as though
                // it were something to read or act on. It told them nothing
                // useful either: it says when the request was QUEUED, not when
                // Epicor accepted it, so a seller reading it as confirmation
                // would be reading it wrong. The honest confirmation is the ERP
                // Comment field above refreshing with their appended text on
                // the next sync. The field remains declared, audited and
                // written - it is simply not a thing the seller is shown.
            ],
        ];
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
}
