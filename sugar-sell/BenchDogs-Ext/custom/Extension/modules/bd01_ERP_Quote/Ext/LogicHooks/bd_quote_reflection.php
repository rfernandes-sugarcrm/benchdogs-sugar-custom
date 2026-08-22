<?php

/**
 * Reflect Bench Dogs ERP quote saves onto the linked Sugar Quote.
 *
 * Registration only - the class lives at custom/modules/bd01_ERP_Quote/
 * (outside this Ext/ tree) on purpose. Sugar's Ext-merge concatenates this
 * entire file's raw content into the compiled logichooks.ext.php, and
 * LogicHook::loadHookClass() separately require_once()s the filename given
 * below at hook-fire time - if the class definition lived in this same file,
 * both of those requires would declare it and the second one fatals with
 * "Cannot redeclare class" (see ERP-Core's
 * order_stage_opportunity_cascade.php for the full story).
 */
$hook_array['after_save'][] = array(
    1,
    'Reflect ERP quote stage/totals onto the linked Sugar Quote',
    'custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php',
    'BdQuoteReflectionHook',
    'reflect',
);
