<?php

require_once 'custom/clients/base/api/BaseErpActionsApi.php';

/**
 * The Accounts header-button actions.
 *
 *   POST Accounts/:record/erp-action              "Create Customer in Epicor":
 *     provisions the Account in Epicor (account_provision, dedupe-or-create)
 *     and stamps erp_writeback_status/erp_writeback_msg/erp_writeback_at on
 *     the account based on the orchestrator's response. Reuses
 *     BaseErpActionsApi's provisionAccount() - the same call
 *     QuotesErpActionsApi already makes as a side effect when a Quote is
 *     submitted against an unprovisioned billing Account; this class just
 *     exposes it as its own standalone action.
 *
 *   POST Accounts/:record/erp-create-opp-quote    "Create Opportunity & Quote":
 *     raises a linked Opportunity + Quote on the account in one act, the
 *     quote born primary and typed Sales Order by default (G392; a tenant's
 *     opp_quote_type overrides it, and the seller can switch the quote to
 *     Advanced Quote for estimation). Entirely Sugar-side - no orchestrator
 *     call - see createOppQuote().
 */
class AccountsErpActionsApi extends BaseErpActionsApi
{
    private const SUPPORTED_ACTIONS = ['create_customer'];

    /**
     * G495 (🔒1759b) - what a seller reads when "Create Customer in Epicor"
     * reaches an account Epicor already holds. The same sentence ships as
     * LBL_CREATE_ERP_ACCOUNT_ALREADY_IN_ERP for the browser's own refusal.
     */
    public const ALREADY_IN_ERP_MESSAGE = 'This account is already in Epicor, so it cannot be created there again. '
        . 'To make it a Customer, set Type to Customer and save: the change is sent to Epicor.';

    /**
     * Administration config (category erp_integration) keys read by
     * createOppQuote(). Sugar's config.name column is 32 chars wide - keep
     * every key under it. Nothing customer-specific is hardcoded below: each
     * default is the product's own neutral behaviour, and a tenant overrides
     * any of them through PUT ERPIntegration/config (ErpIntegration_Api).
     */
    public const CONFIG_CATEGORY = 'erp_integration';
    public const CFG_NAME_TEMPLATE = 'opp_quote_name_template';
    public const CFG_CLOSE_DAYS = 'opp_quote_close_days';
    public const CFG_SALES_STAGE = 'opp_quote_sales_stage';
    public const CFG_PROBABILITY = 'opp_quote_probability';
    public const CFG_QUOTE_STAGE = 'opp_quote_quote_stage';
    public const CFG_QUOTE_TYPE = 'opp_quote_type';
    public const CFG_PLACEHOLDER_ENABLED = 'opp_quote_placeholder_enabled';
    public const CFG_PLACEHOLDER_NAME = 'opp_quote_placeholder_name';
    public const CFG_PLACEHOLDER_PART = 'opp_quote_placeholder_part';

    private const DEFAULT_NAME_TEMPLATE = '{account} - {date}';
    private const DEFAULT_CLOSE_DAYS = 30;
    private const DEFAULT_QUOTE_STAGE = 'Draft';
    // G392 (owner, 2026-09-23): *"create a quote and opperunity shoudl create
    // a quote in sales order type not advanced"*. Was 'advanced_quote' through
    // 1.1.123. The same value erp_quote_type's own vardef defaults a native
    // Create to, so this button and Sugar's own Create now agree. Still only a
    // DEFAULT: an explicit erp_integration.opp_quote_type wins (configuredDomKey
    // below), and the seller can switch a quote to Advanced Quote to send it
    // for estimation. Pinned by tests/CreateOppQuoteIsASalesOrderByDefaultTest.php.
    private const DEFAULT_QUOTE_TYPE = 'sales_order';
    private const DEFAULT_PLACEHOLDER_NAME = 'Engineered to order — scope to be defined in estimating';
    // The free-text PartNum the placeholder line carries to Epicor. Advanced
    // Quote sends a free-text line's mft_part_num as PartNum
    // (QuotesErpActionsApi::getQuoteRecord), and Epicor refuses a quote line
    // with none ("Part is required.") - so the placeholder needs one even
    // though no catalog part exists yet. Estimating replaces it with the real
    // engineered lines.
    private const DEFAULT_PLACEHOLDER_PART = 'ETO-PENDING';
    // Sugar's own stock first stage - only reached when sales_stage_dom
    // cannot be read at all; the real default is the dom's first key.
    private const FALLBACK_SALES_STAGE = 'Prospecting';

    /**
     * The five address columns CORE-ShippingAddresses' populate_list copies
     * from the picked ShippingAddress onto the quote. Same names on both
     * modules, which is why one list serves as source and target.
     */
    private const SHIP_TO_ADDRESS_FIELDS = array(
        'shipping_address_street',
        'shipping_address_city',
        'shipping_address_state',
        'shipping_address_postalcode',
        'shipping_address_country',
    );

    /**
     * G444 (a) — the two ERP lookups billing_account_name's populate_list copies
     * from the account onto the quote besides the company (G429):
     * account relate id => [quote relate id, quote relate name]. The same pairs
     * as billing_account_name_populate_erp_lookups.php, so the server-side
     * create and the browser autofill fill the same fields.
     */
    private const ACCOUNT_LOOKUPS_ON_QUOTE = array(
        'erp_billing_termserp_lookupvalues_idb' => array(
            'erp_quotes_billing_termserp_lookupvalues_idb',
            'erp_quotes_billing_terms_name',
        ),
        'erp_foberp_lookupvalues_idb' => array(
            'erp_quotes_foberp_lookupvalues_idb',
            'erp_quotes_fob_name',
        ),
    );

    /**
     * G275 — the address parts stock Sugar copies from an Account onto a
     * Quote's billing address (SugarEnt 26.1.0 modules/Quotes/QuotesApiHelper
     * .php:130, processBeanAddressFields()).
     */
    private const BILLING_ADDRESS_PARTS = array('street', 'city', 'state', 'postalcode', 'country');

    public function registerApiRest()
    {
        return array(
            'accountsErpAction' => array(
                'reqType' => 'POST',
                'path' => array('Accounts', '?', 'erp-action'),
                'pathVars' => array('module', 'record', ''),
                'method' => 'runErpAction',
                'shortHelp' => 'Runs an ERP write-back action for an account (Create ERP Account).',
                'exceptions' => array(
                    'SugarApiExceptionNotAuthorized',
                    'SugarApiExceptionInvalidParameter',
                    'SugarApiExceptionNotFound',
                ),
            ),
            'accountsErpCreateOppQuote' => array(
                'reqType' => 'POST',
                'path' => array('Accounts', '?', 'erp-create-opp-quote'),
                'pathVars' => array('module', 'record', ''),
                'method' => 'createOppQuote',
                'shortHelp' => 'Creates a linked Opportunity + Quote (typed Sales Order by default, optional placeholder line) from an Account.',
                'exceptions' => array(
                    'SugarApiExceptionNotAuthorized',
                    'SugarApiExceptionInvalidParameter',
                    'SugarApiExceptionNotFound',
                ),
            ),
        );
    }

    public function runErpAction(ServiceBase $api, array $args): array
    {
        if (empty($args['record'])) {
            throw new SugarApiExceptionInvalidParameter('record id is required');
        }

        $action = $args['action'] ?? '';
        if (!in_array($action, self::SUPPORTED_ACTIONS, true)) {
            throw new SugarApiExceptionInvalidParameter('Unsupported action: ' . $action);
        }

        $bean = BeanFactory::retrieveBean('Accounts', $args['record']);
        if ($bean === null || $bean === false) {
            throw new SugarApiExceptionNotFound('Account not found');
        }

        if (!$bean->ACLAccess('edit')) {
            throw new SugarApiExceptionNotAuthorized('No edit access to this account');
        }

        // G495 (🔒1759b) - AN ACCOUNT EPICOR ALREADY HOLDS IS REFUSED, FIRST.
        //
        // This used to answer `status: success, "Account is already
        // provisioned in ERP."` - after the button had ALREADY saved
        // account_type = Customer - so the seller read success while Epicor
        // was never asked for anything (benchdogs-dev ACDELCO, ADM 1310). A
        // Prospect Epicor holds becomes a Customer by its Type being changed
        // in Sugar and the accounts write-back carrying the new customer type
        // (decision 133(b)); it is never created a second time.
        //
        // Checked BEFORE the Customer test below, so a keyed Prospect hit
        // directly is told the true reason, not "Only Customer accounts".
        // Nothing is written on this path: no save, no ERP call, no
        // erp_writeback_* stamp. The browser refuses the same click before it
        // switches the type (create-erp-account.js _onErpActionClicked), which
        // is what keeps account_type unchanged; this answer cannot undo a type
        // an older browser copy already saved, because it does not know what
        // the type was before.
        if (trim((string) ($bean->erp_sync_key ?? '')) !== '') {
            return array(
                'status' => 'error',
                'error' => 'Account is already linked to an ERP customer.',
                'message' => self::ALREADY_IN_ERP_MESSAGE,
                'record' => $bean->id,
                'erp_id' => $bean->erp_account_id ?? '',
            );
        }

        // Mirrors create-erp-account.js's own flow - the click switches the
        // account to Customer and saves before calling here - as defense in
        // depth against the endpoint being hit directly.
        if (($bean->account_type ?? '') !== 'Customer') {
            return array(
                'status' => 'error',
                'message' => 'Only Customer accounts can be provisioned in ERP.',
                'record' => $bean->id,
            );
        }

        $cfg = $this->loadOrchestratorConfig();

        if ($cfg['url'] === '' || $cfg['token'] === '' || $cfg['tenant'] === '') {
            $GLOBALS['log']->error('Accounts ERP action: Missing orchestrator configuration', array(
                'orchestrator_url_empty' => ($cfg['url'] === ''),
                'api_token_empty' => ($cfg['token'] === ''),
                'tenant_id_empty' => ($cfg['tenant'] === ''),
            ));

            return array(
                'status' => 'error',
                'message' => 'ERP integration not configured. Contact administrator.',
                'record' => $bean->id,
            );
        }

        $result = $this->provisionAccount($bean, $cfg);

        // The connector stamps erp_sync_key/erp_account_id/erp_writeback_*
        // directly on this same Account via its own Sugar Integrate call on
        // success - re-retrieve rather than trust this stale in-memory copy
        // (see QuotesErpActionsApi::runErpAction() for the same pattern and
        // why: save() here would otherwise silently revert whatever the
        // connector just wrote).
        $bean->retrieve($bean->id);

        if ($result['status'] === 'error') {
            // The switch-and-save that got us here already committed
            // account_type = Customer - if the ERP call itself then fails,
            // revert it back to Prospect so the account isn't left stuck in
            // a half-provisioned state (Customer, no erp_sync_key). The
            // button's own visibility check (no erp_sync_key, G494) would
            // still show it either way, but a Prospect reflects reality more
            // accurately than an unprovisioned Customer.
            $bean->account_type = 'Prospect';
            if (!empty($result['error'])) {
                $bean->erp_writeback_status = $result['status'];
                $bean->erp_writeback_msg = $result['error'];
                $bean->erp_writeback_at = TimeDate::getInstance()->nowDb();
            }
            $bean->save();
        }

        return array(
            'status' => $result['status'],
            'message' => $result['message'],
            'record' => $bean->id,
            'erp_id' => $result['erp_id'] ?? '',
        );
    }

    // -------------------------------------------------------------------
    // Create Opportunity & Quote
    // -------------------------------------------------------------------

    /**
     * Raises an Opportunity AND its Quote from the account record in one
     * act. Bean sequence follows Sugar's own quote-from-opportunity
     * conversion (Opportunity -> [Revenue Line Item] -> Quote -> default
     * ProductBundle -> Products line), re-sourced from the Account:
     *
     *   - The Quote is the leading object. It is born linked to the
     *     opportunity, marked erp_is_primary_quote (the opportunity and the
     *     quote are created in the same call, so there is no other candidate
     *     - and nothing else sets that flag on a quote Sugar raised itself,
     *     yet the connector's RLI/amount reflection gates on it), and typed
     *     erp_quote_type per config (default sales_order since G392 - it was
     *     advanced_quote through 1.1.123; a seller who needs estimation
     *     switches the quote to Advanced Quote, or a tenant sets
     *     opp_quote_type = advanced_quote to make that the default again).
     *   - One optional FREE-TEXT placeholder line (no product_template_id;
     *     the name IS the item). QuotesErpActionsApi::getQuoteRecord()
     *     handles exactly this shape, so the placeholder rides the Advanced
     *     Quote payload to Epicor untouched. The placeholder is an
     *     estimation artefact: a tenant that opts into it
     *     (opp_quote_placeholder_enabled) should set opp_quote_type =
     *     advanced_quote beside it - this method does not couple the two
     *     keys, because either one is a tenant's own choice. In Revenue Line Items mode the
     *     same placeholder is mirrored as one RLI, the way Sugar's own
     *     conversion would.
     *   - Everything is created at amount 0: the honest number before
     *     estimating has priced anything. The connector's roll-up direction
     *     is ERP -> Sugar, so seeding values here would fight the sync.
     *
     * Serves EVERY account, including one Epicor does not hold yet (owner,
     * 🔒1760b). It used to refuse an account with no erp_display_sync_key;
     * the quote's own hand-off now creates the Epicor record when it is
     * needed - QuotesErpActionsApi::performWriteback() provisions the billing
     * account as a Prospect at Send to Estimation and as a Customer at Submit
     * Order (decision 550 / 133(c)) - so a customer need not exist first.
     *
     * Optional body args: name (overrides the configured name template),
     * placeholder (overrides the configured placeholder line name),
     * quantity (placeholder line quantity, default 1), description (the
     * quote's note - G473: the Smart Prompts cards create their quote through
     * this route and carry why it was raised, e.g. "The reorder window closed
     * 2026-09-04.").
     */
    public function createOppQuote(ServiceBase $api, array $args): array
    {
        if (empty($args['record'])) {
            throw new SugarApiExceptionInvalidParameter('record id is required');
        }

        $account = BeanFactory::retrieveBean('Accounts', $args['record']);
        if ($account === null || $account === false) {
            throw new SugarApiExceptionNotFound('Account not found');
        }

        if (!$account->ACLAccess('edit')) {
            throw new SugarApiExceptionNotAuthorized('No edit access to this account');
        }

        $opp = BeanFactory::newBean('Opportunities');
        $quote = BeanFactory::newBean('Quotes');
        if (!$opp->ACLAccess('save') || !$quote->ACLAccess('save')) {
            throw new SugarApiExceptionNotAuthorized('No access to create opportunities and quotes');
        }

        // 🔒1760b: NO "is it in Epicor?" refusal here any more. It read
        // erp_display_sync_key, which every seed-loaded sandbox account lacks
        // (G494, 379 of 379), and the owner has ruled the button serves every
        // account: Send to Estimation / Submit Order create the Epicor record
        // (QuotesErpActionsApi::performWriteback, decision 550). The record,
        // not-found and ACL guards above are the ones that stay.

        $config = self::erpIntegrationConfig();
        $assigned = $api->user->id;

        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            $name = self::defaultName($config, $account);
        }
        $closeDate = date('Y-m-d', strtotime('+' . self::configInt($config, self::CFG_CLOSE_DAYS, self::DEFAULT_CLOSE_DAYS) . ' days'));
        $salesStage = self::configuredSalesStage($config);
        $probability = self::configuredProbability($config, $salesStage);
        $quoteStage = self::configuredDomKey($config, self::CFG_QUOTE_STAGE, 'quote_stage_dom', self::DEFAULT_QUOTE_STAGE);
        $quoteType = self::configuredDomKey($config, self::CFG_QUOTE_TYPE, 'erp_quote_type_list', self::DEFAULT_QUOTE_TYPE);

        // G250 (owner, 2026-09-22): "dont put by default this line item
        // 'Engineered to order - scope to be defined in estimating' leave it
        // empty". The quote is born with an EMPTY default bundle; the seller
        // adds the first line. The placeholder stays available as an explicit
        // per-tenant opt-in (opp_quote_placeholder_enabled = true) because
        // the seed block below is also what stamps erp_estimation_placeholder
        // (🔒 1449 / G192), and a tenant that still wants the old shape must
        // get the stamped one, never an unstamped look-alike.
        //
        // What this changes downstream, decided under 🔒 1501: with no seed,
        // Send to Estimation on a quote with NO lines is REFUSED, fail-closed,
        // before anything is stamped or sent (QuotesErpActionsApi, the
        // no-lines refusal beside estimationRefusal()). A header-only Epicor
        // quote is not sent. And G251's stale-LineDesc source is gone with the
        // seed: a line the seller adds is described by the product they picked.
        // Measured on Bench 2026-09-22: the erp_integration config carries no
        // opp_quote_* key, so this default is what runs there.
        $seedPlaceholder = self::configBool($config, self::CFG_PLACEHOLDER_ENABLED, false);
        $placeholder = trim((string) ($args['placeholder'] ?? ''));
        if ($placeholder === '') {
            $placeholder = self::configString($config, self::CFG_PLACEHOLDER_NAME, self::DEFAULT_PLACEHOLDER_NAME);
        }
        $placeholderPart = self::configString($config, self::CFG_PLACEHOLDER_PART, self::DEFAULT_PLACEHOLDER_PART);
        $qty = max(1, (int) ($args['quantity'] ?? 1));

        $currency = $this->accountCurrency($account);

        $opp->name = $name;
        $opp->amount = 0;
        $opp->currency_id = $currency['id'];
        $opp->base_rate = $currency['rate'];
        $opp->date_closed = $closeDate;
        $opp->sales_stage = $salesStage;
        if ($probability !== null) {
            $opp->probability = $probability;
        }
        $opp->assigned_user_id = $assigned;
        $opp->account_id = $account->id;
        $opp->account_name = $account->name;
        $opp->save();
        if ($opp->load_relationship('accounts')) {
            $opp->accounts->add($account);
        }

        if ($seedPlaceholder && $this->usingRevenueLineItems()) {
            $rli = BeanFactory::newBean('RevenueLineItems');
            $rli->name = $placeholder;
            $rli->likely_case = 0;
            $rli->best_case = 0;
            $rli->worst_case = 0;
            $rli->quantity = $qty;
            $rli->discount_price = 0;
            $rli->list_price = 0;
            $rli->currency_id = $currency['id'];
            $rli->base_rate = $currency['rate'];
            $rli->date_closed = $closeDate;
            $rli->sales_stage = $salesStage;
            if ($probability !== null) {
                $rli->probability = $probability;
            }
            $rli->assigned_user_id = $assigned;
            $rli->opportunity_id = $opp->id;
            $rli->account_id = $account->id;
            $rli->save();
        }

        $quote->name = $name;
        // G473: the caller's note, verbatim; absent -> the quote has none, as before.
        $description = trim((string) ($args['description'] ?? ''));
        if ($description !== '') {
            $quote->description = $description;
        }
        $quote->quote_stage = $quoteStage;
        $quote->erp_quote_type = $quoteType;
        $quote->date_quote_expected_closed = $closeDate;
        $quote->assigned_user_id = $assigned;
        $quote->currency_id = $currency['id'];
        $quote->base_rate = $currency['rate'];
        $quote->billing_account_id = $account->id;
        $quote->billing_account_name = $account->name;
        $quote->shipping_account_id = $account->id;
        $quote->shipping_account_name = $account->name;
        $quote->erp_is_primary_quote = true;
        $quote->subtotal = 0;
        $quote->new_sub = 0;
        $quote->total = 0;
        $quote->shipping = 0;
        $quote->tax = 0;
        $quote->subtotal_usdollar = 0;
        $quote->new_sub_usdollar = 0;
        $quote->total_usdollar = 0;
        // The opportunity link is added AFTER this save (see the
        // load_relationship('opportunities') call below), and Quote.php's
        // $relationship_fields orders billing_accounts BEFORE opportunities.
        // So at save time there is no opportunity on this quote -- which is
        // exactly the state ErpQuoteAddressGuards refuses (G179/G183: a billing
        // account or shipping address may not be set on an opportunity-less
        // quote). Stamping the id here, before the save, is what lets the
        // guard resolve the opportunity->account chain and allow this create.
        // WITHOUT THIS LINE the product's own "Create Opportunity and Quote"
        // button is refused - a seller's request, on a seller's platform, so
        // the guard's ERP-writer exemption (sugarai_erp_connector /
        // epicor_seed) does not cover it and must not.
        $quote->opportunity_id = $opp->id;

        // G275 — THE QUOTE IS BORN WITH ITS BILLING ADDRESS. Measured: Bench
        // quote 346 (ADDISON, 2026-09-22 15:17:37Z) came out of this page with
        // billing_account set and every billing_address_* line empty, while a
        // REST create with billing_account_id on the same account showed
        // "062 ErpWins Way, Madison WI 53703".
        //
        // The copy a native create gets is SERVER-side but lives in the REST
        // layer only: SugarBeanApiHelper -> QuotesApiHelper::populateFromApi()
        // (SugarEnt 26.1.0 modules/Quotes/QuotesApiHelper.php:37-44) calls
        // processBeanAddressFields($account, $quote, 'billing', 'billing',
        // 'shipping'). This route builds the bean in PHP and save()s it, so
        // that helper never runs; Quote::save(), the logic hooks and the
        // relate field's populate_list (a Sidecar mechanism) do not copy it.
        //
        // MIRRORED EXACTLY, per part (:128-157): a value already on the quote
        // stands; otherwise the account's billing line, falling back to its
        // shipping line only when the billing one is unset (`??`); otherwise
        // ''. Only the BILLING half is mirrored: the helper's shipping half
        // (:50-62) fills the quote's shipping address from the account, which
        // 🔒 1456 / G223 below forbid - the Ship To comes from the account's
        // ERP default ship-to or stays empty and says why.
        //
        // BEFORE the save, like G223's lines: they are real columns, and after
        // the save they would need a second write.
        foreach (self::BILLING_ADDRESS_PARTS as $part) {
            $quoteField = 'billing_address_' . $part;
            if (!empty($quote->{$quoteField})) {
                continue;
            }
            $billingLine = 'billing_address_' . $part;
            $shippingLine = 'shipping_address_' . $part;
            $quote->{$quoteField} = (string) ($account->{$billingLine} ?? $account->{$shippingLine} ?? '');
        }

        // 🔒 G223 — THE QUOTE IS BORN WITH A SHIP TO. Owner: "when you create a
        // quote and opperunity from accuunt create quote and oppruntity page
        // assign it a dfeault shipping adresss dont leave it empty". Measured:
        // Bench quote 314 (ADDISON) came out of this page with Ship To and
        // Shipping Address both EMPTY while billing was filled - this route
        // sets billing explicitly and never named the address at all, and
        // 🔒 1456.2's required flag cannot reach a server-side create.
        //
        // BEFORE the save deliberately: ErpQuoteAddressGuards validates
        // shipping_address_id on save and needs the opportunity stamped above
        // to resolve the account chain. Set after the save it would be a
        // second write that the guard sees without an opportunity.
        $shipTo = self::defaultShippingAddress($account);
        if ($shipTo !== null) {
            $quote->shipping_address_id = (string) $shipTo->id;
            $quote->shipping_address_name = (string) ($shipTo->name ?? '');
            // 🛑 COPIED BY HAND, BECAUSE populate_list IS A CLIENT MECHANISM.
            // CORE-ShippingAddresses declares the five-field populate_list on
            // shipping_address_name, and Sidecar applies it when a seller picks
            // in the drawer. Nothing applies it on a server-side create, so
            // without this the quote would carry an id whose address lines are
            // blank - which is the half of quote 314 the owner could actually
            // see.
            foreach (self::SHIP_TO_ADDRESS_FIELDS as $field) {
                $quote->{$field} = (string) ($shipTo->{$field} ?? '');
            }
        }

        // G429 — THE QUOTE IS BORN WITH ITS ACCOUNT'S ERP COMPANY. Measured:
        // stock quote #1031 (c8b31b70, from this page) read "ERP Company" blank
        // while the account and the Epicor order both carried EPIC06. The one
        // writer of the quote's company is the billing account's populate_list
        // (billing_account_name_populate_erp_lookups.php) - a CLIENT mechanism,
        // the same lesson G223/G275 record above - so a server-side create
        // never ran it. Set here, before the save:
        // erp_companies_quotes_name is a 'save' => true relate, so
        // SugarBean::handle_remaining_relate_fields() writes the link in the
        // same save. The account's OWN company, never a default: an account
        // belongs to one ERP company (erp_companies_accounts, one-to-many), so a
        // customer that exists in two companies is two accounts and each quote
        // follows its own. No company on the account -> none on the quote.
        $company = $this->erpCompanyOf($account);
        if ($company !== null) {
            $quote->erp_companies_quoteserp_companies_ida = (string) $company->id;
            $quote->erp_companies_quotes_name = (string) ($company->name ?? '');
        }

        // G444 (a) — AND ITS BILLING TERMS AND FOB, THE OTHER TWO LOOKUPS THE
        // SAME populate_list COPIES (billing_account_name_populate_erp_lookups.php).
        // Measured on stock (round-2 ADVANCED QUOTE smoke, 1.1.124): Dalton's
        // account says "2/10 Net 30", its account button raised #1033 with
        // Billing Terms EMPTY - the G429 cause again, a client autofill a
        // server-side create never runs. Both are 'save' => true relates, so
        // handle_remaining_relate_fields() writes each link in the same save.
        // The account's own value or nothing: a lookup record that is deleted
        // or does not resolve is not copied, and the name is the record's own.
        foreach (self::ACCOUNT_LOOKUPS_ON_QUOTE as $accountId => $quoteFields) {
            $lookup = $this->erpLookupOf($account, $accountId);
            if ($lookup !== null) {
                $quote->{$quoteFields[0]} = (string) $lookup->id;
                $quote->{$quoteFields[1]} = (string) ($lookup->name ?? '');
            }
        }

        $quote->save();
        if ($quote->load_relationship('billing_accounts')) {
            $quote->billing_accounts->add($account);
        }
        if ($quote->load_relationship('opportunities')) {
            $quote->opportunities->add($opp);
        }

        // The default group every quote carries, whether or not a line goes
        // in it - QuotesErpActionsApi::getDefaultBundleId() expects one.
        $bundle = BeanFactory::newBean('ProductBundles');
        $bundle->name = '';
        $bundle->default_group = true;
        $bundle->bundle_stage = $quoteStage;
        $bundle->currency_id = $currency['id'];
        $bundle->base_rate = $currency['rate'];
        $bundle->subtotal = 0;
        $bundle->new_sub = 0;
        $bundle->total = 0;
        $bundle->save();
        if ($quote->load_relationship('product_bundles')) {
            $quote->product_bundles->add($bundle, ['position' => 0]);
        }

        if ($seedPlaceholder) {
            $li = BeanFactory::newBean('Products');
            $li->name = $placeholder;
            $li->description = $placeholder;
            $li->mft_part_num = $placeholderPart;
            // 🛑 THE IDENTITY STAMP, AND THIS LINE IS THE ONLY PLACE IT IS EVER
            // WRITTEN (🔒 1449, G192). Nothing downstream can work out later
            // which row this was: Epicor renames the part - 0 of the 12
            // surviving seeds on the tenant still read ETO-PENDING - and every
            // shape test tried instead condemned real seller-typed lines
            // (order 11634's three BD-ENDCAP-ALU rows). Here, at creation, the
            // answer is certain, so it is recorded rather than re-derived.
            // ErpGoverningTotal::retiredSeed() is the reader.
            $li->erp_estimation_placeholder = true;
            $li->quantity = $qty;
            $li->discount_price = 0;
            $li->list_price = 0;
            $li->cost_price = 0;
            $li->currency_id = $currency['id'];
            $li->base_rate = $currency['rate'];
            $li->quote_id = $quote->id;
            $li->position = 0;
            $li->assigned_user_id = $assigned;
            $li->account_id = $account->id;
            $li->save();
            if ($bundle->load_relationship('products')) {
                $bundle->products->add($li, ['position' => 0]);
            }
        }

        $GLOBALS['log']->info('Accounts ERP action: opportunity and quote created', array(
            'account_id' => $account->id,
            'opportunity_id' => $opp->id,
            'quote_id' => $quote->id,
            'quote_type' => $quoteType,
            'placeholder_line' => $seedPlaceholder,
        ));

        $message = $seedPlaceholder
            ? sprintf('Opportunity and quote "%s" created with a placeholder line.', $name)
            : sprintf('Opportunity and quote "%s" created.', $name);
        // Say what happened to the Ship To either way: a silently empty box is
        // the defect, and a silently filled one is a value nobody chose.
        $counts = self::activeShipToCounts($account);
        $message .= ' ' . ($shipTo !== null
            ? sprintf('Ship To set to "%s".', (string) ($shipTo->name ?? ''))
            : self::shipToEmptyReason($counts['active']));

        return array(
            'status' => 'success',
            'message' => $message,
            'record' => $account->id,
            'opportunity_id' => $opp->id,
            'opportunity_name' => $opp->name,
            'quote_id' => $quote->id,
            'quote_name' => $quote->name,
            'shipping_address_id' => $shipTo !== null ? (string) $shipTo->id : '',
            'shipping_address_name' => $shipTo !== null ? (string) ($shipTo->name ?? '') : '',
            // G223, the message half. The sentence above was ALREADY here when
            // Bench quote 335 (ADDISON, 3 ship-tos) came out empty: the page
            // showed it in a green auto-closing toast and navigated away, so
            // nobody read it. erp-create-opp-quote.js now raises a persistent
            // warning from these two counts, worded for the case at hand -
            // none at all / several and none primary / more than one primary -
            // without parsing the prose. Active = not deleted and not
            // inactive; primary = erp_primary_ship_to among the active ones.
            'shipping_address_count' => $counts['active'],
            'shipping_address_primary_count' => $counts['primary'],
        );
    }

    /**
     * G444 (a) — the ERP_LookupValues record an account's relate id names, or
     * null when the account has none, or the record is deleted or not found.
     */
    private function erpLookupOf(SugarBean $account, string $idField): ?SugarBean
    {
        $id = trim((string) ($account->{$idField} ?? ''));
        if ($id === '') {
            return null;
        }
        $lookup = BeanFactory::retrieveBean('ERP_LookupValues', $id);
        if (!$lookup || empty($lookup->id) || !empty($lookup->deleted)) {
            return null;
        }

        return $lookup;
    }

    /**
     * G429 — the account's own ERP company (the erp_companies_accounts relate),
     * or null when it has none or the record is gone. The relate id first (what
     * a retrieve fills, and what QuotesErpActionsApi::resolveErpCompanyCode()
     * reads at Submit Order); the link itself when that id is not on the bean.
     */
    private function erpCompanyOf(SugarBean $account): ?SugarBean
    {
        $id = trim((string) ($account->erp_companies_accountserp_companies_ida ?? ''));
        if ($id === '' && $account->load_relationship('erp_companies_accounts')
            && is_object($account->erp_companies_accounts ?? null)) {
            $ids = $account->erp_companies_accounts->get();
            $id = is_array($ids) ? trim((string) ($ids[0] ?? '')) : '';
        }
        if ($id === '') {
            return null;
        }
        $company = BeanFactory::retrieveBean('ERP_Companies', $id);
        if (!$company || empty($company->id) || !empty($company->deleted)) {
            return null;
        }

        return $company;
    }

    /**
     * THE ACCOUNT'S DEFAULT SHIPPING ADDRESS, or null when the data does not
     * name one. G223, G311.
     *
     * 🛑 NEVER THE BILLING ADDRESS (🔒 1456: billing never stands in). A quote
     * with no answer here is left empty and SAYS so - see shipToEmptyReason().
     *
     * ONE RULE: THE ERP'S OWN DEFAULT, AND NOTHING ELSE. erp_primary_ship_to
     * carries Epicor's `Customer.ShipToNum`, written by the connector's
     * ShipToPrimaryEnrichmentStep.
     *     🛑 CORRECTED 2026-09-22: this clause used to name
     *     `ShipTo.PrimaryShipTo` and claim the two agree on all 242 EPIC06
     *     customers. Re-measured, that field is **False on all 292 ship-to
     *     rows** - a dead field - so the "agreement" was one source read
     *     twice. `Customer.ShipToNum` is the only carrier. On ADDISON it
     *     names ShipToNum '001', "Addison, INC" - the owner's expectation.
     *     A BLANK Customer.ShipToNum names the customer's OWN sold-to address.
     *     Since G310 the connector mirrors that row as `<CO>__<CustNum>_@self`
     *     and flags it, so it arrives here as an ordinary flagged address -
     *     nothing below special-cases '@self'.
     *     Requiring EXACTLY one flagged row is the point: two would mean the
     *     ERP itself is ambiguous, which is not something to resolve by
     *     picking the first.
     *
     * 🛑 G311 - "EXACTLY ONE ACTIVE ADDRESS" IS NOT A RULE, AND MUST NOT COME
     * BACK AS A FALLBACK. It was 🔒 1522 phase 1 and sat here as rule 2. A lone
     * address is "the only one Sugar happens to hold", not "the one the ERP
     * names", and the two differ whenever Epicor's default is a row Sugar does
     * not mirror. MEASURED read-only 2026-09-23 against EPIC06
     * (Customer.ShipToNum, cross-checked with CustomerSvc/ShipToes.PrimaryShipTo):
     *   - Bench, no @self rows mirrored: it picked a DIFFERENT PHYSICAL ADDRESS
     *     from Epicor's default on 11 of the 15 ERP single-address customers
     *     (BARRISTON got Barr01; Epicor names its own sold-to address),
     *     silently - the "pallet at the wrong dock" 🔒 1522 rejected "first
     *     listed" for;
     *   - stock, after the G310 population run: those 11 now agree - through
     *     the flag, because their @self row is mirrored and flagged - and the
     *     fallback still fired on 2 accounts whose customer Epicor does not
     *     hold at all.
     * So an unflagged lone address answers null and the seller is told why.
     * It costs the right answer nothing: a customer whose lone address IS its
     * ERP default has it flagged (TORONTO, VICTIMBER, QUOTECUS, PGCUST) and
     * still gets it with no prompt. A tenant whose flags are stale or were
     * never written asks the seller rather than guessing - asking is never a
     * wrong delivery.
     *
     * Anything else - none, one or several with no ERP default, or two ERP
     * defaults - is AMBIGUOUS and answers null. Picking the only, the first,
     * the newest or the lowest ship-to number would be a fabricated default on
     * a customer-facing document.
     *
     * INACTIVE ROWS ARE NEVER CHOSEN: `inactive` is Epicor's own flag on the
     * ship-to, and shipping to one is the failure this is meant to avoid.
     */
    private static function defaultShippingAddress(SugarBean $account): ?SugarBean
    {
        // G474: the rule lives in ErpQuoteFacts::defaultShipToOfAccount() now,
        // so a quote created any other way (a recommendation, a REST create)
        // gets its default Ship To by the SAME rule - ErpQuoteShipToFollowsAccount.
        self::loadQuoteFacts();

        return ErpQuoteFacts::defaultShipToOfAccount($account);
    }

    /**
     * The account's ship-tos that are candidates at all: linked, not deleted,
     * not inactive. One walk, shared by the choice, the reason sentence and
     * the counts the page words its warning from, so the three can never
     * disagree about how many addresses there are.
     *
     * @return SugarBean[]
     */
    private static function activeShipTos(SugarBean $account): array
    {
        self::loadQuoteFacts();

        return ErpQuoteFacts::activeShipTosOfAccount($account);
    }

    /**
     * Load ErpQuoteFacts beside QuotesErpActionsApi's loader: guarded on the
     * CLASS (MLP001), file_exists() first, __DIR__-relative. It ships in this
     * package at a fixed path, so a missing one is a broken install - loud.
     */
    private static function loadQuoteFacts(): void
    {
        if (class_exists('ErpQuoteFacts', false)) {
            return;
        }
        $path = __DIR__ . '/../../../modules/Quotes/ErpQuoteFacts.php';
        if (file_exists($path)) {
            require_once $path;
        }
        if (!class_exists('ErpQuoteFacts', false)) {
            throw new RuntimeException('ErpQuoteFacts is not installed (custom/modules/Quotes/ErpQuoteFacts.php)');
        }
    }

    /**
     * How many active ship-tos the account has, and how many of them carry
     * the ERP's primary flag - the two numbers that decide which case the
     * seller is in when the Ship To comes out empty.
     *
     * @return array{active: int, primary: int}
     */
    private static function activeShipToCounts(SugarBean $account): array
    {
        $active = self::activeShipTos($account);
        $primary = 0;
        foreach ($active as $address) {
            if (!empty($address->erp_primary_ship_to)) {
                $primary++;
            }
        }

        return array('active' => count($active), 'primary' => $primary);
    }

    /**
     * Why the Ship To was left empty, in words the page can show. The seller
     * is the only one who can fix either case, so both name the next action.
     *
     * @param int $count Active ship-tos on the account (activeShipToCounts).
     */
    private static function shipToEmptyReason(int $count): string
    {
        if ($count === 0) {
            return 'Ship To is empty: this account has no shipping address yet. '
                . 'Add one on the account, then pick it on the quote.';
        }
        if ($count === 1) {
            // G311: the lone address is NOT the ERP's default - a flagged one
            // would have been chosen - so this is not "nothing to choose
            // between", and "1 shipping addresses ... among them" would hide
            // the one fact the seller needs.
            return 'Ship To is empty: this account has 1 shipping address and the ERP does not name it '
                . 'as the default, so it was not chosen for you. Pick one on the quote.';
        }

        return sprintf(
            'Ship To is empty: this account has %d shipping addresses and the ERP names no default '
            . 'among them, so none was chosen for you. Pick one on the quote.',
            $count
        );
    }

    /**
     * The configured name template with {account} and {date} filled in.
     */
    private static function defaultName(array $config, SugarBean $account): string
    {
        $template = self::configString($config, self::CFG_NAME_TEMPLATE, self::DEFAULT_NAME_TEMPLATE);

        return trim(strtr($template, array(
            '{account}' => (string) ($account->name ?? ''),
            '{date}' => date('M j, Y'),
        )));
    }

    /**
     * The opportunity's initial stage: the configured sales_stage_dom key
     * when it is one, else the dom's first key. A stage name is a tenant's
     * own vocabulary, so nothing here is hardcoded beyond the stock fallback
     * for a dom that cannot be read at all.
     */
    private static function configuredSalesStage(array $config): string
    {
        $stage = self::configuredDomKey($config, self::CFG_SALES_STAGE, 'sales_stage_dom', '');

        return $stage === '' ? self::FALLBACK_SALES_STAGE : $stage;
    }

    /**
     * The opportunity's initial probability: the configured value when
     * numeric, else what sales_probability_dom says for the stage (the same
     * stage->probability mapping Sugar Logic applies in the UI, which a
     * server-side bean save does not run), else null (leave it alone) -
     * the same resolution ErpOpportunityValuation applies to a partial
     * release.
     */
    private static function configuredProbability(array $config, string $stage): ?int
    {
        $explicit = $config[self::CFG_PROBABILITY] ?? null;
        if ($explicit !== null && $explicit !== '' && is_numeric($explicit)) {
            return max(0, min(100, (int) $explicit));
        }

        $dom = self::appListStrings('sales_probability_dom');
        if (isset($dom[$stage]) && is_numeric($dom[$stage])) {
            return (int) $dom[$stage];
        }

        return null;
    }

    /**
     * A configured dropdown key, validated against its dom: the configured
     * value when it is a key of the dom, else $default when that is one,
     * else the dom's first non-empty key. A typo in config is logged and
     * ignored rather than written onto a record where the UI could never
     * display it.
     */
    private static function configuredDomKey(array $config, string $key, string $dom, string $default): string
    {
        $list = self::appListStrings($dom);
        $value = self::configString($config, $key, '');

        if ($value !== '') {
            if (array_key_exists($value, $list)) {
                return $value;
            }
            $GLOBALS['log']->warn('AccountsErpActionsApi: ' . self::CONFIG_CATEGORY . '.' . $key . ' is '
                . var_export($value, true) . ' which is not a ' . $dom . ' key - using the default');
        }

        if ($default !== '' && (count($list) === 0 || array_key_exists($default, $list))) {
            return $default;
        }

        foreach (array_keys($list) as $domKey) {
            if ((string) $domKey !== '') {
                return (string) $domKey;
            }
        }

        return $default;
    }

    private static function appListStrings(string $dom): array
    {
        $strings = $GLOBALS['app_list_strings'] ?? null;
        if (!is_array($strings) || !isset($strings[$dom])) {
            $strings = return_app_list_strings_language($GLOBALS['current_language'] ?? 'en_us');
        }
        $list = is_array($strings) ? ($strings[$dom] ?? array()) : array();

        return is_array($list) ? $list : array();
    }

    private static function erpIntegrationConfig(): array
    {
        try {
            $admin = BeanFactory::getBean('Administration');
            $config = $admin->getConfigForModule(self::CONFIG_CATEGORY);
        } catch (Throwable $e) {
            $GLOBALS['log']->warn('AccountsErpActionsApi: could not read ' . self::CONFIG_CATEGORY . ' config: ' . $e->getMessage());
            return array();
        }

        return is_array($config) ? $config : array();
    }

    private static function configString(array $config, string $key, string $default): string
    {
        $value = trim((string) ($config[$key] ?? ''));

        return $value === '' ? $default : $value;
    }

    private static function configInt(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return ($value !== null && $value !== '' && is_numeric($value)) ? (int) $value : $default;
    }

    /**
     * An absent or blank value means "not configured", which is the
     * default, not false.
     */
    private static function configBool(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config) || $config[$key] === null || $config[$key] === '') {
            return $default;
        }

        return in_array($config[$key], [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * The currency the new records are priced in: the account's ERP currency
     * when it resolves, else the system default (-99 at rate 1) - the same
     * pair Sugar's own conversion uses when nothing better is known.
     */
    private function accountCurrency(SugarBean $account): array
    {
        $currencyId = (string) ($account->erp_currency_id ?? '');
        if ($currencyId !== '' && $currencyId !== '-99') {
            $currency = SugarCurrency::getCurrencyByID($currencyId);
            if ($currency && !empty($currency->id) && !empty($currency->conversion_rate)) {
                return array('id' => $currency->id, 'rate' => (float) $currency->conversion_rate);
            }
        }

        return array('id' => '-99', 'rate' => 1);
    }

    /**
     * Opportunities mode vs Revenue Line Items mode. Sugar's own answer when
     * it offers one; the Administration setting otherwise.
     */
    private function usingRevenueLineItems(): bool
    {
        if (class_exists('Opportunity') && method_exists('Opportunity', 'usingRevenueLineItems')) {
            return (bool) Opportunity::usingRevenueLineItems();
        }

        $admin = BeanFactory::getBean('Administration');
        $settings = $admin->getConfigForModule('Opportunities');

        return is_array($settings) && ($settings['opps_view_by'] ?? '') === 'RevenueLineItems';
    }
}
