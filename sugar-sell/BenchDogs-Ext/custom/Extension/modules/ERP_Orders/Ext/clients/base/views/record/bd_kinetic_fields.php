<?php

// Bench Dogs' Kinetic fields on this record view where the tenant has them: the ERP order's Project Manager and Engineer (BdFieldPlacement).
// The retired copy of the lines total off it, and ERP-Epicor's own ERP Line Total right after ERP Misc. Charges where the stored view lacks it.
// Guarded: the compiled ext outlives the classes for one step of an uninstall.
if (is_array($viewdefs['ERP_Orders']['base']['view']['record']['panels'] ?? null)
    && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')
    && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdKineticFields')) {
    $viewdefs['ERP_Orders']['base']['view']['record']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::place(
        $viewdefs['ERP_Orders']['base']['view']['record']['panels'],
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::fieldDefs('ERP_Orders'),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::ORDER_FIELDS
    );
    $viewdefs['ERP_Orders']['base']['view']['record']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::retire(
        $viewdefs['ERP_Orders']['base']['view']['record']['panels'],
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::fieldDefs('ERP_Orders'),
        array_keys(\Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::RETIRED['ERP_Orders']),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::ORDER_AFTER
    );
}
