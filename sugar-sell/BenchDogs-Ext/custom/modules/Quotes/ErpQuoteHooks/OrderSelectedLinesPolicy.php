<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * ERP-Epicor's Order Selected Lines hook point, answered for G381 (🔒 1705b):
 * on a Bench Dogs ADM quote, a selected line with no ERP part number blocks
 * the order, with a seller message.
 *
 * Contract (ErpQuoteHooks::fireLineOrderRefusal, ERP-Epicor): class
 * ErpOrderSelectedLinesPolicy, refusal(SugarBean $quote, array $lineIds):
 * ?string. NULL IS THE ONLY "YES"; a string is the refusal, in this layer's
 * own words. A yes/no answer only: the seller named these lines, so they are
 * never narrowed.
 *
 * Also reached by Submit Order on a quote mid-release (ERP-Epicor sends a
 * partially ordered quote down the per-line path), which is why both hook
 * points carry the same rule.
 *
 * A quote of any company other than ADM answers null: the answer ERP-Epicor
 * gives when no package implements this hook. This replaces the retired
 * Bench adapter still on some tenants at this path, which answered "no
 * objection" once its selector was blanked.
 */
class ErpOrderSelectedLinesPolicy
{
    public function refusal($quote, array $lineIds): ?string
    {
        if (!class_exists('BdAdmRules', false)) {
            require_once 'custom/modules/Quotes/BdAdmRules.php';
        }

        return BdAdmRules::nonPartRefusal($quote, $lineIds);
    }
}
