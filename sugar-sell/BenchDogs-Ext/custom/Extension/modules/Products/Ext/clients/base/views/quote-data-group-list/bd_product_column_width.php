<?php

// Since rc120: the quote line grid draws its Product column wider (Sugar's widest list column), so more of a long product name shows, in the line's edit row too.
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Products']['base']['view']['quote-data-group-list']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')) {
    $viewdefs['Products']['base']['view']['quote-data-group-list']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::gridColumnWidth($viewdefs['Products']['base']['view']['quote-data-group-list']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::GRID_PRODUCT, \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::GRID_PRODUCT_WIDTH);
}
