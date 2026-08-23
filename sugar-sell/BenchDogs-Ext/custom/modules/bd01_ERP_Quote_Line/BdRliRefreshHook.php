<?php

require_once 'custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php';

/**
 * after_save hook class for bd01_ERP_Quote_Line - see the registration in
 * custom/Extension/modules/bd01_ERP_Quote_Line/Ext/LogicHooks/bd_rli_refresh.php
 * for why this class lives here and not alongside that registration.
 *
 * REQ-6 trigger companion to BdQuoteReflectionHook: the header reflection
 * only re-materializes the deliverable RLIs when a bd01_ERP_Quote field
 * changes, but a Kinetic revision can land as line-row PATCHes alone
 * (re-priced doc_ext_price, a moved prototype/governing role). This hook
 * forwards those line saves into the same materialization path, through
 * the same gates (sugar_quote_id + erp_is_primary_quote).
 */
class BdRliRefreshHook
{
    /**
     * Line fields that feed the deliverable RLIs. Anything else changing
     * (costs, stage mirrors, link maintenance) is not this hook's business.
     */
    private const VALUE_FIELDS = [
        'doc_ext_price',
        'selling_qty',
        'part_num',
        'prototype',
        'governing',
    ];

    public function refreshDeliverables(SugarBean $bean, string $event, array $arguments): void
    {
        try {
            $relevant = false;
            foreach ($arguments['dataChanges'] ?? [] as $change) {
                if (in_array($change['field_name'] ?? '', self::VALUE_FIELDS, true)
                    && ($change['before'] ?? null) !== ($change['after'] ?? null)
                ) {
                    $relevant = true;
                    break;
                }
            }
            if (!$relevant) {
                return;
            }

            $erpQuote = $this->parentErpQuote($bean);
            if ($erpQuote === null) {
                return;
            }

            // refreshOpportunityAmount carries its own re-entrancy guard, so
            // the cascade this save may already be part of (reflection,
            // governing enforcement) collapses to a single materialization.
            (new BdQuoteReflectionHook())->refreshOpportunityAmount($erpQuote);
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'BdRliRefreshHook: failed refreshing deliverable RLIs for line '
                . $bean->id . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * after_relationship_add on bd01_ERP_Quote_Line: the line just became
     * part of its ERP quote, so re-materialize the deliverables.
     *
     * This is the moment refreshDeliverables() cannot catch. The connector
     * does not create a line WITH its parent link - it creates the row, then
     * links it in a separate call (POST /integrate/{module}/link, see
     * connector-core's sugar_sell.link_by_sync_keys). So at the line's
     * after_save there is no parent yet, parentErpQuote() answers null, and
     * the refresh silently does nothing; the link that completes the picture
     * arrives later and fires no save hook at all.
     *
     * The consequence, measured live on 23 Aug 2026: the header reflection
     * had already run against a line-less quote and claimed the whole
     * quote_total as the production deliverable, and nothing re-valued it -
     * opportunity 49a23488 sat at $24,500 for a $23,750 quote until a human
     * touched a line. "Correct only after someone nudges the record" is not
     * correct.
     *
     * Idempotent by construction: the materialization is an upsert, so the
     * four link events of a four-line quote converge on one answer, and the
     * last one - the only one that sees every line - is the one that decides.
     */
    public function refreshOnLink(SugarBean $bean, string $event, array $arguments): void
    {
        $link = (string) ($arguments['link_name'] ?? $arguments['link'] ?? '');
        $relationship = (string) ($arguments['relationship'] ?? '');
        if ($link !== 'bd01_erp_quote_lines' && $relationship !== 'bd01_erp_quote_lines') {
            return;
        }

        try {
            $erpQuote = $this->parentErpQuote($bean);
            if ($erpQuote === null) {
                return;
            }
            (new BdQuoteReflectionHook())->refreshOpportunityAmount($erpQuote);
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'BdRliRefreshHook: failed refreshing deliverable RLIs on link of line '
                . $bean->id . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * The parent bd01_ERP_Quote of this line - same resolution as
     * BdGoverningLineHook::parentErpQuote.
     */
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
