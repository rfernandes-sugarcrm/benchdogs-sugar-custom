<?php

// G848 (🔒2151b): the quote line's own record page draws no discount_field.
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Products']['base']['view']['record']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdHiddenFields')) {
    $viewdefs['Products']['base']['view']['record']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::strip($viewdefs['Products']['base']['view']['record']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::LINE_FIELDS, array());
}
