<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * ERP-Epicor's Submit Order hook point, answered for G381 (🔒 1705b): on a
 * Bench Dogs ADM quote, a line with no ERP part number is BLOCKED from
 * ordering, with a seller message, instead of reaching ADM as PartNum '' and
 * coming back "Part is required."
 *
 * Contract (ErpQuoteHooks::fireResolveOrderableLines, ERP-Epicor): class
 * ErpQuoteResolveOrderableLinesHook, run(SugarBean $quote, array
 * $candidateLineIds): array, answering ['line_ids' => [...]] or
 * ['refuse' => ['error' => ..., 'message' => ...]].
 *
 * 🛑 IT NEVER NARROWS. The whole-quote Submit Order is refused whole or passed
 * whole: every candidate goes back unchanged, in core's order. A narrowed set
 * would quietly order something other than what the seller pressed for, and
 * an EMPTY list is a contract violation the caller refuses with an
 * administrator message; so an empty candidate set is answered with a refusal
 * of its own, in words a seller can act on.
 *
 * A quote of any company other than ADM gets its candidates back unchanged:
 * the same set ERP-Epicor sends when no package implements this hook.
 *
 * Why this file is Bench Dogs', not ERP-Epicor's: the rule is one customer's
 * ERP company's (gate G2). The retired rc37 Bench adapter at this path (the
 * ladder planner) is a different body; ONEOFF-RetireBdResidue deletes only
 * that exact body (md5 match) and skips this one.
 */
class ErpQuoteResolveOrderableLinesHook
{
    public function run($quote, array $candidateLineIds): array
    {
        if (!class_exists('BdAdmRules', false)) {
            require_once 'custom/modules/Quotes/BdAdmRules.php';
        }

        if ($candidateLineIds === array()) {
            return array('refuse' => array(
                'error' => 'No lines to order',
                'message' => 'This quote has no open lines to order. Add a line, then submit again.',
            ));
        }

        $refusal = BdAdmRules::nonPartRefusal($quote, $candidateLineIds);
        if ($refusal !== null) {
            return array('refuse' => array(
                'error' => 'A line has no ERP part number',
                'message' => $refusal,
            ));
        }

        return array('line_ids' => array_values($candidateLineIds));
    }
}
