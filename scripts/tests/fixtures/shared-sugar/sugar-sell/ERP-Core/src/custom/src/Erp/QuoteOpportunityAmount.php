<?php

namespace Sugarcrm\Sugarcrm\custom\Erp;

use BeanFactory;
use ErpQuoteOpportunityContribution;
use Opportunity;
use SugarBean;
use SugarCurrency;

/** Shared owner of the primary Quote's Opportunity headline amount. */
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
            // The invalidation path - owner ruling 787(4), closing review finding 4. (decision 447)
            if ($this->primaryWasWithdrawn($bean)) {
                $this->unvalue($bean);

                return;
            }
            if (!empty($bean->deleted)) {
                return;
            }
            $this->refreshPrimary($bean);
        } catch (\Throwable $e) {
            // Preserve the quote save; log a failure rather than claiming its forecast was updated.
            $GLOBALS['log']->error('QuoteOpportunityAmount: refresh failed for quote '
                . $bean->id . ': ' . $e->getMessage());
        }
    }

    /** Whether this save withdrew the justification for a published amount. (decision 447) */
    private function primaryWasWithdrawn(SugarBean $quote): bool
    {
        $was = !empty($quote->fetched_row['erp_is_primary_quote']);
        if (!$was) {
            return false;
        }
        // 🔒 1468 (G215): a quote that was primary with no Opportunity and yielded on joining a deal that already had a primary was never this Opportunity's primary.
        if (QuotePrimaryQuoteSoleEnforcer::triggerFor((string) $quote->id)
            === QuotePrimaryQuoteSoleEnforcer::TRIGGER_YIELDED_ON_JOIN) {
            return false;
        }
        if (!empty($quote->deleted)) {
            return true;
        }

        return empty($quote->erp_is_primary_quote);
    }

    /** Put the Opportunity into the visible unvalued state ruling 787(4) chose. (decision 59) */
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
            // Ambiguous ownership must not blank the first record it finds - the same rule the publish path applies.
            return;
        }
        $opportunity = BeanFactory::retrieveBean('Opportunities', reset($ids), array('use_cache' => false));
        if (!$opportunity || !empty($opportunity->deleted)) {
            return;
        }
        // A closed Opportunity keeps its number.
        if (in_array($opportunity->sales_stage ?? '', ['Closed Won', 'Closed Lost'], true)) {
            return;
        }

        $reason = !empty($quote->deleted)
            ? 'The primary quote was deleted, so this forecast has no source.'
            : 'The primary quote flag was cleared, so this forecast has no source.';

        // Idempotent: already unvalued for the same reason writes nothing. (G167)
        $already = $opportunity->amount === null || $opportunity->amount === '';
        if ($already && (string) ($opportunity->erp_amount_unvalued ?? '') === $reason
            && (string) ($opportunity->erp_amount_source ?? '') === ''
        ) {
            return;
        }

        $opportunity->amount = null;
        $opportunity->erp_amount_unvalued = $reason;
        // G167 I4 — the two fields are mutually exclusive by construction.
        $opportunity->erp_amount_source = '';
        $opportunity->save();

        $GLOBALS['log']->info('QuoteOpportunityAmount: opportunity ' . $opportunity->id
            . ' unvalued - ' . $reason);
    }

    private function refreshPrimary(SugarBean $quote): void
    {
        if (empty($quote->erp_is_primary_quote) || $this->usingRevenueLineItems()) {
            return;
        }
        // G602: the row stillPrimary() re-reads is also where the label's Sugar quote number comes from - see sugarQuoteNumber().
        $stored = $this->stillPrimary($quote);
        if ($stored === null) {
            return;
        }
        // Missing/invalid is not a zero-price quote.
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
        // A finite input can overflow during conversion.
        if (!is_finite($amount)) {
            throw new \UnexpectedValueException('Non-finite Opportunity contribution');
        }
        $amount = round($amount, 2);

        // G167 I4 — "a change of primary leaves a reason: which quote the amount came from, and the trigger".
        $source = $this->sourceSentence($quote, $stored);

        // The early return now weighs both facts, and that is I4's anti-coincidence clause, not a tidy-up. (G167, G199, 🔒 1428)
        $unvalued = ($opportunity->amount === null || $opportunity->amount === '');
        $amountSame = !$unvalued && ((float) $opportunity->amount === $amount);
        $sourceSame = ((string) ($opportunity->erp_amount_source ?? '') === $source);
        if ($amountSame && $sourceSame) {
            // Genuinely nothing to say.
            return;
        }

        $opportunity->amount = $amount;
        // A real figure clears the unvalued marker: leaving it would say "no source" beside a number that now has one.
        $opportunity->erp_amount_unvalued = '';
        $opportunity->erp_amount_source = $source;
        $opportunity->save();
        $GLOBALS['log']->info('QuoteOpportunityAmount: refreshed opportunity '
            . $opportunity->id . ' from primary quote ' . $quote->id
            . ' - ' . $source);
    }

    /** G167 I4 — the sentence that says where this Opportunity's amount came from and what moved it. (G445, G602) */
    private function sourceSentence(SugarBean $quote, SugarBean $stored): string
    {
        $num = self::sugarQuoteNumber($stored);
        $erp = self::erpQuoteNumber($quote);
        $name = trim((string) ($quote->name ?? ''));
        if ($num !== '') {
            $label = 'Quote #' . $num;
        } elseif ($name !== '') {
            $label = 'Quote "' . $name . '"';
        } else {
            $label = 'Quote ' . (string) $quote->id;
        }
        if ($erp !== '') {
            $label .= ' (Epicor ' . $erp . ')';
        }
        if ($num !== '' && $name !== '') {
            $label .= ' "' . $name . '"';
        }

        $trigger = QuotePrimaryQuoteSoleEnforcer::triggerFor((string) $quote->id);
        if ($trigger === '') {
            return 'Amount from ' . $label . '; the quote\'s own total changed.';
        }

        return 'Amount from ' . $label . '; ' . $trigger . '.';
    }

    /**
     * G445 / G602 — the quote's own Sugar number, as stored: read off $stored, the row stillPrimary() re-read from the database after this save.
     */
    private static function sugarQuoteNumber(SugarBean $stored): string
    {
        return trim((string) ($stored->quote_num ?? ''));
    }

    /** G445 — the Epicor quote number, '' before the quote reaches Epicor: the raw display key the connector stamps. */
    private static function erpQuoteNumber(SugarBean $quote): string
    {
        $display = trim((string) ($quote->erp_display_sync_key ?? ''));
        if ($display !== '') {
            return $display;
        }
        $scoped = trim((string) ($quote->erp_sync_key ?? ''));
        $cut = strpos($scoped, '__');

        return $cut === false ? '' : trim(substr($scoped, $cut + 2));
    }

    /** Is this quote still the primary, according to the database? (G602, decision 146) */
    private function stillPrimary(SugarBean $quote): ?SugarBean
    {
        if (empty($quote->id)) {
            return null;
        }

        $fresh = BeanFactory::retrieveBean('Quotes', $quote->id, array('use_cache' => false));
        if (!$fresh || !empty($fresh->deleted)) {
            return null;
        }
        if (empty($fresh->erp_is_primary_quote)) {
            $GLOBALS['log']->info('QuoteOpportunityAmount: quote ' . $quote->id
                . ' is no longer the primary quote - not publishing its total');

            return null;
        }

        return $fresh;
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

    /** Neutral optional contribution contract, still owned by this sole writer. */
    private function contribution(SugarBean $quote): float
    {
        $file = 'custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php';
        if (!file_exists($file)) {
            return (float) $quote->total;
        }
        require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($file);
        // G282 — the file existing is not the class existing, and the difference is every opportunity's headline amount.
        if (!class_exists('ErpQuoteOpportunityContribution', false)) {
            throw new \UnexpectedValueException(
                'The Opportunity contribution provider at ' . $file . ' defines no '
                . 'ErpQuoteOpportunityContribution class, so this Opportunity keeps the amount it '
                . 'already had. A package that retires this file must drop it from its build or '
                . 'ship a working provider - never an empty stub at this path (G282).'
            );
        }
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
