<?php

/**
 * Bench Dogs release-stage policy for the shared Partial Fulfillment writer.
 *
 * This class returns plain data and never saves an Opportunity. The shared
 * package validates the stage/probability and remains the sole writer.
 * Classification uses the explicit ERP-line cross-reference on each ordered
 * Quote line item. Missing or contradictory identity throws rather than
 * guessing whether a release was prototype or production.
 */
class ErpOpportunityReleaseStagePolicy
{
    /**
     * @return array{sales_stage: string, probability: int}|null
     */
    public function resolve(SugarBean $quote): ?array
    {
        if (!$quote->load_relationship('bd01_erp_quote_quotes')
            || !$quote->bd01_erp_quote_quotes
            || !is_object($quote->bd01_erp_quote_quotes)
        ) {
            return null;
        }

        $erpQuotes = array_values($quote->bd01_erp_quote_quotes->getBeans());
        if ($erpQuotes === []) {
            return null;
        }
        if (count($erpQuotes) !== 1) {
            throw new UnexpectedValueException('Ambiguous ERP quote revision for release stage');
        }

        $erpQuote = $erpQuotes[0];
        if (!$erpQuote->load_relationship('bd01_erp_quote_lines')
            || !$erpQuote->bd01_erp_quote_lines
            || !is_object($erpQuote->bd01_erp_quote_lines)
        ) {
            throw new UnexpectedValueException('ERP quote lines unavailable for release stage');
        }

        $lineKinds = [];
        $prototypeCount = 0;
        foreach ($erpQuote->bd01_erp_quote_lines->getBeans() as $line) {
            $lineNum = (int) ($line->line_num ?? 0);
            if ($lineNum <= 0 || array_key_exists($lineNum, $lineKinds)) {
                throw new UnexpectedValueException('ERP quote line identity is ambiguous for release stage');
            }
            $isPrototype = !empty($line->prototype);
            if ($isPrototype) {
                $prototypeCount++;
            }
            $lineKinds[$lineNum] = $isPrototype ? 'prototype' : 'production';
        }
        if ($prototypeCount > 1) {
            throw new UnexpectedValueException('Multiple prototype lines make release stage ambiguous');
        }

        if (!$quote->load_relationship('products')
            || !$quote->products
            || !is_object($quote->products)
        ) {
            throw new UnexpectedValueException('Quote line items unavailable for release stage');
        }

        $hasPrototype = false;
        $hasProduction = false;
        foreach ($quote->products->getBeans() as $product) {
            if (!empty($product->deleted) || empty($product->erp_ordered)) {
                continue;
            }
            $lineNum = (int) ($product->bd_erp_line_num ?? 0);
            if ($lineNum <= 0 || !array_key_exists($lineNum, $lineKinds)) {
                throw new UnexpectedValueException(
                    'Ordered Quote line has no unambiguous ERP quote-line identity'
                );
            }
            if ($lineKinds[$lineNum] === 'prototype') {
                $hasPrototype = true;
            } else {
                $hasProduction = true;
            }
        }

        if ($hasProduction) {
            return [
                'sales_stage' => 'Partial Production Closed',
                'probability' => 90,
            ];
        }
        if ($hasPrototype) {
            return [
                'sales_stage' => 'Prototype Closed',
                'probability' => 80,
            ];
        }

        return null;
    }
}
