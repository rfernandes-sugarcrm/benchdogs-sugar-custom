<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * G380 / G381 (owner rulings 🔒 1705b, 🔒 1724b): Bench Dogs' ADM rules, the Sugar
 * half. ADM config and ADM rules ONLY - everything generic is ERP-Epicor's or
 * core's, and this class ASKS for it rather than re-deriving it.
 *
 * WHAT LIVES HERE (each a rule of ONE customer's ERP company):
 *
 *  - Reference DEFAULTS to the ship-to's city and state ("WAYNE NJ") on an ADM
 *    quote not yet sent to the ERP. The field is ERP-Epicor's generic
 *    Quotes.erp_reference; only this default is Bench's. Only an EMPTY value is
 *    filled, so the seller may change it. G530: the default is SHORTENED to
 *    what the ERP takes (Epicor QuoteHed.Reference, 10 characters, the field's
 *    erp_max_length) - the state is kept and the city cut ("HARRISB PA").
 *  - Project is PRE-FILLED from the product-group -> project default list
 *    (bd_adm_project_by_group_list, tenant data an admin edits in Dropdown
 *    Editor; CMI -> 20065, owner rule 🔒 1712b), only when EVERY line's group
 *    maps to the SAME project.
 *  - the seller's pick lists for Lead Source, Lead Type and Project: active
 *    ERP_LookupValues rows of types BdLeadSources / BdLeadTypes / BdProjects,
 *    which core publishes from the ADM connection's own code-list config.
 *  - G460: the pick lists for Marketing Campaign and Marketing Event: active
 *    rows of types BdMarketingCampaigns / BdMarketingEvents, which the Bench
 *    connector extension publishes from ADM's own masters (ADM connection
 *    only). ADM names no default event (DefMktgEvntSeq 0, isDefault false,
 *    measured), so nothing is ever INVENTED for them.
 *  - G809 (owner, quote 8972: "maybe we should put defaults"): on a NEW ADM
 *    quote, an EMPTY Lead Source, Lead Type and Campaign + Event pair are
 *    copied from the SAME ACCOUNT's newest quote that holds them - the
 *    customer's own last choice (pilot ADM: consecutive quotes of one customer
 *    repeat Lead Source 93 %, Lead Type 95 %, Campaign 74 %) - and only a value
 *    the picker still offers. The pair comes from ONE quote. This is the
 *    server half, for a quote created without the form whose create save
 *    carries billing_account_id (an API create) - not the Account page's quote
 *    button, which links the account after its first save; the create form's
 *    half is ERP-Core's erp_prefill_from_account_latest, the same rule in the
 *    browser.
 *
 * WHICH QUOTES ARE ADM, WITH NO COMPANY LIST OF ITS OWN (🔒 1724b: "ADM" from
 * one source). A quote is an ADM quote when its ERP company has published
 * BdLeadSources rows (erp_sync_key "<COMPANY>__BdLeadSources_<code>", core's
 * scoped key). Only the ADM connection's lookup_code_lists config publishes that
 * type, so this side follows core's config and cannot disagree with it.
 *
 * WHAT DOES NOT LIVE HERE ANY MORE: which ERP company a quote is for, and which
 * product group a line is in, are ERP-Epicor's ErpQuoteFacts (G380 (g)) - the
 * one copy QuotesErpActionsApi builds its payload from, so an answer here cannot
 * drift from what is sent (the old private copies had already drifted, footprint
 * SB8). The part-number refusal is ERP-Epicor's per-company switch (G380 (e));
 * this package no longer fills its ordering hook points. Refusing a send that
 * lacks an ADM value is the connector extension's write-back hook.
 *
 * AN OLDER ERP-EPICOR IS TOLERATED, NEVER FATAL. ErpQuoteFacts is loaded by its
 * one literal path, guarded by class_exists (MLP001), with @include_once so a
 * missing file is a warning, not a fatal. Without it the defaults are skipped
 * and the reason is logged: they are a convenience, and a Quote save must never
 * fail for them (MLP004).
 *
 * Scanner-safe: no glob, no is_callable, no dynamic dispatch, no call_user_func.
 */
class BdAdmRules
{
    /** ERP_LookupValues discriminators, a contract with core's code-list config. */
    public const TYPE_LEAD_SOURCES = 'BdLeadSources';
    public const TYPE_LEAD_TYPES = 'BdLeadTypes';
    public const TYPE_PROJECTS = 'BdProjects';

    /** G460: published by the Bench connector extension, a contract with it. */
    public const TYPE_MARKETING_CAMPAIGNS = 'BdMarketingCampaigns';
    public const TYPE_MARKETING_EVENTS = 'BdMarketingEvents';

    /**
     * G804 (🔒 2081b): ADM's customer groups, published by the Bench connector
     * extension (display key = Epicor GroupCode, name = GroupDesc).
     */
    public const TYPE_CUSTOMER_GROUPS = 'BdCustomerGroups';
    public const FIELD_GROUP_CODE = 'bd_customer_group_code';
    public const FIELD_GROUP_NAME = 'bd_customer_group';

    /**
     * G460: an event's picker key is "<campaign>/<seq>" (26DISCNV/2), split at
     * the LAST separator - the contract with the connector extension
     * (adm_rules.EVENT_KEY_SEPARATOR), which splits the seller's pick the same
     * way and refuses a pair whose event is not the campaign's.
     */
    public const EVENT_KEY_SEPARATOR = '/';

    /** Tenant data (app_list_strings), editable in Admin > Dropdown Editor. */
    public const LIST_PROJECT_BY_GROUP = 'bd_adm_project_by_group_list';

    /** ERP-Epicor's public quote facts (G380 (g)), by its one fixed path. */
    public const QUOTE_FACTS_FILE = 'custom/modules/Quotes/ErpQuoteFacts.php';

    /** A quote with more lines than this is not scanned for a project default. */
    public const MAX_LINES_SCANNED = 500;

    /**
     * G809: the single pickers defaulted from the account's newest quote,
     * field => the lookup type whose ACTIVE rows it may take a value from.
     * (The Campaign + Event pair is defaulted as a pair, not from here.)
     */
    public const ACCOUNT_HISTORY_FIELDS = array(
        'bd_lead_source' => self::TYPE_LEAD_SOURCES,
        'bd_lead_type' => self::TYPE_LEAD_TYPES,
    );
    public const FIELD_CAMPAIGN = 'bd_marketing_campaign';
    public const FIELD_EVENT = 'bd_marketing_event';

    /** Upper bound on the BdLeadSources rows read to learn the ADM companies. */
    public const MAX_LEAD_SOURCE_ROWS = 5000;

    /** @var string[]|null the ADM companies, read once per request */
    private static $admCompanies = null;

    // ── which quotes are ADM ─────────────────────────────────────────────────

    /**
     * The ERP companies that have published BdLeadSources rows, upper-cased,
     * read ONCE per request (one query, however many quotes are saved).
     *
     * The company is split off each row's erp_sync_key in PHP at the FIRST
     * "__" (core's scoped key), never matched with SQL LIKE: '_' is a LIKE
     * wildcard, so 'ADM__%' would also match 'ADMX_...'.
     *
     * @return string[]
     */
    public static function admCompanies(): array
    {
        if (self::$admCompanies !== null) {
            return self::$admCompanies;
        }
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('ERP_LookupValues'), array('team_security' => false));
        $query->select(array('erp_sync_key'));
        $query->where()->equals('type', self::TYPE_LEAD_SOURCES);
        $query->limit(self::MAX_LEAD_SOURCE_ROWS);
        self::$admCompanies = self::companiesFromKeys($query->execute());

        return self::$admCompanies;
    }

    /**
     * The pure half of admCompanies(): the distinct company prefixes of these
     * rows' erp_sync_key values. A key without "__", or with nothing before it,
     * names no company and is skipped.
     *
     * @return string[]
     */
    public static function companiesFromKeys(array $rows): array
    {
        $companies = array();
        foreach ($rows as $row) {
            $key = (string) ($row['erp_sync_key'] ?? '');
            $at = strpos($key, '__');
            if ($at === false || $at === 0) {
                continue;
            }
            $companies[strtoupper(trim(substr($key, 0, $at)))] = true;
        }
        unset($companies['']);
        $out = array_keys($companies);
        sort($out);

        return $out;
    }

    /** True when $company has published BdLeadSources rows. */
    public static function isAdmCompany(string $company): bool
    {
        $wanted = strtoupper(trim($company));

        return $wanted !== '' && in_array($wanted, self::admCompanies(), true);
    }

    /** Forget the per-request answer (tests; a long-running worker's next unit of work). */
    public static function forgetAdmCompanies(): void
    {
        self::$admCompanies = null;
    }

    // ── ERP-Epicor's quote facts ─────────────────────────────────────────────

    /** True when ERP-Epicor's ErpQuoteFacts is loaded or loadable. */
    public static function quoteFactsAvailable(): bool
    {
        if (!class_exists('ErpQuoteFacts', false)) {
            // The literal path, as ERP-Epicor documents it (MLP001); keep it
            // equal to QUOTE_FACTS_FILE (bd_adm_rules_test.php pins that).
            @include_once 'custom/modules/Quotes/ErpQuoteFacts.php';
        }

        return class_exists('ErpQuoteFacts', false);
    }

    // ── G380: Reference defaults to the ship-to's city and state ─────────────

    /**
     * "WAYNE NJ": the city and the state, whichever are present, one space
     * apart - SHORTENED to $max characters when it is longer (G530).
     *
     * Measured (benchdogs-sandbox #36, 2026-09-24 21:08:21Z): the default
     * "HARRISBURG PA" (13) was refused by ADM, "The maximum number of
     * characters allowed for Reference is 10", and no ERP quote was created.
     *
     * THE RULE, so a seller can predict it:
     *   1. it fits ($max <= 0 means no limit is known): unchanged;
     *   2. the STATE is kept whole and the CITY is cut to the room left
     *      ($max - state - 1 for the space): "HARRISBURG PA" -> "HARRISB PA",
     *      "SALT LAKE CITY UT" -> "SALT LA UT"; a cut that ends on a space
     *      drops it ("NEW YORK NY" at 7 -> "NEW NY", never "NEW  NY");
     *   3. no room for a city beside the state (a state of $max - 1 or more
     *      characters), or no state: the first $max characters of what there
     *      is, trailing space dropped.
     * Characters, not bytes. The seller can overwrite it; only an EMPTY
     * Reference is ever defaulted.
     */
    public static function defaultReference(string $city, string $state, int $max = 0): string
    {
        $city = trim($city);
        $state = trim($state);
        $parts = array();
        foreach (array($city, $state) as $part) {
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        $full = implode(' ', $parts);
        if ($max <= 0 || mb_strlen($full, 'UTF-8') <= $max) {
            return $full;
        }

        $room = $max - mb_strlen($state, 'UTF-8') - 1;
        if ($city !== '' && $state !== '' && $room >= 1) {
            $cut = rtrim(mb_substr($city, 0, $room, 'UTF-8'));
            if ($cut !== '') {
                return $cut . ' ' . $state;
            }
        }

        return rtrim(mb_substr($full, 0, $max, 'UTF-8'));
    }

    /**
     * G530: the most characters the ERP takes in this quote's Reference,
     * asked of ERP-Epicor (ErpQuoteFacts::referenceMaxLength(), which reads
     * the field's erp_max_length), 0 when that ERP-Epicor is older and does
     * not say - then the default is not cut, and Send to Estimation's answer
     * is the ERP's own, as before. That case is LOGGED, like a missing
     * ErpQuoteFacts: an uncut default is exactly the G530 symptom, and the
     * log is where the reason must be findable.
     */
    public static function referenceMaxLength($bean): int
    {
        if (!method_exists('ErpQuoteFacts', 'referenceMaxLength')) {
            if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
                $GLOBALS['log']->error('BenchDogs-Ext: ERP-Epicor is older than G530 (no '
                    . 'ErpQuoteFacts::referenceMaxLength), so the ADM Reference default is NOT shortened '
                    . 'to the ERP limit for quote ' . (is_object($bean) ? (string) ($bean->id ?? '') : ''));
            }

            return 0;
        }

        return ErpQuoteFacts::referenceMaxLength($bean);
    }

    // ── G381: Project pre-filled from the product-group default list ─────────

    /**
     * The one project the default list gives these product groups, or '' when
     * any group is blank or unmapped, or the groups map to different projects.
     * One project per quote goes on every order line, so a quote mixing a
     * mapped group with an unmapped one (CMI with DISPLAYS, which has no
     * dominant project) is left for the seller to pick.
     */
    public static function defaultProject(array $groups): string
    {
        if ($groups === array()) {
            return '';
        }
        $map = array();
        foreach (self::appList(self::LIST_PROJECT_BY_GROUP) as $group => $project) {
            $group = strtoupper(trim((string) $group));
            $project = trim((string) $project);
            if ($group !== '' && $project !== '') {
                $map[$group] = $project;
            }
        }
        $chosen = '';
        foreach ($groups as $group) {
            $project = $map[strtoupper(trim((string) $group))] ?? '';
            if ($project === '' || ($chosen !== '' && $project !== $chosen)) {
                return '';
            }
            $chosen = $project;
        }

        return $chosen;
    }

    // ── the before_save hook ─────────────────────────────────────────────────

    /**
     * Logic hook entry point (before_save on Quotes). Runs on EVERY Quote save
     * on the tenant, so it is built to cost nothing for a quote it cannot touch.
     *
     * FAILS OPEN, and that is the non-lossy direction here: these are
     * DEFAULTS, a convenience for the seller. A defaults lookup that throws
     * must not fail the save of a quote the seller is editing (MLP004); the
     * worst case is an empty Reference or Project, which the send refuses by
     * name. The failure is logged, never swallowed silently.
     */
    public function beforeSave($bean, $event, $arguments): void
    {
        try {
            // G809: the account-history defaults are for a NEW quote only
            // (Sugar passes isUpdate to before_save; absent means unknown, and
            // unknown is treated as an update: never fill on a guess).
            $isCreate = is_array($arguments) && array_key_exists('isUpdate', $arguments)
                && empty($arguments['isUpdate']);
            self::applyDefaults($bean, $isCreate);
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: ADM quote defaults failed for quote '
                . (string) ($bean->id ?? '') . ': ' . $e->getMessage());
        }
    }

    /**
     * Fill an EMPTY erp_reference and an EMPTY bd_project_id on an ADM quote
     * that has not reached the ERP yet, and (G809, $isCreate only) an EMPTY
     * Lead Source, Lead Type and Campaign + Event pair from the account's
     * newest quote that holds them. Returns the names of the fields set.
     *
     * The exits are ordered cheapest first, so a quote this cannot touch
     * loads no record:
     *   1. the quote already has an ERP number (its values are the ERP's);
     *   2. every value it could fill is already set (nothing to default);
     *   3. no company has published BdLeadSources at all - every non-Bench
     *      tenant, and a Bench tenant before its code lists land (one query per
     *      request, cached);
     *   4. ERP-Epicor's ErpQuoteFacts is not there (an older ERP-Epicor), logged;
     *   5. the quote's company is not an ADM company (this reads the account).
     * Never overwrites what a seller typed; never touches another company's quote.
     */
    public static function applyDefaults($bean, bool $isCreate = false): array
    {
        $set = array();
        if (trim((string) ($bean->erp_display_sync_key ?? '')) !== '') {
            return $set;
        }
        $needsReference = trim((string) ($bean->erp_reference ?? '')) === '';
        $needsProject = trim((string) ($bean->bd_project_id ?? '')) === '';
        $needsHistory = $isCreate && self::historyWanted($bean);
        if (!$needsReference && !$needsProject && !$needsHistory) {
            return $set;
        }
        if (self::admCompanies() === array()) {
            return $set;
        }
        if (!self::quoteFactsAvailable()) {
            $GLOBALS['log']->error('BenchDogs-Ext: ' . self::QUOTE_FACTS_FILE . ' is missing (ERP-Epicor older than'
                . ' the G380 release); ADM quote defaults skipped for quote ' . (string) ($bean->id ?? ''));

            return $set;
        }
        if (!self::isAdmCompany(ErpQuoteFacts::companyCode($bean))) {
            return $set;
        }
        if ($needsReference) {
            $reference = self::defaultReference(
                (string) ($bean->shipping_address_city ?? ''),
                (string) ($bean->shipping_address_state ?? ''),
                self::referenceMaxLength($bean)
            );
            if ($reference !== '') {
                $bean->erp_reference = $reference;
                $set[] = 'erp_reference';
            }
        }
        if ($needsProject) {
            $project = self::defaultProject(self::lineGroups($bean));
            if ($project !== '') {
                $bean->bd_project_id = $project;
                $set[] = 'bd_project_id';
            }
        }
        if ($needsHistory) {
            $set = array_merge($set, self::applyAccountHistory($bean));
        }

        return $set;
    }

    // ── G809: defaults from the account's newest quote ─────────────────────

    /** True when this quote has an account and an EMPTY field the history can fill. */
    private static function historyWanted($bean): bool
    {
        if (trim((string) ($bean->billing_account_id ?? '')) === '') {
            return false;
        }
        foreach (array_keys(self::ACCOUNT_HISTORY_FIELDS) as $field) {
            if (self::text($bean, $field) === '') {
                return true;
            }
        }

        return self::text($bean, self::FIELD_EVENT) === '';
    }

    /**
     * Fill the EMPTY history fields of a new quote. Each single picker from the
     * newest quote of the account that holds it; the Campaign + Event pair from
     * the newest quote that holds BOTH, only when that event is that campaign's,
     * and: both when both are empty, the event alone when the campaign already
     * is that quote's campaign, nothing when the seller chose another campaign
     * (or only an event - one is never derived from the other). A value the
     * picker no longer offers (an inactive code) is not copied.
     *
     * @return string[] the fields set
     */
    public static function applyAccountHistory($bean): array
    {
        $set = array();
        $account = trim((string) ($bean->billing_account_id ?? ''));
        if ($account === '') {
            return $set;
        }
        $self = (string) ($bean->id ?? '');
        foreach (self::ACCOUNT_HISTORY_FIELDS as $field => $type) {
            if (self::text($bean, $field) !== '') {
                continue;
            }
            $row = self::newestQuoteHolding($account, array($field), $self);
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '' && self::offered($value, self::lookupOptions($type))) {
                $bean->$field = $value;
                $set[] = $field;
            }
        }
        if (self::text($bean, self::FIELD_EVENT) !== '') {
            return $set;
        }
        $row = self::newestQuoteHolding($account, array(self::FIELD_CAMPAIGN, self::FIELD_EVENT), $self);
        $pair = self::pairToCopy(
            self::text($bean, self::FIELD_CAMPAIGN),
            trim((string) ($row[self::FIELD_CAMPAIGN] ?? '')),
            trim((string) ($row[self::FIELD_EVENT] ?? ''))
        );
        if ($pair === array()) {
            return $set;
        }
        if (!self::offered($pair[0], self::marketingOptions(self::TYPE_MARKETING_CAMPAIGNS))
            || !self::offered($pair[1], self::marketingOptions(self::TYPE_MARKETING_EVENTS))) {
            return $set;
        }
        if (self::text($bean, self::FIELD_CAMPAIGN) === '') {
            $bean->{self::FIELD_CAMPAIGN} = $pair[0];
            $set[] = self::FIELD_CAMPAIGN;
        }
        $bean->{self::FIELD_EVENT} = $pair[1];
        $set[] = self::FIELD_EVENT;

        return $set;
    }

    /**
     * The pure half of the pair rule: [campaign, event] to copy from the
     * account's quote, or [] when nothing may be copied. The event must be
     * the campaign's (split at the LAST separator, eventCampaign()); a quote
     * whose campaign the seller did not choose is never mixed in.
     *
     * @return string[]
     */
    public static function pairToCopy(string $current, string $campaign, string $event): array
    {
        $parsed = self::eventCampaign($event);
        if ($campaign === '' || $parsed === null || $parsed[0] !== $campaign) {
            return array();
        }
        if (trim($current) !== '' && trim($current) !== $campaign) {
            return array();
        }

        return array($campaign, trim($event));
    }

    /**
     * The newest quote (date_entered) of this billing account, other than
     * $exceptId, whose $fields are all non-empty, as a row of those fields, or
     * null. One query. Team security applies: it copies only from a quote the
     * saving user may see, as the browser's read does.
     */
    public static function newestQuoteHolding(string $accountId, array $fields, string $exceptId): ?array
    {
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('Quotes'));
        $query->select(array_merge(array('id'), $fields));
        $where = $query->where()->equals('billing_account_id', $accountId);
        foreach ($fields as $field) {
            $where->isNotEmpty($field);
        }
        if ($exceptId !== '') {
            $where->notEquals('id', $exceptId);
        }
        $query->orderBy('date_entered', 'DESC');
        $query->limit(1);
        $rows = $query->execute();

        return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    /** Does this picker list offer $value (a real, non-blank option)? */
    private static function offered(string $value, array $options): bool
    {
        return $value !== '' && array_key_exists($value, $options);
    }

    /** A bean field as trimmed text ('' when unset). */
    private static function text($bean, string $field): string
    {
        return trim((string) ($bean->$field ?? ''));
    }

    /** The product group of every live line on the quote, in line order. */
    private static function lineGroups($bean): array
    {
        if (empty($bean->id) || !$bean->load_relationship('products')) {
            return array();
        }
        $groups = array();
        $lines = $bean->products->getBeans(array('limit' => self::MAX_LINES_SCANNED + 1));
        if (count($lines) > self::MAX_LINES_SCANNED) {
            return array();
        }
        foreach ($lines as $line) {
            if (!empty($line->deleted)) {
                continue;
            }
            $groups[] = ErpQuoteFacts::productGroup($line);
        }

        return $groups;
    }

    // ── G804: the Account's Cust. Group ──────────────────────────────────────

    /**
     * Logic hook entry point (before_save on Accounts). On an Account NOT in
     * the ERP whose Group Code the seller picked (bd_customer_group_code, the
     * code the connector extension sends as Customer.GroupCode, G804), keep the
     * readable Cust. Group (bd_customer_group) in step: the group's name from
     * ADM's own list, else the code itself (the extension's rule for the same
     * field: never a fabricated name). A cleared pick clears the name.
     *
     * An Account the ERP holds is left alone: the extension's erp_customers
     * sweep writes both fields from Epicor, and the picker is read-only there.
     * Only a CHANGED code is acted on (a create that sets one, or an update
     * that changes it), so a save that does not touch the group reads nothing.
     * Fails open and logs, like the quote defaults: a save never fails for it.
     */
    public function accountBeforeSave($bean, $event, $arguments): void
    {
        try {
            self::applyCustomerGroupName($bean, is_array($arguments) && !empty($arguments['isUpdate']));
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: Cust. Group name failed for account '
                . (string) ($bean->id ?? '') . ': ' . $e->getMessage());
        }
    }

    /**
     * The body of accountBeforeSave(). Returns true when it set the name.
     */
    public static function applyCustomerGroupName($bean, bool $isUpdate): bool
    {
        if (trim((string) ($bean->erp_sync_key ?? '')) !== ''
            || trim((string) ($bean->erp_display_sync_key ?? '')) !== '') {
            return false;
        }
        $code = trim((string) ($bean->{self::FIELD_GROUP_CODE} ?? ''));
        $fetched = is_array($bean->fetched_row ?? null) ? $bean->fetched_row : array();
        $before = trim((string) ($fetched[self::FIELD_GROUP_CODE] ?? ''));
        if ($isUpdate ? $code === $before : $code === '') {
            return false;
        }
        $bean->{self::FIELD_GROUP_NAME} = $code === '' ? '' : self::customerGroupName($code);

        return true;
    }

    /** The name of ADM's customer group $code, or the code itself when unknown. */
    public static function customerGroupName(string $code): string
    {
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('ERP_LookupValues'), array('team_security' => false));
        $query->select(array('name'));
        $query->where()->equals('type', self::TYPE_CUSTOMER_GROUPS)->equals('erp_display_sync_key', $code);
        $query->limit(1);
        $rows = $query->execute();
        $name = is_array($rows) && isset($rows[0]['name']) ? trim((string) $rows[0]['name']) : '';

        return $name !== '' ? $name : $code;
    }

    // ── the pickers' options ─────────────────────────────────────────────────

    /**
     * Options for one pick list: '' first, then every ACTIVE row of the type,
     * keyed by its ERP code (what ADM stores) and labelled "CODE - Name".
     */
    public static function lookupOptions(string $type): array
    {
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('ERP_LookupValues'), array('team_security' => false));
        $query->select(array('erp_display_sync_key', 'name'));
        $query->where()->equals('type', $type)->equals('is_active', 1);
        $query->orderBy('name', 'ASC');

        return self::optionsFromRows($query->execute());
    }

    /** The pure half of lookupOptions(), so it can be tested without a database. */
    public static function optionsFromRows(array $rows): array
    {
        $options = array('' => '');
        foreach ($rows as $row) {
            $code = trim((string) ($row['erp_display_sync_key'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $options[$code] = ($name === '' || $name === $code) ? $code : $code . ' - ' . $name;
        }

        return $options;
    }

    // ── G460: the marketing pickers ─────────────────────────────────────────

    /**
     * Options for the Marketing Campaign or the Marketing Event picker: '' first,
     * then the ACTIVE rows of that type that can form an ACTIVE PAIR - a
     * campaign is offered only when at least one active event of it is, and an
     * event only when its campaign is. Two queries (one per type), across teams.
     *
     * WHY THE PAIRING. ADM refuses a quote without a valid campaign AND event
     * (G460). Of ADM's 42 active campaigns, 25 have NO active event (measured
     * 2026-09-25): offering one of those would let the seller pick a campaign no
     * event can complete. The list is every usable event, keyed and labelled
     * with its campaign; since G571 ERP-Core's erp-dependent-enum shows only the
     * chosen campaign's events in the browser (the vardef's erp_lookup_parent),
     * and the connector extension still refuses a pair that does not match.
     */
    public static function marketingOptions(string $type): array
    {
        return self::marketingOptionsFromRows(
            $type,
            self::activeRows(self::TYPE_MARKETING_CAMPAIGNS),
            self::activeRows(self::TYPE_MARKETING_EVENTS)
        );
    }

    /**
     * [campaign, seq] of an event picker key, or null when it is not one (no
     * separator, no campaign, a seq that is not a positive whole number).
     */
    public static function eventCampaign(string $key): ?array
    {
        $key = trim($key);
        $at = strrpos($key, self::EVENT_KEY_SEPARATOR);
        if ($at === false) {
            return null;
        }
        $campaign = trim(substr($key, 0, $at));
        $seq = trim(substr($key, $at + strlen(self::EVENT_KEY_SEPARATOR)));
        if ($campaign === '' || $seq === '' || !ctype_digit($seq) || (int) $seq < 1) {
            return null;
        }

        return array($campaign, (int) $seq);
    }

    /**
     * The pure half of marketingOptions(), so it can be tested without a
     * database. Campaigns in code order; events by campaign, then seq as a
     * NUMBER (ADM reuses the same descriptions under every campaign, so a name
     * order would interleave them). Labels are "KEY - Name".
     */
    public static function marketingOptionsFromRows(string $type, array $campaignRows, array $eventRows): array
    {
        $campaigns = array();
        foreach ($campaignRows as $row) {
            $code = trim((string) ($row['erp_display_sync_key'] ?? ''));
            if ($code !== '') {
                $campaigns[$code] = trim((string) ($row['name'] ?? ''));
            }
        }
        // Sorted by a composed string key and ksort(): usort() and its kin are
        // on ModuleScanner's blacklist (MLP002 - one call rejects the upload).
        // "<campaign>\0<seq, zero-padded>" orders by campaign exactly as
        // strcmp() does (NUL sorts before any code character), then by seq as
        // a number.
        $events = array();
        foreach ($eventRows as $row) {
            $key = trim((string) ($row['erp_display_sync_key'] ?? ''));
            $pair = self::eventCampaign($key);
            if ($pair === null || !array_key_exists($pair[0], $campaigns)) {
                continue;
            }
            $events[$pair[0] . "\0" . sprintf('%010d', $pair[1])] =
                array($pair[0], $pair[1], $key, trim((string) ($row['name'] ?? '')));
        }
        ksort($events, SORT_STRING);

        $options = array('' => '');
        if ($type === self::TYPE_MARKETING_EVENTS) {
            foreach ($events as $event) {
                $options[$event[2]] = self::label($event[2], $event[3]);
            }
        } elseif ($type === self::TYPE_MARKETING_CAMPAIGNS) {
            $usable = array();
            foreach ($events as $event) {
                $usable[$event[0]] = true;
            }
            ksort($campaigns, SORT_STRING);
            foreach ($campaigns as $code => $name) {
                if (isset($usable[$code])) {
                    $options[$code] = self::label((string) $code, $name);
                }
            }
        }

        return $options;
    }

    /** "CODE - Name", or the code alone when there is no other name. */
    private static function label(string $code, string $name): string
    {
        return ($name === '' || $name === $code) ? $code : $code . ' - ' . $name;
    }

    /** The active rows of one type (display key + name), across teams. */
    private static function activeRows(string $type): array
    {
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('ERP_LookupValues'), array('team_security' => false));
        $query->select(array('erp_display_sync_key', 'name'));
        $query->where()->equals('type', $type)->equals('is_active', 1);

        return $query->execute();
    }

    private static function appList(string $name): array
    {
        global $app_list_strings;
        $list = is_array($app_list_strings) ? ($app_list_strings[$name] ?? null) : null;
        if (!is_array($list)) {
            $strings = return_app_list_strings_language($GLOBALS['current_language'] ?? 'en_us');
            $list = is_array($strings) ? ($strings[$name] ?? array()) : array();
        }

        return is_array($list) ? $list : array();
    }
}
