<?php

// G848 (🔒2151b): the totals strip draws no Order Level Discount (deal_tot) (🔒 1544a).
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Quotes']['base']['view']['quote-data-grand-totals-header']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdHiddenFields')) {
    $viewdefs['Quotes']['base']['view']['quote-data-grand-totals-header']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::strip($viewdefs['Quotes']['base']['view']['quote-data-grand-totals-header']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::QUOTE_FIELDS, \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::QUOTE_PANELS);
}
