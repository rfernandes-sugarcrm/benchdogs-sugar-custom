<?php

// Expires Date off this view where a stored layout draws it: Valid Until is the same date (BdFieldPlacement).
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Quotes']['base']['view']['recorddashlet']['panels'] ?? null)
    && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')) {
    $viewdefs['Quotes']['base']['view']['recorddashlet']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::takenOff(
        $viewdefs['Quotes']['base']['view']['recorddashlet']['panels'],
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_TAKEN_OFF
    );
}
