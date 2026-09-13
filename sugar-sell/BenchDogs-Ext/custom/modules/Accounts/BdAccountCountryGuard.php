<?php

/**
 * before_save hook class for Accounts - see the registration in
 * custom/Extension/modules/Accounts/Ext/LogicHooks/bd_account_country_guard.php
 * for why this class lives here and not alongside that registration.
 *
 * Bench Dogs REQ-15, option (c): Sugar refuses to save an Account whose billing
 * country no Epicor country matches, instead of accepting it and leaving the
 * country write-back to skip it silently in a run log.
 *
 * "Known" is defined by Epicor, never by this package: the Bench extension
 * publishes one ERP_LookupValues row per Kinetic country (type bd_country) whose
 * description lists every normalized spelling its country write-back resolves
 * (the Kinetic Description, the ISO code, the Bench aliases). normalize() is the
 * same transformation as connector_ext_benchdogs.reference._norm_country, so
 * what this guard accepts is exactly what the write-back resolves.
 *
 * Deliberately narrow:
 *  - only a CHANGED, non-empty billing country is checked; an unrelated edit to
 *    an Account that already holds an unknown country is never blocked;
 *  - the connector's own writes (platform sugarai_erp_connector) are never
 *    blocked - they carry Kinetic's own country text;
 *  - while no bd_country rows exist (the extension flag is off, or the first
 *    sync has not run) it logs and allows the save rather than blocking every
 *    Account edit on an empty list;
 *  - an infrastructure error while reading the list is logged and fails open.
 * The refusal is SugarApiExceptionInvalidParameter: the one exception
 * LogicHook passes through to the REST caller (HTTP 422) instead of swallowing.
 */
class BdAccountCountryGuard
{
    const CONNECTOR_PLATFORM = 'sugarai_erp_connector';
    const LOOKUP_TYPE = 'bd_country';

    public function refuseUnknownCountry(SugarBean $bean, string $event, array $arguments): void
    {
        $refusal = null;
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
                    'BdAccountCountryGuard: no bd_country lookup rows yet; billing country not checked'
                );
                return;
            }
            $refusal = self::refusal($newCountry, $oldCountry, $accepted, $platform);
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('BdAccountCountryGuard: country check skipped: ' . $e->getMessage());
            return;
        }
        if ($refusal !== null) {
            throw new SugarApiExceptionInvalidParameter($refusal);
        }
    }

    /**
     * Upper-case, punctuation dropped, whitespace collapsed - identical to
     * reference._norm_country in connector_ext_benchdogs.
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
     * @param array<string,string> $accepted normalized spelling => Epicor country name
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
     * @return array<string,string> normalized spelling => Epicor country name
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
     * Active bd_country rows, read without team security: every user's save is
     * checked against the same Epicor list.
     *
     * @return array<string,string>
     */
    protected static function acceptedSpellings(): array
    {
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('ERP_LookupValues'), array('team_security' => false));
        $query->select(array('name', 'description'));
        $query->where()->equals('type', self::LOOKUP_TYPE)->equals('is_active', 1);
        return self::spellingsFrom($query->execute());
    }
}
