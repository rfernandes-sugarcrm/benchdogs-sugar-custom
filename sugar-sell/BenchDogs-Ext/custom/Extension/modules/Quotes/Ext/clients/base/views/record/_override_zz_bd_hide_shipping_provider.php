<?php

// The quote record and create views draw no Shipping Provider on Bench Dogs; the field and its data stay.
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Quotes']['base']['view']['record']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdHiddenFields')) {
    $viewdefs['Quotes']['base']['view']['record']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::strip($viewdefs['Quotes']['base']['view']['record']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::QUOTE_UNSYNCED_FIELDS, array());
}
