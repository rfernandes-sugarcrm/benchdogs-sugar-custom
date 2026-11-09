<?php

// The Product Catalog's list shows each product's Kinetic part number, read-only, after its name.
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['ProductTemplates']['base']['view']['list']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')) {
    $viewdefs['ProductTemplates']['base']['view']['list']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::gridColumn($viewdefs['ProductTemplates']['base']['view']['list']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::CATALOG_PART_NUMBER, \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::CATALOG_PART_NUMBER_AFTER);
}
