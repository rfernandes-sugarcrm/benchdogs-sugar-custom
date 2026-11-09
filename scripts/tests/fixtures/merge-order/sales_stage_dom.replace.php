<?php

// Owns the Confluence "Opportunity -> Quote -> Order: Stage/Status Management Workflow" doc's 4-stage Opportunity list (Liana Melendez): Prospecting. (G288, decision 1543, G273)
$app_list_strings['sales_stage_dom'] = array(
    'Prospecting' => 'Prospecting',
    'Proposal/Price Quote' => 'Proposal-Quoting',
    'Closed Won' => 'Closed Won',
    'Closed Lost' => 'Closed Lost',
) + array_diff_key(
    $app_list_strings['sales_stage_dom'] ?? array(),
    array(
        'Qualification' => true,
        'Needs Analysis' => true,
        'Value Proposition' => true,
        'Id. Decision Makers' => true,
        'Perception Analysis' => true,
        'Negotiation/Review' => true,
    )
);
