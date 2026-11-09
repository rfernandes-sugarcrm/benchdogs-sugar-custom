<?php

// Since rc117: the quote line grid shows each line's own Project, editable, after Product Group.
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Products']['base']['view']['quote-data-group-list']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement')) {
    $viewdefs['Products']['base']['view']['quote-data-group-list']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::gridColumn($viewdefs['Products']['base']['view']['quote-data-group-list']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::GRID_LINE_PROJECT, \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::GRID_LINE_PROJECT_AFTER);
}
