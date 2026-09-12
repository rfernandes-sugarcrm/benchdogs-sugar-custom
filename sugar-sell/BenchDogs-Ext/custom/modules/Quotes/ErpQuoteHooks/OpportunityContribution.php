<?php

/**
 * Bench Dogs implementation of ERP-Core's neutral Opportunity contribution
 * contract. It supplies a number only; ERP-Core remains the sole writer.
 *
 * Quantity breaks stay as visible Quote lines. Exactly one governing
 * production line contributes, together with the prototype and the Quote's
 * native tax/shipping charges. ERP doc_ext_price already carries the accepted
 * line pricing/discount result, so this class does not recalculate unit price
 * or mutate native Quote lines to make their display total match reporting.
 */
class ErpQuoteOpportunityContribution
{
    public function resolve(SugarBean $quote): ?float
    {
        if (!$quote->load_relationship('bd01_erp_quote_quotes')
            || !$quote->bd01_erp_quote_quotes
            || !is_object($quote->bd01_erp_quote_quotes)
        ) {
            return null;
        }

        $erpQuotes = array_values($quote->bd01_erp_quote_quotes->getBeans());
        if ($erpQuotes === []) {
            // Native/estimating draft not reflected from Epicor yet.
            return null;
        }
        if (count($erpQuotes) !== 1) {
            throw new UnexpectedValueException('Ambiguous ERP quote revision contribution');
        }

        $erpQuote = $erpQuotes[0];
        if (!$erpQuote->load_relationship('bd01_erp_quote_lines')
            || !$erpQuote->bd01_erp_quote_lines
            || !is_object($erpQuote->bd01_erp_quote_lines)
        ) {
            throw new UnexpectedValueException('ERP quote lines unavailable for contribution');
        }

        $governing = [];
        $prototypes = [];
        foreach ($erpQuote->bd01_erp_quote_lines->getBeans() as $line) {
            if (!empty($line->prototype)) {
                $prototypes[] = $line;
            } elseif (!empty($line->governing)) {
                $governing[] = $line;
            }
        }
        if (count($governing) !== 1) {
            throw new UnexpectedValueException('Exactly one governing production option is required');
        }
        if (count($prototypes) > 1) {
            throw new UnexpectedValueException('Multiple prototype lines make contribution ambiguous');
        }

        $amount = $this->lineAmount($governing[0]);
        if ($prototypes !== []) {
            $amount += $this->lineAmount($prototypes[0]);
        }
        $amount += $this->money($quote->tax ?? 0, 'Quote tax');
        $amount += $this->money($quote->shipping ?? 0, 'Quote shipping');
        return $amount;
    }

    private function lineAmount(SugarBean $line): float
    {
        return $this->money($line->doc_ext_price ?? null, 'ERP line amount');
    }

    private function money($value, string $label): float
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new UnexpectedValueException($label . ' is unavailable');
        }
        return (float) $value;
    }
}
