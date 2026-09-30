<?php

// G848 (🔒2151b): the quote grid draws no Line Discount (discount_field) column or edit-row input.
if (is_array($viewdefs['Products']['base']['view']['quote-data-group-list']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdHiddenFields')) {
    $viewdefs['Products']['base']['view']['quote-data-group-list']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::strip($viewdefs['Products']['base']['view']['quote-data-group-list']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::LINE_FIELDS, array());
}
