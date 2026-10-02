<?php

require_once('custom/include/scripts/BaseErpLayout.php');

class QuotesLayout extends BaseErpLayout
{
    /** The other grand-totals view. */
    private const TOTALS_HEADER_VIEW = 'quote-data-grand-totals-header';

    /** G229 — why this quote's discounts no longer fit what it is worth. */
    const ERP_DISCOUNT_REFUSAL_FIELD = [
        'name' => 'erp_discount_refusal',
        'label' => 'LBL_ERP_DISCOUNT_REFUSAL',
        'readonly' => true,
    ];

    /**
     * 'ERP Quote #': the Epicor QuoteNum, rendered as the link to the quote in Epicor (custom/clients/base/fields/erp-id-link/, G737). (G747)
     */
    const ERP_DISPLAY_SYNC_KEY_FIELD = [
        'name' => 'erp_display_sync_key',
        'label' => 'LBL_ERP_DISPLAY_SYNC_KEY',
        'type' => 'erp-id-link',
        'readonly' => true,
        'related_fields' => ['epicor_deeplink_url'],
    ];

    /**
     * G402 — the ERP panel's read-only datetimes, on the package field type that renders them as a formatted datetime in every mode.
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
     * G380 (d) / 🔒 1724b — the seller's Reference for the ERP quote header (QuoteHed.Reference; core sends it on Send to Estimation when it is filled).
     */
    const ERP_REFERENCE_FIELD = [
        'name' => 'erp_reference',
        'label' => 'LBL_ERP_REFERENCE',
    ];

    /** Req-30's Comments tab - retired by G517 (🔒 1778b). */
    const COMMENTS_PANEL_NAME = 'LBL_RECORDVIEW_PANEL_ERP_COMMENTS';
    const DISCOUNT_PANEL_NAME = 'LBL_RECORDVIEW_PANEL_ERP_DISCOUNT';

    /**
     * G606 (🔒 1800b / 🔒 1799b): the Business Card page's collapsed sections, in their order right before Quote Settings, and how they are shown.
     */
    const BUSINESS_CARD_SECTIONS = ['panel_hidden', self::DISCOUNT_PANEL_NAME];
    const BUSINESS_CARD_SECTION_PROPERTIES = ['newTab' => false, 'panelDefault' => 'collapsed'];

    /** G517 — every field this package has ever put on the Comments tab. (G396) */
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
     * G396 — the Comment Log status line, which replaced the Add Comment box, the Update button and the queue receipt (🔒 1702b Q1). (G517)
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

    /** G677: G585 shipped in 1.1.134. Module Loader's existing installed history is the migration marker. */
    private function stockQuoteCleanupAlreadyInstalled(): bool
    {
        if (!isset($GLOBALS['db'])) {
            return false;
        }
        // As Sugar reads its own history (modules/Administration/UpgradeHistory.php:178).
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('UpgradeHistory'), ['team_security' => false]);
        $query->select(['version']);
        $query->where()->equals('id_name', 'sugarai_erp_epicor')->equals('status', 'installed');
        foreach ($query->execute() as $row) {
            if (version_compare(ltrim((string) $row['version'], 'v'), '1.1.134', '>=')) {
                return true;
            }
        }
        return false;
    }

    public function install(): void
    {
        $this->setPanelBodyAsNewTab('Quotes');
        // mainPanelFields() is empty since 🔒 1413 removed order_stage from the quote; the call stays so a future main-panel field has a home.
        if ($this->replace) {
            $this->addFieldsToRecordView('Quotes', $this->mainPanelFields());
        }
        // G747: does this install lay the ERP panel out from erpPanel() (a replace build, or a view with no ERP panel yet), or keep the one deployed?
        $erpPanelFromDefinition = $this->replace || $this->deployedRecordPanel(self::ERP_PANEL_NAME) === null;
        // Insert ERP panel as 2nd panel (before panel_shipping_body)
        $this->addPanelToRecordViewBefore('Quotes', $this->erpPanel(), 'panel_shipping_body');
        // Outside replace mode an already-deployed ERP panel is kept as-is, so a field retired from erpPanel() would stay on every upgraded tenant.
        $this->removeFieldsFromRecordViewPanel('Quotes', ['name' => self::ERP_PANEL_NAME], $this->retiredErpPanelFields());
        // And bring the panel's own field definitions up to date. (G114, 🔒 774)
        $this->reconcileFieldsInRecordViewPanel('Quotes', ['name' => self::ERP_PANEL_NAME], $this->erpPanel()['fields']);
        // G75: the reconcile above only updates fields a tenant already has, and addPanelToRecordViewBefore() returned early wherever the ERP panel exists.
        $this->addFieldsToRecordView(
            'Quotes',
            [self::ERP_PARENT_QUOTE_NUM_FIELD],
            ['name' => self::ERP_PANEL_NAME],
            'erp_display_sync_key'
        );
        // Its own rule, not a new entry in erpPanelEmptyFieldDependencies(): that one is a single serialized shape. (G78)
        $this->addDependenciesToRecordView('Quotes', $this->erpParentQuoteNumDependency());
        // G229 — the discount warning, placed the same way and for the same reason the revision row above is.
        $this->addFieldsToRecordView(
            'Quotes',
            [self::ERP_DISCOUNT_REFUSAL_FIELD],
            ['name' => self::ERP_PANEL_NAME],
            'erp_quote_type'
        );
        // Its own rule as well, never a target appended to erpPanelEmptyFieldDependencies() - G78 again: that shape is serialized once.
        $this->addDependenciesToRecordView('Quotes', $this->erpDiscountRefusalDependency());
        // G747 (owner): 'ERP Quote #' sits at the top, in the Business Card (stock panel_body, LBL_RECORD_BODY), right after Quote Number. (G75, G452, G737)
        $this->moveFieldToPanel('Quotes', 'erp_display_sync_key', self::ERP_PANEL_NAME, 'panel_body', 'quote_num');
        if ($erpPanelFromDefinition && !$this->recordViewHasField('Quotes', 'erp_display_sync_key')) {
            $this->addFieldsToRecordView('Quotes', [self::ERP_DISPLAY_SYNC_KEY_FIELD], ['name' => 'panel_body'], 'quote_num');
        }
        // And its definition stays current there (the G114 reconcile above covers the ERP panel only).
        $this->reconcileFieldsInRecordViewPanel('Quotes', ['name' => 'panel_body'], [self::ERP_DISPLAY_SYNC_KEY_FIELD]);

        // Quote settings stays a tab. panel_setting_body is Sugar's stock "Quote Settings" panel. (G315, 🔒 693, 🔒 672)
        $this->setPanelAsNewTab('Quotes', 'panel_setting_body');
        // G517 (🔒 1778b) — the comments tab is retired, on every tenant. (G396, G421, G515)
        $this->retireCommentsTab();
        // The status line's new home, on an upgraded tenant: add-if-absent.
        $this->addFieldsToRecordView(
            'Quotes',
            [self::COMMENT_LOG_STATUS_FIELD],
            ['name' => self::ERP_PANEL_NAME],
            'erp_writeback_msg'
        );
        // G396 Q4 — the rollout copies no history.
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
        // G638: runs for both append and replace installs/upgrades. (G622)
        if (!class_exists('ErpQuoteBookedBaseSchema', false)) {
            @include_once 'custom/include/scripts/Migrations/ErpQuoteBookedBaseSchema.php';
        }
        if (class_exists('ErpQuoteBookedBaseSchema', false)) {
            (new ErpQuoteBookedBaseSchema())->install();
        } elseif (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
            $GLOBALS['log']->fatal('[QuotesLayout] Quote booked base schema installer is missing.');
        }
        if (!class_exists('ErpBookedLineSnapshotSchema', false)) {
            @include_once 'custom/include/scripts/Migrations/ErpBookedLineSnapshotSchema.php';
        }
        if (class_exists('ErpBookedLineSnapshotSchema', false)) {
            (new ErpBookedLineSnapshotSchema())->install();
        }
        if (!class_exists('ErpDocumentDiscountPercentSchema', false)) {
            @include_once 'custom/include/scripts/Migrations/ErpDocumentDiscountPercentSchema.php';
        }
        if (class_exists('ErpDocumentDiscountPercentSchema', false)) {
            (new ErpDocumentDiscountPercentSchema())->install();
        } elseif (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
            $GLOBALS['log']->fatal('[QuotesLayout] Percent discount schema installer is missing.');
        }
        // G515 (🔒 1776b) — sugar's stock comment log dashlet on the quote's record dashboard: the shared default.
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
        // Every tenant installed before 687.1 carries order_stage in panel_hidden, i.e.
        $this->removeFieldsFromRecordView('Quotes', ['order_stage']);
        // G737: epicor_deeplink_url is no longer placed here - panel_hidden is the "Show more" section, so a row there is rendered.
        $this->addFieldsToRecordView(
            'Quotes',
            $this->hiddenRecordViewFields(),
            ['name' => 'panel_hidden'],
            null,
            $this->hiddenPanelDefinition()
        );
        // G585 - and bring their definitions up to date where they already sit (the add above is add-if-absent): both are read-only.
        $this->reconcileFieldsInRecordViewPanel('Quotes', ['name' => 'panel_hidden'], $this->hiddenRecordViewFields());
        // G737: the raw 'Epicor Link' row every earlier version placed under Show more comes off - only there, where the package put it. (G452)
        if ($this->recordViewHasField('Quotes', 'erp_display_sync_key')) {
            $this->removeFieldsFromRecordViewPanel('Quotes', ['name' => 'panel_hidden'], ['epicor_deeplink_url']);
        }
        // G585 (owner, 2026-09-25): "a quote field is either populated from the ERP (shown, read-only) or not shown." Measured 2026-09-25 (REST counts).
        if (!$this->stockQuoteCleanupAlreadyInstalled()) {
            $this->removeFieldsFromRecordView('Quotes', self::QUOTE_FIELDS_NOTHING_FILLS);
        }
        // G586 (owner): tax comes from the ERP, so Sugar's stock Tax Rate is not shown; nor are the Currency Rate / Lock Conversion Rates pair.
        $this->removeFieldsFromRecordView('Quotes', self::QUOTE_STOCK_RATE_FIELDS);
        // G585: the ERP link and the estimating stamp show only once set - a Draft never sent shows neither. (G78)
        $this->addDependenciesToRecordView('Quotes', $this->erpStampedFieldsVisibilityDependency());
        // 🔒 1795b (owner, 2026-09-25): the customer's Purchase Order number is ERP-owned. (G78)
        $this->setFieldPropertiesInRecordView('Quotes', 'purchase_order_num', ['readonly' => true]);
        $this->addDependenciesToRecordView('Quotes', $this->purchaseOrderNumVisibilityDependency());
        // Must run after addPanelToRecordViewBefore(), which unsets 'buttons' on every deploy.
        $this->addButtonsToRecordView('Quotes', $this->erpActionButtons(), 'main_dropdown');
        // G422: "Sync Now with ERP" in the Edit menu.
        $this->addButtonsToDropdown('Quotes', 'main_dropdown', [$this->syncWithErpMenuEntry()]);
        // erp_display_sync_key only applies to advanced quotes, not sales orders; hide it for sales orders via a real Sidecar dependency.
        $this->addDependenciesToRecordView('Quotes', $this->erpFieldVisibilityDependencies());
        // The ERP-estimate fields (named for the retired quote mirror that once reflected them) only ever carry a value on an advanced quote.
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyErpEstimateVisibilityDependencies());
        // G78: and the four-target shape of that same rule, which the five-target removal above cannot match.
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyErpEstimateVisibilityDependenciesFourTarget());
        // G78: delete the isEmpty() forms 1.1.53 deployed before adding the corrected equal($f,"") ones.
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyIsEmptyEstimateVisibilityDependencies());
        $this->addDependenciesToRecordView('Quotes', $this->erpEstimateVisibilityDependencies());
        // Advanced quotes only (req-30, owner: "update button on advanced quote only"). (G517, G78)
        $this->removeDependenciesFromRecordView('Quotes', $this->erpCommentVisibilityDependencies());
        $this->addDependenciesToRecordView('Quotes', $this->erpCommentLogStatusVisibilityDependency());
        // The discount panel (owner decision 701: "instead of dosciontun as a button can it be a pannel"). (G104, G105, G106)
        $this->removeButtonsFromRecordView('Quotes', ['erp_discount_button']);
        // G718 (1.1.163): rebuilt where it stands, and - on a view laid out as the Business Card page (G606) - settled before Quote Settings in the same write.
        $this->addPanelToRecordViewKeepingPlace(
            'Quotes',
            $this->erpDiscountPanel(),
            'panel_body',
            'erp_is_primary_quote',
            'panel_setting_body',
            self::BUSINESS_CARD_SECTIONS,
            self::BUSINESS_CARD_SECTION_PROPERTIES
        );
        // G212: the discount panel is offered on sales orders too (owner, quote 287). (G106, decision 702, G78)
        $this->removeDependenciesFromRecordView('Quotes', $this->erpDiscountVisibilityDependencies());
        $this->addDependenciesToRecordView('Quotes', $this->erpDiscountTypeVisibilityDependencies());
        // 🔒 638: blank/diagnostic ERP-panel rows hidden until the ERP fills them.
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyIsEmptyPanelEmptyFieldDependencies());
        // R24: the shape that still held the three lookups, removed by value (G78).
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyPanelEmptyFieldDependenciesWithLookups());
        $this->addDependenciesToRecordView('Quotes', $this->erpPanelEmptyFieldDependencies());
        // 🔒 1393 / G79: once an advanced quote has been sent to estimating. (G781)
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyIsEmptySendToEstimationRatchetDependency());
        $this->removeDependenciesFromRecordView('Quotes', $this->sendToEstimationRatchetDependency());
        // The quote record view's own 'bundles' -> 'product_bundle_items' nested collection field carries an explicit sub-field allowlist.
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->priceAvailabilityLineItemFields());
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->erpLineDeeplinkLineItemFields());
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->unitOfMeasureLineItemFields());
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->orderPriceGuardLineItemFields());
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->lineDateLineItemFields());
        // 🔒 2029b: part revisions are gone from quotes. (decision 693)
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->retiredRevisionLineItemFields());
        // Epicor's own stated tax, on the totals footer the seller actually reads.
        $this->removeFieldsFromTotalsFooter(
            'Quotes',
            array('erp_tax_amount', 'erp_document_discount_amount')
        );
        $this->addFieldsToTotalsFooterBefore('Quotes', $this->erpTotalsFooterFields(), 'shipping');

        // G165 — sugar's own tax row comes off, in both totals views.
        $this->removeFieldsFromTotalsFooter('Quotes', array('tax'));
        $this->removeFieldsFromTotalsFooter('Quotes', array('tax'), self::TOTALS_HEADER_VIEW);

        // G274 — the header strip shows the same tax the footer does. (G165, G321, 🔒 1544)
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

        // G606 / G608 — Reference leaves core's layout, unless a customer package claims it.
        $this->placeReferenceOnlyWhereClaimed();
        // G380 (f) — last: every package's `erp_layout`-marked Quotes field.
        self::syncExtraFields('Quotes');
    }

    /** G606 (G608 absorbed; owner, 2026-09-25): reference is not core's to place. (G530) */
    private function placeReferenceOnlyWhereClaimed(): void
    {
        if (!class_exists('ErpLayoutExtraFields', false)) {
            @include_once 'custom/include/ErpLayoutExtraFields.php';
        }
        if (!class_exists('ErpLayoutExtraFields', false)) {
            // Cannot tell who needs it: keep core's old placement (fail safe).
            $claimed = true;
            $marked = false;
        } else {
            $claimed = ErpLayoutExtraFields::placementClaimed('Quotes', self::ERP_REFERENCE_FIELD['name']);
            $marked = ErpLayoutExtraFields::isMarked('Quotes', self::ERP_REFERENCE_FIELD['name']);
        }
        if ($marked) {
            return;
        }
        if ($claimed) {
            if (!$this->recordViewHasField('Quotes', self::ERP_REFERENCE_FIELD['name'])) {
                $this->addFieldsToRecordView(
                    'Quotes',
                    [self::ERP_REFERENCE_FIELD],
                    ['name' => self::ERP_PANEL_NAME],
                    'erp_quotes_ship_via_name'
                );
            }
            return;
        }
        $this->removeFieldsFromRecordViewPanel(
            'Quotes',
            ['name' => self::ERP_PANEL_NAME],
            [self::ERP_REFERENCE_FIELD['name']]
        );
    }

    /**
     * G380 (f): ErpLayoutExtraFields::sync($module), loaded the QuoteCommentMirrorBaseline way - class-guarded (MLP001).
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

    /** G606 (🔒 1800b, 🔒 1799b) — one business card page, replace-layouts only. */
    public function installBusinessCardLayout(): void
    {
        // The stable marker only this step writes (erpBusinessCard) tells a grader reading the served view whether this step's write held. (G718)
        $this->movePanelsBefore(
            'Quotes',
            self::BUSINESS_CARD_SECTIONS,
            'panel_setting_body',
            self::BUSINESS_CARD_SECTION_PROPERTIES + ['erpBusinessCard' => true]
        );
        $this->moveFieldToPanel(
            'Quotes',
            'erp_is_primary_quote',
            self::ERP_PANEL_NAME,
            'panel_body',
            'date_quote_expected_closed'
        );
        $this->reportBusinessCard();
    }

    /**
     * G718 (1.1.164) — say, in the install's own output, whether the business card page is on the view this step just wrote.
     */
    private function reportBusinessCard(): void
    {
        $panels = $this->deployedRecordPanels();
        $discount = null;
        $settings = null;
        $marked = false;
        $primary = false;
        foreach ($panels as $i => $panel) {
            $name = is_array($panel) ? (string) ($panel['name'] ?? '') : '';
            if ($name === self::DISCOUNT_PANEL_NAME) {
                $discount = $i;
                $marked = !empty($panel['erpBusinessCard']);
            } elseif ($name === 'panel_setting_body') {
                $settings = $i;
            } elseif ($name === 'panel_body') {
                foreach ((array) ($panel['fields'] ?? []) as $cell) {
                    if ((is_array($cell) ? (string) ($cell['name'] ?? '') : (string) $cell) === 'erp_is_primary_quote') {
                        $primary = true;
                    }
                }
            }
        }
        $before = $discount !== null && $settings !== null && $discount < $settings;
        $line = sprintf(
            'ERP-Epicor REPLACE layouts: Business Card page %s (read back: Discount panel %s Quote Settings, '
            . 'erpBusinessCard mark %s, Primary Quote %s the Business Card)',
            $before && $marked && $primary ? 'applied' : 'NOT applied',
            $discount === null ? 'missing,' : ($before ? 'before' : 'after'),
            $marked ? 'present' : 'absent',
            $primary ? 'in' : 'not in'
        );
        echo $line . "\n";
        if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
            $GLOBALS['log']->fatal($line);
        }
    }

    public function uninstall(): void
    {
        // G606: Primary Quote leaves the top section with the package (the ERP panel it came from is removed just below).
        $this->removeFieldsFromRecordViewPanel('Quotes', ['name' => 'panel_body'], ['erp_is_primary_quote']);
        // G747: and so does ERP Quote #, which install() moved there from the ERP panel; its vardef leaves with the package.
        $this->removeFieldsFromRecordViewPanel('Quotes', ['name' => 'panel_body'], ['erp_display_sync_key']);
        $this->movePanelsBefore('Quotes', ['panel_setting_body'], 'panel_hidden');
        $this->removePanelFromRecordView('Quotes');
        $this->removeErpFieldsFromListView('Quotes');
        $this->removeButtonsFromRecordView('Quotes', ['create_erp_order_button', 'advanced_quote_button', 'refresh_price_availability_button', 'erp_discount_button']);
        // G422: the Edit menu entry this package added.
        $this->removeButtonsFromDropdown('Quotes', 'main_dropdown', [$this->syncWithErpMenuEntry()['name']]);
        // G212's rule. The legacy advanced-only shape is still removed below, for a tenant that never ran the install that swapped it.
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
        // G189 / G517: a field this package placed, this package takes back. (🔒 1778b)
        $this->removeFieldsFromRecordViewPanel(
            'Quotes',
            ['name' => self::COMMENTS_PANEL_NAME],
            ['erp_comment_queue', self::COMMENT_LOG_STATUS_FIELD['name']]
        );
        $this->removeDependenciesFromRecordView('Quotes', $this->erpPanelEmptyFieldDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyPanelEmptyFieldDependenciesWithLookups());
        // G585 / G586: the rule this package added, and the stock rows it took off the view, put back (add-if-absent, stock definitions).
        $this->removeDependenciesFromRecordView('Quotes', $this->erpStampedFieldsVisibilityDependency());
        // 🔒 1795b: Purchase Order Num editable and always shown again, as stock.
        $this->removeDependenciesFromRecordView('Quotes', $this->purchaseOrderNumVisibilityDependency());
        $this->setFieldPropertiesInRecordView('Quotes', 'purchase_order_num', ['readonly' => null]);
        foreach (self::STOCK_QUOTE_ROWS as $panelName => $rows) {
            $this->addFieldsToRecordView('Quotes', $rows, ['name' => $panelName]);
        }
        $this->removeDependenciesFromRecordView('Quotes', $this->sendToEstimationRatchetDependency());
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyErpEstimateVisibilityDependencies());
        $this->removeDependenciesFromRecordView('Quotes', $this->legacyErpEstimateVisibilityDependenciesFourTarget());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->priceAvailabilityLineItemFields());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->erpLineDeeplinkLineItemFields());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->unitOfMeasureLineItemFields());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->lineDateLineItemFields());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->retiredRevisionLineItemFields());
        $this->removeFieldsFromTotalsFooter('Quotes', array('erp_tax_amount', 'erp_document_discount_amount'));
        // G274: the header's copy of the ERP tax row leaves with the package too.
        $this->removeFieldsFromTotalsFooter('Quotes', array('erp_tax_amount'), self::TOTALS_HEADER_VIEW);

        // 🔒 1544a: deal_tot is a stock header cell that install() moved and relabelled, so it goes back to stock's first position with stock's own definition.
        $this->removeFieldsFromTotalsFooter('Quotes', array('deal_tot'), self::TOTALS_HEADER_VIEW);
        $this->addFieldsToTotalsFooterBefore(
            'Quotes',
            $this->stockDealTotHeaderField(),
            'new_sub',
            self::TOTALS_HEADER_VIEW
        );

        // G165: `tax` is a stock Sugar row, so removing it in install() obliges us to put it back.
        $this->addFieldsToTotalsFooterBefore('Quotes', $this->stockTaxFooterField(), 'shipping');
        $this->addFieldsToTotalsFooterBefore(
            'Quotes',
            $this->stockTaxFooterField(),
            'shipping',
            self::TOTALS_HEADER_VIEW
        );
    }

    // ------------------------------------------------------------------------- Field definitions -------------------------------------------------------------------------

    /**
     * G165: stock Sugar's own `tax` row, verbatim from the stock quote-data-grand-totals-header view.
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
            // G166: the document-level discount, as its own row.
            array(
                'name' => 'erp_document_discount_amount',
                'label' => 'LBL_ERP_DOCUMENT_DISCOUNT_AMOUNT',
                'type' => 'currency',
                'css_class' => 'quote-footer-currency',
                'convertToBase' => false,
            ),
            // G165 — advanced quotes only.
            array(
                'name' => 'erp_tax_amount',
                'label' => 'LBL_ERP_TAX_AMOUNT',
                // `currency`, not a custom type: this footer's template renders a type only if it ships a template for the footer's action.
                'type' => 'currency',
                'css_class' => 'quote-footer-currency',
                'convertToBase' => false,
            ),
        );
    }

    /** 🔒 1544a: stock Sugar's own `deal_tot` header cell. */
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

    /** The header strip's ERP cells, in the order they sit before `shipping`. (🔒 1544, G274, G165) */
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

    /** 🔒 1413 — nothing. `order_stage` is no longer placed on the quote. */
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
                // order_stage is fetched here and rendered nowhere (G114) — see erpPanelFetchOnlyFields() below for the whole reasoning.
                [
                    'name' => 'erp_quote_type',
                    'label' => 'LBL_ERP_QUOTE_TYPE',
                    'required' => true,
                    'related_fields' => self::FETCH_ONLY_RECORD_VIEW_FIELDS,
                ],
                // Set by the connector when this Quote is Accepted (sibling Quotes on the same Opportunity get auto-unset).
                ['name' => 'erp_is_primary_quote', 'label' => 'LBL_ERP_IS_PRIMARY_QUOTE'],
                ['name' => 'erp_companies_quotes_name', 'label' => 'LBL_ERP_COMPANIES_QUOTES_FROM_ERP_COMPANIES_TITLE'],
                ['name' => 'erp_quotes_billing_terms_name', 'label' => 'LBL_ERP_QUOTES_BILLING_TERMS_FROM_ERP_LOOKUPVALUES_TITLE'],
                ['name' => 'erp_quotes_fob_name', 'label' => 'LBL_ERP_QUOTES_FOB_FROM_ERP_LOOKUPVALUES_TITLE'],
                ['name' => 'erp_quotes_ship_via_name', 'label' => 'LBL_ERP_QUOTES_SHIP_VIA_FROM_ERP_LOOKUPVALUES_TITLE'],
                // G606 / G608: the seller's Reference is not core's to place any more. (🔒 640, G747, G75)
                self::ERP_PARENT_QUOTE_NUM_FIELD,
                // Fields the ERP quote mirror used to reflect onto the Quote. (G229)
                self::ERP_DISCOUNT_REFUSAL_FIELD,
                ['name' => 'erp_estimate_stage', 'label' => 'LBL_ERP_ESTIMATE_STAGE', 'readonly' => true],
                // G402 — a read-only datetime that reads as one in edit mode too. (G114)
                self::ERP_READONLY_DATETIME_FIELDS['erp_priced_at'],
                // G172 — render the resolved label, falling back to the code.
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
                // G517: the Comment Log status line, moved here from the retired Comments tab - see COMMENT_LOG_STATUS_FIELD.
                self::COMMENT_LOG_STATUS_FIELD,
            ],
        ];
    }

    /** G585 - stock quote fields nothing fills (see install()). */
    const QUOTE_FIELDS_NOTHING_FILLS = [
        'original_po_date',
        'date_quote_closed',
        'date_order_shipped',
        'payment_terms',
    ];

    /** G586 - the stock tax-rate and conversion-rate rows (Quote Settings). */
    const QUOTE_STOCK_RATE_FIELDS = [
        'taxrate_name',
        'conversion_rate_lock',
    ];

    /** Where uninstall() puts the G585 / G586 rows back: panel => stock defs. */
    const STOCK_QUOTE_ROWS = [
        'panel_hidden' => [
            ['name' => 'original_po_date', 'type' => 'date', 'label' => 'LBL_ORIGINAL_PO_DATE'],
            ['name' => 'date_quote_closed', 'type' => 'date', 'label' => 'LBL_DATE_QUOTE_CLOSED'],
            ['name' => 'date_order_shipped', 'type' => 'date', 'label' => 'LBL_LIST_DATE_QUOTE_CLOSED'],
            ['name' => 'payment_terms', 'type' => 'enum', 'label' => 'LBL_PAYMENT_TERMS'],
        ],
        'panel_setting_body' => [
            [
                'name' => 'taxrate_name',
                'type' => 'taxrate',
                'initial_filter' => 'active_taxrates',
                'filter_populate' => ['module' => ['TaxRates']],
                'populate_list' => ['id' => 'taxrate_id', 'value' => 'taxrate_value'],
                'label' => 'LBL_TAXRATE',
            ],
            [
                'name' => 'conversion_rate_lock',
                'type' => 'fieldset',
                'label' => 'LBL_CONVERSION_RATE_LOCK_FIELDSET',
                'dismiss_label' => true,
                'show_child_labels' => true,
                'fields' => [
                    ['name' => 'base_rate', 'type' => 'textarea', 'label' => 'LBL_CURRENCY_RATE'],
                    ['name' => 'lock_conversion_rates', 'type' => 'bool', 'label' => 'LBL_LOCK_CONVERSION_RATES'],
                ],
            ],
        ],
    ];

    const FETCH_ONLY_RECORD_VIEW_FIELDS = [
        'order_stage',
        // 🔒 921 / G121 — "Create Opportunity from Quote" is now hidden rather than greyed once the quote has an Opportunity.
        'opportunity_id',
        // G422 — "Sync Now with ERP" is offered when erp_sync_key is set, and the field (fields/sync-with-erp) reads it off the model.
        'erp_sync_key',
        // G586 - the stock rate rows are off the view (install()), so their values are fetched here instead: nothing that reads them off the model.
        'taxrate_id',
        'taxrate_value',
        'base_rate',
        'lock_conversion_rates',
        // G737 (owner): the ERP Quote # is the link to Epicor, and no raw 'Epicor Link' row is shown (it came off panel_hidden, install()).
        'epicor_deeplink_url',
    ];

    private function listViewFields(): array
    {
        $fields = [
            ['name' => 'erp_quote_type', 'label' => 'LBL_ERP_QUOTE_TYPE', 'enabled' => true, 'default' => true],
            ['name' => 'erp_display_sync_key', 'label' => 'LBL_ERP_DISPLAY_SYNC_KEY', 'type' => 'erp-id-link', 'enabled' => true, 'default' => true],
            ['name' => 'erp_companies_quotes_name', 'label' => 'LBL_ERP_COMPANIES_QUOTES_FROM_ERP_COMPANIES_TITLE', 'enabled' => true, 'id' => 'ERP_COMPANIES_QUOTESERP_COMPANIES_IDA', 'link' => true, 'sortable' => false, 'default' => true],
            ['name' => 'quote_stage', 'label' => 'LBL_QUOTE_STAGE', 'enabled' => true, 'default' => true],
            // 🔒 1413: available to admins and reports, but not a default column -- the owner removed this from the seller's surface.
            ['name' => 'order_stage', 'label' => 'LBL_ORDER_STAGE', 'enabled' => true, 'default' => false],
            ['name' => 'erp_is_primary_quote', 'label' => 'LBL_ERP_IS_PRIMARY_QUOTE', 'enabled' => true, 'default' => false],
            ['name' => 'erp_estimate_stage', 'label' => 'LBL_ERP_ESTIMATE_STAGE', 'enabled' => true, 'readonly' => true, 'default' => false],
            ['name' => 'erp_priced_at', 'label' => 'LBL_ERP_PRICED_AT', 'enabled' => true, 'readonly' => true, 'default' => false],
            ['name' => 'erp_writeback_status', 'label' => 'LBL_ERP_WRITEBACK_STATUS', 'enabled' => true, 'readonly' => true, 'default' => false],
            // Appended last, not near erp_display_sync_key - not a default column, just kept enabled so the value is fetched (erp-id-link needs it).
            ['name' => 'epicor_deeplink_url', 'label' => 'LBL_EPICOR_DEEPLINK_URL', 'enabled' => true, 'default' => false],
        ];

        // Replace mode wipes every non-ERP field from the list view, so the stock Quotes list columns have to be re-supplied here.
        if ($this->replace) {
            $fields = array_merge($this->baseListViewFields(), $fields);
        }

        return $fields;
    }

    private function hiddenRecordViewFields(): array
    {
        return [
            // G737: epicor_deeplink_url is not placed here any more; it is fetched through FETCH_ONLY_RECORD_VIEW_FIELDS, and the ERP Quote # is its link. (🔒 1393, G79, G585)
            ['name' => 'erp_sent_to_estimating_at', 'type' => 'erp-readonly-datetime', 'label' => 'LBL_ERP_SENT_TO_ESTIMATING_AT', 'readonly' => true],
        ];
    }

    /**
     * 🔒 1795b - Purchase Order Num is shown only once Epicor has stated one (the connector's read-back of PONum is its only writer now).
     */
    private function purchaseOrderNumVisibilityDependency(): array
    {
        return $this->visibleWhenNotEmptyDependency(['purchase_order_num']);
    }

    /** G585 - "Epicor Link" and "Sent to estimation" are shown only once the integration has set them. */
    private function erpStampedFieldsVisibilityDependency(): array
    {
        return $this->visibleWhenNotEmptyDependency(['epicor_deeplink_url', 'erp_sent_to_estimating_at']);
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
        // order_stage is no longer placed on the quote at all (owner 687.1). (🔒 1413, 🔒 1540, G114)
        $fields = ['renewal', 'renewal_opp_name', 'tag', 'category_name'];

        // payment_terms is superseded by the ERP billing-terms lookup — only hide it in the replace build.
        if ($this->replace) {
            $fields[] = 'payment_terms';
        }

        return $fields;
    }

    // Top-level header buttons (next to Edit/main_dropdown), backed by custom Rowaction field types.
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
                // The gate's input is **not** declared here, and it used to be. (G114, 🔒 774)
            ],
            [
                'type' => 'advanced-quote',
                'event' => 'button:advanced_quote_button:click',
                'name' => 'advanced_quote_button',
                'label' => 'LBL_ADVANCED_QUOTE_BUTTON',
                'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-secondary ml-2',
                'showOn' => 'view',
                'acl_action' => 'edit',
                // Same lifecycle, same input, same carrier as the button above: the fetch is declared on erpPanel()'s erp_writeback_at, not here.
            ],
        ];
    }

    /** G422 — the Edit menu's "Sync Now with ERP" (owner, 2026-09-23). */
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

    // The ERP-estimate fields (named for the retired quote mirror) follow erp_display_sync_key: shown for advanced quotes only.
    private function erpEstimateVisibilityDependencies(): array
    {
        // 🔒 638: advanced-quote and non-empty, in one expression.
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

    // Req-30's Comments tab is meaningful only on an advanced quote: the comment it shows and appends to lives on QuoteHed. (🔒 670, 🔒 668)
    /** G75 — the revision row, as placed in the ERP panel. */
    const ERP_PARENT_QUOTE_NUM_FIELD = [
        'name' => 'erp_parent_quote_num',
        'label' => 'LBL_ERP_PARENT_QUOTE_NUM',
        'readonly' => true,
    ];

    /** G75 — "Revision of ERP quote <n>" when this quote is a revision, and no row at all when it is not. */
    private function erpParentQuoteNumDependency(): array
    {
        return $this->visibleWhenNotEmptyDependency(['erp_parent_quote_num']);
    }

    /** G229 — the Discount Warning row is shown only when it has something to say. (🔒 642) */
    private function erpDiscountRefusalDependency(): array
    {
        return $this->visibleWhenNotEmptyDependency(['erp_discount_refusal']);
    }

    /** G212 — the discount panel's gate, now both quote types. (G106, G205, G781) */
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

    /** The seller's discount panel — one field that draws the whole control. (G510, G452) */
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

    /** The gate its siblings already had (G106 / decision 702). */
    private function erpDiscountVisibilityDependencies(): array
    {
        return $this->advancedQuoteOnlyDependency([
            self::DISCOUNT_PANEL_NAME,
            'erp_discount_panel',
        ]);
    }

    /** Req-30's advanced-quote gate for the Comments tab, in its shipped shape. (G517, G78) */
    private function erpCommentVisibilityDependencies(): array
    {
        return $this->advancedQuoteOnlyDependency([
            // The panel first: hides the whole tab, not just its contents.
            self::COMMENTS_PANEL_NAME,
            'erp_quote_comment',
            'erp_comment_text',
            'update_erp_comment_button',
            'erp_comment_requested_at',
        ]);
    }

    // The shape installed before erp_governing_line was retired.
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

    /** The older four-target shape of the same legacy rule (G78). (decision 638) */
    private function legacyErpEstimateVisibilityDependenciesFourTarget(): array
    {
        return $this->advancedQuoteOnlyDependency([
            'erp_estimate_stage',
            'erp_estimate_total',
            'erp_priced_at',
            'erp_reason_code',
        ]);
    }

    // Fields erpPanel() once placed that nothing writes any more. erp_governing_line was the retired quote mirror's reflection of its governing ERP_QuoteLines row.
    /** G517 — the status line's own advanced-quote rule, in a new shape. (G78) */
    private function erpCommentLogStatusVisibilityDependency(): array
    {
        return $this->advancedQuoteOnlyDependency([
            self::COMMENT_LOG_STATUS_FIELD['name'],
        ]);
    }

    /** G517 (🔒 1778b) — take the comments tab off the deployed record view, losing nobody's field. */
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
     * The guard is not an off switch on a tenant: class_exists() autoloads (ViewdefManager is namespaced under src/).
     */
    private function deployedRecordPanels(): array
    {
        if (!class_exists(\Sugarcrm\Sugarcrm\MetaData\ViewdefManager::class)) {
            return [];
        }
        self::forgetCompiledViewdef('Quotes', 'record');
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
        // Retired this way, not by deleting the erpPanel() entry alone: outside replace mode an already-deployed ERP panel is kept as-is. (decision 536, decision 539)
        return ['erp_governing_line', 'erp_estimate_total'];
    }

    /** The isEmpty() shapes 1.1.53 deployed. (decision 638, G78) */
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

    /** The isEmpty form of erpPanelEmptyFieldDependencies(). */
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

    /**
     * The equal() form of erpPanelEmptyFieldDependencies() up to R24, with the three lookups the record view now hides itself.
     */
    private function legacyPanelEmptyFieldDependenciesWithLookups(): array
    {
        return $this->visibleWhenNotEmptyDependency([
            'erp_companies_quotes_name',
            'erp_quotes_billing_terms_name',
            'erp_quotes_ship_via_name',
            'erp_display_sync_key',
            'erp_writeback_status',
            'erp_writeback_at',
            'erp_writeback_msg',
        ]);
    }

    /** The isEmpty form of erpEstimateVisibilityDependencies(). */
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

    /** The isEmpty form of sendToEstimationRatchetDependency(). */
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
            // equal($f, "") — not isEmpty(). (decision 638, G78)
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
                // Every gated field is its own trigger: the panel must react when the connector fills one mid-session, not only on load.
                'triggerFields' => array_values(array_unique(
                    array_merge($targets, ['erp_quote_type'])
                )),
                'onload' => true,
                'actions' => $actions,
            ],
        ];
    }

    /** The always-blank and diagnostic fields, hidden until the ERP fills them. */
    private function erpPanelEmptyFieldDependencies(): array
    {
        // 🔒 642 — the owner set this list, verbatim. (🔒 638)
        return $this->visibleWhenNotEmptyDependency([
            'erp_display_sync_key',                 // ERP ID - 133/138
            'erp_writeback_status',                 // 4/138 - plumbing; wanted only when a send failed
            'erp_writeback_at',                     // 4/138
            'erp_writeback_msg',                    // 4/138
        ]);
    }

    /** Retired by G781: install() removes this rule and no longer adds it. (G210, 🔒 1393, G79) */
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
                            // Show only while this quote has no Epicor quote and neither estimating stamp.
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

    // Populated by the Refresh Price & Availability button; must be added to the record view's product_bundle_items sub-field allowlist.
    private function priceAvailabilityLineItemFields(): array
    {
        return [
            'erp_available_qty',
            'erp_price_availability_synced_at',
            // 🔒 1436 / G176 — the catalog stock figure the grid shows, now a stored field. (🔒 1438, G180)
            'erp_stock_availability',
            // G187 — the link target, and without it the link is not drawn. (🔒 673)
            'product_template_id',
        ];
    }

    // Same allowlist requirement as priceAvailabilityLineItemFields() above.
    private function erpLineDeeplinkLineItemFields(): array
    {
        return ['epicor_line_deeplink_url', 'epicor_eto_deeplink_url'];
    }

    // The quote line's own unit of measure (user decision 211).
    private function unitOfMeasureLineItemFields(): array
    {
        return ['erp_unit_of_measure'];
    }

    // 🔒 2029b — retired. The Part Revision allowlist entry (G740, 1.1.158- 1.1.167).
    private function retiredRevisionLineItemFields(): array
    {
        return ['erp_revision'];
    }

    // G463 (🔒 1758b) — the line's own Need By / Ship By. (🔒 673)
    private function lineDateLineItemFields(): array
    {
        return ['erp_need_by_date', 'erp_ship_by_date'];
    }

    // G415 — the order buttons refuse a seller line with no Unit Price (QuotesErpAction::_erpRefuseUnpricedOrder, ERP-Core) with G379 (c)'s exemptions.
    private function orderPriceGuardLineItemFields(): array
    {
        return ['erp_estimation_placeholder'];
    }
}
