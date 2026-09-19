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

        $erpQuoteIds = $quote->bd01_erp_quote_quotes->get();
        if (!is_array($erpQuoteIds) || $erpQuoteIds === []) {
            return null;
        }
        if (count($erpQuoteIds) !== 1) {
            throw new UnexpectedValueException('Ambiguous ERP quote revision for release stage');
        }

        // Quote saves during Order Selected Lines can load the Bench ERP
        // relationship graph before the release is committed. Treat Link2
        // beans as a snapshot throughout this policy: resolve identities and
        // re-read both levels outside BeanFactory's request cache.
        $erpQuoteId = (string) reset($erpQuoteIds);
        if ($erpQuoteId === '') {
            throw new UnexpectedValueException('ERP quote identity is unavailable for release stage');
        }
        $erpQuote = BeanFactory::retrieveBean(
            'bd01_ERP_Quote',
            $erpQuoteId,
            ['use_cache' => false]
        );
        if (!$erpQuote || empty($erpQuote->id)) {
            throw new UnexpectedValueException('ERP quote is unavailable for release stage');
        }
        if (!$erpQuote->load_relationship('bd01_erp_quote_lines')
            || !$erpQuote->bd01_erp_quote_lines
            || !is_object($erpQuote->bd01_erp_quote_lines)
        ) {
            throw new UnexpectedValueException('ERP quote lines unavailable for release stage');
        }

        $lineKinds = [];
        $prototypeCount = 0;
        $erpLineIds = $erpQuote->bd01_erp_quote_lines->get();
        if (!is_array($erpLineIds)) {
            throw new UnexpectedValueException('ERP quote-line identities unavailable for release stage');
        }
        foreach ($erpLineIds as $erpLineId) {
            $line = BeanFactory::retrieveBean(
                'bd01_ERP_Quote_Line',
                (string) $erpLineId,
                ['use_cache' => false]
            );
            if (!$line || empty($line->id)) {
                throw new UnexpectedValueException('ERP quote line unavailable for release stage');
            }
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

        // Order Selected Lines loads this relationship before it stamps the
        // selected Product. SugarBean::retrieve() refreshes the Quote fields,
        // but it does not discard an already-loaded Link2 bean snapshot. A
        // getBeans() here therefore observed erp_ordered=false after the order
        // had actually succeeded on QA. Resolve relationship IDs, then read
        // each Product outside BeanFactory's cache so release policy observes
        // the committed row rather than the action's pre-order snapshot.
        $productIds = $quote->products->get();
        if (!is_array($productIds)) {
            throw new UnexpectedValueException('Quote line-item identities unavailable for release stage');
        }

        $hasPrototype = false;
        $hasProduction = false;
        foreach ($productIds as $productId) {
            $product = BeanFactory::retrieveBean(
                'Products',
                (string) $productId,
                ['use_cache' => false]
            );
            if (!$product || empty($product->id)) {
                throw new UnexpectedValueException('Quote line item unavailable for release stage');
            }
            if (!empty($product->deleted) || empty($product->erp_ordered)) {
                continue;
            }
            // 🔒 1032 — READS CORE'S FIELD, NOT A BENCH COPY. This was
            // `bd_erp_line_num`, a Bench-owned column whose only writer
            // (BdQuoteReflectionHook) went with the retired quote mirror. The
            // ERP line number is now projected by connector-core's
            // QuoteLineCoreTransformer onto `Products.erp_quote_line_num`.
            //
            // MEASURED on sugar.local.dev 2026-09-18, before the promotion:
            // `bd_erp_line_num` survived on 6 of 655 quote line items, and 13
            // of the 14 ORDERED lines this loop walks reached the throw below.
            // Core can supply 515 of the 521 connector-owned rows.
            //
            // 🛑 `<= 0` IS DELIBERATE AND MUST STAY. Core types this field
            // `int | None` and OMITS it when the ERP line is undeterminable,
            // precisely so an unknown line never arrives here as 0 and is
            // never mistaken for line zero. A `?? 0` reading an absent field
            // still lands on 0, which is why this refuses rather than guesses.
            $lineNum = (int) ($product->erp_quote_line_num ?? 0);
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
                'sales_stage' => 'Partial Production Ordered',
                'probability' => 90,
            ];
        }
        if ($hasPrototype) {
            return [
                'sales_stage' => 'Prototype Ordered',
                'probability' => 80,
            ];
        }

        throw new UnexpectedValueException(
            'No committed Quote line is visible for Bench release-stage policy'
        );
    }
}
