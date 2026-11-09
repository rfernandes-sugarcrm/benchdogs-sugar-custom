<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

use BeanFactory;
use Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts;

/** G860 (🔒2179b): ADM's Ship Via requirement, named in ERP-Epicor's one pre-send order refusal; the Sugar half of the connector extension's G829/G852 rule. */
class BdAdmOrderRequirements
{
    public const SHORT_NO_SHIP_VIA = 'The quote has no Ship Via';

    /**
     * ERP-Epicor's OrderRequirements answer: the Ship Via an order for an ADM quote would reach ADM without, else [].
     * ADM fills a blank one from the ERP quote an order converts, else (a Sales Order) from the customer's default, kept on the billing account's Ship Via.
     * A failed read throws: the adapter answers nothing missing and the connector extension checks again before the send.
     * @param SugarBean $quote
     * @param SugarBean[] $lines the lines this order carries
     * @return array<int, array{error: string, message: string}>
     */
    public static function missing($quote, array $lines): array
    {
        if ($lines === array() || self::quoteShipViaCode($quote) !== '') {
            return array();
        }
        if (BdAdmRules::admCompanies() === array() || !BdAdmRules::isAdmCompany(ErpQuoteFacts::companyCode($quote))) {
            return array();
        }
        $erpQuote = self::convertedErpQuote($lines);
        if ($erpQuote !== 0) {
            return array(array('error' => self::SHORT_NO_SHIP_VIA, 'message' => self::noShipViaSentence($erpQuote)));
        }
        if (self::customerShipViaCode($quote) !== '') {
            return array();
        }

        return array(array('error' => self::SHORT_NO_SHIP_VIA, 'message' => self::noCustomerShipViaSentence()));
    }

    /** The seller's sentence, in the shape of ERP-Epicor's FOB refusal and the extension's G852 words. */
    public static function noShipViaSentence(int $erpQuote): string
    {
        return 'This quote has no Ship Via and its ERP quote ' . $erpQuote . ' has none either, so it cannot be '
            . 'ordered. Pick a Ship Via on the quote, then submit again. Nothing was sent to the ERP.';
    }

    /** The seller's sentence for an order that takes the customer's Ship Via, in the words of ERP-Epicor's FOB refusal. */
    public static function noCustomerShipViaSentence(): string
    {
        return 'This quote has no Ship Via and its customer has no default Ship Via in the ERP, so it cannot be '
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

        return self::lookupValueCode($id);
    }

    /**
     * The ERP code of the customer's default Ship Via: the billing account's Ship Via, which the connector's account
     * sync sets from the ERP customer's Ship Via (and clears when the customer has none). '' when it has none.
     */
    public static function customerShipViaCode($quote): string
    {
        $accountId = trim((string) ($quote->billing_account_id ?? ''));
        $account = $accountId === '' ? null : BeanFactory::retrieveBean('Accounts', $accountId);
        if (!$account || empty($account->id)) {
            return '';
        }
        $id = '';
        if ($account->load_relationship('erp_ship_via') && is_object($account->erp_ship_via ?? null)) {
            $stored = $account->erp_ship_via->get();
            $id = is_array($stored) && $stored !== array() ? (string) reset($stored) : '';
        } else {
            $id = (string) ($account->erp_ship_viaerp_lookupvalues_idb ?? '');
        }

        return self::lookupValueCode($id);
    }

    /** The ERP code of an ERP_LookupValues row, '' for no id, a row that cannot be found, or a row with no code. */
    private static function lookupValueCode(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        $row = BeanFactory::retrieveBean('ERP_LookupValues', $id);

        return ($row && !empty($row->id)) ? trim((string) ($row->erp_display_sync_key ?? '')) : '';
    }
}
