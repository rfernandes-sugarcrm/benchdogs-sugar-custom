<?php

namespace Sugarcrm\Sugarcrm\custom\Erp;

use BeanFactory;

/**
 * G380 / G381 (🔒 1724b) — WHAT ERP-EPICOR SENDS ABOUT A QUOTE, ASKABLE BY ANYONE.
 *
 * The public, one-implementation answers to "which ERP company is this quote
 * for?", "which Epicor PartNum will this line go out as?" and "which Epicor
 * product group (ProdCode) is this line's part in?". QuotesErpActionsApi builds
 * its write-back payload FROM these functions (getQuoteRecord() sends
 * `company` and each line's `part_num` through them), so a caller that asks
 * here cannot disagree with what is sent.
 *
 * 📌 WHY THIS FILE EXISTS: THE DRIFT HAD ALREADY HAPPENED. The Bench Dogs
 * package copied both rules into its own BdAdmRules (companyOf, partNumberOf)
 * with a comment saying they "MUST match" getQuoteRecord(). By the time it was
 * read (footprint item SB8), they did not: partNumberOf had no G379 fallback
 * (a catalog row with no erp_display_sync_key but a scoped erp_sync_key
 * `ADM__49000450` is sent as `49000450`; the copy called it part-less), and
 * companyOf fell back to the account's erp_sync_key prefix, which the payload
 * never does. A rule written twice drifts; this is the one copy.
 *
 * Contract (lane F, g380-contract.md §2(g)):
 *   - never throws on MISSING data - '' is the answer for "unknown";
 *   - a Sugar failure (a read that throws) propagates - callers decide;
 *   - parameters are untyped on purpose: callers hand in beans, and the
 *     package's own test doubles are not SugarBean subclasses.
 *
 * Scanner-safe: no dynamic dispatch, no include-path lookups, no globbing.
 * Loaded by QuotesErpActionsApi::loadQuoteFacts() from its fixed position
 * (custom/modules/Quotes/ErpQuoteFacts.php); another package loads it by that
 * one literal path, guarded by class_exists(ErpQuoteFacts::class) (MLP001).
 */
class ErpQuoteFacts
{
    /**
     * G455 — the two ERP lookups billing_account_name's populate_list copies
     * from the Account onto a Quote besides its company
     * (ERP-Epicor Ext/Vardefs/billing_account_name_populate_erp_lookups.php):
     * the Account's relate id field => [the Quote's link, its relate id field,
     * its relate name field]. Read by ErpQuoteCompanyFollowsAccount, which gives
     * a quote created without the browser autofill the same fields.
     */
    const ACCOUNT_LOOKUPS_ON_QUOTE = array(
        'erp_billing_termserp_lookupvalues_idb' => array(
            'erp_quotes_billing_terms',
            'erp_quotes_billing_termserp_lookupvalues_idb',
            'erp_quotes_billing_terms_name',
        ),
        'erp_foberp_lookupvalues_idb' => array(
            'erp_quotes_fob',
            'erp_quotes_foberp_lookupvalues_idb',
            'erp_quotes_fob_name',
        ),
    );

    /**
     * The quote's ERP company code (e.g. "EPIC06", "ADM"), '' when unknown.
     *
     * Exactly what getQuoteRecord() sends as `company`: the billing account's
     * erp_companies_accounts relate to ERP_Companies, read as that record's
     * erp_sync_key. There is NO fallback to the account's own erp_sync_key
     * prefix - the payload has none, and a helper that answered where the
     * payload is silent would be a second rule.
     *
     * @param SugarBean $quote
     */
    public static function companyCode($quote): string
    {
        if (!is_object($quote) || empty($quote->billing_account_id)) {
            return '';
        }
        $account = BeanFactory::retrieveBean('Accounts', $quote->billing_account_id);

        return self::companyOfAccount($account ?: null);
    }

    /**
     * The ERP company code of an Account, '' when it has no company relate or
     * the company record cannot be found. The body of what used to be
     * QuotesErpActionsApi::resolveErpCompanyCode(), unchanged: that method now
     * delegates here. The record is companyRecordOfAccount()'s - one read.
     *
     * @param SugarBean|null $account
     */
    public static function companyOfAccount($account): string
    {
        $company = self::companyRecordOfAccount($account);

        return $company ? (string) ($company->erp_sync_key ?? '') : '';
    }

    /**
     * G435 - the Account's ERP_Companies RECORD (the erp_companies_accounts
     * relate), null when it has none or the record cannot be found (a deleted
     * company is not found). companyOfAccount() is this record's erp_sync_key,
     * so the company a new quote is linked to at creation
     * (ErpQuoteCompanyFollowsAccount) and the company its write-back payload
     * names can never be two answers.
     *
     * @param SugarBean|null $account
     * @return SugarBean|null
     */
    public static function companyRecordOfAccount($account)
    {
        if (!$account || empty($account->erp_companies_accountserp_companies_ida)) {
            return null;
        }

        $company = BeanFactory::retrieveBean('ERP_Companies', $account->erp_companies_accountserp_companies_ida);

        return $company ?: null;
    }

    /**
     * G455 — the ERP_LookupValues RECORD (payment terms, FOB) an Account's
     * relate id field names, null when the Account has none or the record is
     * deleted or cannot be found. The same rule as companyRecordOfAccount():
     * the account's own value or nothing, never a default.
     *
     * @param SugarBean|null $account
     * @param string $idField one of ACCOUNT_LOOKUPS_ON_QUOTE's keys
     * @return SugarBean|null
     */
    public static function lookupRecordOfAccount($account, string $idField)
    {
        $id = $account ? trim((string) ($account->{$idField} ?? '')) : '';
        if ($id === '') {
            return null;
        }
        $lookup = BeanFactory::retrieveBean('ERP_LookupValues', $id);

        return ($lookup && !empty($lookup->id) && empty($lookup->deleted)) ? $lookup : null;
    }

    /**
     * G474 — the five address lines CORE-ShippingAddresses' populate_list copies
     * from a picked ShippingAddress onto the quote (same names on both modules).
     */
    const SHIP_TO_ADDRESS_FIELDS = array(
        'shipping_address_street',
        'shipping_address_city',
        'shipping_address_state',
        'shipping_address_postalcode',
        'shipping_address_country',
    );

    /**
     * The Account's ship-tos that are candidates at all: linked through
     * shipping_addresses_accounts, not deleted, not inactive (Epicor's own flag
     * on the ship-to). [] when the link is not there (CORE-ShippingAddresses
     * not installed).
     *
     * @param SugarBean|null $account
     * @return SugarBean[]
     */
    public static function activeShipTosOfAccount($account): array
    {
        if (!$account || !$account->load_relationship('shipping_addresses_accounts')) {
            return array();
        }
        $link = $account->shipping_addresses_accounts ?? null;
        if (!is_object($link)) {
            return array();
        }

        $active = array();
        foreach ($link->getBeans() as $address) {
            if (!is_object($address) || !empty($address->deleted) || !empty($address->inactive)) {
                continue;
            }
            $active[] = $address;
        }

        return $active;
    }

    /**
     * The Ship To a new quote on this Account gets, or null for the seller to
     * pick. Only ACTIVE ship-tos are candidates (activeShipTosOfAccount()).
     *   1. The ONE active address carrying the ERP's own default flag
     *      (erp_primary_ship_to = Epicor Customer.ShipToNum) - G223 / G311.
     *   2. G748 (owner, 2026-09-28: "it's supposed to take the single shipping
     *      address and put it in"): with no flagged one, the account's ONE
     *      active address.
     *   3. Otherwise - none, several with no flag, two flagged - null: picking
     *      the first of several is a fabricated default on a customer
     *      document, and two flags are the ERP contradicting itself.
     * Read by both writers of a new quote's Ship To: AccountsErpActionsApi's
     * Account button and ErpQuoteShipToFollowsAccount's before_save fill.
     *
     * 🚩 RULE 2 OVERRIDES G311, WHICH HAD REMOVED IT, AND ITS RISK IS NOT GONE
     * EVERYWHERE. G311 measured (EPIC06, 2026-09-23) that a lone address is
     * "the only one Sugar holds", not always "the one Epicor names": on Bench,
     * before Epicor's sold-to rows were mirrored, it picked a different
     * physical address from Epicor's default on 11 of 15 single-address
     * customers. Rule 1 still decides wherever Epicor's default row IS
     * mirrored and flagged (since G310 the sold-to is mirrored as `..._@self`
     * and flagged; stock then agreed 15 of 15), because a flagged row makes
     * two candidates, not one. Where the default row is NOT mirrored - a
     * tenant whose flags no full read has written, or an `@self` row with no
     * street and city - rule 2 takes the lone other address, as G311 found.
     * Nothing in the data tells those apart without a new field, and the
     * owner has ruled that the single address is taken.
     *
     * @param SugarBean|null $account
     * @return SugarBean|null
     */
    public static function defaultShipToOfAccount($account)
    {
        $active = array();
        $flagged = array();
        foreach (self::activeShipTosOfAccount($account) as $address) {
            $active[] = $address;
            if (!empty($address->erp_primary_ship_to)) {
                $flagged[] = $address;
            }
        }
        if (count($flagged) === 1) {
            return $flagged[0];
        }
        // (A lone active address that IS flagged was answered just above.)
        if (count($active) === 1) {
            return $active[0];
        }

        return null;
    }

    /**
     * The Epicor PartNum core will be sent for this quote line, '' when none.
     *
     * Exactly getQuoteRecord()'s `part_num`, which is built by calling this:
     *   - a catalog line (product_template_id set): its template's part number
     *     (templatePartNumber());
     *   - a free-text line: its own mft_part_num, AS STORED - not trimmed,
     *     because the payload has always sent it untrimmed and "one
     *     implementation" must not quietly change what EPIC06 receives.
     * A caller asking "does this line have a part number?" trims the answer.
     *
     * @param SugarBean $line a Products bean
     */
    public static function partNumber($line): string
    {
        if (!is_object($line)) {
            return '';
        }
        if (!empty($line->product_template_id)) {
            return self::templatePartNumber((string) $line->product_template_id);
        }

        return (string) ($line->mft_part_num ?? '');
    }

    /**
     * A catalog product's Epicor PartNum, '' when the product is missing or
     * carries no key. The body of what used to be
     * QuotesErpActionsApi::getProductErpSyncKey(), unchanged: that method now
     * delegates here.
     *
     * G379: when the catalog row carries no erp_display_sync_key (the raw
     * PartNum) but does carry its scoped key `<COMPANY>__<PartNum>`
     * (ADM__49000450, EPIC06__BD-ENDCAP-ALU), the PartNum is the part after the
     * company prefix. A seeded row can lack the display key while the catalog
     * sync has not enriched it yet (G382's shape). A stated display key is
     * returned exactly as stored.
     */
    public static function templatePartNumber(string $templateId): string
    {
        $product = BeanFactory::retrieveBean('ProductTemplates', $templateId);
        if ($product) {
            $display = (string) ($product->erp_display_sync_key ?? '');
            if (trim($display) !== '') {
                return $display;
            }
            $scoped = trim((string) ($product->erp_sync_key ?? ''));
            $cut = strpos($scoped, '__');

            return $cut === false ? '' : trim(substr($scoped, $cut + 2));
        }

        return '';
    }

    /**
     * The Epicor product group (ProdCode) of a line's catalog part, '' for a
     * free-text line, a part with no category, or a category with no code.
     *
     * The same source core reads for OrderDtl.ProdCode (g380-contract §1b):
     * Products.product_template_id -> ProductTemplates.category_id ->
     * ProductCategories.erp_display_sync_key, which IS the raw ProdCode as the
     * product catalog sync lands it (CMI-211967V3K -> CMI; 49000450 ->
     * DISPLAYS, measured on ADM, 🔒 1710b). Trimmed: a group is a code, and a
     * blank one is no group.
     *
     * @param SugarBean $line a Products bean
     */
    public static function productGroup($line): string
    {
        if (!is_object($line) || empty($line->product_template_id)) {
            return '';
        }
        $template = BeanFactory::retrieveBean('ProductTemplates', (string) $line->product_template_id);
        $categoryId = $template ? trim((string) ($template->category_id ?? '')) : '';
        if ($categoryId === '') {
            return '';
        }
        $category = BeanFactory::retrieveBean('ProductCategories', $categoryId);

        return $category ? trim((string) ($category->erp_display_sync_key ?? '')) : '';
    }

    /**
     * G530 — the most characters the ERP takes in the quote's Reference
     * (Epicor QuoteHed.Reference), 0 when the field states none.
     *
     * Read from the field itself: ERP-Core's erp_reference vardef carries it
     * as `erp_max_length` (10, measured on ADM and in EPIC06's data
     * dictionary - the measurement is recorded on the vardef). The Quotes
     * views read the same attribute from the served metadata, so the save
     * check, Send to Estimation's refusal and a package's default can never
     * be three different numbers. `len` (50) is storage, not the limit.
     *
     * 0 ("not stated") means no limit is enforced here and the ERP answers
     * for itself, exactly as before G530.
     *
     * @param SugarBean|null $quote a Quotes bean (its field_defs); null reads
     *                              the Quote dictionary
     */
    public static function referenceMaxLength($quote = null): int
    {
        $def = null;
        if (is_object($quote) && isset($quote->field_defs) && is_array($quote->field_defs)) {
            $def = $quote->field_defs['erp_reference'] ?? null;
        } elseif (isset($GLOBALS['dictionary']['Quote']['fields']['erp_reference'])) {
            $def = $GLOBALS['dictionary']['Quote']['fields']['erp_reference'];
        }
        $max = is_array($def) ? (int) ($def['erp_max_length'] ?? 0) : 0;

        return $max > 0 ? $max : 0;
    }

    /**
     * G530 — the length the ERP will count for a Reference: characters, not
     * bytes ("MÜNCHEN BY" is 10), of the value as core sends it (trimmed,
     * connector_epicor quote_writeback read_quote_header_extras()).
     */
    public static function referenceLength(string $reference): int
    {
        return mb_strlen(trim($reference), 'UTF-8');
    }

    /**
     * G380 (e): does this quote's ERP company refuse to order a line with no
     * ERP part number? The per-company switch
     * ERP_Companies.erp_order_requires_part_number (ERP-Core, bool, default
     * off). An admin turns it on on ONE company's record; every other company
     * - EPIC06 included - answers false.
     *
     * False when the quote has no billing account, the account has no company
     * relate, or the company record cannot be found: a quote whose company is
     * unknown is not a quote of a company that asked for this rule. A read
     * that THROWS propagates, so the caller can tell "off" from "unreadable".
     *
     * @param SugarBean $quote
     */
    public static function orderRequiresPartNumber($quote): bool
    {
        return self::companySwitchIsOn($quote, 'erp_order_requires_part_number');
    }

    /**
     * G569 (🔒 1912b): does this quote's ERP company refuse to order a quote
     * with no FOB whose customer has no ERP default FOB either? The per-company
     * switch ERP_Companies.erp_order_requires_fob (ERP-Core, bool, default
     * off), read exactly as orderRequiresPartNumber() reads its own.
     *
     * @param SugarBean $quote
     */
    public static function orderRequiresFob($quote): bool
    {
        return self::companySwitchIsOn($quote, 'erp_order_requires_fob');
    }

    /**
     * One read of a per-company ordering switch for both rules, so they can
     * never disagree about which company a quote belongs to or what "on" is.
     *
     * @param SugarBean $quote
     */
    private static function companySwitchIsOn($quote, string $field): bool
    {
        if (!is_object($quote) || empty($quote->billing_account_id)) {
            return false;
        }
        $account = BeanFactory::retrieveBean('Accounts', $quote->billing_account_id);
        if (!$account || empty($account->erp_companies_accountserp_companies_ida)) {
            return false;
        }
        $company = BeanFactory::retrieveBean('ERP_Companies', $account->erp_companies_accountserp_companies_ida);
        if (!$company) {
            return false;
        }

        // Sugar serves a bool as true/false, '1'/'0' or 1/0 depending on the
        // read path; '0' must not read as on.
        return in_array($company->{$field} ?? false, array(true, 1, '1', 'true', 'on'), true);
    }
}
