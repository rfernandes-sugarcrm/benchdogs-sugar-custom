<?php

// Since rc117: the quoted line item's record view shows the line's own Project, editable (BdFieldPlacement).
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Products']['base']['view']['record']['panels'] ?? null)
    && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')) {
    $viewdefs['Products']['base']['view']['record']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::place(
        $viewdefs['Products']['base']['view']['record']['panels'],
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::fieldDefs('Products'),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::LINE_PROJECT
    );
}
