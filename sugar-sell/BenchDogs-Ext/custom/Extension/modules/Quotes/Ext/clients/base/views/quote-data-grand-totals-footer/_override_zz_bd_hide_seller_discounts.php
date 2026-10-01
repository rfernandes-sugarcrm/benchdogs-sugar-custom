<?php

// G848 (🔒2151b): the totals footer draws no Order Level Discount row (erp_document_discount_amount).
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Quotes']['base']['view']['quote-data-grand-totals-footer']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdHiddenFields')) {
    $viewdefs['Quotes']['base']['view']['quote-data-grand-totals-footer']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::strip($viewdefs['Quotes']['base']['view']['quote-data-grand-totals-footer']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::QUOTE_FIELDS, \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::QUOTE_PANELS);
}
