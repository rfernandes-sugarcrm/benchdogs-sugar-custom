<?php

// How the Opportunity record draws Bench Dogs' four stages that Sugar has no style for. Sugar draws sales_stage as a
// formatted enum, which shows a key with no style entry blank. Each is set only when no style for that key exists, so a
// tenant's own choice is kept; the stock and Partial Fulfillment stages keep theirs.
$bdStageStyles = array(
    'Estimating' => array('#E9D5FF', '#6B21A8', 'dashboard-default'),
    'Negotiation/Revision' => array('#D9F99D', '#3F6212', 'message'),
    'Verbal commit/PO Pending' => array('#BFDBFE', '#1E40AF', 'nodes'),
    // Sugar styles On Hold for quotes only; slate with a clock, so a paused deal does not look like the yellow stages.
    'On Hold' => array('#E2E8F0', '#1E293B', 'clock'),
);
foreach ($bdStageStyles as $bdStage => $bdStyle) {
    if (!array_key_exists($bdStage, $app_dropdowns_style['sales_stage_dom_style'] ?? array())) {
        $app_dropdowns_style['sales_stage_dom_style'][$bdStage] = array(
            'backgroundColor' => $bdStyle[0],
            'text' => array(
                'isBold' => true,
                'isItalic' => false,
                'isUnderline' => false,
                'isLineThrough' => false,
                'color' => $bdStyle[1],
            ),
            'icon' => array(
                'class' => $bdStyle[2],
                'color' => $bdStyle[1],
            ),
        );
    }
}
unset($bdStageStyles, $bdStage, $bdStyle);
