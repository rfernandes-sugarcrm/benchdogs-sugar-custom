<?php

namespace Sugarcrm\Sugarcrm\custom\Erp;

/** G401 / 🔒 1701b — the quote stage flow, in one place (owner yes/no, 2026-09-23 ~20:50Z). (🔒 1628, 🔒 1566, 🔒 1636) */
class ErpQuoteStageRules
{
    /** G401 / 🔒 1701b.2 — the one stage from which an order may be raised first, by either route. */
    const ORDERING_STAGE = 'Confirmed';

    /** Some lines ordered; the remainder is ordered from here. */
    const PARTIALLY_FULFILLED = 'Partially Fulfilled';

    /** Ordered in full - written by the order. */
    const ORDERED = 'Closed Accepted';

    /**
     * Legacy pre-order stage. The replace install moves its quotes to 'Delivered' and the list no longer serves it;
     * the key stays named so a row that still holds it is read as a pre-order stage.
     */
    const LEGACY_SENT = 'Sent';

    /** Ordered in full. */
    const ORDERED_STAGES = array(self::ORDERED);

    /** The quote has won its deal: ordered in full or in part. (decision 146) */
    const COMMITTED_STAGES = array(self::ORDERED, self::PARTIALLY_FULFILLED);

    /** 🔒 1628.4 — the only stages at which Get Best Price & Availability is offered and accepted. */
    const PRICE_LOOKUP_STAGES = array('Draft', 'Delivered', 'On Hold', 'Priced', 'Negotiation');

    /**
     * 🔒 1624 — "discount edits lock at the same point" Get Best Price & Availability stops being offered: from Confirmed on. (G401, 🔒 1606)
     */
    const DISCOUNT_LOCKED_STAGES = array(self::ORDERING_STAGE, self::ORDERED, self::PARTIALLY_FULFILLED);

    /**
     * G556 — a line's agreed terms (Unit Price, Quantity, Unit of Measure, Product) lock where its discount locks. (🔒 1606, 🔒 1624)
     */
    const OPEN_LINE_QUANTITY_STAGES = array(self::PARTIALLY_FULFILLED);

    public static function isOrderedInFull($stage): bool
    {
        return is_string($stage) && in_array($stage, self::ORDERED_STAGES, true);
    }

    public static function isCommitted($stage): bool
    {
        return is_string($stage) && in_array($stage, self::COMMITTED_STAGES, true);
    }

    public static function priceLookupAllowed($stage): bool
    {
        return is_string($stage) && in_array($stage, self::PRICE_LOOKUP_STAGES, true);
    }

    /**
     * Has the quote reached estimation, by its stage: In Estimating, Priced, or a stage past pricing.
     * The same three lists the Send to Estimation button hides on. Any other stage, or none, answers false.
     */
    public static function isAtOrPastEstimation($stage): bool
    {
        if (!is_string($stage) || $stage === '') {
            return false;
        }

        return $stage === ErpEstimatingStamps::ESTIMATING_STAGE
            || $stage === ErpPricedStageRatchet::PRICED_STAGE
            || in_array($stage, ErpPricedStageRatchet::POST_PRICED_STAGES, true);
    }

    /**
     * Has the estimator's price reached the quote, by its stage: Priced, or a stage past pricing. Draft, In Estimating,
     * any other stage, or none, answers false.
     */
    public static function isPricedOrPast($stage): bool
    {
        return is_string($stage) && ($stage === ErpPricedStageRatchet::PRICED_STAGE
            || in_array($stage, ErpPricedStageRatchet::POST_PRICED_STAGES, true));
    }

    /**
     * The stage as a seller reads it: the served quote_stage_dom label, the key itself when the list has none.
     * @param mixed      $stage
     * @param array|null $dom
     */
    public static function label($stage, ?array $dom = null): string
    {
        $stage = is_string($stage) ? $stage : '';
        if ($dom === null) {
            $dom = $GLOBALS['app_list_strings']['quote_stage_dom'] ?? null;
        }
        $label = is_array($dom) ? ($dom[$stage] ?? null) : null;

        return is_string($label) && $label !== '' ? $label : $stage;
    }
}
