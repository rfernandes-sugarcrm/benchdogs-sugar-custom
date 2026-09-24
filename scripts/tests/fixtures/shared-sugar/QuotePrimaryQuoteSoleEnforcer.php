<?php

/**
 * Decision 143 / 145 / 146 — EXACTLY ONE PRIMARY QUOTE PER OPPORTUNITY,
 * enforced at the WRITE.
 *
 * Registration lives at custom/Extension/modules/Quotes/Ext/LogicHooks/
 * quote_primary_quote_sole_enforcer.php — the class is deliberately here,
 * OUTSIDE the Ext/ tree, for the same reason QuoteAcceptSiblingReject is:
 * Sugar's Ext-merge concatenates the registration file's raw content into the
 * compiled logichooks.ext.php AND LogicHook::loadHookClass() separately
 * require_once()s the named file, so a class declared in the registration
 * file is declared twice and the second fatals with "Cannot redeclare class".
 *
 * ---------------------------------------------------------------------------
 * THE GRAIN, AND WHY A BOOLEAN ON THE QUOTE IS THE RIGHT SHAPE
 *
 * Decision 143 clause 3 worried that Sugar's Quotes<->Opportunities link
 * permits a quote to relate to more than one Opportunity — in which case
 * "primary" would be a property of the (quote, opportunity) PAIR and a
 * boolean on the quote could not express it.
 *
 * MEASURED IN THE PLATFORM SOURCE, THAT PREMISE IS FALSE. A quote relates to
 * AT MOST ONE Opportunity:
 *
 *   - metadata/quotes_opportunitiesMetaData.php declares
 *     'relationship_type' => 'many-to-many' BUT
 *     'true_relationship_type' => 'one-to-many' (lhs Opportunities, rhs Quotes).
 *   - data/Relationships/RelationshipFactory.php:77 resolves the class from
 *     `true_relationship_type` FIRST, and with a join_table + join_key_rhs
 *     present it returns One2MRelationship, not M2MRelationship.
 *   - One2MRelationship::add() — non-self-referencing, so isRHSMany() is
 *     false — takes the branch that calls removeAll($rhs->$rhsLinkName),
 *     i.e. linking a quote to an Opportunity REMOVES every Opportunity link
 *     the quote already had.
 *   - modules/Quotes/vardefs.php gives the `opportunities` link
 *     'link_type' => 'one'.
 *
 * Byte-identical in SugarEnt 25.2.0 and 26.1.0. So "clear it from the other
 * one" is unambiguous: the other QUOTE on this Opportunity.
 *
 * ⚠️ ONE-NESS IS ORM-ENFORCED, NOT DB-ENFORCED. The join table's `quote_id`
 * index is type 'alternate_key', and DBManager.php:1397 rewrites
 * alternate_key -> plain index, so there is NO unique constraint behind it.
 * Anything that writes quotes_opportunities with raw SQL can still create a
 * two-Opportunity quote. This class therefore degrades safely rather than
 * assuming: it enforces per-Opportunity and simply does nothing when a quote
 * somehow reports more than one (see doEnforce()).
 *
 * ---------------------------------------------------------------------------
 * WHY THE MOVE RUNS IN after_save — THE ATOMICITY ARGUMENT, AND ITS LIMIT
 *
 * (The hook is registered on before_save TOO, but that pass carries the
 * 'refuse' mode only and is inert under the shipped 'move'. See
 * ON_SECOND_PRIMARY and doEnforce().)
 *
 * The invariant has two failure modes and they are NOT equally bad:
 * TWO primaries is the bug being fixed; ZERO primaries is WORSE, because
 * every consumer gate (QuoteOpportunityAmount::refreshPrimary, Partial
 * Fulfillment's ErpOpportunityValuation) returns early on an empty flag, so
 * an Opportunity with no primary silently stops being valued at all.
 *
 * In before_save the winner's own flag has NOT been written yet. Clearing the
 * losers there commits their clears BEFORE the winner's set — and if the
 * winner's save then fails, the Opportunity is left with ZERO primaries.
 * In after_save the winner's write is already durable when this runs, so for
 * a SINGLE writer the worst reachable interleaving is two primaries
 * converging to one, never zero.
 *
 * ⚠️ BUT ORDERING ALONE IS NOT ENOUGH, and an earlier version of this comment
 * overclaimed that zero was "structurally unreachable". It is not: TWO
 * concurrent writers can each durably set their own flag and then clear the
 * other, landing on zero. That race is closed separately — by an Opportunity
 * row lock and a self-re-read — and movePrimaryTo() carries the full trace.
 *
 * The lock, the self-re-read, the rival re-check and the loser clears all run
 * inside ONE real DBAL transaction, so the move is atomic rather than
 * half-applied.
 *
 * ⚠️ Note $db->commit()/$db->rollback() are NOT used, and must not be: on
 * MySQL those are the inherited DBManager stubs (DBManager.php:4196/4209) —
 * commit() logs "stub" and returns true, rollback() does NOTHING and returns
 * false. Only IBMDB2Manager overrides them. The real handle is the Doctrine
 * DBAL connection from getConnection().
 *
 * ---------------------------------------------------------------------------
 * WHY THE TRIGGER IS STATE-BASED, NOT TRANSITION-BASED
 *
 * QuoteAcceptSiblingReject fires only on a genuine TRANSITION into a
 * committed stage. A transition-only trigger is structurally incapable of
 * repairing the shape decision 143 was raised for — BOTH flags already true,
 * neither one transitioning. It would also turn decision 141's second
 * release into an early return that never looked, rather than a no-op that
 * looked and found nothing to do.
 *
 * So the rule here is a STATE assertion: "if this quote is flagged after the
 * save, make it the SOLE flagged quote on its Opportunity." Idempotency then
 * falls out of the data rather than out of change detection — when the quote
 * is already sole, this performs ZERO writes and emits ZERO audit lines, so
 * replays and follow-up syncs (this pipeline replays) cannot thrash the flag.
 *
 * ---------------------------------------------------------------------------
 * 🔒 709 / 710 — PRIMARY IS A RADIO ACROSS THE OPPORTUNITY'S QUOTES, NOT A
 * CHECKBOX ON EACH ONE. This class now owns all three halves of that.
 *
 * Owner, verbatim 2026-09-20:
 *
 *   709 "dont ever let uncheck primary quote only let check primary quote on
 *        antoher quote taht removes the old on efrom beeing primary"
 *   710 "and every quote that is created as first on opperunty is paimary"
 *
 *     event                                       | result
 *     --------------------------------------------|---------------------------
 *     first quote on an Opportunity, created OR    | primary, automatically
 *     linked afterwards                            | (710, bootstrapFirstPrimary)
 *     tick primary on another quote                | it wins, incumbent cleared
 *                                                  | (145 'move', unchanged)
 *     clear primary by hand                        | RESTORED, not a user action
 *                                                  | (709, restoreUnsanctionedClear)
 *
 * 🛑 WHY 709 AND 710 ARE ONE CHANGE AND NEITHER SHIPS ALONE. 709 makes the
 * only legal move "promote another quote, which demotes the incumbent" — and
 * a promotion needs an incumbent. With 709 alone the FIRST primary is
 * uncreatable, so an Opportunity that reaches zero primaries stays there
 * forever and QuoteOpportunityAmount publishes nothing for it. 710 is the
 * bootstrap that makes zero unreachable in the first place.
 *
 * 📌 "OR LINKED AFTERWARDS" IS THE LOAD-BEARING HALF, NOT A CAVEAT. Measured
 * on Ophir 2026-09-20: 146 of 157 quotes (93%) have no Opportunity at all,
 * because the seed loader creates them 'Closed Accepted' and links them later
 * (quote_initial_stage()). G92 correctly stopped QuoteAcceptSiblingReject
 * stamping a quote that has no Opportunity — a stage transition is not a link
 * event — so a create-time-only bootstrap would miss that entire population
 * and leave it permanently unvalued. The stamp therefore hangs off
 * after_relationship_add (the link) and after_save (which also heals an
 * Opportunity already sitting at zero), never off a stage transition.
 *
 * 🛑 §DG — THE ILLEGAL STATE IS UNREACHABLE, NOT MERELY DETECTED. There is no
 * "clear primary" operation for a caller to reach for: the ONLY way the flag
 * goes false is demoteAndSave(), and that is what the 709 guard licenses.
 * Nothing counts anything and throws — G97 cost −$1,848.00 doing exactly that.
 */
class QuotePrimaryQuoteSoleEnforcer
{
    /**
     * ============ THE MOVE-VS-REFUSE SWITCH ============
     *
     * 'move'   — ticking Primary on a quote MAKES it the primary and CLEARS
     *            the flag from whichever quote held it. Last write wins, no
     *            error shown to the seller.
     * 'refuse' — a second primary is REJECTED and the seller must clear the
     *            first one themselves (fail-closed, decision 29's precedent).
     *
     * DECISION 145 SET THIS TO 'move', overruling the fail-closed
     * recommendation. User, verbatim: "it shoudl take the latest one and mark
     * it as primary and remove it from the other one".
     *
     * Both behaviours are built and both are tested; this constant is the
     * one-line switch between them.
     *
     * ⚠️ ONE HONEST LIMIT ON 'refuse', stated rather than discovered later:
     * it vetoes the SAVE path, which is where a seller's tick and the sync's
     * PATCH both go. It cannot veto the LINK path - relationship hooks are
     * after-the-fact (there is no before_relationship_add in this
     * registration), so linking an already-primary quote into an Opportunity
     * that already has one is still resolved by moving the flag. Closing that
     * would need a relationship-level guard, which is only worth building if
     * 'refuse' ever becomes the shipped mode. It is not: decision 145 chose
     * 'move', where the link path and the save path already agree.
     */
    public const ON_SECOND_PRIMARY = 'move';

    /**
     * Re-entrancy guard. Clearing a loser calls $loser->save(), which re-fires
     * this very after_save hook. Without this, a three-way collision recurses
     * once per loser. (The losers also fail the isPrimary() test by then, so
     * this is belt-and-braces — but the guard is what makes that ordering
     * irrelevant.)
     */
    private static $enforcing = false;

    /**
     * 🔒 709. The licence to clear `erp_is_primary_quote`, held only for the
     * duration of demoteAndSave(). Every other attempt to move the flag from
     * true to false is restored by restoreUnsanctionedClear().
     *
     * A SEPARATE FLAG FROM $enforcing, deliberately. $enforcing means "this
     * class is already running, do not re-enter"; this one means "the clear
     * about to happen is the sanctioned one". QuoteAcceptSiblingReject demotes
     * a sibling from OUTSIDE this class's own run, so it needs the licence
     * without needing the re-entrancy suppression, and folding the two would
     * have made its demotion either impossible or silently exempt from
     * everything else this class does.
     */
    private static $sanctionedDemotion = false;

    /**
     * G167 I4 — WHY THE PRIMARY FLAG LAST MOVED, keyed by quote id.
     *
     * G167's fourth invariant, carried from G67(b): "a change of primary leaves
     * a reason: which quote the amount came from, and the trigger". This class
     * is the only place that knows the TRIGGER; QuoteOpportunityAmount is the
     * only place that knows the AMOUNT. The note is how the second learns what
     * the first did, within the one request that did it.
     *
     * 🛑 KEYED BY QUOTE ID, NOT A SINGLE SCALAR, and that is the whole of the
     * correctness argument. A hijack clears rivals, and each cleared rival
     * saves, and every one of those saves runs QuoteOpportunityAmount too. A
     * single "last trigger" would be read by whichever bean happened to ask
     * next and label the wrong quote's publish a hijack.
     *
     * Request-scoped by nature: PHP statics die with the request, and the
     * consumer runs at priority 20 on the same hook dispatch as the priority-10
     * write that set it. Nothing persists, so a stale note cannot outlive the
     * act it describes.
     *
     * 🛑 AN ARRAY AND TWO NAMED METHODS, NOT A CLOSURE OR A VARIABLE CALL.
     * ModuleScanner refuses a call through a variable ($fn()) anywhere in a
     * packaged file and ONE occurrence rejects the whole upload (MLP017, which
     * blocked the 1.1.89 build).
     */
    private static $primaryMoveTriggers = array();

    /** G167 I4: the first quote on a deal, made primary by construction (🔒 710). */
    public const TRIGGER_FIRST_PICK = 'first-created pick';

    /** G167 I4: another quote was ticked primary and took the flag (🔒 145). */
    public const TRIGGER_HIJACK = 'hijack';

    /**
     * 🔒 1468 (G215): a quote that was ALREADY primary joined a deal that
     * already had one, and yielded. Read by restoreUnsanctionedClear() (do not
     * put the flag back) and by QuoteOpportunityAmount (this quote was never
     * the Opportunity's primary, so its clear withdraws nothing).
     */
    public const TRIGGER_YIELDED_ON_JOIN = 'yielded on join';

    /**
     * 🔒 1472 — erp_quote_origin's two stated values. Empty means UNKNOWN (the
     * quote predates the stamp), which is neither of them.
     */
    public const ORIGIN_SYNC = 'erp_sync';
    public const ORIGIN_SUGAR = 'sugar';

    /**
     * The OAuth platform(s) the connector authenticates on. ERP-Epicor
     * registers `sugarai_erp_connector` (Ext/Platforms) and connector_core's
     * SugarSellClient defaults to it. A destination that overrides its
     * platform must register that one in Sugar AND add it here, or quotes it
     * creates are stamped 'sugar'.
     */
    public const SYNC_PLATFORMS = array('sugarai_erp_connector');

    /**
     * Record why this quote just became - or stayed - the primary one.
     *
     * Only this class writes the flag, so only this class can say why.
     */
    public static function noteTrigger(string $quoteId, string $trigger): void
    {
        if ($quoteId === '' || $trigger === '') {
            return;
        }
        self::$primaryMoveTriggers[$quoteId] = $trigger;
    }

    /**
     * The trigger recorded for this quote in THIS request, or '' if the flag
     * did not move here.
     *
     * '' is a real answer and must not be turned into a guess: an amount that
     * moved because the quote's own lines changed was not a change of primary,
     * and labelling it one would make the audit trail say something untrue.
     * The caller decides what to write for that case.
     */
    public static function triggerFor(string $quoteId): string
    {
        return (string) (self::$primaryMoveTriggers[$quoteId] ?? '');
    }

    /**
     * 🔒 709 — THE ONLY SANCTIONED WAY TO CLEAR THE FLAG, and the reason the
     * illegal state is unreachable rather than merely detected.
     *
     * Callers that need to change other fields in the same write set them on
     * $quote first; this performs the save.
     *
     * A BEAN save, not raw SQL: erp_is_primary_quote is 'audited' => true, so
     * this writes a quotes_audit row (true -> false, with the acting user and
     * timestamp) readable from the record's own View Change Log. A raw UPDATE
     * would move the flag with no such trail.
     *
     * There is deliberately NO "clear this quote's primary flag" endpoint,
     * button or API action anywhere. Demotion exists only as the far half of
     * a promotion.
     */
    public static function demoteAndSave(SugarBean $quote): void
    {
        $quote->erp_is_primary_quote = false;
        self::$sanctionedDemotion = true;
        try {
            $quote->save();
        } finally {
            self::$sanctionedDemotion = false;
        }
    }

    /**
     * Registered entry point, and nothing more than a guard: an exception
     * thrown here fails the save that triggered it, not just this method.
     * The same shape as OrderStageOpportunityCascade::cascade().
     */
    public function enforce($bean, $event = '', $arguments = array()): void
    {
        try {
            $this->doEnforce($bean, $event, $arguments);
        } catch (SugarApiException $e) {
            // The 'refuse' branch signals through this deliberately - a
            // SugarApiException from a hook aborts the write, which is the
            // control, not a bug. Never swallowed.
            throw $e;
        } catch (\Throwable $e) {
            $GLOBALS['log']->error(
                'QuotePrimaryQuoteSoleEnforcer: enforcement failed for quote '
                . (is_object($bean) && isset($bean->id) ? $bean->id : '?')
                . ' - ' . $e->getMessage()
            );
        }
    }

    private function doEnforce($bean, string $event, array $arguments): void
    {
        if (self::$enforcing) {
            return;
        }
        if (!($bean instanceof SugarBean) || empty($bean->id) || !empty($bean->deleted)) {
            return;
        }
        // 🔒 1472 — record WHO created the quote, in the creating save, before
        // any rule below reads it. clearOrphanPrimary() needs it in this very
        // save: the connector's create of an Epicor quote IS a before_save of
        // a quote with no Opportunity.
        $this->stampOriginAtBirth($bean, $event);
        // 🔒 1472 (G215) — and a quote the sync creates is primary in that same
        // creating save, whatever the payload carried. Reads the origin just
        // stamped, so it must stay after it and before any rule below.
        $this->stampPrimaryAtSyncBirth($bean, $event);
        // after_relationship_add fires for every link on the module; only the
        // Opportunity link can change which Opportunity this quote competes on.
        if ($event === 'after_relationship_add' && ($arguments['link'] ?? '') !== 'opportunities') {
            return;
        }
        // STATE, not transition. See the class docblock.
        if (empty($bean->erp_is_primary_quote)) {
            // 🔒 709. An un-tick is not a user action. Restoring here, in
            // before_save, means the cleared value never reaches the database
            // at all - so there is no window in which the Opportunity has
            // zero primaries and no audit row recording a change that did not
            // happen.
            if ($this->restoreUnsanctionedClear($bean, $event)) {
                return;
            }

            // 🔒 710. Otherwise this quote may be the FIRST on its
            // Opportunity, in which case it is the primary by construction.
            $this->bootstrapFirstPrimary($bean, $event);

            return;
        }

        // G167 I3 — A QUOTE WITH NO OPPORTUNITY CANNOT BE PRIMARY. Runs
        // BEFORE the mode gate below, because in the shipped 'move' mode that
        // gate returns on every before_save and this rule would never fire.
        if ($this->clearOrphanPrimary($bean, $event)) {
            return;
        }

        // 🔒 1468 (G215) — LINKING IS NOT MARKING. A quote that was already
        // primary before it joined this deal (an Epicor-created quote, primary
        // from creation with no Opportunity - 🔒 1473) yields to a primary the
        // deal already has. BEFORE the mode gate so it holds in BOTH modes: in
        // 'move' the move below would hand the deal to the joiner and demote
        // the seller's primary; in 'refuse' the gate returns on every event
        // but before_save, so a yield placed after it never runs and the deal
        // is left with TWO primaries. (Mutation-found: in 'move' alone the
        // placement is invisible.)
        if ($this->yieldOnJoin($bean, $event)) {
            return;
        }

        // ===== WHICH PASS ACTS DEPENDS ON THE MODE =====================
        // This is the entire reason both before_save and after_save are
        // registered, and getting it backwards silently breaks whichever mode
        // is configured.
        //
        // 'refuse' MUST act in before_save. A SugarApiException thrown from
        // before_save genuinely ABORTS the write: the hook fires inside
        // SugarBean::saveData() ahead of its insert()/update() call, and
        // LogicHook::process_hooks() re-throws that exception class up through
        // save() (QuoteAcceptSiblingReject's docblock records the same
        // mechanism, re-verified live). Refusing from after_save instead would
        // be STRICTLY WORSE THAN DOING NOTHING - the UPDATE has already landed,
        // so the seller gets an error AND the database is left holding two
        // primaries. That is the exact inverse of fail-closed.
        //
        // 'move' MUST act in after_save, because the winner's flag has to be
        // durable before any loser is cleared or a failed winner save leaves
        // the Opportunity at ZERO primaries. See the class docblock.
        $isBeforeSave = ($event === 'before_save');
        if (self::ON_SECOND_PRIMARY === 'refuse') {
            if (!$isBeforeSave) {
                return;
            }
        } elseif ($isBeforeSave) {
            return;
        }

        $oppIds = $this->getOpportunityIds($bean);
        // Exactly one, or this class has nothing well-defined to assert.
        // count === 0: an unlinked quote competes with nothing.
        // count  >  1: the ORM's one-Opportunity rule has been bypassed (only
        // reachable through raw SQL - there is no DB constraint). Choosing a
        // "first" Opportunity here would be exactly the guess that makes a
        // $0.00 draft govern a real deal, so refuse to guess and say so.
        if (count($oppIds) !== 1) {
            if (count($oppIds) > 1) {
                $GLOBALS['log']->error(
                    'QuotePrimaryQuoteSoleEnforcer: quote ' . $bean->id . ' reports '
                    . count($oppIds) . ' Opportunities, which Sugar\'s One2MRelationship '
                    . 'should make impossible. Not guessing which one owns the primary flag.'
                );
            }
            return;
        }
        $oppId = (string) reset($oppIds);

        // CHEAP PRE-CHECK, deliberately outside any transaction. The common
        // case by far is "already sole", and it must cost one read and
        // nothing else: no transaction, no row lock, no writes, no audit
        // line. Decision 141's second release lands here (the quote that
        // produced the first release is still the primary), and so does every
        // replay. The authoritative re-check happens under the lock below;
        // this one only decides whether it is worth taking the lock at all.
        if (!$this->otherPrimaryQuoteIds($oppId, $bean->id)) {
            return;
        }

        if (self::ON_SECOND_PRIMARY === 'refuse') {
            throw new SugarApiExceptionInvalidParameter(
                'Another quote on this opportunity is already the primary quote. '
                . 'Clear it first, then mark this one.'
            );
        }

        self::$enforcing = true;
        try {
            $this->movePrimaryTo($bean, $oppId);
        } finally {
            self::$enforcing = false;
        }
    }

    /**
     * 🔒 709 — PUT BACK A FLAG NOBODY WAS ALLOWED TO CLEAR.
     *
     * Returns true when it restored the flag, so the caller stops: the quote
     * is primary again and the database still says so, therefore it is still
     * sole and there is nothing to enforce.
     *
     * WHY RESTORE RATHER THAN THROW, stated so it can be overturned. Both
     * obey the ruling - neither lets the flag go false - and the estate's own
     * §DG prefers the unreachable state to the detected one. Three reasons
     * settled it this way round:
     *
     *   1. A throw fails the WHOLE save. A seller editing an expiry date on
     *      the primary quote, whose form round-trips a checkbox, would lose
     *      that edit to an error about a field they never touched. The tick
     *      is read-only in the UI; the payload still carries it.
     *   2. The flag is 'audited'. Restoring in before_save means the false
     *      never lands, so the change log stays truthful - no row saying the
     *      flag moved and moved back.
     *   3. G97 is the standing lesson about what "count it and throw" costs
     *      (−$1,848.00 on a quote whose total was right the whole time).
     *
     * THE ERROR LOG IS NOT DECORATION. An API client that tried to clear the
     * flag gets a save that succeeded and a field that did not change, which
     * is exactly the "silently ignored write" this estate dislikes. It is
     * logged at ERROR so the attempt is visible to whoever has to explain it,
     * and the UI half (the tick is read-only once true) is what stops a human
     * ever reaching this path. ⚠️ THAT UI HALF IS NOT IN THIS CHANGE - see the
     * PR; a seller can still click the box and see it snap back on reload.
     *
     * 🛑 AN ORPHAN QUOTE'S FLAG STAYS CLEARABLE, AND THAT IS DELIBERATE.
     * "Primary" means "the winning quote on this deal" and is meaningless
     * without an Opportunity - G92 / decision 688.4, which measured 40 quotes
     * on Bench carrying the flag with no Opportunity behind it and ordered
     * "guard first, CLEANUP second". Refusing the clear on those 40 would
     * make that cleanup impossible and freeze the defect in place. So the
     * guard is scoped to a quote that actually has an Opportunity: on
     * everything else the flag is noise, and noise must be removable.
     */
    private function restoreUnsanctionedClear(SugarBean $bean, string $event): bool
    {
        if ($event !== 'before_save' || self::$sanctionedDemotion) {
            return false;
        }
        // 🔒 1468: a joiner that yielded to the deal's primary in THIS save is a
        // sanctioned clear, not an un-tick. Without this, the create-or-edit
        // path (the link rides the quote's own save) would put the flag back
        // here and the after_save move would then demote the incumbent.
        if (self::triggerFor((string) $bean->id) === self::TRIGGER_YIELDED_ON_JOIN) {
            return false;
        }
        // Nothing to restore unless the DATABASE said true a moment ago. A
        // bean assembled in memory with no fetched_row (the connector builds
        // these) has no prior state to contradict, and inventing one would
        // stamp the flag onto quotes nobody chose.
        if (empty($bean->fetched_row['erp_is_primary_quote'])) {
            return false;
        }
        if (count($this->getOpportunityIds($bean)) !== 1) {
            return false;
        }

        $bean->erp_is_primary_quote = true;
        $GLOBALS['log']->error(
            'QuotePrimaryQuoteSoleEnforcer: refused to clear erp_is_primary_quote on quote '
            . $bean->id . ' - primary is a radio across the Opportunity\'s quotes (decision 709).'
            . ' Tick Primary on another quote instead; that demotes this one in the same save.'
        );

        return true;
    }

    /**
     * 🔒 1472 — STAMP WHO CREATED THIS QUOTE, ONCE, IN ITS CREATING SAVE.
     *
     * The fact used is the request's OAuth PLATFORM, read from
     * $_SESSION['platform'] - the same value RestService resolves the request
     * platform from (include/api/RestService.php:177), written by BOTH
     * authentication paths (SugarOAuth2Storage.php:511 for legacy OAuth,
     * IdM's OIDC SessionListener.php:73). The connector authenticates on its
     * own registered platform; nothing a person or Sugar-side job does arrives
     * on it.
     *
     * 🛑 NOT erp_sync_key, which a Sugar quote acquires LATER from the
     * write-back stamp, and NOT created_by, which the package cannot know (the
     * connector's user is per-tenant configuration: an IdM service user on
     * Bench, Administrator on older setups).
     *
     * A CREATE is SugarBean::isUpdate() === false - the platform's own signal:
     * save() calls ensureHasId() BEFORE saveData(), which sets new_with_id, and
     * isUpdate() is false while new_with_id is set (data/SugarBean.php:1849,
     * :2043). An UPDATE never stamps - so a Sugar quote the connector later
     * writes back to stays Sugar-born.
     *
     * 🛑 ON A CREATE THE PLATFORM ALWAYS DECIDES, WHATEVER THE PAYLOAD SAYS.
     * The field is readonly in the UI but the REST API still writes readonly
     * fields, so "keep a value the create arrived with" would let any REST
     * client claim erp_sync and exempt its quote from the orphan rule.
     * (Mutation-found: an earlier "never overwrite" guard did exactly that,
     * and protected nothing, because an update never reaches this line.)
     */
    private function stampOriginAtBirth(SugarBean $bean, string $event): void
    {
        if ($event !== 'before_save' || !method_exists($bean, 'isUpdate') || $bean->isUpdate()) {
            return;
        }
        $platform = isset($_SESSION['platform']) ? (string) $_SESSION['platform'] : '';
        $bean->erp_quote_origin = in_array($platform, self::SYNC_PLATFORMS, true)
            ? self::ORIGIN_SYNC
            : self::ORIGIN_SUGAR;
    }

    /**
     * 🔒 1472 (G215) — "WHEN EVER A QUOTE IS SYNCED FOR THE FIRST TIME TO
     * SUGAR IT SHOULD BE MARKED AS PRIMARY AS PART OF THE CREATION".
     *
     * WHAT WAS MEASURED (Bench, 2026-09-22). Connector-created quotes 317
     * (EPIC06__1269) and 318 (EPIC06__1270) were inserted at 22:13:14 with
     * the flag 0 - no create-time audit row for it, although team_name's
     * create-time row IS there - and `erp_is_primary_quote` audits "0"->"1"
     * at 22:13:20/21 from a separate save. End state right, mechanism not the
     * owner's.
     *
     * WHY THE SUGAR SIDE OWNS THIS, read out of the code rather than assumed.
     * Connector core (a6b746ec transformers/quotes.py:616-635) puts the flag
     * in the create body only on what IT judges a first landing: no `quotes`
     * cache hit AND no `quotes_xref` / `quote_to_quote_xref` binding in its
     * own store (:592-597). A resync that re-lands an Epicor quote whose
     * binding survived (its Sugar row deleted) is an INSERT in Sugar with no
     * flag in the body. Nothing on this side answered 🔒 1472 for that
     * insert, so it went in at the vardef default, 0. When the body DOES
     * carry it, it already survives every Quotes hook into the insert (this
     * class: clearOrphanPrimary() spares ORIGIN_SYNC; the copy shed returns
     * for the connector platform) - pinned by
     * scripts/tests/test_primary_at_sync_birth.py.
     *
     * THE RULE, stated from Sugar's own facts, so it cannot depend on the
     * sender's bookkeeping: a CREATE (isUpdate() false, the same test
     * stampOriginAtBirth() uses) on a SYNC platform (the origin just
     * stamped) with NO Opportunity is primary in this before_save, so the
     * flag rides the INSERT itself. Idempotent with the connector's own flag.
     *
     * 🛑 NO OPPORTUNITY, OR NOTHING. With one, "first" is the deal's question,
     * not the sync's: 🔒 710 (bootstrapFirstPrimary) stamps the first quote on
     * a deal, and 🔒 1468 (yieldOnJoin) keeps a deal's existing primary. The
     * connector's own rule is the same ("a create that carries an
     * Opportunity fails toward NOT primary"). A link that cannot be read is
     * not "no Opportunity" - it is not stamped.
     *
     * 🛑 NEVER A SUGAR-BORN CREATE. A UI copy, a hand-made quote, the seed
     * loader (its own 'epicor_seed' platform) all stamp ORIGIN_SUGAR and are
     * untouched here, so a copy still opens non-primary and G167 I3 still
     * clears an orphan Sugar quote's flag.
     */
    private function stampPrimaryAtSyncBirth(SugarBean $bean, string $event): void
    {
        if ($event !== 'before_save' || !method_exists($bean, 'isUpdate') || $bean->isUpdate()) {
            return;
        }
        if ($this->originOf($bean) !== self::ORIGIN_SYNC || !empty($bean->erp_is_primary_quote)) {
            return;
        }
        if (!empty($bean->opportunity_id)) {
            return;
        }
        if (!$bean->load_relationship('opportunities') || !is_object($bean->opportunities)) {
            return;
        }
        if ($this->getOpportunityIds($bean) !== array()) {
            return;
        }

        $bean->erp_is_primary_quote = true;
        $GLOBALS['log']->info(
            'QuotePrimaryQuoteSoleEnforcer: quote ' . $bean->id . ' is created by the sync with no'
            . ' Opportunity and is primary in its creating save (🔒 1472)'
        );
    }

    /**
     * erp_quote_origin as stated, or '' when the quote predates the stamp.
     */
    private function originOf(SugarBean $bean): string
    {
        $origin = trim((string) ($bean->erp_quote_origin ?? ''));

        return in_array($origin, array(self::ORIGIN_SYNC, self::ORIGIN_SUGAR), true) ? $origin : '';
    }

    /**
     * G167 I3 — "A QUOTE WITH NO OPPORTUNITY CANNOT BE PRIMARY. The flag is
     * cleared, or refused on write."
     *
     * Returns true when it cleared the flag, so doEnforce() stops: there is no
     * Opportunity, therefore nothing to be sole primary OF, and every step
     * below this point needs exactly one.
     *
     * 🛑 CLEARED, NOT REFUSED, AND THE CHOICE IS THE WHOLE RISK OF THIS RULE.
     * G167 offers both; refusing is the one that breaks the product. 93% of
     * quotes here (146 of 157, measured for 🔒 710) are created with NO
     * Opportunity and linked afterwards - the seed loader, "Create Opportunity
     * and Quote", every REST integration. A save-time refusal on a flagged
     * orphan turns each of those into a failed button, which is precisely the
     * regression G179's guard had to be un-armed for (`AccountsErpActionsApi`
     * set the billing account before the opportunity link existed). A gap
     * about data quality must not ship as a broken create.
     *
     * 🛑 AND IT COSTS NOTHING TO DO IT THIS WAY ROUND, because 🔒 710 puts the
     * flag back the moment the quote joins a deal: bootstrapFirstPrimary()
     * fires on after_relationship_add and stamps the first quote on an
     * Opportunity. So the create-then-link path ends in exactly the state the
     * seller wanted - it simply never passes THROUGH the illegal one. That is
     * §DG's unreachable-rather-than-detected, obtained by doing less.
     *
     * before_save ONLY, for restoreUnsanctionedClear()'s reason: the cleared
     * value never reaches the database, so `erp_is_primary_quote` being
     * 'audited' does not produce a change-log row for a flag that was never
     * legitimately set. Clearing in after_save would need a nested save - the
     * thing this class refuses to do anywhere (see bootstrapFirstPrimary).
     *
     * 🚩 A RELATIONSHIP THAT WILL NOT LOAD IS NOT AN ABSENT ONE. getOpportunityIds()
     * answers array() for BOTH "no link rows" and "load_relationship() failed",
     * and clearing a live primary quote's flag because a link read failed
     * would be this rule doing real damage on no evidence. So the load is
     * tested separately here and a failure leaves the flag alone.
     *
     * ⚠️ WHAT THIS DOES NOT DO, stated rather than discovered later: the 41
     * orphans already carrying the flag on the tenant are NOT healed by this.
     * They are only reached when somebody saves them. G167 sequences that
     * deliberately - "ship the rule first, THEN correct the data, then
     * re-measure" - because a sweep run before the rule re-arms. The sweep is
     * a separate, owner-authorised data task and is not code.
     */
    private function clearOrphanPrimary(SugarBean $bean, string $event): bool
    {
        if ($event !== 'before_save') {
            return false;
        }
        // 🔒 1472 / 🔒 1473 — THE FOURTH GATE. A quote the connector created
        // from Epicor is primary from creation with NO Opportunity; a seller
        // links one later. Clearing it here would clear it in the very create
        // that set it. So the clear is for a quote KNOWN to be Sugar-born.
        //
        // 🛑 AND "UNKNOWN" IS NOT "SUGAR" (🔒 1428). A quote that predates the
        // stamp carries no origin at all. Treating that as Sugar-born would
        // clear every existing Epicor-created orphan on its next save - and
        // the connector re-saves Epicor quotes on every sync, so that would
        // happen within one sync of the deploy, before anyone had reviewed
        // which ones they were. Those rows are decided by
        // scripts/clear_orphan_primary_flags.py, from their creation facts,
        // after a dry run the owner sees.
        if ($this->originOf($bean) !== self::ORIGIN_SUGAR) {
            return false;
        }
        if (!$bean->load_relationship('opportunities') || !is_object($bean->opportunities)) {
            // Could not ask. Not an answer, and not a licence to clear.
            $GLOBALS['log']->error(
                'QuotePrimaryQuoteSoleEnforcer: could not load the opportunities link on quote '
                . $bean->id . ' - leaving erp_is_primary_quote alone rather than clearing it on a'
                . ' read that did not happen (G167 I3)'
            );

            return false;
        }
        if ($this->getOpportunityIds($bean) !== array()) {
            return false;
        }

        $bean->erp_is_primary_quote = false;
        $GLOBALS['log']->info(
            'QuotePrimaryQuoteSoleEnforcer: cleared erp_is_primary_quote on quote ' . $bean->id
            . ' - it has no Opportunity, so there is nothing for it to be the primary quote of'
            . ' (G167 I3). Link it to an Opportunity and decision 710 stamps it back.'
        );

        return true;
    }

    /**
     * 🔒 1468 (G215) — LINKING IS NOT MARKING: A PRIMARY QUOTE THAT JOINS A
     * DEAL WHICH ALREADY HAS ONE YIELDS TO IT.
     *
     * 🔒 1472 / 🔒 1473 make an Epicor-created quote primary from creation
     * with NO Opportunity; a seller links one later. When the deal they link
     * it to already has a primary, the owner's rule is: the person's existing
     * primary stays, the linked quote's flag clears, exactly one results.
     *
     * 🛑 WHAT THE CODE DID BEFORE THIS, MEASURED ON THE SHIPPED MODE ('move').
     * after_relationship_add reached doEnforce() with the joiner primary, the
     * mode gate let it through (it is not before_save), otherPrimaryQuoteIds()
     * found the incumbent, and movePrimaryTo() handed the deal to the JOINER -
     * demoting the seller's primary and republishing the Opportunity amount
     * from a quote nobody chose. In 'refuse' mode it returned instead and left
     * TWO primaries. Neither is the ruling.
     *
     * THE DISCRIMINATOR IS "WAS IT PRIMARY BEFORE THIS OPERATION", from
     * fetched_row - the platform's own pre-save snapshot. A quote the database
     * already held as primary is being LINKED; a quote whose flag goes
     * false -> true in this same save is being MARKED by a person, and the
     * existing move rule (the seller's tick wins) still applies to it.
     *
     * ONLY after_relationship_add ON THE OPPORTUNITIES LINK (doEnforce()
     * filters the link). A deal with NO primary is left alone here: the joiner
     * keeps its flag and QuoteOpportunityAmount, at priority 20 on the same
     * event, gives the Opportunity its total.
     *
     * THE CLEAR IS ADMITTED BY SingleDemotionSiteTest by name and by guard. It
     * cannot leave a deal with zero primaries, because it fires only when the
     * deal already has one. It records TRIGGER_YIELDED_ON_JOIN so that
     * restoreUnsanctionedClear() does not put the flag back, and so that
     * QuoteOpportunityAmount does not read this clear as the Opportunity's
     * primary being withdrawn - it never was that quote, and blanking the
     * amount here would be G199's hole reopened from the other side.
     *
     * Persistence follows bootstrapFirstPrimary() exactly: inside a save in
     * flight the flag rides that write; on the link-later path (the quote was
     * loaded fresh by the relationship code) it is saved here, once.
     */
    private function yieldOnJoin(SugarBean $bean, string $event): bool
    {
        if ($event !== 'after_relationship_add') {
            return false;
        }
        if (empty($bean->fetched_row['erp_is_primary_quote'])) {
            return false;
        }
        $oppIds = $this->getOpportunityIds($bean);
        if (count($oppIds) !== 1) {
            return false;
        }
        $oppId = (string) reset($oppIds);
        if (!$this->otherPrimaryQuoteIds($oppId, $bean->id)) {
            return false;
        }

        $bean->erp_is_primary_quote = false;
        self::noteTrigger((string) $bean->id, self::TRIGGER_YIELDED_ON_JOIN);
        $GLOBALS['log']->info(
            'QuotePrimaryQuoteSoleEnforcer: quote ' . $bean->id . ' was already primary and joined'
            . ' opportunity ' . $oppId . ', which has its own primary quote - it yields to that'
            . ' one (🔒 1468: linking is not marking)'
        );

        if (!empty($bean->in_save)) {
            return true;
        }

        self::$enforcing = true;
        try {
            $bean->save();
        } finally {
            self::$enforcing = false;
        }

        return true;
    }

    /**
     * 🔒 710 — THE FIRST QUOTE ON AN OPPORTUNITY IS THE PRIMARY ONE.
     *
     * No user action, no transition, no stage: a deal with exactly one quote
     * and no primary has precisely one candidate, so "which quote drives the
     * headline amount" has an answer and this writes it down.
     *
     * WHY IT HANGS OFF THE LINK AND NOT THE CREATE. 93% of quotes on Ophir
     * (146 of 157) are created with NO Opportunity - the seed loader makes
     * them 'Closed Accepted' first and links them afterwards - so a bootstrap
     * that only fired at create-with-Opportunity would miss the population it
     * exists for. after_relationship_add is the event that means "this quote
     * just joined a deal", and it covers BOTH halves of 710: creating a quote
     * on an Opportunity and linking an existing one are the same event.
     *
     * 🛑 ONLY THAT EVENT, AND THE RESTRICTION IS LOAD-BEARING - IT IS WHAT
     * KEEPS THIS FROM PROMOTING A SIBLING. Firing on every after_save would
     * make any later save of any non-primary quote stamp itself the moment
     * its Opportunity happened to have no primary - which is exactly the
     * "arbitrarily choose a sibling" that owner ruling 787(4) forbids
     * outright and that test_a_sibling_is_NEVER_promoted_to_fill_the_gap
     * pins. A quote that survives a deleted primary was never the FIRST quote
     * on that deal, so 710 does not reach it: 787(4)'s visible unvalued state
     * stands, and choosing the replacement stays a human decision.
     *
     * 710 and 787(4) therefore divide cleanly by event, not by preference:
     *
     *     a quote JOINS a deal that has no primary   -> 710 stamps it
     *     a deal LOSES its primary (delete / unlink) -> 787(4) unvalues it
     *
     * ⚠️ WHAT THIS DOES NOT DO, stated rather than discovered later: it does
     * not heal an Opportunity that ALREADY sits at zero primaries, because
     * that Opportunity's link events are in the past. G92's fix legitimately
     * leaves that population behind. Repairing it is a one-off data task -
     * stamp the sole quote of every zero-primary Opportunity - and is NOT
     * done from here, for the same reason: from here it would be
     * indistinguishable from promoting a sibling.
     *
     * 📌 NOT before_save either, even though landing in the same write would
     * be neater. At before_save on a create the relationship row does not
     * exist yet, so getOpportunityIds() is empty and there is nothing to
     * decide - the check would be structurally incapable of firing on the
     * very case it is for. The extra save is paid once per Opportunity, ever.
     *
     * IDEMPOTENT BY DATA, like the rest of this class: a second quote linked
     * to the same Opportunity finds an incumbent and stamps nothing, so
     * "exactly one" holds without anybody counting and refusing.
     */
    private function bootstrapFirstPrimary(SugarBean $bean, string $event): void
    {
        if ($event !== 'after_relationship_add') {
            return;
        }

        $oppIds = $this->getOpportunityIds($bean);
        if (count($oppIds) !== 1) {
            // No Opportunity: primary of what? More than one: the same
            // refusal-to-guess doEnforce() makes, for the same reason.
            return;
        }
        $oppId = (string) reset($oppIds);

        // Somebody already holds it. ($bean is not primary here, so excluding
        // it from the scan is right, and this is the SAME "who is primary"
        // read the move path uses - a rule written twice drifts.)
        if ($this->otherPrimaryQuoteIds($oppId, $bean->id)) {
            return;
        }

        // 🛑 "NO OTHER PRIMARY" IS NOT "FIRST". 🔒 710's own word is FIRST -
        // "every quote that is created as FIRST on opperunty is paimary" - and
        // the check above only asked whether anybody else holds the flag. On a
        // deal whose primary was withdrawn (🔒 787(4): left visibly unvalued,
        // re-affirmed by the owner 2026-09-22 with "no sibling is
        // auto-promoted") every later joiner passed it, was stamped primary,
        // was labelled "first-created pick", and the unvalued deal was then
        // valued off it by QuoteOpportunityAmount. Shown by executing this
        // method on a CONSTRUCTED fixture (a copy joining a deal with nine
        // live quotes and no primary came out PRIMARY) - not on quote 310's
        // measured state: c176ed74 is in G167's 09-21 baseline as a deal WITH
        // a primary, and on such a deal the check above already declined to
        // stamp. So this closes the zero-primary variant of the copy concern,
        // which is the system choosing the replacement 787(4) reserves for a
        // human; it is not claimed as 310's mechanism.
        //
        // 📌 hasOtherLiveQuote() SUBSUMES the otherPrimaryQuoteIds() check
        // above - any other primary is a live quote. That check is kept for
        // its log-free cheap exit on the common case, not because it still
        // decides anything the second one would not.
        if ($this->hasOtherLiveQuote($oppId, (string) $bean->id)) {
            return;
        }

        $bean->erp_is_primary_quote = true;
        // G167 I4. Set BEFORE either branch below: the in_save branch does not
        // save, it lets the flag ride the save already in flight - and that
        // save's own QuoteOpportunityAmount pass is the one that needs to know
        // why the quote it is publishing became primary.
        self::noteTrigger((string) $bean->id, self::TRIGGER_FIRST_PICK);

        // 🛑 NEVER NEST A SAVE INSIDE THE SAVE THAT TRIGGERED THIS. Measured
        // in the platform source (SugarEnt 26.1.0 data/SugarBean.php), not
        // inferred:
        //
        //   :2202  saveData() sets $this->in_save = true
        //   :2241  save_relationship_changes()  <- THE LINK IS WRITTEN HERE,
        //          so after_relationship_add fires from inside this very save
        //   :2251  before_save
        //   :2288  db->insert(), which is also where new_with_id is cleared
        //
        // CREATING a Quote with an Opportunity resolves the relate field
        // inside that save, so this hook runs on a row that DOES NOT EXIST
        // YET and whose new_with_id is still set. save() there would run a
        // second full hook cycle and INSERT the record a second time.
        //
        // 📌 AND IT IS UNNECESSARY, WHICH IS THE NEAT PART. The relationship
        // is written BEFORE the insert, so a flag set here rides the SAME
        // write - exactly what QuoteAcceptSiblingReject's docblock asks for
        // ("erp_is_primary_quote needs to land in the SAME write"), obtained
        // by doing less rather than more.
        //
        // in_save is the platform's own signal for this: BeanFactory.php:495
        // reads `->in_save` for the same question. It is a dynamic property
        // on a bean that has never been saved, so empty() and not isset().
        //
        // The LINK-LATER path - the 93% - is the other branch: there the
        // quote bean is loaded fresh by the relationship code, no save is in
        // flight, and nothing else is going to persist the flag for us.
        if (!empty($bean->in_save)) {
            $GLOBALS['log']->info(
                'QuotePrimaryQuoteSoleEnforcer: quote ' . $bean->id
                . ' is the first quote on opportunity ' . $oppId
                . ' and is being made its primary quote in the same save (decision 710)'
            );

            return;
        }

        self::$enforcing = true;
        try {
            $bean->save();
        } finally {
            self::$enforcing = false;
        }

        $GLOBALS['log']->info(
            'QuotePrimaryQuoteSoleEnforcer: quote ' . $bean->id
            . ' is the first quote on opportunity ' . $oppId
            . ' and has been made its primary quote (decision 710)'
        );
    }

    /**
     * THE MOVE, as one serialized all-or-nothing operation.
     *
     * ⚠️ WHY THE LOCK AND THE SELF-RE-READ EXIST — a two-writer race that DOES
     * reach ZERO primaries, which is the outcome 145(1)/146(c) single out as
     * worse than the bug being fixed.
     *
     * Without them, two PHP workers PATCHing quotes A and B on the same
     * Opportunity within a few milliseconds interleave like this:
     *
     *   A.save commits A=1
     *   B.save commits B=1
     *   A's after_save: rivals = {B} -> clears B
     *   B's after_save: its IN-MEMORY flag is still true (the bean never
     *                   re-reads itself), rivals = {A} -> clears A
     *   => A=0, B=0. The Opportunity silently stops being valued.
     *
     * Ordering alone cannot prevent this: each writer's own set really is
     * durable before it clears anyone, which is all the after_save argument
     * ever bought. Two things close it, and both are needed:
     *
     *   1. SELECT ... FOR UPDATE on the Opportunity row SERIALIZES the two
     *      moves, so the second one cannot observe a half-finished first.
     *   2. Re-reading OUR OWN flag FROM THE DATABASE (never from the bean in
     *      memory) makes the loser of that race STAND DOWN, because by then a
     *      concurrent winner has already cleared it.
     *
     * Result under the race: exactly one primary, not zero.
     */
    private function movePrimaryTo(SugarBean $winner, string $oppId): void
    {
        $db = DBManagerFactory::getInstance();
        $conn = $db->getConnection();

        $conn->beginTransaction();
        try {
            if (!$this->lockOpportunity($conn, $oppId)) {
                // UNSERIALIZED IS NOT A SAFE DEGRADATION - see lockOpportunity.
                // Abandon the MOVE, keep the winner's already-durable flag.
                // Worst reachable state is TWO primaries, which decision 145
                // ranks strictly above the ZERO this would otherwise risk.
                $conn->rollBack();
                return;
            }

            // THE SELF-RE-READ. `use_cache => false` is load-bearing: a cached
            // bean would hand back the same stale in-memory flag this guard
            // exists to distrust.
            $fresh = BeanFactory::retrieveBean('Quotes', $winner->id, array('use_cache' => false));
            if (!$fresh || empty($fresh->erp_is_primary_quote)) {
                // A concurrent writer won and already cleared us. Standing
                // down leaves THEIR quote as the sole primary; clearing anyone
                // from here is what would produce zero.
                $conn->commit();
                $GLOBALS['log']->info(
                    'QuotePrimaryQuoteSoleEnforcer: stood down on opportunity ' . $oppId
                    . ' - quote ' . $winner->id . ' was cleared by a concurrent primary move'
                );
                return;
            }

            // Authoritative re-check, now that nobody else can be mid-move.
            $rivalIds = $this->otherPrimaryQuoteIds($oppId, $winner->id);
            if (!$rivalIds) {
                $conn->commit();
                return;
            }

            // G167 I4, recorded HERE and not at the top of the move: above this
            // line the move can still abandon itself (lock refused, self
            // re-read shows a concurrent winner already cleared us, no rivals
            // left). A trigger noted before those exits would report a hijack
            // that did not happen, on a quote that may have LOST the race -
            // which is the same "publish from a bean the database has already
            // cleared" defect stillPrimary() exists to stop, wearing a label.
            self::noteTrigger((string) $winner->id, self::TRIGGER_HIJACK);
            $this->clearRivals($rivalIds, $winner, $oppId);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Serialize concurrent primary moves on one Opportunity.
     *
     * RETURNS FALSE RATHER THAN DEGRADING, and the caller abandons the move.
     *
     * The previous behaviour logged a warning and proceeded unserialized, on
     * the argument that "the single-writer case is every case outside a
     * genuine race". That argument inverts the risk. Outside a race the lock
     * is not needed, so nothing is gained; INSIDE a race - the only situation
     * this lock exists for - proceeding without it is precisely what lets two
     * writers each pass the self-re-read and then clear one another, leaving
     * the Opportunity with ZERO primaries.
     *
     * Decision 145 ranks those two outcomes explicitly, and it is the owner's
     * ruling rather than this lane's preference:
     *
     *     "If it can half-apply, there is a window with two primaries - or
     *      none - and 'none' is worse than the bug being fixed."
     *
     * Zero is the silent one: every consumer gate returns early on an empty
     * flag, so an Opportunity with no primary simply stops being valued and
     * says nothing. Two primaries is visible, is what decision 143 was raised
     * to fix, and is repairable by the next save. So when the lock cannot be
     * taken, the safe direction is to leave the winner's already-durable flag
     * alone and clear nobody.
     *
     * Logged at FATAL, not WARN: a lock that cannot be acquired on this
     * connection means the serialization the whole class rests on is gone,
     * and that is an operational condition, not a curiosity.
     */
    private function lockOpportunity($conn, string $oppId): bool
    {
        try {
            $conn->executeQuery('SELECT id FROM opportunities WHERE id = ? FOR UPDATE', array($oppId));

            return true;
        } catch (\Throwable $e) {
            $GLOBALS['log']->fatal(
                'QuotePrimaryQuoteSoleEnforcer: could not lock opportunity ' . $oppId
                . ' (' . $e->getMessage() . '); ABANDONING the primary move - the winner keeps its '
                . 'flag and no rival is cleared, because an unserialized clear can leave ZERO primaries'
            );

            return false;
        }
    }

    /**
     * THE MOVE, as one all-or-nothing operation.
     *
     * Deliberately clears ONLY the losers and never re-saves the winner. The
     * winner's flag is already durable (that is why this runs in after_save),
     * and re-saving a primary quote would re-fire QuoteOpportunityAmount -
     * which on a price-break ladder republishes the POLICY_SUM total and can
     * inflate an Opportunity by orders of magnitude (register 89(b): quote
     * 1063 measured at 42,508,500 vs 500,100). Clearing a loser is
     * side-effect-free by comparison: QuoteOpportunityAmount::refreshPrimary()
     * returns early on an empty flag, so the loser's save publishes nothing.
     */
    private function clearRivals(array $rivalIds, SugarBean $winner, string $oppId): void
    {
        // Runs INSIDE movePrimaryTo()'s transaction and under its Opportunity
        // row lock - it does not open its own. Keeping the transaction one
        // level up is what makes the lock, the self-re-read, the rival
        // re-check and these clears a single atomic unit; a transaction
        // started here would leave the first three outside it.
        foreach ($rivalIds as $rivalId) {
                // use_cache => false for two separate reasons, both paid for.
                // (1) A rival a concurrent winner already cleared still reads
                //     1 from cache, so we would issue a redundant UPDATE, a
                //     duplicate quotes_audit row and a false "cleared" log line.
                // (2) save() rewrites every field the bean holds in memory -
                //     this package has already had one save silently revert an
                //     untouched field (see QuotesErpActionsApi's re-retrieve
                //     comments). A bean cached from before the lock would write
                //     back whatever the connector stamped on it in the interim.
                $loser = BeanFactory::retrieveBean('Quotes', $rivalId, array('use_cache' => false));
                if (!$loser || !empty($loser->deleted)) {
                    continue;
                }
                if (empty($loser->erp_is_primary_quote)) {
                    continue;
                }
                // 🔒 709: the ONE sanctioned demotion site. Clearing the flag
                // inline here would now be restored by
                // restoreUnsanctionedClear() before it ever reached the
                // database, and the move would silently do nothing.
                self::demoteAndSave($loser);

                // Decision 145/146 both require the move to be LOGGED: a
                // forecast can change with no human action behind it, and on
                // 146 nobody clicked anything at all. The audit row above is
                // the durable record; this line is the operational one.
                $GLOBALS['log']->info(
                    'QuotePrimaryQuoteSoleEnforcer: primary quote moved on opportunity '
                    . $oppId . ' - cleared quote ' . $rivalId
                    . ', now primary is quote ' . $winner->id
                );
        }
    }

    /**
     * Ids of every OTHER quote on $oppId that also carries the primary flag.
     *
     * Goes through the relationship, exactly like
     * QuoteAcceptSiblingReject::getSiblingQuoteIds(): Quotes.opportunity_id is
     * a NON-DB relate field - there is no such column on the quotes table - so
     * a raw field compare finds nothing. This is also why the census probe for
     * this defect must use Opportunities/{id}/link/quotes and never the
     * `opportunities.id` in a Quotes LIST payload, which echoes the quote's
     * own id and makes a collision structurally impossible to observe.
     *
     * ⚠️ EVERY READ HERE IS `use_cache => false`, AND THAT IS NOT DECORATION.
     * Called the second time - inside movePrimaryTo()'s transaction, after the
     * Opportunity row lock - this is meant to be the AUTHORITATIVE re-check. A
     * cached Opportunity bean carries an already-loaded Link2 whose get() does
     * NOT re-query, and cached sibling beans carry the flag values read before
     * the lock. Without these flags the "re-check" would return exactly what
     * the pre-check already saw and would be authoritative in name only.
     * A fresh bean gets a fresh Link2, so get() really does re-query.
     */
    private function otherPrimaryQuoteIds(string $oppId, string $selfId): array
    {
        $opportunity = BeanFactory::retrieveBean('Opportunities', $oppId, array('use_cache' => false));
        if (!$opportunity) {
            return array();
        }
        if (!$opportunity->load_relationship('quotes') || !is_object($opportunity->quotes)) {
            return array();
        }

        $rivals = array();
        foreach ($opportunity->quotes->get() as $quoteId) {
            if ($quoteId === $selfId) {
                continue;
            }
            $sibling = BeanFactory::retrieveBean('Quotes', $quoteId, array('use_cache' => false));
            if (!$sibling || !empty($sibling->deleted)) {
                continue;
            }
            if (!empty($sibling->erp_is_primary_quote)) {
                $rivals[] = $quoteId;
            }
        }

        return $rivals;
    }

    /**
     * 🔒 710's "FIRST": does $oppId carry any OTHER live quote besides $selfId?
     *
     * Deleted siblings do not count - a deal whose only earlier quote was
     * deleted has no quote a seller could have chosen instead, so the joiner
     * really is the first live one and 710 applies.
     *
     * 🛑 AN UNREADABLE DEAL ANSWERS "YES", i.e. do not promote. Not knowing
     * whether this is the first quote is not licence to stamp it: the wrong
     * stamp values a deal off a quote nobody chose, while the withheld stamp
     * leaves it in 787(4)'s VISIBLE unvalued state, which a seller can see and
     * fix by ticking Primary. Same reads as otherPrimaryQuoteIds(), for the
     * same reason: `use_cache => false` so the Link2 really re-queries.
     */
    private function hasOtherLiveQuote(string $oppId, string $selfId): bool
    {
        $opportunity = BeanFactory::retrieveBean('Opportunities', $oppId, array('use_cache' => false));
        if (!$opportunity || !$opportunity->load_relationship('quotes') || !is_object($opportunity->quotes)) {
            return true;
        }

        foreach ((array) $opportunity->quotes->get() as $quoteId) {
            if ((string) $quoteId === $selfId || empty($quoteId)) {
                continue;
            }
            $sibling = BeanFactory::retrieveBean('Quotes', $quoteId, array('use_cache' => false));
            if ($sibling && empty($sibling->deleted)) {
                return true;
            }
        }

        return false;
    }

    /** Opportunity ids this quote is linked to, through the relationship. */
    private function getOpportunityIds(SugarBean $bean): array
    {
        if (!$bean->load_relationship('opportunities') || !is_object($bean->opportunities)) {
            return array();
        }

        // array_filter() IS ON THE CLOUD SCANNER'S DENYLIST and refused this
        // package: ERP-Epicor 1.1.24-rc29 came back "Code attempted to call
        // denylisted function "array_filter" on line 453 on 2026-09-15.
        // Every function that takes a callable is denied -- array_filter,
        // array_map, array_walk, usort, preg_replace_callback -- because it is
        // an indirect-dispatch vector, whether or not a callable is passed.
        // This loop is exactly equivalent: array_filter with no callback drops
        // falsy entries, and array_values reindexes from zero.
        $relatedIds = array();
        foreach ((array) $bean->opportunities->get() as $relatedId) {
            if (!empty($relatedId)) {
                $relatedIds[] = $relatedId;
            }
        }

        return $relatedIds;
    }
}
