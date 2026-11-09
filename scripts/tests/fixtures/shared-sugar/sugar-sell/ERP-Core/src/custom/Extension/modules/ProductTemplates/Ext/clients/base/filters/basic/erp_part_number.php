<?php

// G582: the quote-line relate picker uses ProductTemplates' quick-search metadata. getSearchFields() only controls returned columns, not matching.
if (!in_array('mft_part_num', $viewdefs['ProductTemplates']['base']['filter']['basic']['quicksearch_field'], true)) {
    $viewdefs['ProductTemplates']['base']['filter']['basic']['quicksearch_field'][] = 'mft_part_num';
}
