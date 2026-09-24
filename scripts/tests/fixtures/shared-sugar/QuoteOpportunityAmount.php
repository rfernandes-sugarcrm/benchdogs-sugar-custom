<?php

// G167 I4 reads the primary-move trigger off QuotePrimaryQuoteSoleEnforcer,
// which is the only class that writes the flag and therefore the only one that
// knows why it moved.
//
// GUARDED ON THE CLASS WITH THE REQUIRE INSIDE THE GUARD, byte-for-byte the
// idiom QuoteAcceptSiblingReject.php documents at length, and for both of its
// reasons. (1) A bare class_exists() with no require is an OFF SWITCH: Sugar
// autoloads only the hook class it was asked for, so the trigger would read ''
// on every quote and every amount would be labelled "the quote's own total
// changed" - a fix that records a plausible falsehood, which is worse than
// recording nothing. (2) The guard is on the CLASS, not the path, because
// Sugar's own LogicHook::loadHookClass() require_once()s the same file by a
// CWD-relative spelling; behind a symlinked docroot the two spellings do not
// dedupe and the second load is a "Cannot redeclare class" fatal on every
// Quote save (MLP001; this repo paid for it on 2026-09-11).
if (!class_exists('QuotePrimaryQuoteSoleEnforcer', false)) {
    require_once __DIR__ . '/QuotePrimaryQuoteSoleEnforcer.php';
}

/**
 * Shared owner of the primary Quote's Opportunity headline amount.
 *
 * A quote need not have been ordered, and Partial Fulfillment need not be
 * installed. Use the stored native grand total, not a second calculation of
 * line prices that drops discounts, tax or shipping. A neutral optional
 * contribution contract can supply a policy-specific total; the writer and
 * currency conversion remain here. This does not choose a
 * primary quote or sum competing revisions. It never writes stage or RLIs.
 */
class QuoteOpportunityAmount
{
    public function refresh($bean, $event = '', $arguments = array()): void
    {
        if (!($bean instanceof SugarBean) || empty($bean->id)) {
            return;
        }
        if ($event === 'after_relationship_add' && ($arguments['link'] ?? '') !== 'opportunities') {
            return;
        }
        try {
            // THE INVALIDATION PATH - owner ruling 787(4), closing review
            // finding 4. This runs BEFORE the deleted-bean guard below on
            // purpose: a DELETED primary quote is one of the two doors this
            // ruling exists to close, and the old early-return on
            // `!empty($bean->deleted)` is precisely why a delete could not
            // recalculate anything (decision 447 measured it: 500 survives).
            if ($this->primaryWasWithdrawn($bean)) {
                $this->unvalue($bean);

                return;
            }
            if (!empty($bean->deleted)) {
                return;
            }
            $this->refreshPrimary($bean);
        } catch (\Throwable $e) {
            // Preserve the quote save; log a failure rather than claiming its
            // forecast was updated. No record payload or credentials in logs.
            $GLOBALS['log']->error('QuoteOpportunityAmount: refresh failed for quote '
                . $bean->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Whether this save WITHDREW the justification for a published amount.
     *
     * Two doors to one hole, and decision 447 measured both leaving the old
     * figure in place: the flag un-ticked, and the quote deleted. A third,
     * unlinking, arrives as a relationship event and is handled by the same
     * check because the link is re-read below.
     *
     * PROVENANCE COMES FROM ``fetched_row``, WHICH IS THE POINT. "This
     * Opportunity has an amount" is not enough to justify blanking it -
     * another package may have written it. What justifies it is that THIS
     * quote was the primary a moment ago and is not one now: the seller has
     * just withdrawn the thing the number rested on. A quote that was never
     * primary withdraws nothing and must not touch the figure.
     */
    private function primaryWasWithdrawn(SugarBean $quote): bool
    {
        $was = !empty($quote->fetched_row['erp_is_primary_quote']);
        if (!$was) {
            return false;
        }
        // 🔒 1468 (G215): a quote that was primary with NO Opportunity and
        // yielded on joining a deal that already had a primary was never THIS
        // Opportunity's primary. Its clear withdraws nothing, and blanking the
        // deal here would unvalue a deal whose primary is still standing.
        if (QuotePrimaryQuoteSoleEnforcer::triggerFor((string) $quote->id)
            === QuotePrimaryQuoteSoleEnforcer::TRIGGER_YIELDED_ON_JOIN) {
            return false;
        }
        if (!empty($quote->deleted)) {
            return true;
        }

        return empty($quote->erp_is_primary_quote);
    }

    /**
     * Put the Opportunity into the VISIBLE unvalued state ruling 787(4) chose.
     *
     * NULL, NOT ZERO. A stored 0.00 is indistinguishable from a genuinely
     * zero-value deal - the shape that put 994 fabricated 0.00 rows on a
     * tenant (decision 59). The reason string is what makes the blank
     * READABLE rather than merely empty, which is the half the review said was
     * missing: "nothing on the screen says the number is now unowned".
     *
     * NO SIBLING IS PROMOTED. The review forbids it outright and
     * ``test_a_sibling_is_NEVER_promoted_to_fill_the_gap`` still pins it; this
     * method does not look at siblings at all.
     */
    private function unvalue(SugarBean $quote): void
    {
        if ($this->usingRevenueLineItems()) {
            return;
        }
        if (!$quote->load_relationship('opportunities') || !is_object($quote->opportunities)) {
            return;
        }
        $ids = $quote->opportunities->get();
        if (count($ids) !== 1) {
            // Ambiguous ownership must not blank the first record it finds -
            // the same rule the publish path applies.
            return;
        }
        $opportunity = BeanFactory::retrieveBean('Opportunities', reset($ids), array('use_cache' => false));
        if (!$opportunity || !empty($opportunity->deleted)) {
            return;
        }
        // A CLOSED Opportunity keeps its number. The deal is done; blanking a
        // won or lost forecast rewrites history rather than reporting it - and
        // the publish path already refuses to touch these for the same reason.
        if (in_array($opportunity->sales_stage ?? '', ['Closed Won', 'Closed Lost'], true)) {
            return;
        }

        $reason = !empty($quote->deleted)
            ? 'The primary quote was deleted, so this forecast has no source.'
            : 'The primary quote flag was cleared, so this forecast has no source.';

        // Idempotent: already unvalued for the same reason writes nothing, so
        // a re-save does not re-stamp date_modified and leave the audit
        // answering "since when" with "always". G167 I4 joins the condition:
        // a record still carrying a source sentence has not finished being
        // unvalued, whatever its amount says.
        $already = $opportunity->amount === null || $opportunity->amount === '';
        if ($already && (string) ($opportunity->erp_amount_unvalued ?? '') === $reason
            && (string) ($opportunity->erp_amount_source ?? '') === ''
        ) {
            return;
        }

        $opportunity->amount = null;
        $opportunity->erp_amount_unvalued = $reason;
        // G167 I4 — THE TWO FIELDS ARE MUTUALLY EXCLUSIVE BY CONSTRUCTION.
        // Leaving the old sentence here would leave the record saying "Amount
        // from quote 1265 (hijack)" beside "the primary quote was deleted, so
        // this forecast has no source" - simultaneously owned and unowned, and
        // the stale half is the one a seller would believe because it names a
        // quote. The publish path clears erp_amount_unvalued for the mirror
        // reason; neither clearing is optional.
        $opportunity->erp_amount_source = '';
        $opportunity->save();

        $GLOBALS['log']->info('QuoteOpportunityAmount: opportunity ' . $opportunity->id
            . ' unvalued - ' . $reason);
    }

    private function refreshPrimary(SugarBean $quote): void
    {
        if (empty($quote->erp_is_primary_quote) || $this->usingRevenueLineItems()) {
            return;
        }
        if (!$this->stillPrimary($quote)) {
            return;
        }
        // Missing/invalid is not a zero-price quote. A real zero still clears
        // the amount when the last line is removed or an estimate is reset.
        if (!isset($quote->total) || !is_numeric($quote->total)) {
            return;
        }
        if (!is_finite((float) $quote->total)) {
            throw new \UnexpectedValueException('Non-finite native Quote total');
        }
        if (!$quote->load_relationship('opportunities') || !is_object($quote->opportunities)) {
            return;
        }
        $ids = $quote->opportunities->get();
        if (count($ids) !== 1) {
            // Ambiguous/missing ownership must not choose the first record.
            return;
        }
        $opportunity = BeanFactory::retrieveBean('Opportunities', reset($ids), array('use_cache' => false));
        if (!$opportunity || !empty($opportunity->deleted)
            || in_array($opportunity->sales_stage ?? '', ['Closed Won', 'Closed Lost'], true)) {
            return;
        }

        $from = (string) ($quote->currency_id ?? '');
        $to = (string) ($opportunity->currency_id ?? '');
        if ($from === '' || $to === '') {
            return;
        }
        $amount = $this->contribution($quote);
        if ($from !== $to) {
            $amount = (float) SugarCurrency::convertAmount($amount, $from, $to);
        }
        // A finite input can overflow during conversion. Never publish an
        // invalid monetary result or mistake it for an acknowledged forecast.
        if (!is_finite($amount)) {
            throw new \UnexpectedValueException('Non-finite Opportunity contribution');
        }
        $amount = round($amount, 2);

        // G167 I4 — "a change of primary leaves a reason: which quote the
        // amount came from, and the trigger".
        $source = $this->sourceSentence($quote);

        // 🛑 THE EARLY RETURN NOW WEIGHS BOTH FACTS, AND THAT IS I4's
        // ANTI-COINCIDENCE CLAUSE, NOT A TIDY-UP. G167's own test 2 insists the
        // amount must MOVE when the primary moves, "or the test proves
        // nothing". The converse is this line: when two quotes on a deal happen
        // to total the SAME, a hijack moves the flag and the amount does not
        // change - and an amount-only comparison would return here and record
        // no reason for the one event I4 exists to explain. Two quotes at
        // 5,000.00 is not an exotic fixture; it is a revised quote.
        //
        // 🛑 G199 — AN UNVALUED OPPORTUNITY NEVER "MATCHES" A ZERO WINNER.
        // 🔒 1428 / decision 59 again: zero is not nothing. The old test read
        // `(float) ($opportunity->amount ?? 0)`, which coerces the NULL that
        // unvalue() writes into 0.0 - so a winner whose total is exactly 0.00
        // compared EQUAL to a blank deal and the publish returned here.
        //
        // That is reachable on EVERY hijack, not in some exotic corner:
        // movePrimaryTo() -> clearRivals() -> demoteAndSave($loser) fires the
        // LOSER's refresh, where primaryWasWithdrawn() is true, so unvalue()
        // NULLs the amount mid-move. The winner's own priority-20 publish is
        // what heals it. With a zero-total winner the heal was skipped and the
        // deal stayed BLANK - unknown rendered as nothing, on a record where
        // "we have not valued this" and "this is worth 0.00" are different
        // claims and only one of them is true.
        //
        // The $sourceSame clause below happens to mask this today, because
        // unvalue() also clears erp_amount_source so the sentences differ.
        // 🚩 THAT IS AN ACCIDENTAL GUARD ON AN UNRELATED FIELD: it holds only
        // for as long as I4's reason string keeps being written, and a future
        // narrowing of it (say, writing the source only when a trigger was
        // noted) would silently hand the blank deal back. The NULL test is
        // what makes the heal structural, so the two clauses are independent
        // and either one alone is sufficient.
        $unvalued = ($opportunity->amount === null || $opportunity->amount === '');
        $amountSame = !$unvalued && ((float) $opportunity->amount === $amount);
        $sourceSame = ((string) ($opportunity->erp_amount_source ?? '') === $source);
        if ($amountSame && $sourceSame) {
            // Genuinely nothing to say. Writing anyway would re-stamp
            // date_modified and leave the audit answering "since when" with
            // "always" - the same idempotence unvalue() keeps.
            return;
        }

        $opportunity->amount = $amount;
        // A real figure clears the unvalued marker: leaving it would say "no
        // source" beside a number that now has one.
        $opportunity->erp_amount_unvalued = '';
        $opportunity->erp_amount_source = $source;
        $opportunity->save();
        $GLOBALS['log']->info('QuoteOpportunityAmount: refreshed opportunity '
            . $opportunity->id . ' from primary quote ' . $quote->id
            . ' - ' . $source);
    }

    /**
     * G167 I4 — the sentence that says where this Opportunity's amount came
     * from and what moved it.
     *
     * TWO FACTS, because G167 names two: WHICH QUOTE, and THE TRIGGER.
     *
     * 🛑 THE TRIGGER IS NOT INFERRED HERE, IT IS READ. Only
     * QuotePrimaryQuoteSoleEnforcer moves the flag, so only it can say why; it
     * notes the trigger against the quote id at priority 10 and this runs at
     * 20 in the same dispatch. Guessing a trigger from the state visible here
     * is exactly the mistake this row is about - a number whose explanation was
     * reconstructed rather than recorded.
     *
     * NO TRIGGER IS A REAL ANSWER, NOT A MISSING ONE. An amount that moved
     * because the quote's own lines changed is not a change of primary, and
     * calling it "hijack" would put a false sentence in an audited field. It
     * says so plainly instead.
     *
     * 🛑 IDENTIFIES THE QUOTE BY quote_num FIRST. The id is a UUID a seller
     * cannot match against anything they can see; quote_num and the name are
     * what is on the screen and on the PDF. The id is the last resort rather
     * than the default, which is the opposite of what a log line would do -
     * this field is read by the seller, not by me.
     *
     * G445 — ONE NAME FOR ONE QUOTE, WHOEVER SAVED IT:
     * `Quote #<Sugar number> (Epicor <Epicor number>) "<name>"`, each part only
     * when known. Round 2 (stock, audit 03:31:11Z / 03:31:22Z) wrote the same
     * quote as "quote 1378" and then "quote 1033": the Sugar number is read as
     * STORED (sugarQuoteNumber()), and the Epicor number sits beside it
     * instead of standing in for it.
     */
    private function sourceSentence(SugarBean $quote): string
    {
        $num = self::sugarQuoteNumber($quote);
        $erp = self::erpQuoteNumber($quote);
        $name = trim((string) ($quote->name ?? ''));
        if ($num !== '') {
            $label = 'Quote #' . $num;
        } elseif ($name !== '') {
            $label = 'Quote "' . $name . '"';
        } else {
            $label = 'Quote ' . (string) $quote->id;
        }
        if ($erp !== '') {
            $label .= ' (Epicor ' . $erp . ')';
        }
        if ($num !== '' && $name !== '') {
            $label .= ' "' . $name . '"';
        }

        $trigger = QuotePrimaryQuoteSoleEnforcer::triggerFor((string) $quote->id);
        if ($trigger === '') {
            return 'Amount from ' . $label . '; the quote\'s own total changed.';
        }

        return 'Amount from ' . $label . '; ' . $trigger . '.';
    }

    /**
     * G445 — the quote's OWN Sugar number, as STORED.
     *
     * quote_num is an auto_increment column, and Sugar never writes one on an
     * update (include/database/DBManager.php:2480-2483, SugarEnt 26.1.0). But
     * the connector's quote upsert puts Epicor's QuoteNum on the bean (core
     * QuoteCoreTransformer::map_to_sell, `"quote_num": src.quote_num`), so
     * during a connector save the bean says 1378 while the row - and the
     * screen, and the PDF - say 1033. The fetched row is what is stored. A
     * create has none, and there the bean is right: SugarBean::saveData()
     * reloads auto_increment values after the insert
     * (loadAutoIncrementValues()), before any after_save hook runs.
     */
    private static function sugarQuoteNumber(SugarBean $quote): string
    {
        $row = $quote->fetched_row ?? null;
        $stored = is_array($row) ? trim((string) ($row['quote_num'] ?? '')) : '';

        return $stored !== '' ? $stored : trim((string) ($quote->quote_num ?? ''));
    }

    /**
     * G445 — the Epicor quote number, '' before the quote reaches Epicor: the
     * raw display key the connector stamps, else the part of the scoped
     * erp_sync_key after its company (`EPIC06__1378` -> `1378`).
     */
    private static function erpQuoteNumber(SugarBean $quote): string
    {
        $display = trim((string) ($quote->erp_display_sync_key ?? ''));
        if ($display !== '') {
            return $display;
        }
        $scoped = trim((string) ($quote->erp_sync_key ?? ''));
        $cut = strpos($scoped, '__');

        return $cut === false ? '' : trim(substr($scoped, $cut + 2));
    }

    /**
     * Is this quote STILL the primary, according to the database?
     *
     * THE IN-MEMORY FLAG IS NOT AN ANSWER, and trusting it is how a losing
     * quote publishes the headline amount.
     *
     * QuotePrimaryQuoteSoleEnforcer runs ahead of this hook (priority 10 vs
     * 20) and, when it finds that a concurrent writer has already cleared the
     * quote it was called for, it STANDS DOWN - correctly, because clearing
     * anyone from there is what would produce zero primaries. But standing
     * down leaves the request's own bean untouched, so `erp_is_primary_quote`
     * is still 1 in memory on a quote the database says is no longer primary.
     * This hook then read that flag and published that quote's total.
     *
     * The interleaving, with A and B both saving primary=true:
     *
     *     A: enforcer clears B, publishes A's amount
     *     B: enforcer re-reads, sees B was cleared, stands down
     *     B: THIS HOOK sees B's stale in-memory flag and publishes B's total
     *
     * The Opportunity then carries the amount of the quote that LOST, which
     * is the exact failure decision 146 names: "Leaving 'primary' pointing
     * anywhere else means the Opportunity is valued off a quote that lost."
     *
     * `use_cache => false` is load-bearing for the same reason it is on the
     * enforcer's own self-re-read: a cached bean hands back the stale flag
     * this check exists to distrust.
     *
     * WHAT THIS DOES NOT CLOSE - AN ACCEPTED RESIDUAL, NOT A CLOSED HOLE.
     *
     * A check and a write are two steps, and the flag can move between them.
     * The exact interleaving that still leaks, written out rather than
     * gestured at:
     *
     *   1. A is primary; the Opportunity carries A's total.
     *   2. A seller ticks B. B's enforcer clears A. The database says B.
     *   3. B's amount hook passes THIS check and starts computing.
     *   4. The seller ticks A again. A's enforcer clears B. Database says A.
     *   5. A's amount hook publishes A's total.
     *   6. B's publish from step 3 lands LAST, with B's total.
     *
     * Final state: the primary flag is A and the headline amount is B's.
     *
     * DECISION 145 DOES NOT RESCUE THIS, and an earlier draft of this
     * docblock wrongly said it did. 145 rules that the latest tick wins THE
     * FLAG. It says nothing about which AMOUNT lands last, and the two are
     * written by different hooks in different requests. Calling that
     * "resolved by 145" was a defence of the shortcut, not an argument.
     *
     * It is accepted here for one reason, stated so it can be overturned:
     * reaching it needs two competing primary ticks inside the few
     * milliseconds of one publish, whereas the defect this method DOES close
     * - publishing from a bean the database has already cleared - needs only
     * one ordinary concurrent save and was measured, not hypothesised.
     * Closing the larger gap and closing the common one are different
     * changes, and shipping the second should not wait on the first.
     *
     * THE REMEDY IF IT IS NOT ACCEPTABLE: make the publish conditional on
     * the flag at the moment of the write - either an UPDATE guarded by the
     * quote still being primary, or holding the Opportunity row lock across
     * the check and the save. An earlier draft asserted the lock route
     * "introduces a real lock-ordering deadlock". THAT IS UNVERIFIED and
     * should not be repeated as fact: the enforcer also takes the
     * Opportunity row first, so the orders may well agree. It is a question
     * to settle, not a reason already established. Decision 434.
     */
    private function stillPrimary(SugarBean $quote): bool
    {
        if (empty($quote->id)) {
            return false;
        }

        $fresh = BeanFactory::retrieveBean('Quotes', $quote->id, array('use_cache' => false));
        if (!$fresh || !empty($fresh->deleted)) {
            return false;
        }
        if (empty($fresh->erp_is_primary_quote)) {
            $GLOBALS['log']->info('QuoteOpportunityAmount: quote ' . $quote->id
                . ' is no longer the primary quote - not publishing its total');

            return false;
        }

        return true;
    }

    private function usingRevenueLineItems(): bool
    {
        if (class_exists('Opportunity') && method_exists('Opportunity', 'usingRevenueLineItems')) {
            return (bool) Opportunity::usingRevenueLineItems();
        }
        $settings = BeanFactory::getBean('Administration')->getConfigForModule('Opportunities');
        // A missing mode is not authorization to override native forecasting.
        return !is_array($settings) || ($settings['opps_view_by'] ?? '') !== 'Opportunities';
    }

    /**
     * Neutral optional contribution contract, still owned by this sole writer.
     * The fixed file defines ErpQuoteOpportunityContribution::resolve($quote).
     * Return null only when not applicable; otherwise return a finite int/float
     * in the Quote's currency, including the applicable commercial charges.
     * An applicable but unresolved policy must throw, never return null: the
     * outer handler then preserves the old amount and records the failure.
     * Providers must not write Opportunity or alter Quote lines/totals.
     */
    private function contribution(SugarBean $quote): float
    {
        $file = 'custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php';
        if (!file_exists($file)) {
            return (float) $quote->total;
        }
        require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($file);
        // G282 — THE FILE EXISTING IS NOT THE CLASS EXISTING, AND THE
        // DIFFERENCE IS EVERY OPPORTUNITY'S HEADLINE AMOUNT.
        //
        // Two packages ship this path; retiring one of them by shipping an
        // EMPTY STUB there would leave the class undefined. The `new` below
        // would then raise Error, refresh()'s catch(\Throwable) would swallow
        // it, and every Opportunity would silently keep its previous amount
        // for ever - a frozen estate whose only trace is a log line about a
        // class name. This makes that failure SAY what it is, in the same
        // preserve-and-log boundary: throwing here (rather than returning
        // $quote->total) keeps the contract "applicable but unresolved
        // throws", so the old amount is preserved rather than overwritten by
        // a number this package had no provider to compute.
        if (!class_exists('ErpQuoteOpportunityContribution', false)) {
            throw new \UnexpectedValueException(
                'The Opportunity contribution provider at ' . $file . ' defines no '
                . 'ErpQuoteOpportunityContribution class, so this Opportunity keeps the amount it '
                . 'already had. A package that retires this file must drop it from its build or '
                . 'ship a working provider - never an empty stub at this path (G282).'
            );
        }
        $amount = (new ErpQuoteOpportunityContribution())->resolve($quote);
        if ($amount === null) {
            return (float) $quote->total;
        }
        if ((!is_int($amount) && !is_float($amount)) || !is_finite((float) $amount)) {
            throw new \UnexpectedValueException('Invalid Opportunity contribution');
        }
        return (float) $amount;
    }
}
