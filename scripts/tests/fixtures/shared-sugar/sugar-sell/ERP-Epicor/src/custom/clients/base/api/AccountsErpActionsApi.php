<?php

use Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts;

/** The Accounts header-button actions. (G392) */
class AccountsErpActionsApi extends BaseErpActionsApi
{
    private const SUPPORTED_ACTIONS = ['create_customer'];

    /** G495 (🔒1759b) - what a seller reads when "Create Customer in Epicor" reaches an account Epicor already holds. */
    public const ALREADY_IN_ERP_MESSAGE = 'This account is already in Epicor, so it cannot be created there again. '
        . 'To make it a Customer, set Type to Customer and save: the change is sent to Epicor.';

    /** Administration config (category erp_integration) keys read by createOppQuote(). */
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
    // G392 (owner, 2026-09-23): *"create a quote and opperunity shoudl create a quote in sales order type not advanced"*.
    private const DEFAULT_QUOTE_TYPE = 'sales_order';
    private const DEFAULT_PLACEHOLDER_NAME = 'Engineered to order — scope to be defined in estimating';
    // The free-text PartNum the placeholder line carries to Epicor.
    private const DEFAULT_PLACEHOLDER_PART = 'ETO-PENDING';
    // Sugar's own stock first stage - only reached when sales_stage_dom cannot be read at all; the real default is the dom's first key.
    private const FALLBACK_SALES_STAGE = 'Prospecting';

    /**
     * The five address columns core-ShippingAddresses' populate_list copies from the picked ShippingAddress onto the quote.
     */
    private const SHIP_TO_ADDRESS_FIELDS = array(
        'shipping_address_street',
        'shipping_address_city',
        'shipping_address_state',
        'shipping_address_postalcode',
        'shipping_address_country',
    );

    /**
     * G444 (a) — the two ERP lookups billing_account_name's populate_list copies from the account onto the quote besides the company (G429).
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

    /** G275 — the address parts stock Sugar copies from an Account onto a Quote's billing address. */
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

        // G495 (🔒1759b) - an account epicor already holds is refused, first. (decision 133)
        if (trim((string) ($bean->erp_sync_key ?? '')) !== '') {
            return array(
                'status' => 'error',
                'error' => 'Account is already linked to an ERP customer.',
                'message' => self::ALREADY_IN_ERP_MESSAGE,
                'record' => $bean->id,
                'erp_id' => $bean->erp_account_id ?? '',
            );
        }

        // Mirrors create-erp-account.js's own flow - the click switches the account to Customer and saves before calling here.
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

        // The connector stamps erp_sync_key/erp_account_id/erp_writeback_* directly on this same Account via its own Sugar Integrate call on success.
        $bean->retrieve($bean->id);

        if ($result['status'] === 'error') {
            // The switch-and-save that got us here already committed account_type = Customer - if the ERP call itself then fails. (G494)
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

    // ------------------------------------------------------------------- Create Opportunity & Quote -------------------------------------------------------------------

    /** Raises an Opportunity and its Quote from the account record in one act. (G392, 🔒1760b, decision 550) */
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

        // 🔒1760b: no "is it in Epicor?" refusal here any more. (G494, decision 550)

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

        // G250 (owner, 2026-09-22): "dont put by default this line item 'Engineered to order - scope to be defined in estimating' leave it empty". (🔒 1449, G192, 🔒 1501)
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
        // The opportunity link is added after this save (see the load_relationship('opportunities') call below). (G179, G183)
        $quote->opportunity_id = $opp->id;

        // G275 — the quote is born with its billing address. (🔒 1456, G223)
        foreach (self::BILLING_ADDRESS_PARTS as $part) {
            $quoteField = 'billing_address_' . $part;
            if (!empty($quote->{$quoteField})) {
                continue;
            }
            $billingLine = 'billing_address_' . $part;
            $shippingLine = 'shipping_address_' . $part;
            $quote->{$quoteField} = (string) ($account->{$billingLine} ?? $account->{$shippingLine} ?? '');
        }

        // 🔒 G223 — the quote is born with a ship to. (🔒 1456.2)
        $shipTo = self::defaultShippingAddress($account);
        if ($shipTo !== null) {
            $quote->shipping_address_id = (string) $shipTo->id;
            $quote->shipping_address_name = (string) ($shipTo->name ?? '');
            // Copied by hand, because populate_list is a client mechanism.
            foreach (self::SHIP_TO_ADDRESS_FIELDS as $field) {
                $quote->{$field} = (string) ($shipTo->{$field} ?? '');
            }
        }

        // G429 — the quote is born with its account's ERP company. (G223, G275)
        $company = $this->erpCompanyOf($account);
        if ($company !== null) {
            $quote->erp_companies_quoteserp_companies_ida = (string) $company->id;
            $quote->erp_companies_quotes_name = (string) ($company->name ?? '');
        }

        // G444 (a) — and its billing terms and FOB, the other two lookups the same populate_list copies (billing_account_name_populate_erp_lookups.php). (G429)
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

        // The default group every quote carries, whether or not a line goes in it - QuotesErpActionsApi::getDefaultBundleId() expects one.
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
            // The identity stamp, and this line is the only place it is ever written (🔒 1449, G192).
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
        // Say what happened to the Ship To either way: a silently empty box is the defect, and a silently filled one is a value nobody chose.
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
            // G223, the message half.
            'shipping_address_count' => $counts['active'],
            'shipping_address_primary_count' => $counts['primary'],
        );
    }

    /**
     * G444 (a) — the ERP_LookupValues record an account's relate id names, or null when the account has none, or the record is deleted or not found.
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
     * G429 — the account's own ERP company (the erp_companies_accounts relate), or null when it has none or the record is gone.
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

    /** The account's default shipping address, or null when the data does not name one. (G223, G311, G748) */
    private static function defaultShippingAddress(SugarBean $account): ?SugarBean
    {
        // G474: the rule lives in ErpQuoteFacts::defaultShipToOfAccount() now, so a quote created any other way.

        return ErpQuoteFacts::defaultShipToOfAccount($account);
    }

    /**
     * The account's ship-tos that are candidates at all: linked, not deleted, not inactive.
     * @return SugarBean[]
     */
    private static function activeShipTos(SugarBean $account): array
    {

        return ErpQuoteFacts::activeShipTosOfAccount($account);
    }

    /**
     * How many active ship-tos the account has, and how many of them carry the erp's primary flag.
     * @return array{active:
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
     * Why the Ship To was left empty, in words the page can show.
     * @param int $count
     */
    private static function shipToEmptyReason(int $count): string
    {
        if ($count === 0) {
            return 'Ship To is empty: this account has no shipping address yet. '
                . 'Add one on the account, then pick it on the quote.';
        }
        if ($count === 1) {
            // Not reached since G748 (an account's one active address is always taken); kept so no rule change can print "1 shipping addresses ... among them".
            return 'Ship To is empty: this account has 1 shipping address and the ERP does not name it '
                . 'as the default, so it was not chosen for you. Pick one on the quote.';
        }

        return sprintf(
            'Ship To is empty: this account has %d shipping addresses and the ERP names no default '
            . 'among them, so none was chosen for you. Pick one on the quote.',
            $count
        );
    }

    /** The configured name template with {account} and {date} filled in. */
    private static function defaultName(array $config, SugarBean $account): string
    {
        $template = self::configString($config, self::CFG_NAME_TEMPLATE, self::DEFAULT_NAME_TEMPLATE);

        return trim(strtr($template, array(
            '{account}' => (string) ($account->name ?? ''),
            '{date}' => self::sellersDate(),
        )));
    }

    /** G542 — the {date} in a default name is the seller's date, not the server's. */
    private static function sellersDate(): string
    {
        try {
            $now = TimeDate::getInstance()->getNow(true);
            if ($now instanceof \DateTimeInterface) {
                return $now->format('M j, Y');
            }
        } catch (\Throwable $e) {
            $GLOBALS['log']->warn('AccountsErpActionsApi: the seller\'s local date could not be read, so the '
                . 'default name uses the server\'s date (G542): ' . $e->getMessage());
        }

        return date('M j, Y');
    }

    /** The opportunity's initial stage: the configured sales_stage_dom key when it is one, else the dom's first key. */
    private static function configuredSalesStage(array $config): string
    {
        $stage = self::configuredDomKey($config, self::CFG_SALES_STAGE, 'sales_stage_dom', '');

        return $stage === '' ? self::FALLBACK_SALES_STAGE : $stage;
    }

    /**
     * The opportunity's initial probability: the configured value when numeric, else what sales_probability_dom says for the stage.
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
     * A configured dropdown key, validated against its dom: the configured value when it is a key of the dom, else $default when that is one.
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

    /** An absent or blank value means "not configured", which is the default, not false. */
    private static function configBool(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config) || $config[$key] === null || $config[$key] === '') {
            return $default;
        }

        return in_array($config[$key], [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * The currency the new records are priced in: the account's ERP currency when it resolves, else the system default (-99 at rate 1).
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

    /** Opportunities mode vs Revenue Line Items mode. */
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
