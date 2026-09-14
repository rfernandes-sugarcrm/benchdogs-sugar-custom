<?php

/**
 * The ONLY automatic route into decision 72's auto-selection. Registration is
 * in custom/Extension/modules/bd01_ERP_Quote_Line/Ext/LogicHooks/
 * bd_governing_autoselect.php; the class lives out here for the same
 * "Cannot redeclare class" reason bd_governing_line.php explains at length.
 *
 * THIS FILE IS THE BLAST RADIUS, so it is written to be read as one.
 *
 * FORWARD ONLY, AND STRUCTURALLY SO
 *
 * `autoSelectOnNewLine()` returns immediately unless `$arguments['isUpdate']`
 * is false. SugarBean::save() computes that from SugarBean::isUpdate(), which
 * is false only when the row has no id yet or carries `new_with_id`
 * (SugarEnt-Full 26.1.0, data/SugarBean.php:1849-1857 and :2063-2067) - i.e.
 * only for a line being CREATED. A line that already exists on a tenant is
 * never created again, so no quantity break already sitting in the database
 * can be reached through this hook, on any sync, ever. That is not a policy
 * this file chooses; it is a property of the event it listens to.
 *
 * The consequences worth stating, because they are what an operator needs:
 *
 *   - Installing the package selects nothing. No installdef, no post_execute
 *     block and no schedule calls BdGoverningAutoSelect.
 *   - A connector resync that re-saves existing lines selects nothing: every
 *     one of those saves is an update.
 *   - A NEW Kinetic quote arriving from here on gets exactly one governing
 *     line and therefore an Opportunity estimate, which is the behaviour
 *     decision 72 asked for.
 *   - Retro-selecting the lines already on a tenant is a SEPARATE, deliberate
 *     act: scripts/bd_governing_backfill.php, dry-run by default, wired to
 *     nothing.
 *
 * WHY THE ROLLUP PATH IS NOT USED
 *
 * BdQuoteReflectionHook::refreshOpportunityAmount() is the funnel every
 * valuation trigger converges on, and calling the auto-selector from there
 * would have been one line. It is deliberately not done: that funnel fires on
 * every bd01_ERP_Quote save, so the first connector resync after an install
 * would auto-select every unselected quote on the tenant at once. On the Bench
 * QA tenant that is all 230 ERP quote lines and the Opportunities behind them
 * - a bulk commercial-data rewrite nobody approved, on the evidence base the
 * release is being graded against.
 *
 * WHY THE RELATIONSHIP EVENT IS ALSO NEEDED
 *
 * The connector creates a line and links it to its ERP quote in SEPARATE
 * calls (the same reason bd_quote_line_refresh.php registers an
 * after_relationship_add). At after_save time a freshly created line usually
 * has no parent quote to select within yet, so the create event alone would
 * leave Kinetic-born quotes unselected forever. `autoSelectOnLink()` closes
 * that, and because BdGoverningAutoSelect only ever moves a selection it made
 * itself, a re-link of an already-selected quote is a no-op.
 */
class BdGoverningAutoSelectHook
{
    /**
     * Re-entrancy guard. Setting `governing` saves a line, which fires this
     * same hook; nothing in that cascade may start a second selection.
     */
    private static bool $inProgress = false;

    /**
     * bd01_ERP_Quote_Line::after_save, priority 3 - after D29-R1's
     * single-governing enforcement (1) and the contribution refresh (2) have
     * settled, so this only ever sees a quote whose roles are already stated.
     */
    public function autoSelectOnNewLine(SugarBean $bean, string $event, array $arguments): void
    {
        // The catch is HERE, on the registered entry point, not only around
        // the worker: a logic hook that throws fails the unrelated save that
        // triggered it, and a failed auto-selection must never cost a
        // connector its line. The quote is then left exactly as decision 29
        // left it - unselected, refusing - which the product already handles.
        try {
            // THE forward-only gate. See the class docblock: this single
            // condition is what makes the change unable to touch a row that
            // already exists on a tenant.
            if (!empty($arguments['isUpdate'])) {
                return;
            }
            $this->run($bean);
        } catch (Throwable $e) {
            $this->failed($bean, $e);
        }
    }

    /**
     * bd01_ERP_Quote_Line::after_relationship_add, priority 3. Only the link
     * to the parent ERP quote matters; every other relationship is ignored.
     */
    public function autoSelectOnLink(SugarBean $bean, string $event, array $arguments): void
    {
        try {
            if (($arguments['link'] ?? '') !== 'bd01_erp_quote_lines') {
                return;
            }
            $this->run($bean);
        } catch (Throwable $e) {
            $this->failed($bean, $e);
        }
    }

    private function failed(SugarBean $bean, Throwable $e): void
    {
        $GLOBALS['log']->error(
            'BdGoverningAutoSelectHook: auto-selection failed for line '
            . $bean->id . ': ' . $e->getMessage()
        );
    }

    private function run(SugarBean $bean): void
    {
        if (self::$inProgress) {
            return;
        }
        self::$inProgress = true;
        try {
            $file = 'custom/modules/bd01_ERP_Quote_Line/BdGoverningAutoSelect.php';
            if (!class_exists('BdGoverningAutoSelect', false)) {
                if (!file_exists($file)) {
                    return;
                }
                require_once $file;
            }
            if (!BdGoverningAutoSelect::enabled()) {
                return;
            }
            $erpQuote = $this->parentErpQuote($bean);
            if ($erpQuote === null) {
                // Normal on a create: the connector links the quote afterwards,
                // and autoSelectOnLink() picks the quote up then.
                return;
            }
            $verdict = (new BdGoverningAutoSelect())->applyToQuote($erpQuote);
            if (!in_array($verdict['action'], array('selected', 'reselected'), true)) {
                return;
            }
            // Value the deal from the line just chosen, through the exact
            // rollup the reflection hook owns - one code path, one set of
            // gates. This is also what stamps the Opportunity marker.
            $reflection = 'custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php';
            if (!class_exists('BdQuoteReflectionHook', false) && file_exists($reflection)) {
                require_once $reflection;
            }
            if (class_exists('BdQuoteReflectionHook', false)) {
                (new BdQuoteReflectionHook())->refreshOpportunityAmount($erpQuote);
            }
        } finally {
            // Only the guard is released here; the catch lives on each
            // registered entry point, where it protects the triggering save.
            self::$inProgress = false;
        }
    }

    /** The parent bd01_ERP_Quote, exactly as BdGoverningLineHook reads it. */
    private function parentErpQuote(SugarBean $bean): ?SugarBean
    {
        $bean->load_relationship('bd01_erp_quote_lines');
        if (!$bean->bd01_erp_quote_lines || !is_object($bean->bd01_erp_quote_lines)) {
            return null;
        }
        $quoteIds = $bean->bd01_erp_quote_lines->get();
        $quoteId = $quoteIds[0] ?? '';
        if ($quoteId === '') {
            return null;
        }
        $erpQuote = BeanFactory::retrieveBean('bd01_ERP_Quote', $quoteId);
        if (!$erpQuote || empty($erpQuote->id)) {
            return null;
        }
        return $erpQuote;
    }
}
