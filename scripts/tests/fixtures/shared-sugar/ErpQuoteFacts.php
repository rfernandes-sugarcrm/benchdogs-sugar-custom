<?php

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
 * one literal path, guarded by class_exists('ErpQuoteFacts', false) (MLP001).
 */
class ErpQuoteFacts
{
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
     * delegates here.
     *
     * @param SugarBean|null $account
     */
    public static function companyOfAccount($account): string
    {
        if (!$account || empty($account->erp_companies_accountserp_companies_ida)) {
            return '';
        }

        $company = BeanFactory::retrieveBean('ERP_Companies', $account->erp_companies_accountserp_companies_ida);

        return $company ? (string) ($company->erp_sync_key ?? '') : '';
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
        return in_array($company->erp_order_requires_part_number ?? false, array(true, 1, '1', 'true', 'on'), true);
    }
}
