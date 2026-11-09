<?php

// Bench Dogs' Kinetic fields on this record view where the tenant has them: the quoted line item's Kinetic fields, grouped Cost & capacity under ERP-Epicor's Epicor Cost Worksheet panel (BdFieldPlacement).
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Products']['base']['view']['record']['panels'] ?? null)
    && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')) {
    $viewdefs['Products']['base']['view']['record']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::place(
        $viewdefs['Products']['base']['view']['record']['panels'],
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::fieldDefs('Products'),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::LINE_FIELDS,
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::COST_CAPACITY_PANEL,
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::COST_WORKSHEET_PANEL
    );
}
