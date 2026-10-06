<?php

// G278 / 🔒 1506 — the Sidecar presentation for the two release milestones this package adds to sales_stage_dom (see the _override language fragment).
if (!array_key_exists(
    'Prototype Ordered',
    $app_dropdowns_style['sales_stage_dom_style'] ?? array()
)) {
    $app_dropdowns_style['sales_stage_dom_style']['Prototype Ordered'] = array(
        'backgroundColor' => '#FEF08A',
        'text' => array(
            'isBold' => true,
            'isItalic' => false,
            'isUnderline' => false,
            'isLineThrough' => false,
            'color' => '#854D0E',
        ),
        'icon' => array(
            'class' => 'check-circle',
            'color' => '#854D0E',
        ),
    );
}

if (!array_key_exists(
    'Partial Production Ordered',
    $app_dropdowns_style['sales_stage_dom_style'] ?? array()
)) {
    $app_dropdowns_style['sales_stage_dom_style']['Partial Production Ordered'] = array(
        'backgroundColor' => '#A7F3D0',
        'text' => array(
            'isBold' => true,
            'isItalic' => false,
            'isUnderline' => false,
            'isLineThrough' => false,
            'color' => '#065F46',
        ),
        'icon' => array(
            'class' => 'check-circle',
            'color' => '#065F46',
        ),
    );
}
