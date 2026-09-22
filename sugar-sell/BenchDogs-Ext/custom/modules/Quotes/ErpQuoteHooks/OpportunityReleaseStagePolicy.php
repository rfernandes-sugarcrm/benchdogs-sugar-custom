<?php

/**
 * RETIRED PROVIDER — 0.9.42-rc65, G280 / 🔒 1507. `resolve()` returns null on
 * purpose, and that is the whole file.
 *
 * WHAT THIS USED TO DO. It counted the Quote's ordered lines and answered
 * `['sales_stage' => 'Partial Production Ordered', 'probability' => 90]` for
 * any release with at least one committed line. Partial Fulfillment does the
 * same thing generically, from tenant config, with no Bench code at all:
 * `ErpOpportunityValuation::configuredPartialStageResolution()` reads
 * `erp_integration.partial_order_sales_stage` and takes the probability from
 * `sales_probability_dom`, which PF itself now ships as 90 (G278 / 🔒 1506).
 * scripts/post_install.php writes that config key.
 *
 * 🛑 WHY THIS FILE STILL EXISTS, AND WHY IT IS NOT EMPTY. Both halves matter,
 * and each was read out of PF's source rather than assumed:
 *
 *  1. DELETING IT WOULD CHANGE NOTHING ON A TENANT THAT HAS IT. PF looks the
 *     provider up by a hardcoded PATH — `file_exists('custom/modules/Quotes/
 *     ErpQuoteHooks/OpportunityReleaseStagePolicy.php')`, ErpOpportunityValuation
 *     .php:288 — and Module Loader never deletes a file a later build stops
 *     shipping (§CW / G37). Drop it from the package and the OLD provider keeps
 *     running and keeps outranking the config. `unlink()` is not available:
 *     the cloud scanner denies it (MLP002). Overwriting is the only removal.
 *
 *  2. AN EMPTY STUB WOULD BE WORSE THAN DOING NOTHING. When the file exists but
 *     does not define `ErpOpportunityReleaseStagePolicy`, PF logs and returns
 *     `policy_provider_invalid`, which PRESERVES the current stage and never
 *     consults the config (ErpOpportunityValuation.php:297-301). The
 *     Opportunity stage would then silently stop being written at all. A
 *     provider that exists and returns null is the one shape that hands control
 *     to the generic path (`policy_provider_null` → :312 → :319).
 *
 * ⚠️ ONE DELIBERATE BEHAVIOUR CHANGE, recorded rather than hidden. PF's generic
 * path only fires while lines remain open (`$partial === true`,
 * ErpOpportunityValuation.php:315). The old provider answered on a FINAL
 * release too, stamping 'Partial Production Ordered' on a quote with nothing
 * left to order. That stage is now core's business, which is the point of the
 * ruling: *"Donthave any logic on bench that is not on core"*.
 */
class ErpOpportunityReleaseStagePolicy
{
    /**
     * @return array{sales_stage: string, probability: int}|null Always null:
     *   "this package has no opinion", which is what makes PF read the config.
     */
    public function resolve(SugarBean $quote): ?array
    {
        return null;
    }
}
