<?php

/**
 * after_save hook class for bd01_ERP_Quote - see the registration in
 * custom/Extension/modules/bd01_ERP_Quote/Ext/LogicHooks/bd_quote_reflection.php
 * for why this class lives here and not alongside that registration.
 *
 * When sugar_quote_id is set, the saved ERP quote is reflected onto that
 * Sugar Quote: bd_erp_total / bd_erp_stage / bd_priced_at / bd_reason_code
 * are updated (only when a value actually changed), and if the Quote is the
 * primary quote of its Opportunity (erp_is_primary_quote, owned by ERP-Core),
 * the Opportunity amount is refreshed from the rollup amount: the governing
 * line's extended price when one of the quote's lines is marked governing
 * (Bench Dogs REQ-5/REQ-6 - see BdGoverningLineHook, which keeps the flag
 * unique per quote), else the whole quote_total (v0.1 behavior, unchanged).
 *
 * When sugar_quote_id is empty the hook does nothing - v0.1 policy; the
 * quote-matching/policy hook lands in a later version.
 */
class BdQuoteReflectionHook
{
    /**
     * Fields on bd01_ERP_Quote that feed the reflection. If none of them
     * changed in this save there is nothing to push.
     */
    private const SOURCE_FIELDS = [
        'sugar_quote_id',
        'current_stage',
        'quote_closed',
        'reason_code',
        'quote_total',
    ];

    /**
     * Re-entrancy guard: saving the Quote (or its Opportunity) inside this
     * hook can fire further logic hooks; nothing in that cascade should run
     * this reflection again.
     */
    private static bool $inProgress = false;

    public function reflect(SugarBean $bean, string $event, array $arguments): void
    {
        if (self::$inProgress) {
            return;
        }

        if (empty($bean->sugar_quote_id)) {
            // v0.1: unlinked ERP quotes are left alone (policy hook lands later).
            return;
        }

        // Only act when something reflection-relevant actually changed this
        // save. dataChanges, not fetched_row: after_save fires after
        // SugarBean has overwritten fetched_row with the bean's own
        // post-write values, so fetched_row can never show a transition -
        // dataChanges is passed to after_save hooks precisely for this
        // (see OrderStageOpportunityCascade for the confirmed-live account).
        $changed = [];
        foreach ($arguments['dataChanges'] ?? [] as $change) {
            $fieldName = $change['field_name'] ?? '';
            if (in_array($fieldName, self::SOURCE_FIELDS, true)
                && ($change['before'] ?? null) !== ($change['after'] ?? null)
            ) {
                $changed[] = $fieldName;
            }
        }
        if ($changed === []) {
            // Nothing relevant changed (e.g. a resave or an unrelated field
            // edit) - and on a brand-new record dataChanges carries the
            // initial values as changes, so a genuine first sync still lands
            // here with a non-empty list.
            return;
        }

        self::$inProgress = true;
        try {
            $this->reflectOntoQuote($bean);
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'BdQuoteReflectionHook: failed reflecting bd01_ERP_Quote ' . $bean->id
                . ' onto Quote ' . $bean->sugar_quote_id . ': ' . $e->getMessage()
            );
        } finally {
            self::$inProgress = false;
        }
    }

    private function reflectOntoQuote(SugarBean $bean): void
    {
        $quote = BeanFactory::retrieveBean('Quotes', $bean->sugar_quote_id);
        if (!$quote || empty($quote->id)) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: bd01_ERP_Quote ' . $bean->id
                . ' points at Quote ' . $bean->sugar_quote_id . ' which could not be retrieved'
            );
            return;
        }

        $stage = $this->mapStage(
            (string) ($bean->current_stage ?? ''),
            !empty($bean->quote_closed),
            (string) ($bean->reason_code ?? ''),
            $this->hasLinkedOrder($quote)
        );

        $dirty = false;

        $total = $bean->quote_total;
        if ($total !== null && $total !== '' && (float) $quote->bd_erp_total !== (float) $total) {
            $quote->bd_erp_total = (float) $total;
            $dirty = true;
        }

        if ((string) $quote->bd_erp_stage !== $stage) {
            $quote->bd_erp_stage = $stage;
            $dirty = true;
        }

        // Stamp bd_priced_at the first time the ERP quote reaches a
        // priced-or-later stage; never overwrite an existing stamp.
        if (empty($quote->bd_priced_at)
            && in_array($stage, ['priced', 'revision', 'accepted', 'ordered'], true)
        ) {
            $quote->bd_priced_at = TimeDate::getInstance()->nowDb();
            $dirty = true;
        }

        $reason = (string) ($bean->reason_code ?? '');
        if ((string) $quote->bd_reason_code !== $reason) {
            $quote->bd_reason_code = $reason;
            $dirty = true;
        }

        if ($dirty) {
            $quote->save();
            $GLOBALS['log']->info(
                'BdQuoteReflectionHook: reflected bd01_ERP_Quote ' . $bean->id
                . ' onto Quote ' . $quote->id . ' (stage=' . $stage . ')'
            );
        }

        $this->maybeUpdateOpportunity($bean, $quote);
    }

    /**
     * Has an ERP sales order been raised from this Sugar Quote?
     *
     * Read off the quotes_erp_orders relationship, which is ERP-Core's own
     * link and the only order signal that reaches Sugar: Epicor's
     * QuoteHed.Ordered is dropped by connector-epicor's normalize_quote before
     * a container extension ever sees the row (see mapStage).
     *
     * Failure is answered false, never fatal. A missing relationship (an
     * ERP-Core version without it) must degrade to "no order known" and leave
     * the closed-quote mapping to decide - not abort the reflection and lose
     * the total and reason with it.
     */
    private function hasLinkedOrder(SugarBean $quote): bool
    {
        try {
            if (!$quote->load_relationship('quotes_erp_orders')) {
                return false;
            }
            return $quote->quotes_erp_orders->get() !== [];
        } catch (Throwable $e) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: could not read quotes_erp_orders on Quote '
                . $quote->id . ': ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Re-run just the Opportunity amount rollup for an ERP quote, outside a
     * bd01_ERP_Quote save. BdGoverningLineHook calls this after a governing
     * flag changes hands so the governing line's extended price lands on the
     * Opportunity immediately - same code path, same gates (sugar_quote_id
     * on the ERP quote, erp_is_primary_quote on the Sugar Quote).
     */
    public function refreshOpportunityAmount(SugarBean $bean): void
    {
        if (self::$inProgress || empty($bean->sugar_quote_id)) {
            return;
        }

        self::$inProgress = true;
        try {
            $quote = BeanFactory::retrieveBean('Quotes', $bean->sugar_quote_id);
            if ($quote && !empty($quote->id)) {
                $this->maybeUpdateOpportunity($bean, $quote);
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'BdQuoteReflectionHook: failed refreshing opportunity amount for bd01_ERP_Quote '
                . $bean->id . ': ' . $e->getMessage()
            );
        } finally {
            self::$inProgress = false;
        }
    }

    /**
     * If the reflected Quote is its Opportunity's primary quote
     * (erp_is_primary_quote is owned by ERP-Core and set by the connector),
     * keep the Opportunity amount in step with the rollup amount (governing
     * line's extended price, else the whole quote total - see rollupAmount).
     */
    private function maybeUpdateOpportunity(SugarBean $bean, SugarBean $quote): void
    {
        if (empty($quote->erp_is_primary_quote)) {
            return;
        }
        $total = $this->rollupAmount($bean);
        if ($total === null) {
            return;
        }

        $quote->load_relationship('opportunities');
        if (!$quote->opportunities || !is_object($quote->opportunities)) {
            return;
        }
        $oppIds = $quote->opportunities->get();
        $oppId = $oppIds[0] ?? '';
        if ($oppId === '') {
            return;
        }

        $opportunity = BeanFactory::retrieveBean('Opportunities', $oppId);
        if (!$opportunity || empty($opportunity->id)) {
            return;
        }

        if ((float) $opportunity->amount === (float) $total) {
            return;
        }

        $opportunity->amount = (float) $total;
        $opportunity->save();
        $GLOBALS['log']->info(
            'BdQuoteReflectionHook: opportunity ' . $oppId . ' amount set to ' . (float) $total
            . ' from bd01_ERP_Quote ' . $bean->id . ' (primary quote ' . $quote->id . ')'
        );
    }

    /**
     * The amount to roll up onto the Opportunity (Bench Dogs REQ-5/REQ-6):
     * when one of the ERP quote's lines is marked governing, that line's
     * extended price governs the deal value; otherwise the whole quote_total
     * (v0.1 behavior) applies. Null when no usable amount exists.
     */
    private function rollupAmount(SugarBean $bean): ?float
    {
        $governing = $this->findGoverningLine($bean);
        if ($governing !== null) {
            $GLOBALS['log']->info(
                'BdQuoteReflectionHook: bd01_ERP_Quote ' . $bean->id
                . ' rollup governed by line ' . $governing->id
                . ' (doc_ext_price=' . (float) $governing->doc_ext_price . ')'
            );
            return (float) $governing->doc_ext_price;
        }
        $total = $bean->quote_total;
        if ($total === null || $total === '') {
            return null;
        }
        return (float) $total;
    }

    /**
     * The quote's governing bd01_ERP_Quote_Line, if any. BdGoverningLineHook
     * keeps the flag unique per quote; if legacy data still carries several,
     * the first one the relationship returns wins (deterministic enough
     * until the next save re-enforces uniqueness).
     */
    private function findGoverningLine(SugarBean $bean): ?SugarBean
    {
        $bean->load_relationship('bd01_erp_quote_lines');
        if (!$bean->bd01_erp_quote_lines || !is_object($bean->bd01_erp_quote_lines)) {
            return null;
        }
        foreach ($bean->bd01_erp_quote_lines->getBeans() as $line) {
            if (!empty($line->governing)) {
                return $line;
            }
        }
        return null;
    }

    /**
     * Map the ERP quote's current_stage + quote_closed + reason_code onto a
     * bd_erp_stage_list key (draft, in_estimating, priced, revision,
     * accepted, ordered, lost).
     *
     * A REASON CODE DOES NOT MEAN THE QUOTE WAS LOST. Epicor stamps
     * QuoteHed.ReasonCode when a quote closes EITHER way and records which way
     * in the separate ReasonType ('W' won / 'L' lost) - verified live on quote
     * 1190, which closed ReasonType 'W', ReasonCode 'PRICE'. This used to read
     * `if ($reasonCode !== '') return 'lost';`, so every won deal reflected
     * into Sugar as Lost, on the record the rep looks at.
     *
     * Won/lost is taken from the STAGE LABEL, which the connector guarantees:
     * transformers.quotes.map_stage() writes exactly 'Closed', 'Closed (Won)'
     * or 'Closed (Lost)' for a closed quote. reason_code is only a fallback
     * and deliberately not the oracle - today it happens to carry the word
     * 'Won'/'Lost', but its documented preference order is
     * description -> mnemonic -> W/L word, so once core stops dropping
     * ReasonDescription it will read 'Couldn't meet delivery date' and any
     * test on it would quietly start failing open.
     *
     * @param bool $hasOrder A Sugar ERP_Orders record is linked to the Quote.
     *   Outranks everything: the quote demonstrably became an order. Epicor's
     *   own QuoteHed.Ordered flag would be the better source and is NOT
     *   available here - connector-epicor's normalize_quote emits 15 canonical
     *   fields and Ordered is not one of them, so it never reaches Sugar.
     */
    private function mapStage(
        string $currentStage,
        bool $closed,
        string $reasonCode,
        bool $hasOrder = false
    ): string {
        $stage = strtolower(trim($currentStage));

        if ($hasOrder) {
            return 'ordered';
        }

        if ($closed) {
            if (strpos($stage, 'order') !== false) {
                return 'ordered';
            }
            if (strpos($stage, '(lost)') !== false) {
                return 'lost';
            }
            if (strpos($stage, '(won)') !== false) {
                return 'accepted';
            }
            // No marker in the label: fall back to the bare W/L word, and only
            // to that word. Anything else is a reason, not an outcome.
            if (strtolower(trim($reasonCode)) === 'lost') {
                return 'lost';
            }
            return 'accepted';
        }

        if ($stage === '' || strpos($stage, 'draft') !== false) {
            return 'draft';
        }
        if (strpos($stage, 'estimat') !== false || strpos($stage, 'engineer') !== false) {
            return 'in_estimating';
        }
        if (strpos($stage, 'revis') !== false || strpos($stage, 'rework') !== false) {
            return 'revision';
        }
        if (strpos($stage, 'order') !== false) {
            return 'ordered';
        }
        if (strpos($stage, 'accept') !== false || strpos($stage, 'won') !== false) {
            return 'accepted';
        }
        if (strpos($stage, 'lost') !== false || strpos($stage, 'cancel') !== false) {
            return 'lost';
        }
        if (strpos($stage, 'price') !== false || strpos($stage, 'quoted') !== false) {
            return 'priced';
        }

        return 'draft';
    }
}
