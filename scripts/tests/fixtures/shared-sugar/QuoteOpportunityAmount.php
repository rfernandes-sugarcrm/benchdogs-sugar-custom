<?php

/**
 * Shared owner of the primary Quote's Opportunity headline amount.
 *
 * A quote need not have been ordered, and Partial Fulfillment need not be
 * installed. Use the stored native grand total, not a second calculation of
 * line prices that drops discounts, tax or shipping. A neutral optional
 * contribution contract can supply a policy-specific total; the writer and
 * currency conversion remain here. This does not choose a
 * primary quote or sum competing revisions. It never writes stage or RLIs.
 */
class QuoteOpportunityAmount
{
    public function refresh($bean, $event = '', $arguments = array()): void
    {
        if (!($bean instanceof SugarBean) || empty($bean->id)) {
            return;
        }
        if ($event === 'after_relationship_add' && ($arguments['link'] ?? '') !== 'opportunities') {
            return;
        }
        try {
            // THE INVALIDATION PATH - owner ruling 787(4), closing review
            // finding 4. This runs BEFORE the deleted-bean guard below on
            // purpose: a DELETED primary quote is one of the two doors this
            // ruling exists to close, and the old early-return on
            // `!empty($bean->deleted)` is precisely why a delete could not
            // recalculate anything (decision 447 measured it: 500 survives).
            if ($this->primaryWasWithdrawn($bean)) {
                $this->unvalue($bean);

                return;
            }
            if (!empty($bean->deleted)) {
                return;
            }
            $this->refreshPrimary($bean);
        } catch (\Throwable $e) {
            // Preserve the quote save; log a failure rather than claiming its
            // forecast was updated. No record payload or credentials in logs.
            $GLOBALS['log']->error('QuoteOpportunityAmount: refresh failed for quote '
                . $bean->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Whether this save WITHDREW the justification for a published amount.
     *
     * Two doors to one hole, and decision 447 measured both leaving the old
     * figure in place: the flag un-ticked, and the quote deleted. A third,
     * unlinking, arrives as a relationship event and is handled by the same
     * check because the link is re-read below.
     *
     * PROVENANCE COMES FROM ``fetched_row``, WHICH IS THE POINT. "This
     * Opportunity has an amount" is not enough to justify blanking it -
     * another package may have written it. What justifies it is that THIS
     * quote was the primary a moment ago and is not one now: the seller has
     * just withdrawn the thing the number rested on. A quote that was never
     * primary withdraws nothing and must not touch the figure.
     */
    private function primaryWasWithdrawn(SugarBean $quote): bool
    {
        $was = !empty($quote->fetched_row['erp_is_primary_quote']);
        if (!$was) {
            return false;
        }
        if (!empty($quote->deleted)) {
            return true;
        }

        return empty($quote->erp_is_primary_quote);
    }

    /**
     * Put the Opportunity into the VISIBLE unvalued state ruling 787(4) chose.
     *
     * NULL, NOT ZERO. A stored 0.00 is indistinguishable from a genuinely
     * zero-value deal - the shape that put 994 fabricated 0.00 rows on a
     * tenant (decision 59). The reason string is what makes the blank
     * READABLE rather than merely empty, which is the half the review said was
     * missing: "nothing on the screen says the number is now unowned".
     *
     * NO SIBLING IS PROMOTED. The review forbids it outright and
     * ``test_a_sibling_is_NEVER_promoted_to_fill_the_gap`` still pins it; this
     * method does not look at siblings at all.
     */
    private function unvalue(SugarBean $quote): void
    {
        if ($this->usingRevenueLineItems()) {
            return;
        }
        if (!$quote->load_relationship('opportunities') || !is_object($quote->opportunities)) {
            return;
        }
        $ids = $quote->opportunities->get();
        if (count($ids) !== 1) {
            // Ambiguous ownership must not blank the first record it finds -
            // the same rule the publish path applies.
            return;
        }
        $opportunity = BeanFactory::retrieveBean('Opportunities', reset($ids), array('use_cache' => false));
        if (!$opportunity || !empty($opportunity->deleted)) {
            return;
        }
        // A CLOSED Opportunity keeps its number. The deal is done; blanking a
        // won or lost forecast rewrites history rather than reporting it - and
        // the publish path already refuses to touch these for the same reason.
        if (in_array($opportunity->sales_stage ?? '', ['Closed Won', 'Closed Lost'], true)) {
            return;
        }

        $reason = !empty($quote->deleted)
            ? 'The primary quote was deleted, so this forecast has no source.'
            : 'The primary quote flag was cleared, so this forecast has no source.';

        // Idempotent: already unvalued for the same reason writes nothing, so
        // a re-save does not re-stamp date_modified and leave the audit
        // answering "since when" with "always".
        $already = $opportunity->amount === null || $opportunity->amount === '';
        if ($already && (string) ($opportunity->erp_amount_unvalued ?? '') === $reason) {
            return;
        }

        $opportunity->amount = null;
        $opportunity->erp_amount_unvalued = $reason;
        $opportunity->save();

        $GLOBALS['log']->info('QuoteOpportunityAmount: opportunity ' . $opportunity->id
            . ' unvalued - ' . $reason);
    }

    private function refreshPrimary(SugarBean $quote): void
    {
        if (empty($quote->erp_is_primary_quote) || $this->usingRevenueLineItems()) {
            return;
        }
        if (!$this->stillPrimary($quote)) {
            return;
        }
        // Missing/invalid is not a zero-price quote. A real zero still clears
        // the amount when the last line is removed or an estimate is reset.
        if (!isset($quote->total) || !is_numeric($quote->total)) {
            return;
        }
        if (!is_finite((float) $quote->total)) {
            throw new \UnexpectedValueException('Non-finite native Quote total');
        }
        if (!$quote->load_relationship('opportunities') || !is_object($quote->opportunities)) {
            return;
        }
        $ids = $quote->opportunities->get();
        if (count($ids) !== 1) {
            // Ambiguous/missing ownership must not choose the first record.
            return;
        }
        $opportunity = BeanFactory::retrieveBean('Opportunities', reset($ids), array('use_cache' => false));
        if (!$opportunity || !empty($opportunity->deleted)
            || in_array($opportunity->sales_stage ?? '', ['Closed Won', 'Closed Lost'], true)) {
            return;
        }

        $from = (string) ($quote->currency_id ?? '');
        $to = (string) ($opportunity->currency_id ?? '');
        if ($from === '' || $to === '') {
            return;
        }
        $amount = $this->contribution($quote);
        if ($from !== $to) {
            $amount = (float) SugarCurrency::convertAmount($amount, $from, $to);
        }
        // A finite input can overflow during conversion. Never publish an
        // invalid monetary result or mistake it for an acknowledged forecast.
        if (!is_finite($amount)) {
            throw new \UnexpectedValueException('Non-finite Opportunity contribution');
        }
        $amount = round($amount, 2);
        if ((float) ($opportunity->amount ?? 0) === $amount) {
            return;
        }
        $opportunity->amount = $amount;
        // A real figure clears the unvalued marker: leaving it would say "no
        // source" beside a number that now has one.
        $opportunity->erp_amount_unvalued = '';
        $opportunity->save();
        $GLOBALS['log']->info('QuoteOpportunityAmount: refreshed opportunity '
            . $opportunity->id . ' from primary quote ' . $quote->id);
    }

    /**
     * Is this quote STILL the primary, according to the database?
     *
     * THE IN-MEMORY FLAG IS NOT AN ANSWER, and trusting it is how a losing
     * quote publishes the headline amount.
     *
     * QuotePrimaryQuoteSoleEnforcer runs ahead of this hook (priority 10 vs
     * 20) and, when it finds that a concurrent writer has already cleared the
     * quote it was called for, it STANDS DOWN - correctly, because clearing
     * anyone from there is what would produce zero primaries. But standing
     * down leaves the request's own bean untouched, so `erp_is_primary_quote`
     * is still 1 in memory on a quote the database says is no longer primary.
     * This hook then read that flag and published that quote's total.
     *
     * The interleaving, with A and B both saving primary=true:
     *
     *     A: enforcer clears B, publishes A's amount
     *     B: enforcer re-reads, sees B was cleared, stands down
     *     B: THIS HOOK sees B's stale in-memory flag and publishes B's total
     *
     * The Opportunity then carries the amount of the quote that LOST, which
     * is the exact failure decision 146 names: "Leaving 'primary' pointing
     * anywhere else means the Opportunity is valued off a quote that lost."
     *
     * `use_cache => false` is load-bearing for the same reason it is on the
     * enforcer's own self-re-read: a cached bean hands back the stale flag
     * this check exists to distrust.
     *
     * WHAT THIS DOES NOT CLOSE - AN ACCEPTED RESIDUAL, NOT A CLOSED HOLE.
     *
     * A check and a write are two steps, and the flag can move between them.
     * The exact interleaving that still leaks, written out rather than
     * gestured at:
     *
     *   1. A is primary; the Opportunity carries A's total.
     *   2. A seller ticks B. B's enforcer clears A. The database says B.
     *   3. B's amount hook passes THIS check and starts computing.
     *   4. The seller ticks A again. A's enforcer clears B. Database says A.
     *   5. A's amount hook publishes A's total.
     *   6. B's publish from step 3 lands LAST, with B's total.
     *
     * Final state: the primary flag is A and the headline amount is B's.
     *
     * DECISION 145 DOES NOT RESCUE THIS, and an earlier draft of this
     * docblock wrongly said it did. 145 rules that the latest tick wins THE
     * FLAG. It says nothing about which AMOUNT lands last, and the two are
     * written by different hooks in different requests. Calling that
     * "resolved by 145" was a defence of the shortcut, not an argument.
     *
     * It is accepted here for one reason, stated so it can be overturned:
     * reaching it needs two competing primary ticks inside the few
     * milliseconds of one publish, whereas the defect this method DOES close
     * - publishing from a bean the database has already cleared - needs only
     * one ordinary concurrent save and was measured, not hypothesised.
     * Closing the larger gap and closing the common one are different
     * changes, and shipping the second should not wait on the first.
     *
     * THE REMEDY IF IT IS NOT ACCEPTABLE: make the publish conditional on
     * the flag at the moment of the write - either an UPDATE guarded by the
     * quote still being primary, or holding the Opportunity row lock across
     * the check and the save. An earlier draft asserted the lock route
     * "introduces a real lock-ordering deadlock". THAT IS UNVERIFIED and
     * should not be repeated as fact: the enforcer also takes the
     * Opportunity row first, so the orders may well agree. It is a question
     * to settle, not a reason already established. Decision 434.
     */
    private function stillPrimary(SugarBean $quote): bool
    {
        if (empty($quote->id)) {
            return false;
        }

        $fresh = BeanFactory::retrieveBean('Quotes', $quote->id, array('use_cache' => false));
        if (!$fresh || !empty($fresh->deleted)) {
            return false;
        }
        if (empty($fresh->erp_is_primary_quote)) {
            $GLOBALS['log']->info('QuoteOpportunityAmount: quote ' . $quote->id
                . ' is no longer the primary quote - not publishing its total');

            return false;
        }

        return true;
    }

    private function usingRevenueLineItems(): bool
    {
        if (class_exists('Opportunity') && method_exists('Opportunity', 'usingRevenueLineItems')) {
            return (bool) Opportunity::usingRevenueLineItems();
        }
        $settings = BeanFactory::getBean('Administration')->getConfigForModule('Opportunities');
        // A missing mode is not authorization to override native forecasting.
        return !is_array($settings) || ($settings['opps_view_by'] ?? '') !== 'Opportunities';
    }

    /**
     * Neutral optional contribution contract, still owned by this sole writer.
     * The fixed file defines ErpQuoteOpportunityContribution::resolve($quote).
     * Return null only when not applicable; otherwise return a finite int/float
     * in the Quote's currency, including the applicable commercial charges.
     * An applicable but unresolved policy must throw, never return null: the
     * outer handler then preserves the old amount and records the failure.
     * Providers must not write Opportunity or alter Quote lines/totals.
     */
    private function contribution(SugarBean $quote): float
    {
        $file = 'custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php';
        if (!file_exists($file)) {
            return (float) $quote->total;
        }
        require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($file);
        $amount = (new ErpQuoteOpportunityContribution())->resolve($quote);
        if ($amount === null) {
            return (float) $quote->total;
        }
        if ((!is_int($amount) && !is_float($amount)) || !is_finite((float) $amount)) {
            throw new \UnexpectedValueException('Invalid Opportunity contribution');
        }
        return (float) $amount;
    }
}
