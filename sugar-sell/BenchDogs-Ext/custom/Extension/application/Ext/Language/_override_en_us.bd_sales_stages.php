<?php

// This package's stages, in order, as (default label, probability); every key the integration writes stays a key.
// An administrator's Dropdown Editor list is a plain fragment, so it runs before this one: its added stages, labels and
// probabilities are kept, and a stage of this package's is added, at its place, only where it is missing.
$bdStages = array(
    'Prospecting' => array('Qualifying_Designing', 10),
    'Estimating' => array('Estimating', 20),
    'Proposal/Price Quote' => array('Quote Delivered', 25),
    'Negotiation/Revision' => array('Negotiation/Revision', 50),
    'Verbal commit/PO Pending' => array('Verbal commit/PO Pending', 75),
    'Prototype Ordered' => array('Prototype Ordered', 80),
    'Partial Production Ordered' => array('Partial Production Ordered', 90),
    // The Quote stage's own key, last of the open stages, at 5, which no other stage uses; only a seller sets it.
    'On Hold' => array('On Hold', 5),
    'Closed Won' => array('Closed Won', 100),
    'Closed Lost' => array('Closed Lost', 0),
);
// What Sugar's own lists and ERP-Core's template give these keys: not an administrator's choice, so this package's
// value replaces it. A copy, because a language fragment cannot read another package's files.
$bdShippedLabels = array(
    'Prospecting' => array('Prospecting'),
    'Proposal/Price Quote' => array('Proposal/Price Quote', 'Proposal-Quoting'),
);
$bdShippedProbabilities = array(
    'Proposal/Price Quote' => array('65'),
);

$bdPresent = isset($app_list_strings['sales_stage_dom']) && is_array($app_list_strings['sales_stage_dom'])
    ? $app_list_strings['sales_stage_dom'] : array();
$bdPresentOdds = isset($app_list_strings['sales_probability_dom']) && is_array($app_list_strings['sales_probability_dom'])
    ? $app_list_strings['sales_probability_dom'] : array();

// An added stage goes after the nearest earlier stage of this package's that comes before the next one in this
// package's order, so a key another template moved to the front is no anchor.
$bdRank = array();
foreach (array_keys($bdStages) as $bdAt => $bdKey) {
    $bdRank[$bdKey] = $bdAt;
}
$bdKeys = array_keys($bdPresent);
$bdAfter = array();
foreach ($bdKeys as $bdAt => $bdKey) {
    if (isset($bdRank[$bdKey])) {
        continue;
    }
    $bdNext = null;
    for ($bdI = $bdAt + 1; $bdI < count($bdKeys); $bdI++) {
        if (isset($bdRank[$bdKeys[$bdI]])) {
            $bdNext = $bdRank[$bdKeys[$bdI]];
            break;
        }
    }
    $bdAnchor = '';
    for ($bdI = $bdAt - 1; $bdI >= 0; $bdI--) {
        if (isset($bdRank[$bdKeys[$bdI]]) && ($bdNext === null || $bdRank[$bdKeys[$bdI]] < $bdNext)) {
            $bdAnchor = $bdKeys[$bdI];
            break;
        }
    }
    $bdAfter[$bdAnchor][] = $bdKey;
}

$bdOrder = isset($bdAfter['']) ? $bdAfter[''] : array();
foreach ($bdStages as $bdKey => $bdStage) {
    $bdOrder[] = $bdKey;
    foreach (isset($bdAfter[$bdKey]) ? $bdAfter[$bdKey] : array() as $bdExtra) {
        $bdOrder[] = $bdExtra;
    }
}

// An administrator's own stage keeps its label and its probability, and gets none when it has none.
$bdList = array();
$bdOdds = array();
foreach ($bdOrder as $bdKey) {
    $bdLabel = isset($bdPresent[$bdKey]) ? $bdPresent[$bdKey] : null;
    $bdOdd = isset($bdPresentOdds[$bdKey]) ? $bdPresentOdds[$bdKey] : null;
    if (!isset($bdStages[$bdKey])) {
        $bdList[$bdKey] = $bdLabel;
        if ($bdOdd !== null) {
            $bdOdds[$bdKey] = $bdOdd;
        }
        continue;
    }
    $bdStage = $bdStages[$bdKey];
    $bdShipped = isset($bdShippedLabels[$bdKey]) ? $bdShippedLabels[$bdKey] : array();
    $bdList[$bdKey] = ($bdLabel === null || in_array((string) $bdLabel, $bdShipped, true)) ? $bdStage[0] : $bdLabel;
    $bdShipped = isset($bdShippedProbabilities[$bdKey]) ? $bdShippedProbabilities[$bdKey] : array();
    $bdShipped[] = (string) $bdStage[1];
    $bdOdds[$bdKey] = ($bdOdd === null || in_array((string) $bdOdd, $bdShipped, true)) ? $bdStage[1] : $bdOdd;
}
$app_list_strings['sales_stage_dom'] = $bdList;
$app_list_strings['sales_probability_dom'] = $bdOdds;
unset($bdStages, $bdShippedLabels, $bdShippedProbabilities, $bdPresent, $bdPresentOdds, $bdRank, $bdKeys, $bdAfter, $bdAt, $bdKey, $bdNext, $bdI, $bdAnchor, $bdOrder, $bdStage, $bdExtra, $bdList, $bdOdds, $bdLabel, $bdOdd, $bdShipped);
