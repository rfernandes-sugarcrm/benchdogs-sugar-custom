<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/** G380 / G381 (🔒 1705b, 🔒 1724b): Bench Dogs' ADM rules, the Sugar half; everything generic is ERP-Epicor's or core's (G530, 🔒 1712b, G460, G809). */
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

    /** G460: an event's picker key is "<campaign>/<seq>" (26DISCNV/2), split at the LAST separator - the contract with the connector extension (adm_rules.EVENT_KEY_SEPARATOR), which splits the seller's pick the same way and refuses a pair whose event is not the campaign's. */
    public const EVENT_KEY_SEPARATOR = '/';

    /** Tenant data (app_list_strings), editable in Admin > Dropdown Editor. */
    public const LIST_PROJECT_BY_GROUP = 'bd_adm_project_by_group_list';

    /** ERP-Epicor's public quote facts (G380 (g)), by its one fixed path. */
    public const QUOTE_FACTS_FILE = 'custom/modules/Quotes/ErpQuoteFacts.php';

    /** A quote with more lines than this is not scanned for a project default. */
    public const MAX_LINES_SCANNED = 500;

    /** G809: the single pickers defaulted from the account's newest quote, field => the lookup type whose ACTIVE rows it may take a value from (🔒 1712b). */
    public const ACCOUNT_HISTORY_FIELDS = array(
        'bd_lead_source' => self::TYPE_LEAD_SOURCES,
        'bd_lead_type' => self::TYPE_LEAD_TYPES,
        'bd_project_id' => self::TYPE_PROJECTS,
    );
    public const FIELD_CAMPAIGN = 'bd_marketing_campaign';
    public const FIELD_EVENT = 'bd_marketing_event';

    /** G809: how many of the account's newest quotes holding a field one history read returns; the FIRST whose value is usable wins. */
    public const HISTORY_SCAN = 20;

    /** Upper bound on the BdLeadSources rows read to learn the ADM companies. */
    public const MAX_LEAD_SOURCE_ROWS = 5000;

    /** @var string[]|null the ADM companies, read once per request */
    private static $admCompanies = null;

    // ── which quotes are ADM ─────────────────────────────────────────────────

    /**
     * The ERP companies that have published BdLeadSources rows, upper-cased, read ONCE per request (one query, however many quotes are saved).
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
        // T2 first, AUTOLOADED (see the class docblock): the installed class
        // wins over any leftover old file.
        if (class_exists('Sugarcrm\\Sugarcrm\\custom\\Erp\\ErpQuoteFacts')) {
            return true;
        }
        if (!class_exists('ErpQuoteFacts', false)) {
            // The literal path, as ERP-Epicor documents it (MLP001); keep it
            // equal to QUOTE_FACTS_FILE (bd_adm_rules_test.php pins that).
            @include_once 'custom/modules/Quotes/ErpQuoteFacts.php';
        }

        return class_exists('ErpQuoteFacts', false);
    }

    /**
     * True when the ErpQuoteFacts that answers is T2's namespaced one. Autoload
     * OFF on purpose: quoteFactsAvailable() already asked with it on, and this
     * runs once per quote line.
     */
    private static function factsAreNamespaced(): bool
    {
        return class_exists('Sugarcrm\\Sugarcrm\\custom\\Erp\\ErpQuoteFacts', false);
    }

    /** ErpQuoteFacts::companyCode(), from whichever class is installed. */
    private static function factsCompanyCode($bean): string
    {
        if (self::factsAreNamespaced()) {
            return \Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts::companyCode($bean);
        }

        return ErpQuoteFacts::companyCode($bean);
    }

    /** ErpQuoteFacts::productGroup(), from whichever class is installed. */
    private static function factsProductGroup($line): string
    {
        if (self::factsAreNamespaced()) {
            return \Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts::productGroup($line);
        }

        return ErpQuoteFacts::productGroup($line);
    }

    // ── G380: Reference defaults to the ship-to's city and state ─────────────

    /** "WAYNE NJ": the city and the state, whichever are present, one space apart - SHORTENED to $max characters when it is longer (G530). */
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

    /** G530: the most characters the ERP takes in this quote's Reference, asked of ERP-Epicor (ErpQuoteFacts::referenceMaxLength(), which reads the field's erp_max_length), 0 when that ERP-Epicor is older and does not say - then the default is not cut, and Send to Estimation's answer is the ERP's own, as before. */
    public static function referenceMaxLength($bean): int
    {
        if (self::factsAreNamespaced()) {
            // T2 (#158) is newer than G530, so the method is there.
            return \Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts::referenceMaxLength($bean);
        }
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

    /** The one project the default list gives these product groups, or '' when any group is blank or unmapped, or the groups map to different projects. */
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

    /** Logic hook entry point (before_save on Quotes). */
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

    /** Fill an EMPTY erp_reference and an EMPTY bd_project_id on an ADM quote that has not reached the ERP yet, and (G809, $isCreate only) an EMPTY Lead Source, Lead Type, Project and Campaign + Event pair from the account's newest quote holding a usable value of each. */
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
        if (!self::isAdmCompany(self::factsCompanyCode($bean))) {
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
     * Fill the EMPTY history fields of a new quote, each field on its own: of the account's newest quotes holding that field (HISTORY_SCAN of them, newest first), the FIRST whose value the picker still offers - a code retired since (an inactive lead source, project or campaign) is skipped for the next quote, never copied.
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
            $options = null;
            foreach (self::newestQuotesHolding($account, array($field), $self) as $row) {
                $value = trim((string) ($row[$field] ?? ''));
                $options = $options ?? self::lookupOptions($type);
                if ($value !== '' && self::offered($value, $options)) {
                    $bean->$field = $value;
                    $set[] = $field;
                    break;
                }
            }
        }
        if (self::text($bean, self::FIELD_EVENT) !== '') {
            return $set;
        }
        $current = self::text($bean, self::FIELD_CAMPAIGN);
        $campaigns = null;
        $events = null;
        foreach (self::newestQuotesHolding($account, array(self::FIELD_CAMPAIGN, self::FIELD_EVENT), $self) as $row) {
            $pair = self::pairToCopy(
                $current,
                trim((string) ($row[self::FIELD_CAMPAIGN] ?? '')),
                trim((string) ($row[self::FIELD_EVENT] ?? ''))
            );
            if ($pair === array()) {
                continue;
            }
            $campaigns = $campaigns ?? self::marketingOptions(self::TYPE_MARKETING_CAMPAIGNS);
            $events = $events ?? self::marketingOptions(self::TYPE_MARKETING_EVENTS);
            if (!self::offered($pair[0], $campaigns) || !self::offered($pair[1], $events)) {
                continue;
            }
            if ($current === '') {
                $bean->{self::FIELD_CAMPAIGN} = $pair[0];
                $set[] = self::FIELD_CAMPAIGN;
            }
            $bean->{self::FIELD_EVENT} = $pair[1];
            $set[] = self::FIELD_EVENT;
            break;
        }

        return $set;
    }

    /**
     * The pure half of the pair rule: [campaign, event] to copy from the account's quote, or [] when nothing may be copied.
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
     * The newest quotes (date_entered, newest first, at most HISTORY_SCAN) of this billing account, other than $exceptId, whose $fields are all non-empty, as rows of those fields; [] when there are none.
     * @return array[]
     */
    public static function newestQuotesHolding(string $accountId, array $fields, string $exceptId): array
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
        $query->limit(self::HISTORY_SCAN);
        $rows = $query->execute();
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
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
            $groups[] = self::factsProductGroup($line);
        }

        return $groups;
    }

    // ── G804: the Account's Cust. Group ──────────────────────────────────────

    /** Logic hook entry point (before_save on Accounts) (G804). */
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

    /** Options for the Marketing Campaign or the Marketing Event picker: '' first, then the ACTIVE rows of that type that can form an ACTIVE PAIR - a campaign is offered only when at least one active event of it is, and an event only when its campaign is (G460, G571). */
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

    /** The pure half of marketingOptions(), so it can be tested without a database. */
    public static function marketingOptionsFromRows(string $type, array $campaignRows, array $eventRows): array
    {
        $campaigns = array();
        foreach ($campaignRows as $row) {
            $code = trim((string) ($row['erp_display_sync_key'] ?? ''));
            if ($code !== '') {
                $campaigns[$code] = trim((string) ($row['name'] ?? ''));
            }
        }
        // Sorted by a composed string key and ksort(): usort() and its kin are on ModuleScanner's blacklist (MLP002 - one call rejects the upload).
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
