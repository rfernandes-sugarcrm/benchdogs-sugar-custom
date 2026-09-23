<?php

/**
 * Bench Dogs implementation of ERP-Core's neutral Opportunity contribution
 * contract. It supplies a number only; ERP-Core remains the sole writer.
 *
 * 🔒 708 / G123, owner verbatim 2026-09-20:
 *
 *     "now opprtuntiy rollup is simple no need for cgoveringing line its what
 *      ever is in the quote right???/ no more selected wired logic...."
 *     "add taht as a gap to fix on how to simply calaualted oppetunity roll up
 *      now form the primairy quote."
 *
 * SO THE RULE IS ONE LINE: **the headline amount is the primary quote's own
 * total.** Not a per-line walk, not a sum over selections, not a governing
 * count. ERP-Core has already decided WHICH quote is primary before this class
 * is called (QuoteOpportunityAmount::stillPrimary), and it converts the
 * currency afterwards; this class only answers "what is this quote worth".
 *
 * ────────────────────────────────────────────────────────────────────────────
 * 🚩 WHAT THIS REPLACES, AND WHAT IT COST: −$1,848.00 ON ONE OPPORTUNITY (G97)
 * ────────────────────────────────────────────────────────────────────────────
 *
 * This class used to count `erp_governing` lines PER QUOTE and throw when it
 * found more than one. Measured live on Ophir 2026-09-19, Opportunity
 * 3e9ef3f8 / quote 1250:
 *
 *     erp_open_amount  237.30 -> 2,085.30   written by the PF rollup  ✅
 *     amount           — NO ROW —           this class threw          ❌
 *
 * Quote 1250 has TWO ladder groups — `EPIC06__1250_1_1` and `EPIC06__1250_2_1`
 * — each legitimately governed by its own rung (2,016.00 + 69.30 = 2,085.30,
 * exactly the quote total). Two governing lines is not a selection bug on a
 * quote with two price-break parts; it is the normal shape. The count hit
 * 2 > 1, threw, `QuoteOpportunityAmount::refresh()` caught it and logged, and
 * the Opportunity kept 237.30 for a quote worth 2,085.30.
 *
 * 🛑 THE QUOTE TOTAL WAS RIGHT THE WHOLE TIME. The defect was a check, not an
 * arithmetic. That is why 708 deletes the check rather than making it
 * per-ladder-group: a correct version of a check that should not exist still
 * costs an install cycle to get wrong again. New rule §DG — make the illegal
 * state unreachable, not detectable.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * 📌 WHY "THE QUOTE TOTAL" DOES NOT RE-ADMIT THE ALTERNATIVES, verified in the
 * vardef rather than assumed
 * ────────────────────────────────────────────────────────────────────────────
 *
 * ERP-Epicor-QuantityAlternatives ships, at
 * `custom/Extension/modules/Products/Ext/Vardefs/erp_total_role.php:110`:
 *
 *     $dictionary['Product']['fields']['subtotal']['formula'] =
 *         'ifElse(equal($erp_total_role, "alternative"), 0, …)'
 *
 * calculated + enforced SugarLogic, and `Quotes.total` is
 * `currencyAdd(rollupCurrencySum($product_bundles,"new_sub"), $tax, $shipping)`
 * — equally enforced. So an unselected rung contributes **zero** to the quote
 * total by the platform's own arithmetic, server and browser, with no custom
 * code in the path. Summing the quote therefore does NOT sum the ladder.
 *
 * It is also STRICTLY better than the old per-line sum this replaces, which
 * added `subtotal` over the `counts` lines and then added `$quote->tax` and
 * `$quote->shipping` on top: that arithmetic skipped bundle-level discount
 * (`new_sub` = subtotal − deal_tot) and so over-stated any discounted quote.
 * The stored total has never had that hole.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * 🛑 THE ONE REFUSAL THAT SURVIVES, AND IT IS NOT ABOUT GOVERNING LINES
 * ────────────────────────────────────────────────────────────────────────────
 *
 * A quote whose every ERP line is still `alternative` has a total of **0.00**
 * BY CONSTRUCTION — every line is zeroed by the formula above. That 0.00 is
 * not a measurement of the deal, it is the absence of one, and publishing it
 * would be the "fabricated zero" this estate has paid for before.
 *
 * Quote 1049 is the live control: 4 lines, all `alternative`, and an admin set
 * `amount` to 23.00 by hand on 09-18. Under a naive "amount = total" that 23.00
 * is overwritten with 0.00. So this refuses instead — and refusing (rather
 * than returning null) is what preserves the old figure, because ERP-Core's
 * contract is explicit that an applicable-but-unresolved policy must throw:
 * `QuoteOpportunityAmount::refresh()` then catches, logs, and writes nothing.
 * Returning null would fall through to `(float) $quote->total` = 0.00 and
 * destroy the admin's number, which is the trap this branch exists to avoid.
 *
 * The refusal is stated as "not yet priced". It says nothing about governing
 * lines, because after 708 nothing here counts them.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THE LINES ARE STILL READ AT ALL
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Only to tell those two states apart, and neither is arithmetic:
 *
 *     no ERP-role line at all   -> null, "not applicable": a native/estimating
 *                                  draft. ERP-Core uses the native total.
 *     every ERP-role line is
 *     `alternative`             -> refuse: not yet priced (above).
 *     anything else             -> the quote's own stored total.
 *
 * `erp_governing` is not read by this class any more. `subtotal` is not read
 * by this class any more. If a future edit re-introduces either, it is
 * re-introducing G97.
 */
class ErpQuoteOpportunityContribution
{
    /** A line that belongs in the quote's money. */
    const ROLE_COUNTS = 'counts';

    /** An unselected rung of a price ladder. Zeroed by the subtotal formula. */
    const ROLE_ALTERNATIVE = 'alternative';

    public function resolve(SugarBean $quote): ?float
    {
        if (empty($quote->id)) {
            return null;
        }

        $lines = $this->erpLines($quote);

        // No line carries a role: this is not an ERP-reflected ladder quote.
        // Same meaning the mirror's "no bd01_ERP_Quote yet" branch had — a
        // native/estimating draft — and ERP-Core must leave the Opportunity
        // alone rather than write a zero over a human's number.
        if ($lines === []) {
            return null;
        }

        // Counted with a plain loop on purpose: ModuleScanner DENYLISTS
        // array_filter (it takes a callable), and a package that uses it is
        // refused by the SugarCloud hosted scan - "Code attempted to call
        // denylisted function array_filter" - so the whole release fails to
        // install. Measured on Bench 2026-09-18, not guessed from the docs.
        $counted = 0;
        foreach ($lines as $line) {
            if ((string) $line->erp_total_role === self::ROLE_COUNTS) {
                $counted++;
            }
        }

        // Every rung still `alternative` is not "zero pounds of business" — it
        // is a ladder nobody has priced yet, and the formula has zeroed the
        // quote total to match. Refuse, so ERP-Core PRESERVES whatever the
        // Opportunity already carries (quote 1049's hand-set 23.00). See the
        // header: returning null here would publish the 0.00 instead.
        if ($counted === 0) {
            throw new UnexpectedValueException(
                'This quote is not yet priced: every ERP line is still an unselected'
                . ' break-ladder rung, so its total is 0.00 by construction'
            );
        }

        // 🔒 708. The quote's own stored total, which Sugar has already rolled
        // up from the lines - alternatives zeroed, bundle discount applied,
        // native tax and shipping included. ERP-Core converts the currency.
        //
        // money() rather than a cast: a sparse or stale Quote read is not
        // evidence of a zero-value deal, and throwing preserves the prior
        // figure through the same failure boundary the refusal above uses.
        return $this->money($quote->total ?? null, 'Quote total');
    }

    /**
     * The quote's native lines that carry an ERP role.
     *
     * Products.quote_id is the direct parent pointer, so this does not need the
     * Quote -> ProductBundles -> Products hop, and cannot be thrown off by a
     * bundle whose link is stale.
     *
     * @return SugarBean[]
     */
    private function erpLines(SugarBean $quote): array
    {
        $seed = BeanFactory::newBean('Products');
        if (empty($seed)) {
            return array();
        }

        $query = new SugarQuery();
        $query->select(array('id'));
        $query->from($seed);
        $query->where()->equals('quote_id', $quote->id);
        $query->where()->notEquals('erp_total_role', '');

        $out = array();
        foreach ($query->execute() as $row) {
            $line = BeanFactory::retrieveBean('Products', $row['id'], array('use_cache' => false));
            if (!empty($line)) {
                $out[] = $line;
            }
        }
        return $out;
    }

    private function money($value, string $label): float
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new UnexpectedValueException($label . ' is unavailable');
        }
        return (float) $value;
    }
}
