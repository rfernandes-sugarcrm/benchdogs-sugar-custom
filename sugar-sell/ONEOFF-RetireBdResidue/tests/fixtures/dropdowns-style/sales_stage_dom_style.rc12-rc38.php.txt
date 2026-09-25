<?php

/**
 * Native Sidecar presentation for the two Bench release milestones.
 *
 * Opportunities renders sales_stage with Sugar's enum-cascade field. When
 * sales_stage_dom_style has applyFormatting enabled, a domain key without a
 * matching style renders as an empty detail value even though the model and
 * sales_stage_dom label are correct. Keep these entries append-only: the
 * shared ERP package and the tenant own every other stage style.
 *
 * Prototype Closed remains an open-pipeline milestone, so it reuses Sugar's
 * native Proposal yellow. Partial Production Closed uses Sugar's native
 * successful-milestone green. Both use the stock check-circle icon rather
 * than introducing a second visual vocabulary.
 */
if (!array_key_exists(
    'Prototype Closed',
    $app_dropdowns_style['sales_stage_dom_style'] ?? array()
)) {
    $app_dropdowns_style['sales_stage_dom_style']['Prototype Closed'] = array(
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
    'Partial Production Closed',
    $app_dropdowns_style['sales_stage_dom_style'] ?? array()
)) {
    $app_dropdowns_style['sales_stage_dom_style']['Partial Production Closed'] = array(
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
