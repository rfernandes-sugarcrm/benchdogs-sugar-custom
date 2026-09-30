<?php

// G848 (🔒2151b): the quote record and create views draw no Discount panel and no quote-level discount figure; hide only (decision 803).
if (is_array($viewdefs['Quotes']['base']['view']['record']['panels'] ?? null) && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdHiddenFields')) {
    $viewdefs['Quotes']['base']['view']['record']['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::strip($viewdefs['Quotes']['base']['view']['record']['panels'], \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::QUOTE_FIELDS, \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::QUOTE_PANELS);
}
