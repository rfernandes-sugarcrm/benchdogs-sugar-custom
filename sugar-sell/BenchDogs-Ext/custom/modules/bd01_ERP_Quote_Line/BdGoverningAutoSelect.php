<?php

/**
 * Decision 72: when NOTHING is governing on a Bench Dogs ERP quote, select the
 * LOWEST-TOTAL production line, and mark that selection as machine-made.
 *
 * This AMENDS decision 29, which required the Opportunity valuation to REFUSE
 * while zero lines were governing. The user's words, 2026-09-14:
 *
 *     "1. if nothing is selcted take the lowest amount 2. just fix it to
 *      select one"
 *     "and mark it as selected so you can have an esmiation of the opertunity
 *      dont leave it blank to begin with"
 *
 * The second message is why this class WRITES `governing = 1` instead of
 * quietly computing a number behind a still-unselected quote. A quote that
 * values its Opportunity while showing nobody a chosen line is a second
 * invisible state, and the point of the amendment is that the deal is not
 * blank to begin with.
 *
 * WHAT THIS CLASS MUST NEVER DO - each of these is a decision, not a taste
 *
 *  1. It must never resolve an AMBIGUITY. Two or more governing lines still
 *     fail closed: ErpQuoteOpportunityContribution::resolve() throws, the
 *     shared writer catches, the previous Opportunity amount is preserved.
 *     Decision 72 amends the ZERO case and nothing else, and D29-R1's atomic
 *     selection fix (0577bc0) is untouched by this file - it holds the lock,
 *     this one never takes it.
 *  2. It must never re-point, demote or overwrite a HUMAN selection. Only a
 *     person may move a line a person chose. The marker is what makes that
 *     distinction decidable, which is the whole reason the marker exists.
 *  3. It must never treat an unreadable amount as a cheap one. A missing
 *     doc_ext_price is refused, not read as 0.00 - and a 0.00 read from
 *     nowhere is exactly what put 994 fabricated rows on a tenant (decision
 *     59). `money()` here mirrors the provider's rule deliberately.
 *  4. It must never walk the quotes of a tenant. There is no sweep in this
 *     class and nothing in the install calls it. The one retroactive route is
 *     scripts/bd_governing_backfill.php, which is dry-run by default and is
 *     wired to no hook, no installdef and no schedule.
 *
 * WHERE IT IS CALLED FROM, which IS the blast radius
 *
 *  - BdGoverningAutoSelectHook, on bd01_ERP_Quote_Line after_save, ONLY when
 *    `$arguments['isUpdate']` is false - i.e. only for a line that has just
 *    been CREATED - and on after_relationship_add when a line is linked to its
 *    ERP quote (the connector creates and links in separate calls, so without
 *    the second event a Kinetic-born quote would never converge).
 *  - Nothing else. In particular it is deliberately NOT called from
 *    BdQuoteReflectionHook::refreshOpportunityAmount(): that is the rollup
 *    funnel every trigger converges on, so calling it there would auto-select
 *    every existing quote on the next connector resync. That is the code path
 *    that would have retro-selected, and it is left unwired on purpose.
 *
 * The consequence, stated so it can be checked rather than assumed: a line
 * that already exists is never created again, so the lines already on a tenant
 * cannot be reached by the hook path at all. Installing this package selects
 * nothing.
 */
class BdGoverningAutoSelect
{
    /** The selection was made by this class. */
    public const ORIGIN_AUTO = 'auto';

    /** The selection was made by a person (or by anything that is not us). */
    public const ORIGIN_HUMAN = 'human';

    /**
     * Test seam ONLY. Null means "ask the instance". Production code must not
     * set this; the operator switch is the config key read by enabled().
     */
    public static ?bool $enabledOverride = null;

    /**
     * True while this class is writing a line, so BdGoverningLineHook can tell
     * our own selection from a person's and leave our marker alone. The hook
     * clears the marker on every OTHER route into `governing = 1`, which is
     * what makes decision 72 item 4 - "the marker clears the moment a person
     * sets the governing line" - true without a second code path.
     */
    private static bool $applying = false;

    public static function isApplying(): bool
    {
        return self::$applying;
    }

    /**
     * Operator kill switch. `bench_dogs.governing_autoselect === false` in
     * config_override.php restores rc26 behaviour exactly: nothing is written,
     * zero lines are selected and the valuation refuses as decision 29 said.
     *
     * Absent config means ENABLED, because decision 72 is the product's
     * intended behaviour and a missing key must not silently withdraw it. The
     * switch exists so that arming or disarming the forward path on a tenant
     * whose evidence base is being graded is one config line, not a rebuild.
     */
    public static function enabled(): bool
    {
        if (self::$enabledOverride !== null) {
            return self::$enabledOverride;
        }
        $config = $GLOBALS['sugar_config']['bench_dogs']['governing_autoselect'] ?? true;
        return $config !== false && $config !== 0 && $config !== '0' && $config !== 'false';
    }

    /**
     * THE RULE. "Lowest" means LOWEST TOTAL - the smallest extended price -
     * and that reading is the USER'S OWN, confirmed 2026-09-14 ~21:45Z
     * ("confirm 'lowest' = lowest total confirmed"), recorded in journal
     * section D72-1.
     *
     * THE OTHER READING EXISTS AND IS NOT IMPLEMENTED. "Lowest" could equally
     * mean the lowest UNIT price, and on a quantity-break ladder the two
     * readings choose OPPOSITE ENDS of it. On Kinetic quote 1193, whose breaks
     * are 6,400 / 8,400 / 9,600 for 50 / 75 / 100 units:
     *
     *     lowest TOTAL      -> the 50-unit break,  6,400   <- implemented
     *     lowest UNIT price -> the 100-unit break, 9,600   <- not implemented
     *
     * Lowest total is the conservative forecast: it is the one least likely to
     * overstate pipeline on a deal nobody has reviewed yet.
     *
     * THIS METHOD IS THE FLIP POINT. Changing the reading means changing the
     * comparison below from doc_ext_price to doc_unit_price and nothing else -
     * no other file in this package encodes which end of the ladder wins.
     *
     * Ties are broken by the lowest line_num, then by id, so two refreshes of
     * the same quote cannot oscillate between two equally cheap breaks.
     *
     * @param SugarBean[] $productionLines candidates, prototypes already removed
     * @throws UnexpectedValueException if any candidate's amount is unreadable
     */
    public static function lowestTotalLine(array $productionLines): ?SugarBean
    {
        $best = null;
        $bestAmount = null;
        foreach ($productionLines as $line) {
            // Read EVERY candidate, not just the ones cheaper than the leader.
            // An unreadable amount on any line makes the whole comparison
            // unsafe: we cannot know it was not the cheapest.
            $amount = self::money($line->doc_ext_price ?? null, 'ERP line amount');
            if ($bestAmount === null
                || $amount < $bestAmount
                || ($amount === $bestAmount && self::precedes($line, $best))
            ) {
                $best = $line;
                $bestAmount = $amount;
            }
        }
        return $best;
    }

    /**
     * Apply decision 72 to ONE ERP quote. Idempotent: running it again on a
     * settled quote writes nothing, which is what lets every trigger converge
     * on the same rows instead of churning commercial data.
     *
     * @param bool $dryRun report the verdict and write nothing. This is the
     *                     backfill's DEFAULT mode, and the reason the verdict
     *                     is returned rather than only logged.
     * @return array{action:string,line_id:?string,amount:?float,from_line_id:?string}
     */
    public function applyToQuote(SugarBean $erpQuote, bool $dryRun = false): array
    {
        if (!self::enabled()) {
            return self::verdict('disabled');
        }

        $lines = $this->productionLines($erpQuote);
        if ($lines === null) {
            return self::verdict('unreadable');
        }

        $governing = [];
        foreach ($lines as $line) {
            if (!empty($line->governing)) {
                $governing[] = $line;
            }
        }

        // FAIL CLOSED, unchanged by decision 72. The machine does not get to
        // pick between two things a person may have selected on purpose.
        if (count($governing) > 1) {
            return self::verdict('ambiguous');
        }

        if (count($governing) === 1) {
            $held = $governing[0];
            if ((string) ($held->bd_governing_origin ?? '') !== self::ORIGIN_AUTO) {
                // A person chose this. Nothing here may move it.
                return self::verdict('human', $held);
            }
            // Our own earlier pick. The connector creates lines ONE AT A TIME,
            // so the first break to arrive is not the cheapest one; an
            // auto-selection has to follow the ladder down as siblings appear.
            $lowest = self::lowestTotalLine($lines);
            if ($lowest === null || $lowest->id === $held->id) {
                return self::verdict('unchanged', $held);
            }
            if (!$dryRun) {
                $this->write($lowest, $held);
            }
            return self::verdict('reselected', $lowest, $held);
        }

        if ($lines === []) {
            // A prototype-only quote has no production option to value from.
            // Refusing is correct and is NOT the state decision 72 amended.
            return self::verdict('no-candidates');
        }

        $lowest = self::lowestTotalLine($lines);
        if ($lowest === null) {
            return self::verdict('no-candidates');
        }
        if (!$dryRun) {
            $this->write($lowest, null);
        }
        return self::verdict('selected', $lowest);
    }

    /**
     * What the Opportunity marker should read for this quote, DERIVED from the
     * lines rather than remembered. A derived marker cannot drift into a lie:
     * it says what the rows currently say, every time it is written.
     *
     * '' (empty) when there is nothing to report - zero selected, or the
     * ambiguous state that still fails closed. Empty reads empty; it is never
     * a default, and no vardef supplies one.
     */
    public static function classify(SugarBean $erpQuote): string
    {
        $self = new self();
        $lines = $self->productionLines($erpQuote);
        if ($lines === null) {
            return '';
        }
        $governing = [];
        foreach ($lines as $line) {
            if (!empty($line->governing)) {
                $governing[] = $line;
            }
        }
        if (count($governing) !== 1) {
            return '';
        }
        return (string) ($governing[0]->bd_governing_origin ?? '') === self::ORIGIN_AUTO
            ? self::ORIGIN_AUTO
            : self::ORIGIN_HUMAN;
    }

    /**
     * Production lines of the quote - prototypes excluded, because a prototype
     * is never the governing production option (it contributes alongside one).
     * Null means the lines could not be read at all, which is not the same as
     * a quote that has none.
     */
    private function productionLines(SugarBean $erpQuote): ?array
    {
        if (!$erpQuote->load_relationship('bd01_erp_quote_lines')
            || !$erpQuote->bd01_erp_quote_lines
            || !is_object($erpQuote->bd01_erp_quote_lines)
        ) {
            return null;
        }
        $lines = [];
        foreach ($erpQuote->bd01_erp_quote_lines->getBeans() as $line) {
            if (empty($line->prototype)) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /**
     * Promote $keep, demote $drop. Demote FIRST is wrong and promote first is
     * right: BdGoverningLineHook fires on each save, and a quote that passes
     * through "two selected" is a state the provider already refuses, while a
     * quote that passes through "zero selected" is the state D29-R1 was
     * written to make unreachable.
     */
    private function write(SugarBean $keep, ?SugarBean $drop): void
    {
        self::$applying = true;
        try {
            $keep->governing = 1;
            $keep->bd_governing_origin = self::ORIGIN_AUTO;
            $keep->save();
            $GLOBALS['log']->info(
                'BdGoverningAutoSelect: auto-selected line ' . $keep->id
                . ' (lowest total ' . (string) $keep->doc_ext_price . ') - no line was governing'
            );
            if ($drop !== null && $drop->id !== $keep->id) {
                $drop->governing = 0;
                $drop->bd_governing_origin = '';
                $drop->save();
                $GLOBALS['log']->info(
                    'BdGoverningAutoSelect: released its earlier auto-selection ' . $drop->id
                    . ' in favour of the lower total on ' . $keep->id
                );
            }
        } finally {
            self::$applying = false;
        }
    }

    /** Deterministic tie-break: lowest line_num, then lowest id. */
    private static function precedes(SugarBean $candidate, ?SugarBean $incumbent): bool
    {
        if ($incumbent === null) {
            return true;
        }
        $a = (int) ($candidate->line_num ?? 0);
        $b = (int) ($incumbent->line_num ?? 0);
        if ($a !== $b) {
            return $a < $b;
        }
        return strcmp((string) $candidate->id, (string) $incumbent->id) < 0;
    }

    /**
     * Same rule as ErpQuoteOpportunityContribution::money() and deliberately
     * so: an absent, blank or non-numeric amount is UNAVAILABLE, never zero.
     */
    private static function money($value, string $label): float
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new UnexpectedValueException($label . ' is unavailable');
        }
        return (float) $value;
    }

    private static function verdict(
        string $action,
        ?SugarBean $line = null,
        ?SugarBean $from = null
    ): array {
        return array(
            'action' => $action,
            'line_id' => $line === null ? null : (string) $line->id,
            'amount' => $line === null || !is_numeric($line->doc_ext_price ?? null)
                ? null
                : (float) $line->doc_ext_price,
            'from_line_id' => $from === null ? null : (string) $from->id,
        );
    }
}
