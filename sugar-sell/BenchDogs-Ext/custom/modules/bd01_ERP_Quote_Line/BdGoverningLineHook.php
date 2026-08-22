<?php

/**
 * after_save hook class for bd01_ERP_Quote_Line - see the registration in
 * custom/Extension/modules/bd01_ERP_Quote_Line/Ext/LogicHooks/
 * bd_governing_line.php for why this class lives here and not alongside
 * that registration.
 *
 * Bench Dogs REQ-5/REQ-6: at most ONE line of an ERP quote may be marked
 * governing. When a save flips a line's `governing` flag on, every sibling
 * line of the same parent bd01_ERP_Quote that still carries the flag is
 * flipped off, and the Opportunity amount rollup is refreshed through the
 * same code path BdQuoteReflectionHook uses (so the governing line's
 * extended price takes effect immediately, not only on the next ERP sync).
 *
 * Clearing the flag deliberately does NOT touch siblings or the rollup -
 * "no governing line" is a valid state (the rollup falls back to the whole
 * quote_total on the next reflection), and un-marking must never guess a
 * replacement.
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

            $this->demoteSiblings($erpQuote, $bean);

            // Push the new governing amount onto the Opportunity through the
            // exact rollup path the reflection hook owns - one code path,
            // one set of gates (sugar_quote_id + erp_is_primary_quote).
            require_once 'custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php';
            (new BdQuoteReflectionHook())->refreshOpportunityAmount($erpQuote);
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
