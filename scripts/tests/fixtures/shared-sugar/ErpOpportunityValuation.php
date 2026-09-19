<?php

require_once 'custom/modules/Quotes/ErpQuoteLineRollup.php';

/**
 * The writer of partial-fulfillment Opportunity fields, through TWO
 * entry points with deliberately different ownership and different triggers.
 *
 * 1. RELEASE TIME - sales_stage. afterLinesOrdered() is called
 *    explicitly by QuotesErpActionsApi::orderLines() after a release lands,
 *    NEVER from a logic hook: a change to an opportunity's STAGE has one
 *    named cause, and the two things that write it from a quote - this class
 *    at release time and OrderStageOpportunityCascade when an order reaches a
 *    terminal state - live side by side in the core package and yield to each
 *    other by quote_stage (the cascade stands down while the quote is
 *    'Partially Fulfilled'; this half only ever acts on a release).
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
 *   - A customer package may ALSO provide the fixed, neutral line-rollup
 *     adjudication policy at .../ErpQuoteHooks/OpportunityLineRollupPolicy.php,
 *     which can REFUSE a quote outright. Same shape, same failure rule, and
 *     see refreshLineRollup() for why the refusal has to live here rather than
 *     in the package that knows what it means.
 *   - Opportunities.erp_rollup_refusal: the refusing provider's own sentence,
 *     verbatim, beside the two figures it held - and blank whenever they are
 *     current. A refusal nobody can see is the defect it replaces wearing the
 *     other face: the same figures as before, and nothing saying they have
 *     stopped following the quote. See publishRollupRefusal().
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
    public const GROUP_POLICY_KEY = 'quote_group_rollup';

    private const RELEASE_STAGE_POLICY_FILE =
        'custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php';

    private const LINE_ROLLUP_POLICY_FILE =
        'custom/modules/Quotes/ErpQuoteHooks/OpportunityLineRollupPolicy.php';

    /** Where a refusal is published so a seller can see it, and its width. */
    private const REFUSAL_FIELD = 'erp_rollup_refusal';
    private const REFUSAL_LENGTH = 255;


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
            return self::preservedResolution($providerStatus);
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
        $stage = trim((string) ($config[self::PARTIAL_STAGE_KEY] ?? ''));
        if ($stage === '') {
            return ['stage' => '', 'reason' => 'missing'];
        }

        $appListStrings = self::currentAppListStrings();
        $dom = $appListStrings['sales_stage_dom'] ?? array();
        if (!is_array($dom) || !array_key_exists($stage, $dom)) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: ' . self::CONFIG_CATEGORY . '.'
                . self::PARTIAL_STAGE_KEY . ' is ' . var_export($stage, true)
                . ' which is not a sales_stage_dom key - ignoring it');
            return ['stage' => '', 'reason' => 'invalid'];
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
     * Roll this quote's LINE ITEMS up onto its opportunity as two numbers:
     * what the customer has committed to (erp_ordered_amount, summed over
     * lines the ERP already carries) and what is still winnable
     * (erp_open_amount). The arithmetic - including how mutually exclusive
     * quantity breaks collapse - lives in ErpQuoteLineRollup; this method only
     * reads beans, converts currency and writes.
     *
     * DELIBERATELY DOES NOT TOUCH amount OR sales_stage. The shared
     * QuoteOpportunityAmount hook owns headline valuation from the native
     * grand total; afterLinesOrdered and OrderStageOpportunityCascade own
     * stage transitions.
     *
     * TAX AND SHIPPING - DECISION 122, AND THIS SENTENCE USED TO SAY THE
     * OPPOSITE. It read "This line rollup excludes tax and shipping", which
     * was true, internally consistent, documented - and meant that
     * `erp_open_amount` and the Opportunity `amount` beside it could NEVER
     * agree on any quote carrying either, because the headline amount adds
     * both. Two figures on one record, computed on different bases, so every
     * row that grades "are the numbers right" could only pass on an artificial
     * zero-tax, zero-shipping quote. Neither figure was buggy; that was the
     * problem. The ruling: BOTH must mean what the customer owes.
     *
     * AND THE PART THAT IS NOT NEGOTIABLE - THE NUMBER COMES FROM THE ERP.
     * This method does not compute tax. It does not read a rate, it does not
     * multiply a base by one, it does not touch Sugar's own `tax`/`taxrate_
     * value`/`shipping`. A locally-derived figure that happens to tie out
     * today is still a local figure and it will drift the first time a
     * jurisdiction, an exemption or a rounding rule differs - and the ERP is
     * the system that will actually invoice the customer. Either the ERP has
     * stated the charge on this quote or this method publishes nothing new.
     * See erpHeaderCharges() for the three states and what each one does.
     *
     * WHICH QUOTE DRIVES IT. Only the contributing primary quote. Editing a
     * nonprimary revision must not overwrite the current pursuit's values.
     *
     * AND IT CAN REFUSE - WHICH IS WHY THE REFUSAL LIVES HERE AND NOT IN THE
     * PACKAGE THAT KNOWS WHAT IT MEANS.
     *
     * These two numbers are recomputed on ANY save of the quote: a rep pressing
     * Save, a connector writing the header, a release landing. That is the
     * point of a save-time rollup and it is not changing. But it also means
     * that a package layered on top of this one CANNOT intercept the write -
     * it does not own the hook, it does not own the fields, and by the time its
     * own code runs this method has already published a number.
     *
     * The headline `amount` has had a refusal boundary all along: a customer
     * contribution provider throws, the shared writer catches, and the previous
     * value is preserved rather than replaced by a guess. These two fields had
     * NONE. On a quote whose alternatives nobody has adjudicated, compute()
     * falls through to the configured policy - `sum` in production - and
     * publishes the whole ladder as still-winnable, beside a frozen `amount`
     * that correctly refused. Two numbers on one record, disagreeing by
     * multiples, with nothing on screen or in the log saying why.
     *
     * THE RULE THIS PACKAGE CANNOT LEARN, AND THE ONE IT CAN. It cannot learn
     * "exactly one alternative per group" - that is a customer's rule, this
     * package installs on every tenant, and tenants whose repeated part numbers
     * are genuinely additive would have their forecasts refused for a state
     * that is correct for them. What it CAN own, customer-agnostically, is the
     * REFUSAL PATH ITSELF: somewhere for an adjudicating package to say "do not
     * publish a number for this quote", and one shared, auditable response to
     * that which PRESERVES what is stored. The predicate belongs to whoever
     * knows the domain; the decision to write or not to write belongs to
     * whoever owns the write, and that is this method.
     *
     * PRESERVED, NOT ZEROED, AND NOT PARTIALLY WRITTEN. A refusal returns
     * before any assignment, so both fields keep their stored values and the
     * Opportunity is not saved at all. Writing 0.00 would be worse than the
     * defect it replaces - a fabricated zero reads as a real number and
     * destroys the last figure anybody adjudicated (decision 59's 994 rows).
     *
     * THE COST, STATED RATHER THAN DISCOVERED LATER. erp_ordered_amount is
     * written by the same call, so a refusal also holds back the ORDERED
     * figure - a fact, not a forecast - until the quote next settles and saves.
     * Publishing half of a rollup whose other half was refused would put a
     * fresh number beside a stale one with nothing to mark which is which, so
     * the whole statement waits. A tenant with no provider file is unaffected
     * by any of this.
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

        // THE ORDER OF THESE TWO STATEMENTS IS LOAD-BEARING. quoteLines() is
        // what forces Link2 to drop the beans an earlier walk in this same
        // request materialised (see its own comment). An adjudicating provider
        // re-reads the quote's lines to make its decision, so consulting it
        // BEFORE this read would hand it the rows as they were before the
        // seller's toggle - and it would then answer "settled" for a quote that
        // is not, or refuse one that is. Read first, ask second.
        $lines = $this->quoteLines($quote);

        // DECISION 122. Read BEFORE the branch below so that a charge the ERP
        // has not stated refuses through the SAME path a policy refusal takes:
        // one preserve-and-publish response, not a second one to keep in step.
        // The customer's own adjudicator still speaks first - its refusal is
        // about whether this quote may be valued at all, which is the prior
        // question to what the valuation should include.
        $charges = $this->erpHeaderCharges($quote, $lines);

        $refusal = $this->lineRollupRefusal($quote);
        if ($refusal === null) {
            $refusal = $charges['refusal'];
        }
        if ($refusal !== null) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: the line rollup for quote ' . $quote->id
                . ' was REFUSED (' . $refusal . ') - preserving erp_ordered_amount '
                . var_export($opportunity->erp_ordered_amount ?? null, true) . ' and erp_open_amount '
                . var_export($opportunity->erp_open_amount ?? null, true)
                . ' rather than publishing a value nothing has adjudicated');
            // AND SAY SO ON THE RECORD. A log line needs an administrator and
            // a Diagnostic Tool pull to read; the person the stale number
            // misleads is the seller looking at this Opportunity. Neither
            // amount field is assigned on this branch - that is still the
            // whole point - but the reason is published beside them, so the
            // refusal is an observable event rather than an absence of one.
            $this->publishRollupRefusal($opportunity, $refusal);
            return;
        }

        $rollup = ErpQuoteLineRollup::compute(
            $lines,
            self::configuredGroupPolicy()
        );

        // THE CHARGE LANDS ON THE OPEN SIDE AND NOWHERE ELSE, and the reason
        // is the same one that makes `ordered` a plain sum. An ordered line is
        // a fact that has left this document: the ERP re-states tax and
        // freight on the ORDER it created, against that order's own ship-to
        // and its own tax point, and this quote header knows nothing about it.
        // Moving a slice of the QUOTE's charge into erp_ordered_amount would
        // put a quote-derived guess inside a figure whose whole value is that
        // it contains no guesses. Where anything IS ordered the charge is not
        // added at all - erpHeaderCharges() has already refused by then, so
        // this addition is only ever reached on a wholly-open quote.
        $ordered = $this->inOpportunityCurrency($rollup['ordered'], $quote, $opportunity);
        $open = $this->inOpportunityCurrency($rollup['open'] + $charges['amount'], $quote, $opportunity);
        if ($ordered === null || $open === null) {
            return;
        }

        $changed = false;
        // CLEARED ON THE WAY THROUGH, THROUGH THE SAME $changed BLOCK. A
        // refusal reason that outlives its refusal is worse than none: it
        // labels a perfectly current pair of figures as held, and a seller who
        // learns to ignore it has lost the one signal that matters. It is
        // derived from THIS valuation, never remembered from an earlier one.
        if ($this->refusalStorable($opportunity)
            && (string) ($opportunity->erp_rollup_refusal ?? '') !== ''
        ) {
            $opportunity->erp_rollup_refusal = '';
            $changed = true;
        }
        if ((float) ($opportunity->erp_ordered_amount ?? 0) !== $ordered) {
            $opportunity->erp_ordered_amount = $ordered;
            $changed = true;
        }
        if ((float) ($opportunity->erp_open_amount ?? 0) !== $open) {
            $opportunity->erp_open_amount = $open;
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $opportunity->save();

        $GLOBALS['log']->info('ErpOpportunityValuation: opportunity ' . $opportunity->id
            . ' line rollup from quote ' . $quote->id . ' (policy ' . self::configuredGroupPolicy()
            . '): ordered=' . $ordered . ' open=' . $open
            // SAY WHICH OF THE TWO MEANINGS THIS NUMBER HAS. An open figure
            // that includes an ERP-stated charge and one that does not are
            // different quantities, and after decision 122 both are reachable
            // on the same fleet depending on whether the connector has filled
            // the fields on that tenant yet. A reader comparing two tenants'
            // logs must not have to guess which they are looking at.
            . ' erp_charges=' . ($charges['applicable'] ? $charges['amount'] : 'not-carried'));
    }

    /**
     * This quote's line items in the shape ErpQuoteLineRollup expects.
     *
     * THE GROUP IS EPICOR'S OWN QUOTE LINE, read out of erp_sync_key - see
     * ladderGroup() below. It used to be mft_part_num, which was a GUESS at
     * the thing Epicor already states (decision 789).
     *
     * discount_price, not list price: the same field the quote's own totals
     * are summed from elsewhere in this package.
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
                    'ordered' => !empty($product->erp_ordered),
                    // WHICH ERP order took this line. Needed by the close
                    // contribution to find the order Epicor restated tax on -
                    // and needed as an identity, not a count: two lines on the
                    // SAME order must not have that order's tax counted twice.
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
     * WHAT THE ERP SAYS THIS QUOTE'S TAX AND SHIPPING ARE - decision 122's
     * whole surface in this package, and the guard that keeps it off the
     * fleet until something actually states them.
     *
     * Returns, always:
     *   applicable  bool   whether an ERP-stated charge is in play at all
     *   amount      float  what to add to the OPEN side (0.0 when not)
     *   refusal     ?string  a reason to publish nothing, or null
     *
     * THREE STATES, AND THE MIDDLE ONE IS THE ENTIRE POINT.
     *
     * 1. NOT CARRIED -> not applicable, add nothing, refuse nothing. The
     *    fields are not declared on this instance, so no package has ever
     *    offered to fill them. This is EVERY TENANT IN THE ESTATE TODAY and
     *    it is the branch that makes this release a no-op fleet-wide: the
     *    rollup is byte-for-byte the one 1.0.18 published. It is the same
     *    guard, and for the same reason, as "no provider file means no
     *    opinion" one method down, and as the decision-102 correction that
     *    stopped POLICY_MIN becoming a fleet default - a valuation change
     *    that reaches tenants who opted into nothing is a regression however
     *    right it is for the tenant that asked for it.
     *
     *    ASKED OF field_defs, NEVER OF THE PROPERTY. An undeclared field and
     *    a declared-but-empty one are the same `??` answer and they mean
     *    opposite things: one is "nobody offers this number", the other is
     *    "somebody offers it and has not got one". refusalStorable() already
     *    draws that distinction for the refusal column; this is the same cut.
     *
     * 2. CARRIED BUT UNSTATED -> REFUSE. The fields exist, this quote came
     *    from the ERP, and one of them holds nothing numeric. That is the
     *    connector saying "I have not got Epicor's answer for this quote" -
     *    it is NOT the ERP saying zero, and the difference is decision 59's
     *    994 fabricated 0.00 rows. Reading an absent charge as no charge
     *    would publish a number that looks exactly like a correct one and is
     *    quietly short by whatever the ERP will actually invoice. So the
     *    whole statement waits, both figures keep their stored values, and
     *    the reason goes on the record beside them. An explicit numeric zero
     *    IS a statement and is honoured as one: Epicor's quote tax engine is
     *    on for every quote we have measured (XbSystCalcQuoteTax true on
     *    130 of 130 EPIC06 quotes), so a zero from it is an evaluated zero.
     *
     * 3. STATED -> add tax + shipping to the open side.
     *
     * AND ONE REFUSAL THAT IS NOT ABOUT ABSENCE AT ALL. A quote header states
     * ONE tax and ONE freight figure, for the whole document. erp_open_amount
     * values a SUBSET of that document - what has not yet been released - so
     * the moment any line is ordered the header's charge no longer describes
     * what this figure measures. There is no ERP field that splits it: Epicor
     * states quote tax at the header and at QuoteDtl, and QuoteQty - the rung
     * that becomes a Sugar quote line, and the level the released/open cut is
     * actually made at - carries no tax property at all (108 enumerated, none
     * matching "Tax", confirmed against live rows). Apportioning it here by
     * value or by count would be exactly the locally-derived figure the ruling
     * forbids, dressed as arithmetic. So a partially-released quote refuses
     * and says so, rather than publishing a plausible split nobody computed.
     *
     * THIS METHOD LEARNS NOTHING ABOUT ANY CUSTOMER. "Include what the ERP
     * says the customer owes" is an accounting choice every tenant makes the
     * same way; it carries no rule about ladders, pins, part numbers or which
     * of several alternatives counts. Those stay where gate G2 put them - the
     * pin in the adjudicating package, the grouping in ErpQuoteLineRollup -
     * and nothing here reads or writes either.
     *
     * @param array $lines the same rows the rollup is computed from
     *
     * @return array{applicable: bool, amount: float, refusal: ?string}
     */
    private function erpHeaderCharges(SugarBean $quote, array $lines): array
    {
        $charges = self::statedDocumentCharges($quote, $lines);

        if (empty($charges['applicable']) || $charges['refusal'] !== null) {
            return $charges;
        }

        // The whole-document charge cannot be cut to fit a subset.
        //
        // THIS IS THE ONLY DIFFERENCE between the subset question and the
        // whole-document one, and it is why they are now two methods rather
        // than one. erp_open_amount is a SUBSET: once part of the document has
        // been ordered there is no honest way to say how much of a single
        // document-level charge belongs to what is left, and Epicor does not
        // answer that question either - it RECOMPUTES tax and freight on the
        // order it creates, against that order's own ship-to and tax point.
        // Inventing a split here would put a guess inside a figure whose whole
        // worth is that it contains none.
        //
        // The Opportunity's HEADLINE amount is not a subset - it covers the
        // whole quote, ordered lines included - so the same charge applies to
        // it exactly and nothing needs apportioning. That caller takes
        // statedDocumentCharges() directly and never reaches this refusal.
        foreach ($lines as $line) {
            if (!empty($line['ordered'])) {
                return array(
                    'applicable' => true,
                    'amount' => 0.0,
                    'refusal' => 'erp_charges_not_apportionable',
                );
            }
        }

        return $charges;
    }

    /**
     * WHAT THE ERP STATES FOR THE WHOLE DOCUMENT, with no view about which
     * part of it anyone is asking about.
     *
     * Split out of erpHeaderCharges() so the two questions stop being one.
     * The three states below are unchanged and are the whole safeguard; the
     * apportionment refusal that used to sit among them is a property of the
     * SUBSET caller, not of the charge, and now lives with that caller.
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
     * Publish WHY the two rollup figures did not move, beside the figures.
     *
     * THE HALF A REFUSAL IS WORTH NOTHING WITHOUT. Preserving a stored number
     * instead of overwriting it with one nobody adjudicated is correct, and on
     * its own it is indistinguishable from the number being right: the record
     * shows the same figures it showed before, and nothing on the page says
     * they have stopped following the quote. That is the same class of defect
     * as the one the refusal path closed - a wrong-looking number in front of
     * a person with no way to tell - wearing the other face.
     *
     * THE PROVIDER'S OWN WORDS, VERBATIM. This package does not interpret,
     * translate or classify them. It cannot: the reasons belong to a customer
     * package's domain, and inventing a vocabulary for them here is exactly
     * the customer-specific knowledge decision 87(b) gate G2 keeps out of the
     * shared layer. Truncated only to the column's width, and truncated rather
     * than dropped because a clipped sentence still names the condition.
     *
     * COMPARE BEFORE WRITING. The Quotes after_save this runs under fires on
     * EVERY save of the quote, so an unconditional write would save the
     * Opportunity - and stamp date_modified, and add an audit row - on every
     * one of them while nothing about the situation changed. Saving only on a
     * real change keeps the audit trail answering "since when", which is the
     * question the field is audited for.
     *
     * NOTHING IS WRITTEN WHERE NOTHING CAN STORE IT. On an instance whose
     * vardefs have not been rebuilt, setting the property would put a value in
     * memory that no column persists - a silent half-state, and worse than no
     * marker at all. The rollup figures are preserved either way; only the
     * explanation is lost, and the log line above still carries it.
     */
    private function publishRollupRefusal(SugarBean $opportunity, string $reason): void
    {
        if (!$this->refusalStorable($opportunity)) {
            return;
        }

        $reason = mb_substr(trim($reason), 0, self::REFUSAL_LENGTH);
        if ((string) ($opportunity->erp_rollup_refusal ?? '') === $reason) {
            return;
        }

        $opportunity->erp_rollup_refusal = $reason;
        $opportunity->save();
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
     * WHY this quote's line rollup must not be published, or null when nothing
     * objects to publishing it.
     *
     * Provider contract - deliberately the same shape as the release-stage
     * provider above, because a second shape for a second optional hook is a
     * second thing to get wrong:
     *
     *   file:   custom/modules/Quotes/ErpQuoteHooks/OpportunityLineRollupPolicy.php
     *   class:  ErpOpportunityLineRollupPolicy
     *   method: refusal(SugarBean $quote): ?string
     *
     *   null              -> no opinion. Roll up exactly as before.
     *   a non-blank string -> REFUSE, and the string says why, for the log.
     *
     * NO FILE MEANS NO OPINION, AND THAT IS THE GUARD THE WHOLE CHANGE HANGS
     * ON. This package installs on every tenant. On an instance with no
     * provider file this method returns null before doing anything at all, so
     * the rollup is byte-for-byte the one that shipped before a refusal path
     * existed. Nobody who opted into nothing gets a number that moved.
     *
     * EVERYTHING ELSE REFUSES, AND THE ASYMMETRY IS THE POINT. A file that
     * does not define the class, a provider that throws, a provider that
     * answers with anything other than null or a non-blank string: all refuse.
     * The provider file's PRESENCE is a package saying "I adjudicate this
     * tenant's quotes". Once that has been said, "I could not tell you" is not
     * evidence that the number is safe to publish - it is evidence that nobody
     * checked, and publishing anyway is precisely the fail-open this exists to
     * close. It mirrors the release-stage rule one screen up: invalid or
     * throwing policy code preserves, it never falls back.
     *
     * A blank string is not a quiet "no" - it is a refusal with no reason,
     * which is a broken provider, and it refuses on the invalid-shape branch.
     *
     * The literal path/class/method calls are intentionally verbose:
     * SugarCloud's scanner rejects dynamic dispatch in installable packages.
     */
    private function lineRollupRefusal(SugarBean $quote): ?string
    {
        if (!file_exists(self::LINE_ROLLUP_POLICY_FILE)) {
            return null;
        }

        try {
            require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath(
                self::LINE_ROLLUP_POLICY_FILE
            );
            if (!class_exists('ErpOpportunityLineRollupPolicy', false)) {
                $GLOBALS['log']->warn('ErpOpportunityValuation: the line-rollup policy file does not define '
                    . 'ErpOpportunityLineRollupPolicy - refusing quote ' . $quote->id
                    . ' rather than valuing it unadjudicated');
                return 'policy_provider_invalid';
            }
            $answer = (new ErpOpportunityLineRollupPolicy())->refusal($quote);
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('ErpOpportunityValuation: the line-rollup policy failed for quote '
                . $quote->id . ' - refusing rather than valuing it unadjudicated: ' . $e->getMessage());
            return 'policy_provider_exception';
        }

        if ($answer === null) {
            return null;
        }

        if (!is_string($answer) || trim($answer) === '') {
            $GLOBALS['log']->warn('ErpOpportunityValuation: the line-rollup policy returned an invalid shape '
                . var_export($answer, true) . ' for quote ' . $quote->id
                . ' - refusing rather than valuing it unadjudicated');
            return 'policy_invalid_shape';
        }

        return trim($answer);
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
