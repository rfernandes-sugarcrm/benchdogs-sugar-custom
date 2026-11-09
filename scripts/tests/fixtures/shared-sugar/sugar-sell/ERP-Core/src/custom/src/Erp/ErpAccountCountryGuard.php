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
    /** The ERP writers: the connector and the seed loader. */
    const ERP_WRITER_PLATFORMS = ErpConnectorSession::ERP_WRITERS;

    /** App list naming every ERP_LookupValues type that carries countries. */
    const TYPE_LIST = 'erp_country_lookup_type_list';

    /** Core's own published type - the fallback if the app list is unreadable. */
    const DEFAULT_TYPE = 'Country';

    public function refuseUnknownCountry(SugarBean $bean, string $event, array $arguments): void
    {
        $refusal = null;
        $canonical = null;
        try {
            $platform = ErpConnectorSession::platform();
            $newCountry = (string) ($bean->billing_address_country ?? '');
            $oldCountry = (string) ($bean->fetched_row['billing_address_country'] ?? '');
            if (!self::needsCheck($newCountry, $oldCountry, $platform)) {
                // The same country retyped in another case or spacing is no new country, but it still takes the ERP's spelling.
                if (self::retypedOnly($newCountry, $oldCountry, $platform)) {
                    $canonical = self::canonicalFor($newCountry, static::acceptedSpellings());
                }
                if ($canonical === null) {
                    return;
                }
                $bean->billing_address_country = $canonical;
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

    /** A seller's edit that changed only the case, spacing or punctuation of the country already on the record. */
    public static function retypedOnly(string $newCountry, string $oldCountry, ?string $platform): bool
    {
        if (in_array($platform, self::ERP_WRITER_PLATFORMS, true) || $newCountry === $oldCountry) {
            return false;
        }
        $new = self::normalize($newCountry);
        return $new !== '' && $new === self::normalize($oldCountry);
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
        // One short sentence: what was typed and a few close names, never the whole list.
        $typed = trim($newCountry);
        $closest = self::closestNames($newCountry, $accepted);
        if ($closest !== array()) {
            return sprintf(
                '"%s" isn\'t a country in Epicor. Did you mean %s? Pick one of Epicor\'s country names and save again.',
                $typed,
                self::orList($closest)
            );
        }
        return sprintf(
            '"%s" isn\'t a country in Epicor. Use the country name Epicor has for it (for example %s) and save again.',
            $typed,
            self::orList(self::exampleNames($accepted))
        );
    }

    /** At most this many names are suggested. */
    const MAX_SUGGESTIONS = 3;

    /** Names offered as examples when nothing typed is close, in this order, if Epicor has them. */
    const EXAMPLE_NAMES = array('United States', 'USA', 'Canada', 'United Kingdom', 'Germany', 'France');

    /**
     * Epicor's names closest to what was typed, best first: about one typing slip per four letters, at most three.
     * @param array<string,string> $accepted
     * @return string[]
     */
    public static function closestNames(string $newCountry, array $accepted): array
    {
        $typed = self::normalize($newCountry);
        if ($typed === '') {
            return array();
        }
        $limit = max(1, intdiv(strlen($typed), 4));
        $best = array();
        foreach ($accepted as $spelling => $name) {
            $distance = levenshtein($typed, (string) $spelling);
            if ($distance <= $limit && (!isset($best[$name]) || $distance < $best[$name])) {
                $best[$name] = $distance;
            }
        }
        // Grouped by distance without a callback sort: every sort taking a callable is on ModuleScanner's deny-list.
        $byDistance = array();
        foreach ($best as $name => $distance) {
            $byDistance[$distance][] = (string) $name;
        }
        ksort($byDistance);
        $ordered = array();
        foreach ($byDistance as $names) {
            sort($names, SORT_STRING);
            $ordered = array_merge($ordered, $names);
        }
        return array_slice($ordered, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Two familiar names Epicor holds, to show the kind of name it expects.
     * @param array<string,string> $accepted
     * @return string[]
     */
    public static function exampleNames(array $accepted): array
    {
        $names = array_values(array_unique(array_values($accepted)));
        $examples = array_values(array_intersect(self::EXAMPLE_NAMES, $names));
        if (count($examples) < 2) {
            sort($names);
            $examples = array_values(array_unique(array_merge($examples, $names)));
        }
        return array_slice($examples, 0, 2);
    }

    /**
     * "A", "A or B", "A, B or C".
     * @param string[] $names
     */
    private static function orList(array $names): string
    {
        if (count($names) < 2) {
            return (string) reset($names);
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' or ' . $last;
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
