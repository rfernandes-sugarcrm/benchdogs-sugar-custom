<?php

/**
 * after_save hook class for bd01_ERP_Quote - see the registration in
 * custom/Extension/modules/bd01_ERP_Quote/Ext/LogicHooks/bd_quote_reflection.php
 * for why this class lives here and not alongside that registration.
 *
 * When sugar_quote_id is set, the saved ERP quote is reflected onto that
 * Sugar Quote: bd_erp_total / bd_erp_stage / bd_priced_at / bd_reason_code
 * are updated only when a value changed. Bench QA is Opportunities-only: the
 * shared Quote hook owns headline amount, this class maintains forecast
 * provenance and pre-order Proposal initialization, and Partial Fulfillment
 * owns release-stage writes through Bench's neutral policy. The package uses
 * native Quote lines only and never reads or writes Opportunity line items.
 *
 * When NEITHER sugar_quote_id nor bd_materialized_quote_id is set the ERP
 * quote was born in Kinetic, and REQ-28's materialization runs instead: a
 * native Sugar Quote with its line items, plus an Opportunity, on the
 * account the connector already matched by ERP sync key. See
 * materializeFromKinetic() for the three rules that bound it.
 */
class BdQuoteReflectionHook
{
    /**
     * Stages an ERP quote can be in before estimating has priced it. Mirrors
     * BdEstimatingNotificationHook::PRE_PRICING_STAGES - the same transition
     * drives the return-leg notification and the first-hand-back backfill,
     * and they must agree about what "not yet priced" means.
     */
    private const PRE_PRICING_STAGES = ['draft', 'in_estimating', 'revision'];

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

        $sugarQuoteId = $this->effectiveSugarQuoteId($bean);
        if ($sugarQuoteId === '') {
            // REQ-28: this quote was born in Kinetic - there is no Sugar
            // quote to reflect onto, so make one. See
            // materializeFromKinetic() for the three rules that govern it.
            self::$inProgress = true;
            try {
                $this->materializeFromKinetic($bean);
            } catch (Throwable $e) {
                $GLOBALS['log']->error(
                    'BdQuoteReflectionHook: failed materializing Kinetic quote '
                    . ($bean->quote_num ?? $bean->id) . ': ' . $e->getMessage()
                );
            } finally {
                self::$inProgress = false;
            }
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
            $this->reflectOntoQuote($bean, $sugarQuoteId);
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'BdQuoteReflectionHook: failed reflecting bd01_ERP_Quote ' . $bean->id
                . ' onto Quote ' . $bean->sugar_quote_id . ': ' . $e->getMessage()
            );
        } finally {
            self::$inProgress = false;
        }
    }

    private function reflectOntoQuote(SugarBean $bean, string $sugarQuoteId = ''): void
    {
        if ($sugarQuoteId === '') {
            $sugarQuoteId = $this->effectiveSugarQuoteId($bean);
        }
        $quote = BeanFactory::retrieveBean('Quotes', $sugarQuoteId);
        if (!$quote || empty($quote->id)) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: bd01_ERP_Quote ' . $bean->id
                . ' points at Quote ' . $sugarQuoteId . ' which could not be retrieved'
            );
            return;
        }

        $this->linkToSugarQuote($bean, $quote);
        if ($this->syncMaterializedQuoteLines($bean, $quote->id)) {
            // The line sync just wrote new totals straight to the database.
            // The bean in hand predates them, and everything below ends in
            // $quote->save() - which would put the stale totals back.
            // Measured on Kinetic quote 1200: the header re-saved a $9,420
            // quote as $900, the value of the single line it had when it was
            // first materialized.
            $fresh = BeanFactory::retrieveBean('Quotes', $quote->id, ['use_cache' => false]);
            if ($fresh !== null && !empty($fresh->id)) {
                $quote = $fresh;
            }
        }

        $stage = $this->mapStage(
            (string) ($bean->current_stage ?? ''),
            !empty($bean->quote_closed),
            (string) ($bean->reason_code ?? ''),
            $this->hasLinkedOrder($quote)
        );

        // The estimator's ladder has to reach the rep's own grid, and until
        // now nothing carried it there: the total landed automatically but the
        // per-line breakdown only appeared if somebody called
        // bd-sync-quote-tiers by hand, and no screen anywhere offers that. So
        // on a live instance a rep who sent a quote out for pricing got a
        // headline number over a grid that still showed their single
        // scope-to-be-defined line.
        //
        // Runs BEFORE the dirty-field edits below so the re-read cannot
        // discard pending changes - same ordering the materialized path uses,
        // and for the same reason (adding line items rewrites the quote's
        // totals underneath the bean in hand).
        if ($this->backfillOnFirstHandBack($bean, $quote, $stage)) {
            $fresh = BeanFactory::retrieveBean('Quotes', $quote->id, ['use_cache' => false]);
            if ($fresh !== null && !empty($fresh->id)) {
                $quote = $fresh;
            }
        }

        $dirty = false;

        $total = $bean->quote_total;
        if ($total !== null && $total !== '' && (float) $quote->bd_erp_total !== (float) $total) {
            $quote->bd_erp_total = (float) $total;
            $dirty = true;
        }

        // erp_quoted_value is ERP-Core's field and its own panel's headline
        // number - "Quoted Value" on the Quotes record view. On a quote that
        // ORIGINATES in Sugar the connector never fills it (it writes the
        // write-back status trio and nothing else), so the header read
        // "$0.00" beside a $24,850 grand total on the very quote this demo is
        // built around. Mirror our number into it so the two agree.
        //
        // Written ONLY while it is empty or zero: a value the connector
        // actually supplied is ERP-Core's answer and outranks ours, and
        // overwriting it every reflection would be a tug-of-war between two
        // packages over one field. Empty-or-zero is not an answer, so filling
        // it takes nothing away.
        if ($total !== null && $total !== '' && (float) $total !== 0.0
            && isset($quote->field_defs['erp_quoted_value'])
            && (float) ($quote->erp_quoted_value ?? 0) === 0.0
        ) {
            $quote->erp_quoted_value = (float) $total;
            $dirty = true;
        }

        $previousStage = (string) $quote->bd_erp_stage;
        if ($previousStage !== $stage) {
            $quote->bd_erp_stage = $stage;
            $dirty = true;
        }

        // REQ-13 turnaround, the closing half: the FIRST time estimating
        // hands this ERP quote back priced, timestamp it. Same transition
        // the return-leg notification keys on (see
        // BdEstimatingNotificationHook::PRE_PRICING_STAGES for why '' and
        // the closed stages are not hand-backs).
        //
        // Guarded on the field's own emptiness, never on a status: statuses
        // get rewritten underneath us, and a first-price-back that a later
        // Kinetic revision can overwrite measures nothing.
        if ($stage === 'priced'
            && in_array($previousStage, ['draft', 'in_estimating', 'revision'], true)
            && empty($bean->bd_priced_back_at)
        ) {
            $bean->bd_priced_back_at = TimeDate::getInstance()->nowDb();
            // Safe inside our own after_save: the re-entrancy guard is held
            // for the whole reflection, so this save's reflect() no-ops.
            $bean->save();
            $GLOBALS['log']->info(
                'BdQuoteReflectionHook: bd_priced_back_at stamped on bd01_ERP_Quote '
                . $bean->id . ' (' . $previousStage . ' -> priced)'
            );
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
     * Make sugar_quote_id visible as an actual Sugar relationship.
     *
     * sugar_quote_id is a bare id column: it is what the connector writes and
     * what this hook navigates by, but Sugar's UI cannot see through it. The
     * "Bench Dogs ERP Quotes" subpanel on the Quote record reads the
     * bd01_erp_quote_quotes link, and nothing in the pipeline ever asserted
     * it - so on a live instance every ERP quote carried its sugar_quote_id
     * and the subpanel on EVERY Quote read "No data available" (verified:
     * 110 ERP quotes, 13 with sugar_quote_id, 0 with the relationship).
     * The reflected fields landed and the record the rep opens still looked
     * unconnected.
     *
     * The sibling links do not have this problem because they are written
     * relationally to begin with - quote lines to their ERP quote (184/184),
     * costs to their line (420/420), ERP quotes to their Account (110/110).
     * This one link was the gap.
     *
     * Idempotent: add() on an existing row is a no-op, and this runs on every
     * reflection, so pre-existing records heal on their next sync rather than
     * needing a backfill. Failure is logged, never fatal - a subpanel that
     * stays empty is worth strictly less than the stage, total and reason this
     * hook is here to write, so it must not be able to abort them.
     */
    private function linkToSugarQuote(SugarBean $bean, SugarBean $quote): void
    {
        try {
            if (!$bean->load_relationship('bd01_erp_quote_quotes')) {
                $GLOBALS['log']->warn(
                    'BdQuoteReflectionHook: bd01_erp_quote_quotes link not available on '
                    . 'bd01_ERP_Quote ' . $bean->id . '; subpanel will stay empty'
                );
                return;
            }
            $bean->bd01_erp_quote_quotes->add($quote->id);
        } catch (Throwable $e) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: could not link bd01_ERP_Quote ' . $bean->id
                . ' to Quote ' . $quote->id . ': ' . $e->getMessage()
            );
        }
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
     * Re-run the Quote-owned contribution and Bench forecast path outside an
     * ERP quote save. Governing and reflected-line hooks call this after the
     * selected contribution, price or role changes. The shared Quote hook
     * remains the sole Opportunity amount writer.
     */
    public function refreshOpportunityAmount(SugarBean $bean): void
    {
        $sugarQuoteId = $this->effectiveSugarQuoteId($bean);
        if (self::$inProgress || $sugarQuoteId === '') {
            return;
        }

        self::$inProgress = true;
        try {
            // A REQ-28 quote's lines live in Sugar only because we copied
            // them there, so a line change in Kinetic has to be copied again
            // before the contribution is recomputed - otherwise the
            // opportunity would be re-valued from a stale quote.
            $this->syncMaterializedQuoteLines($bean, $sugarQuoteId);
            $quote = BeanFactory::retrieveBean('Quotes', $sugarQuoteId, ['use_cache' => false]);
            if ($quote && !empty($quote->id)) {
                // ERP-Core owns the one Opportunity amount writer. Invoke its
                // public hook before Bench consumes the resulting headline
                // for its system-managed forecast cases. That preserves the
                // shared writer's currency conversion and makes one trigger
                // converge in one pass instead of leaving Best/Worst one
                // governing selection behind.
                $file = 'custom/modules/Quotes/QuoteOpportunityAmount.php';
                if (!class_exists('QuoteOpportunityAmount', false) && file_exists($file)) {
                    require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($file);
                }
                if (class_exists('QuoteOpportunityAmount', false)) {
                    (new QuoteOpportunityAmount())->refresh($quote);
                }
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
     * Refresh Bench-owned forecast provenance and pre-order stage policy for
     * the primary Quote. Amount remains owned by the shared Quote hook, and
     * release-stage writes remain owned by Partial Fulfillment.
     */
    private function maybeUpdateOpportunity(SugarBean $bean, SugarBean $quote): void
    {
        // An order raised in Kinetic rather than through Sugar never touches
        // erp_ordered, so the release goes on reporting as open pipeline after
        // it has been won. Reconciled HERE, on the single path every caller
        // funnels through, rather than in the action that happened to need it
        // first: whether the deal is re-valued by a pipeline sync, a link
        // event or a button, it has to land on the same numbers. Convergence
        // is the requirement - the answer must not depend on what triggered it.
        $reconciledReleaseLines = $this->reconcileOrderedFromErpOrders($quote);

        if (empty($quote->erp_is_primary_quote)) {
            return;
        }

        $opportunity = $this->linkedOpportunity($quote);
        if ($opportunity === null) {
            return;
        }

        if ($this->isStaleGeneration($bean, $quote)) {
            // Bench Dogs revisions arrive as NEW Kinetic quotes carrying the
            // same sugar_quote_id (1194 -> 1195 measured live). Only the
            // newest generation may value the deal - a late save of an old
            // generation must not drag the forecast backwards.
            return;
        }

        if ($reconciledReleaseLines > 0) {
            $this->dispatchReleaseStageAfterKineticReconciliation($quote);
        }

        $deliverables = $this->deliverables($bean, $quote);
        if ($deliverables === []) {
            return;
        }

        // Re-read immediately before saving Bench-owned fields: earlier line
        // or Quote saves may have refreshed the shared headline already.
        $fresh = BeanFactory::retrieveBean(
            'Opportunities',
            $opportunity->id,
            ['use_cache' => false]
        );
        $this->writeOpportunityDirect(
            $bean,
            $quote,
            ($fresh && !empty($fresh->id)) ? $fresh : $opportunity,
            $deliverables
        );
    }


    /**
     * Is a NEWER Kinetic generation of this deal already reflected? Compared
     * by quote_num across the Sugar quote's bd01_erp_quote_quotes siblings.
     * Fails open: preserving the current forecast is safer than freezing it.
     */
    private function isStaleGeneration(SugarBean $bean, SugarBean $quote): bool
    {
        try {
            if (!$quote->load_relationship('bd01_erp_quote_quotes')
                || !$quote->bd01_erp_quote_quotes
                || !is_object($quote->bd01_erp_quote_quotes)
            ) {
                return false;
            }
            foreach ($quote->bd01_erp_quote_quotes->getBeans() as $sibling) {
                if ($sibling->id !== $bean->id
                    && (int) $sibling->quote_num > (int) $bean->quote_num
                ) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * The Opportunity the Quote belongs to, via the quotes->opportunities
     * relationship (first linked opportunity wins, as before 0.8.6).
     */
    private function linkedOpportunity(SugarBean $quote): ?SugarBean
    {
        $quote->load_relationship('opportunities');
        if (!$quote->opportunities || !is_object($quote->opportunities)) {
            return null;
        }
        $oppIds = $quote->opportunities->get();
        $oppId = $oppIds[0] ?? '';
        if ($oppId === '') {
            return null;
        }
        $opportunity = BeanFactory::retrieveBean('Opportunities', $oppId);
        if (!$opportunity || empty($opportunity->id)) {
            return null;
        }
        return $opportunity;
    }

    /**
     * Join the ERP quote's lines to the Sugar quote's line items.
     *
     * Two passes, and the order matters.
     *
     * PASS 1 - the explicit cross-reference, Product.bd_erp_line_num. This is
     * the only join that is certain, because something deliberately wrote it.
     *
     * PASS 2 - tolerant match on (part number, quantity) for any ERP line
     * pass 1 could not place. This exists because the cross-reference is
     * WRITTEN BY THIS PACKAGE AND NOWHERE ELSE: the connector does not carry
     * it, so a quote nobody has ordered from yet has the column empty on
     * every line and the exact join resolves nothing. Measured live on
     * Northgate quote 1195, 23 Aug 2026: one line item, bd_erp_line_num
     * empty, four ERP lines - a quote in perfectly ordinary shape that the
     * exact join could not read at all.
     *
     * A pass-2 match is only accepted when EXACTLY ONE unclaimed line item
     * has that part and that quantity. Two lines of the same part at the same
     * quantity are genuinely ambiguous and a coin-flip there would silently
     * attribute an order to the wrong release.
     *
     * A pass-2 match is then STAMPED onto the line item, so the tolerant
     * match happens once and every later pass takes the exact join. That also
     * means the xref stops being something only the ordering action can
     * create - which is what made it empty on every un-ordered quote.
     *
     * @param SugarBean[] $lines ERP lines to place (prototype excluded).
     * @return array<int, SugarBean> line number => quoted line item
     */
    private function joinErpLinesToQlis(array $lines, ?SugarBean $quote): array
    {
        if ($quote === null || empty($quote->id) || $lines === []) {
            return [];
        }

        $items = [];
        try {
            if (!$quote->load_relationship('products')) {
                return [];
            }
            foreach ($quote->products->getBeans() as $product) {
                if (empty($product->deleted)) {
                    $items[] = $product;
                }
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: could not read the line items of Quote ' . $quote->id
                . ' for the ERP line join: ' . $e->getMessage()
            );
            return [];
        }

        $map = [];
        $claimed = [];
        foreach ($items as $product) {
            $lineNum = (int) ($product->bd_erp_line_num ?? 0);
            if ($lineNum <= 0) {
                continue;
            }
            if (isset($map[$lineNum])) {
                // Two line items claiming one ERP line. First wins, and it is
                // said out loud: silently picking one of two contradictory
                // rows is how an opportunity quietly reports the wrong number
                // for a month.
                $GLOBALS['log']->warn(
                    'BdQuoteReflectionHook: Quote ' . $quote->id . ' has more than one line '
                    . 'item stamped bd_erp_line_num=' . $lineNum . ' (' . $map[$lineNum]->id
                    . ' and ' . $product->id . ') - using the first.'
                );
                continue;
            }
            $map[$lineNum] = $product;
            $claimed[$product->id] = true;
        }
        $exact = count($map);

        $healed = [];
        $ambiguous = [];
        foreach ($lines as $line) {
            $lineNum = (int) $line->line_num;
            if (isset($map[$lineNum])) {
                continue;
            }
            $part = $this->normalisePart((string) ($line->part_num ?? ''));
            $qty = (float) ($line->selling_qty ?? 0);
            if ($part === '') {
                continue;
            }
            $candidates = [];
            foreach ($items as $product) {
                if (isset($claimed[$product->id])) {
                    continue;
                }
                if ($this->itemPart($product) !== $part) {
                    continue;
                }
                if (abs((float) ($product->quantity ?? 0) - $qty) > 0.0001) {
                    continue;
                }
                $candidates[] = $product;
            }
            if (count($candidates) !== 1) {
                if ($candidates !== []) {
                    $ambiguous[] = $lineNum;
                }
                continue;
            }
            $product = $candidates[0];
            $map[$lineNum] = $product;
            $claimed[$product->id] = true;
            $healed[] = $lineNum;

            try {
                $product->bd_erp_line_num = $lineNum;
                $product->save();
            } catch (Throwable $e) {
                // The join still stands for this pass; only the shortcut for
                // the next one is lost.
                $GLOBALS['log']->warn(
                    'BdQuoteReflectionHook: matched line item ' . $product->id . ' to ERP line '
                    . $lineNum . ' but could not stamp bd_erp_line_num: ' . $e->getMessage()
                );
            }
        }

        $GLOBALS['log']->info(
            'BdQuoteReflectionHook: ERP line join for Quote ' . $quote->id . ' - '
            . count($lines) . ' lines to place, ' . $exact . ' by cross-reference, '
            . count($healed) . ' by (part, quantity)'
            . ($healed !== [] ? ' [' . implode(', ', $healed) . ' stamped]' : '')
            . ($ambiguous !== [] ? ', ' . count($ambiguous) . ' AMBIGUOUS ['
                . implode(', ', $ambiguous) . '] left unplaced' : '')
            . ', ' . (count($lines) - count($map)) . ' unplaced.'
        );

        return $map;
    }

    /**
     * Backfill the estimator's lines onto a rep-owned quote, ONCE, on the
     * first time estimating hands it back priced.
     *
     * backfillMissingQuoteLines() is deliberately manual because on a
     * rep-owned quote, re-adding lines on every sync would fight the rep for
     * control of their own document - a quantity break they deliberately
     * deleted would keep coming back. That reasoning is sound for every
     * LATER sync and wrong for the first one: pressing Send to Estimating is
     * the rep explicitly handing pricing to the estimator, so placing what
     * comes back is completing the round trip they started, not overriding
     * them. There is nothing of theirs to overwrite either - the method is
     * add-only and never touches a row it did not create.
     *
     * Gated on all four of:
     *   - this is the first hand-back (bd_priced_back_at still empty), which
     *     is what keeps a later deletion deleted;
     *   - the quote was actually sent to estimating from Sugar, so an ERP
     *     quote that merely drifted into a priced stage on its own does not
     *     rewrite a rep's grid;
     *   - the stage transition really is pre-pricing -> priced;
     *   - the quote is not one this package materialized, where
     *     syncMaterializedQuoteLines already owns every row.
     *
     * The transition check is not belt-and-braces, it is what bounds this to
     * one run: bd_priced_back_at is stamped by the block below under exactly
     * that same condition, so gating on it guarantees the stamp lands in the
     * same pass that backfills. Loosen it here without loosening it there and
     * the stamp never lands, and this re-adds deleted breaks on every single
     * sync - precisely the behaviour backfillMissingQuoteLines was kept
     * manual to avoid.
     *
     * Never fatal: a grid that stays thin is worth strictly less than the
     * stage, total and reason the caller exists to write, so a failure here
     * must not be able to abort them.
     *
     * @return bool whether any line item was created (caller must re-read)
     */
    private function backfillOnFirstHandBack(SugarBean $bean, SugarBean $quote, string $stage): bool
    {
        if ($stage !== 'priced' || !empty($bean->bd_priced_back_at)) {
            return false;
        }
        if (empty($bean->bd_sent_to_estimating_at)) {
            return false;
        }
        if (!in_array((string) $quote->bd_erp_stage, self::PRE_PRICING_STAGES, true)) {
            return false;
        }
        if ((string) ($bean->bd_materialized_quote_id ?? '') === (string) $quote->id) {
            return false;
        }

        try {
            $created = $this->backfillMissingQuoteLines($bean, $quote);
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'BdQuoteReflectionHook: first-hand-back backfill failed on Quote '
                . $quote->id . ': ' . $e->getMessage()
            );
            return false;
        }

        if ($created > 0) {
            $GLOBALS['log']->info(
                'BdQuoteReflectionHook: first hand-back placed ' . $created
                . ' estimator line(s) on Quote ' . $quote->id
            );
        }
        return $created > 0;
    }

    /**
     * Give every Kinetic quote line a Sugar line item, ADDING ONLY.
     *
     * The tiered model reads "ordered" off the quoted line item, so a Kinetic
     * quantity break with no line item on the Sugar quote can never be
     * ordered, never be recognised as won, and never resolve the join - the
     * whole quote falls back to reporting its ladder as one undifferentiated
     * open figure. Measured live on Northgate quote 1195, 23 Aug 2026: four
     * Kinetic lines, one Sugar line item.
     *
     * This is NOT syncLinesToQuote(). That method owns every row on a quote
     * this package materialized, so it may rewrite and delete them. This one
     * runs on quotes a REP owns, where an existing line item may have been
     * priced, renamed or discounted by hand, and where a break the rep
     * deliberately removed must stay removed. So it only ever ADDS lines the
     * quote has none for, and it never touches a row it did not create.
     *
     * Deliberately not automatic. On a rep-owned quote, silently adding lines
     * on every sync would fight the rep for control of their own document;
     * this runs when somebody asks for it.
     *
     * @return int line items created
     */
    public function backfillMissingQuoteLines(SugarBean $bean, SugarBean $quote): int
    {
        $lines = [];
        $bean->load_relationship('bd01_erp_quote_lines');
        if ($bean->bd01_erp_quote_lines && is_object($bean->bd01_erp_quote_lines)) {
            foreach ($bean->bd01_erp_quote_lines->getBeans() as $line) {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            return 0;
        }

        // Ordered by Kinetic line number with an insertion sort rather than
        // usort(): SugarCloud's packageScan denylists usort outright, so a
        // package that calls it will not install at all.
        $ordered = [];
        foreach ($lines as $line) {
            $at = count($ordered);
            for ($i = 0; $i < count($ordered); $i++) {
                if ((int) $line->line_num < (int) $ordered[$i]->line_num) {
                    $at = $i;
                    break;
                }
            }
            array_splice($ordered, $at, 0, [$line]);
        }
        $lines = $ordered;

        $map = $this->joinErpLinesToQlis($lines, $quote);
        $missing = [];
        foreach ($lines as $line) {
            if (!isset($map[(int) $line->line_num])) {
                $missing[] = $line;
            }
        }
        if ($missing === []) {
            return 0;
        }

        $bundle = $this->defaultBundle($quote);
        if ($bundle === null) {
            $GLOBALS['log']->error(
                'BdQuoteReflectionHook: cannot backfill Kinetic lines onto Quote ' . $quote->id
                . ' - it has no product bundle to put them in.'
            );
            return 0;
        }

        $position = 0;
        if ($bundle->load_relationship('products')) {
            foreach ($bundle->products->getBeans() as $product) {
                if (empty($product->deleted)) {
                    $position = max($position, (int) $product->position + 1);
                }
            }
        }

        $created = 0;
        foreach ($missing as $line) {
            $price = (string) ($line->doc_unit_price ?? '0');
            $name = trim((string) $line->name) !== ''
                ? (string) $line->name
                : (string) $line->part_num;

            $row = BeanFactory::newBean('Products');
            $row->name = $name;
            $row->mft_part_num = (string) $line->part_num;
            $row->quantity = (float) $line->selling_qty;
            $row->discount_price = $price;
            $row->list_price = $price;
            $row->cost_price = 0;
            $row->currency_id = '-99';
            $row->base_rate = 1;
            $row->position = $position;
            $row->quote_id = $quote->id;
            $row->account_id = (string) ($quote->billing_account_id ?? '');
            $row->assigned_user_id = (string) ($quote->assigned_user_id ?? '');
            // Stamped at birth, so this line never needs the tolerant match.
            $row->bd_erp_line_num = (int) $line->line_num;
            $row->save();
            if ($bundle->load_relationship('products')) {
                $bundle->products->add($row, ['position' => $position]);
            }
            $position++;
            $created++;
            $GLOBALS['log']->info(
                'BdQuoteReflectionHook: added line item ' . $row->id . ' to Quote ' . $quote->id
                . ' for Kinetic line ' . $line->line_num . ' (' . $line->part_num . ' x'
                . (float) $line->selling_qty . ')'
            );
        }

        // Re-total from what is now on the bundle - every row, not just the
        // added ones. Sugar does not roll quote totals up on save (they are
        // computed by the Quotes API from a client payload), so a quote whose
        // lines were assembled bean-by-bean keeps whatever total its bean
        // carried until something states the new one.
        $sum = 0.0;
        $fresh = BeanFactory::retrieveBean('Product_Bundles', $bundle->id, ['use_cache' => false]);
        if ($fresh !== null && !empty($fresh->id) && $fresh->load_relationship('products')) {
            foreach ($fresh->products->getBeans() as $product) {
                if (empty($product->deleted)) {
                    $sum += (float) $product->quantity * (float) $product->discount_price;
                }
            }
            $this->stampTotals($fresh, $sum);
        }
        $freshQuote = BeanFactory::retrieveBean('Quotes', $quote->id, ['use_cache' => false]);
        if ($freshQuote !== null && !empty($freshQuote->id)) {
            $this->stampTotals($freshQuote, $sum);
        }

        return $created;
    }

    /**
     * Mark quoted line items that Kinetic has ALREADY turned into orders.
     *
     * erp_ordered is written by ERP-Epicor's Order Selected Lines (erp-order-selected), which is the
     * path where Sugar RAISES the order. It is not the only way a Bench Dogs
     * release gets ordered: an order raised directly in Kinetic - by the
     * inside-sales desk, or before the customer was ever worked in Sugar -
     * never touches that action, so the quote goes on reporting the release
     * as open pipeline that has in fact been won. Measured live on Northgate
     * 23 Aug 2026: orders 9368 and 9371 both sat against quote 1195 in Sugar
     * while the opportunity still showed the whole ladder as open.
     *
     * The evidence is already synced, so this derives rather than assumes:
     * only orders LINKED TO THIS QUOTE (quotes_erp_orders) are considered, and
     * a line is only claimed when exactly one un-ordered line item carries the
     * same part at the same quantity. Anything ambiguous is left alone and
     * said out loud.
     *
     * It only ever sets erp_ordered TRUE. Nothing here clears it: an order can
     * be cancelled in Kinetic without the release ceasing to have been won,
     * and quietly reopening closed revenue is not a decision a sync should
     * take by itself.
     *
     * @return int line items newly marked ordered
     */
    private function reconcileOrderedFromErpOrders(SugarBean $quote): int
    {
        if (empty($quote->id)) {
            return 0;
        }
        try {
            if (!$quote->load_relationship('quotes_erp_orders')) {
                return 0;   // ERP-Core's order module is not present in this tenant
            }
            $orders = $quote->quotes_erp_orders->getBeans();
            if ($orders === []) {
                return 0;
            }
            if (!$quote->load_relationship('products')) {
                return 0;
            }
            $items = [];
            foreach ($quote->products->getBeans() as $product) {
                if (empty($product->deleted)) {
                    $items[] = $product;
                }
            }
            if ($items === []) {
                return 0;
            }

            $marked = 0;
            foreach ($orders as $order) {
                if (!$order->load_relationship('erp_orders_erp_orderlines')) {
                    continue;
                }
                foreach ($order->erp_orders_erp_orderlines->getBeans() as $orderLine) {
                    $part = $this->orderLinePart($orderLine);
                    if ($part === '') {
                        continue;
                    }
                    $qty = (float) ($orderLine->quantity ?? 0);
                    $candidates = [];
                    foreach ($items as $product) {
                        if (!empty($product->erp_ordered)) {
                            continue;   // already won - nothing to decide
                        }
                        if ($this->itemPart($product) !== $part) {
                            continue;
                        }
                        if (abs((float) ($product->quantity ?? 0) - $qty) > 0.0001) {
                            continue;
                        }
                        $candidates[] = $product;
                    }
                    if ($candidates === []) {
                        continue;
                    }
                    if (count($candidates) > 1) {
                        $GLOBALS['log']->warn(
                            'BdQuoteReflectionHook: order line ' . $orderLine->name . ' on Quote '
                            . $quote->id . ' matches ' . count($candidates) . ' un-ordered line '
                            . 'items of ' . $part . ' x' . $qty . ' - leaving all of them open '
                            . 'rather than guessing which release was ordered.'
                        );
                        continue;
                    }
                    $product = $candidates[0];
                    // ERP-Epicor's own lock (>= 1.0.84): the same three fields
                    // Order Selected Lines stamps, so a line ordered in Kinetic
                    // and one released from Sugar look identical on the grid.
                    $product->erp_ordered = true;
                    $product->erp_ordered_order_num = (string) ($order->name ?? '');
                    $product->erp_ordered_at = TimeDate::getInstance()->nowDb();
                    $product->save();
                    $marked++;
                    $GLOBALS['log']->info(
                        'BdQuoteReflectionHook: line item ' . $product->id . ' (' . $part . ' x'
                        . $qty . ') marked ordered from Kinetic order ' . $order->name
                        . ' on Quote ' . $quote->id
                    );
                }
            }
            return $marked;
        } catch (Throwable $e) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: could not reconcile ordered line items on Quote '
                . $quote->id . ': ' . $e->getMessage()
            );
            return 0;
        }
    }

    /**
     * The part number on an ERP order line.
     *
     * ERP-Core does not give the module a part_num field - the part survives
     * only inside the record NAME, which the connector builds as
     * "<order>/<line>/<part>/<ship date>". Parsed defensively: anything that
     * is not that shape yields no part, and no part means no match, which
     * leaves the line item open rather than claiming it on a guess.
     */
    private function orderLinePart(SugarBean $orderLine): string
    {
        $bits = explode('/', (string) ($orderLine->name ?? ''));
        if (count($bits) < 4) {
            return '';
        }
        return $this->normalisePart($bits[2]);
    }

    /**
     * A quoted line item's part number, from whichever field carries it.
     */
    private function itemPart(SugarBean $product): string
    {
        $part = $this->normalisePart((string) ($product->mft_part_num ?? ''));
        if ($part !== '') {
            return $part;
        }
        if (!empty($product->product_template_id)) {
            $tpl = BeanFactory::retrieveBean('ProductTemplates', (string) $product->product_template_id);
            if ($tpl !== null && !empty($tpl->id)) {
                $part = $this->normalisePart((string) ($tpl->erp_display_sync_key ?? ''));
                if ($part === '' && !empty($tpl->erp_sync_key)) {
                    $bits = explode('__', (string) $tpl->erp_sync_key, 2);
                    $part = $this->normalisePart((string) end($bits));
                }
            }
        }
        return $part;
    }

    private function normalisePart(string $part): string
    {
        return strtoupper(trim($part));
    }

    /**
     * Native Quote-line release slices used only for Bench forecast
     * provenance and pre-order stage initialization. Opportunity amount is
     * not calculated here: shared ERP-Core owns that write through the
     * governing contribution provider (selected production + prototype +
     * native tax + shipping).
     *
     * The roles distinguish prototype, ordered production and still-open
     * production so stage policy sees committed release state without
     * inventing a second amount owner.
     */
    private function deliverables(SugarBean $bean, ?SugarBean $quote = null): array
    {
        $proto = null;
        $governing = null;
        $ladder = [];
        $bean->load_relationship('bd01_erp_quote_lines');
        if ($bean->bd01_erp_quote_lines && is_object($bean->bd01_erp_quote_lines)) {
            foreach ($bean->bd01_erp_quote_lines->getBeans() as $line) {
                if (!empty($line->prototype)) {
                    if ($proto === null) {
                        $proto = $line;
                    }
                    continue;   // the prototype is its own deliverable, never production
                }
                if ($governing === null && !empty($line->governing)) {
                    $governing = $line;
                }
                $ladder[] = $line;
            }
        }

        // Resolve the ERP line -> quoted line item join ONCE for this pass.
        // The PROTOTYPE is joined too, even though it never joins the ladder:
        // it can be ordered like any other line (Kinetic order 9368 against
        // Northgate 23 Aug 2026 is exactly that), and release-stage policy
        // must see the committed result.
        $allLines = $ladder;
        if ($proto !== null) {
            $allLines[] = $proto;
        }
        $qliByLine = $this->joinErpLinesToQlis($allLines, $quote);
        $unresolved = [];
        foreach ($ladder as $line) {
            if (!isset($qliByLine[(int) $line->line_num])) {
                $unresolved[] = (int) $line->line_num;
            }
        }
        $joinResolved = ($ladder !== [] && $unresolved === []);

        $out = [];
        if ($proto !== null) {
            $protoQli = $qliByLine[(int) $proto->line_num] ?? null;
            $out['prototype'] = [
                'amount' => (float) $proto->doc_ext_price,
                'quantity' => (float) $proto->selling_qty,
                'name' => trim((string) $proto->part_num) !== ''
                    ? trim((string) $proto->part_num) . ' (prototype)'
                    : 'Prototype run',
                'won' => $protoQli !== null && !empty($protoQli->erp_ordered),
            ];
        }

        // Partition alternative production lines by committed order state.
        // The values inform stage/provenance only; they never replace the
        // shared governing contribution used for Opportunity amount.
        $orderedProduction = [];
        $open = [];
        foreach ($ladder as $line) {
            $qli = $qliByLine[(int) $line->line_num] ?? null;
            if ($joinResolved && $qli !== null && !empty($qli->erp_ordered)) {
                $orderedProduction[] = $line;
                continue;
            }
            $open[] = $line;
        }

        // Each ordered break, closed, locked at what it was ordered at.
        foreach ($orderedProduction as $line) {
            $qty = (float) $line->selling_qty;
            $part = trim((string) $line->part_num);
            $out['ordered_' . (int) $line->line_num] = [
                'amount' => (float) $line->doc_ext_price,
                'quantity' => $qty,
                'name' => ($part !== '' ? $part : 'Production run') . ' x'
                    . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')
                    . ' (ordered)',
                'won' => true,
            ];
        }

        // Summarize lines that remain orderable for stage/provenance. The
        // governing contribution is handled by the shared amount seam.
        $openSum = 0.0;
        $openQtys = [];
        $openPart = '';
        foreach ($open as $line) {
            $openSum += (float) $line->doc_ext_price;
            $openQtys[] = rtrim(rtrim(number_format((float) $line->selling_qty, 2, '.', ''), '0'), '.');
            if ($openPart === '') {
                $openPart = trim((string) $line->part_num);
            }
        }

        if ($open !== [] && $openSum > 0) {
            // Name it from the part and the tiers it covers. The old label
            // fell back to the Kinetic quote number whenever no tier had been
            // released yet ("Quote 1195"), which the working document lists as
            // a known cosmetic gap - the values were right, the label was
            // plain. This closes it.
            $label = $openPart !== ''
                ? $openPart . ' x' . implode('/', $openQtys) . ' (still orderable)'
                : (trim((string) $bean->name) !== ''
                    ? trim((string) $bean->name)
                    : 'Quoted deal value');

            $out['production'] = [
                'amount' => $openSum,
                'quantity' => 1.0,
                'name' => $label,
                'won' => false,
            ];
        }

        $GLOBALS['log']->info(
            'BdQuoteReflectionHook: deliverables for bd01_ERP_Quote ' . $bean->id
            . ' - ' . count($ladder) . ' non-prototype break(s): '
            . count($orderedProduction) . ' ordered (each its own closed row), '
            . count($open) . ' still orderable carried together at ' . number_format($openSum, 2)
            . '. Join ' . ($joinResolved ? 'resolved' : 'NOT resolved')
            . ($unresolved !== [] ? ' (lines ' . implode(', ', $unresolved) . ' unplaced)' : '')
            . '.'
        );

        // There is no header-total fallback: this state model emits only slices
        // it can identify. Amount ambiguity belongs to the shared writer.

        return $out;
    }

    /**
     * Maintain Bench forecast fields and pre-order Proposal initialization
     * from native Quote-line release state.
     *
     * Headline amount belongs to the shared primary-Quote hook, never this
     * method. System-managed Best/Worst consume that already-converted
     * headline, so selected production + prototype + native tax/shipping has
     * one currency semantic and one arithmetic owner. Deliverables remain the
     * input to stage only.
     *
     * Release stage is not derived here. Partial Fulfillment is the sole
     * writer through the neutral provider beside this class. This method only
     * initializes an unreleased priced deal to Proposal, forward-only, and
     * leaves Closed Lost and every later stage alone.
     */
    private function writeOpportunityDirect(
        SugarBean $bean,
        SugarBean $quote,
        SugarBean $opportunity,
        array $deliverables
    ): void {
        $sum = 0.0;
        $wonPrototype = false;
        $wonProduction = false;

        foreach ($deliverables as $role => $d) {
            $sum += (float) $d['amount'];
            if (!empty($d['won'])) {
                if ($role === 'prototype') {
                    $wonPrototype = true;
                } else {
                    $wonProduction = true;
                }
            }
        }

        $current = (string) ($opportunity->sales_stage ?? '');
        $dirty = false;

        // Amount itself is read-only here and already in Opportunity currency,
        // owned by the shared Quote hook. Best/Worst carry that same value
        // while system-managed: the accepted policy supplies no evidence for
        // a spread. Provenance is explicit because comparing a forecast to
        // the CURRENT headline loses ownership when the shared writer changes
        // amount first. That exact sequence left QA Best/Worst at the old
        // all-options subtotal after governing selection changed.
        $forecast = (float) ($opportunity->amount ?? 0);
        $managedValue = isset($opportunity->bd_forecast_managed_value)
            && is_numeric($opportunity->bd_forecast_managed_value)
            ? (float) $opportunity->bd_forecast_managed_value
            : null;
        $managedAny = false;

        foreach (['best_case' => 'bd_best_case_origin', 'worst_case' => 'bd_worst_case_origin'] as $bdCase => $originField) {
            if (!isset($opportunity->field_defs[$bdCase])) {
                continue;
            }
            $held = (float) $opportunity->$bdCase;
            $origin = (string) ($opportunity->$originField ?? '');

            if (!in_array($origin, ['', 'system', 'human'], true)) {
                // Corrupt/foreign provenance is never authorization to
                // overwrite a native forecast. Fail conservative and make
                // the ownership state explicit for subsequent passes.
                $opportunity->$originField = 'human';
                $dirty = true;
                continue;
            }
            if ($origin === 'human') {
                continue;
            }
            if ($origin === 'system' && ($managedValue === null || abs($held - $managedValue) > 0.005)) {
                // The visible field diverged from the exact value we last
                // wrote. Record that independent human takeover permanently;
                // never reclaim it merely because values happen to coincide.
                $opportunity->$originField = 'human';
                $dirty = true;
                continue;
            }

            if ($origin === '') {
                // One-time upgrade adoption. Previous package versions wrote
                // zero, the then-current headline, or the legacy deliverable
                // sum. Anything else is conservatively classified as human.
                $legacyManaged = abs($held) <= 0.005
                    || abs($held - $forecast) <= 0.005
                    || abs($held - $sum) <= 0.005;
                if (!$legacyManaged) {
                    $opportunity->$originField = 'human';
                    $dirty = true;
                    continue;
                }
                $opportunity->$originField = 'system';
                $dirty = true;
            }

            if (abs($held - $forecast) > 0.005) {
                $opportunity->$bdCase = $forecast;
                $dirty = true;
            }
            $managedAny = true;
        }

        if ($managedAny
            && ($managedValue === null || abs($managedValue - $forecast) > 0.005)
        ) {
            $opportunity->bd_forecast_managed_value = $forecast;
            $dirty = true;
        }

        // Before the first release, a priced quote is at Proposal. Once any
        // release is won this hook no longer writes stage: the shared Partial
        // Fulfillment package owns the release transition and consumes our
        // neutral policy. Keeping a second post-order writer here made the
        // final stage depend on whether ERP reflection or the order action ran
        // last.
        if (!$wonPrototype && !$wonProduction
            && $current !== 'Closed Lost'
            && self::stageRank('Proposal/Price Quote') > self::stageRank($current)
        ) {
            $opportunity->sales_stage = 'Proposal/Price Quote';
            $opportunity->probability = 65;
            $dirty = true;
        }

        if (empty($opportunity->date_closed)) {
            // Required by Opportunity::save(); the quote's expiry is the only
            // honest guess available, and a human overrides it freely.
            $opportunity->date_closed = $quote->date_quote_expires
                ?: date('Y-m-d', strtotime('+30 days'));
            $dirty = true;
        }

        if (!$dirty) {
            return;
        }

        $opportunity->save();

        $GLOBALS['log']->info(
            'BdQuoteReflectionHook: opportunity ' . $opportunity->id
            . ' stage refreshed from ' . count($deliverables) . ' deliverable(s); system-managed '
            . 'forecast = Opportunity amount ' . number_format($forecast, 2) . ' for ERP quote '
            . $bean->quote_num
            . ' stage ' . (string) $opportunity->sales_stage
            . ' (Quote-line-only mode)'
        );
    }

    /**
     * A release raised directly in Kinetic bypasses Order Selected Lines, so
     * invoke the same neutral shared hook after reconciliation marks its Quote
     * line. Bench still supplies policy only; Partial Fulfillment performs the
     * Opportunity write. Literal dispatch is required by SugarCloud scanner.
     */
    private function dispatchReleaseStageAfterKineticReconciliation(SugarBean $quote): void
    {
        $file = 'custom/modules/Quotes/ErpQuoteHooks.php';
        if (!file_exists($file)) {
            $GLOBALS['log']->warn('BdQuoteReflectionHook: shared release-stage dispatcher is unavailable for Quote '
                . $quote->id);
            return;
        }

        try {
            require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($file);
            if (!class_exists('ErpQuoteHooks', false)) {
                $GLOBALS['log']->warn('BdQuoteReflectionHook: shared release-stage dispatcher class is unavailable '
                    . 'for Quote ' . $quote->id);
                return;
            }
            ErpQuoteHooks::fireAfterLinesOrdered($quote, $this->hasOpenQuoteLines($quote));
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BdQuoteReflectionHook: shared release-stage dispatch failed for Quote '
                . $quote->id . ': ' . $e->getMessage());
        }
    }

    private function hasOpenQuoteLines(SugarBean $quote): bool
    {
        if (!$quote->load_relationship('products')
            || !$quote->products
            || !is_object($quote->products)
        ) {
            // Conservative: generic fallback must not interpret an unreadable
            // line set as a final release.
            return true;
        }
        foreach ($quote->products->getBeans() as $product) {
            if (empty($product->deleted) && empty($product->erp_ordered)) {
                return true;
            }
        }
        return false;
    }


    /**
     * Forward-only ordering over the sales stages this package can produce.
     * Unknown stages (an admin added their own) rank 0 so they never block a
     * closure this method is certain about, and never get clobbered either -
     * a same-rank write is not a forward move.
     */
    private static function stageRank(string $stage): int
    {
        $ranks = [
            'Prospecting' => 10,
            'Qualification' => 20,
            'Needs Analysis' => 30,
            'Value Proposition' => 40,
            'Id. Decision Makers' => 50,
            'Perception Analysis' => 55,
            'Proposal/Price Quote' => 65,
            'Negotiation/Review' => 70,
            'Prototype Closed' => 80,
            'Partial Production Closed' => 90,
            'Closed Won' => 100,
        ];
        return $ranks[$stage] ?? 0;
    }


    // -------------------------------------------------------------------
    // REQ-28: a quote born in Kinetic becomes a native Sugar quote
    // -------------------------------------------------------------------

    /**
     * The Sugar Quote this ERP quote belongs to, from whichever of the two
     * ids is populated.
     *
     * sugar_quote_id is the connector's answer, parsed out of the Kinetic
     * QuoteComment - authoritative for a quote that ORIGINATED in Sugar.
     * bd_materialized_quote_id is this package's answer for a quote that
     * originated in Kinetic and was materialized here. Exactly one of them
     * is ever set on a given row; see the bd_materialize vardef for why the
     * second field has to exist rather than reusing the first.
     */
    private function effectiveSugarQuoteId(SugarBean $bean): string
    {
        $id = trim((string) ($bean->sugar_quote_id ?? ''));
        if ($id !== '') {
            return $id;
        }
        return trim((string) ($bean->bd_materialized_quote_id ?? ''));
    }

    /**
     * REQ-28: materialize a Kinetic-born quote as a native Sugar Quote with
     * its line items, plus an Opportunity, on the matched Account.
     *
     * Until now the ERP -> Sugar reflection could only ever PATCH a Sugar
     * quote that already existed, matched by an id the Sugar side had
     * stamped into the Kinetic QuoteComment. A quote raised directly in
     * Kinetic carries no such id, so it arrived as a bd01_* mirror and
     * stopped there: real work, invisible to the pipeline. This is the piece
     * that was written up as "cannot be met this phase".
     *
     * It is MLP work by necessity, not preference: core's CRM writer cannot
     * write ProductBundles, so the line items a Sugar quote needs cannot be
     * created from the connector side at all.
     *
     * THREE RULES, none negotiable:
     *
     * 1. A Kinetic quote that ORIGINATED in Sugar is never re-materialized.
     *    The embedded Sugar quote id is the guard and it is already proven:
     *    the caller only reaches here when BOTH ids are empty, and a quote
     *    born in Sugar always has sugar_quote_id parsed back out of its own
     *    QuoteComment (1196 -> 49c7b618, 1197 -> bf62a990, measured).
     *
     * 2. An account is never guessed. The customer link comes from the
     *    relationship the connector already resolves by the account's own
     *    ERP sync key (110/110 populated live); no name matching, no
     *    fuzzy fallback, no creating a customer that nobody confirmed. A
     *    quote whose customer has no Sugar account WAITS - visibly, with the
     *    reason on the record in bd_materialize_status/_msg - and is retried
     *    on every later sync, because the account may simply not have synced
     *    yet.
     *
     * 3. Re-runnable. Two independent guards: bd_materialized_quote_id short
     *    circuits the whole path, and before creating anything the code looks
     *    for a Sugar quote already carrying this Kinetic quote number
     *    (erp_display_sync_key) and ADOPTS it. So a wiped mirror table that
     *    re-syncs from scratch re-attaches to the quotes it made last time
     *    instead of making them again.
     *
     * Scope: only Kinetic quotes above the materialize floor (see
     * materializeFloor()). Everything the customer already had in Kinetic
     * when this was switched on is history, not a backlog to import - 96 of
     * the 115 mirror rows on this instance are exactly that, and creating 96
     * opportunities on install is nobody's idea of the feature working.
     */
    private function materializeFromKinetic(SugarBean $bean): void
    {
        if (trim((string) ($bean->sugar_quote_id ?? '')) !== ''
            || trim((string) ($bean->bd_materialized_quote_id ?? '')) !== ''
        ) {
            return;   // rule 1 / rule 3 - nothing to do
        }

        $quoteNum = (int) ($bean->quote_num ?? 0);
        if ($quoteNum <= 0) {
            return;   // a mirror row with no Kinetic quote number is not a quote
        }
        $floor = $this->materializeFloor();
        if ($quoteNum <= $floor) {
            // Pre-existing Kinetic history. Said out loud on the record
            // rather than silently: a feature that declines to fire should
            // be legible to the person wondering why, and stampMaterialize
            // is a no-op once the row already says this, so it costs one
            // write per row ever, not one per sync.
            $this->stampMaterialize(
                $bean,
                'below_floor',
                'Kinetic quote ' . $quoteNum . ' predates the Sugar materialization floor ('
                . $floor . ') - existing Kinetic history, not a backlog to import.'
            );
            return;
        }

        $account = $this->matchedAccount($bean);
        if ($account === null) {
            $this->stampMaterialize(
                $bean,
                'waiting_account',
                'Kinetic quote ' . $quoteNum . ' has no matching Sugar account yet - '
                . 'waiting rather than inventing one. Retried on every sync.'
            );
            return;
        }

        $lines = $this->orderedLines($bean);
        if ($lines === []) {
            // The connector writes the header first and links its lines in a
            // separate call afterwards, so an empty line set here means the
            // picture is still arriving - not that the quote is empty. A
            // Sugar quote materialized now would be a quote with no line
            // items, which is worse than one that appears a second later.
            // retryMaterializeOnLink() brings us back when they land.
            $this->stampMaterialize(
                $bean,
                'waiting_lines',
                'Kinetic quote ' . $quoteNum . ' has no lines in Sugar yet - '
                . 'materializing when they arrive.'
            );
            return;
        }

        $adopted = $this->findQuoteByKineticNumber($quoteNum);
        if ($adopted !== null) {
            $this->stampMaterialize(
                $bean,
                'adopted',
                'Adopted the existing Sugar quote for Kinetic quote ' . $quoteNum . '.',
                $adopted->id
            );
            $this->linkToSugarQuote($bean, $adopted);
            return;
        }

        $quote = $this->createNativeQuote($bean, $account, $lines);
        if ($quote === null) {
            return;   // createNativeQuote has already recorded why
        }

        $this->stampMaterialize(
            $bean,
            'materialized',
            'Created Sugar quote and opportunity from Kinetic quote ' . $quoteNum . '.',
            $quote->id
        );
        $this->linkToSugarQuote($bean, $quote);

        // Hand the new quote straight to the ordinary reflection. Creating
        // the records is only half of REQ-28: without this the Sugar quote
        // would carry no bd_erp_stage or bd_erp_total and its Opportunity
        // would remain invisible to the shared amount and Bench forecast
        // paths. Doing it here rather than
        // waiting for the next sync also means the record is complete the
        // first time anyone looks at it.
        $this->reflectOntoQuote($bean, $quote->id);
    }

    /**
     * Kinetic quote numbers at or below this are pre-existing history and
     * are never materialized.
     *
     * Derived from the data, not from anything recorded at install time:
     * the floor is the highest Kinetic quote number Sugar ALREADY has a
     * quote for. The reasoning is that Sugar has been keeping up with
     * Kinetic as far as that number, so anything at or below it that Sugar
     * does not have is history it deliberately does not have - 96 of the 115
     * mirror rows on this instance are exactly that, and turning them into
     * 96 opportunities on install is nobody's idea of the feature working.
     * Anything ABOVE it is genuinely new, which is what REQ-28 is about.
     *
     * Deriving it beats storing it. A stored floor has to survive an MLP
     * uninstall, a wipe of the mirror table and the full resync that
     * recreates every row as if new; this one is recomputed from the Sugar
     * quotes, which outlive all three. It also self-advances: once 1199 is
     * materialized it becomes the floor, so 1200 is next and 1199 is never
     * reconsidered.
     *
     * $sugar_config['benchdogs_ext']['materialize_from_quote_num'] overrides
     * it, which is the supported way to deliberately backfill a range.
     *
     * FAILS CLOSED. If no Sugar quote is linked to any mirror row - a fresh
     * tenant, or a broken link table - this answers the highest quote number
     * in the mirror, i.e. materialize nothing until something newer arrives.
     * A feature that visibly does not fire is recoverable; a hundred
     * fabricated deals in a customer's pipeline is not, and those costs are
     * not symmetric.
     */
    private function materializeFloor(): int
    {
        $override = SugarConfig::getInstance()->get('benchdogs_ext.materialize_from_quote_num', null);
        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        try {
            $query = new SugarQuery();
            $query->select(['quote_num']);
            $query->from(BeanFactory::newBean('bd01_ERP_Quote'));
            $query->where()->queryOr()
                ->notEquals('sugar_quote_id', '')
                ->notEquals('bd_materialized_quote_id', '');
            $query->orderBy('quote_num', 'DESC');
            $query->limit(1);
            $rows = $query->execute();
            if (!empty($rows) && (int) ($rows[0]['quote_num'] ?? 0) > 0) {
                return (int) $rows[0]['quote_num'];
            }

            // Nothing linked at all - fail closed at the current high water.
            $query = new SugarQuery();
            $query->select(['quote_num']);
            $query->from(BeanFactory::newBean('bd01_ERP_Quote'));
            $query->orderBy('quote_num', 'DESC');
            $query->limit(1);
            $rows = $query->execute();
            return (int) ($rows[0]['quote_num'] ?? PHP_INT_MAX);
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'BdQuoteReflectionHook: could not derive the materialize floor: ' . $e->getMessage()
            );
            return PHP_INT_MAX;
        }
    }

    /**
     * The Sugar Account this ERP quote's customer is, or null.
     *
     * Read off bd01_erp_quote_accounts, which the connector populates by the
     * account's own ERP sync key ('EPIC06__10269' - the same scoped key core
     * writes onto Accounts). That is a key match, not a name match, which is
     * the whole reason rule 2 can be honoured: either Epicor's CustNum
     * resolves to an account Sugar already has, or it does not, and there is
     * no third answer to be tempted by.
     */
    private function matchedAccount(SugarBean $bean): ?SugarBean
    {
        try {
            if (!$bean->load_relationship('bd01_erp_quote_accounts')
                || !$bean->bd01_erp_quote_accounts
                || !is_object($bean->bd01_erp_quote_accounts)
            ) {
                return null;
            }
            foreach ($bean->bd01_erp_quote_accounts->getBeans() as $account) {
                if (!empty($account->id)) {
                    return $account;
                }
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: could not read the account link on bd01_ERP_Quote '
                . $bean->id . ': ' . $e->getMessage()
            );
        }
        return null;
    }

    /**
     * An existing Sugar Quote already carrying this Kinetic quote number in
     * erp_display_sync_key (ERP-Core's field), or null.
     *
     * This is rule 3's second guard and the reason a wipe-and-resync is
     * survivable: the Sugar quotes outlive the mirror table, so the mirror
     * re-attaches to them instead of duplicating them.
     */
    private function findQuoteByKineticNumber(int $quoteNum): ?SugarBean
    {
        try {
            $query = new SugarQuery();
            $query->select(['id']);
            $query->from(BeanFactory::newBean('Quotes'));
            $query->where()->equals('erp_display_sync_key', (string) $quoteNum);
            $query->limit(1);
            $rows = $query->execute();
            $id = $rows[0]['id'] ?? '';
            if ($id === '') {
                return null;
            }
            $quote = BeanFactory::retrieveBean('Quotes', $id);
            return ($quote && !empty($quote->id)) ? $quote : null;
        } catch (Throwable $e) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: adoption lookup failed for Kinetic quote '
                . $quoteNum . ': ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Build the Opportunity, the Quote, its bundle and its line items.
     *
     * Same record shape createOppQuote() writes for a Sugar-born deal
     * (Opportunity -> Quote -> default ProductBundle -> Products lines,
     * free-text lines carrying the Kinetic PartNum in mft_part_num), so a
     * materialized quote is indistinguishable from a hand-made one to every
     * downstream action in this package.
     *
     * The quote is stamped erp_display_sync_key (adoption's key on the next
     * run) and erp_is_primary_quote. The second one matters because the shared
     * Quote amount writer is gated on it, so without it the Opportunity this
     * method just created would sit at zero forever. We are the ones
     * declaring this quote the opportunity's primary quote - there is no
     * other candidate, we made both records in the same breath.
     */
    private function createNativeQuote(SugarBean $bean, SugarBean $account, array $lines): ?SugarBean
    {
        $quoteNum = (int) $bean->quote_num;

        $assigned = (string) ($account->assigned_user_id ?? '');
        if ($assigned === '') {
            $assigned = '1';   // admin - a record nobody owns is worse than one the admin owns
        }
        $name = $account->name . ' - Kinetic Quote ' . $quoteNum;
        $closeDate = date('Y-m-d', strtotime('+30 days'));

        $opp = BeanFactory::newBean('Opportunities');
        $opp->name = $name;
        $opp->amount = 0;
        $opp->currency_id = '-99';
        $opp->base_rate = 1;
        $opp->date_closed = $closeDate;
        $opp->sales_stage = 'Proposal/Price Quote';
        $opp->probability = 65;
        $opp->assigned_user_id = $assigned;
        $opp->account_id = $account->id;
        $opp->account_name = $account->name;
        $opp->description = 'Raised in Epicor Kinetic as quote ' . $quoteNum
            . ' and materialized into Sugar by BenchDogs-Ext (REQ-28).';
        $opp->save();
        if ($opp->load_relationship('accounts')) {
            $opp->accounts->add($account);
        }

        $quote = BeanFactory::newBean('Quotes');
        $quote->name = $name;
        $quote->quote_stage = 'Draft';
        $quote->erp_quote_type = 'advanced_quote';
        $quote->erp_display_sync_key = (string) $quoteNum;
        $quote->erp_is_primary_quote = true;
        $quote->date_quote_expected_closed = $closeDate;
        $quote->assigned_user_id = $assigned;
        $quote->currency_id = '-99';
        $quote->base_rate = 1;
        $quote->billing_account_id = $account->id;
        $quote->billing_account_name = $account->name;
        $quote->shipping_account_id = $account->id;
        $quote->shipping_account_name = $account->name;
        $quote->subtotal = 0;
        $quote->new_sub = 0;
        $quote->total = 0;
        $quote->shipping = 0;
        $quote->tax = 0;
        $quote->subtotal_usdollar = 0;
        $quote->new_sub_usdollar = 0;
        $quote->total_usdollar = 0;
        $quote->description = 'Materialized from Epicor Kinetic quote ' . $quoteNum . '.';
        $quote->save();
        if ($quote->load_relationship('billing_accounts')) {
            $quote->billing_accounts->add($account);
        }
        if ($quote->load_relationship('opportunities')) {
            $quote->opportunities->add($opp);
        }

        $bundle = BeanFactory::newBean('ProductBundles');
        $bundle->name = '';
        $bundle->default_group = true;
        $bundle->bundle_stage = 'Draft';
        $bundle->currency_id = '-99';
        $bundle->base_rate = 1;
        $bundle->subtotal = 0;
        $bundle->new_sub = 0;
        $bundle->total = 0;
        $bundle->save();
        if ($quote->load_relationship('product_bundles')) {
            $quote->product_bundles->add($bundle, ['position' => 0]);
        }

        $position = $this->syncLinesToQuote($quote, $bundle, $lines, $account->id, $assigned);

        // Re-save so the quote's own rollup totals evaluate against the rows
        // just written - the same step copyWinningLinesToQuote() ends on.
        $requote = BeanFactory::retrieveBean('Quotes', $quote->id, ['use_cache' => false]);
        if ($requote !== null) {
            $requote->save();
            $quote = $requote;
        }

        $GLOBALS['log']->info(
            'BdQuoteReflectionHook: REQ-28 materialized Kinetic quote ' . $quoteNum
            . ' as Sugar quote ' . $quote->id . ' / opportunity ' . $opp->id
            . ' on account ' . $account->id . ' (' . $position . ' lines)'
        );

        return $quote;
    }

    /**
     * Write the mirror's lines onto a Sugar quote's bundle, by position.
     *
     * Upsert, not append: row N of the bundle becomes line N of the Kinetic
     * quote, surplus rows are removed, and running it twice changes nothing.
     * That idempotence is what lets it be called from a relationship hook
     * that fires once PER LINE - the two-line quote materializes on the
     * first line's link with one row and is topped up to two on the second,
     * converging on the right answer whatever order the links arrive in.
     *
     * Same free-text-line shape createOppQuote() and copyWinningLinesToQuote()
     * write (name is the description, mft_part_num carries the Kinetic
     * PartNum), so nothing downstream can tell a materialized line from a
     * hand-made one.
     *
     * @return int the number of lines written
     */
    private function syncLinesToQuote(
        SugarBean $quote,
        SugarBean $bundle,
        array $lines,
        string $accountId,
        string $assigned
    ): int {
        // Match existing rows by NAME, not by bundle position. Sugar's
        // ProductBundles link does not keep the position we hand it (both
        // rows of the first materialization came back position 0, measured),
        // so position-matching would silently overwrite line 1 with line 2's
        // values on the next pass. The name is derived from the mirror line
        // and is stable and unique per Kinetic line ("Quote 1199 Line 1"),
        // which makes it a real identity to upsert on.
        $existing = [];
        if ($bundle->load_relationship('products')) {
            foreach ($bundle->products->getBeans() as $product) {
                $existing[(string) $product->name] = $product;
            }
        }

        $position = 0;
        $sum = 0.0;
        $kept = [];
        foreach ($lines as $line) {
            // The ERP's quantity verbatim, zero included. A Kinetic line
            // quoted at expected-qty 0 is a line worth nothing yet, and
            // rounding it up to 1 would make the Sugar quote disagree with
            // the ERP total it is supposed to be a copy of (measured: quote
            // 1199 read $259 in Sugar against $0 in Kinetic before this).
            $qty = (float) $line->selling_qty;
            $price = (string) ($line->doc_unit_price ?? '0');
            $name = trim((string) $line->name) !== ''
                ? (string) $line->name
                : (string) $line->part_num;

            $isNew = !isset($existing[$name]);
            $row = $isNew ? BeanFactory::newBean('Products') : $existing[$name];
            $row->name = $name;
            $row->mft_part_num = (string) $line->part_num;
            $row->quantity = $qty;
            $row->discount_price = $price;
            $row->list_price = $price;
            $row->cost_price = 0;
            $row->currency_id = $row->currency_id ?: '-99';
            $row->base_rate = $row->base_rate ?: 1;
            $row->position = $position;
            if ($isNew) {
                $row->quote_id = $quote->id;
                $row->assigned_user_id = $assigned;
                $row->account_id = $accountId;
            }
            $row->save();
            if ($isNew && $bundle->load_relationship('products')) {
                $bundle->products->add($row, ['position' => $position]);
            }
            $kept[$name] = true;
            $sum += $qty * (float) $price;
            $position++;
        }

        // A line deleted in Kinetic is deleted here. Only rows this method
        // owns are candidates, and it owns every row on a REQ-28 quote.
        foreach ($existing as $name => $row) {
            if (!isset($kept[$name])) {
                $row->mark_deleted($row->id);
            }
        }

        $this->stampTotals($bundle, $sum);
        $this->stampTotals($quote, $sum);

        return $position;
    }

    /**
     * Put a line-derived total on a Quote or a ProductBundle.
     *
     * Sugar's quote totals are NOT rolled up by SugarBean::save() - they are
     * calculated by the Quotes API from the bundle payload the client sends,
     * so a quote assembled bean-by-bean saves with whatever totals its bean
     * happened to carry. Measured on the first materialization of Kinetic
     * quote 1199: two lines of 138 and 121 gave a bundle of 259 and a quote
     * reading 138 - the header had simply never been told.
     *
     * So the sum of the lines is written explicitly. It is the same
     * arithmetic the client would do (quantity x unit price, no discount, no
     * tax, no shipping - none of which a Kinetic quote line carries into the
     * mirror), and it is written to the header AND the bundle so the record
     * agrees with itself wherever it is read.
     */
    private function stampTotals(SugarBean $bean, float $sum): void
    {
        $bean->subtotal = $sum;
        $bean->new_sub = $sum;
        $bean->total = $sum;
        if (isset($bean->field_defs['subtotal_usdollar'])) {
            $bean->subtotal_usdollar = $sum;
            $bean->new_sub_usdollar = $sum;
            $bean->total_usdollar = $sum;
        }
        if (isset($bean->field_defs['deal_tot'])) {
            $bean->deal_tot = 0;
        }
        $bean->save();
    }

    /**
     * after_relationship_add on bd01_ERP_Quote: the account or a line just
     * became part of this ERP quote, so reconsider REQ-28.
     *
     * Needed for the connector's create-then-link ordering: it writes the
     * mirror header first and attaches its account and its lines
     * in separate calls afterwards, none of which fire a save hook on the
     * header. Without this, a Kinetic-born quote would sit at
     * "waiting_account" or "waiting_lines" until some unrelated field
     * changed - materialization that needs a human to nudge it is not
     * materialization.
     *
     * Once materialized, later link events top the line items up instead
     * (syncLinesToQuote is an upsert), which is what makes a two-line quote
     * arriving as two separate link events end up with two lines.
     */
    public function retryMaterializeOnLink(SugarBean $bean, string $event, array $arguments): void
    {
        $link = (string) ($arguments['link_name'] ?? $arguments['link'] ?? '');
        $relationship = (string) ($arguments['relationship'] ?? '');
        $watched = ['bd01_erp_quote_accounts', 'bd01_erp_quote_lines'];
        if (!in_array($link, $watched, true) && !in_array($relationship, $watched, true)) {
            return;
        }
        if (self::$inProgress) {
            return;
        }

        self::$inProgress = true;
        try {
            if (trim((string) ($bean->sugar_quote_id ?? '')) !== '') {
                return;   // born in Sugar - REQ-28 is not about this quote
            }
            $materializedId = trim((string) ($bean->bd_materialized_quote_id ?? ''));
            if ($materializedId === '') {
                $this->materializeFromKinetic($bean);
                return;
            }
            $this->syncMaterializedQuoteLines($bean, $materializedId);
            $this->reflectOntoQuote($bean, $materializedId);
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'BdQuoteReflectionHook: link-triggered materialization failed for bd01_ERP_Quote '
                . $bean->id . ': ' . $e->getMessage()
            );
        } finally {
            self::$inProgress = false;
        }
    }

    /**
     * Re-copy the mirror's lines onto a quote THIS package materialized.
     *
     * Silently does nothing for a Sugar-born quote, which is the point: that
     * quote's lines are the customer's own work and the ERP is downstream of
     * them, so overwriting them from the mirror would be destroying data.
     * Only a REQ-28 quote - one that exists solely as a copy of a Kinetic
     * quote - may be re-copied.
     */
    private function syncMaterializedQuoteLines(SugarBean $bean, string $quoteId): bool
    {
        if ($quoteId === '' || (string) ($bean->bd_materialized_quote_id ?? '') !== $quoteId) {
            return false;
        }
        $lines = $this->orderedLines($bean);
        if ($lines === []) {
            return false;
        }
        $quote = BeanFactory::retrieveBean('Quotes', $quoteId, ['use_cache' => false]);
        if (!$quote || empty($quote->id)) {
            return false;
        }
        $bundle = $this->defaultBundle($quote);
        if ($bundle === null) {
            return false;
        }
        $this->syncLinesToQuote(
            $quote,
            $bundle,
            $lines,
            (string) ($quote->billing_account_id ?? ''),
            (string) ($quote->assigned_user_id ?? '')
        );
        return true;
    }

    /** The quote's first (default) product bundle, or null. */
    private function defaultBundle(SugarBean $quote): ?SugarBean
    {
        if (!$quote->load_relationship('product_bundles')) {
            return null;
        }
        $byPos = [];
        $tie = 0;
        foreach ($quote->product_bundles->getBeans() as $bundle) {
            $byPos[((int) ($bundle->position ?? 0)) * 100000 + $tie++] = $bundle;
        }
        if ($byPos === []) {
            return null;
        }
        ksort($byPos);
        return reset($byPos);
    }

    /**
     * This ERP quote's lines in Kinetic line order. ksort on a line-number
     * key with an insertion tiebreak, not usort - ModuleScanner denylists
     * the callable argument (same reason copyWinningLinesToQuote sorts this
     * way).
     */
    private function orderedLines(SugarBean $bean): array
    {
        $byNum = [];
        $tie = 0;
        try {
            if ($bean->load_relationship('bd01_erp_quote_lines')
                && $bean->bd01_erp_quote_lines
                && is_object($bean->bd01_erp_quote_lines)
            ) {
                foreach ($bean->bd01_erp_quote_lines->getBeans() as $line) {
                    $byNum[((int) ($line->line_num ?? 0)) * 100000 + $tie++] = $line;
                }
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: could not read lines of bd01_ERP_Quote '
                . $bean->id . ': ' . $e->getMessage()
            );
        }
        ksort($byNum);
        return array_values($byNum);
    }

    /**
     * Record what happened, on the ERP quote row itself, so the outcome is
     * legible without reading a log. Saved directly through the bean while
     * the re-entrancy guard is held, so this save's own reflect() no-ops.
     */
    private function stampMaterialize(
        SugarBean $bean,
        string $status,
        string $message,
        string $quoteId = ''
    ): void {
        $dirty = false;
        if ($quoteId !== '' && (string) ($bean->bd_materialized_quote_id ?? '') !== $quoteId) {
            $bean->bd_materialized_quote_id = $quoteId;
            $dirty = true;
        }
        if ((string) ($bean->bd_materialize_status ?? '') !== $status) {
            $bean->bd_materialize_status = $status;
            $dirty = true;
        }
        $message = mb_substr($message, 0, 255);
        if ((string) ($bean->bd_materialize_msg ?? '') !== $message) {
            $bean->bd_materialize_msg = $message;
            $dirty = true;
        }
        if ($dirty) {
            $bean->save();
        }
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
