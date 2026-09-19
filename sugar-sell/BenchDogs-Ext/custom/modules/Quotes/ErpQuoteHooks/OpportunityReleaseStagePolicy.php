<?php

/**
 * Bench Dogs release-stage policy for the shared Partial Fulfillment writer.
 *
 * Returns plain data and never saves an Opportunity. The shared package
 * validates the stage/probability and remains the sole writer.
 *
 * READS THE NATIVE QUOTE LINES. It used to walk the bd01_* quote mirror to
 * build a `line_num => 'prototype'|'production'` lookup, then map each ordered
 * native Product onto it via `bd_erp_line_num`. Decision 901/903 retires that
 * mirror.
 *
 * 🚩 AND THE LOOKUP WAS ALREADY DEAD. The mirror's `prototype` boolean has had
 * NO WRITER since D16. `connector_ext_benchdogs.transformers.quotes` says so
 * outright — "The prototype line used to be flagged here by a
 * part-number/description heuristic (`_is_prototype`, use case 5). REMOVED at
 * D16: under decision 87(b)'s per-group exactly-one rule the prototype rung is
 * its own `mft_part_num` group carrying its own pin, so it falls out of
 * valuation structurally ... G2 also forbids Bench owning the grouping" — and
 * `TestPrototypeIdentificationMovedOut` pins it: no Bench model may declare a
 * `prototype` field, and `_is_prototype` must not exist.
 *
 * So `$line->prototype` has read false for every line since D16, every ordered
 * line classified as 'production', and this policy has returned
 * 'Partial Production Ordered' every single time. **'Prototype Ordered' has
 * been an unreachable stage, not a rare one.** This rewrite preserves that
 * measured behaviour exactly rather than inventing a replacement rule: the
 * distinction is retired by decision, and re-deriving it here from a part
 * number would be Bench owning the grouping, which G2 forbids.
 *
 * 📌 Measured on Bench 2026-09-18, and why a heuristic is NOT available: quote
 * 641e586c carries ETO-PENDING (own part group, role `counts`) alongside the
 * BD-ENDCAP-ALU ladder, while quote a249e26a carries DEMO-INSTALL (own part
 * group, role `counts`, no break quantity) which is an INSTALL CHARGE. Both
 * look identical to any "own group and it counts" test, so `counts &&
 * !erp_governing` would stage an install charge as a prototype. Refusing to
 * guess is the point.
 *
 * If the prototype milestone is ever wanted back it needs a real writer on the
 * native line, decided upstream where the grouping lives - not a guess here.
 */
class ErpOpportunityReleaseStagePolicy
{
    /**
     * @return array{sales_stage: string, probability: int}|null
     */
    public function resolve(SugarBean $quote): ?array
    {
        if (empty($quote->id)) {
            return null;
        }

        // Order Selected Lines loads this relationship before it stamps the
        // selected Product. SugarBean::retrieve() refreshes the Quote fields
        // but does NOT discard an already-loaded Link2 bean snapshot, so a
        // getBeans() here once observed erp_ordered=false after the order had
        // actually succeeded on QA. Resolve identities, then read each line
        // outside BeanFactory's request cache so this observes the committed
        // row rather than the action's pre-order snapshot.
        if (!$quote->load_relationship('products')
            || !$quote->products
            || !is_object($quote->products)
        ) {
            throw new UnexpectedValueException('Quote line items unavailable for release stage');
        }

        $productIds = $quote->products->get();
        if (!is_array($productIds)) {
            throw new UnexpectedValueException('Quote line-item identities unavailable for release stage');
        }

        $ordered = 0;
        foreach ($productIds as $productId) {
            $product = BeanFactory::retrieveBean(
                'Products',
                (string) $productId,
                array('use_cache' => false)
            );
            if (!$product || empty($product->id)) {
                throw new UnexpectedValueException('Quote line item unavailable for release stage');
            }
            if (!empty($product->deleted) || empty($product->erp_ordered)) {
                continue;
            }
            $ordered++;
        }

        // Unchanged: a release with nothing committed is not a stage, it is a
        // contradiction, and staging an Opportunity off it would be a guess.
        if ($ordered === 0) {
            throw new UnexpectedValueException(
                'No committed Quote line is visible for Bench release-stage policy'
            );
        }

        return array(
            'sales_stage' => 'Partial Production Ordered',
            'probability' => 90,
        );
    }
}
