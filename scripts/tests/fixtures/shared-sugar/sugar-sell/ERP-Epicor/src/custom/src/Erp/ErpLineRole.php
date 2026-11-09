<?php

namespace Sugarcrm\Sugarcrm\custom\Erp;

use BeanFactory;
use Throwable;

/** Stamps ErpGoverningTotal'S verdict onto the lines (🔒 676). (🔒 644) */
class ErpLineRole
{
    /** The sentinel the subtotal formula tests for. */
    const ROLE_ALTERNATIVE = 'alternative';

    /** A line this class has looked at and decided does count. */
    const ROLE_COUNTS = 'counts';

    /** @var array<string,bool> */
    private static $inFlight = array();

    /**
     * Bring every line of this quote's stamp into agreement with 🔒 644.
     * @param SugarBean $quote
     * @return int
     */
    public static function stampQuote($quote): int
    {
        $quoteId = (string) ($quote->id ?? '');
        if ($quoteId === '' || !empty(self::$inFlight[$quoteId])) {
            return 0;
        }
        self::$inFlight[$quoteId] = true;
        try {
            $bundles = array();
            $lines = self::readLines($quote, $bundles);
            if (empty($lines)) {
                return 0;
            }

            $countable = array_flip(ErpGoverningTotal::countableLineIds($lines));

            $changed = 0;
            $totalsMoved = false;
            foreach ($lines as $line) {
                $id = (string) $line['id'];
                $want = isset($countable[$id]) ? self::ROLE_COUNTS : self::ROLE_ALTERNATIVE;

                if ($want === $line['role']) {
                    continue;                      // Already right: write nothing
                }

                // See the class docblock.
                if ($want === self::ROLE_ALTERNATIVE && !empty($line['flat_discount'])) {
                    $GLOBALS['log']->fatal(sprintf(
                        'ErpLineRole: line %s on quote %s is an unchosen quantity break BUT carries a '
                        . 'flat discount of %s, which a zeroed subtotal would not cancel. LEFT COUNTING '
                        . 'so the quote cannot go negative - the total still over-states this group.',
                        $id,
                        $quoteId,
                        (string) $line['flat_discount']
                    ));
                    continue;
                }

                $product = BeanFactory::retrieveBean('Products', $id);
                if ($product === null) {
                    continue;
                }
                $product->erp_total_role = $want;
                $product->save();
                $changed++;
                // The line formulas zero only an alternative; an unstamped line and a counting one sum alike.
                if ($want === self::ROLE_ALTERNATIVE || $line['role'] === self::ROLE_ALTERNATIVE) {
                    $totalsMoved = true;
                }
            }

            // Re-roll the bundles when a line went into or out of the totals, or the quote recomputes from stale ones.
            // A stamp that moved no total has nothing to re-roll.
            if ($totalsMoved) {
                foreach ($bundles as $bundle) {
                    // Inside a batch of connector line writes the group is re-rolled once, at the end.
                    if (!ErpQuoteLineBatch::defersGroupSave($bundle)) {
                        $bundle->save();
                    }
                }
            }

            if ($changed > 0) {
                $GLOBALS['log']->info(sprintf(
                    'ErpLineRole: quote %s - %d of %d lines restamped, %d now count toward the total '
                    . '(unchosen quantity-break alternatives excluded)',
                    $quoteId,
                    $changed,
                    count($lines),
                    count($countable)
                ));
            }

            return $changed;
        } catch (Throwable $e) {
            // Same rule the pin hook, the delete guard and the edit guard keep: a wrong total is bad, an unsaveable quote is worse.
            $GLOBALS['log']->fatal(
                'ErpLineRole: could not stamp quote ' . $quoteId
                . ', LEFT UNCHANGED: ' . $e->getMessage()
            );
            return 0;
        } finally {
            unset(self::$inFlight[$quoteId]);
        }
    }

    /** True while this quote is being stamped, so a cascade can stand down. */
    public static function isStamping(string $quoteId): bool
    {
        return !empty(self::$inFlight[$quoteId]);
    }

    /**
     * G780 — hold the per-line stamp while a caller rewrites a ladder, and stamp once afterwards. (🔒 676)
     * @param string $quoteId
     * @return bool
     */
    public static function holdStamping(string $quoteId): bool
    {
        if ($quoteId === '' || !empty(self::$inFlight[$quoteId])) {
            return false;
        }
        self::$inFlight[$quoteId] = true;

        return true;
    }

    /** Release a hold set by holdStamping(). */
    public static function releaseStamping(string $quoteId): void
    {
        unset(self::$inFlight[$quoteId]);
    }

    /** @var array<string,bool> */
    private static $cleaning = array();

    /** @var array<string,bool> */
    private static $reported = array();

    /**
     * 🔒 1467 / G192 — delete the lines the ERP does not hold, once it has answered.
     * @param SugarBean $quote
     * @return int
     */
    public static function cleanSugarOnlyLines($quote): int
    {
        $quoteId = (string) ($quote->id ?? '');
        if ($quoteId === '' || !empty(self::$cleaning[$quoteId]) || !empty(self::$inFlight[$quoteId])) {
            return 0;
        }
        // Answered before any line is read: only an estimation quote can have lines to delete.
        if (!ErpGoverningTotal::isEstimationQuoteType(self::quoteType($quote))) {
            return 0;
        }
        self::$cleaning[$quoteId] = true;
        try {
            $lines = self::readLines($quote);
            if (empty($lines)) {
                return 0;
            }

            $verdict = ErpGoverningTotal::sugarOnlyLineVerdicts($lines, self::quoteFacts($quote));
            if (empty($verdict['in_scope'])) {
                return 0;                       // Not an answered estimation quote
            }

            foreach ($verdict['skip'] as $lineId => $reason) {
                self::reportOnce($quoteId, (string) $lineId, 'SKIPPED (' . $reason . ')', sprintf(
                    'ErpLineRole: G192 keyless line %s on estimation quote %s was NOT deleted [%s] - %s '
                    . '(cut-off %s UTC). It stays on the quote and keeps its value.',
                    $lineId,
                    $quoteId,
                    $reason,
                    self::reasonText($reason),
                    $verdict['cutoff']
                ));
            }

            $deleted = 0;
            foreach ($verdict['delete'] as $lineId) {
                $product = BeanFactory::retrieveBean('Products', $lineId, array('use_cache' => false));
                if ($product === null || !empty($product->deleted)) {
                    continue;
                }
                // A delete re-rolls its bundle and re-saves its quote as it always did, even inside a batch.
                $paused = ErpQuoteLineBatch::pause();
                try {
                    $product->mark_deleted($lineId);
                } catch (Throwable $e) {
                    self::reportOnce($quoteId, (string) $lineId, 'SKIPPED (delete_refused)', sprintf(
                        'ErpLineRole: G192 keyless line %s on estimation quote %s was NOT deleted '
                        . '[delete_refused] - Sugar refused the delete: %s',
                        $lineId,
                        $quoteId,
                        $e->getMessage()
                    ));
                    continue;
                } finally {
                    ErpQuoteLineBatch::resume($paused);
                }
                $deleted++;
                // A cached pre-delete copy would still read deleted = 0 to any rollup later in this request (the memoised-link lesson).
                if (method_exists('BeanFactory', 'unregisterBean')) {
                    BeanFactory::unregisterBean('Products', $lineId);
                }
                $GLOBALS['log']->fatal(sprintf(
                    'ErpLineRole: G192 DELETED keyless line %s (%s, qty %s @ %s) from estimation quote %s - '
                    . 'created before the estimation cut-off %s UTC and carrying no ERP key, so Epicor '
                    . 'does not hold it (🔒 1467).',
                    $lineId,
                    (string) ($product->name ?? ''),
                    (string) ($product->quantity ?? ''),
                    (string) ($product->discount_price ?? ''),
                    $quoteId,
                    $verdict['cutoff']
                ));
            }

            return $deleted;
        } catch (Throwable $e) {
            // A line left in place is a number that is wrong; a failed save is a seller who cannot quote.
            $GLOBALS['log']->fatal(
                'ErpLineRole: G192 could not clean quote ' . $quoteId
                . ', NOTHING deleted: ' . $e->getMessage()
            );
            return 0;
        } finally {
            unset(self::$cleaning[$quoteId]);
        }
    }

    /**
     * The quote-level facts the decider needs. `erp_orders` is null when the quote's ERP-order link cannot be read - the decider fails closed on that.
     */
    private static function quoteFacts($quote): array
    {
        $orders = null;
        if ($quote->load_relationship('quotes_erp_orders')) {
            $orders = array();
            foreach ($quote->quotes_erp_orders->getBeans() as $order) {
                if (empty($order->deleted)) {
                    $orders[] = (string) ($order->erp_sync_key ?? $order->id);
                }
            }
        }

        return array(
            'type' => self::quoteType($quote),
            'sent_at' => (string) ($quote->erp_sent_to_estimating_at ?? ''),
            'stage' => (string) ($quote->quote_stage ?? ''),
            'erp_orders' => $orders,
        );
    }

    /** The quote's erp_quote_type, as the decider is handed it. */
    private static function quoteType($quote): string
    {
        return (string) ($quote->erp_quote_type ?? '');
    }

    private static function reasonText(string $reason): string
    {
        switch ($reason) {
            case 'ordered':
                return 'the line is on an ERP order (erp_ordered)';
            case 'quote_has_erp_order':
                return 'the quote has produced an ERP order and Sugar cannot tell which keyless lines '
                    . 'core bound to it (identity_xref quote_to_order_line)';
            case 'order_link_unreadable':
                return 'the quote\'s ERP-order link could not be read, so "not ordered" cannot be proven';
            case 'created_after_cutoff':
                return 'it was created after the quote went to estimation, so it is a seller\'s addition '
                    . 'Epicor has not been sent';
            case 'no_creation_time':
                return 'its creation time cannot be read, so it cannot be proven to predate the estimation';
        }

        return $reason;
    }

    private static function reportOnce(string $quoteId, string $lineId, string $tag, string $message): void
    {
        $k = $quoteId . '|' . $lineId . '|' . $tag;
        if (!empty(self::$reported[$k])) {
            return;
        }
        self::$reported[$k] = true;
        $GLOBALS['log']->fatal($message);
    }

    /** The ladder this line belongs to, as Epicor states it; the one copy of the rule in this package. (decision 789) */
    public static function ladderGroupOf($product): string
    {
        $key = trim((string) ($product->erp_sync_key ?? ''));
        if ($key !== '') {
            $cut = strrpos($key, '_');

            return $cut === false ? $key : substr($key, 0, $cut);
        }

        // A copy sheds the key, but not the ladder. (decision 789)
        $ladder = trim((string) ($product->erp_ladder_group ?? ''));
        if ($ladder !== '') {
            return $ladder;
        }

        return 'sugar:' . (string) ($product->id ?? '');
    }

    private static function readLines($quote, array &$bundles = array()): array
    {
        $out = array();
        $ids = ErpQuoteLineRows::lineIds($quote, false, $bundles);

        // Re-read the lines, do not trust the relationship's copy: inside a save it can still hold values from before it.
        foreach (ErpQuoteLineRows::stored((string) ($quote->id ?? ''), $ids, self::LINE_FIELDS) as $product) {
            // discount_select === percentage mode. Anything else means the discount_amount is a flat currency figure.
            $isPercent = in_array((string) ($product->discount_select ?? ''), array('1', 'true'), true);
            $discount = (float) ($product->discount_amount ?? 0);

            $out[] = array(
                'id' => (string) $product->id,
                'group' => self::ladderGroupOf($product),
                'quantity' => (float) ($product->quantity ?? 0),
                'price' => (float) ($product->discount_price ?? 0),
                'governing' => !empty($product->erp_governing),
                'ordered' => !empty($product->erp_ordered),
                // Identity must not survive a copy; provenance must.
                'origin' => ErpGoverningTotal::originOf(
                    $product->erp_sync_key ?? null,
                    $product->erp_break_quantity ?? null
                ),
                'role' => (string) ($product->erp_total_role ?? ''),
                // The seed stamp, not the line's shape.
                'placeholder' => !empty($product->erp_estimation_placeholder),
                'flat_discount' => (!$isPercent && $discount != 0.0) ? $discount : 0.0,
                // Identity, not provenance: the raw key, which a copy sheds. sugarOnlyLineVerdicts() deletes, so it asks the stricter question.
                'key' => (string) ($product->erp_sync_key ?? ''),
                'created' => (string) ($product->date_entered ?? ''),
            );
        }

        return $out;
    }

    /**
     * The line fields readLines() reads. A test compares the one-query read with the one-by-one read
     * field for field, so a field used above and missing here fails it.
     */
    const LINE_FIELDS = array(
        'id', 'quantity', 'discount_price', 'discount_select', 'discount_amount', 'erp_governing',
        'erp_ordered', 'erp_sync_key', 'erp_break_quantity', 'erp_ladder_group', 'erp_total_role',
        'erp_estimation_placeholder', 'date_entered',
    );
}
