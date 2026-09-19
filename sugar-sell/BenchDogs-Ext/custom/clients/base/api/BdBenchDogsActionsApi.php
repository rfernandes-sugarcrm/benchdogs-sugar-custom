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
 *     typed advanced_quote, so estimating carries the deal from here.
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
 *     too, its stage advanced to 'Prototype Ordered' / 'Partial Production
 *     Closed' - the exact stage-expression answer REQ-22's discussion
 *     records as the agreed direction.
 *
 * Extends ERP-Epicor's BaseErpActionsApi for the orchestrator plumbing
 * (loadOrchestratorConfig/postWritebackSync) - a hard dependency, exactly as
 * the product's own QuotesErpActionsApi requires it.
 */

// This class extends ERP-Epicor's BaseErpActionsApi, so it cannot be defined
// at all unless that package is still installed - a require_once on a
// missing file is a fatal compile error, not a \Throwable, so the
// file_exists guard has to come first (same convention as
// ERP-Epicor-PartialFulfillment's PartialFulfillmentQuotesApi.php).
// ServiceDictionary::buildAllDictionaries() require_once's every file under
// custom/clients/*/api/*.php on EVERY REST call before it ever checks
// class_exists() - so without this guard, uninstalling ERP-Epicor takes down
// the entire REST API (every endpoint, not just this one), which is exactly
// what happened live on benchdogs-dev when Epicor Integration was
// uninstalled before Bench Dogs Extensions.
$parentApiFile = 'custom/clients/base/api/BaseErpActionsApi.php';
if (file_exists($parentApiFile)) {
    require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($parentApiFile);

    class BdBenchDogsActionsApi extends BaseErpActionsApi
    {
        /**
         * The write-back entity registered by the Bench Dogs extension container
         * (connector_ext_benchdogs.writeback.quotes.OrderFromQuoteWriteBack).
         * Its transform orders ONLY the governing quote lines -
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
            );
        }

        // -------------------------------------------------------------------
        // REQ-20: account-level Create Opportunity & Quote
        // -------------------------------------------------------------------

        /**
         * Bean sequence creates one Opportunity, one Quote, its default
         * ProductBundle and a native Products line, re-sourced from the Account
         * record and carrying a free-text line instead of a catalog part.
         * Everything is created at amount 0 - the honest number before
         * estimating has priced anything (REQ-6's roll-up direction is ERP ->
         * Sugar, so seeding fake values here would fight the sync).
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
            // Same gate as ERP-Epicor's AccountsErpActionsApi::createOppQuote:
            // this action writes an Opportunity, a Quote, its bundle and a line
            // for the account, so viewing the account is not enough. Both checks
            // run before any bean is saved, so a refused caller creates nothing.
            if (!$account->ACLAccess('edit')) {
                throw new SugarApiExceptionNotAuthorized('No edit access to this account');
            }
            if (!BeanFactory::newBean('Opportunities')->ACLAccess('save')
                || !BeanFactory::newBean('Quotes')->ACLAccess('save')) {
                throw new SugarApiExceptionNotAuthorized('No access to create opportunities and quotes');
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
            // Shared ERP-Core's single Opportunity amount writer is gated on
            // this flag, and nothing else sets it on a quote Sugar raised
            // itself - measured live: two quotes
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
         * Delegate creation of the Kinetic quote to ERP-Epicor's public owner.
         *
         * Bench Dogs owns only the customer stage and turnaround timestamp.
         * Reimplementing quote_to_quote here would bypass the shared owner's
         * validation, transport, ordinary re-click guard, status stamps and
         * future repairs. Durable concurrent/lost-response idempotency is
         * still an open shared-layer gap. The quote is re-read after the
         * shared call because that call can update it while this request is in
         * flight; saving the pre-call bean would overwrite those shared fields
         * on this Sugar version.
         */
        public function sendToEstimating(ServiceBase $api, array $args): array
        {
            $sharedApiFile = 'custom/clients/base/api/QuotesErpActionsApi.php';
            if (!file_exists($sharedApiFile)) {
                throw new SugarApiExceptionNotFound('ERP-Epicor quote actions are not installed');
            }
            require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($sharedApiFile);

            // The shared action deliberately returns success for a normal
            // re-click once erp_display_sync_key exists. Remember that state
            // before delegating: a priced/revision/ordered ERP quote must never
            // be pushed backwards into estimating by this customer wrapper.
            $recordId = (string) ($args['record'] ?? '');
            $before = $recordId === '' ? null : BeanFactory::retrieveBean(
                'Quotes',
                $recordId,
                array('use_cache' => false)
            );
            $erpQuoteAlreadyExisted = $before !== null
                && $before !== false
                && !empty($before->id)
                && trim((string) ($before->erp_display_sync_key ?? '')) !== '';

            $sharedArgs = $args;
            $sharedArgs['action'] = 'advanced_quote';
            $result = (new QuotesErpActionsApi())->runErpAction($api, $sharedArgs);

            if (($result['status'] ?? '') !== 'success' || $erpQuoteAlreadyExisted) {
                return $result;
            }

            $quote = BeanFactory::retrieveBean('Quotes', $recordId, array('use_cache' => false));
            if ($quote === null || $quote === false || empty($quote->id)) {
                return $this->estimatingStageFailure($result);
            }

            $needsSave = false;
            if (($quote->bd_erp_stage ?? '') !== 'in_estimating') {
                $quote->bd_erp_stage = 'in_estimating';
                $needsSave = true;
            }
            if ($needsSave) {
                try {
                    if (!$quote->save()) {
                        return $this->estimatingStageFailure($result);
                    }
                } catch (Throwable $e) {
                    $GLOBALS['log']->error(
                        'BdBenchDogsActionsApi: Kinetic quote created but in_estimating save failed'
                    );
                    return $this->estimatingStageFailure($result);
                }
            }

            // save() returning an id is not persistence evidence. Re-read once
            // more and refuse a success response unless the customer stage is
            // actually visible outside the bean that performed the save.
            $persisted = BeanFactory::retrieveBean('Quotes', $recordId, array('use_cache' => false));
            if ($persisted === null
                || $persisted === false
                || empty($persisted->id)
                || ($persisted->bd_erp_stage ?? '') !== 'in_estimating'
            ) {
                return $this->estimatingStageFailure($result);
            }
            // The Kinetic write and persisted Bench stage are the primary
            // hand-off. The after_save hook's in-app Notification is a
            // secondary delivery: report it independently and never turn its
            // failure into an unsafe invitation to create the ERP quote again.
            $result['erp_handoff_status'] = 'completed';
            $notificationOutcome = array(
                'status' => 'not_observed',
                'message' => 'The Kinetic hand-off completed, but Sugar did not report a '
                    . 'notification attempt. Use the In Estimating view and ask an '
                    . 'administrator to verify the notification hook.',
            );
            if (class_exists('BdEstimatingNotificationHook', false)) {
                $notificationOutcome = BdEstimatingNotificationHook::consumeEstimatingOutcome(
                    $recordId
                );
            }
            $result['notification_status'] = (string) (
                $notificationOutcome['status'] ?? 'not_observed'
            );
            $result['notification_message'] = (string) (
                $notificationOutcome['message'] ?? ''
            );

            return $result;
        }

        /** Preserve the ERP identity while refusing a misleading hand-off success. */
        private function estimatingStageFailure(array $result): array
        {
            $result['status'] = 'error';
            $result['partial_success'] = true;
            $result['retry_safe'] = false;
            $result['message'] = 'The Kinetic quote was created, but Sugar could not confirm the '
                . 'In Estimating stage. Refresh this Quote and contact an administrator if the '
                . 'stage is still missing. Do not retry from another tab until its ERP quote '
                . 'number has been checked.';
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
                // Bench Dogs is an Opportunities-only deployment. Repair the
                // Quote/Opportunity surfaces it owns without compiling a
                // disabled sales model.
                $modules = array('Quotes', 'Opportunities', 'Accounts');
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
            $steps['verify_sales_stage_dom'] = (isset($doms['sales_stage_dom']['Prototype Ordered'])
                && isset($doms['sales_stage_dom']['Partial Production Ordered'])) ? 'present' : 'MISSING';

            return array('status' => 'success', 'steps' => $steps);
        }
    }
}
