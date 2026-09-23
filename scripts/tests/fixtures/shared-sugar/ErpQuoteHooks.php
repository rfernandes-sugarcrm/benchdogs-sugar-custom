<?php

/**
 * ERP-Epicor's Quotes lifecycle hook points. Each hook point has ONE fixed,
 * neutral contract path/class/method that ERP-Epicor names itself - never an
 * optional package's own class - so ERP-Epicor never has to know which
 * optional package (if any) implements it, or that package's internal class
 * names. See AGENTS.md's "Core code must never reference a specific
 * optional package's class by name" (QuotesErpActionsApi.php hardcoding
 * ErpOpportunityValuation from the optional ERP-Epicor-PartialFulfillment
 * package was that incident).
 *
 * Deliberately NOT glob/dynamic-dispatch based: Sugar's MLP package scanner
 * (ModuleScanner) rejects glob(), is_callable(), dynamic class
 * instantiation and dynamic method calls in any file shipped inside a
 * package zip - confirmed live, an earlier draft of this class was rejected
 * on upload for exactly those four things ("Invalid Package" /
 * upload_package_error). One fixed literal path/class/method per hook
 * point, guarded with file_exists() + try/catch (a require_once on a
 * missing file is a fatal compile error, not a \Throwable, so the guard has
 * to come first), is the scanner-safe shape - the same shape the code this
 * replaces already had, just pointed at a contract name ERP-Epicor owns
 * instead of the optional package's own business class.
 *
 * An optional package implements a hook point by shipping ONE file at the
 * fixed path the hook point's method documents, defining a class with the
 * fixed contract name and method, delegating to whatever its own real
 * implementation class is.
 */
class ErpQuoteHooks
{
    /**
     * Fired by QuotesErpActionsApi::orderLines() right after a release
     * lands. Contract: custom/modules/Quotes/ErpQuoteHooks/AfterLinesOrdered.php
     * defines class ErpQuoteAfterLinesOrderedHook with a
     * run(SugarBean $quote, bool $partial): string method. The returned
     * non-sensitive status is passed through to the action response so a
     * hosted run can distinguish a stage update from a preserved stage even
     * when application logs are unavailable. An older void hook remains
     * callable and reports hook_unreported. No-ops if that file does not
     * exist (no optional package implements this hook).
     */
    public static function fireAfterLinesOrdered($quote, bool $partial): string
    {
        $file = 'custom/modules/Quotes/ErpQuoteHooks/AfterLinesOrdered.php';
        if (!file_exists($file)) {
            return 'hook_absent';
        }

        try {
            require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($file);
            $status = (new ErpQuoteAfterLinesOrderedHook())->run($quote, $partial);
            $reported = [
                'updated',
                'unchanged',
                'opportunity_missing',
                'terminal',
                // policy_preserved is retained for an older Partial
                // Fulfillment hook. The specific values remain deliberately
                // bounded and contain no provider data, record ids or errors.
                'policy_preserved',
                'policy_provider_absent',
                'policy_provider_null',
                'policy_provider_invalid',
                'policy_provider_exception',
                'policy_invalid_shape',
                'policy_invalid_stage',
                'policy_invalid_probability',
                'policy_provider_absent_config_missing',
                'policy_provider_null_config_missing',
                'policy_provider_absent_config_invalid',
                'policy_provider_null_config_invalid',
            ];
            if (is_string($status) && in_array($status, $reported, true)) {
                return $status;
            }

            $GLOBALS['log']->warn('ErpQuoteHooks: AfterLinesOrdered returned no recognized status for quote '
                . $quote->id);
            return 'hook_unreported';
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('ErpQuoteHooks: AfterLinesOrdered failed for quote '
                . $quote->id . ': ' . $e->getMessage());
            return 'hook_failed';
        }
    }

    /**
     * Fired by QuotesErpActionsApi::runErpAction() BEFORE Submit Order sends
     * anything, so the layer that owns line SELECTION can narrow the set or
     * refuse it. Contract:
     * custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php defines
     * class ErpQuoteResolveOrderableLinesHook with
     * run(SugarBean $quote, array $candidateLineIds): array, answering
     * either ['line_ids' => [...]] or
     * ['refuse' => ['error' => '...', 'message' => '...']].
     *
     * WHY THIS EXISTS AT ALL. Decision 127 restores Submit Order as a CORE
     * button, and decision 29 requires it to REFUSE - never guess - when a
     * quantity-break group carries zero or two selections. But core does not
     * know what a break group is and must not learn: the grouping belongs to
     * Partial Fulfillment and the narrowing ("exactly one rung per group")
     * belongs to a customer package (decisions 87(b), 102, gate G2). Putting either
     * here is the mistake decision 102 caught, where a fleet-wide default
     * would have silently changed every non-a customer tenant's numbers.
     *
     * CORE CONSUMES A SELECTION; IT NEVER DECIDES ONE. This hook carries no
     * rule, no policy, no threshold and no field of its own - the refusing
     * layer supplies both the decision and the words. It is also the only
     * seam that could work: Submit Order posts to a route THIS class
     * registers, so an optional package has no other way to be consulted
     * before the order leaves.
     *
     * NOT A SECOND REFUSAL MECHANISM. A refusal answered here is returned
     * through QuotesErpActionsApi::sliceRefused() - the one refusal shape
     * that path already had - and persists nothing. erp_rollup_refusal
     * (Partial Fulfillment) stays the one and only refusal SURFACE; this
     * hands it a reason, it does not compete with it.
     *
     * WITH NO IMPLEMENTER, THE ANSWER IS THE CANDIDATES UNCHANGED. That is
     * not fail-open: without Partial Fulfillment there is no erp_ordered and
     * no grouping, and without a customer package there is no narrowing, so a "break
     * group" is not a thing that exists on such a tenant - its lines are
     * ordinary additive lines and every one of them is meant to be ordered
     * (decision 102: SUM for everything, MIN is Bench-only). There is
     * nothing to refuse because the precondition for a refusal cannot arise.
     *
     * Same scanner-safe shape as fireAfterLinesOrdered(): one fixed literal
     * path, class and method, file_exists() first (a require_once on a
     * missing file is a fatal compile error, not a \Throwable), no glob, no
     * dynamic dispatch.
     *
     * @param SugarBean $quote
     * @param string[] $candidateLineIds Live, NOT-yet-ordered line ids.
     * @return array{line_ids: string[], refuse: ?array, status: string}
     */
    public static function fireResolveOrderableLines($quote, array $candidateLineIds): array
    {
        $identity = ['line_ids' => $candidateLineIds, 'refuse' => null, 'status' => 'hook_absent'];

        $file = 'custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php';
        if (!file_exists($file)) {
            return $identity;
        }

        try {
            require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($file);
            $answer = (new ErpQuoteResolveOrderableLinesHook())->run($quote, $candidateLineIds);
        } catch (\Throwable $e) {
            // A selection layer that THREW has not said "these lines are
            // fine" - it has said nothing. Ordering the lot on the strength
            // of an exception is exactly the guess decision 29 forbids, and
            // it would file irreversible ERP orders. Refuse and say so.
            $GLOBALS['log']->error('ErpQuoteHooks: ResolveOrderableLines failed for quote '
                . $quote->id . ': ' . $e->getMessage());

            return [
                'line_ids' => [],
                'refuse' => [
                    'error' => 'Line selection could not be resolved',
                    'message' => 'The line-selection rules for this quote could not be evaluated, so nothing '
                        . 'was ordered. Try again, and tell an administrator if it keeps happening.',
                ],
                'status' => 'hook_failed',
            ];
        }

        if (!is_array($answer)) {
            return self::resolveContractViolation($quote, 'answer was not an array');
        }

        // TWO SPELLINGS OF A REFUSAL ARE ACCEPTED, and that is deliberate.
        //
        //   ['refuse'  => ['error' => ..., 'message' => ...]]
        //   ['refusal' => '<seller-facing sentence>', 'error' => '<short form>']
        //
        // The second is the shape Partial Fulfillment's line-rollup provider
        // contract already established and that a customer package's planner
        // already answers in. Accepting it means the adapter such a package
        // ships at this hook point is a PASS-THROUGH rather than a translation
        // layer - and a translation layer between two refusal vocabularies is
        // precisely where a refusal quietly becomes an empty list and an empty
        // list quietly becomes "order everything".
        //
        // Neither spelling lets core author the words. It only decides which
        // key to read them out of.
        if (isset($answer['refuse'])) {
            $refusal = $answer['refuse'];
            if (!is_array($refusal) || !isset($refusal['error']) || !isset($refusal['message'])) {
                return self::resolveContractViolation($quote, 'refusal carried no error/message pair');
            }

            return [
                'line_ids' => [],
                // The refusing layer's own words, passed through unchanged -
                // core has no view on why a group is unorderable.
                'refuse' => [
                    'error' => (string) $refusal['error'],
                    'message' => (string) $refusal['message'],
                ],
                'status' => 'hook_refused',
            ];
        }

        if (isset($answer['refusal']) && $answer['refusal'] !== null) {
            if (!is_scalar($answer['refusal']) || (string) $answer['refusal'] === '') {
                return self::resolveContractViolation($quote, 'refusal carried no seller-facing sentence');
            }

            return [
                'line_ids' => [],
                'refuse' => [
                    // 'error' is the short form and is optional; the sentence
                    // is what a seller reads, so it stands in when it is absent
                    // rather than leaving the refusal unnamed.
                    'error' => isset($answer['error']) && is_scalar($answer['error']) && (string) $answer['error'] !== ''
                        ? (string) $answer['error']
                        : (string) $answer['refusal'],
                    'message' => (string) $answer['refusal'],
                ],
                'status' => 'hook_refused',
            ];
        }

        if (!isset($answer['line_ids']) || !is_array($answer['line_ids'])) {
            return self::resolveContractViolation($quote, 'answer carried no line_ids array');
        }

        // Narrowing only. A hook may drop candidates (one rung of a ladder
        // instead of three); it may NOT introduce an id core did not offer,
        // because core's candidate list is what already excludes the locked
        // erp_ordered lines. Letting one back in re-sends a line an ERP
        // order already carries - the BD-05 duplicate.
        $resolved = [];
        foreach ($answer['line_ids'] as $lineId) {
            if (is_scalar($lineId) && in_array((string) $lineId, $candidateLineIds, true)) {
                $resolved[] = (string) $lineId;
            }
        }
        $resolved = array_values(array_unique($resolved));

        if (count($resolved) !== count(array_unique($answer['line_ids']))) {
            return self::resolveContractViolation($quote, 'answer named lines that are not orderable candidates');
        }

        if (count($resolved) === 0) {
            // "Nothing is orderable" is a refusal, and a refusal has to carry
            // a reason a seller can act on. An empty list is not one.
            return self::resolveContractViolation($quote, 'answer narrowed every candidate away without refusing');
        }

        return ['line_ids' => $resolved, 'refuse' => null, 'status' => 'hook_resolved'];
    }

    /**
     * Fired by QuotesErpActionsApi::orderLines() BEFORE any Epicor call, so
     * the layer that ADJUDICATES a quote can refuse an ordering request core
     * is otherwise about to send. Contract:
     * custom/modules/Quotes/ErpQuoteHooks/OrderSelectedLinesPolicy.php defines
     * class ErpOrderSelectedLinesPolicy with a
     * refusal(SugarBean $quote, array $lineIds): ?string method.
     *
     * NULL IS THE ONLY "YES". Null means proceed; a string is the refusal, in
     * the refusing layer's own words. Anything else - an empty string, a
     * non-string, an exception - REFUSES. That asymmetry is deliberate and is
     * the opposite of the usual defensive default: a future bug in an
     * adjudicating package that returns the wrong type must not read as
     * consent, because what is on the other side of this call is an
     * irreversible ERP sales order.
     *
     * WHY THIS EXISTS, AND WHY IT IS NOT fireResolveOrderableLines. That hook
     * serves Submit Order, the WHOLE-QUOTE button, where core offers a
     * candidate set and the selection layer may NARROW it. `Order Selected
     * Lines` is the other button and it is a different question: the seller
     * has already named the lines, one by one, in the grid. Narrowing THAT is
     * not adjudication, it is quietly ordering something other than what was
     * asked for - the same "quiet narrowing" orderLines() already refuses a
     * mixed request over. So this hook can only answer yes or no. Decision
     * 136(c) - "if you dont select the tab nothing goes to the order selected
     * line items" - is a whole-request refusal, never a filter.
     *
     * CORE LEARNS EXACTLY ONE THING: that an adjudicating package may refuse
     * an ordering request. It does not learn what a quantity break is, what a
     * ladder is, what a group is, that exactly one rung is required, or any
     * threshold, minimum or message. Every one of those words lives in the
     * implementing package and in no shared file - that is gate G2, and it is
     * the whole reason this is a seam and not a check.
     *
     * WITH NO IMPLEMENTER, THE ANSWER IS YES. Same reasoning as
     * fireResolveOrderableLines: without the adjudicating package there is no
     * grouping and no narrowing, so a "break group" is not a thing that exists
     * on such a tenant and there is nothing for it to refuse. Note the
     * asymmetry with a package that IS installed and then answers off
     * contract - absent is consent, broken is not.
     *
     * NOT A SECOND REFUSAL SURFACE. The answer is returned through
     * QuotesErpActionsApi::sliceRefused() - the one refusal shape that path
     * already had - and persists nothing.
     *
     * Same scanner-safe shape as the hooks above: one fixed literal path,
     * class and method, file_exists() first (a require_once on a missing file
     * is a fatal compile error, not a \Throwable), no glob, no dynamic
     * dispatch.
     *
     * @param SugarBean $quote
     * @param string[] $lineIds The lines about to be ordered - already
     *                          validated as live and NOT-yet-ordered.
     * @return string|null null to proceed, else the seller-facing refusal.
     */
    public static function fireLineOrderRefusal($quote, array $lineIds): ?string
    {
        $file = 'custom/modules/Quotes/ErpQuoteHooks/OrderSelectedLinesPolicy.php';
        if (!file_exists($file)) {
            return null;
        }

        try {
            require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($file);
            $answer = (new ErpOrderSelectedLinesPolicy())->refusal($quote, $lineIds);
        } catch (\Throwable $e) {
            // An adjudicating layer that THREW has not said "these lines are
            // fine" - it has said nothing. Ordering on the strength of an
            // exception is the guess decision 29 forbids, and it files an
            // irreversible ERP order.
            $GLOBALS['log']->error('ErpQuoteHooks: OrderSelectedLinesPolicy failed for quote '
                . $quote->id . ': ' . $e->getMessage());

            return self::lineOrderContractViolation($quote, 'the rules could not be evaluated');
        }

        if ($answer === null) {
            return null;
        }

        // Everything from here down is a refusal. The only question left is
        // whether the refusing layer supplied words a seller can act on.
        if (!is_string($answer) || $answer === '') {
            $GLOBALS['log']->error('ErpQuoteHooks: OrderSelectedLinesPolicy returned an invalid answer '
                . 'for quote ' . $quote->id . ' (refusal carried no seller-facing sentence)');

            return self::lineOrderContractViolation($quote, 'the rules did not return a usable answer');
        }

        // The refusing layer's own words, passed through unchanged - core has
        // no view on why these lines may not be ordered.
        return $answer;
    }

    /**
     * Core's own words for a policy that answered off-contract, and the ONLY
     * sentence this class authors on this hook point. It describes the
     * FAILURE, not a business rule: core still has no opinion about which
     * lines should have been ordered, only that it was not told.
     */
    private static function lineOrderContractViolation($quote, string $detail): string
    {
        return 'The ordering rules for this quote could not be applied (' . $detail . '), so nothing was '
            . 'ordered. Tell an administrator before trying again.';
    }

    /**
     * A hook that answered off-contract is treated exactly like one that
     * threw: refuse. The message describes the failure, not a business rule -
     * core still has no opinion about which lines should have been chosen.
     *
     * @return array{line_ids: string[], refuse: array, status: string}
     */
    private static function resolveContractViolation($quote, string $detail): array
    {
        $GLOBALS['log']->error('ErpQuoteHooks: ResolveOrderableLines returned an invalid answer for quote '
            . $quote->id . ' (' . $detail . ')');

        return [
            'line_ids' => [],
            'refuse' => [
                'error' => 'Line selection could not be resolved',
                'message' => 'The line-selection rules for this quote did not return a usable answer, so '
                    . 'nothing was ordered. Tell an administrator before trying again.',
            ],
            'status' => 'hook_invalid',
        ];
    }
}
