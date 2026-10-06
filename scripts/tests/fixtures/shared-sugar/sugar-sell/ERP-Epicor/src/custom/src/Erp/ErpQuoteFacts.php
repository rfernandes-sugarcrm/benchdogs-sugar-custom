<?php

namespace Sugarcrm\Sugarcrm\custom\Erp;

use BeanFactory;

/** G380 / G381 (🔒 1724b) — what ERP-epicor sends about a quote, askable by anyone. (G379) */
class ErpQuoteFacts
{
    /**
     * G455 — the two ERP lookups billing_account_name's populate_list copies from the Account onto a Quote besides its company.
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
     * The quote's ERP company code (e.g).
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
     * The ERP company code of an Account, '' when it has no company relate or the company record cannot be found.
     * @param SugarBean|null $account
     */
    public static function companyOfAccount($account): string
    {
        $company = self::companyRecordOfAccount($account);

        return $company ? (string) ($company->erp_sync_key ?? '') : '';
    }

    /**
     * G435 - the Account's ERP_Companies record (the erp_companies_accounts relate), null when it has none or the record cannot be found.
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
     * G455 — the ERP_LookupValues record (payment terms, FOB) an Account's relate id field names.
     * @param SugarBean|null $account
     * @param string $idField
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
     * G474 — the five address lines core-ShippingAddresses' populate_list copies from a picked ShippingAddress onto the quote (same names on both modules).
     */
    const SHIP_TO_ADDRESS_FIELDS = array(
        'shipping_address_street',
        'shipping_address_city',
        'shipping_address_state',
        'shipping_address_postalcode',
        'shipping_address_country',
    );

    /**
     * The Account's ship-tos that are candidates at all: linked through shipping_addresses_accounts, not deleted, not inactive.
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
     * The Ship To a new quote on this Account gets, or null for the seller to pick. (G223, G311, G748)
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
        // (A lone active address that is flagged was answered just above.)
        if (count($active) === 1) {
            return $active[0];
        }

        return null;
    }

    /**
     * The Epicor PartNum core will be sent for this quote line, '' when none.
     * @param SugarBean $line
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

    /** A catalog product's Epicor PartNum, '' when the product is missing or carries no key. (G379, G382) */
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
     * The Epicor product group (ProdCode) of a line's catalog part, '' for a free-text line, a part with no category, or a category with no code. (🔒 1710b)
     * @param SugarBean $line
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
     * G530 — the most characters the ERP takes in the quote's Reference (Epicor QuoteHed.Reference), 0 when the field states none.
     * @param SugarBean|null $quote
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
     * G530 — the length the ERP will count for a Reference: characters, not bytes ("MÜNCHEN by" is 10), of the value as core sends it.
     */
    public static function referenceLength(string $reference): int
    {
        return mb_strlen(trim($reference), 'UTF-8');
    }

    /**
     * G380 (e): does this quote's ERP company refuse to order a line with no ERP part number?
     * @param SugarBean $quote
     */
    public static function orderRequiresPartNumber($quote): bool
    {
        return self::companySwitchIsOn($quote, 'erp_order_requires_part_number');
    }

    /**
     * G569 (🔒 1912b): does this quote's ERP company refuse to order a quote with no FOB whose customer has no ERP default FOB either?
     * @param SugarBean $quote
     */
    public static function orderRequiresFob($quote): bool
    {
        return self::companySwitchIsOn($quote, 'erp_order_requires_fob');
    }

    /**
     * One read of a per-company ordering switch for both rules, so they can never disagree about which company a quote belongs to or what "on" is.
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

        // Sugar serves a bool as true/false, '1'/'0' or 1/0 depending on the read path; '0' must not read as on.
        return in_array($company->{$field} ?? false, array(true, 1, '1', 'true', 'on'), true);
    }
}
