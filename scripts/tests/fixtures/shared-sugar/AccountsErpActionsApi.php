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
 *     quote born primary and typed for Advanced Quote so the Advanced Quote
 *     button can take it to Epicor next. Entirely Sugar-side - no
 *     orchestrator call - see createOppQuote().
 */
class AccountsErpActionsApi extends BaseErpActionsApi
{
    private const SUPPORTED_ACTIONS = ['create_customer'];

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
    private const DEFAULT_QUOTE_TYPE = 'advanced_quote';
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
                'shortHelp' => 'Creates a linked Opportunity + Quote (typed for Advanced Quote, optional placeholder line) from an Account.',
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

        // Mirrors create-erp-account.js's own visibility check - defense in
        // depth against the endpoint being hit directly.
        if (($bean->account_type ?? '') !== 'Customer') {
            return array(
                'status' => 'error',
                'message' => 'Only Customer accounts can be provisioned in ERP.',
                'record' => $bean->id,
            );
        }

        if (!empty($bean->erp_sync_key)) {
            return array(
                'status' => 'success',
                'message' => 'Account is already provisioned in ERP.',
                'record' => $bean->id,
                'erp_id' => $bean->erp_account_id ?? '',
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
            // button's own visibility check (account_type === 'Prospect' ||
            // !erp_sync_key) would still show it either way, but a Prospect
            // reflects reality more accurately than an unprovisioned Customer.
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
     *     erp_quote_type per config (default advanced_quote, the lifecycle
     *     the Advanced Quote button and QuotesErpActionsApi gate on).
     *   - One optional FREE-TEXT placeholder line (no product_template_id;
     *     the name IS the item). QuotesErpActionsApi::getQuoteRecord()
     *     handles exactly this shape, so the placeholder rides the Advanced
     *     Quote payload to Epicor untouched. In Revenue Line Items mode the
     *     same placeholder is mirrored as one RLI, the way Sugar's own
     *     conversion would.
     *   - Everything is created at amount 0: the honest number before
     *     estimating has priced anything. The connector's roll-up direction
     *     is ERP -> Sugar, so seeding values here would fight the sync.
     *
     * Refuses an account with no ERP customer behind it: the quote raised
     * here is meant to go to Epicor next, and that submission needs the
     * customer to exist first (Create Customer in Epicor).
     *
     * Optional body args: name (overrides the configured name template),
     * placeholder (overrides the configured placeholder line name),
     * quantity (placeholder line quantity, default 1).
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

        // Mirrors erp-create-opp-quote.js's own visibility check - defense
        // in depth against the endpoint being hit directly. Same wording
        // family as the Quotes actions' "not linked to an ERP customer".
        if (trim((string) ($account->erp_display_sync_key ?? '')) === '') {
            return array(
                'status' => 'error',
                'error' => 'Account is not linked to an ERP customer.',
                'message' => 'Cannot create an opportunity and quote: account has no ERP customer ID. Create the customer in Epicor first.',
                'record' => $account->id,
            );
        }

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

        $seedPlaceholder = self::configBool($config, self::CFG_PLACEHOLDER_ENABLED, true);
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

        return array(
            'status' => 'success',
            'message' => $seedPlaceholder
                ? sprintf('Opportunity and quote "%s" created with a placeholder line.', $name)
                : sprintf('Opportunity and quote "%s" created.', $name),
            'record' => $account->id,
            'opportunity_id' => $opp->id,
            'opportunity_name' => $opp->name,
            'quote_id' => $quote->id,
            'quote_name' => $quote->name,
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
