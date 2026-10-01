<?php

namespace Sugarcrm\Sugarcrm\custom\Erp;

use BeanFactory;
use SugarApiExceptionInvalidParameter;
use SugarBean;
use SugarQuery;

/**
 * before_save hook for Accounts; erp_account_country_guard.php says why. (decision 15, G837)
 */
class ErpAccountCountryGuard
{
    const CONNECTOR_PLATFORM = 'sugarai_erp_connector';

    /** The ERP writers: the connector and the seed loader. */
    const ERP_WRITER_PLATFORMS = array(self::CONNECTOR_PLATFORM, 'epicor_seed');

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
            // Req-15 C3. Accepting an alias and storing it verbatim left Sugar holding a spelling the ERP never uses - 'U.S.A.', 'usa ', 'united kingdom'.
            $bean->billing_address_country = $canonical;
        }
    }

    /**
     * The erp's own spelling for a value this guard just accepted, or null to leave the bean alone.
     * @param array<string,string> $accepted
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
     * Upper-case, punctuation dropped, whitespace collapsed - identical to the connector's _norm_country (connector_core.reference.countries).
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
        if (in_array($platform, self::ERP_WRITER_PLATFORMS, true)) {
            return false;
        }
        $new = self::normalize($newCountry);
        return $new !== '' && $new !== self::normalize($oldCountry);
    }

    /**
     * Pure decision (L-0052 style): the refusal message, or null to allow.
     * @param array<string,string> $accepted
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
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,string>
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
     * Active lookup rows of the registered country types, read without team security: every user's save is checked against the same ERP list.
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

    /** @return array<string,string> */
    protected static function acceptedSpellings(): array
    {
        return self::spellingsFrom(static::lookupRows(static::countryTypes()));
    }
}
