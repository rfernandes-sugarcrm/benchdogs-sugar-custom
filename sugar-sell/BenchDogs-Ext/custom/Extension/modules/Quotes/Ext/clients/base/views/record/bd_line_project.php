<?php

// Since rc117 the Project is each line's own: the quote-level one leaves this view, and the quote fetches each line's.
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Quotes']['base']['view']['record']['panels'] ?? null)
    && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')) {
    $viewdefs['Quotes']['base']['view']['record']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::fetchedWithLines(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::retire(
            $viewdefs['Quotes']['base']['view']['record']['panels'],
            array(),
            \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_RETIRED
        ),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_LINE_FETCH,
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdAdmRules::FIELD_PROJECT
    );
}
