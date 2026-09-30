<?php

require_once 'custom/modules/Quotes/ErpQuoteLineRollup.php';

/**
 * The writer of partial-fulfillment Opportunity fields, through two entry points with deliberately different ownership and different triggers. (G346, 🔒 1614, 🔒 1413)
 */
class ErpOpportunityValuation
{
    public const CONFIG_CATEGORY = 'erp_integration';
    public const PARTIAL_STAGE_KEY = 'partial_order_sales_stage';
    public const PARTIAL_PROBABILITY_KEY = 'partial_order_probability';

    /**
     * G305 / 🔒 1519 — what a partial release does to the opportunity when nobody has configured this tenant. (🔒 1514, G278, 🔒 1506)
     */
    public const DEFAULT_PARTIAL_STAGE = 'Partial Production Ordered';

    /** G346 — the Opportunity stage a release that leaves nothing open writes when no customer policy decides. */
    public const ORDERED_IN_FULL_SALES_STAGE = 'Closed Won';

    public const GROUP_POLICY_KEY = 'quote_group_rollup';

    private const RELEASE_STAGE_POLICY_FILE =
        'custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php';

    /** Where the one remaining refusal is published so a seller can see it. */
    private const REFUSAL_FIELD = 'erp_rollup_refusal';

    /** 🔒 1468's only refusal: the primary's own total is not a number. */
    public const TOTAL_NOT_A_NUMBER = 'primary_total_not_a_number';


    /** The ladder this line belongs to, as epicor states it. (decision 789, 🔒 796) */
    private static function ladderGroup($product): string
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

    /** A name for this line that a seller would recognise on their own screen. */
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

    /** What the ERP says this quote's tax and shipping are - decision 122. */
    private const ERP_TAX_FIELD = 'erp_tax_amount';
    private const ERP_SHIPPING_FIELD = 'erp_shipping_amount';

    /** G466 (🔒 1758b / 🔒 1764b): shipping counts on an advanced quote only. */
    private const QUOTE_TYPE_FIELD = 'erp_quote_type';
    private const ADVANCED_QUOTE = 'advanced_quote';

    /** An ERP-born quote is one the connector keyed. (decision 122) */
    private const ERP_ORIGIN_FIELD = 'erp_sync_key';

    private const TERMINAL_SALES_STAGES = ['Closed Won', 'Closed Lost'];

    /**
     * G498 / 🔒 1761b: the stage of an opportunity whose quote lost every order - a stock sales_stage_dom key, the one TERMINAL_SALES_STAGES already names.
     */
    public const ORDERS_LOST_SALES_STAGE = 'Closed Lost';

    /** G498: on a revival only Closed Won stays terminal. */
    private const REVIVAL_TERMINAL_SALES_STAGES = ['Closed Won'];

    /** The quote stages an order puts a quote in (ErpQuoteStageRules::COMMITTED_STAGES). */
    private const COMMITTED_QUOTE_STAGES = ['Closed Accepted', 'Partially Fulfilled'];

    /**
     * Re-retrieved after its stage save
     * @param SugarBean $quote
     * @param bool      $partial
     */
    public function afterLinesOrdered(SugarBean $quote, bool $partial): string
    {
        return $this->valueFromOrder($quote, $partial, self::TERMINAL_SALES_STAGES);
    }

    /** G498 / 🔒 1761b — a live order came back to a quote that had lost every order. */
    public function afterOrderRevived(SugarBean $quote, bool $partial): string
    {
        return $this->valueFromOrder($quote, $partial, self::REVIVAL_TERMINAL_SALES_STAGES);
    }

    /**
     * G498 / 🔒 1761b — every order linked to the quote is dead and the quote moved to Closed Lost; its opportunity follows to Closed Lost.
     */
    public function afterOrdersLost(SugarBean $quote): string
    {
        $opportunity = $this->linkedOpportunity($quote);
        if ($opportunity === null) {
            return 'opportunity_missing';
        }

        $stage = self::ORDERS_LOST_SALES_STAGE;
        if (($opportunity->sales_stage ?? '') === $stage) {
            return 'unchanged';
        }

        if ($this->anotherQuoteIsCommitted($opportunity, (string) $quote->id)) {
            $GLOBALS['log']->info('ErpOpportunityValuation: quote ' . $quote->id . ' lost every order, but another '
                . 'quote on opportunity ' . $opportunity->id . ' is committed - the opportunity keeps its stage (G498)');
            return 'sibling_committed';
        }

        $appListStrings = self::currentAppListStrings();
        $dom = $appListStrings['sales_stage_dom'] ?? array();
        if (!is_array($dom) || !array_key_exists($stage, $dom)) {
            $GLOBALS['log']->warn('ErpOpportunityValuation: quote ' . $quote->id . ' lost every order, but '
                . var_export($stage, true) . ' is not a sales_stage_dom key on this instance - the '
                . 'Opportunity keeps its current stage (G498)');
            return 'stage_not_served';
        }
        $probabilities = $appListStrings['sales_probability_dom'] ?? array();

        $opportunity->sales_stage = $stage;
        // In Revenue Line Items mode a bare sales_stage save is dropped; Opportunity::save() persists it through sales_stage_cascade (see valueFromOrder()).
        $opportunity->sales_stage_cascade = $stage;
        if (is_array($probabilities) && isset($probabilities[$stage]) && is_numeric($probabilities[$stage])) {
            $opportunity->probability = max(0, min(100, (int) $probabilities[$stage]));
        }
        $opportunity->save();

        $GLOBALS['log']->info('ErpOpportunityValuation: opportunity ' . $opportunity->id . ' moved to ' . $stage
            . ' - every order of quote ' . $quote->id . ' is dead (G498)');

        return 'updated';
    }

    /** Whether a quote on this opportunity other than $quoteId sits at a committed stage. */
    private function anotherQuoteIsCommitted(SugarBean $opportunity, string $quoteId): bool
    {
        try {
            if (!$opportunity->load_relationship('quotes') || !is_object($opportunity->quotes)) {
                return false;
            }
            foreach ((array) $opportunity->quotes->get() as $id) {
                $id = (string) $id;
                if ($id === '' || $id === $quoteId) {
                    continue;
                }
                $sibling = BeanFactory::retrieveBean('Quotes', $id, array('use_cache' => false));
                if ($sibling && empty($sibling->deleted)
                    && in_array((string) ($sibling->quote_stage ?? ''), self::COMMITTED_QUOTE_STAGES, true)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('ErpOpportunityValuation: could not read the quotes of opportunity '
                . $opportunity->id . ': ' . $e->getMessage());
            return true;
        }

        return false;
    }

    /**
     * The release-time valuation shared by afterLinesOrdered() (every order) and afterOrderRevived() (G498).
     * @param string[] $terminal
     */
    private function valueFromOrder(SugarBean $quote, bool $partial, array $terminal): string
    {
        // Refresh the line rollup from the release explicitly rather than leaning on the Quotes after_save hook to fire in the right order.
        $this->rollupHook($quote);

        $opportunity = $this->linkedOpportunity($quote);
        if ($opportunity === null) {
            $GLOBALS['log']->info('ErpOpportunityValuation: quote ' . $quote->id
                . ' has no linked opportunity - nothing to value');
            return 'opportunity_missing';
        }

        if (in_array($opportunity->sales_stage ?? '', $terminal, true)) {
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
                // Same reason OrderStageOpportunityCascade sets both: in Revenue Line Items mode a bare sales_stage save is silently dropped.
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
     * Resolve release-stage data without giving the customer policy write access.
     * @return array{
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
     * decision: array{sales_stage: string, probability: int}|null, status: string }
     * @param mixed $decision
     * @return array{
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
     * G346 — A release that leaves nothing open. (G401, 🔒 1701b, 🔒 1413)
     * @return array{
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

    /** @return array{decision: */
    private static function preservedResolution(string $status): array
    {
        return ['decision' => null, 'status' => $status];
    }

    /**
     * The tenant's partial-order opportunity stage, '' when none is configured or the configured key is not in sales_stage_dom.
     */
    public static function configuredPartialStage(): string
    {
        return self::configuredPartialStageResolution()['stage'];
    }

    /**
     * Keep the public stage-only helper compatible while preserving the missing/invalid distinction for the neutral release diagnostic.
     * @return array{stage:
     */
    private static function configuredPartialStageResolution(): array
    {
        $config = self::erpIntegrationConfig();

        // G305 / 🔒 1519 — absent and blank are different answers, and that distinction is the whole fix.
        $configured = array_key_exists(self::PARTIAL_STAGE_KEY, $config);
        $stage = trim((string) ($config[self::PARTIAL_STAGE_KEY] ?? ''));

        if ($configured && $stage === '') {
            // An explicit "leave it alone".
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
            // A default is not a licence to write an unknown stage.
            $GLOBALS['log']->warn('ErpOpportunityValuation: ' . self::CONFIG_CATEGORY . '.'
                . self::PARTIAL_STAGE_KEY . ' is ' . var_export($stage, true)
                . ($usingDefault ? ' (this package\'s shipped default)' : '')
                . ' which is not a sales_stage_dom key - ignoring it');
            return ['stage' => '', 'reason' => 'invalid'];
        }

        if ($usingDefault) {
            // Not silent. G305 was found only because two tenants behaved differently and neither of them said anything.
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
     * Probability for the partial stage: the explicit setting if present, else what sales_probability_dom says for that stage.
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

    /** Application dropdowns for the request user's current language. */
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

    /** The Opportunity this quote is linked to (quotes_opportunities), or null. */
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

        // Link2::get() callers must not assume numeric keys.
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
     * after_save on Quotes. Keeps the opportunity's ERP rollup fields current as the quote is built, not only when a release is raised - a rep adding.
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
     * Publish this primary quote onto its Opportunity as two numbers: what the customer has committed to. (🔒 1468, 🔒 1632, G336)
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

        // Read fresh (quoteLines() makes Link2 drop the beans an earlier walk in this request materialised) so a release that has just stamped erp_ordered is seen.
        $lines = $this->quoteLines($quote);
        $rollup = ErpQuoteLineRollup::compute($lines, self::configuredGroupPolicy());

        $total = $quote->total ?? null;
        $open = null;
        // 🔒 1697: Ordered (Won) comes from orderedFigure(), the same function the close contribution.
        $ordered = $this->inOpportunityCurrency(
            self::orderedFigure($total, (float) $rollup['ordered'], $quote->new_sub ?? null),
            $quote,
            $opportunity
        );
        if ($ordered === null) {
            return;
        }
        if (self::isStatedTotal($total)) {
            // 🔒 1632 (G336): the Grand Total is split by the ordered share of the lines it was built from, so the header net.
            $split = self::splitGrandTotal((float) $total, (float) $rollup['ordered'], $quote->new_sub ?? null);
            $grand = $this->inOpportunityCurrency($split['ordered'] + $split['open'], $quote, $opportunity);
            if ($grand === null) {
                return;
            }
            $open = max(0.0, round($grand - $ordered, 2));
        }
        // else: no Grand Total to split. orderedFigure() answered with the ordered fact (the plain line sum), exactly as before 1632.

        $changed = false;
        if ((float) ($opportunity->erp_ordered_amount ?? 0) !== $ordered) {
            $opportunity->erp_ordered_amount = $ordered;
            $changed = true;
        }

        // The reason this Opportunity's open figure is held, or '' when it is current.
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
     * 🔒 1632 (G336) — the grand total, split by the ordered share of its lines. (🔒 1468)
     * @param float $grandTotal
     * @param float $orderedLines
     * @param mixed $linesTotal
     * @return array{ordered:
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
     * 🔒 1697 (owner, 2026-09-23, G389) — the one ordered figure. (🔒 149, 🔒 1632)
     * @param mixed $grandTotal
     * @param float $orderedLines
     * @param mixed $linesTotal
     * @return float
     */
    public static function orderedFigure($grandTotal, float $orderedLines, $linesTotal): float
    {
        if (!self::isStatedTotal($grandTotal)) {
            return $orderedLines;
        }

        return self::splitGrandTotal((float) $grandTotal, $orderedLines, $linesTotal)['ordered'];
    }

    /**
     * Is this Quotes.total a number to split? (🔒 1697)
     * @param mixed $total
     */
    private static function isStatedTotal($total): bool
    {
        return !is_bool($total) && is_numeric($total);
    }

    /** This quote's line items in the shape ErpQuoteLineRollup expects. (decision 789, G89) */
    public static function quoteLines(SugarBean $quote): array
    {
        $lines = array();
        // G466: read once per quote; see the 'shipping' key below.
        $shippingCounts = self::shippingCounts($quote);

        $quote->load_relationship('product_bundles');
        if (!$quote->product_bundles || !is_object($quote->product_bundles)) {
            return $lines;
        }

        // Read the lines as they are now, not as something earlier in this request happened to see them.
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
                    // What to call this line when a human has to read it.
                    'label' => self::lineLabel($product),
                    'quantity' => (float) ($product->quantity ?? 0),
                    'price' => (float) ($product->discount_price ?? 0),
                    // The concession the seller gave on this line, in document currency. (G89)
                    'discount' => self::lineDiscount($product),
                    'ordered' => !empty($product->erp_ordered),
                    // Which ERP order took this line, as an identity. (🔒 1697)
                    'order_num' => trim((string) ($product->erp_ordered_order_num ?? '')),
                    // This line's own ERP-stated charges. null means the ERP has not stated one for this line, which is not the same as a stated 0.00.
                    'tax' => self::statedCharge($product->erp_tax_amount ?? null),
                    // G466 (🔒 1764b): shipping counts on an advanced quote only.
                    'shipping' => $shippingCounts
                        ? self::statedCharge($product->erp_shipping_amount ?? null)
                        : null,
                    'governing' => !empty($product->erp_governing),
                    // Where the line came from decides what it can be an alternative to.
                    'origin' => empty($product->erp_sync_key) ? 'sugar' : 'erp',
                );
            }
        }

        return $lines;
    }

    /**
     * What the ERP states for the whole document, with no view about which part of it anyone is asking about. (🔒 1468, 🔒 1450)
     * @return array{applicable:
     */
    public static function statedDocumentCharges(SugarBean $quote, array $lines = array()): array
    {
        $inert = array('applicable' => false, 'amount' => 0.0, 'refusal' => null);

        // User ruling: "in sugar summerize the shippping and tax from the lines if there are there ... or take the line = 0 if its there for shipping".
        $lineTotal = self::statedLineCharges($lines);
        if ($lineTotal !== null) {
            return array('applicable' => true, 'amount' => $lineTotal, 'refusal' => null);
        }

        // State 1. Neither field declared -> this instance carries no ERP charge statement and never did.
        if (!isset($quote->field_defs[self::ERP_TAX_FIELD])
            || !isset($quote->field_defs[self::ERP_SHIPPING_FIELD])
        ) {
            return $inert;
        }

        // A quote nobody synced has no ERP statement to source from, and the ruling is about reconciling two ERP-derived figures.
        if (!isset($quote->field_defs[self::ERP_ORIGIN_FIELD])
            || trim((string) ($quote->erp_sync_key ?? '')) === ''
        ) {
            return $inert;
        }

        $tax = self::statedCharge($quote->erp_tax_amount ?? null);
        // G466 (🔒 1764b): a quote that is not an advanced quote has no shipping by ruling - a 0.00, never "unstated" - the Grand Total's own shipping term. (🔒 1450, 🔒 1468)
        $shipping = self::shippingCounts($quote)
            ? self::statedCharge($quote->erp_shipping_amount ?? null)
            : 0.0;

        // State 2. Offered and not answered.
        if ($tax === null || $shipping === null) {
            return array(
                'applicable' => true,
                'amount' => 0.0,
                'refusal' => 'erp_charges_unstated',
            );
        }

        // State 3.
        return array(
            'applicable' => true,
            'amount' => $tax + $shipping,
            'refusal' => null,
        );
    }

    /**
     * One quote line's discount, in document currency - Sugar's two spellings of one concession, resolved into the single number ErpQuoteLineRollup takes.
     * @param SugarBean|object $product
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
     * The lines' own charges, summed - or null when no line states one. (G405, 🔒 1468)
     * @param array $lines
     */
    private static function statedLineCharges(array $lines): ?float
    {
        return \Sugarcrm\Sugarcrm\custom\Erp\ErpStatedCharges::statedSum($lines, array('tax', 'shipping'));
    }

    /** G466 (🔒 1758b / 🔒 1764b): does shipping count on this quote? (🔒 1468) */
    private static function shippingCounts(SugarBean $quote): bool
    {
        $type = $quote->{self::QUOTE_TYPE_FIELD} ?? null;

        return is_scalar($type) && (string) $type === self::ADVANCED_QUOTE;
    }

    private static function statedCharge($value): ?float
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /** Can this Opportunity actually store a refusal reason? */
    private function refusalStorable(SugarBean $opportunity): bool
    {
        return isset($opportunity->field_defs[self::REFUSAL_FIELD]);
    }

    /** How lines sharing a part number combine on the open side: 'sum'. */
    public static function configuredGroupPolicy(): string
    {
        $config = self::erpIntegrationConfig();
        $policy = trim((string) ($config[self::GROUP_POLICY_KEY] ?? ''));

        return $policy === '' ? ErpQuoteLineRollup::POLICY_SUM : $policy;
    }

    /**
     * $amount expressed in the opportunity's currency, or null when it cannot be converted (then nothing is written, same as the total conversion).
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
