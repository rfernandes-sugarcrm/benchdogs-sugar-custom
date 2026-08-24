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
 * the Opportunity's REVENUE LINE ITEMS are materialized and maintained from
 * the quote's deliverables (Bench Dogs REQ-6): the prototype-flagged line
 * feeds the prototype RLI, the governing line (see BdGoverningLineHook,
 * which keeps that flag unique per quote) feeds the production RLI, each
 * upserted by bd_deliverable_key with replace semantics. Sugar's own RLI
 * arithmetic then carries the value up to Opportunity.amount - this
 * instance runs opps_view_by = RevenueLineItems, where a directly written
 * amount does not durably stick (pre-0.8.6 wrote the amount here; the
 * write is now re-targeted at the RLIs).
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

        $dirty = false;

        $total = $bean->quote_total;
        if ($total !== null && $total !== '' && (float) $quote->bd_erp_total !== (float) $total) {
            $quote->bd_erp_total = (float) $total;
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
     * Re-run just the deliverable-RLI materialization for an ERP quote,
     * outside a bd01_ERP_Quote save. BdGoverningLineHook calls this after a
     * governing flag changes hands (so the production RLI re-values
     * immediately), BdRliRefreshHook after a line's price or role changes -
     * same code path, same gates (sugar_quote_id on the ERP quote,
     * erp_is_primary_quote on the Sugar Quote).
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
            // before the deliverables are recomputed - otherwise the
            // opportunity would be re-valued from a stale quote.
            $this->syncMaterializedQuoteLines($bean, $sugarQuoteId);
            $quote = BeanFactory::retrieveBean('Quotes', $sugarQuoteId, ['use_cache' => false]);
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
     * REQ-6: if the reflected Quote is its Opportunity's primary quote
     * (erp_is_primary_quote is owned by ERP-Core and set by the connector),
     * materialize and maintain the Opportunity's revenue line items from the
     * quote's deliverables, and let Sugar's own RLI arithmetic carry the
     * value up to Opportunity.amount.
     *
     * Deliverables, not ladder rows: the quantity-break ladder's lines are
     * alternative quantities of ONE item - mirroring every line into an RLI
     * would multiply the deal value inside a single revision. So the
     * prototype-flagged line feeds the prototype RLI, the governing line
     * feeds the production RLI (no governing line: the quote total minus the
     * prototype slice, keeping the v0.1 whole-quote fallback), and each is
     * upserted by bd_deliverable_key with REPLACE semantics: five or six
     * Kinetic revisions re-value the same rows, never add to them.
     */
    private function maybeUpdateOpportunity(SugarBean $bean, SugarBean $quote): void
    {
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
            // generation must not drag the RLIs backwards.
            return;
        }

        $deliverables = $this->deliverables($bean, $quote);
        if ($deliverables === []) {
            return;
        }

        $this->upsertDeliverableRlis($bean, $quote, $opportunity, $deliverables);
    }

    /**
     * Is a NEWER Kinetic generation of this deal already reflected? Compared
     * by quote_num across the Sugar quote's bd01_erp_quote_quotes siblings.
     * Fails open: better a maintained RLI than a frozen one.
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
     * The quote's line items indexed by the Kinetic line number they mirror.
     *
     * Built ONCE per pass and handed to the whole deliverables calculation:
     * the alternative is a getBeans() per ERP line, which on a four-break
     * quote is four full relationship loads to answer one question.
     *
     * bd_erp_line_num is the join column and it is MLP-owned - REQ-1's
     * ordering action stamps it, nothing in the connector writes it. A quote
     * whose line items predate that stamp (or a REQ-28 quote, whose line
     * items this package materialized before any ordering happened) simply
     * yields an empty or partial map, which the caller reads as "the join is
     * unresolvable" and answers conservatively.
     *
     * @return array<int, SugarBean> line number => quoted line item
     */
    private function qliByErpLine(?SugarBean $quote): array
    {
        if ($quote === null || empty($quote->id)) {
            return [];
        }
        $map = [];
        try {
            if (!$quote->load_relationship('products')) {
                return [];
            }
            foreach ($quote->products->getBeans() as $product) {
                if (!empty($product->deleted)) {
                    continue;
                }
                $lineNum = (int) ($product->bd_erp_line_num ?? 0);
                if ($lineNum <= 0) {
                    continue;
                }
                if (isset($map[$lineNum])) {
                    // Two line items claiming one ERP line. First wins, and
                    // it is said out loud: silently picking one of two
                    // contradictory rows is how an opportunity quietly
                    // reports the wrong number for a month.
                    $GLOBALS['log']->warn(
                        'BdQuoteReflectionHook: Quote ' . $quote->id . ' has more than one line '
                        . 'item stamped bd_erp_line_num=' . $lineNum . ' (' . $map[$lineNum]->id
                        . ' and ' . $product->id . ') - using the first.'
                    );
                    continue;
                }
                $map[$lineNum] = $product;
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: could not read the line items of Quote ' . $quote->id
                . ' for the ERP line join: ' . $e->getMessage()
            );
            return [];
        }
        return $map;
    }

    /**
     * The quote's deliverables, keyed by role.
     *
     * prototype  - the line flagged prototype: its extended price. Always its
     *              own slice, never part of production.
     * production - the OPEN production value: money still to win.
     * ordered    - production already ordered: money won. Only ever emitted
     *              when the join below resolves, because it is the only thing
     *              that can tell ordered from open.
     *
     * A BENCH DOGS QUANTITY LADDER IS TIERED, NOT EXCLUSIVE. The breaks (25,
     * 50, 100) are releases against one programme, not three competing
     * versions of the same deal, and the customer orders them in stages over
     * the life of the programme. So the deal is worth the whole ladder, and
     * ordering a release does not shrink it - it moves that slice from
     * 'production' to 'ordered' and the total stays put.
     *
     * FOUR PATHS, in priority order, and deliberately not collapsed:
     *
     *   1. A governing line is marked -> that line's extended price, exactly
     *      as before. An estimator's explicit answer outranks derivation.
     *      Skipped only when that very line has already been ordered (see
     *      the comment at the branch: REQ-1 stamps governing when a single
     *      line is ordered, so honouring it there would report won money as
     *      still open).
     *   2. The ERP line -> quoted line item join resolves for EVERY
     *      non-prototype line -> open production is the sum of the lines
     *      whose line item is not bd_ordered, and the ordered ones become
     *      the 'ordered' slice.
     *   3. The join does not fully resolve -> the sum of ALL non-prototype
     *      lines, which is what this method did before tiering existed. A
     *      partial join is treated as no join: knowing that two of four
     *      lines are unordered says nothing about the other two, and half an
     *      answer here writes a wrong number to a forecast.
     *   4. No non-prototype line is visible at all -> the pre-existing
     *      residual path, quote_total minus what this pass can see, flagged
     *      'residual' so applyResidual() reconciles it against siblings.
     *      'residual' is set on this path and no other: it means "this
     *      figure claims the whole quote", which is true of nothing else
     *      here.
     *
     * Empty array when the quote has no usable value at all - the caller
     * then leaves the opportunity's RLIs alone.
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
        $qliByLine = $this->qliByErpLine($quote);
        $unresolved = [];
        foreach ($ladder as $line) {
            if (!isset($qliByLine[(int) $line->line_num])) {
                $unresolved[] = (int) $line->line_num;
            }
        }
        $joinResolved = ($ladder !== [] && $unresolved === []);

        $out = [];
        if ($proto !== null) {
            $out['prototype'] = [
                'amount' => (float) $proto->doc_ext_price,
                'quantity' => (float) $proto->selling_qty,
                'name' => trim((string) $proto->part_num) !== ''
                    ? trim((string) $proto->part_num) . ' (prototype)'
                    : 'Prototype run',
            ];
        }

        // The governing line, when one is marked, IS the production figure -
        // an estimator's explicit answer outranks anything derived. The one
        // exception is a governing line that has already been ORDERED: it is
        // then describing a won slice, not the open run, and reporting it as
        // open production would state the deal as still-to-win money that is
        // already banked. This exception is not decoration - REQ-1's
        // bd-order-selected-lines stamps governing on the ERP line whenever
        // exactly one line is ordered, so without it every single-line order
        // would collapse the open value to the line just won.
        $governingOrdered = $governing !== null
            && $joinResolved
            && !empty($qliByLine[(int) $governing->line_num]->bd_ordered);

        if ($governing !== null && !$governingOrdered) {
            $out['production'] = [
                'amount' => (float) $governing->doc_ext_price,
                'quantity' => (float) $governing->selling_qty,
                'name' => trim((string) $governing->part_num) !== ''
                    ? trim((string) $governing->part_num) . ' (production run)'
                    : 'Production run',
            ];
            $GLOBALS['log']->info(
                'BdQuoteReflectionHook: deliverables for bd01_ERP_Quote ' . $bean->id
                . ' took the GOVERNING branch - line ' . $governing->line_num
                . ' is marked governing, production = ' . (float) $governing->doc_ext_price
            );
        } elseif ($joinResolved) {
            // TIERED: the quantity breaks are RELEASES against one programme,
            // not alternatives. Bench Dogs orders a quote in stages, so every
            // break that has not yet been ordered is still winnable money and
            // the open value is their sum. What has been ordered leaves the
            // open figure and reappears as a won slice, so the deal's total
            // never moves when a release is placed - it only changes hands.
            //
            // "Ordered" is read off the QUOTED LINE ITEM (bd_ordered), which
            // is where REQ-1 records the decision, joined to the ERP line by
            // Product.bd_erp_line_num.
            $openAmount = 0.0;
            $ordered = [];
            $orderedAmount = 0.0;
            $orderedQty = 0.0;
            foreach ($ladder as $line) {
                $qli = $qliByLine[(int) $line->line_num];
                if (!empty($qli->bd_ordered)) {
                    $ordered[] = $line;
                    $orderedAmount += (float) $line->doc_ext_price;
                    $orderedQty += (float) $line->selling_qty;
                    continue;
                }
                $openAmount += (float) $line->doc_ext_price;
            }

            // Name and quantity are deliberately the ones the RESIDUAL path
            // has always written. This row already exists on the filmed deal
            // reading "Quote 1196" at quantity 1; the tiering changes what
            // the number MEANS, and there is no reason to also change what
            // the record says while the value is identical.
            $out['production'] = [
                'amount' => $openAmount,
                'quantity' => 1.0,
                'name' => trim((string) $bean->name) !== ''
                    ? trim((string) $bean->name)
                    : 'Quoted deal value',
            ];

            if ($ordered !== []) {
                $out['ordered'] = [
                    'amount' => $orderedAmount,
                    'quantity' => $orderedQty,
                    'name' => count($ordered) === 1
                        ? trim((string) $ordered[0]->part_num) . ' x'
                            . rtrim(rtrim(number_format((float) $ordered[0]->selling_qty, 2, '.', ''), '0'), '.')
                            . ' (ordered)'
                        : 'Ordered releases (' . count($ordered) . ' lines)',
                ];
            }

            $GLOBALS['log']->info(
                'BdQuoteReflectionHook: deliverables for bd01_ERP_Quote ' . $bean->id
                . ' took the TIERED branch - ' . count($ladder) . ' non-prototype lines, '
                . count($ordered) . ' already ordered. Open production = ' . $openAmount
                . ', ordered = ' . $orderedAmount
            );
        } elseif ($ladder !== []) {
            // JOIN UNRESOLVABLE. Some ERP line has no quoted line item
            // carrying its number, so this pass cannot tell what has been
            // ordered and what has not - and guessing would either invent
            // won revenue or delete open revenue. Fall back to the behaviour
            // that predates tiering: every non-prototype line counts as open.
            // Wrong in the same direction it has always been wrong, which is
            // the only safe direction to be wrong in.
            $openAmount = 0.0;
            foreach ($ladder as $line) {
                $openAmount += (float) $line->doc_ext_price;
            }
            $out['production'] = [
                'amount' => $openAmount,
                'quantity' => 1.0,
                'name' => trim((string) $bean->name) !== ''
                    ? trim((string) $bean->name)
                    : 'Quoted deal value',
            ];
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: deliverables for bd01_ERP_Quote ' . $bean->id
                . ' could NOT resolve the ERP line -> quoted line item join'
                . ($quote === null
                    ? ' (no Sugar quote in hand)'
                    : ' (Quote ' . $quote->id . ' has no line item carrying bd_erp_line_num '
                        . implode(', ', $unresolved) . ')')
                . ' - falling back to the whole non-prototype ladder as open production ('
                . $openAmount . '). Ordered releases cannot be recognised until every ERP '
                . 'line has a quoted line item stamped with its line number.'
            );
        } else {
            $total = $bean->quote_total;
            if ($total !== null && $total !== '') {
                $amount = (float) $total;
                if ($proto !== null) {
                    $amount = max(0.0, $amount - (float) $proto->doc_ext_price);
                }
                $out['production'] = [
                    'amount' => $amount,
                    'quantity' => 1.0,
                    'name' => trim((string) $bean->name) !== ''
                        ? trim((string) $bean->name)
                        : 'Quoted deal value',
                    // RESIDUAL: this figure is the whole quote MINUS the
                    // slices this pass could see. Any deliverable already
                    // materialized that this pass could NOT see (the
                    // prototype line has not synced yet, or arrived on a
                    // later generation) is still real money on the
                    // opportunity, and upsertDeliverableRlis() subtracts it
                    // too - see the residual block there. Without that,
                    // "quote total" plus a surviving prototype RLI adds up
                    // to MORE than the quote (measured live 23 Aug 2026:
                    // opportunity 49a23488 read $24,500 for a $23,750
                    // quote), which is the exact double-count REQ-6
                    // promises cannot happen.
                    'residual' => true,
                ];
            }
        }

        return $out;
    }

    /**
     * Upsert one RLI per deliverable, keyed on bd_deliverable_key
     * ("<bd01_ERP_Quote id>:<role>").
     *
     * Replace semantics: an existing keyed RLI is re-valued in place, never
     * duplicated. The $0 placeholder RLI that the account-level action
     * inserts at opportunity birth (unkeyed, likely_case 0) is claimed for
     * the first missing deliverable instead of being left as an orphan row.
     * Human-created RLIs (unkeyed, non-zero) are never touched.
     *
     * sales_stage is only set on rows this pass brings INTO the deliverable
     * model (created or adopted) - an existing keyed RLI keeps whatever
     * stage the closure machinery gave it (the partial-win lane in
     * BdBenchDogsActionsApi owns closing the won slice; REQ-1 hinges on it).
     */
    private function upsertDeliverableRlis(
        SugarBean $bean,
        SugarBean $quote,
        SugarBean $opportunity,
        array $deliverables
    ): void
    {
        if (!$opportunity->load_relationship('revenuelineitems')
            || !$opportunity->revenuelineitems
            || !is_object($opportunity->revenuelineitems)
        ) {
            return;
        }

        $byKey = [];
        $byRole = [];
        $placeholder = null;
        foreach ($opportunity->revenuelineitems->getBeans() as $rli) {
            $key = (string) ($rli->bd_deliverable_key ?? '');
            if ($key !== '') {
                if (!isset($byKey[$key])) {
                    $byKey[$key] = $rli;
                }
                $rolePart = substr($key, strrpos($key, ':') + 1);
                $byRole[$rolePart][] = $rli;
            } elseif ($placeholder === null && (float) $rli->likely_case === 0.0) {
                $placeholder = $rli;
            }
        }

        $deliverables = $this->applyResidual($bean, $quote, $deliverables, $byKey);

        foreach ($deliverables as $role => $spec) {
            // Keyed on the SUGAR quote (the deal), not the ERP quote row: a
            // Bench Dogs revision arrives as a NEW Kinetic quote for the same
            // deal, and it must land on the SAME RLIs (replace, never add).
            $key = $quote->id . ':' . $role;
            $rli = $byKey[$key] ?? null;
            $created = false;
            $adopted = false;

            if ($rli === null && !empty($byRole[$role])) {
                // A connector-owned RLI of this role under an older key
                // (an earlier package version keyed on the ERP quote row, or
                // an earlier Kinetic generation): re-key it in place.
                $rli = array_shift($byRole[$role]);
            }
            if ($rli === null && $placeholder !== null) {
                $rli = $placeholder;
                $placeholder = null;
                $adopted = true;
            }
            if ($rli === null) {
                $rli = BeanFactory::newBean('RevenueLineItems');
                $rli->opportunity_id = $opportunity->id;
                $rli->account_id = (string) ($opportunity->account_id ?? '');
                $rli->assigned_user_id = (string) ($opportunity->assigned_user_id ?? '');
                $rli->currency_id = '-99';
                $rli->base_rate = 1;
                $rli->date_closed = !empty($opportunity->date_closed)
                    ? $opportunity->date_closed
                    : date('Y-m-d', strtotime('+30 days'));
                $created = true;
            }

            $dirty = $created;
            if ((string) ($rli->bd_deliverable_key ?? '') !== $key) {
                $rli->bd_deliverable_key = $key;
                $dirty = true;
            }
            if ((string) $rli->name !== $spec['name']) {
                $rli->name = $spec['name'];
                $dirty = true;
            }
            foreach (['likely_case', 'best_case', 'worst_case'] as $field) {
                if ((float) $rli->$field !== $spec['amount']) {
                    $rli->$field = $spec['amount'];
                    $dirty = true;
                }
            }
            if ($spec['quantity'] > 0 && (float) $rli->quantity !== $spec['quantity']) {
                $rli->quantity = $spec['quantity'];
                $dirty = true;
            }

            if ($created || $adopted) {
                [$stage, $probability] = $this->deliverableStage($role, $opportunity);
                if ($stage !== '' && (string) $rli->sales_stage !== $stage) {
                    $rli->sales_stage = $stage;
                    $rli->probability = $probability;
                    $dirty = true;
                }
            }

            if ($dirty) {
                $rli->save();
                $GLOBALS['log']->info(
                    'BdQuoteReflectionHook: RLI ' . $rli->id . ' (' . $key . ') '
                    . ($created ? 'created' : ($adopted ? 'adopted from placeholder' : 'updated'))
                    . ' likely_case=' . $spec['amount'] . ' on opportunity ' . $opportunity->id
                );
            }

            // Stale generations of this role (keyed rows that lost the
            // upsert) are connector-owned by definition - remove them so a
            // re-quoted deal never double-counts. Closed rows are history
            // and stay.
            foreach ($byRole[$role] ?? [] as $stale) {
                if ($stale->id === $rli->id) {
                    continue;
                }
                if (in_array((string) $stale->sales_stage, ['Closed Won', 'Closed Lost'], true)) {
                    continue;
                }
                $stale->mark_deleted($stale->id);
                $GLOBALS['log']->info(
                    'BdQuoteReflectionHook: stale deliverable RLI ' . $stale->id
                    . ' (' . $stale->bd_deliverable_key . ') removed - superseded by ' . $key
                );
            }
        }
    }

    /**
     * Keep the deliverable RLIs summing to the QUOTE TOTAL, not to more.
     *
     * The production deliverable has two sources: a marked governing line
     * (an exact figure - nothing to reconcile) or, when no line is marked,
     * the whole quote_total minus the slices this pass could see. Only the
     * second is flagged 'residual', and only the second can double-count.
     *
     * It double-counts whenever a deliverable is ALREADY materialized on the
     * opportunity but is NOT visible to this pass. That is not hypothetical:
     * the connector creates a bd01_ERP_Quote header and links its lines in a
     * SEPARATE call afterwards (POST /integrate/{module}/link - see
     * connector-core's sugar_sell.link_by_sync_keys), so the header's
     * after_save reflection runs against a quote with ZERO lines. It finds no
     * prototype line, claims the entire quote_total as production, and the
     * $750 prototype RLI from the previous generation survives beside it:
     * $23,750 + $750 = $24,500 on a $23,750 quote (measured live on
     * opportunity 49a23488, 23 Aug 2026).
     *
     * So a residual production figure gives up every dollar already carried
     * by a keyed sibling deliverable this pass is not itself re-valuing.
     * Roles this pass DOES value are skipped - deliverables() already
     * subtracted those lines, and subtracting them twice would understate
     * the deal by exactly the prototype.
     *
     * Only keys belonging to this deal are considered: the Sugar quote's own
     * key prefix, plus the ERP quote row's (the pre-0.8.6 key shape, still
     * on records that have not been re-keyed yet).
     *
     * @param array<string, array> $byKey RLIs on the opportunity, by deliverable key.
     */
    private function applyResidual(
        SugarBean $bean,
        SugarBean $quote,
        array $deliverables,
        array $byKey
    ): array {
        if (empty($deliverables['production']['residual'])) {
            return $deliverables;
        }

        $prefixes = [$quote->id . ':', $bean->id . ':'];
        $residual = (float) $deliverables['production']['amount'];

        foreach ($byKey as $key => $rli) {
            $role = substr($key, strrpos($key, ':') + 1);
            if ($role === 'production' || isset($deliverables[$role])) {
                continue;   // being written now, or already netted off above
            }
            $mine = false;
            foreach ($prefixes as $prefix) {
                if (strpos($key, $prefix) === 0) {
                    $mine = true;
                    break;
                }
            }
            if (!$mine) {
                continue;   // another deal's deliverable sharing this opportunity
            }
            $residual -= (float) $rli->likely_case;
            $GLOBALS['log']->info(
                'BdQuoteReflectionHook: residual production for quote ' . $quote->id
                . ' nets off already-materialized deliverable ' . $key
                . ' (' . (float) $rli->likely_case . ')'
            );
        }

        $deliverables['production']['amount'] = max(0.0, $residual);
        return $deliverables;
    }

    /**
     * The stage a deliverable RLI is BORN with (create/adopt only - updates
     * never touch stage). Derived from the opportunity's own stage so the
     * materialization slots into whatever state the deal is already in:
     * a prototype that already closed keeps its closure; the production
     * slice of a partially-closed deal is the quoted proposal still in play.
     *
     * @return array{0: string, 1: int}
     */
    private function deliverableStage(string $role, SugarBean $opportunity): array
    {
        $oppStage = (string) ($opportunity->sales_stage ?? '');

        if ($role === 'ordered') {
            // An ordered release is money that has been won, whatever the
            // rest of the deal is doing. It is also the row the stale-row
            // sweep in upsertDeliverableRlis() must never remove, and Closed
            // Won is exactly what protects it there.
            return ['Closed Won', 100];
        }

        if ($role === 'prototype') {
            if ($oppStage === 'Prototype Closed' || $oppStage === 'Partial Production Closed') {
                return ['Prototype Closed', 80];
            }
            return [
                $oppStage !== '' ? $oppStage : 'Prospecting',
                (int) ($opportunity->probability ?? 10),
            ];
        }

        if ($oppStage === 'Closed Won') {
            return ['Closed Won', 100];
        }
        if ($oppStage === 'Closed Lost') {
            return ['Closed Lost', 0];
        }
        if ($oppStage === ''
            || $oppStage === 'Prototype Closed'
            || $oppStage === 'Partial Production Closed'
        ) {
            return ['Proposal/Price Quote', 65];
        }
        return [$oppStage, (int) ($opportunity->probability ?? 50)];
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
        // would carry no bd_erp_stage and no bd_erp_total, and the
        // opportunity would sit at zero with no deliverable RLIs - a
        // materialized deal that is invisible to every forecast the rest of
        // this package exists to make true. Doing it HERE rather than
        // waiting for the next sync also means the record is complete the
        // first time anyone looks at it.
        $this->reflectOntoQuote($bean, $quote->id);
        $this->seedDealStage($quote);
    }

    /**
     * Put a newly materialized deal at Proposal/Price Quote.
     *
     * With RevenueLineItems on, an Opportunity's sales_stage is rolled UP
     * from its RLIs, and deliverableStage() seeds an RLI from the
     * opportunity's stage - so a brand-new opportunity, which has no RLIs and
     * therefore rolls up to Prospecting, seeds RLIs that say Prospecting and
     * holds the deal there. Measured on Kinetic quote 1200: a fully priced
     * $9,420 quote landed in Prospecting.
     *
     * A materialized deal is never at prospecting: a priced quote already
     * exists, in the ERP, which is the whole reason the record was created.
     *
     * Runs ONCE, at birth, and only on rows still sitting at the default -
     * never on a later sync, so a rep who moves the deal keeps it moved.
     */
    private function seedDealStage(SugarBean $quote): void
    {
        try {
            $opportunity = $this->linkedOpportunity($quote);
            if ($opportunity === null || !$opportunity->load_relationship('revenuelineitems')) {
                return;
            }
            foreach ($opportunity->revenuelineitems->getBeans() as $rli) {
                if ((string) $rli->sales_stage !== 'Prospecting') {
                    continue;
                }
                $rli->sales_stage = 'Proposal/Price Quote';
                $rli->probability = 65;
                $rli->save();
            }
        } catch (Throwable $e) {
            $GLOBALS['log']->warn(
                'BdQuoteReflectionHook: could not seed the deal stage for quote '
                . $quote->id . ': ' . $e->getMessage()
            );
        }
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
     * run) and erp_is_primary_quote. The second one matters: the deliverable
     * RLI materialization is gated on it, so without it the opportunity this
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
     * Needed for the same create-then-link ordering that broke the
     * deliverable RLIs (see BdRliRefreshHook::refreshOnLink): the connector
     * writes the mirror header first and attaches its account and its lines
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
