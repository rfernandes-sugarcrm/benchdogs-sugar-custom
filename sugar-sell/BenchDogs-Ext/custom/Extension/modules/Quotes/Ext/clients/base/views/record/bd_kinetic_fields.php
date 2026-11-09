<?php

// Kinetic's quote fields where the tenant has them (BdFieldPlacement): the Due after Valid Until, the project name on a
// row of its own after the Due, best case, confidence; Expires Date off (Valid Until is the same date). The create form
// draws these panels too. Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Quotes']['base']['view']['record']['panels'] ?? null)
    && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')) {
    $viewdefs['Quotes']['base']['view']['record']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::takenOff(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::place(
            $viewdefs['Quotes']['base']['view']['record']['panels'],
            \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::fieldDefs('Quotes'),
            \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS
        ),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_TAKEN_OFF
    );
}
