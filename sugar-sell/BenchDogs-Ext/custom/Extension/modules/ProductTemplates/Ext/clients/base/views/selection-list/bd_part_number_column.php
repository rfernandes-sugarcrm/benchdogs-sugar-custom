<?php

// The Product Catalog's Search and Select list shows each product's Kinetic part number, read-only, after its name.
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['ProductTemplates']['base']['view']['selection-list']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')) {
    $viewdefs['ProductTemplates']['base']['view']['selection-list']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::gridColumn($viewdefs['ProductTemplates']['base']['view']['selection-list']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::CATALOG_PART_NUMBER, \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::CATALOG_PART_NUMBER_AFTER);
}
