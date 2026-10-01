<?php

// G848 (🔒2151b): the Quotes list preview draws no Order Discount (deal_tot) (🔒2159b).
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Quotes']['base']['view']['preview']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdHiddenFields')) {
    $viewdefs['Quotes']['base']['view']['preview']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::strip($viewdefs['Quotes']['base']['view']['preview']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::QUOTE_FIELDS, \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::QUOTE_PANELS);
}
