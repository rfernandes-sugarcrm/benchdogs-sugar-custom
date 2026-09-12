<?php

/**
 * Keep the native Quote contribution current when a reflected Kinetic line
 * changes. Priority 2 runs after single-governing enforcement settles roles.
 */
$hook_array['after_save'][] = array(
    2,
    'Refresh the native Quote contribution on ERP quote line changes',
    'custom/modules/bd01_ERP_Quote_Line/BdQuoteLineRefreshHook.php',
    'BdQuoteLineRefreshHook',
    'refreshQuoteContribution',
);

/**
 * The connector creates a line and links its ERP quote in separate calls, so
 * the relationship event completes the same refresh for newly linked lines.
 */
$hook_array['after_relationship_add'][] = array(
    2,
    'Refresh the native Quote contribution when a line is linked',
    'custom/modules/bd01_ERP_Quote_Line/BdQuoteLineRefreshHook.php',
    'BdQuoteLineRefreshHook',
    'refreshOnLink',
);
