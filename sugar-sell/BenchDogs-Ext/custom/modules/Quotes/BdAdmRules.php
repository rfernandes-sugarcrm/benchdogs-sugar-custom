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

    /** Tenant data (app_list_strings), editable in Admin > Dropdown Editor. */
    public const LIST_PROJECT_BY_GROUP = 'bd_adm_project_by_group_list';

    /** ERP-Epicor's public quote facts (G380 (g)), by its one fixed path. */
    public const QUOTE_FACTS_FILE = 'custom/modules/Quotes/ErpQuoteFacts.php';

    /** A quote with more lines than this is not scanned for a project default. */
    public const MAX_LINES_SCANNED = 500;

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
     * is the ERP's own, as before.
     */
    public static function referenceMaxLength($bean): int
    {
        if (!method_exists('ErpQuoteFacts', 'referenceMaxLength')) {
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
            self::applyDefaults($bean);
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: ADM quote defaults failed for quote '
                . (string) ($bean->id ?? '') . ': ' . $e->getMessage());
        }
    }

    /**
     * Fill an EMPTY erp_reference and an EMPTY bd_project_id on an ADM quote
     * that has not reached the ERP yet. Returns the names of the fields set.
     *
     * The exits are ordered cheapest first, so a quote this cannot touch
     * loads no record:
     *   1. the quote already has an ERP number (its values are the ERP's);
     *   2. both values are already set (nothing to default);
     *   3. no company has published BdLeadSources at all - every non-Bench
     *      tenant, and a Bench tenant before its code lists land (one query per
     *      request, cached);
     *   4. ERP-Epicor's ErpQuoteFacts is not there (an older ERP-Epicor), logged;
     *   5. the quote's company is not an ADM company (this reads the account).
     * Never overwrites what a seller typed; never touches another company's quote.
     */
    public static function applyDefaults($bean): array
    {
        $set = array();
        if (trim((string) ($bean->erp_display_sync_key ?? '')) !== '') {
            return $set;
        }
        $needsReference = trim((string) ($bean->erp_reference ?? '')) === '';
        $needsProject = trim((string) ($bean->bd_project_id ?? '')) === '';
        if (!$needsReference && !$needsProject) {
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

        return $set;
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
