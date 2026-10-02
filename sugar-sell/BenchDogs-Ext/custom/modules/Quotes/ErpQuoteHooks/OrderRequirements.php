<?php

use Sugarcrm\Sugarcrm\custom\BenchDogs\BdAdmOrderRequirements;

/** Implements ERP-Epicor's OrderRequirements hook contract (G860, 🔒2179b). */
class ErpQuoteOrderRequirementsHook
{
    public function missing($quote, array $lines): array
    {
        try {
            return BdAdmOrderRequirements::missing($quote, $lines);
        } catch (\Throwable $e) {
            // Fails open: the connector extension checks the Ship Via again before the ERP is called (G829, G852).
            $GLOBALS['log']->error('BenchDogs-Ext: ADM order requirements skipped for quote '
                . (string) ($quote->id ?? '') . ': ' . $e->getMessage());

            return array();
        }
    }
}
