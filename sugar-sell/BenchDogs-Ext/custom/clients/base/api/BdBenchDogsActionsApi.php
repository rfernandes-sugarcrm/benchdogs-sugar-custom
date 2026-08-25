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
            'bdSyncQuoteTiers' => array(
                'reqType' => 'POST',
                'path' => array('Quotes', '?', 'bd-sync-quote-tiers'),
                'pathVars' => array('module', 'record', ''),
                'method' => 'syncQuoteTiers',
                'shortHelp' => 'Adds a line item for every Kinetic quantity break this quote is missing, and marks the breaks Kinetic has already ordered. Never edits or removes an existing line.',
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
                'shortHelp' => 'Reprices OPEN catalog-linked line items from the live Epicor price lists; already-ordered lines are left untouched, and non-catalog lines are skipped - both reported by name.',
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
            // The Bench Dogs ERP panel too (append-only: restored when a
            // later ERP-Epicor layout pass dropped it, never rewritten when
            // it is there) - an install whose post_execute did not run, or a
            // core upgrade in between, leaves the record view without it.
            BdQuotesLayoutExtensions::write(false);
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
            $helper = 'custom/modules/Accounts/BdAccountsLayoutExtensions.php';
            require_once $helper;
            BdAccountsLayoutExtensions::writeCustomerGroupField();
            $steps['accounts_group_field'] = 'ok';
        } catch (Throwable $e) {
            $steps['accounts_group_field'] = get_class($e) . ': ' . $e->getMessage();
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
     * The Epicor part number a Product Catalog record stands for, or ''.
     * erp_display_sync_key is the raw part num; erp_sync_key is the scoped
     * form ("EPIC06__BD-DISPLAY-01") and is unscoped here.
     */
    private function erpPartNumFromTemplate(SugarBean $tpl): string
    {
        $partNum = $tpl->erp_display_sync_key ?: '';
        if ($partNum === '' && !empty($tpl->erp_sync_key)) {
            $parts = explode('__', (string) $tpl->erp_sync_key, 2);
            $partNum = end($parts) ?: '';
        }
        return (string) $partNum;
    }

    /**
     * The catalog record for a part number typed onto an unlinked line.
     * Matched on the catalog's OWN part number field, so a typo does not
     * silently reprice a line from some other part: no match means the line
     * is reported as not-in-catalog exactly as before.
     */
    private function findTemplateByPartNum(string $partNum): ?SugarBean
    {
        $partNum = trim($partNum);
        if ($partNum === '') {
            return null;
        }
        $query = new SugarQuery();
        $seed = BeanFactory::newBean('ProductTemplates');
        $query->from($seed);
        $query->select(array('id'));
        $query->where()->equals('mft_part_num', $partNum);
        $query->limit(1);
        $rows = $query->execute();
        foreach ($rows as $row) {
            if (!empty($row['id'])) {
                return BeanFactory::retrieveBean('ProductTemplates', $row['id']);
            }
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

    /**
     * Bring a quote's line items into line with the Kinetic quote behind it.
     *
     * Two things, both additive:
     *
     *   1. a line item for every Kinetic quantity break that has none, so
     *      each break can be ordered, valued and reported separately;
     *   2. erp_ordered on any break Kinetic has ALREADY ordered, read off the
     *      sales orders already synced against this quote.
     *
     * Nothing is edited and nothing is removed. A rep who deleted a break
     * they are not quoting will get it back if they run this, which is why it
     * is an action they invoke rather than something a sync does behind them.
     *
     * This is the repair path for a quote that predates per-line ordering, or
     * that was worked in Kinetic first - the case the tiered model cannot
     * otherwise see, because it reads everything off the Sugar line items.
     */
    public function syncQuoteTiers(ServiceBase $api, array $args)
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

        $erpQuote = $this->pickErpQuote($bean);
        if ($erpQuote === null) {
            return array(
                'status' => 'error',
                'message' => 'This quote is not linked to a Kinetic quote, so there are no quantity breaks to sync.',
            );
        }

        require_once 'custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php';
        $hook = new BdQuoteReflectionHook();

        $created = $hook->backfillMissingQuoteLines($erpQuote, $bean);

        // Re-value through the ordinary reflection path, which also runs the
        // ordered reconciliation - so the opportunity, the RLIs and the
        // ordered flags all land from one code path rather than this action
        // inventing its own arithmetic.
        $hook->refreshOpportunityAmount($erpQuote);

        $ordered = 0;
        $fresh = BeanFactory::retrieveBean('Quotes', $bean->id, array('use_cache' => false));
        if ($fresh !== null && $fresh->load_relationship('products')) {
            foreach ($fresh->products->getBeans() as $product) {
                if (!empty($product->erp_ordered)) {
                    $ordered++;
                }
            }
        }

        return array(
            'status' => 'success',
            'lines_added' => $created,
            'lines_ordered' => $ordered,
            'message' => $created > 0
                ? sprintf(
                    'Added %d line item%s from Kinetic quote %s. %d line%s now marked ordered.',
                    $created,
                    $created === 1 ? '' : 's',
                    (string) ($erpQuote->name ?? ''),
                    $ordered,
                    $ordered === 1 ? '' : 's'
                )
                : sprintf(
                    'Every Kinetic quantity break already has a line item. %d line%s marked ordered.',
                    $ordered,
                    $ordered === 1 ? '' : 's'
                ),
        );
    }
}
