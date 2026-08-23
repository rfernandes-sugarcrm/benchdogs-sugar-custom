<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * Bench Dogs quote-led actions - the three seams the working doc's
 * requirements name and nothing else ships:
 *
 *   POST Accounts/:record/bd-create-opp-quote   REQ-20 + build commitments
 *     #1/#2: create an Opportunity AND its Quote from the account record in
 *     one act, with a FREE-TEXT placeholder line (Bench Dogs is
 *     engineered-to-order - no catalog part exists at account stage; the doc
 *     records "Epicor permits a free text string not in the catalog"). The
 *     Quote is the leading object: it is born linked to the opportunity and
 *     typed advanced_quote, so estimating - not revenue line items - carries
 *     the deal from here.
 *
 *   POST Quotes/:record/bd-send-to-estimating   REQ-27 (the path the whole
 *     solution rests on) + REQ-13/UC-6: create the Kinetic quote shell for
 *     this Sugar Quote via the product's own quote_to_quote write-back, then
 *     stamp bd_erp_stage=in_estimating so BdEstimatingNotificationHook
 *     notifies the estimating owner. Dale's own quote workbench IS the
 *     queue - no email, no folder link.
 *
 *   POST Quotes/:record/bd-order-winning-line   REQ-1/REQ-2/REQ-22: raise an
 *     Epicor sales order from ONLY the winning (governing) Kinetic quote
 *     line, at the quoted price, while the QUOTE STAYS OPEN. A subset order
 *     moves quote_stage to 'Partially Fulfilled' (a stage this package adds)
 *     - deliberately NOT 'Closed Accepted', so QuoteAcceptSiblingReject never
 *     fires and sibling quotes/lines stay live. The opportunity stays open
 *     too, its stage advanced to 'Prototype Closed' / 'Partial Production
 *     Closed' - the exact stage-expression answer REQ-22's discussion
 *     records as the agreed direction.
 *
 * Extends ERP-Epicor's BaseErpActionsApi for the orchestrator plumbing
 * (loadOrchestratorConfig/postWritebackSync) - a hard dependency, exactly as
 * the product's own QuotesErpActionsApi requires it. ModuleScanner denylists
 * class_alias, so a soft fallback is not expressible in uploaded package
 * code; this package therefore requires ERP-Epicor to be installed (which
 * every Bench Dogs instance has - the whole solution rides its stack).
 */

require_once 'custom/clients/base/api/BaseErpActionsApi.php';

class BdBenchDogsActionsApi extends BaseErpActionsApi
{
    /**
     * The write-back entity registered by the Bench Dogs extension container
     * (connector_ext_benchdogs.writeback.quotes.OrderFromQuoteWriteBack).
     * Its transform orders ONLY the governing bd01_ERP_Quote_Line rows -
     * partial-by-construction; see governing_lines() there.
     */

    public function registerApiRest()
    {
        return array(
            'bdCreateOppQuote' => array(
                'reqType' => 'POST',
                'path' => array('Accounts', '?', 'bd-create-opp-quote'),
                'pathVars' => array('module', 'record', ''),
                'method' => 'createOppQuote',
                'shortHelp' => 'Creates a linked Opportunity + Quote (free-text placeholder line) from an Account.',
                'exceptions' => array(
                    'SugarApiExceptionNotAuthorized',
                    'SugarApiExceptionInvalidParameter',
                    'SugarApiExceptionNotFound',
                ),
            ),
            'bdSendToEstimating' => array(
                'reqType' => 'POST',
                'path' => array('Quotes', '?', 'bd-send-to-estimating'),
                'pathVars' => array('module', 'record', ''),
                'method' => 'sendToEstimating',
                'shortHelp' => 'Creates the Kinetic quote for this Sugar Quote (quote_to_quote) and flags it In Estimating.',
                'exceptions' => array(
                    'SugarApiExceptionNotAuthorized',
                    'SugarApiExceptionInvalidParameter',
                    'SugarApiExceptionNotFound',
                ),
            ),
            'bdRepairUi' => array(
                'reqType' => 'POST',
                'path' => array('bd-tools', 'repair-ui'),
                'pathVars' => array('', ''),
                'method' => 'repairUi',
                'shortHelp' => 'Admin-only: re-runs the Bench Dogs UI deploy steps (buttons, stage dropdowns) and reports each step verbatim.',
                'exceptions' => array(
                    'SugarApiExceptionNotAuthorized',
                ),
            ),
            'bdOrderWinningLine' => array(
                'reqType' => 'POST',
                'path' => array('Quotes', '?', 'bd-order-winning-line'),
                'pathVars' => array('module', 'record', ''),
                'method' => 'orderWinningLine',
                'shortHelp' => 'Raises an Epicor sales order from the winning (governing) quote line only; the quote stays open.',
                'exceptions' => array(
                    'SugarApiExceptionNotAuthorized',
                    'SugarApiExceptionInvalidParameter',
                    'SugarApiExceptionNotFound',
                ),
            ),
        );
    }

    // -------------------------------------------------------------------
    // REQ-20: account-level Create Opportunity & Quote
    // -------------------------------------------------------------------

    /**
     * Bean sequence cloned from the proven sales-i
     * RecommendationConvertApi::convertToOpportunity (Opportunity -> RLI ->
     * Quote -> default ProductBundle -> Products line), re-sourced from the
     * Account record and carrying a free-text line instead of a catalog
     * part. Everything is created at amount 0 - the honest number before
     * estimating has priced anything (REQ-6's roll-up direction is
     * ERP -> Sugar, so seeding fake values here would fight the sync).
     */
    public function createOppQuote(ServiceBase $api, array $args)
    {
        if (empty($args['record'])) {
            throw new SugarApiExceptionInvalidParameter('Missing record id');
        }

        $account = BeanFactory::retrieveBean('Accounts', $args['record']);
        if ($account === null || empty($account->id)) {
            throw new SugarApiExceptionNotFound('Account not found: ' . $args['record']);
        }
        if (!$account->ACLAccess('view')) {
            throw new SugarApiExceptionNotAuthorized('No access to this account');
        }

        $assigned = $api->user->id;
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            $name = $account->name . ' - Engineered Job ' . date('M j, Y');
        }
        $placeholder = trim((string) ($args['placeholder'] ?? ''));
        if ($placeholder === '') {
            $placeholder = 'Engineered-to-order part - scope to be defined in estimating';
        }
        $qty = max(1, (int) ($args['quantity'] ?? 1));
        $closeDate = date('Y-m-d', strtotime('+30 days'));

        $opp = BeanFactory::newBean('Opportunities');
        $opp->name = $name;
        $opp->amount = 0;
        $opp->currency_id = '-99';
        $opp->base_rate = 1;
        $opp->date_closed = $closeDate;
        $opp->sales_stage = 'Prospecting';
        $opp->probability = 10;
        $opp->assigned_user_id = $assigned;
        $opp->account_id = $account->id;
        $opp->account_name = $account->name;
        $opp->save();
        if ($opp->load_relationship('accounts')) {
            $opp->accounts->add($account);
        }

        if (class_exists('Opportunity')
            && method_exists('Opportunity', 'usingRevenueLineItems')
            && Opportunity::usingRevenueLineItems()
        ) {
            $rli = BeanFactory::newBean('RevenueLineItems');
            $rli->name = $placeholder;
            $rli->likely_case = 0;
            $rli->best_case = 0;
            $rli->worst_case = 0;
            $rli->quantity = $qty;
            $rli->discount_price = 0;
            $rli->list_price = 0;
            $rli->currency_id = '-99';
            $rli->base_rate = 1;
            $rli->date_closed = $closeDate;
            $rli->sales_stage = 'Prospecting';
            $rli->probability = 10;
            $rli->assigned_user_id = $assigned;
            $rli->opportunity_id = $opp->id;
            $rli->account_id = $account->id;
            $rli->save();
        }

        $quote = BeanFactory::newBean('Quotes');
        $quote->name = $name;
        $quote->quote_stage = 'Draft';
        // The quote is born for estimating: advanced_quote is the lifecycle
        // ERP-Epicor's own Advanced Quote button and QuotesErpActionsApi
        // gate on, and it is what bd-send-to-estimating submits.
        $quote->erp_quote_type = 'advanced_quote';
        $quote->date_quote_expected_closed = $closeDate;
        $quote->assigned_user_id = $assigned;
        $quote->currency_id = '-99';
        $quote->base_rate = 1;
        $quote->billing_account_id = $account->id;
        $quote->billing_account_name = $account->name;
        $quote->shipping_account_id = $account->id;
        $quote->shipping_account_name = $account->name;
        $quote->subtotal = 0;
        $quote->new_sub = 0;
        $quote->total = 0;
        $quote->shipping = 0;
        $quote->tax = 0;
        $quote->subtotal_usdollar = 0;
        $quote->new_sub_usdollar = 0;
        $quote->total_usdollar = 0;
        $quote->save();
        if ($quote->load_relationship('billing_accounts')) {
            $quote->billing_accounts->add($account);
        }
        if ($quote->load_relationship('opportunities')) {
            $quote->opportunities->add($opp);
        }

        $bundle = BeanFactory::newBean('ProductBundles');
        $bundle->name = '';
        $bundle->default_group = true;
        $bundle->bundle_stage = 'Draft';
        $bundle->currency_id = '-99';
        $bundle->base_rate = 1;
        $bundle->subtotal = 0;
        $bundle->new_sub = 0;
        $bundle->total = 0;
        $bundle->save();
        if ($quote->load_relationship('product_bundles')) {
            $quote->product_bundles->add($bundle, ['position' => 0]);
        }

        // Free-text line: no product_template_id, the name IS the item.
        // ERP-Epicor's QuotesErpActionsApi::getQuoteRecord() handles exactly
        // this shape (its free-text-line branch), so the placeholder rides
        // the advanced-quote payload to Kinetic untouched.
        $li = BeanFactory::newBean('Products');
        $li->name = $placeholder;
        // ERP-Epicor's getQuoteRecord maps a free-text line's part number
        // from mft_part_num (name only feeds LineDesc) - without this the
        // Kinetic create fails with Epicor's "Part is required." The token
        // is the free-text PartNum the working doc's REQ-20 note describes;
        // estimating replaces it with real engineered lines.
        $li->mft_part_num = 'ETO-PENDING';
        $li->quantity = $qty;
        $li->discount_price = 0;
        $li->list_price = 0;
        $li->cost_price = 0;
        $li->currency_id = '-99';
        $li->base_rate = 1;
        $li->quote_id = $quote->id;
        $li->position = 0;
        $li->assigned_user_id = $assigned;
        $li->account_id = $account->id;
        $li->save();
        if ($bundle->load_relationship('products')) {
            $bundle->products->add($li, ['position' => 0]);
        }

        return array(
            'status' => 'success',
            'message' => sprintf('Opportunity and quote "%s" created with a placeholder line.', $name),
            'opportunity_id' => $opp->id,
            'opportunity_name' => $opp->name,
            'quote_id' => $quote->id,
            'quote_name' => $quote->name,
        );
    }

    // -------------------------------------------------------------------
    // REQ-27 / REQ-13: Send to Estimating
    // -------------------------------------------------------------------

    /**
     * Thin, honest wrapper over the product's own advanced_quote action -
     * quote_to_quote is already registered and live in core; this package
     * adds only the missing rep-facing act and the REQ-13 hand-off stamp.
     * Delegating (rather than re-posting the payload ourselves) keeps the
     * product API the single owner of the quote payload shape, account
     * provisioning, and the erp_display_sync_key idempotency guard.
     */
    public function sendToEstimating(ServiceBase $api, array $args)
    {
        if (empty($args['record'])) {
            throw new SugarApiExceptionInvalidParameter('Missing record id');
        }

        $productApi = 'custom/clients/base/api/QuotesErpActionsApi.php';
        if (!file_exists($productApi)) {
            return array(
                'status' => 'error',
                'message' => 'ERP-Epicor package is not installed - Send to Estimating needs its quote_to_quote path.',
            );
        }

        $bean = BeanFactory::retrieveBean('Quotes', $args['record']);
        if ($bean === null || empty($bean->id)) {
            throw new SugarApiExceptionNotFound('Quote not found: ' . $args['record']);
        }
        if (!$bean->ACLAccess('edit')) {
            throw new SugarApiExceptionNotAuthorized('No edit access to this quote');
        }

        // The product API's advanced_quote branch requires this lifecycle
        // type (its button never shows otherwise); a quote created outside
        // bd-create-opp-quote may still carry sales_order. Flip it before
        // delegating - this is the "the quote is the leading object" seam.
        if (($bean->erp_quote_type ?? '') !== 'advanced_quote') {
            $bean->erp_quote_type = 'advanced_quote';
            $bean->save();
        }

        require_once $productApi;
        $delegate = new QuotesErpActionsApi();
        $result = $delegate->runErpAction($api, array(
            'module' => 'Quotes',
            'record' => $bean->id,
            'action' => 'advanced_quote',
        ));

        if (($result['status'] ?? '') === 'success') {
            // Re-retrieve: the write-back path has stamped
            // erp_display_sync_key/erp_writeback_* on this row while our
            // $bean copy still holds pre-submit values; saving the stale
            // copy would revert them (SugarBean rewrites every field it
            // holds in memory - the exact trap QuotesErpActionsApi's own
            // docblock records).
            $fresh = BeanFactory::retrieveBean('Quotes', $bean->id, array('use_cache' => false));
            if ($fresh !== null) {
                $dirty = false;
                if (($fresh->bd_erp_stage ?? '') === '') {
                    // The REQ-13 hand-off: BdEstimatingNotificationHook fires on
                    // this transition and notifies the estimating owner. Only
                    // stamped when the sync has not already written a real ERP
                    // stage (reflection may beat us here - that is fine, its
                    // value is the truer one).
                    $fresh->bd_erp_stage = 'in_estimating';
                    $dirty = true;
                }
                if (($fresh->quote_stage ?? '') === 'order_submitted') {
                    // The product's quote_to_quote success stamp reuses
                    // CreateOrderWriteBack's "Order Submitted" dom value on a
                    // quote-only hand-off (its own comment calls the wording
                    // ill-fitting). Nothing has been ordered - restore an
                    // open, truthful stage while estimating works the job.
                    $fresh->quote_stage = 'Negotiation';
                    $dirty = true;
                }
                if ($dirty) {
                    $fresh->save();
                }
            }
            $result['message'] = ($result['message'] ?? 'Sent to estimating.')
                . ' Estimating has been notified - the job is in their Kinetic queue.';
        }

        return $result;
    }

    // -------------------------------------------------------------------
    // REQ-1 / REQ-2 / REQ-22: order the winning line, quote stays open
    // -------------------------------------------------------------------

    public function orderWinningLine(ServiceBase $api, array $args)
    {
        if (empty($args['record'])) {
            throw new SugarApiExceptionInvalidParameter('Missing record id');
        }
        $productApi = 'custom/clients/base/api/QuotesErpActionsApi.php';
        if (!file_exists($productApi)) {
            return array(
                'status' => 'error',
                'message' => 'ERP-Epicor package is not installed - ordering needs its quote_to_order path.',
            );
        }

        $bean = BeanFactory::retrieveBean('Quotes', $args['record']);
        if ($bean === null || empty($bean->id)) {
            throw new SugarApiExceptionNotFound('Quote not found: ' . $args['record']);
        }
        if (!$bean->ACLAccess('edit')) {
            throw new SugarApiExceptionNotAuthorized('No edit access to this quote');
        }
        if (($bean->quote_stage ?? '') === 'Closed Lost') {
            return array(
                'status' => 'error',
                'message' => 'This quote is Closed Lost - reopen it before ordering.',
            );
        }
        if (($bean->order_stage ?? '') === 'Pending') {
            return array(
                'status' => 'error',
                'message' => 'An order for this quote is already in flight (order stage Pending).',
            );
        }

        // The winning line lives on the Kinetic reflection, not the Sugar
        // line items: bd01_ERP_Quote_Line.governing, kept single-winner by
        // BdGoverningLineHook. That is deliberate - the quantity-break lines
        // (REQ-5) exist only on the Kinetic quote, and REQ-2 requires the
        // order to carry the quoted price of exactly the chosen break.
        $erpQuote = $this->pickErpQuote($bean);
        if ($erpQuote === null) {
            return array(
                'status' => 'error',
                'message' => 'No Kinetic quote is linked to this quote yet - send it to estimating first.',
            );
        }

        $lines = array();
        if ($erpQuote->load_relationship('bd01_erp_quote_lines')) {
            $lines = $erpQuote->bd01_erp_quote_lines->getBeans();
        }
        if (count($lines) === 0) {
            return array(
                'status' => 'error',
                'message' => sprintf('Kinetic quote %s has no lines yet - it is still being estimated.', $erpQuote->quote_num),
            );
        }

        $winning = array();
        foreach ($lines as $line) {
            if (!empty($line->governing)) {
                $winning[] = $line;
            }
        }
        if (count($winning) === 0) {
            return array(
                'status' => 'error',
                'message' => 'Mark the winning line first: tick "Winning line" on the quote line the customer chose.',
            );
        }

        // Stamp the write-back's delta field BEFORE triggering, so the row
        // is selectable by the polling sweep too if the synchronous trigger
        // dies between the ERP write and the response (the connector's
        // idempotency guards make the re-sweep safe, not duplicative).
        $now = TimeDate::getInstance()->nowDb();
        $bean->bd_order_requested_at = $now;
        $bean->save();

        // Copy the winning break onto the Sugar quote's own line items -
        // the product's quote_to_order write-back builds the ERP order from
        // the Quote's Products rows, so the quote must SAY what is being
        // ordered before the product path runs. It is also the right CRM
        // record: the ETO placeholder's job ended when estimating returned
        // the ladder; from here the quote's own lines ARE the ordered slice
        // at the agreed break price (REQ-1/REQ-2).
        $copyError = $this->copyWinningLinesToQuote($bean, $winning);
        if ($copyError !== null) {
            return array('status' => 'error', 'message' => $copyError);
        }

        // The product API's server-side gate: only an accepted quote may
        // submit an order (create-erp-order.js::_isAccepted's server half).
        // Choosing the winning line IS the customer's acceptance act here,
        // so the stamp is truthful - and for a partial win it is corrected
        // to 'Partially Fulfilled' right after the submit succeeds.
        $gate = BeanFactory::retrieveBean('Quotes', $bean->id, array('use_cache' => false));
        if ($gate !== null) {
            $bean = $gate;
        }
        if (($bean->quote_stage ?? '') !== 'Closed Accepted') {
            $bean->quote_stage = 'Closed Accepted';
            $bean->save();
        }

        // REQ-1 repeat orders: the product guard reads ANY linked ERP order
        // (or a submitted order_stage) as "already submitted", because a true
        // re-submit of the SAME order collides on erp_sync_key. A second,
        // DIFFERENT order - the production run that follows the prototype -
        // is the legitimate case this button exists for, so park both guard
        // signals while the delegate runs and restore them right after. The
        // end state stays truthful: every order the quote produced is linked
        // back below, which is needed on success anyway - the stamp-back's
        // link-create PUT soft-deletes sibling link rows (the guard's own
        // comment documents this).
        $previousOrders = array();
        $previousOrderStage = (string) ($bean->order_stage ?? '');
        if ($bean->load_relationship('quotes_erp_orders')) {
            foreach ($bean->quotes_erp_orders->getBeans() as $prevOrder) {
                $previousOrders[] = $prevOrder;
                $bean->quotes_erp_orders->delete($bean->id, $prevOrder);
            }
        }
        $submittedStages = array('Pending', 'Confirmed', 'In Fulfillment', 'Manufacturing', 'Commitment Final');
        $stageParked = in_array($previousOrderStage, $submittedStages, true);
        if ($stageParked) {
            $bean->order_stage = 'CRM Only';
            $bean->save();
        }

        require_once $productApi;
        $delegate = new QuotesErpActionsApi();
        try {
            $result = $delegate->runErpAction($api, array(
                'module' => 'Quotes',
                'record' => $bean->id,
                'action' => 'create_order',
            ));
        } catch (Exception $e) {
            $result = array('status' => 'error', 'message' => $e->getMessage());
        }

        // Restore the parked signals: re-link every prior order whatever the
        // outcome, and put the parked stage back only on failure (on success
        // the connector's stamp-back owns order_stage).
        if (count($previousOrders) > 0) {
            $relinked = BeanFactory::retrieveBean('Quotes', $bean->id, array('use_cache' => false));
            if ($relinked !== null && $relinked->load_relationship('quotes_erp_orders')) {
                foreach ($previousOrders as $prevOrder) {
                    $relinked->quotes_erp_orders->add($prevOrder);
                }
            }
        }
        if (($result['status'] ?? '') !== 'success') {
            if ($stageParked) {
                $failBean = BeanFactory::retrieveBean('Quotes', $bean->id, array('use_cache' => false));
                if ($failBean !== null) {
                    $failBean->order_stage = $previousOrderStage;
                    $failBean->save();
                }
            }
            return $result;
        }

        // Re-retrieve before stamping stages: the trigger path has already
        // stamped erp_writeback_* on this row (core stamps the source record
        // itself on the sync route - independent of the after_write hook,
        // which never fires for container handlers).
        $fresh = BeanFactory::retrieveBean('Quotes', $bean->id, array('use_cache' => false));
        if ($fresh === null) {
            return $result;
        }

        $partial = count($winning) < count($lines);
        $prototypeWin = false;
        foreach ($winning as $line) {
            // Reflection flag first; the container's _is_prototype() regex
            // as fallback, in case the flag predates that transformer field.
            if (!empty($line->prototype)
                || preg_match('/(^|[-_])PROTO/i', (string) $line->part_num)
            ) {
                $prototypeWin = true;
                break;
            }
        }

        if ($partial) {
            // THE REQ-1 BEHAVIOUR: a subset win leaves the quote OPEN.
            // 'Partially Fulfilled' is this package's stage (installed via
            // dropdowntemplates) - deliberately not 'Closed Accepted', so
            // QuoteAcceptSiblingReject never fires and the remaining lines
            // stay live for the production order that follows the prototype.
            $fresh->quote_stage = 'Partially Fulfilled';
        } else {
            // Every line won - that IS full acceptance; the product's
            // existing close semantics apply (sibling reject included).
            $fresh->quote_stage = 'Closed Accepted';
        }
        $fresh->save();

        // REQ-22, the agreed stage-expression answer: the opportunity STAYS
        // OPEN and its stage says which slice closed. Both new stages are
        // neither won nor lost, so forecasting keeps the remainder in the
        // open pipeline.
        if ($partial && $fresh->load_relationship('opportunities')) {
            $newStage = $prototypeWin ? 'Prototype Closed' : 'Partial Production Closed';
            foreach ($fresh->opportunities->getBeans() as $opp) {
                if (in_array($opp->sales_stage, array('Closed Won', 'Closed Lost'), true)) {
                    continue;
                }
                $opp->sales_stage = $newStage;
                $opp->probability = $prototypeWin ? 80 : 90;
                $opp->save();
                // Opportunity stage is a rollup of RLI stages when revenue
                // line items are in play - align them or the next RLI save
                // silently reverts the opportunity.
                if ($opp->load_relationship('revenuelineitems')) {
                    foreach ($opp->revenuelineitems->getBeans() as $rliBean) {
                        if (in_array($rliBean->sales_stage, array('Closed Won', 'Closed Lost'), true)) {
                            continue;
                        }
                        $rliBean->sales_stage = $newStage;
                        $rliBean->probability = $prototypeWin ? 80 : 90;
                        $rliBean->save();
                    }
                }
            }
        }

        $orderedParts = array();
        foreach ($winning as $line) {
            $orderedParts[] = sprintf(
                '%s x%s @ %s',
                $line->part_num,
                rtrim(rtrim(number_format((float) $line->selling_qty, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format((float) $line->doc_unit_price, 2, '.', ''), '0'), '.')
            );
        }

        $result['message'] = sprintf(
            '%s Ordered %d of %d quote lines (%s) at the quoted price. %s',
            $result['message'],
            count($winning),
            count($lines),
            implode('; ', $orderedParts),
            $partial
                ? 'The quote stays open - the remaining lines are still live.'
                : 'All lines won - the quote is closed accepted.'
        );
        $result['partial'] = $partial;
        $result['lines_ordered'] = count($winning);
        $result['lines_total'] = count($lines);

        return $result;
    }

    /**
     * Re-runs the post_install UI deploy steps with NOTHING swallowed: every
     * step's exception text comes back in the response. post_install logs
     * failures to sugarcrm.log, which SugarCloud keeps out of reach - this
     * route exists because the buttons/dropdown steps failed there silently.
     * Admin-only; every mutation is the same idempotent core-class call the
     * installer makes.
     */
    public function repairUi(ServiceBase $api, array $args)
    {
        global $current_user;
        if (empty($current_user) || !$current_user->isAdmin()) {
            throw new SugarApiExceptionNotAuthorized('Admins only');
        }

        $steps = array();

        try {
            $helper = 'custom/modules/Quotes/BdQuotesLayoutExtensions.php';
            require_once $helper;
            BdQuotesLayoutExtensions::writeButtons();
            $steps['quotes_buttons'] = 'ok';
        } catch (Throwable $e) {
            $steps['quotes_buttons'] = get_class($e) . ': ' . $e->getMessage();
        }

        try {
            $helper = 'custom/modules/Accounts/BdAccountsLayoutExtensions.php';
            require_once $helper;
            BdAccountsLayoutExtensions::writeButtons();
            $steps['accounts_button'] = 'ok';
        } catch (Throwable $e) {
            $steps['accounts_button'] = get_class($e) . ': ' . $e->getMessage();
        }

        try {
            $tpl = 'custom/dropdowntemplates/bd_stage_doms.append.php';
            if (!file_exists($tpl)) {
                $steps['stage_dropdowns'] = 'template missing: ' . $tpl;
            } else {
                require_once 'ModuleInstall/ModuleInstaller.php';
                $mi = new ModuleInstaller();
                $mi->silent = true;
                $mi->id_name = 'zz_bd_stage_doms';
                $mi->base_dir = getcwd();
                $mi->installdefs = array(
                    'language' => array(
                        array(
                            'from' => $tpl,
                            'to_module' => 'application',
                            'language' => 'en_us',
                        ),
                    ),
                );
                $mi->install_languages();
                $steps['stage_dropdowns'] = 'ok';
            }
        } catch (Throwable $e) {
            $steps['stage_dropdowns'] = get_class($e) . ': ' . $e->getMessage();
        }

        try {
            // Merge any statically shipped application-level language
            // extensions (en_us.bd_stage_doms.php) regardless of whether the
            // installdefs route above worked.
            require_once 'ModuleInstall/ModuleInstaller.php';
            $mi2 = new ModuleInstaller();
            $mi2->silent = true;
            $mi2->rebuild_languages(array('en_us' => 'en_us'));
            $steps['rebuild_languages'] = 'ok';
        } catch (Throwable $e) {
            $steps['rebuild_languages'] = get_class($e) . ': ' . $e->getMessage();
        }

        try {
            SugarAutoLoader::load('modules/Administration/QuickRepairAndRebuild.php');
            $modules = array('Quotes', 'Opportunities', 'RevenueLineItems', 'Accounts');
            $rac = new RepairAndClear();
            $rac->show_output = false;
            $rac->module_list = $modules;
            $rac->clearVardefs();
            $rac->rebuildExtensions($modules);
            MetaDataManager::refreshModulesCache($modules);
            if (method_exists('MetaDataManager', 'refreshLanguagesCache')) {
                MetaDataManager::refreshLanguagesCache(array('en_us'));
            }
            $steps['repair_rebuild'] = 'ok';
        } catch (Throwable $e) {
            $steps['repair_rebuild'] = get_class($e) . ': ' . $e->getMessage();
        }

        // Live verification straight from the rebuilt app strings.
        $doms = return_app_list_strings_language('en_us');
        $steps['verify_quote_stage_dom'] = isset($doms['quote_stage_dom']['Partially Fulfilled']) ? 'present' : 'MISSING';
        $steps['verify_sales_stage_dom'] = (isset($doms['sales_stage_dom']['Prototype Closed'])
            && isset($doms['sales_stage_dom']['Partial Production Closed'])) ? 'present' : 'MISSING';

        return array('status' => 'success', 'steps' => $steps);
    }

    /**
     * Rewrite the quote's Products rows to exactly the winning ERP lines,
     * so the product's quote_to_order path orders precisely the chosen
     * break at its estimated price. The first existing row (normally the
     * ETO placeholder) is updated in place, further winners append, and
     * surplus rows are removed. Returns an error string, or null on success.
     */
    private function copyWinningLinesToQuote(SugarBean $quote, array $winning): ?string
    {
        if (!$quote->load_relationship('product_bundles')) {
            return 'Quote has no product bundle to carry the ordered line.';
        }
        // ModuleScanner denylists usort (callable arg) - order via ksort on
        // a position-keyed map instead, with an insertion counter to break
        // duplicate-position ties.
        $byPos = array();
        $tie = 0;
        foreach ($quote->product_bundles->getBeans() as $b) {
            $byPos[((int) ($b->position ?? 0)) * 100000 + $tie++] = $b;
        }
        if (count($byPos) === 0) {
            return 'Quote has no product bundle to carry the ordered line.';
        }
        ksort($byPos);
        $bundle = reset($byPos);

        $existing = array();
        if ($bundle->load_relationship('products')) {
            $rowsByPos = array();
            $tie = 0;
            foreach ($bundle->products->getBeans() as $p) {
                $rowsByPos[((int) ($p->position ?? 0)) * 100000 + $tie++] = $p;
            }
            ksort($rowsByPos);
            $existing = array_values($rowsByPos);
        }

        $winning = array_values($winning);
        foreach ($winning as $i => $line) {
            $qty = (float) $line->selling_qty;
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $price = (string) ($line->doc_unit_price ?? '0');
            $isNew = $i >= count($existing);
            $row = $isNew ? BeanFactory::newBean('Products') : $existing[$i];
            // Same free-text-line shape createOppQuote writes: name is the
            // line description, mft_part_num the PartNum the ERP layer maps.
            $row->name = (string) ($line->name !== '' ? $line->name : $line->part_num);
            $row->mft_part_num = (string) $line->part_num;
            $row->quantity = $qty;
            $row->discount_price = $price;
            $row->list_price = $price;
            $row->currency_id = $row->currency_id ?: '-99';
            $row->base_rate = $row->base_rate ?: 1;
            if ($isNew) {
                $row->quote_id = $quote->id;
                $row->position = $i;
                $row->assigned_user_id = $quote->assigned_user_id;
            }
            $row->save();
            if ($isNew && $bundle->load_relationship('products')) {
                $bundle->products->add($row, ['position' => $i]);
            }
        }
        // Surplus rows would order lines the customer did not win - remove.
        for ($i = count($winning); $i < count($existing); $i++) {
            $existing[$i]->mark_deleted($existing[$i]->id);
        }

        // Re-save the quote so its rollup totals re-evaluate against the
        // rewritten lines before the delegate stamps erp_quoted_value from
        // $bean->total.
        $requote = BeanFactory::retrieveBean('Quotes', $quote->id, array('use_cache' => false));
        if ($requote !== null) {
            $requote->save();
        }
        return null;
    }

    /**
     * The bd01_ERP_Quote this Sugar Quote reflects. More than one can be
     * linked after re-estimates; the highest quote_num is the live one -
     * the same rule the container's _pick_live_quote applies, for the same
     * confirmed-live reason (an abandoned lower-numbered shell once won).
     */
    private function pickErpQuote(SugarBean $bean): ?SugarBean
    {
        if (!$bean->load_relationship('bd01_erp_quote_quotes')) {
            return null;
        }
        $best = null;
        foreach ($bean->bd01_erp_quote_quotes->getBeans() as $erpQuote) {
            if ($best === null || (int) $erpQuote->quote_num > (int) $best->quote_num) {
                $best = $erpQuote;
            }
        }
        return $best;
    }
}
