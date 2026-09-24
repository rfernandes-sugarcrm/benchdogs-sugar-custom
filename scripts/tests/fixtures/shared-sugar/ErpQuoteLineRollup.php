<?php

/**
 * Rolls a quote's LINE ITEMS up into the two numbers an opportunity actually
 * needs: what the customer has committed to, and what is still winnable.
 *
 * WHY THIS EXISTS. The opportunity amount is the quote's grand total - one
 * number standing in for three different things: lines already ordered, lines
 * still open, and, the expensive one, alternative quantity breaks that can
 * never all be sold. A quote offering 25 / 50 / 75 of the same part is quoting
 * ONE sale, but summing its lines values the deal at all three. Every such
 * quote inflates the forecast and nothing in the pipeline number says so.
 *
 * PURE ON PURPOSE. No SugarBean, no globals, no config lookup, no logging -
 * plain arrays in, plain floats out. That is what makes it testable at all:
 * this package ships no PHPUnit and no Sugar test harness, so the only
 * arithmetic anyone can actually verify is arithmetic that needs no Sugar to
 * run. Bean reading, currency conversion and config lookup stay in
 * ErpOpportunityRollup, where they belong. See tests/ErpQuoteLineRollupTest.php.
 *
 * ORDERED IS ALWAYS A PLAIN SUM. An ordered line is a fact - it went to the
 * ERP and came back confirmed. Two ordered lines of the same part are two real
 * releases, never two readings of one sale, so no grouping policy may ever
 * collapse them. Only the OPEN side is a forecast, and only a forecast gets a
 * policy applied to it.
 *
 * A PIN BEATS THE POLICY. A line may carry 'governing' - a seller has said
 * THIS is the line of its group that counts. A policy guesses which of several
 * alternatives will sell; a pin is someone who knows, so where both have an
 * opinion the pin wins, whatever the policy is set to. The rule is deliberately
 * small and deliberately generic:
 *
 *   - a group with a pin is worth its PINNED lines and nothing else;
 *   - a group that holds a pin AND an ordered line is worth ZERO open -
 *     whether or not the ordered line is the pinned one. A pin asserts that
 *     the group's lines are alternatives to one another, and one of them has
 *     now become a fact, counted in 'ordered';
 *   - a group with no pin is valued by the configured policy, exactly as
 *     before - and when NOTHING anywhere is pinned this class behaves
 *     identically to the version that had no pin at all.
 *
 * TWO PINS IN ONE GROUP BOTH COUNT, ON PURPOSE. This is a shared package that
 * installs on every tenant; it knows nothing about quantity-break ladders,
 * about picking a lowest total, or about refusing a second selection. A
 * customer package that wants "exactly one per group" enforces that where the
 * flag is written, and makes the two-pin state unreachable. If this class
 * started refusing or collapsing two pins it would be guessing on behalf of
 * tenants whose alternatives genuinely are additive.
 */
class ErpQuoteLineRollup
{
    /**
     * Every line stands alone: open = every unordered line.
     *
     * This WAS the whole meaning of 'sum' and is still its meaning for any
     * group with no pinned line - which is every group on every tenant that
     * never writes erp_governing. Where a group IS pinned, sum no longer means
     * "every unordered line of that group": it means the pinned lines, and the
     * losing alternatives drop out. See the class docblock.
     */
    public const POLICY_SUM = 'sum';

    /** Lines sharing a group are alternatives; count the largest still open. */
    public const POLICY_MAX = 'max';

    /** Lines sharing a group are alternatives; count the smallest still open. */
    public const POLICY_MIN = 'min';

    public const POLICIES = [self::POLICY_SUM, self::POLICY_MAX, self::POLICY_MIN];

    /**
     * @param array  $lines  each ['group' => string, 'quantity' => float,
     *                       'price' => float, 'ordered' => bool,
     *                       'governing' => bool, 'origin' => string].
     *                       'governing' and 'origin' are both optional, and
     *                       their absent values - false and '' - are the
     *                       behaviour this class had before either existed,
     *                       so a caller that has never heard of pinning gets
     *                       the old answers. 'origin' says where the line
     *                       came from: two lines are alternatives to one
     *                       another only if they share a group AND an origin.
     *                       'discount' is this line's concession in DOCUMENT
     *                       CURRENCY - already resolved, see lineValue().
     * @param string $policy one of POLICIES; anything else falls back to
     *                       POLICY_SUM rather than throwing - a typo in an
     *                       admin setting must not be able to stop an order
     *                       being recorded, and sum is the pre-existing
     *                       behaviour, so the fallback is also the safe one.
     *
     * @return array{ordered: float, open: float, total: float}
     */
    public static function compute(array $lines, string $policy = self::POLICY_SUM): array
    {
        if (!in_array($policy, self::POLICIES, true)) {
            $policy = self::POLICY_SUM;
        }

        $ordered = 0.0;
        $openFlat = 0.0;
        // TWO PARTITIONS OF THE SAME LINES, AND THE REASON IS A REAL CONFLICT
        // BETWEEN TWO RULES, NOT FASTIDIOUSNESS.
        //
        // $groups is the LEGACY partition - groupKey(), byte-for-byte what
        // every previous release used. Only the no-pin branch reads it, so
        // "no pin anywhere means the numbers this class produced before"
        // stays literally true for every tenant under every policy.
        //
        // $pinGroups is the CORRECTED partition - pinGroupKey(), which
        // case-folds. Only the pinned branch reads it. Folding is not a
        // tidy-up: where one part number IS one ladder, two casings of that
        // part number are the SAME ladder, and splitting them hands a
        // customer package two groups where the seller sees one - so its
        // "exactly one per group" rule is satisfied twice and the forecast
        // silently counts two rungs of a ladder that can only sell one.
        //
        // Folding the legacy key instead would have moved max/min output on
        // tenants that never pinned anything, which is exactly what the
        // no-pin guard exists to forbid. Keeping one unfolded key would have
        // left the splitting bug in the path that now decides the money.
        // Neither rule gives way: the legacy partition stays frozen for the
        // legacy path and the corrected one serves the new path.
        $groups = [];
        $pinGroups = [];
        $anyPin = false;

        foreach ($lines as $i => $line) {
            $value = self::lineValue($line);
            $key = self::groupKey($line, $i);
            $pinKey = self::pinGroupKey($line, $i);

            if (!isset($groups[$key])) {
                $groups[$key] = self::emptyGroup();
            }
            if (!isset($pinGroups[$pinKey])) {
                $pinGroups[$pinKey] = self::emptyGroup();
            }

            // RECORDED BEFORE THE ORDERED SHORT-CIRCUIT BELOW, WHICH IS THE
            // WHOLE POINT. An ordered line leaves this loop at the `continue`,
            // so a pin noticed any later than here would never be seen on the
            // very line that won. The group would read as unpinned the moment
            // the seller's chosen rung was released to the ERP, and its losing
            // alternatives would flood straight back into the forecast - the
            // number jumping UP at the exact moment the deal was partly won.
            if (!empty($line['governing'])) {
                $pinGroups[$pinKey]['pinned'] = true;
                $anyPin = true;
            }

            if (!empty($line['ordered'])) {
                $ordered += $value;
                $groups[$key]['settled'] = true;
                $pinGroups[$pinKey]['settled'] = true;
                continue;
            }

            $openFlat += $value;
            $groups[$key]['values'][] = $value;
            $pinGroups[$pinKey]['values'][] = $value;

            // Only an OPEN pin has an open value to contribute. An ordered
            // pin's money is already in $ordered; adding it here would count
            // the same line twice.
            if (!empty($line['governing'])) {
                $pinGroups[$pinKey]['pinnedOpen'][] = $value;
            }
        }

        if (!$anyPin) {
            // NOTHING IS PINNED ANYWHERE - and this block is the original
            // code, unchanged, reached by every tenant that never writes
            // erp_governing. It is kept whole and separate rather than folded
            // into the general case below so that "no pin anywhere behaves
            // exactly as it did before" is a property of the SHAPE of this
            // method, not merely of its arithmetic. This package ships to
            // every tenant; that promise is the one that must not rot.
            if ($policy === self::POLICY_SUM) {
                $open = $openFlat;
            } else {
                $open = 0.0;
                foreach ($groups as $group) {
                    // A group with an ordered line is decided. Its remaining
                    // siblings are not "still open" - they are the options the
                    // customer did not take, and counting them re-inflates exactly
                    // what this class exists to fix.
                    if ($group['settled'] || $group['values'] === []) {
                        continue;
                    }
                    $open += $policy === self::POLICY_MAX
                        ? max($group['values'])
                        : min($group['values']);
                }
            }
        } else {
            // AT LEAST ONE PIN EXISTS. Each group is settled on its own terms:
            // a pinned group by its pin, an unpinned group by the configured
            // policy. Note where this sits - BEFORE any branch on $policy, so a
            // pin is honoured under 'sum' too. Putting it inside the else above
            // would have been dead code on the only policy in production: under
            // POLICY_SUM $open is just $openFlat and the $groups loop never
            // runs at all.
            //
            // SAID OUT LOUD BECAUSE IT NARROWS THE DESIGN'S PER-GROUP WORDING:
            // once ANY line on this quote is pinned, the UNPINNED groups of the
            // same quote are valued over the corrected partition too, not the
            // legacy one. Under 'sum' that is no difference at all - a sum does
            // not care how the lines are partitioned. Under 'max'/'min' an
            // unpinned group whose members differ only by casing, or by origin,
            // answers differently than it would have. That is the right answer
            // by the same argument that justifies the corrected partition in
            // the first place; it is recorded here because "a group with no pin
            // is valued exactly as before" is true of every tenant with no pin
            // anywhere, and only ALMOST true of an unpinned group sitting
            // beside a pinned one.
            $open = 0.0;
            foreach ($pinGroups as $group) {
                if ($group['pinned']) {
                    // A FACT BEATS A PIN. Once ANY line of a pinned group has
                    // been ordered, that group's question is answered: a pin
                    // says "these lines are alternatives to one another", and
                    // one of the alternatives has become a real release. What
                    // is left is not "still winnable", it is the options the
                    // customer did not take - the same reading the unpinned
                    // max/min path has always applied to a settled group at
                    // :148. Without this the open value survives the release
                    // while the ordered value is added beside it, so the
                    // opportunity's total JUMPS UP by the whole order at the
                    // instant the deal is partly won.
                    //
                    // Otherwise: sum, not max/min/first - two pins in one
                    // group both count.
                    $open += $group['settled'] ? 0.0 : array_sum($group['pinnedOpen']);
                    continue;
                }
                $open += self::openByPolicy($group, $policy);
            }
        }

        $ordered = round($ordered, 2);
        $open = round($open, 2);

        return [
            'ordered' => $ordered,
            'open' => $open,
            'total' => round($ordered + $open, 2),
        ];
    }

    /**
     * WHAT ONE LINE IS WORTH: quantity x price, LESS the concession the seller
     * gave on it.
     *
     * 🛑 THE DISCOUNT TERM IS WHY G89 EXISTED. This was `quantity * price` and
     * nothing else, so a quote carrying per-line discounts produced TWO sums of
     * the same lines that could not agree: this class's figure, published to
     * Opportunities.erp_open_amount and added into Opportunities.amount, and
     * Sugar's own enforced Grand Total (Quotes.new_sub = the bundles'
     * subtotal - deal_tot, a line contributing Products.total_amount). Measured
     * live on Bench quote 273, 2026-09-21: erp_open_amount 25,100.00 beside
     * Quotes.total 24,100.00 - over by exactly the 402.33 + 597.67 the seller
     * had taken off two lines. The manager's pipeline read 1,000.00 more than
     * the number printed on the customer's quote. See
     * tests/ErpQuote273LineDiscountProbeTest.php.
     *
     * A CURRENCY AMOUNT, ALREADY RESOLVED, AND THAT SPLIT IS DELIBERATE. Sugar
     * spells one concession two ways - a percent of the line when
     * Products.discount_select is set, flat dollars otherwise. Teaching this
     * class that flag would mean teaching it Sugar, and its whole value is that
     * it needs no Sugar to run (see the class docblock). The translation lives
     * with the bean reader, in ErpOpportunityValuation::lineDiscount(); this
     * takes the money.
     *
     * ABSENT IS 0.0, WHICH IS THE POINT. Every caller that has never heard of
     * the key - and every fixture written before it - gets the arithmetic this
     * class always had, byte for byte. Same promise, same reason, as
     * 'governing' and 'origin' beside it.
     *
     * NOT CLAMPED AT ZERO. Sugar permits a discount larger than the line, and
     * a line that is genuinely a credit is worth a negative number. Flooring it
     * would fabricate a zero over a figure the quote itself carries - decision
     * 59's defect, with the sign reversed.
     *
     * NON-NUMERIC IS 0.0 AND NEVER A THROW. This runs inside an after_save hook
     * on Quotes; a malformed cell must not be able to fail a seller's save. The
     * quantity and price beside it already degrade the same way.
     */
    private static function lineValue(array $line): float
    {
        $gross = (float) ($line['quantity'] ?? 0) * (float) ($line['price'] ?? 0);
        $discount = $line['discount'] ?? null;

        return is_numeric($discount) ? $gross - (float) $discount : $gross;
    }

    /**
     * One UNPINNED group's open value under the configured policy - today's
     * rule, per group.
     *
     * Under 'sum' that is every unordered line of the group. Under 'max'/'min'
     * it is the surviving extremum, and a group holding an ordered line is
     * already decided, so its losing siblings contribute nothing.
     *
     * THIS ROUTE IS NOT INTERCHANGEABLE WITH $openFlat, AND AN EARLIER VERSION
     * OF THIS DOCBLOCK CLAIMED IT WAS. It said the two "agree by construction
     * because every open line is pushed into exactly one group". They select
     * the same lines; they do not always produce the same float. $openFlat
     * accumulates in LINE order and this accumulates per group, and IEEE-754
     * addition is not associative, so the two can differ in the last bits and
     * land either side of a cent once rounded. Products.discount_price is
     * currency '26,6' (SugarEnt 26.1.0 modules/Products/vardefs.php:356-359),
     * so six-decimal operands are a real input, not a contrived one.
     *
     * That is why the no-pin branch above is kept as a separate block rather
     * than being folded into this general path: not merely so the shape says
     * "unchanged", but because folding it would move real tenants' numbers by
     * a cent with nothing to say why. tests/ErpQuoteLineRollupTest.php carries
     * a fixture that fails if anyone tries - see GUARD SUMMATION ORDER.
     *
     * @param array{values: float[], settled: bool} $group
     */
    private static function openByPolicy(array $group, string $policy): float
    {
        if ($policy === self::POLICY_SUM) {
            return (float) array_sum($group['values']);
        }

        if ($group['settled'] || $group['values'] === []) {
            return 0.0;
        }

        return (float) ($policy === self::POLICY_MAX
            ? max($group['values'])
            : min($group['values']));
    }

    /**
     * A line with no group identifier is UNGROUPABLE, not a member of one big
     * blank group. Collapsing every unidentified line together would silently
     * discard real, independent lines the moment a part number is missing, and
     * the symptom would read as a pricing bug rather than a grouping one.
     * Falling back to the line's own index makes each such line its own group,
     * so an alternatives policy degrades to sum for exactly the lines it cannot
     * reason about, and for no others.
     *
     * @param int|string $index
     */
    private static function groupKey(array $line, $index): string
    {
        $group = trim((string) ($line['group'] ?? ''));

        return $group === '' ? '#ungrouped-' . $index : 'g:' . $group;
    }

    /**
     * The group key the PIN path uses: groupKey(), case-folded.
     *
     * WHY FOLDING IS LOAD-BEARING HERE AND NOT IN groupKey(). A group key
     * names a part; a part number is an identifier, and identifiers do not
     * change identity with their casing. 'BD-ENDCAP-ALU' and 'BD-Endcap-ALU'
     * on one quote are one ladder that a seller sees as one choice. Leave
     * them split and a customer package enforcing "exactly one selection per
     * group" is satisfied by one pin in EACH half, so two rungs of a ladder
     * that can only ever sell one are both counted - and nothing refuses,
     * because from inside either half the state is correct. There is no error
     * surface for this anywhere, which is what makes it worth code.
     *
     * WHY NOT JUST FOLD groupKey(). Because that key still serves the no-pin
     * path, and folding it there would change max/min output for mixed-case
     * groups on tenants that never wrote a pin. This package installs on
     * every tenant; moving a number for someone who opted into nothing is the
     * one outcome the no-pin guard exists to prevent. Folding is right, but
     * it is a change, and a change belongs in the path that is already new.
     *
     * mb_strtoupper where the extension is present (it is, wherever Sugar
     * runs) and strtoupper otherwise, because this class is deliberately
     * runnable on bare PHP with nothing loaded - see the class docblock.
     *
     * @param int|string $index
     */
    private static function pinGroupKey(array $line, $index): string
    {
        $group = trim((string) ($line['group'] ?? ''));

        if ($group === '') {
            return '#ungrouped-' . $index;
        }

        $folded = function_exists('mb_strtoupper')
            ? mb_strtoupper($group, 'UTF-8')
            : strtoupper($group);

        // ORIGIN IS PART OF THE KEY. Two lines are alternatives to one another
        // only if they share a part number AND came from the same place. A
        // line the ERP delivered as one rung of a priced ladder and a line a
        // rep typed by hand are not two readings of one sale even when they
        // carry the same part number - they are a quantity break and an extra
        // item, and the second one is real money somebody expects to invoice.
        //
        // This matters ONLY because a pin now deletes a group's unpinned
        // members from the open value. Without the split, pinning a rung
        // silently subtracts the rep's own line from the forecast: no error,
        // no visual difference in the grid, and a customer package's
        // exactly-one rule sees one pin in the group and is satisfied. It is
        // not hypothetical - the design of record's own migration step says
        // Sugar-authored free-text lines "share mft_part_num with core rungs
        // once Kinetic prices the quote".
        //
        // An absent 'origin' means every line shares one origin, which is
        // exactly the partition this class used before the key existed - so a
        // caller that has never heard of it is unaffected.
        $origin = trim((string) ($line['origin'] ?? ''));

        return 'g:' . $origin . '|' . $folded;
    }

    /**
     * @return array{values: float[], settled: bool, pinned: bool, pinnedOpen: float[]}
     */
    private static function emptyGroup(): array
    {
        return ['values' => [], 'settled' => false, 'pinned' => false, 'pinnedOpen' => []];
    }
}
