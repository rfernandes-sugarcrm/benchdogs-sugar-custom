<?php

/**
 * before_save hook class for Accounts - see the registration in
 * custom/Extension/modules/Accounts/Ext/LogicHooks/erp_account_country_guard.php
 * for why this class lives here and not alongside that registration.
 *
 * REQ-15: Sugar refuses to save an Account whose billing country no ERP country
 * matches, instead of accepting it and leaving the country write-back to skip it
 * silently in a run log.
 *
 * USER DECISION 62 - "put it in core so you done need it in custom". This guard
 * shipped first in the customer package (a customer package's own country guard, 0.9.42-rc19)
 * and was live-proven there; it now lives in the shared ERP-Core overlay, which
 * every profile installs, so the stock profile refuses an unmappable country
 * too. That SUPERSEDES decision 15's Bench-only placement for this guard.
 *
 * "Known" is defined by the ERP, never by this package: one ERP_LookupValues row
 * per ERP country, whose description lists every normalized spelling the
 * connector's own country resolution accepts (the ERP Description, the ISO code,
 * and each alias). normalize() below is the same transformation as the
 * connector's _norm_country, so what this guard accepts is exactly what the
 * connector resolves.
 *
 * WHICH lookup types carry countries is data, not logic: the app list
 * erp_country_lookup_type_list names them. Core publishes and registers
 * 'Country'; a customer package that already publishes its own country rows
 * appends its type in one line of its own language fragment, and keeps running
 * THIS class. Nothing about a customer's type is known here.
 *
 * Deliberately narrow:
 *  - only a CHANGED, non-empty billing country is checked; an unrelated edit to
 *    an Account that already holds an unknown country is never blocked;
 *  - the connector's own writes (platform sugarai_erp_connector) are never
 *    blocked - they carry the ERP's own country text;
 *  - while no country rows exist (the publishing sync has not run yet) it logs
 *    and allows the save rather than blocking every Account edit on an empty
 *    list;
 *  - an infrastructure error while reading the list is logged and fails open.
 * The refusal is SugarApiExceptionInvalidParameter: the one exception
 * LogicHook passes through to the REST caller (HTTP 422) instead of swallowing.
 */
class ErpAccountCountryGuard
{
    const CONNECTOR_PLATFORM = 'sugarai_erp_connector';

    /** App list naming every ERP_LookupValues type that carries countries. */
    const TYPE_LIST = 'erp_country_lookup_type_list';

    /** Core's own published type - the fallback if the app list is unreadable. */
    const DEFAULT_TYPE = 'Country';

    public function refuseUnknownCountry(SugarBean $bean, string $event, array $arguments): void
    {
        $refusal = null;
        $canonical = null;
        try {
            $platform = isset($_SESSION['platform']) ? (string) $_SESSION['platform'] : null;
            $newCountry = (string) ($bean->billing_address_country ?? '');
            $oldCountry = (string) ($bean->fetched_row['billing_address_country'] ?? '');
            if (!self::needsCheck($newCountry, $oldCountry, $platform)) {
                return;
            }
            $accepted = static::acceptedSpellings();
            if (empty($accepted)) {
                $GLOBALS['log']->warning(
                    'ErpAccountCountryGuard: no ERP country lookup rows yet; billing country not checked'
                );
                return;
            }
            $refusal = self::refusal($newCountry, $oldCountry, $accepted, $platform);
            if ($refusal === null) {
                $canonical = self::canonicalFor($newCountry, $accepted);
            }
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('ErpAccountCountryGuard: country check skipped: ' . $e->getMessage());
            return;
        }
        if ($refusal !== null) {
            throw new SugarApiExceptionInvalidParameter($refusal);
        }
        if ($canonical !== null) {
            // REQ-15 C3. Accepting an alias and STORING IT VERBATIM left Sugar
            // holding a spelling the ERP never uses - 'U.S.A.', 'usa ',
            // 'united kingdom' - so the alias table was the only thing keeping
            // the record resolvable, and every later reader had to re-run it.
            // The guard already knows the ERP's own spelling at this point; it
            // was simply being discarded. This is a before_save hook, so the
            // assignment is what gets persisted.
            $bean->billing_address_country = $canonical;
        }
    }

    /**
     * The ERP's own spelling for a value this guard just accepted, or null to
     * leave the bean alone.
     *
     * PURE (L-0052), so the decision is testable without a Sugar stack.
     *
     * 🛑 IT RETURNS THE ROW'S `name`, NOT ITS `description`. `description` is
     * the pipe-delimited ALIAS LIST ('USA|US|UNITED STATES|...'); storing it
     * would put that whole string in the field. `name` is the single canonical
     * country as Epicor spells it, and `$accepted` already maps every accepted
     * spelling to exactly that.
     *
     * Returns null when nothing should change: an unmatched value (the caller
     * refuses that separately), an empty name, or a value that is ALREADY the
     * canonical spelling - so an ordinary save of an already-correct country
     * writes nothing.
     *
     * 🔑 This can only ever store a name that came from an ERP row, so C6's
     * "never a guessed id" is untouched: no match, no write.
     *
     * @param array<string,string> $accepted normalized spelling => ERP country name
     */
    public static function canonicalFor(string $newCountry, array $accepted): ?string
    {
        $name = $accepted[self::normalize($newCountry)] ?? null;
        if ($name === null || $name === '' || $name === $newCountry) {
            return null;
        }
        return $name;
    }

    /**
     * Upper-case, punctuation dropped, whitespace collapsed - identical to the
     * connector's _norm_country (connector_core.reference.countries), and to
     * the copy the Bench extension's write-back resolves with.
     */
    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $cleaned = preg_replace('/[^A-Za-z0-9 ]+/', '', $text);
        $cleaned = preg_replace('/\s+/', ' ', (string) $cleaned);
        return strtoupper(trim((string) $cleaned));
    }

    public static function needsCheck(string $newCountry, string $oldCountry, ?string $platform): bool
    {
        if ($platform === self::CONNECTOR_PLATFORM) {
            return false;
        }
        $new = self::normalize($newCountry);
        return $new !== '' && $new !== self::normalize($oldCountry);
    }

    /**
     * Pure decision (L-0052 style): the refusal message, or null to allow.
     *
     * @param array<string,string> $accepted normalized spelling => ERP country name
     */
    public static function refusal(string $newCountry, string $oldCountry, array $accepted, ?string $platform): ?string
    {
        if (!self::needsCheck($newCountry, $oldCountry, $platform) || empty($accepted)) {
            return null;
        }
        if (isset($accepted[self::normalize($newCountry)])) {
            return null;
        }
        $names = array_values(array_unique(array_values($accepted)));
        sort($names);
        return sprintf(
            "Billing country '%s' does not match any country in Epicor. Use one of: %s.",
            $newCountry,
            implode(', ', $names)
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows ERP_LookupValues rows (name, description)
     * @return array<string,string> normalized spelling => ERP country name
     */
    public static function spellingsFrom(array $rows): array
    {
        $out = array();
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            foreach (explode('|', (string) ($row['description'] ?? '')) as $spelling) {
                $key = self::normalize($spelling);
                if ($key !== '' && $name !== '') {
                    $out[$key] = $name;
                }
            }
        }
        return $out;
    }

    /**
     * Every ERP_LookupValues type registered as carrying countries.
     *
     * Read from the app list so a customer package extends it by shipping one
     * more key, with no code here to change and nothing about that package
     * named here.
     *
     * THREE SOURCES, IN ORDER, AND THE REASON FOR THE SECOND. Every path this
     * guard fires on in practice already carries the global: the REST API
     * (which is both the connector's lane and Sidecar's record-view save) gets
     * it from ServiceBase::loadUserEnvironment/loadGuestEnvironment, and the
     * legacy UI from SugarApplication.php - both verified in
     * SugarEnt-Full-26.1.0. A bean saved outside either - a repair script, a
     * cron job, a console command - has no such global, and there the list is
     * loaded on demand rather than skipped: silently narrowing to core's own
     * type would drop a customer package's country rows out of the check and
     * quietly stop refusing on a tenant that used to.
     * Core's own type is the last resort, never "no check at all".
     *
     * @return array<int,string>
     */
    public static function countryTypes(): array
    {
        $declared = $GLOBALS['app_list_strings'][self::TYPE_LIST] ?? null;
        if (!is_array($declared) && function_exists('return_app_list_strings_language')) {
            $language = (string) ($GLOBALS['current_language'] ?? '');
            if ($language === '') {
                $language = (string) ($GLOBALS['sugar_config']['default_language'] ?? 'en_us');
            }
            $loaded = return_app_list_strings_language($language);
            $declared = is_array($loaded) ? ($loaded[self::TYPE_LIST] ?? null) : null;
        }
        $types = array();
        if (is_array($declared)) {
            foreach (array_keys($declared) as $type) {
                $type = trim((string) $type);
                if ($type !== '') {
                    $types[] = $type;
                }
            }
        }
        return $types ?: array(self::DEFAULT_TYPE);
    }

    /**
     * Active lookup rows of the registered country types, read without team
     * security: every user's save is checked against the same ERP list.
     *
     * @param array<int,string> $types
     * @return array<int,array<string,mixed>>
     */
    protected static function lookupRows(array $types): array
    {
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('ERP_LookupValues'), array('team_security' => false));
        $query->select(array('name', 'description'));
        $query->where()->in('type', $types)->equals('is_active', 1);
        return $query->execute();
    }

    /**
     * @return array<string,string>
     */
    protected static function acceptedSpellings(): array
    {
        return self::spellingsFrom(static::lookupRows(static::countryTypes()));
    }
}
