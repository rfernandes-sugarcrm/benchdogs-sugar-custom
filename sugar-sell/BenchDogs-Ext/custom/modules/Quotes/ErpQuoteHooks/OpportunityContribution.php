<?php

/**
 * Bench Dogs implementation of ERP-Core's neutral Opportunity contribution
 * contract. It supplies a number only; ERP-Core remains the sole writer.
 *
 * READS THE NATIVE QUOTE LINES. Decision 901/903 retired the bd01_* quote
 * mirror, and this class used to walk it:
 *
 *     $quote->bd01_erp_quote_quotes -> bd01_erp_quote_lines
 *            -> the one `governing` line + the optional `prototype` line
 *
 * 🚩 THAT WALK FAILED SILENTLY WHEN THE MIRROR WENT. Its first guard was
 * `if (!load_relationship('bd01_erp_quote_quotes')) return null;`, which every
 * quote satisfies once the module is gone — so the contract returned "no
 * contribution" for the whole estate, with no exception and no log line, and
 * ERP-Core (the sole writer) wrote nothing. The pipeline would quietly stop
 * being valued. Deleting the mirror without rewriting this was a silent
 * regression, not a cleanup.
 *
 * WHY THE NATIVE READ IS ALSO THE SIMPLER ONE
 *
 * `erp_total_role` already states, per line, whether that line belongs in the
 * quote's money. Measured live on Bench (2026-09-18), quote 641e586c:
 *
 *     part            brk  governing  erp_total_role  subtotal
 *     ETO-PENDING       1  false      counts            450.00   <- prototype
 *     BD-ENDCAP-ALU    50  TRUE       counts           6400.00   <- governing
 *     BD-ENDCAP-ALU    75  false      alternative          0.00
 *     BD-ENDCAP-ALU   100  false      alternative          0.00
 *
 * The rung ladder is visible as ordinary Quote lines, the unselected rungs are
 * `alternative` AND already carry subtotal 0, and the two lines that count are
 * exactly the governing production rung and the prototype. So the old
 * hand-reconstruction of "one governing + maybe a prototype" is simply
 * `erp_total_role === 'counts'` — the same answer, computed upstream, instead
 * of re-derived here from two flags on a mirrored table.
 *
 * `doc_ext_price` does not exist on a native line; `subtotal` is the native
 * carrier of the accepted per-line money (50 x 128.00 = 6400.00 above). This
 * class still does not recalculate unit price or mutate native Quote lines to
 * make a display total match reporting.
 */
class ErpQuoteOpportunityContribution
{
    /** The line roles that belong in the Opportunity's money. */
    const ROLE_COUNTS = 'counts';

    /** An unselected rung of a price ladder. Excluded, and already zeroed. */
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

        // Fail closed on an ambiguous ladder, exactly as before. More than one
        // governing rung is a selection bug, and guessing which one wins would
        // put a wrong number on a forecast.
        //
        // Counted with a plain loop on purpose: ModuleScanner DENYLISTS
        // array_filter (it takes a callable), and a package that uses it is
        // refused by the SugarCloud hosted scan - "Code attempted to call
        // denylisted function array_filter" - so the whole release fails to
        // install. Measured on Bench 2026-09-18, not guessed from the docs.
        $governingCount = 0;
        foreach ($lines as $line) {
            if (!empty($line->erp_governing)) {
                $governingCount++;
            }
        }
        if ($governingCount > 1) {
            throw new UnexpectedValueException(
                'Exactly one governing production option is required'
            );
        }

        $amount = 0.0;
        $counted = 0;
        foreach ($lines as $line) {
            if ((string) $line->erp_total_role !== self::ROLE_COUNTS) {
                continue;
            }
            $amount += $this->money($line->subtotal ?? null, 'ERP line amount');
            $counted++;
        }

        // Every rung `alternative` and nothing counting is not "zero pounds of
        // business" — it is a ladder with no rung selected yet. Refuse rather
        // than contribute 0, which would read as a lost deal.
        if ($counted === 0) {
            throw new UnexpectedValueException(
                'No quote line counts toward the total; a governing option has not been selected'
            );
        }

        // A sparse/stale Quote read is not evidence of zero commercial charges.
        // Explicit native zero remains valid; unknown charges preserve the
        // prior Opportunity value through the shared failure boundary.
        $amount += $this->money($quote->tax ?? null, 'Quote tax');
        $amount += $this->money($quote->shipping ?? null, 'Quote shipping');

        return $amount;
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
