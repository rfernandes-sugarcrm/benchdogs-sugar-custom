<?php

require_once 'custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php';

/**
 * Re-run the Quote-owned amount/forecast path when a reflected Kinetic line
 * changes or becomes linked to its ERP quote.
 *
 * The shared Quote hook remains the sole Opportunity amount writer. This
 * customer hook only makes sure that writer sees the current native Quote
 * lines before Bench refreshes its forecast provenance and stage policy.
 */
class BdQuoteLineRefreshHook
{
    private const VALUE_FIELDS = [
        'doc_ext_price',
        'selling_qty',
        'part_num',
        'prototype',
        'governing',
    ];

    public function refreshQuoteContribution(SugarBean $bean, string $event, array $arguments): void
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
            (new BdQuoteReflectionHook())->refreshOpportunityAmount($erpQuote);
        } catch (Throwable $e) {
            $GLOBALS['log']->error(
                'BdQuoteLineRefreshHook: failed refreshing Quote contribution for line '
                . $bean->id . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * The connector creates a mirror line first and links it later. The link
     * event is therefore the first moment the parent can be resolved reliably.
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
                'BdQuoteLineRefreshHook: failed refreshing Quote contribution after linking line '
                . $bean->id . ': ' . $e->getMessage()
            );
        }
    }

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
        return ($erpQuote && !empty($erpQuote->id)) ? $erpQuote : null;
    }
}
