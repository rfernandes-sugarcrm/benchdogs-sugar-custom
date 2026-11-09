<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

/** A quote made by the Account's Create Opportunity & Quote starts with Needs Price Estimate on, through the per-tenant default ERP-Epicor reads. */
class BdQuoteTypeDefault
{
    /** ERP-Epicor's setting for that quote's type (AccountsErpActionsApi::CONFIG_CATEGORY, ::CFG_QUOTE_TYPE); a test holds them equal. */
    public const CATEGORY = 'erp_integration';
    public const KEY = 'opp_quote_type';

    /** The quote type the Needs Price Estimate switch stands for (an erp_quote_type_list key). */
    public const NEEDS_PRICE_ESTIMATE = 'advanced_quote';

    /** Make Needs Price Estimate the default; says what it found. Run once per tenant, so an administrator's later choice stands. */
    public static function apply($admin): string
    {
        $config = $admin->getConfigForModule(self::CATEGORY, 'base', true);
        $current = is_array($config) ? trim((string) ($config[self::KEY] ?? '')) : '';
        if ($current === self::NEEDS_PRICE_ESTIMATE) {
            return 'kept: already ' . self::NEEDS_PRICE_ESTIMATE;
        }
        $admin->saveSetting(self::CATEGORY, self::KEY, self::NEEDS_PRICE_ESTIMATE, 'base');
        // saveSetting() clears the module-config cache only for a module, and this category is not one.
        $admin->getConfigForModule(self::CATEGORY, 'base', true);

        return 'set to ' . self::NEEDS_PRICE_ESTIMATE . ' (was ' . ($current === '' ? 'unset' : $current) . ')';
    }
}
