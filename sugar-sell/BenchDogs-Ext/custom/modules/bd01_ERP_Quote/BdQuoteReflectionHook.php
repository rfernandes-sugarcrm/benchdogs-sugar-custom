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

        $this->linkToSugarQuote($bean, $quote);

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

        $deliverables = $this->deliverables($bean);
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
     * The quote's deliverables, keyed by role ('prototype' / 'production').
     *
     * prototype   - the line flagged prototype (BdGoverningLineHook's sibling
     *               flag, set by the reflection): its extended price.
     * production  - the governing line's extended price when one is marked
     *               (BdGoverningLineHook keeps the flag unique per quote);
     *               otherwise the whole quote_total minus the prototype
     *               slice, so the two RLIs never double-count the same
     *               dollars (v0.1 whole-quote fallback, REQ-6-safe).
     *
     * Empty array when the quote has no usable value at all - the caller
     * then leaves the opportunity's RLIs alone.
     */
    private function deliverables(SugarBean $bean): array
    {
        $proto = null;
        $governing = null;
        $bean->load_relationship('bd01_erp_quote_lines');
        if ($bean->bd01_erp_quote_lines && is_object($bean->bd01_erp_quote_lines)) {
            foreach ($bean->bd01_erp_quote_lines->getBeans() as $line) {
                if ($proto === null && !empty($line->prototype)) {
                    $proto = $line;
                }
                if ($governing === null && !empty($line->governing)) {
                    $governing = $line;
                }
            }
        }

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

        if ($governing !== null) {
            $out['production'] = [
                'amount' => (float) $governing->doc_ext_price,
                'quantity' => (float) $governing->selling_qty,
                'name' => trim((string) $governing->part_num) !== ''
                    ? trim((string) $governing->part_num) . ' (production run)'
                    : 'Production run',
            ];
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
