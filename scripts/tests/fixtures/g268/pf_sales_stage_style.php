<?php

// G278 / 🔒 1506 — the Sidecar presentation for the two release milestones
// this package adds to sales_stage_dom (see the _override language fragment).
//
// WHY A STYLE IS REQUIRED AND NOT DECORATION. Opportunities renders
// sales_stage with Sugar's enum-cascade field. Where sales_stage_dom_style has
// applyFormatting enabled, a domain key with NO matching style renders as an
// EMPTY detail value even though the model value and its sales_stage_dom label
// are both correct - a stage that reads blank on the record the seller is
// looking at.
//
// 🛑 APPEND-ONLY, GUARDED ON THE KEY. The tenant, ERP-Core and any other
// package own every other stage style, and a tenant that has already styled
// these two keys (or another package that got there first) keeps its own -
// this only fills a gap, it never restyles. Its own filename, deliberately:
// two packages shipping one Ext path is a fight the last installer wins, and
// uninstalling either would take the other's file with it (L-0013).
//
// Prototype Ordered stays an OPEN-pipeline milestone, so it takes Sugar's
// native proposal yellow; Partial Production Ordered takes the native
// successful-milestone green. Both use the stock check-circle icon rather
// than a second visual vocabulary.
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
