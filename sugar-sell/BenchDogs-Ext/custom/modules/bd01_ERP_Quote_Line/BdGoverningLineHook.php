<?php

/**
 * after_save hook class for bd01_ERP_Quote_Line - see the registration in
 * custom/Extension/modules/bd01_ERP_Quote_Line/Ext/LogicHooks/
 * bd_governing_line.php for why this class lives here and not alongside
 * that registration.
 *
 * Bench Dogs REQ-5/REQ-6: at most ONE line of an ERP quote may be marked
 * governing. When a save flips a line's `governing` flag on, the selection is
 * re-stated for the whole quote - the saved line is asserted as the governing
 * one and every sibling still carrying the flag is flipped off - and the
 * Opportunity amount rollup is refreshed through the same code path
 * BdQuoteReflectionHook uses (so the governing line's extended price takes
 * effect immediately, not only on the next ERP sync).
 *
 * CONCURRENCY (D29-R1, decision 29). Sugar commits the row in
 * SugarBean::save() BEFORE this hook runs, so two simultaneous selections
 * arrive here with BOTH rows already set and each process holding its own
 * in-memory copy saying it is the selection. Demoting on that basis makes the
 * two requests cancel each other out and the quote lands on zero selected,
 * with the Opportunity keeping a number for a line that is no longer chosen
 * (measured live 2026-09-14, 2 of 3 clean trials; journal D29-LIVE-2). Two
 * things therefore have to hold together, and neither works alone:
 *
 *   1. the whole re-statement runs inside a per-quote critical section
 *      (BdGoverningLineLock), so two of them serialise; and
 *   2. inside it the claim is re-read from the database and re-asserted
 *      before any sibling is demoted, because a writer that waited may have
 *      had its own row demoted while it waited.
 *
 * The resulting rule is LAST WRITE WINS: whichever request completes the
 * section last is the selection, which is what an ordinary sequential switch
 * already does. The quote is never left with zero governing lines by this
 * hook - only a person clearing the flag can do that.
 *
 * Clearing the flag deliberately does NOT touch siblings here - "no governing
 * line" is a valid state and un-marking must never guess a replacement. It is
 * NOT true that the rollup then falls back to the whole quote_total: that
 * prose described pre-decision-29 behaviour and contradicted this very file.
 * refreshOpportunityAmount() calls the shared fail-closed writer, whose
 * provider throws unless exactly one production option is selected, so the
 * previous Opportunity amount is preserved and the summed total is never
 * written (confirmed live, journal D29-LIVE-2 A7).
 */
class BdGoverningLineHook
{
    /**
     * Re-entrancy guard: flipping a sibling's flag saves that sibling,
     * which fires this same hook again; nothing in that cascade should
     * re-run the enforcement.
     */
    private static bool $inProgress = false;

    public function enforceSingleGoverning(SugarBean $bean, string $event, array $arguments): void
    {
        if (self::$inProgress) {
            return;
        }

        if (empty($bean->governing)) {
            // Off, or being cleared - nothing to enforce (see class docblock).
            return;
        }

        // Only act on a real transition into governing, not every resave of
        // a line already governing. dataChanges, not fetched_row: after_save
        // fires after SugarBean has overwritten fetched_row with the bean's
        // own post-write values, so fetched_row can never show a transition
        // (see OrderStageOpportunityCascade for the confirmed-live account).
        // On a brand-new record dataChanges carries the initial values as
        // changes, so a line CREATED governing still enforces.
        $governingChange = null;
        foreach ($arguments['dataChanges'] ?? [] as $change) {
            if (($change['field_name'] ?? '') === 'governing') {
                $governingChange = $change;
                break;
            }
        }
        if ($governingChange === null
            || (bool) ($governingChange['before'] ?? false) === (bool) ($governingChange['after'] ?? false)
        ) {
            return;
        }

        self::$inProgress = true;
        try {
            $erpQuote = $this->parentErpQuote($bean);
            if ($erpQuote === null) {
                $GLOBALS['log']->warn(
                    'BdGoverningLineHook: line ' . $bean->id
                    . ' marked governing but has no parent bd01_ERP_Quote - nothing to enforce against'
                );
                return;
            }

            require_once 'custom/modules/bd01_ERP_Quote_Line/BdGoverningLineLock.php';
            $lock = new BdGoverningLineLock();
            // Three states, and they are not interchangeable. No store at all
            // means exclusion is impossible on this instance, so behave as
            // before rather than refusing every selection. A store that is
            // there but busy past the wait means another writer is live right
            // now, and demoting on top of that is precisely the write that
            // lands the quote on zero.
            $available = $lock->isAvailable();
            $exclusive = $available && $lock->acquire((string) $erpQuote->id);
            if (!$available) {
                $GLOBALS['log']->warn(
                    'BdGoverningLineHook: no system process lock available; enforcing the '
                    . 'governing selection of bd01_ERP_Quote ' . $erpQuote->id
                    . ' without exclusivity'
                );
            } elseif (!$exclusive) {
                $GLOBALS['log']->warn(
                    'BdGoverningLineHook: another selection holds bd01_ERP_Quote '
                    . $erpQuote->id . '; asserting this claim without demoting siblings'
                );
            }

            try {
                $this->restateSelection($erpQuote, $bean, $exclusive || !$available);

                // Push the new governing amount onto the Opportunity through
                // the exact rollup path the reflection hook owns - one code
                // path, one set of gates (sugar_quote_id +
                // erp_is_primary_quote). Inside the section, so the value
                // written is the one this section just established.
                require_once 'custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php';
                (new BdQuoteReflectionHook())->refreshOpportunityAmount($erpQuote);
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'BdGoverningLineHook: failed enforcing single governing line for '
                . $bean->id . ': ' . $e->getMessage()
            );
        } finally {
            self::$inProgress = false;
        }
    }

    /**
     * The parent bd01_ERP_Quote of this line, via the bd01_erp_quote_lines
     * relationship (one quote per line - link-type 'one' on this side).
     */
    private function parentErpQuote(SugarBean $bean): ?SugarBean
    {
        $bean->load_relationship('bd01_erp_quote_lines');
        if (!$bean->bd01_erp_quote_lines || !is_object($bean->bd01_erp_quote_lines)) {
            return null;
        }
        $quoteIds = $bean->bd01_erp_quote_lines->get();
        $quoteId = $quoteIds[0] ?? '';
        if ($quoteId === '') {
            return null;
        }
        $erpQuote = BeanFactory::retrieveBean('bd01_ERP_Quote', $quoteId);
        if (!$erpQuote || empty($erpQuote->id)) {
            return null;
        }
        return $erpQuote;
    }

    /**
     * Re-state the quote's whole selection: this line governs, nobody else
     * does. Called inside the critical section.
     *
     * The claim is re-read from the database rather than taken from the bean
     * this hook was handed. That bean says `governing = 1` for the whole of
     * this request no matter what another request did to the stored row, and
     * trusting it is what let two simultaneous selections cancel out (D29-R1).
     *
     * $demote is false only when a store exists and another writer holds the
     * quote. Leaving two lines selected is a state the shared provider already
     * refuses, and one a person can see in the picker; zero selected is
     * neither, so it must not be reachable from here.
     */
    private function restateSelection(SugarBean $erpQuote, SugarBean $keep, bool $demote): void
    {
        $claim = BeanFactory::retrieveBean(
            'bd01_ERP_Quote_Line',
            $keep->id,
            array('use_cache' => false)
        );
        if (!$claim || empty($claim->id) || !empty($claim->deleted)) {
            // Without a readable claim, demoting would leave nothing selected.
            $GLOBALS['log']->warn(
                'BdGoverningLineHook: governing line ' . $keep->id
                . ' could not be re-read; leaving the selection of bd01_ERP_Quote '
                . $erpQuote->id . ' untouched'
            );
            return;
        }
        if (empty($claim->governing)) {
            // Re-assert BEFORE demoting anyone, so the quote goes from two
            // selections to one and never passes through zero - which also
            // keeps the priority-2 rollup fired by each sibling save off the
            // fail-closed path on a legitimate switch.
            $claim->governing = 1;
            $claim->save();
            $GLOBALS['log']->info(
                'BdGoverningLineHook: re-asserted governing line ' . $claim->id
                . ' of bd01_ERP_Quote ' . $erpQuote->id
                . ' after a concurrent selection demoted it'
            );
        }
        if (!$demote) {
            return;
        }
        $this->demoteSiblings($erpQuote, $keep);
    }

    /**
     * Flip `governing` off on every OTHER line of the parent quote that
     * still carries it.
     */
    private function demoteSiblings(SugarBean $erpQuote, SugarBean $keep): void
    {
        $erpQuote->load_relationship('bd01_erp_quote_lines');
        if (!$erpQuote->bd01_erp_quote_lines || !is_object($erpQuote->bd01_erp_quote_lines)) {
            return;
        }
        foreach ($erpQuote->bd01_erp_quote_lines->getBeans() as $line) {
            if ($line->id === $keep->id || empty($line->governing)) {
                continue;
            }
            $line->governing = 0;
            $line->save();
            $GLOBALS['log']->info(
                'BdGoverningLineHook: line ' . $line->id . ' governing flag cleared - '
                . $keep->id . ' is now the governing line of bd01_ERP_Quote ' . $erpQuote->id
            );
        }
    }
}
