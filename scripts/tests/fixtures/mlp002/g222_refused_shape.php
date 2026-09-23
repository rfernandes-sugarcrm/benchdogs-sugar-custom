<?php
// MLP002 fixture: the shape SugarCloud refused in ERP-Epicor 1.1.100 (G222),
// reduced from src/custom/clients/base/api/QuotesErpActionsApi.php at 1db8c9b.
// The verdicts are in expected.json, taken from the real scanner.
// stream_resolve_include_path('in a comment') is not a call.
class QuotesErpActionsApiShape
{
    private function loadQueue()
    {
        $installed = __DIR__ . '/../../../modules/Quotes/ErpQuoteCommentQueue.php';
        $path = file_exists($installed)
            ? $installed
            : stream_resolve_include_path('custom/modules/Quotes/ErpQuoteCommentQueue.php');
        if ($path !== false && $path !== '' && file_exists($path)) {
            require_once $path;
        }
        $why = 'stream_resolve_include_path() is denied'; // in a string
        return function_exists('stream_resolve_include_path');
    }

    private function loadRatchetTheWay373ef0fDoes()
    {
        $installed = __DIR__ . '/../../../modules/Quotes/ErpPricedStageRatchet.php';
        if (file_exists($installed)) {
            require_once $installed;
        } else {
            @include_once 'custom/modules/Quotes/ErpPricedStageRatchet.php';
        }
    }
}
