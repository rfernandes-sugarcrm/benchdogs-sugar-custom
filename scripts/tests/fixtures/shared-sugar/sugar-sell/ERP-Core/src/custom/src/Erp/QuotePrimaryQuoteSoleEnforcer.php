<?php

namespace Sugarcrm\Sugarcrm\custom\Erp;

use BeanFactory;
use DBManagerFactory;
use SugarApiException;
use SugarApiExceptionInvalidParameter;
use SugarBean;

/**
 * Decision 143 / 145 / 146 — exactly one primary quote per opportunity, enforced at the write. (decision 143, decision 141, 🔒 709)
 */
class QuotePrimaryQuoteSoleEnforcer
{
    /** ============ the move-vs-refuse switch ============ 'move'. (decision 29, decision 145) */
    public const ON_SECOND_PRIMARY = 'move';

    /** Re-entrancy guard. Clearing a loser calls $loser->save(), which re-fires this very after_save hook. */
    private static $enforcing = false;

    /** 🔒 709. The licence to clear `erp_is_primary_quote`, held only for the duration of demoteAndSave(). */
    private static $sanctionedDemotion = false;

    /** G167 I4 — why the primary flag last moved, keyed by quote id. (G67(b)) */
    private static $primaryMoveTriggers = array();

    /** G167 I4: the first quote on a deal, made primary by construction (🔒 710). */
    public const TRIGGER_FIRST_PICK = 'first-created pick';

    /** G167 I4: another quote was ticked primary and took the flag (🔒 145). */
    public const TRIGGER_HIJACK = 'hijack';

    /** 🔒 1468 (G215): a quote that was already primary joined a deal that already had one, and yielded. */
    public const TRIGGER_YIELDED_ON_JOIN = 'yielded on join';

    /** 🔒 1472 — erp_quote_origin's two stated values. */
    public const ORIGIN_SYNC = 'erp_sync';
    public const ORIGIN_SUGAR = 'sugar';

    /**
     * The OAuth platforms that write Epicor's quotes into Sugar, so a quote they create is born 'erp_sync': sugarai_erp_connector the connector. (G832, 🔒 1474)
     */
    public const SYNC_PLATFORMS = array('sugarai_erp_connector', 'epicor_seed');

    /** Record why this quote just became - or stayed - the primary one. */
    public static function noteTrigger(string $quoteId, string $trigger): void
    {
        if ($quoteId === '' || $trigger === '') {
            return;
        }
        self::$primaryMoveTriggers[$quoteId] = $trigger;
    }

    /**
     * The trigger recorded for this quote in this request, or '' if the flag did not move here. '' is a real answer and must not be turned into a guess.
     */
    public static function triggerFor(string $quoteId): string
    {
        return (string) (self::$primaryMoveTriggers[$quoteId] ?? '');
    }

    /**
     * 🔒 709 — the only sanctioned way to clear the flag, and the reason the illegal state is unreachable rather than merely detected.
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
     * Registered entry point, and nothing more than a guard: an exception thrown here fails the save that triggered it, not just this method.
     */
    public function enforce($bean, $event = '', $arguments = array()): void
    {
        try {
            $this->doEnforce($bean, $event, $arguments);
        } catch (SugarApiException $e) {
            // The 'refuse' branch signals through this deliberately - a SugarApiException from a hook aborts the write, which is the control, not a bug.
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
        // 🔒 1472 — record who created the quote, in the creating save, before any rule below reads it. clearOrphanPrimary() needs it in this very save.
        $this->stampOriginAtBirth($bean, $event);
        // 🔒 1472 (G215) — and a quote the sync creates is primary in that same creating save, whatever the payload carried.
        $this->stampPrimaryAtSyncBirth($bean, $event);
        // after_relationship_add fires for every link on the module; only the Opportunity link can change which Opportunity this quote competes on.
        if ($event === 'after_relationship_add' && ($arguments['link'] ?? '') !== 'opportunities') {
            return;
        }
        // State, not transition.
        if (empty($bean->erp_is_primary_quote)) {
            // 🔒 709. An un-tick is not a user action.
            if ($this->restoreUnsanctionedClear($bean, $event)) {
                return;
            }

            // 🔒 710. Otherwise this quote may be the first on its Opportunity, in which case it is the primary by construction.
            $this->bootstrapFirstPrimary($bean, $event);

            return;
        }

        // G167 I3 — A quote with no opportunity cannot be primary.
        if ($this->clearOrphanPrimary($bean, $event)) {
            return;
        }

        // 🔒 1468 (G215) — linking is not marking. (🔒 1473)
        if ($this->yieldOnJoin($bean, $event)) {
            return;
        }

        // ===== which pass acts depends on the mode ===================== This is the entire reason both before_save and after_save are registered.
        $isBeforeSave = ($event === 'before_save');
        if (self::ON_SECOND_PRIMARY === 'refuse') {
            if (!$isBeforeSave) {
                return;
            }
        } elseif ($isBeforeSave) {
            return;
        }

        $oppIds = $this->getOpportunityIds($bean);
        // Exactly one, or this class has nothing well-defined to assert. count === 0: an unlinked quote competes with nothing. count > 1.
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

        // Cheap pre-check, deliberately outside any transaction.
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

    /** 🔒 709 — put back a flag nobody was allowed to clear. (G97, G92, decision 688) */
    private function restoreUnsanctionedClear(SugarBean $bean, string $event): bool
    {
        if ($event !== 'before_save' || self::$sanctionedDemotion) {
            return false;
        }
        // 🔒 1468: a joiner that yielded to the deal's primary in this save is a sanctioned clear, not an un-tick.
        if (self::triggerFor((string) $bean->id) === self::TRIGGER_YIELDED_ON_JOIN) {
            return false;
        }
        // Nothing to restore unless the database said true a moment ago.
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

    /** 🔒 1472 — stamp who created this quote, once, in its creating save. */
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
     * 🔒 1472 (G215) — "when ever a quote is synced for the first time to sugar it should be marked as primary as part of the creation". (🔒 710, 🔒 1468, G167)
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

    /** erp_quote_origin as stated, or '' when the quote predates the stamp. */
    private function originOf(SugarBean $bean): string
    {
        $origin = trim((string) ($bean->erp_quote_origin ?? ''));

        return in_array($origin, array(self::ORIGIN_SYNC, self::ORIGIN_SUGAR), true) ? $origin : '';
    }

    /** G167 I3 — "A quote with no opportunity cannot be primary. (🔒 710, G179) */
    private function clearOrphanPrimary(SugarBean $bean, string $event): bool
    {
        if ($event !== 'before_save') {
            return false;
        }
        // 🔒 1472 / 🔒 1473 — the fourth gate. (🔒 1428, G670)
        if ($this->originOf($bean) !== self::ORIGIN_SUGAR) {
            return false;
        }
        if (!$bean->load_relationship('opportunities') || !is_object($bean->opportunities)) {
            // Could not ask.
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
     * 🔒 1468 (G215) — linking is not marking: A primary quote that joins a deal which already has one yields to it. (🔒 1472, 🔒 1473, G199)
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

    /** 🔒 710 — the first quote on an opportunity is the primary one. (G92) */
    private function bootstrapFirstPrimary(SugarBean $bean, string $event): void
    {
        if ($event !== 'after_relationship_add') {
            return;
        }

        $oppIds = $this->getOpportunityIds($bean);
        if (count($oppIds) !== 1) {
            // No Opportunity: primary of what?
            return;
        }
        $oppId = (string) reset($oppIds);

        // Somebody already holds it.
        if ($this->otherPrimaryQuoteIds($oppId, $bean->id)) {
            return;
        }

        // "no other primary" is not "first". (🔒 710, 🔒 787, G167)
        if ($this->hasOtherLiveQuote($oppId, (string) $bean->id)) {
            return;
        }

        $bean->erp_is_primary_quote = true;
        // G167 I4. Set before either branch below: the in_save branch does not save, it lets the flag ride the save already in flight.
        self::noteTrigger((string) $bean->id, self::TRIGGER_FIRST_PICK);

        // Never nest a save inside the save that triggered this.
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

    /** The move, as one serialized all-or-nothing operation. */
    private function movePrimaryTo(SugarBean $winner, string $oppId): void
    {
        $db = DBManagerFactory::getInstance();
        // mlp-lint: ignore MLP025 the transaction and its row lock need the DBAL connection: Sugar has no lock API
        $conn = $db->getConnection();

        $conn->beginTransaction();
        try {
            if (!$this->lockOpportunity($conn, $oppId)) {
                // Unserialized is not a safe degradation - see lockOpportunity. (decision 145)
                $conn->rollBack();
                return;
            }

            // The self-re-read. `use_cache => false` is load-bearing: a cached bean would hand back the same stale in-memory flag this guard exists to distrust.
            $fresh = BeanFactory::retrieveBean('Quotes', $winner->id, array('use_cache' => false));
            if (!$fresh || empty($fresh->erp_is_primary_quote)) {
                // A concurrent writer won and already cleared us.
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

            // G167 I4, recorded here and not at the top of the move: above this line the move can still abandon itself.
            self::noteTrigger((string) $winner->id, self::TRIGGER_HIJACK);
            $this->clearRivals($rivalIds, $winner, $oppId);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /** Serialize concurrent primary moves on one Opportunity. (decision 143) */
    private function lockOpportunity($conn, string $oppId): bool
    {
        try {
            // mlp-lint: ignore MLP025 row lock: Sugar has no lock API; DBAL QueryBuilder::forUpdate() (doctrine/dbal src/Query/QueryBuilder.php:601)
            $builder = $conn->createQueryBuilder();
            $builder->select('id')->from('opportunities')
                ->where($builder->expr()->eq('id', $builder->createPositionalParameter($oppId)))
                ->forUpdate()
                ->executeQuery(); // mlp-lint: ignore MLP025 the lock above

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

    /** The move, as one all-or-nothing operation. */
    private function clearRivals(array $rivalIds, SugarBean $winner, string $oppId): void
    {
        // Runs inside movePrimaryTo()'s transaction and under its Opportunity row lock - it does not open its own.
        foreach ($rivalIds as $rivalId) {
                // use_cache => false for two separate reasons, both paid for.
                $loser = BeanFactory::retrieveBean('Quotes', $rivalId, array('use_cache' => false));
                if (!$loser || !empty($loser->deleted)) {
                    continue;
                }
                if (empty($loser->erp_is_primary_quote)) {
                    continue;
                }
                // 🔒 709: the one sanctioned demotion site.
                self::demoteAndSave($loser);

                // Decision 145/146 both require the move to be logged: a forecast can change with no human action behind it, and on 146 nobody clicked anything at all.
                $GLOBALS['log']->info(
                    'QuotePrimaryQuoteSoleEnforcer: primary quote moved on opportunity '
                    . $oppId . ' - cleared quote ' . $rivalId
                    . ', now primary is quote ' . $winner->id
                );
        }
    }

    /** Ids of every other quote on $oppId that also carries the primary flag. */
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

    /** 🔒 710's "first": does $oppId carry any other live quote besides $selfId? */
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

        // array_filter() is on the cloud scanner's denylist and refused this package.
        $relatedIds = array();
        foreach ((array) $bean->opportunities->get() as $relatedId) {
            if (!empty($relatedId)) {
                $relatedIds[] = $relatedId;
            }
        }

        return $relatedIds;
    }
}
