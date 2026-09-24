<?php

require_once 'custom/modules/Quotes/ErpQuoteLineRollup.php';

/**
 * The writer of partial-fulfillment Opportunity fields, through TWO
 * entry points with deliberately different ownership and different triggers.
 *
 * 1. RELEASE TIME - sales_stage. afterLinesOrdered() is called through
 *    ERP-Epicor's ErpQuoteHooks::fireAfterLinesOrdered() whenever an order
 *    lands or completes a quote - after a release (orderLines()), after a
 *    whole-quote Submit Order (runErpAction(), G346) and after a line delete
 *    completes a Partially Fulfilled quote (ErpReleaseStageSettle, 🔒 1614) -
 *    and NEVER from a logic hook of its own: a change to an opportunity's
 *    STAGE has one named cause. This class is now the ONLY writer of it from
 *    a quote: OrderStageOpportunityCascade, which used to close the pursuit
 *    from order_stage, was retired by the owner (🔒 1413) and registers
 *    nothing. A partial release writes the partial stage; a release that
 *    leaves nothing open writes Closed Won (orderedInFullResolution(), G346).
 *
 * 2. QUOTE-SAVE TIME - erp_ordered_amount / erp_open_amount. rollupHook() IS
 *    an after_save logic hook on Quotes, because these numbers must follow the
 *    quote as it is built rather than wait for a release. That is not a
 *    softening of the rule above, it is a second, narrower question: the hook
 *    writes ONLY the two rollup fields and never sales_stage or amount, so
 *    "one named cause" still holds for every field the rule was written to
 *    protect. Two triggers for two field sets, not two writers of one field.
 *
 * Headline amount is owned by the shared QuoteOpportunityAmount hook. It
 * follows the primary quote before any order exists and without requiring
 * this optional package. Neither entry point here writes amount.
 *
 * WHAT IT WRITES
 *   - A customer package may provide the fixed, neutral release-stage policy
 *     at custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php.
 *     The policy returns plain stage/probability data; this shared class
 *     remains the only writer. An absent/non-applicable policy falls back to
 *     the tenant's generic partial-stage configuration. Invalid or throwing
 *     policy code preserves the current stage instead of guessing.
 *   - 🔒 1468 RETIRED the line-rollup adjudication policy provider
 *     (.../ErpQuoteHooks/OpportunityLineRollupPolicy.php). It could REFUSE a
 *     quote's rollup, and that refusal kept the stored figures. It is no
 *     longer consulted, because erp_open_amount no longer comes from the
 *     rollup it adjudicated: it is the primary's own Grand Total, which
 *     already applies the pin (🔒 29). A tenant still shipping the file is
 *     unaffected in every other respect. See refreshLineRollup().
 *   - Opportunities.erp_rollup_refusal: blank whenever the two figures are
 *     current, and after 🔒 1468 that is every save but one. The one
 *     exception is a primary whose own total is not a number: open is then
 *     held, and this field says so.
 *
 * WHAT IT NEVER DOES
 *   - Create, edit, re-stage or delete a Revenue Line Item. An RLI is the
 *     tenant's forecasting record; a connector that writes them has to know
 *     the tenant's forecasting model, and one that guesses corrupts the
 *     forecast silently. Keyed to the Sugar quote and nothing else.
 *   - Reopen a closed opportunity, or touch one it cannot find.
 *   - Fail the order. Every caller wraps this in try/catch; a stale
 *     forecast is a lesser evil than an ERP order the CRM does not record.
 */
class ErpOpportunityValuation
{
    public const CONFIG_CATEGORY = 'erp_integration';
    public const PARTIAL_STAGE_KEY = 'partial_order_sales_stage';
    public const PARTIAL_PROBABILITY_KEY = 'partial_order_probability';

    /**
     * 🛑 G305 / 🔒 1519 — WHAT A PARTIAL RELEASE DOES TO THE OPPORTUNITY WHEN
     * NOBODY HAS CONFIGURED THIS TENANT. Owner: *"thsi shoudl work the same on
     * core and on bench"*.
     *
     * THE DEFECT. `erp_integration.partial_order_sales_stage` is a CONFIG ROW,
     * and a config row is DATA - it does not arrive with a package. Its only
     * writer in the entire estate was Bench Dogs' post_install (verified in the
     * built artifact sugarai_benchdogs_ext-0.9.42-rc66.zip,
     * scripts/post_install.php:110-128 - write-if-absent, value
     * 'Partial Production Ordered'). THIS class is the only reader. So a seller
     * released part of an order on a stock tenant and the Opportunity did not
     * move: no error, no message, nothing on screen. A textbook 🔒 1514 - not
     * customer-category logic, just a general capability that happened to work
     * only where Bench Dogs was installed.
     *
     * 🛑 WHY THIS EXACT STRING AND NOT A STOCK STAGE NAME. The bar is the same
     * OUTCOME on both tenants, not "core has a default too". A default holding
     * a different value would leave stock and Bench each carrying a row and
     * still behaving differently - a quieter divergence that LOOKS fixed. So it
     * is byte-exact with the value Bench's post_install writes.
     *
     * 🛑 AND IT IS ANSWERABLE ON STOCK, which is the part that is easy to get
     * wrong: the KEY is already this package's own. G278 / 🔒 1506 moved the
     * stage vocabulary here from Bench Dogs ("this hsoudl happen in the core"),
     * so _override_en_us.partial_fulfillment_sales_stage.php:41-45 ships
     * 'Partial Production Ordered' into sales_stage_dom and 90 into
     * sales_probability_dom on EVERY tenant that installs this package. Bench's
     * own fragment was emptied in 0.9.42-rc65 for exactly that reason. Nothing
     * here adds vocabulary; it supplies the row that was never data-shipped.
     *
     * 🛑 NOT WRITTEN INTO THE TENANT'S CONFIG FROM A post_install, AND THE
     * REASON IS BENCH. Bench's writer is write-if-absent. This package installs
     * BEFORE Bench Dogs (Bench depends on it), so a PF post_install would put a
     * value in the row first, Bench's guard would find it non-empty and leave
     * it, and Bench would quietly stop reaching its own stage on any freshly
     * built tenant. A reader-side default writes nothing, so Bench's
     * post_install still lands its value and Bench is untouched - which 🔒 1519
     * makes the control for this gap.
     *
     * 📌 THE PROBABILITY NEEDS NO DEFAULT. configuredPartialProbability()
     * already falls through to sales_probability_dom, where this package ships
     * 90 for this key - the same derivation Bench's post_install comment says
     * it relies on ("the probability is deliberately NOT written").
     *
     * 📌 A BEHAVIOUR CHANGE, STATED. "Never configured" used to mean "off". An
     * administrator who wants a partial release to leave the stage alone now
     * sets this key to an empty string, which is still honoured exactly.
     */
    public const DEFAULT_PARTIAL_STAGE = 'Partial Production Ordered';

    /**
     * G346 — the Opportunity stage a release that leaves nothing open writes
     * when no customer policy decides. A stock key; see
     * orderedInFullResolution() for why this one.
     */
    public const ORDERED_IN_FULL_SALES_STAGE = 'Closed Won';

    public const GROUP_POLICY_KEY = 'quote_group_rollup';

    private const RELEASE_STAGE_POLICY_FILE =
        'custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php';

    /** Where the one remaining refusal is published so a seller can see it. */
    private const REFUSAL_FIELD = 'erp_rollup_refusal';

    /** 🔒 1468's only refusal: the primary's own total is not a number. */
    public const TOTAL_NOT_A_NUMBER = 'primary_total_not_a_number';


    /**
     * THE LADDER THIS LINE BELONGS TO, AS EPICOR STATES IT.
     *
     * A quantity break is not a Sugar concept. In Epicor it is a QuoteQty row
     * hanging off a QuoteDtl, so every rung of one ladder shares that
     * QuoteDtl - and the connector already carries all three identifiers
     * verbatim in the sync key it stamps on each line:
     *
     *     <COMPANY>__<QuoteNum>_<QuoteLine>_<QtyNum>
     *
     * (connector_epicor/normalize.py:620, whose fields are read straight off
     * QuoteQty rows: QtyNum is Epicor's, not an index this estate invented.)
     * So the ladder is the key with the trailing _<QtyNum> removed, and
     * grouping on it is READING what the ERP said rather than inferring it.
     * Proven live on quote 225: EPIC06__9001_1_1 / _2 / _3 / _4, four rungs,
     * one line.
     *
     * 🚩 WHY NOT mft_part_num, WHICH THIS REPLACES. A part number is a guess
     * at the ERP's line identity and is wrong at four edges decision 789
     * tabulated: a line with no part number collapses every such line into
     * one group; two different products sharing a part number are wrongly
     * merged; a free-text line has no part number to group on at all; and
     * editing a part number silently SPLITS a ladder in half, which a pin
     * policy then reads as two groups and happily pins twice. The ERP line
     * has none of those failure modes because it is an identity, not an
     * attribute.
     *
     * 📌 A SUGAR-AUTHORED LINE GETS ITS OWN GROUP, KEYED ON ITS OWN ID. Its
     * erp_sync_key is empty - measured on quote 225's "Installation service"
     * - and returning "" would put every rep-typed line in ONE group, which
     * is precisely the collapse this method exists to prevent. Keying on the
     * bean id rather than the loop index also survives a reorder: an index
     * moves when a line above it is deleted, and a group key that moves is a
     * pin that silently points at a different line.
     *
     * This does NOT change what a ladder VALUES. Decision 29 still refuses to
     * total a ladder until a rung is selected, and 🔒 796 measured that 63 of
     * 65 ladders have no selection - so this is a correctness fix for the
     * edges, not a fix for the zero-valued ladders.
     */
    private static function ladderGroup($product): string
    {
        $key = trim((string) ($product->erp_sync_key ?? ''));
        if ($key !== '') {
            $cut = strrpos($key, '_');

            return $cut === false ? $key : substr($key, 0, $cut);
        }

        // A COPY SHEDS THE KEY, BUT NOT THE LADDER. The key is IDENTITY and
        // ErpCopyShedsSyncKeys clears it correctly; which ladder the rung
        // belongs to is PROVENANCE, and rides `erp_ladder_group`, stamped
        // from the key by that same guard BEFORE it clears it.
        //
        // Without this every copied line fell to 'sugar:'.$id below - unique
        // per line - so no group ever reached the two members
        // countableLineIds() requires. Every rung came back 'counts', the
        // radio was never drawn (the owner: "when it copy it needs to show
        // the breaks") and the 8 alternatives took money: measured on Bench,
        // source ERP Quote 1010 = 101,500 with alternative 8 / counts 3, its
        // copy = 108,150 with counts 11. Decision 927.
        //
        // NOT a part number: decision 789 rules that out at four edges, the
        // worst being that editing one SPLITS a ladder and the pin policy
        // then pins twice.
        $ladder = trim((string) ($product->erp_ladder_group ?? ''));
        if ($ladder !== '') {
            return $ladder;
        }

        return 'sugar:' . (string) ($product->id ?? '');
    }

    /**
     * A name for this line that a seller would recognise on their own screen.
     *
     * Part number first because that is what the grid shows and what estimating
     * quotes against; the line's own name next for a free-text row that has no
     * part number; and only then the machine key, which is better than an empty
     * pair of quotes but is never the intended answer.
     *
     * Never used for grouping or identity - see ladderGroup() for that.
     */
    private static function lineLabel($product): string
    {
        foreach (array('mft_part_num', 'name', 'part_num') as $field) {
            $value = trim((string) ($product->$field ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return self::ladderGroup($product);
    }

    /**
     * WHAT THE ERP SAYS THIS QUOTE'S TAX AND SHIPPING ARE - decision 122.
     *
     * Two fields on Quotes, READ here and WRITTEN BY NOBODY IN THIS PACKAGE.
     * They belong to the connector-facing layer (ERP-Core's vardefs, filled by
     * the quotes sync) for a reason that is not stylistic: this package is
     * OPTIONAL, the connector is not, and a field that exists only where an
     * optional add-on is installed would dead-letter the header write on every
     * tenant without it. PF reads them where they exist and is inert where
     * they do not. See §DEC122-IMPL-1 for the state of that connector work.
     *
     * DOCUMENT CURRENCY, to match the lines. Epicor states both a base and a
     * document figure for every money column (`Tax` vs `DocTax`) and the
     * document one is what was actually quoted; the base one is Epicor's own
     * converted equivalent. The order-line lane learned this the hard way and
     * the quote header normalizer already reads `Doc*` only. Whatever fills
     * these fields must keep doing so, or the addition below mixes currencies
     * inside one sum and `inOpportunityCurrency()` converts the result once,
     * from the wrong base.
     */
    private const ERP_TAX_FIELD = 'erp_tax_amount';
    private const ERP_SHIPPING_FIELD = 'erp_shipping_amount';

    /**
     * An ERP-born quote is one the connector keyed. Hand-built quotes are not
     * in scope for decision 122 at all: there is no ERP statement to source
     * from, and Sugar's own `tax`/`shipping` already flow into the native
     * total the shared amount writer falls back to.
     */
    private const ERP_ORIGIN_FIELD = 'erp_sync_key';

    private const TERMINAL_SALES_STAGES = ['Closed Won', 'Closed Lost'];

    /**
     * @param SugarBean $quote   the quote a release was just raised from,
     *                           re-retrieved after its stage save
     * @param bool      $partial true when open lines remain on the quote
     */
    public function afterLinesOrdered(SugarBean $quote, bool $partial): string
    {
        // Refresh the line rollup from the release explicitly rather than
        // leaning on the Quotes after_save hook to fire in the right order:
        // the release has just stamped erp_ordered onto the lines it carried,
        // and this is the moment those numbers move most.
        $this->rollupHook($quote);

        $opportunity = $this->linkedOpportunity($quote);
        if ($opportunity === null) {
            $GLOBALS['log']->info('ErpOpportunityValuation: quote ' . $quote->id
                . ' has no linked opportunity - nothing to value');
            return 'opportunity_missing';
        }

        if (in_array($opportunity->sales_stage ?? '', self::TERMINAL_SALES_STAGES, true)) {
            $GLOBALS['log']->info('ErpOpportunityValuation: opportunity ' . $opportunity->id . ' is already '
                . $opportunity->sales_stage . ' - not reopening it for quote ' . $quote->id);
            return 'terminal';
        }

        $changed = false;
        $resolution = $this->releaseStageDecision($quote, $partial);
        $decision = $resolution['decision'];

        if ($decision !== null) {
            $stage = $decision['sales_stage'];
            $probability = $decision['probability'];
            if (($opportunity->sales_stage ?? '') !== $stage) {
                $opportunity->sales_stage = $stage;
                // Same reason OrderStageOpportunityCascade sets both: in
                // Revenue Line Items mode a bare sales_stage save is
                // silently dropped; Opportunity::save() persists it only
                // through its non-db sales_stage_cascade field. Harmless in
                // Opportunities mode (cascade() no-ops there).
                $opportunity->sales_stage_cascade = $stage;
                $changed = true;
            }
            if ($probability !== null
                && (int) ($opportunity->probability ?? -1) !== $probability
            ) {
                $opportunity->probability = $probability;
                $changed = true;
            }
        }

        if (!$changed) {
            return $decision === null ? $resolution['status'] : 'unchanged';
        }

        $opportunity->save();

        $GLOBALS['log']->info('ErpOpportunityValuation: opportunity ' . $opportunity->id . ' valued from quote '
            . $quote->id . ' (' . ($partial ? 'partial release' : 'final release') . '): sales_stage='
            . var_export($opportunity->sales_stage ?? null, true) . ' amount='
            . var_export($opportunity->amount ?? null, true));

        return 'updated';
    }

    /**
     * Resolve release-stage data without giving the customer policy write
     * access. The literal path/class/method calls are intentionally verbose:
     * SugarCloud's scanner rejects dynamic dispatch in installable packages.
     *
     * Provider contract:
     *   class ErpOpportunityReleaseStagePolicy
     *   resolve(SugarBean $quote): ?array
     *   ['sales_stage' => <sales_stage_dom key>, 'probability' => 0..100]
     *
     * A missing provider or null result means "use generic configuration".
     * An invalid result or exception means "preserve", never fallback.
     *
     * @return array{
     *   decision: array{sales_stage: string, probability: ?int}|null,
     *   status: string
     * }
     */
    private function releaseStageDecision(SugarBean $quote, bool $partial): array
    {
        $providerPresent = file_exists(self::RELEASE_STAGE_POLICY_FILE);
        $providerResult = null;
        $providerStatus = 'policy_provider_absent';

        if ($providerPresent) {
            try {
                require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath(
                    self::RELEASE_STAGE_POLICY_FILE
                );
                if (!class_exists('ErpOpportunityReleaseStagePolicy', false)) {
                    $GLOBALS['log']->warn('ErpOpportunityValuation: release-stage policy file does not define '
                        . 'ErpOpportunityReleaseStagePolicy - preserving the current Opportunity stage');
                    return self::preservedResolution('policy_provider_invalid');
                }
                $providerResult = (new ErpOpportunityReleaseStagePolicy())->resolve($quote);
            } catch (\Throwable $e) {
                $GLOBALS['log']->error('ErpOpportunityValuation: release-stage policy failed for quote '
                    . $quote->id . ' - preserving the current Opportunity stage: ' . $e->getMessage());
                return self::preservedResolution('policy_provider_exception');
            }

            if ($providerResult !== null) {
                return $this->validatedReleaseStageDecision($providerResult, $quote);
            }
            $providerStatus = 'policy_provider_null';
        }

        if (!$partial) {
            return self::orderedInFullResolution($providerStatus, $quote);
        }

        $configured = self::configuredPartialStageResolution();
        $stage = $configured['stage'];
        if ($stage === '') {
            $GLOBALS['log']->info('ErpOpportunityValuation: no valid ' . self::CONFIG_CATEGORY . '.'
                . self::PARTIAL_STAGE_KEY . ' configured - quote ' . $quote->id
                . ' keeps the current Opportunity stage');
            if ($configured['reason'] === 'invalid') {
                return self::preservedResolution(
                    $providerStatus === 'policy_provider_null'
                        ? 'policy_provider_null_config_invalid'
                        : 'policy_provider_absent_config_invalid'
                );
            }
            return self::preservedResolution(
                $providerStatus === 'policy_provider_null'
                    ? 'policy_provider_null_config_missing'
                    : 'policy_provider_absent_config_missing'
            );
        }

        return [
            'decision' => [
                'sales_stage' => $stage,
                'probability' => self::configuredPartialProbability($stage),
            ],
            'status' => 'policy_generic_config_applied',
        ];
    }

    /**
     * @param mixed $decision
     * @return array{
     *   decision: array{sales_stage: string, probability: int}|null,
     *   status: string
     * }
     */
    private function validatedReleaseStageDecision($decision, SugarBean $quote): array
    {
        if (!is_array($decision)
            || !array_key_exists('sales_stage', $decision)
            || !array_key_exists('probability', $decision)
        ) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: release-stage policy returned an invalid shape for quote '
                . $quote->id . ' - preserving the current Opportunity stage');
            return self::preservedResolution('policy_invalid_shape');
        }

        $stage = is_string($decision['sales_stage']) ? trim($decision['sales_stage']) : '';
        $appListStrings = self::currentAppListStrings();
        $dom = $appListStrings['sales_stage_dom'] ?? array();
        if ($stage === '' || !is_array($dom) || !array_key_exists($stage, $dom)) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: release-stage policy returned unknown stage '
                . var_export($decision['sales_stage'], true) . ' for quote ' . $quote->id
                . ' - preserving the current Opportunity stage');
            return self::preservedResolution('policy_invalid_stage');
        }

        $probability = $decision['probability'];
        if (!is_int($probability) || $probability < 0 || $probability > 100) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: release-stage policy returned invalid probability '
                . var_export($probability, true) . ' for quote ' . $quote->id
                . ' - preserving the current Opportunity stage');
            return self::preservedResolution('policy_invalid_probability');
        }

        return [
            'decision' => ['sales_stage' => $stage, 'probability' => $probability],
            'status' => 'policy_valid',
        ];
    }

    /**
     * 🛑 G346 — A RELEASE THAT LEAVES NOTHING OPEN (the quote is Closed
     * Accepted, shown "Closed Won", ordered in full - G401 / 🔒 1701b) moves the
     * Opportunity to Closed Won when no customer policy decided otherwise. This
     * class reads $partial, never the quote's stage key, so G401 changes
     * nothing here.
     *
     * THE DEFECT. This branch used to PRESERVE the stage on every final
     * release, on the theory that "order lifecycle closes the pursuit" - i.e.
     * OrderStageOpportunityCascade mapping order_stage 'Commitment Final' to
     * 'Closed Won'. The owner retired that cascade (🔒 1413: order_stage was
     * seller-editable, "we dont need the logic"), and nothing took its place.
     * So a partial release moved the Opportunity to Partial Production
     * Ordered 90% and completing the SAME quote left it where it was:
     * measured on stock #949, Prospecting 10% after the whole quote was
     * ordered. 🔒 1614 (owner) presumes the opposite - a line delete that
     * completes the quote moves the Opportunity "exactly as when an order
     * completes the quote".
     *
     * WHY 'Closed Won', AND WHY IT IS NOT A NEW KEY:
     *   * it is the only won stage in ERP-Core's owned Opportunity list
     *     (sales_stage_dom.replace.php: Prospecting, Proposal-Quoting,
     *     Closed Won, Closed Lost) and a stock Sugar key;
     *   * it is the partial stage's own counterpart: this package's
     *     _override_en_us.partial_fulfillment_sales_stage.php keeps Partial
     *     Production Ordered OPEN precisely because "the remainder of the deal
     *     stays open pipeline" - with no remainder there is nothing open, and
     *     90% would keep a fully-ordered deal in the pipeline (G74's complaint);
     *   * it is the target the retired cascade already mapped a completed
     *     order to ('Commitment Final' => 'Closed Won'), and the one
     *     ERP-Core's OpportunityCloseAmount (decisions 140/176) values at the
     *     ordered total when the Opportunity reaches it.
     * NOT the 1413 cascade coming back: that read a stage a seller could type.
     * This is reached only after an ERP order exists and nothing is left open.
     *
     * Probability: what sales_probability_dom says for the key (100 on stock),
     * never the partial_order_probability setting, which is a PARTIAL figure.
     *
     * A CUSTOMER POLICY STILL WINS - it returned before this is reached (a
     * valid decision) or preserved (an invalid one). This runs only when the
     * provider is absent or returned null, the same two cases the partial
     * fallback serves.
     *
     * A tenant that removed 'Closed Won' from sales_stage_dom is preserved and
     * logged, never written an unknown key - the same refusal a mistyped
     * partial stage gets, reported through the same two status strings so
     * ERP-Epicor's allowlist (ErpQuoteHooks::fireAfterLinesOrdered) needs no
     * new entry.
     *
     * @return array{
     *   decision: array{sales_stage: string, probability: ?int}|null,
     *   status: string
     * }
     */
    private static function orderedInFullResolution(string $providerStatus, SugarBean $quote): array
    {
        $stage = self::ORDERED_IN_FULL_SALES_STAGE;
        $appListStrings = self::currentAppListStrings();
        $dom = $appListStrings['sales_stage_dom'] ?? array();
        if (!is_array($dom) || !array_key_exists($stage, $dom)) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: quote ' . $quote->id . ' was ordered in full, but '
                . var_export($stage, true) . ' is not a sales_stage_dom key on this instance - the '
                . 'Opportunity keeps its current stage (G346)');
            return self::preservedResolution(
                $providerStatus === 'policy_provider_null'
                    ? 'policy_provider_null_config_invalid'
                    : 'policy_provider_absent_config_invalid'
            );
        }

        $probabilities = $appListStrings['sales_probability_dom'] ?? array();
        $probability = is_array($probabilities) && isset($probabilities[$stage]) && is_numeric($probabilities[$stage])
            ? max(0, min(100, (int) $probabilities[$stage]))
            : null;

        return [
            'decision' => ['sales_stage' => $stage, 'probability' => $probability],
            'status' => 'policy_generic_ordered_in_full_applied',
        ];
    }

    /**
     * @return array{decision: null, status: string}
     */
    private static function preservedResolution(string $status): array
    {
        return ['decision' => null, 'status' => $status];
    }

    /**
     * The tenant's partial-order opportunity stage, '' when none is
     * configured or the configured key is not in sales_stage_dom (a typo in
     * an admin setting must not write an unknown stage into the forecast).
     */
    public static function configuredPartialStage(): string
    {
        return self::configuredPartialStageResolution()['stage'];
    }

    /**
     * Keep the public stage-only helper compatible while preserving the
     * missing/invalid distinction for the neutral release diagnostic. Both
     * refusal reasons retain the existing no-write behavior.
     *
     * @return array{stage: string, reason: string}
     */
    private static function configuredPartialStageResolution(): array
    {
        $config = self::erpIntegrationConfig();

        // 🛑 G305 / 🔒 1519 — ABSENT AND BLANK ARE DIFFERENT ANSWERS, AND THAT
        // DISTINCTION IS THE WHOLE FIX.
        //
        // ABSENT means nobody ever configured this tenant, which until now
        // meant "the Opportunity silently does not move". BLANK ('' or '  ')
        // means an administrator wrote the setting and left it empty, which is
        // them saying "do not move the stage here". Reading both through one
        // trim() collapsed the two, and the first of them was the defect.
        $configured = array_key_exists(self::PARTIAL_STAGE_KEY, $config);
        $stage = trim((string) ($config[self::PARTIAL_STAGE_KEY] ?? ''));

        if ($configured && $stage === '') {
            // An explicit "leave it alone". Unchanged behaviour, deliberately.
            return ['stage' => '', 'reason' => 'missing'];
        }

        $usingDefault = false;
        if (!$configured) {
            $stage = self::DEFAULT_PARTIAL_STAGE;
            $usingDefault = true;
        }

        $appListStrings = self::currentAppListStrings();
        $dom = $appListStrings['sales_stage_dom'] ?? array();
        if (!is_array($dom) || !array_key_exists($stage, $dom)) {
            // A DEFAULT IS NOT A LICENCE TO WRITE AN UNKNOWN STAGE. A tenant
            // that has customised sales_stage_dom out from under this package
            // gets the same refusal a typo gets: preserve, and say so.
            $GLOBALS['log']->warn('ErpOpportunityValuation: ' . self::CONFIG_CATEGORY . '.'
                . self::PARTIAL_STAGE_KEY . ' is ' . var_export($stage, true)
                . ($usingDefault ? ' (this package\'s shipped default)' : '')
                . ' which is not a sales_stage_dom key - ignoring it');
            return ['stage' => '', 'reason' => 'invalid'];
        }

        if ($usingDefault) {
            // 🚩 NOT SILENT. G305 was found only because two tenants behaved
            // differently and neither of them said anything. A tenant taking
            // the default is told which stage it took and how to change it.
            $GLOBALS['log']->info('ErpOpportunityValuation: ' . self::CONFIG_CATEGORY . '.'
                . self::PARTIAL_STAGE_KEY . ' is not configured on this instance - using this '
                . 'package\'s shipped default ' . var_export($stage, true)
                . '. Set ' . self::CONFIG_CATEGORY . '.' . self::PARTIAL_STAGE_KEY
                . ' to another sales_stage_dom key to change it, or to an empty string to '
                . 'preserve the Opportunity stage on a partial release.');

            return ['stage' => $stage, 'reason' => 'default'];
        }

        return ['stage' => $stage, 'reason' => 'valid'];
    }

    /**
     * Probability for the partial stage: the explicit setting if present,
     * else what sales_probability_dom says for that stage (the same
     * stage->probability mapping Sugar Logic applies in the UI, which a
     * server-side bean save does not run), else null (leave it alone).
     */
    public static function configuredPartialProbability(string $stage): ?int
    {
        $config = self::erpIntegrationConfig();
        $explicit = $config[self::PARTIAL_PROBABILITY_KEY] ?? null;
        if ($explicit !== null && $explicit !== '' && is_numeric($explicit)) {
            return max(0, min(100, (int) $explicit));
        }

        $appListStrings = self::currentAppListStrings();
        $dom = $appListStrings['sales_probability_dom'] ?? array();
        if (is_array($dom) && isset($dom[$stage]) && is_numeric($dom[$stage])) {
            return (int) $dom[$stage];
        }

        return null;
    }

    /**
     * Application dropdowns for the request user's current language.
     *
     * ServiceBase normally populates the global copy during REST bootstrap,
     * but a package repair can rebuild the canonical language extension while
     * a worker still carries an older request-global array. Release policy is
     * a validation boundary, so read the canonical files without language
     * cache before deciding that another package's stage key is unknown.
     * Loading failure remains fail-safe: use the already bootstrapped copy and
     * let the existing strict key validation preserve the current stage when
     * neither source contains the key.
     */
    private static function currentAppListStrings(): array
    {
        $fallback = $GLOBALS['app_list_strings'] ?? array();
        if (!is_array($fallback)) {
            $fallback = array();
        }
        if (!function_exists('return_app_list_strings_language')) {
            return $fallback;
        }

        $language = $GLOBALS['current_language']
            ?? ($GLOBALS['sugar_config']['default_language'] ?? 'en_us');
        $language = is_string($language) && trim($language) !== ''
            ? trim($language)
            : 'en_us';

        try {
            $loaded = return_app_list_strings_language($language, false);
            if (is_array($loaded)) {
                return $loaded;
            }
        } catch (\Throwable $e) {
            // Validation below still fails closed against the request copy.
        }

        $GLOBALS['log']->warn('ErpOpportunityValuation: current-language dropdowns could not be refreshed; '
            . 'validating release stage against the request copy');
        return $fallback;
    }

    private static function erpIntegrationConfig(): array
    {
        $admin = BeanFactory::getBean('Administration');
        $config = $admin->getConfigForModule(self::CONFIG_CATEGORY);

        return is_array($config) ? $config : array();
    }

    /**
     * The Opportunity this quote is linked to (quotes_opportunities), or
     * null. Quotes.opportunity_id is a non-db relate field, so this goes
     * through the relationship - same as the sibling hooks.
     */
    private function linkedOpportunity(SugarBean $quote): ?SugarBean
    {
        $quote->load_relationship('opportunities');
        if (!$quote->opportunities || !is_object($quote->opportunities)) {
            return null;
        }

        $ids = $quote->opportunities->get();
        if (!is_array($ids) || count($ids) === 0) {
            $GLOBALS['log']->info('ErpOpportunityValuation: quote ' . $quote->id
                . ' opportunities relationship returned no ids');
            return null;
        }

        if (count($ids) !== 1) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: quote ' . $quote->id
                . ' has an ambiguous opportunities relationship (' . count($ids) . ' ids) - preserving stage');
            return null;
        }

        // Link2::get() callers must not assume numeric keys. Relationship
        // implementations can return the same id set keyed by record id;
        // reset() consumes the value in either representation. The provider
        // sits after this lookup, so `$ids[0]` silently prevented any customer
        // policy from running when an associative shape was returned.
        $id = (string) reset($ids);
        if ($id === '') {
            $GLOBALS['log']->warn('ErpOpportunityValuation: quote ' . $quote->id
                . ' opportunities relationship returned an empty id - preserving stage');
            return null;
        }

        $opportunity = BeanFactory::retrieveBean('Opportunities', $id, array('use_cache' => false));

        if (!$opportunity) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: quote ' . $quote->id
                . ' linked opportunity could not be retrieved - preserving stage');
        }

        return $opportunity ?: null;
    }

    /**
     * after_save on Quotes. Keeps the opportunity's ERP rollup fields current
     * as the quote is BUILT, not only when a release is raised - a rep adding,
     * repricing or removing a line sees the opportunity follow.
     *
     * Never throws. A forecast field is not worth failing a quote save over,
     * and a rep who cannot save their quote has a much worse problem than a
     * stale number.
     *
     * No recursion risk: this saves the Opportunity, never the Quote.
     *
     * @param SugarBean $bean
     * @param string    $event
     * @param array     $arguments
     */
    public function rollupHook($bean, $event = '', $arguments = array()): void
    {
        if (!($bean instanceof SugarBean) || empty($bean->id) || !empty($bean->deleted)) {
            return;
        }

        try {
            $this->refreshLineRollup($bean);
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('ErpOpportunityValuation::rollupHook failed for quote '
                . $bean->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Publish this primary quote onto its Opportunity as two numbers: what the
     * customer has committed to (erp_ordered_amount, summed over the lines the
     * ERP already carries as ordered) and what is still winnable
     * (erp_open_amount).
     *
     * 🔒 1468, THE RULE, IN THE OWNER'S WORDS: "always take the total form
     * priamry even if Epicor di dnot procide tax or shipping yet, what ever the
     * user mark as paimry take the total from there".
     *
     *   erp_ordered_amount = 🔒 1632 (G336): the primary's own Grand Total
     *                        (Quotes.total) x the ORDERED SHARE of its lines
     *                        (ErpQuoteLineRollup's `ordered` / Quotes.new_sub),
     *                        see splitGrandTotal(). Before 1632 it was the plain
     *                        ordered-line sum, and Open = total - that sum put
     *                        the whole header net (ERP tax - order discount) on
     *                        Open: -38.13 on #376, Ordered above Likely.
     *   erp_open_amount    = the Grand Total less that Ordered figure,
     *                        both in the Opportunity's currency, on EVERY save.
     * So ordered + open equals the quote's Grand Total, which is what the seller
     * sees on the quote and what the headline `amount` falls back to - and now
     * neither is below 0 or above it.
     *
     * WHAT 🔒 1468 RETIRED, AND WHY NOTHING A GUARD PROTECTED IS LOST.
     * erp_open_amount used to be the ROLLUP's open figure (compute() under the
     * configured `sum`, plus the ERP-stated header charge). Around it stood:
     *   - the pin arithmetic and the `sum` policy. The Grand Total already
     *     applies the pin: ErpGoverningTotal lets an unpinned group contribute
     *     nothing (🔒 29), and an 'alternative' rung extends to 0.00. `sum`
     *     did not do that. On quote 285 it counted a keyless 50 x 85.91 twin
     *     that the Grand Total zeroes, and read 11,862.82 against a total of
     *     8,184.54;
     *   - decision 122's ERP-sourced charge addition, and its two refusals
     *     (`erp_charges_unstated`, `erp_charges_not_apportionable`). The Grand
     *     Total already carries the ERP's stated tax and freight
     *     (erp_total_carries_erp_tax.php, 🔒 1450), and falls back to Sugar's
     *     own until Epicor states them. 🔒 1468 accepts that fallback in
     *     so many words ("even if Epicor did not provide tax or shipping
     *     yet");
     *   - the customer line-rollup policy provider and its refusal. It
     *     adjudicated a rollup that no longer produces this figure;
     *   - PRESERVED, NOT ZEROED on every refusal. That kept, live on Bench
     *     (G214, 2026-09-21), quote 273's 25,100.00 on an Opportunity whose
     *     primary had become quote 285 (8,184.54). The figure was a Closed Lost,
     *     non-primary quote's, and was shown as open pipeline.
     *
     * THE ONE REFUSAL LEFT: A PRIMARY WHOSE OWN TOTAL IS NOT A NUMBER. There is
     * nothing to take, and inventing one would be decision 59's fabricated
     * zero. erp_open_amount is PRESERVED and erp_rollup_refusal says why. With
     * no total there is nothing to split, so the ordered FACT (the plain
     * ordered-line sum) is written, as before 1632.
     *
     * DELIBERATELY DOES NOT TOUCH amount OR sales_stage. The shared
     * QuoteOpportunityAmount hook owns headline valuation; afterLinesOrdered
     * owns stage transitions (OrderStageOpportunityCascade is retired, 🔒 1413).
     *
     * WHICH QUOTE DRIVES IT: only the primary, and on every one of its saves.
     * A hand-over therefore needs no detection. The first save of the newly
     * primary quote publishes its own figures, and a non-primary revision
     * never writes. fetched_row is not consulted.
     *
     * COMPARE BEFORE WRITING. This runs under a Quotes after_save that fires
     * on every save. The Opportunity is saved only when a figure or the reason
     * actually changes, so an unchanged re-save adds no date_modified bump and
     * no audit row.
     */
    public function refreshLineRollup(SugarBean $quote): void
    {
        if (empty($quote->erp_is_primary_quote)) {
            return;
        }
        $opportunity = $this->linkedOpportunity($quote);
        if ($opportunity === null) {
            return;
        }

        // Read fresh (quoteLines() makes Link2 drop the beans an earlier walk
        // in this request materialised) so a release that has just stamped
        // erp_ordered is seen. The lines now serve ONE purpose: the ordered
        // fact, by the same compute() path as before.
        $lines = $this->quoteLines($quote);
        $rollup = ErpQuoteLineRollup::compute($lines, self::configuredGroupPolicy());

        $total = $quote->total ?? null;
        $open = null;
        // 🔒 1697: Ordered (Won) comes from orderedFigure(), the SAME function
        // the close contribution (ErpQuoteHooks/OpportunityCloseContribution
        // .php) answers from, so the Opportunity closes at exactly this figure.
        $ordered = $this->inOpportunityCurrency(
            self::orderedFigure($total, (float) $rollup['ordered'], $quote->new_sub ?? null),
            $quote,
            $opportunity
        );
        if ($ordered === null) {
            return;
        }
        if (self::isStatedTotal($total)) {
            // 🔒 1632 (G336): the Grand Total is SPLIT by the ordered share of
            // the lines it was built from, so the header net (ERP tax, freight,
            // order-level discount) is apportioned instead of landing whole on
            // Open. Converted once each and subtracted, so the two figures sum
            // to the converted total to the cent.
            $split = self::splitGrandTotal((float) $total, (float) $rollup['ordered'], $quote->new_sub ?? null);
            $grand = $this->inOpportunityCurrency($split['ordered'] + $split['open'], $quote, $opportunity);
            if ($grand === null) {
                return;
            }
            $open = max(0.0, round($grand - $ordered, 2));
        }
        // else: no Grand Total to split. orderedFigure() answered with the
        // ordered FACT (the plain line sum), exactly as before 1632, and Open
        // is preserved below.

        $changed = false;
        if ((float) ($opportunity->erp_ordered_amount ?? 0) !== $ordered) {
            $opportunity->erp_ordered_amount = $ordered;
            $changed = true;
        }

        // The reason this Opportunity's open figure is held, or '' when it is
        // current. Derived from THIS save, never remembered from an earlier one.
        $reason = $open === null ? self::TOTAL_NOT_A_NUMBER : '';
        if ($this->refusalStorable($opportunity)
            && (string) ($opportunity->erp_rollup_refusal ?? '') !== $reason
        ) {
            $opportunity->erp_rollup_refusal = $reason;
            $changed = true;
        }

        if ($open === null) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: primary quote ' . $quote->id
                . ' has no numeric total (' . var_export($total, true) . ') - preserving erp_open_amount '
                . var_export($opportunity->erp_open_amount ?? null, true)
                . ' rather than publishing a value nothing has stated');
        } elseif ((float) ($opportunity->erp_open_amount ?? 0) !== $open) {
            $opportunity->erp_open_amount = $open;
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $opportunity->save();

        $GLOBALS['log']->info('ErpOpportunityValuation: opportunity ' . $opportunity->id
            . ' valued from primary quote ' . $quote->id . ' per decisions 1468/1632: ordered=' . $ordered
            . ' open=' . ($open === null ? 'held' : $open) . ' (total ' . var_export($total, true)
            . ' split by ordered lines ' . (float) $rollup['ordered'] . ' of ' . var_export($quote->new_sub ?? null, true)
            . ')');
    }

    /**
     * 🔒 1632 (G336) — THE GRAND TOTAL, SPLIT BY THE ORDERED SHARE OF ITS LINES.
     *
     * MEASURED (test lane a02e1d6d, Ophir, Opportunity e5018b80 / quote #376):
     * total 1024.446 = lines 1062.58 + ERP tax 17.90 - order-level discount
     * 56.034, every line ordered. The old rule, Open = total - ordered lines,
     * put the whole header net on Open: -38.13, beside an Ordered of 1062.58
     * that was MORE than the Opportunity's Likely. Ophir had six such.
     *
     * Owner, yes/no (🔒 1632): split the Grand Total across the lines by ordered
     * share, so Ordered (Won) + Open (Likely) = Likely, neither below 0 nor
     * above Likely. Accepted cost: Ordered no longer equals the raw ordered-line
     * sum (on #376 it reads 1024.45, not 1062.58).
     *
     *   share   = ordered lines / the lines the total was built from, in [0, 1]
     *   Ordered = max(total, 0) x share
     *   Open    = max(total, 0) - Ordered
     *
     * THE DENOMINATOR IS THE QUOTE'S OWN new_sub - the line roll-up the total
     * formula adds the header net to (erp_total_carries_erp_tax.php), so the
     * share and the total describe the same lines, pins and alternative rungs
     * included (🔒 1468's quote 285: the `sum` rollup counts a twin the total
     * zeroes, so the rollup's own total is NOT a fallback). When new_sub is
     * not stated the Grand Total itself is the denominator: the header net is
     * then treated as nil, which is the pre-1632 split held inside [0,
     * Likely]. A share above 1 (an ordered line the total does not
     * count) is held at 1; with no lines worth anything, the share is 1 if
     * anything is ordered and 0 if not. A total below zero has nothing to
     * split: both figures are 0.00.
     *
     * Pure: no Sugar, so the suite executes it.
     *
     * @param float $grandTotal   the primary quote's Grand Total (Quotes.total)
     * @param float $orderedLines the ordered lines' value (ErpQuoteLineRollup `ordered`)
     * @param mixed $linesTotal   Quotes.new_sub, as the bean holds it
     * @return array{ordered: float, open: float, share: float} in the quote's currency, unrounded
     */
    public static function splitGrandTotal(float $grandTotal, float $orderedLines, $linesTotal): array
    {
        $grand = max(0.0, $grandTotal);
        if (is_string($linesTotal)) {
            $linesTotal = trim($linesTotal);
        }
        $lines = (!is_bool($linesTotal) && is_numeric($linesTotal)) ? (float) $linesTotal : $grandTotal;

        if ($lines > 0.005) {
            $share = $orderedLines / $lines;
        } else {
            $share = $orderedLines > 0.005 ? 1.0 : 0.0;
        }
        $share = max(0.0, min(1.0, $share));
        $ordered = $grand * $share;

        return array('ordered' => $ordered, 'open' => $grand - $ordered, 'share' => $share);
    }

    /**
     * 🔒 1697 (owner, 2026-09-23, G389) — THE ONE ORDERED FIGURE.
     *
     * Ordered (Won) (refreshLineRollup, above) and the Opportunity's CLOSE
     * amount (ErpQuoteHooks/OpportunityCloseContribution.php, read by
     * ERP-Core's OpportunityCloseAmount) both take this, so at close Likely ==
     * Ordered (Won).
     *
     * MEASURED BEFORE IT (stock SMOKE-1, #1020, opp b7e1658c): a partial order
     * (11719) and the remainder (11720) closed the Opportunity at Likely
     * 811.89 = ordered lines 693.64 + ERP ORDER tax 118.25 (🔒 149's basis),
     * beside Ordered (Won) 799.10 = the Grand Total with the ERP QUOTE tax
     * 105.46. Owner: the close uses the Ordered (Won) figure; this supersedes
     * 🔒 149's order-tax close basis.
     *
     *   Grand Total stated -> splitGrandTotal()'s ordered share (🔒 1632).
     *   Not stated         -> the ordered lines as they are - the ordered
     *                         FACT, as before 1632. Never an invented total.
     *
     * Pure: no Sugar, so the suite executes it.
     *
     * @param mixed $grandTotal   Quotes.total, as the bean holds it
     * @param float $orderedLines the ordered lines' value (ErpQuoteLineRollup `ordered`)
     * @param mixed $linesTotal   Quotes.new_sub, as the bean holds it
     * @return float in the quote's currency, unrounded
     */
    public static function orderedFigure($grandTotal, float $orderedLines, $linesTotal): float
    {
        if (!self::isStatedTotal($grandTotal)) {
            return $orderedLines;
        }

        return self::splitGrandTotal((float) $grandTotal, $orderedLines, $linesTotal)['ordered'];
    }

    /**
     * Is this Quotes.total a number to split? A numeric STRING is - Sugar
     * hands a decimal column back as one ('799.100000'). A bool, null, '' or
     * any other text is not. The same test refreshLineRollup applied inline
     * before 🔒 1697 gave it a name.
     *
     * @param mixed $total
     */
    private static function isStatedTotal($total): bool
    {
        return !is_bool($total) && is_numeric($total);
    }

    /**
     * This quote's line items in the shape ErpQuoteLineRollup expects.
     *
     * THE GROUP IS EPICOR'S OWN QUOTE LINE, read out of erp_sync_key - see
     * ladderGroup() below. It used to be mft_part_num, which was a GUESS at
     * the thing Epicor already states (decision 789).
     *
     * discount_price, not list price: the same field the quote's own totals
     * are summed from elsewhere in this package. AND the line's own discount
     * beside it - that sentence used to end at "summed from", which was only
     * three quarters true and is what G89 turned out to be: Sugar's totals sum
     * discount_price AND THEN SUBTRACT the line's concession, and this mapping
     * carried the first half only.
     *
     * erp_governing is read on EVERY line, ordered ones included. The rollup
     * needs to know that a group's chosen line is the one that was released,
     * not merely that the group has an ordered line in it; see
     * ErpQuoteLineRollup::compute(). A line whose bean has no erp_governing
     * field at all reads as false, so this keeps working on an instance where
     * the vardef has not been rebuilt yet.
     */
    public static function quoteLines(SugarBean $quote): array
    {
        $lines = array();

        $quote->load_relationship('product_bundles');
        if (!$quote->product_bundles || !is_object($quote->product_bundles)) {
            return $lines;
        }

        // READ THE LINES AS THEY ARE NOW, NOT AS SOMETHING EARLIER IN THIS
        // REQUEST HAPPENED TO SEE THEM. This is the read the whole governing
        // pin rests on: a seller ticks a line and the opportunity must follow.
        //
        // Link2 caches twice over, and getBeans() with no arguments hits both
        // caches. Its own rows are only re-queried when it has not loaded; its
        // $beans array is returned verbatim once beansAreLoaded(), so a bundle
        // walked earlier in the same request hands back the SAME Product
        // objects - carrying the values they had before the toggle was saved.
        // Passing params is not a fix either: with a non-empty $params the row
        // query re-runs but an already-cached bean is still reused.
        //
        // load() is the only call that clears both: it re-queries the rows and
        // nulls the bean array, so the getBeans() below takes the branch that
        // actually retrieves, and use_cache => false stops BeanFactory
        // answering that retrieve from ITS cache in turn. Verified against
        // SugarEnt 26.1.0 data/Link2.php and data/BeanFactory.php.
        //
        // linkedOpportunity() already forces use_cache => false 125 lines
        // above for the same reason. This is the read a seller's own toggle
        // flows through, so it is the one that could least afford to be the
        // cached one.
        //
        // NOT A CLAIM THAT G4 IS MET. Whether the quote grid saves a pin as
        // PUT /Products/{id} or as a Quotes write with nested
        // product_bundle_items - and therefore whether this hook runs after
        // the toggle is persisted at all - needs a tenant to settle and is
        // still owed. This removes one of the two ways the number could be
        // stale, not both.
        $quote->product_bundles->load();

        foreach ($quote->product_bundles->getBeans([], array('use_cache' => false)) as $bundle) {
            if (!empty($bundle->deleted) || !$bundle->load_relationship('products')) {
                continue;
            }
            if (!$bundle->products || !is_object($bundle->products)) {
                continue;
            }
            $bundle->products->load();
            foreach ($bundle->products->getBeans([], array('use_cache' => false)) as $product) {
                if (!empty($product->deleted)) {
                    continue;
                }
                $lines[] = array(
                    'group' => self::ladderGroup($product),
                    // WHAT TO CALL THIS LINE WHEN A HUMAN HAS TO READ IT.
                    //
                    // Deliberately NOT 'group'. The group key used to be
                    // mft_part_num, so anything showing it to a seller showed a
                    // part number by accident; `ab6ab11` moved grouping onto
                    // Epicor's quote line and those same messages silently
                    // started printing `EPIC06__1193_1` -- or, for a rep-typed
                    // row with no sync key, `sugar:<uuid>`. Measured in the
                    // delete guard's own refusal: 'line "sugar:quote-1" is
                    // already on an ERP order', which names nothing a seller
                    // can find on their screen.
                    //
                    // An identity and a label are different jobs. 'group'
                    // decides what is an alternative to what and must stay
                    // machine-stable; this one only has to be recognisable.
                    'label' => self::lineLabel($product),
                    'quantity' => (float) ($product->quantity ?? 0),
                    'price' => (float) ($product->discount_price ?? 0),
                    // THE CONCESSION THE SELLER GAVE ON THIS LINE, in document
                    // currency. Without it the rollup sums the GROSS line and
                    // the opportunity reads HIGHER than the Grand Total printed
                    // on the quote - G89, measured live on quote 273 as
                    // erp_open_amount 25,100.00 beside Quotes.total 24,100.00.
                    // Sugar's percent/flat flag is resolved in lineDiscount();
                    // ErpQuoteLineRollup only ever sees money.
                    'discount' => self::lineDiscount($product),
                    'ordered' => !empty($product->erp_ordered),
                    // WHICH ERP order took this line, as an identity. The close
                    // contribution used it to look up each order's own tax
                    // until 🔒 1697 moved the close onto the Ordered (Won)
                    // figure; no money path reads it now. Kept because it is
                    // part of the line shape other readers receive.
                    'order_num' => trim((string) ($product->erp_ordered_order_num ?? '')),
                    // THIS LINE'S OWN ERP-STATED CHARGES. null means the ERP has
                    // not stated one for this line, which is NOT the same as a
                    // stated 0.00 - only the second is a measurement. Kept as
                    // null rather than coalesced so the sum below can tell
                    // "nobody stated anything" from "everyone stated zero".
                    'tax' => self::statedCharge($product->erp_tax_amount ?? null),
                    'shipping' => self::statedCharge($product->erp_shipping_amount ?? null),
                    'governing' => !empty($product->erp_governing),
                    // Where the line came from, which decides what it can be
                    // an ALTERNATIVE to. erp_sync_key is set by the ERP quote
                    // lane on every line it delivers and by nothing else, so
                    // its presence separates a priced quantity break from a
                    // row a rep typed into the grid. Both still count toward
                    // the open value; they simply never collapse into each
                    // other. See ErpQuoteLineRollup::pinGroupKey().
                    'origin' => empty($product->erp_sync_key) ? 'sugar' : 'erp',
                );
            }
        }

        return $lines;
    }

    /**
     * WHAT THE ERP STATES FOR THE WHOLE DOCUMENT, with no view about which
     * part of it anyone is asking about.
     *
     * 🔒 1468 LEFT THIS WITH NO PRODUCTION CALLER. Its two consumers were the
     * rollup-sourced erp_open_amount (via erpHeaderCharges(), now removed) and
     * the headline contribution (ErpQuoteHooks/OpportunityContribution.php).
     * Both now take the primary's Grand Total, whose own formula carries the
     * ERP's stated charges (🔒 1450). It is KEPT, unchanged, as the one place
     * that still states the lines-before-header rule (the user ruling below)
     * in executable form; removing it, statedLineCharges() and statedCharge()
     * is a follow-up, not part of this change.
     *
     * @return array{applicable: bool, amount: float, refusal: ?string}
     */
    public static function statedDocumentCharges(SugarBean $quote, array $lines = array()): array
    {
        $inert = array('applicable' => false, 'amount' => 0.0, 'refusal' => null);

        // USER RULING: "in sugar summerize the shippping and tax from the lines
        // if there are there ... or take the line = 0 if its there for
        // shipping". So the LINES are asked first and the document-level figure
        // is the fallback, not the other way round.
        //
        // WHY LINES WIN WHEN THEY EXIST, and it is not a preference. Epicor
        // holds charges as rows: QuoteMsc/QuoteDtlTax on a LINE, QuoteHedMsc/
        // QuoteHedTax on the DOCUMENT, and the header's total is their SUM. A
        // line-held figure therefore survives a PARTIAL release intact - it
        // rides its own line into the order - while a document figure cannot be
        // split across part of a document at all. Summing the lines is reading
        // the same arithmetic Epicor already did, one level down, where it
        // stays true when only some lines are taken.
        $lineTotal = self::statedLineCharges($lines);
        if ($lineTotal !== null) {
            return array('applicable' => true, 'amount' => $lineTotal, 'refusal' => null);
        }

        // STATE 1. Neither field declared -> this instance carries no ERP
        // charge statement and never did. 1.0.18's behaviour, unchanged.
        if (!isset($quote->field_defs[self::ERP_TAX_FIELD])
            || !isset($quote->field_defs[self::ERP_SHIPPING_FIELD])
        ) {
            return $inert;
        }

        // A quote nobody synced has no ERP statement to source from, and the
        // ruling is about reconciling two ERP-derived figures. Leaving it
        // alone is not a gap - a hand-built quote's tax and shipping are
        // Sugar's own, already inside the native total the shared amount
        // writer uses when no ERP contribution applies.
        if (!isset($quote->field_defs[self::ERP_ORIGIN_FIELD])
            || trim((string) ($quote->erp_sync_key ?? '')) === ''
        ) {
            return $inert;
        }

        $tax = self::statedCharge($quote->erp_tax_amount ?? null);
        $shipping = self::statedCharge($quote->erp_shipping_amount ?? null);

        // STATE 2. Offered and not answered.
        if ($tax === null || $shipping === null) {
            return array(
                'applicable' => true,
                'amount' => 0.0,
                'refusal' => 'erp_charges_unstated',
            );
        }

        // STATE 3.
        return array(
            'applicable' => true,
            'amount' => $tax + $shipping,
            'refusal' => null,
        );
    }

    /**
     * One ERP-stated charge as a float, or null when the ERP has not stated
     * it - which is NOT the same as stating nothing.
     *
     * is_numeric() is the test, not a cast and not empty(). A cast turns null,
     * '' and 'n/a' all into 0.0, which is the absent-read-as-zero that put 994
     * fabricated rows on a tenant. empty() would additionally throw away a
     * real, stated "0" and a real, stated "0.00" - and a stated zero is the
     * COMMON case here, since most quotes genuinely carry no tax.
     *
     * A NEGATIVE CHARGE IS A VALID STATEMENT AND IS KEPT. Measured on EPIC06:
     * quote 1120 carries DocTax -0.43 across two negative line taxes. Any
     * guard written as `> 0` would silently drop it; the test is `is_numeric`
     * and nothing here compares against zero.
     */
    /**
     * ONE QUOTE LINE'S DISCOUNT, IN DOCUMENT CURRENCY - Sugar's two spellings
     * of one concession, resolved into the single number ErpQuoteLineRollup
     * takes.
     *
     * Products stores the figure in `discount_amount` and then reads it two
     * incompatible ways depending on `discount_select`: a PERCENT of the line
     * when set (which is Sugar's own default for the field), flat document
     * currency when not. The stored 5.000000 is $5 or $45 depending entirely on
     * a boolean, so a caller that ignores the flag is not approximately right -
     * it is an order of magnitude out, silently, in whichever direction the
     * tenant happens to sell.
     *
     * 🛑 THE RULE IS THE PLATFORM'S, QUOTED. SugarEnt 26.1.0
     * modules/Products/vardefs.php, `deal_calc` (:415-436) and `total_amount`
     * (:184-215):
     *
     *   deal = discount_select ? subtotal x (discount_amount / 100)
     *                          : (quantity > 0 ? (discount_price < 0 ? -d : d)
     *                                          : -d)
     *
     * The two sign flips look like noise and are not: they are how Sugar keeps
     * a credit line and a zero-quantity line from having their discount
     * applied the wrong way round, and copying the rule without them would
     * produce a figure that agrees with the quote on ordinary lines and
     * disagrees on exactly the lines somebody is already looking at.
     *
     * 📌 TAKEN AGAINST THE LINE'S GROSS, NOT AGAINST Products.subtotal, AND
     * THAT IS THE LOAD-BEARING CHOICE. ERP-Epicor overrides the `subtotal`
     * formula so an `erp_total_role = alternative` rung extends to 0.00
     * (.../Products/Ext/Vardefs/erp_total_role.php). `deal_calc` and
     * `total_amount` both read `$subtotal`, so both read 0.00 on a losing rung.
     * Reading either of them here would tell the rollup that a discounted
     * alternative carries no discount - and this class values alternatives at
     * their own worth ON PURPOSE, to answer "what is this rung worth IF it
     * wins". That counterfactual has to carry the concession attached to the
     * rung, so the percent is taken against quantity x discount_price: exactly
     * what `subtotal` would be if the rung were the one that counted.
     *
     * On a `counts` line the two are the same number, so nothing diverges on
     * the lines that decide the Grand Total. The one platform case where they
     * differ on a counting line is a SERVICE line, whose `subtotal` is prorated
     * by service_duration_value/catalog_service_duration_value - a divergence
     * ErpQuoteLineRollup has always had, since its line value has always been
     * quantity x price. Inherited knowingly, not widened.
     *
     * ⚠️ A FLAT discount on an ALTERNATIVE cannot legally arise: ErpLineRole
     * refuses to stamp such a line, for the reason that same vardef states in
     * its own words. This function still answers for that shape rather than
     * assuming it away.
     *
     * ABSENT, EMPTY AND NON-NUMERIC ARE ALL 0.00, never a throw. It runs inside
     * an after_save hook on Quotes; a malformed cell must not fail a seller's
     * save. `statedCharge()` below draws the opposite distinction on purpose -
     * there, absent and zero MEAN different things because the ERP is the one
     * speaking. Here the seller is, and a line with nothing typed in its
     * discount box has no discount.
     *
     * @param SugarBean|object $product one Products row
     */
    public static function lineDiscount($product): float
    {
        $raw = $product->discount_amount ?? null;
        if (!is_numeric($raw)) {
            return 0.0;
        }

        $discount = (float) $raw;
        if ($discount === 0.0) {
            return 0.0;
        }

        if (!empty($product->discount_select)) {
            $gross = (float) ($product->quantity ?? 0) * (float) ($product->discount_price ?? 0);

            return $gross * ($discount / 100.0);
        }

        if ((float) ($product->quantity ?? 0) > 0) {
            return (float) ($product->discount_price ?? 0) < 0 ? -$discount : $discount;
        }

        return -$discount;
    }

    /**
     * The lines' own charges, summed - or null when no line states one.
     *
     * NULL IS THE WHOLE POINT and is not the same as 0.0. A quote whose lines
     * carry no stated charge must fall through to the document-level figure; a
     * quote whose lines all state 0.00 has been ANSWERED, and answering zero is
     * a measurement the caller must not discard by falling through. Returning
     * 0.0 for both would silently prefer a document charge over an explicit
     * line-level "nothing to add".
     *
     * Tax and shipping are summed together because the caller adds one figure
     * to one side of the rollup; they are held apart on the LINE, where a
     * seller reads them, and only joined here.
     *
     * @param array $lines rows from quoteLines()
     */
    private static function statedLineCharges(array $lines): ?float
    {
        $total = 0.0;
        $anyStated = false;

        foreach ($lines as $line) {
            foreach (array('tax', 'shipping') as $key) {
                $value = $line[$key] ?? null;
                if ($value === null) {
                    continue;
                }
                $anyStated = true;
                $total += (float) $value;
            }
        }

        return $anyStated ? $total : null;
    }

    private static function statedCharge($value): ?float
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Can this Opportunity actually store a refusal reason?
     *
     * Asked of field_defs rather than of the property, because an absent
     * property and a declared-but-empty one are the same `??` result and only
     * one of them is safe to write.
     */
    private function refusalStorable(SugarBean $opportunity): bool
    {
        return isset($opportunity->field_defs[self::REFUSAL_FIELD]);
    }

    /**
     * How lines sharing a part number combine on the OPEN side: 'sum' (every
     * line independent - the pre-existing meaning, and the DEFAULT so that no
     * tenant's numbers move), or 'max'/'min' when a tenant's repeated part
     * numbers are mutually exclusive quantity breaks. Validated inside
     * ErpQuoteLineRollup, which falls back to 'sum' on anything it does not
     * recognise.
     *
     * THE DEFAULT STAYS 'sum', AND A LANE THAT BRIEFLY CHANGED IT TO 'min' WAS
     * WRONG. Recorded here because the reasoning is worth more than the diff.
     *
     * The observation behind that change was sound: erp_integration
     * .quote_group_rollup is written by no installer, no UI and no tenant
     * measured, so whatever the UNSET case resolves to IS the behaviour of the
     * whole fleet, and a summed quantity ladder is exactly the inflation
     * ErpQuoteLineRollup's own docblock exists to remove.
     *
     * THE CONCLUSION WAS WRONG BECAUSE OF WHERE IT PUT THE RULE. "Repeated part
     * numbers on one quote are mutually exclusive quantity breaks" is a
     * statement about ONE CUSTOMER'S catalogue, not about every tenant that
     * installs this package. Making it the fleet default takes a customer's
     * valuation rule and ships it to everyone who opted into nothing - the
     * precise thing this package's layering exists to prevent, and which the
     * class docblock next door already refuses in as many words: "it would be
     * guessing on behalf of tenants whose alternatives genuinely are additive."
     *
     * WHERE THE RULE ACTUALLY LIVES, AND WHY NOTHING IS NEEDED HERE. The
     * customer's own words are "min is only on <customer> multiple quantity to
     * the same product you take the minimum for that break, but all other line
     * items in the quote sum". That composes out of machinery that already
     * exists, with no customer-specific rule in this shared package:
     *
     *   - ACROSS groups this class always sums, because different part numbers
     *     legitimately add. Nothing to change.
     *   - WITHIN a break group, the customer package pins ONE rung and
     *     ErpQuoteLineRollup::compute() honours that pin BEFORE any policy
     *     branch. WHICH rung - the lowest total - is the customer's rule and
     *     lives in the customer's own selector.
     *   - Every OTHER line is its own group (a different part number) or a
     *     different provenance, so it is summed exactly as before.
     *
     * So "minimum within the break, sum everywhere else" is already the
     * behaviour on an adjudicated tenant, and it is reached without this key.
     * A tenant that genuinely wants extremum arithmetic with no pins can still
     * set the key by hand - that is what it is for.
     *
     * ONE HAZARD THAT IS INERT ONLY BECAUSE THE DEFAULT IS 'sum', AND MUST BE
     * FIXED BEFORE ANYONE EVER SETS 'min' OR 'max' ON A TENANT: groupKey(),
     * which the extremum branches read, does NOT include provenance the way
     * pinGroupKey() does. Under 'min' a line a rep typed by hand that happens
     * to share a part number with a priced ladder joins that ladder's group
     * and, being the cheapest member, BECOMES the group's whole value -
     * deleting the ladder's worth and every other line in it, with no error
     * anywhere. Measured on the live shape: 500 where 6,900 belongs. Under
     * 'sum' groupKey() feeds a branch that never runs, so nothing is wrong
     * today. See validation/g3-line-rollup-refusal-plan.md section 9.
     */
    public static function configuredGroupPolicy(): string
    {
        $config = self::erpIntegrationConfig();
        $policy = trim((string) ($config[self::GROUP_POLICY_KEY] ?? ''));

        return $policy === '' ? ErpQuoteLineRollup::POLICY_SUM : $policy;
    }

    /**
     * $amount expressed in the opportunity's currency, or null when it cannot
     * be converted (then nothing is written, same as the total conversion).
     */
    private function inOpportunityCurrency(float $amount, SugarBean $quote, SugarBean $opportunity): ?float
    {
        $from = (string) ($quote->currency_id ?? '');
        $to = (string) ($opportunity->currency_id ?? '');
        if ($from === '' || $to === '' || $from === $to) {
            return round($amount, 2);
        }

        try {
            return round((float) SugarCurrency::convertAmount($amount, $from, $to), 2);
        } catch (\Throwable $e) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: cannot convert quote ' . $quote->id
                . ' rollup from ' . $from . ' to ' . $to . ': ' . $e->getMessage());
            return null;
        }
    }
}
