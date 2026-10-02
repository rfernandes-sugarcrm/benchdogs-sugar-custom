<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

use BeanFactory;
use Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts;

/** G860 (🔒2179b): ADM's Ship Via requirement, named in ERP-Epicor's one pre-send order refusal; the Sugar half of the connector extension's G829/G852 rule. */
class BdAdmOrderRequirements
{
    public const SHORT_NO_SHIP_VIA = 'The quote has no Ship Via';

    /**
     * ERP-Epicor's OrderRequirements answer: the Ship Via an order converting an ADM ERP quote would reach ADM without, else [].
     * A Sales Order (no converted line) is not judged: Sugar does not hold the customer's default Ship Via, and the extension checks it before the send.
     * @param SugarBean $quote
     * @param SugarBean[] $lines the lines this order carries
     * @return array<int, array{error: string, message: string}>
     */
    public static function missing($quote, array $lines): array
    {
        $erpQuote = self::convertedErpQuote($lines);
        if ($erpQuote === 0 || self::quoteShipViaCode($quote) !== '') {
            return array();
        }
        if (BdAdmRules::admCompanies() === array() || !BdAdmRules::isAdmCompany(ErpQuoteFacts::companyCode($quote))) {
            return array();
        }

        return array(array('error' => self::SHORT_NO_SHIP_VIA, 'message' => self::noShipViaSentence($erpQuote)));
    }

    /** The seller's sentence, in the shape of ERP-Epicor's FOB refusal and the extension's G852 words. */
    public static function noShipViaSentence(int $erpQuote): string
    {
        return 'This quote has no Ship Via and its ERP quote ' . $erpQuote . ' has none either, so it cannot be '
            . 'ordered. Pick a Ship Via on the quote, then submit again. Nothing was sent to the ERP.';
    }

    /**
     * The ERP quote the first converted line names, 0 when no line converts one: core converts a line whose erp_sync_key is <CO>__<QuoteNum>_<QuoteLine>_<QtyNum>.
     * @param SugarBean[] $lines
     */
    public static function convertedErpQuote(array $lines): int
    {
        foreach ($lines as $line) {
            $link = is_object($line) ? self::quoteLineLink((string) ($line->erp_sync_key ?? '')) : null;
            if ($link !== null) {
                return $link[0];
            }
        }

        return 0;
    }

    /**
     * [QuoteNum, QuoteLine] of a rung key, null when it is not one (the same test as core's parse_rung_key).
     * @return int[]|null
     */
    public static function quoteLineLink(string $key): ?array
    {
        $key = trim($key);
        $at = strpos($key, '__');
        if ($at === false || $at === 0) {
            return null;
        }
        $parts = explode('_', substr($key, $at + 2));
        if (count($parts) !== 3) {
            return null;
        }
        foreach ($parts as $part) {
            if ($part === '' || !ctype_digit($part)) {
                return null;
            }
        }
        if ((int) $parts[0] < 1 || (int) $parts[1] < 1) {
            return null;
        }

        return array((int) $parts[0], (int) $parts[1]);
    }

    /** The ERP code of the quote's Ship Via (the read-back of its ERP quote's ShipViaCode), '' when it has none. */
    public static function quoteShipViaCode($quote): string
    {
        $id = '';
        if ($quote->load_relationship('erp_quotes_ship_via') && is_object($quote->erp_quotes_ship_via ?? null)) {
            $stored = $quote->erp_quotes_ship_via->get();
            $id = is_array($stored) && $stored !== array() ? (string) reset($stored) : '';
        } else {
            $id = (string) ($quote->erp_quotes_ship_viaerp_lookupvalues_idb ?? '');
        }
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        $row = BeanFactory::retrieveBean('ERP_LookupValues', $id);

        return ($row && !empty($row->id)) ? trim((string) ($row->erp_display_sync_key ?? '')) : '';
    }
}
