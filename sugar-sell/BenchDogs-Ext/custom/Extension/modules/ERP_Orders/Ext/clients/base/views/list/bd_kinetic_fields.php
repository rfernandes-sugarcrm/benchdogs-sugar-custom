<?php

// The retired copy of the lines total off the ERP Orders list view and its column chooser, where a stored layout still has it (BdFieldPlacement).
// Guarded: the compiled ext outlives the classes for one step of an uninstall.
if (is_array($viewdefs['ERP_Orders']['base']['view']['list']['panels'] ?? null)
    && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')
    && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdKineticFields')) {
    $viewdefs['ERP_Orders']['base']['view']['list']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::retire(
        $viewdefs['ERP_Orders']['base']['view']['list']['panels'],
        array(),
        array_keys(\Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::RETIRED['ERP_Orders'])
    );
}
