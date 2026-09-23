<?php

/**
 * Partial Fulfillment's answer to core's open-pursuit question: "what is this
 * Opportunity worth while the quote is still live?"
 *
 * 🔒 1468, THE ANSWER, IN THE OWNER'S WORDS: "always take the total form
 * priamry even if Epicor di dnot procide tax or shipping yet, what ever the
 * user mark as paimry take the total from there".
 *
 * So the headline is the PRIMARY QUOTE'S OWN GRAND TOTAL (Quotes.total), in
 * the quote's currency (core converts it into the Opportunity's), on every
 * save, whether or not the ERP has stated its tax and freight yet. This is the
 * same figure PF's erp_open_amount now follows (ErpOpportunityValuation::
 * refreshLineRollup), so the two figures a manager reads side by side agree.
 *
 * WHAT 🔒 1468 RETIRED HERE, AND WHY NOTHING A GUARD PROTECTED IS LOST.
 *   - The line rollup (ErpQuoteLineRollup::compute() under the configured
 *     `sum`) as the source of the number. The Grand Total already applies the
 *     pin: ErpGoverningTotal lets an unpinned group contribute nothing (🔒 29)
 *     and an 'alternative' rung's subtotal extends to 0.00. `sum` did neither
 *     for a line grouped alone. Modelled on Bench quote 285's measured lines
 *     with the ERP's tax stated, `sum` read 11,862.82 against a Grand Total of
 *     8,184.54; the gap is a keyless 50 x 85.91 twin that the total zeroes.
 *   - Decision 122's ERP-stated tax-and-freight ADDITION, and its throw when
 *     the ERP had not yet stated them. That throw is what froze a headline the
 *     way G214 froze erp_open_amount. The Grand Total's own formula carries the
 *     ERP's stated tax and freight (ERP-Core erp_total_carries_erp_tax.php,
 *     🔒 1450), and Sugar's own until they are stated. 1468 accepts that in so
 *     many words.
 *   - Bench Dogs' own copy of this file (BenchDogs-Ext, same installed path,
 *     🔒 708) already returned the quote's total.
 *
 * 🛑 G282 — "SO THE TWO COPIES SAY THE SAME" WAS NOT TRUE, AND THIS FILE SAID
 * IT ANYWAY. Two packages ship this exact path with this exact class name, and
 * whichever installed LAST is the one a tenant runs. Censused 2026-09-22: they
 * agreed on every quote state but ONE. Where every ERP line is an unselected
 * `alternative` rung, the quote's own total is 0.00 by construction (the
 * subtotal formula zeroes those rungs) - and there Bench's copy THREW, so
 * ERP-Core preserved the Opportunity's existing amount, while this copy
 * returned the 0.00 and published it. The live control is Bench quote 1049:
 * four lines, all `alternative`, whose Opportunity an admin had hand-set to
 * 23.00. This copy would have overwritten that with 0.00 the moment it became
 * the installed one.
 *
 * So the preserve behaviour is now HERE, below, and the two copies really do
 * agree - which is what lets Bench's copy be retired.
 *
 * 🛑 AND IT MUST NEVER BE RETIRED BY SHIPPING AN EMPTY STUB AT THIS PATH.
 * ERP-Core's QuoteOpportunityAmount::contribution() requires this file and
 * then instantiates the class; an emptied file leaves the class undefined, the
 * error is caught by refresh()'s Throwable handler, and EVERY Opportunity
 * headline amount silently freezes. The safe retirements are: drop the file
 * from the other package's build and let this copy own the path, or ship that
 * copy carrying this body.
 *
 * WHAT CORE STILL OWNS, UNTOUCHED BY THIS FILE (QuoteOpportunityAmount):
 *   - 🔒 787(4): a primary that is withdrawn (flag cleared, or deleted) puts
 *     the Opportunity into the visible UNVALUED state. No sibling's figure is
 *     promoted into it. That path runs before this provider is ever asked.
 *   - A primary whose total is missing or not a number PRESERVES the previous
 *     amount; core returns before calling this. This file throws on the same
 *     condition, so that any other caller also gets a preserve and never a
 *     guess.
 *   - G199: a primary whose total is a real 0.00 publishes 0.00, never blank.
 *     A numeric zero is a number here.
 *   - G167 I4: erp_amount_source and erp_amount_unvalued stay mutually
 *     exclusive. That is the writer's rule, and this file writes nothing.
 *
 * RETURNS
 *   float  the primary's Grand Total, in the QUOTE's currency
 *   null   a deleted quote. Core then uses $quote->total, as it always has
 *   throws when the total is not a number, and when every ERP line on the
 *          quote is still an unselected rung (G282). Core's contract: an
 *          applicable but unresolved value throws, never returns null, so the
 *          previous amount is preserved and the failure is recorded.
 *
 * This provider READS ONLY. It writes no bean, saves nothing, and must not.
 */
class ErpQuoteOpportunityContribution
{
    /** A line that belongs in the quote's money. */
    const ROLE_COUNTS = 'counts';

    /** An unselected rung of a price ladder. Zeroed by the subtotal formula. */
    const ROLE_ALTERNATIVE = 'alternative';

    /**
     * @return float|null
     */
    public function resolve($quote)
    {
        if (!($quote instanceof SugarBean) || !empty($quote->deleted)) {
            return null;
        }

        // G282 — A LADDER NOBODY HAS PRICED YET IS NOT A ZERO-VALUE DEAL.
        // Every ERP line still `alternative` means the subtotal formula
        // (ERP-Core erp_total_role.php's
        // ifElse(equal($erp_total_role, "alternative"), 0, ...)) has zeroed
        // the quote total by construction. Refusing here is what makes
        // ERP-Core PRESERVE the amount the Opportunity already carries -
        // Bench quote 1049's hand-set 23.00 - instead of publishing 0.00 over
        // it. Returning null would publish the 0.00, because core reads null
        // as "not applicable" and falls back to $quote->total.
        $roleLines = $this->erpRoleLines($quote);
        if ($roleLines !== array()) {
            // A PLAIN LOOP, never array_filter: ModuleScanner denylists every
            // function that takes a callable, and a package that uses one is
            // refused by the SugarCloud hosted scan (measured, ERP-Epicor
            // 1.1.24-rc29: "Code attempted to call denylisted function
            // array_filter").
            $counted = 0;
            foreach ($roleLines as $role) {
                if ($role === self::ROLE_COUNTS) {
                    $counted++;
                }
            }
            if ($counted === 0) {
                throw new \UnexpectedValueException(
                    'This quote is not yet priced: every ERP line is still an unselected'
                    . ' break-ladder rung, so its total is 0.00 by construction (G282)'
                );
            }
        }

        $total = $quote->total ?? null;
        if (is_bool($total) || !is_numeric($total)) {
            throw new \UnexpectedValueException(
                'The primary quote has no numeric total, so there is nothing to take (decision 1468)'
            );
        }

        $amount = (float) $total;
        if (!is_finite($amount)) {
            throw new \UnexpectedValueException('Non-finite Opportunity contribution');
        }

        return $amount;
    }

    /**
     * The erp_total_role of each of this quote's native lines that carries one.
     *
     * Products.quote_id is the direct parent pointer, so this does not need the
     * Quote -> ProductBundles -> Products hop and cannot be thrown off by a
     * bundle whose link is stale - the same read Bench's copy used, kept
     * identical on purpose so the two answers cannot drift again.
     *
     * Roles only: this provider reads no money off a line (🔒 708 / G97). An
     * unreadable line set answers array(), which leaves the quote's own total
     * to speak - never a refusal invented from a failed read.
     *
     * @return string[]
     */
    private function erpRoleLines($quote): array
    {
        $roles = array();
        try {
            $seed = BeanFactory::newBean('Products');
            if (empty($seed)) {
                return $roles;
            }

            $query = new SugarQuery();
            $query->select(array('erp_total_role'));
            $query->from($seed);
            $query->where()->equals('quote_id', (string) $quote->id);
            $query->where()->notEquals('erp_total_role', '');

            foreach ($query->execute() as $row) {
                $role = trim((string) ($row['erp_total_role'] ?? ''));
                if ($role !== '') {
                    $roles[] = $role;
                }
            }
        } catch (\Throwable $e) {
            $GLOBALS['log']->error(
                'ErpQuoteOpportunityContribution: could not read the line roles on quote '
                . (string) ($quote->id ?? '?') . ' - answering from the quote total alone: '
                . $e->getMessage()
            );

            return array();
        }

        return $roles;
    }
}
