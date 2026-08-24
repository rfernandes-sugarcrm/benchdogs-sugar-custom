<?php

use Sugarcrm\Sugarcrm\Security\HttpClient\ExternalResourceClient;

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
            'bdOrderSelectedLines' => array(
                'reqType' => 'POST',
                'path' => array('Quotes', '?', 'bd-order-selected-lines'),
                'pathVars' => array('module', 'record', ''),
                'method' => 'orderSelectedLines',
                'shortHelp' => 'Raises an Epicor sales order from the line items ticked To Order; untouched lines stay on the quote.',
                'exceptions' => array(
                    'SugarApiExceptionNotAuthorized',
                    'SugarApiExceptionInvalidParameter',
                    'SugarApiExceptionNotFound',
                ),
            ),
            'bdBestPricing' => array(
                'reqType' => 'POST',
                'path' => array('Quotes', '?', 'bd-best-pricing'),
                'pathVars' => array('module', 'record', ''),
                'method' => 'bestPricingFromCatalog',
                'shortHelp' => 'Reprices catalog-linked line items from the live Epicor price lists; non-catalog lines are skipped and reported by name.',
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
        $quote->erp_is_primary_quote = true;
        // REQ-6's RLI materialization is gated on this flag, and nothing else
        // sets it on a quote Sugar raised itself - measured live: two quotes
        // created here reached 'priced' in Kinetic and their opportunities
        // still read $0, because the gate had never opened. We create the
        // opportunity and the quote in the same call, so there is no other
        // candidate to be primary; saying so is a statement of fact, not a
        // guess.
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
                // REQ-13 turnaround, the opening half. Stamped on the ERP
                // QUOTE ROW, not on the Sugar quote: the ERP quote is the
                // unit of work estimating actually picks up, and a
                // re-estimate arrives as a whole new row.
                $this->stampSentToEstimating($fresh);
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
                    $wonRoleSuffix = $prototypeWin ? ':prototype' : ':production';
                    foreach ($opp->revenuelineitems->getBeans() as $rliBean) {
                        if (in_array($rliBean->sales_stage, array('Closed Won', 'Closed Lost'), true)) {
                            continue;
                        }
                        // Deliverable-keyed RLIs (the REQ-6 materialization,
                        // BdQuoteReflectionHook) close INDEPENDENTLY: only
                        // the slice that actually won takes the stage - a
                        // prototype win must not relabel the production RLI
                        // (REQ-1: winning one part never closes the rest).
                        // Unkeyed RLIs keep the pre-0.8.6 blanket behaviour.
                        $deliverableKey = (string) ($rliBean->bd_deliverable_key ?? '');
                        if ($deliverableKey !== ''
                            && substr($deliverableKey, -strlen($wonRoleSuffix)) !== $wonRoleSuffix
                        ) {
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
     * REQ-1/REQ-2/REQ-22 expressed on the quote's OWN line items (1:1 CRM
     * quote to ERP quote): raise an Epicor sales order from ONLY the quoted
     * line items ticked bd_to_order that are not yet bd_ordered. Nothing is
     * copied and nothing is deleted - the connector's quote_to_order handler
     * orders exactly the lines the payload names, so the untouched rows stay
     * on the quote, the quote moves to 'Partially Fulfilled' while lines
     * remain, and every row keeps its history (bd_ordered).
     */
    public function orderSelectedLines(ServiceBase $api, array $args)
    {
        if (empty($args['record'])) {
            throw new SugarApiExceptionInvalidParameter('Missing record id');
        }
        $bean = BeanFactory::retrieveBean('Quotes', $args['record']);
        if ($bean === null || empty($bean->id)) {
            throw new SugarApiExceptionNotFound('Quote not found: ' . $args['record']);
        }
        if (!$bean->ACLAccess('edit')) {
            throw new SugarApiExceptionNotAuthorized('No edit access to this quote');
        }
        if (($bean->quote_stage ?? '') === 'Closed Lost') {
            return array('status' => 'error', 'message' => 'This quote is Closed Lost - reopen it before ordering.');
        }
        if (($bean->order_stage ?? '') === 'Pending') {
            return array('status' => 'error', 'message' => 'An order for this quote is already in flight (order stage Pending).');
        }
        if (empty($bean->erp_display_sync_key)) {
            return array('status' => 'error', 'message' => 'No Kinetic quote is linked to this quote yet - send it to estimating first.');
        }

        $all = array();
        if ($bean->load_relationship('products')) {
            $all = $bean->products->getBeans(array('order_by' => 'position'));
        }
        $rows = array();
        foreach ($all as $p) {
            if (empty($p->deleted)) {
                $rows[] = $p;
            }
        }
        if (count($rows) === 0) {
            return array('status' => 'error', 'message' => 'This quote has no line items yet.');
        }

        $selected = array();
        $unordered = 0;
        foreach ($rows as $p) {
            $isOrdered = !empty($p->bd_ordered);
            if (!$isOrdered) {
                $unordered++;
            }
            if (!empty($p->bd_to_order) && !$isOrdered) {
                $selected[] = $p;
            }
        }
        if (count($selected) === 0) {
            return array('status' => 'error', 'message' => 'Tick "To Order" on the line item(s) the customer committed to, then try again.');
        }

        $cfg = $this->loadOrchestratorConfig();
        if ($cfg['url'] === '' || $cfg['token'] === '' || $cfg['tenant'] === '') {
            return array('status' => 'error', 'message' => 'ERP integration not configured. Contact administrator.');
        }

        // Same pre-step the product's performWriteback runs: a quote against
        // an unprovisioned billing account provisions it first.
        if (!empty($bean->billing_account_id)) {
            $account = BeanFactory::retrieveBean('Accounts', $bean->billing_account_id);
            if ($account && empty($account->erp_sync_key)) {
                $provisionResult = $this->provisionAccount($account, $cfg);
                if (($provisionResult['status'] ?? '') !== 'success') {
                    return $provisionResult;
                }
            }
        }

        // The product getQuoteRecord's shape, with line_items filtered to
        // the ticked rows only - the payload IS the order.
        $lineItems = array();
        foreach ($selected as $p) {
            $lineItems[] = array(
                'id' => $p->id,
                'quote_id' => $p->quote_id,
                'product_id' => $p->product_template_id ?? '',
                'part_num' => ($p->mft_part_num ?? '') !== '' ? $p->mft_part_num : ($p->name ?? ''),
                'name' => $p->name ?? '',
                'description' => ($p->description ?? '') !== '' ? $p->description : ($p->name ?? ''),
                'quantity' => $p->quantity ?? 0,
                'unit_of_measure' => $p->unit_of_measure ?? '',
                'list_price' => $p->list_price ?? '0',
                'discount_amount' => $p->discount_amount ?? '',
                'discount_select' => (bool) ($p->discount_select ?? true),
                'tax_class' => $p->tax_class ?? '',
            );
        }
        $record = array(
            'id' => $bean->id,
            'billing_account_id' => $bean->billing_account_id ?? '',
            'purchase_order_num' => $bean->purchase_order_num ?? '',
            'quote_num' => $bean->quote_num ?? '',
            'date_quote_expected_closed' => $bean->date_quote_expected_closed ?? '',
            'currency_id' => $bean->currency_id ?? '',
            'erp_terms_code' => $bean->erp_terms_code ?? '',
            'erp_ship_via_code' => $bean->erp_ship_via_code ?? '',
            'line_count' => count($lineItems),
            'line_items' => $lineItems,
        );

        // The connector resolves the Epicor customer from
        // account_erp_sync_key (the raw CustNum, erp_display_sync_key) -
        // same resolution the product's getQuoteRecord performs.
        if (!empty($bean->billing_account_id)) {
            $billingAccount = BeanFactory::retrieveBean('Accounts', $bean->billing_account_id, array('use_cache' => false));
            if ($billingAccount) {
                $record['account_erp_sync_key'] = $billingAccount->erp_display_sync_key ?? '';
            }
        }
        if (!empty($bean->shipping_address_id)) {
            $shippingAddress = BeanFactory::retrieveBean('ShippingAddresses', $bean->shipping_address_id);
            if ($shippingAddress) {
                $record['shipping_address_erp_sync_key'] = $shippingAddress->erp_display_sync_key ?? '';
            }
        }

        // Sweep parity with orderWinningLine: stamp BEFORE triggering so the
        // polling sweep can pick the row up if the synchronous trigger dies.
        $now = TimeDate::getInstance()->nowDb();
        $bean->bd_order_requested_at = $now;
        $bean->save();

        $payload = array('tenant' => $cfg['tenant'], 'record' => $record);
        $result = $this->postWritebackSync(
            'quote_to_order',
            $payload,
            $cfg['url'],
            $cfg['token'],
            'create_order',
            'Quote write-back completed.'
        );
        if (($result['status'] ?? '') !== 'success') {
            return $result;
        }

        // Mark the ordered rows - they STAY on the quote as history.
        $prototypeWin = false;
        $orderedParts = array();
        foreach ($selected as $p) {
            $p->bd_ordered = 1;
            $p->save();
            if (preg_match('/(^|[-_])PROTO/i', (string) ($p->mft_part_num ?? ''))) {
                $prototypeWin = true;
            }
            $orderedParts[] = sprintf(
                '%s x%s @ %s',
                ($p->mft_part_num ?? '') !== '' ? $p->mft_part_num : $p->name,
                rtrim(rtrim(number_format((float) ($p->quantity ?? 0), 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format((float) ($p->list_price ?? 0), 2, '.', ''), '0'), '.')
            );
        }

        // Best-effort mirror onto the Kinetic reflection when exactly one
        // line was ordered: keeps the bd01 subpanel's governing tick
        // truthful without fighting BdGoverningLineHook's single winner.
        if (count($selected) === 1 && !empty($selected[0]->bd_erp_line_num)) {
            $erpQuote = $this->pickErpQuote($bean);
            if ($erpQuote !== null && $erpQuote->load_relationship('bd01_erp_quote_lines')) {
                foreach ($erpQuote->bd01_erp_quote_lines->getBeans() as $line) {
                    if ((int) $line->line_num === (int) $selected[0]->bd_erp_line_num
                        && empty($line->governing)
                    ) {
                        $line->governing = 1;
                        $line->save();
                    }
                }
            }
        }

        $fresh = BeanFactory::retrieveBean('Quotes', $bean->id, array('use_cache' => false));
        if ($fresh === null) {
            return $result;
        }

        $partial = ($unordered - count($selected)) > 0;
        if ($partial) {
            // THE REQ-1 BEHAVIOUR on native lines: a subset order leaves the
            // quote OPEN with the remaining line items still live.
            $fresh->quote_stage = 'Partially Fulfilled';
        } else {
            $fresh->quote_stage = 'Closed Accepted';
        }
        $fresh->save();

        // REQ-22: the opportunity stays open and its stage names the slice.
        if ($partial && $fresh->load_relationship('opportunities')) {
            $newStage = $prototypeWin ? 'Prototype Closed' : 'Partial Production Closed';
            foreach ($fresh->opportunities->getBeans() as $opp) {
                if (in_array($opp->sales_stage, array('Closed Won', 'Closed Lost'), true)) {
                    continue;
                }
                $opp->sales_stage = $newStage;
                $opp->probability = $prototypeWin ? 80 : 90;
                $opp->save();
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

        $result['message'] = sprintf(
            '%s Ordered %d of %d open quote lines (%s) at the quoted price. %s',
            $result['message'],
            count($selected),
            $unordered,
            implode('; ', $orderedParts),
            $partial
                ? 'The quote stays open - the remaining line items are still live.'
                : 'All open lines ordered - the quote is closed accepted.'
        );
        $result['partial'] = $partial;
        $result['lines_ordered'] = count($selected);
        $result['lines_total'] = $unordered;

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
                require_once 'custom/modules/Quotes/BdQliColumnsLayout.php';
                (new BdQliColumnsLayout())->install();
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
    /**
     * "Get Best Pricing from Catalog" (REQ-5's live-pricing half).
     *
     * The catalog boundary is Sugar's Product Catalog: a line item counts as
     * IN the catalog only when it is linked to a ProductTemplate that
     * carries an ERP part key. Everything else - engineered/free-text lines
     * like the Bench Dogs prototypes - is deliberately SKIPPED and reported
     * back by name, never errored: custom work has no list price and
     * repricing it from a price list would be wrong by construction.
     *
     * Catalog lines go to the product's own orchestrator route
     * (/v1/quotes/{id}/refresh-price-availability -> Epicor
     * GetPriceListInquiry per customer+part+qty), so quantity breaks and
     * customer price lists resolve exactly as Epicor's own order entry
     * would. Lines that come back degraded, or with no price on file
     * (net_price <= 0 means "no list entry", not "free"), keep their
     * current price and are reported in their own bucket.
     */
    public function bestPricingFromCatalog(ServiceBase $api, array $args)
    {
        if (empty($args['record'])) {
            throw new SugarApiExceptionInvalidParameter('Missing record id');
        }
        $bean = BeanFactory::retrieveBean('Quotes', $args['record']);
        if ($bean === null || empty($bean->id)) {
            throw new SugarApiExceptionNotFound('Quote not found: ' . $args['record']);
        }
        if (!$bean->ACLAccess('edit')) {
            throw new SugarApiExceptionNotAuthorized('No edit access to this quote');
        }

        if (in_array($bean->quote_stage, array('Closed Lost', 'Closed Accepted'), true)) {
            return array('status' => 'error', 'message' => 'This quote is closed - pricing is locked.');
        }

        $bean->load_relationship('products');
        $lineItems = ($bean->products && is_object($bean->products))
            ? $bean->products->getBeans(array('order_by' => 'line_num', 'limit' => 100))
            : array();
        if (empty($lineItems)) {
            return array('status' => 'error', 'message' => 'This quote has no line items to price.');
        }

        $candidates = array();      // id => bean, catalog-linked
        $payloadLines = array();
        $skippedNames = array();    // not in catalog
        foreach ($lineItems as $li) {
            $partNum = '';
            if (!empty($li->product_template_id)) {
                $tpl = BeanFactory::retrieveBean('ProductTemplates', $li->product_template_id);
                if ($tpl) {
                    $partNum = $tpl->erp_display_sync_key ?: '';
                    if ($partNum === '' && !empty($tpl->erp_sync_key)) {
                        // scoped key "EPIC06__BD-DISPLAY-01" -> raw part num
                        $parts = explode('__', (string) $tpl->erp_sync_key, 2);
                        $partNum = end($parts) ?: '';
                    }
                }
            }
            if ($partNum === '') {
                $skippedNames[] = $li->mft_part_num ?: ($li->name ?: 'Unnamed line');
                continue;
            }
            $candidates[$li->id] = array('bean' => $li, 'part' => $partNum);
            $payloadLines[] = array(
                'id' => $li->id,
                'part_num' => $partNum,
                'quantity' => (float) ($li->quantity ?? 0),
                'unit_of_measure' => 'EA',
            );
        }

        $skippedLabel = $skippedNames
            ? ' Skipped (not in catalog): ' . implode(', ', array_unique($skippedNames)) . '.'
            : '';

        if (empty($candidates)) {
            return array(
                'status' => 'success',
                'message' => 'No catalog-linked line items on this quote - nothing was repriced.' . $skippedLabel,
                'lines_repriced' => 0,
            );
        }

        $custNum = 0;
        $custId = '';
        $currency = 'USD';
        if (!empty($bean->billing_account_id)) {
            $account = BeanFactory::retrieveBean('Accounts', $bean->billing_account_id);
            if ($account) {
                $custNum = (int) ($account->erp_display_sync_key ?? 0);
                $custId = (string) ($account->erp_account_id ?? '');
                $currency = $this->resolveCurrencyIsoCode($account->erp_currency_id ?? null);
            }
        }
        if ($custNum <= 0) {
            return array(
                'status' => 'error',
                'message' => 'The billing account is not linked to an ERP customer yet - provision it first.',
            );
        }

        $cfg = $this->loadOrchestratorConfig();
        if ($cfg['url'] === '' || $cfg['token'] === '') {
            return array('status' => 'error', 'message' => 'ERP integration is not configured.');
        }

        $payload = json_encode(array(
            'cust_num' => $custNum,
            'cust_id' => $custId,
            'currency_code' => $currency,
            'line_count' => count($payloadLines),
            'line_items' => $payloadLines,
        ));
        $url = rtrim($cfg['url'], '/') . '/v1/quotes/' . $bean->id . '/refresh-price-availability';

        try {
            $client = new ExternalResourceClient(120);
            $client = $client->trustTo('host.docker.internal');
            $response = $client->post($url, $payload, array(
                'Authorization' => 'Bearer ' . $cfg['token'],
                'Content-Type' => 'application/json',
            ));
            $statusCode = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true) ?? array();
            if ($statusCode < 200 || $statusCode >= 300) {
                $err = $body['error'] ?? ('ERP pricing service returned HTTP ' . $statusCode);
                return array('status' => 'error', 'message' => $err);
            }
        } catch (Throwable $e) {
            return array('status' => 'error', 'message' => 'Could not reach the ERP pricing service: ' . $e->getMessage());
        }

        $repriced = array();
        $noPriceNames = array();
        foreach (($body['lines'] ?? array()) as $lineResult) {
            $entry = $candidates[$lineResult['line_id'] ?? ''] ?? null;
            if ($entry === null) {
                continue;
            }
            $li = $entry['bean'];
            $netPrice = (float) (($lineResult['price'] ?? array())['net_price'] ?? 0);
            if (!empty($lineResult['degraded']) || $netPrice <= 0) {
                $noPriceNames[] = $entry['part'];
                continue;
            }
            $old = (float) ($li->discount_price ?? 0);
            $li->discount_price = $netPrice;
            $availability = $lineResult['availability'] ?? array();
            $availableQty = 0.0;
            foreach ($availability as $wh) {
                $availableQty += (float) ($wh['available_qty'] ?? 0);
            }
            $li->erp_available_qty = $availableQty;
            $li->erp_price_availability_synced_at = TimeDate::getInstance()->nowDb();
            $li->save();
            $repriced[] = sprintf(
                '%s x%d ($%s -> $%s)',
                $entry['part'],
                (int) ($li->quantity ?? 0),
                number_format($old, 2),
                number_format($netPrice, 2)
            );
        }

        $msgParts = array();
        if ($repriced) {
            $msgParts[] = 'Best catalog pricing applied to ' . count($repriced) . ' line(s): ' . implode('; ', $repriced) . '.';
        } else {
            $msgParts[] = 'No lines were repriced.';
        }
        if ($noPriceNames) {
            $msgParts[] = 'No catalog price on file (left unchanged): ' . implode(', ', array_unique($noPriceNames)) . '.';
        }
        if ($skippedLabel !== '') {
            $msgParts[] = trim($skippedLabel);
        }

        return array(
            'status' => 'success',
            'message' => implode(' ', $msgParts),
            'lines_repriced' => count($repriced),
            'lines_skipped_not_catalog' => count($skippedNames),
            'lines_no_price' => count($noPriceNames),
        );
    }

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
     * Start the REQ-13 turnaround clock on the ERP quote row this send just
     * raised in Kinetic.
     *
     * The mirror row usually does not exist yet - the quote is seconds old
     * in Kinetic and the connector has not swept since - so this
     * resolve-or-CREATES it, keyed on the sync key the connector will use
     * ('<COMPANY>__<QuoteNum>', verified live: EPIC06__1196). Creating it
     * with that exact key means the next sync UPSERTS onto this row and
     * fills in the rest; it is not a second record racing the real one. The
     * company prefix is read off an existing mirror rather than hard-coded,
     * and if no mirror exists to read it from, this does nothing rather than
     * guess a key that would fork the record.
     *
     * bd_sent_to_estimating_at is in no container payload, so the sync that
     * arrives moments later cannot blank it.
     */
    private function stampSentToEstimating(SugarBean $quote): void
    {
        try {
            $quoteNum = trim((string) ($quote->erp_display_sync_key ?? ''));
            if ($quoteNum === '' || !ctype_digit($quoteNum)) {
                return;   // the delegate did not come back with a Kinetic number
            }

            $erpQuote = $this->findErpQuoteByNumber((int) $quoteNum);
            if ($erpQuote === null) {
                $prefix = $this->erpSyncKeyPrefix();
                if ($prefix === '') {
                    $GLOBALS['log']->warn(
                        'BdBenchDogsActionsApi: no existing bd01_ERP_Quote to read the sync-key '
                        . 'prefix from - not creating a mirror row for Kinetic quote ' . $quoteNum
                    );
                    return;
                }
                $erpQuote = BeanFactory::newBean('bd01_ERP_Quote');
                $erpQuote->name = 'Quote ' . $quoteNum;
                $erpQuote->quote_num = (int) $quoteNum;
                $erpQuote->erp_sync_key = $prefix . '__' . $quoteNum;
                $erpQuote->sugar_quote_id = $quote->id;
            }

            if (!empty($erpQuote->bd_sent_to_estimating_at)) {
                return;   // already clocked - a re-send must not restart it
            }
            $erpQuote->bd_sent_to_estimating_at = TimeDate::getInstance()->nowDb();
            $erpQuote->save();

            $GLOBALS['log']->info(
                'BdBenchDogsActionsApi: bd_sent_to_estimating_at stamped on bd01_ERP_Quote '
                . $erpQuote->id . ' for Kinetic quote ' . $quoteNum
            );
        } catch (Throwable $e) {
            // A missing KPI stamp must never fail the hand-off itself.
            $GLOBALS['log']->error(
                'BdBenchDogsActionsApi: could not stamp bd_sent_to_estimating_at: ' . $e->getMessage()
            );
        }
    }

    /** The bd01_ERP_Quote mirror for a Kinetic quote number, or null. */
    private function findErpQuoteByNumber(int $quoteNum): ?SugarBean
    {
        $query = new SugarQuery();
        $query->select(['id']);
        $query->from(BeanFactory::newBean('bd01_ERP_Quote'));
        $query->where()->equals('quote_num', $quoteNum);
        $query->limit(1);
        $rows = $query->execute();
        $id = $rows[0]['id'] ?? '';
        if ($id === '') {
            return null;
        }
        $bean = BeanFactory::retrieveBean('bd01_ERP_Quote', $id);
        return ($bean && !empty($bean->id)) ? $bean : null;
    }

    /**
     * The company prefix the connector puts in front of every ERP sync key,
     * read from a mirror row that already has one ('EPIC06__1196' ->
     * 'EPIC06'). Empty when there is nothing to read it from.
     */
    private function erpSyncKeyPrefix(): string
    {
        $query = new SugarQuery();
        $query->select(['erp_sync_key']);
        $query->from(BeanFactory::newBean('bd01_ERP_Quote'));
        $query->where()->notEquals('erp_sync_key', '');
        $query->orderBy('quote_num', 'DESC');
        $query->limit(1);
        $rows = $query->execute();
        $key = (string) ($rows[0]['erp_sync_key'] ?? '');
        $pos = strpos($key, '__');
        return $pos === false ? '' : substr($key, 0, $pos);
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
