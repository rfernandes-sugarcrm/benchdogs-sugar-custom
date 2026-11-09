<?php

// Since rc116: the quote line grid shows each line's Product Group (its stock category, synced from Epicor), read-only, after Part Number.
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Products']['base']['view']['quote-data-group-list']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')) {
    $viewdefs['Products']['base']['view']['quote-data-group-list']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::gridColumn($viewdefs['Products']['base']['view']['quote-data-group-list']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::GRID_PRODUCT_GROUP, \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::GRID_PRODUCT_GROUP_AFTER);
}
