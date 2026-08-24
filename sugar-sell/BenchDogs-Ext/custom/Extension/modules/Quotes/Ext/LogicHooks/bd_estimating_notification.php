<?php

/**
 * Notify the estimating owner when a Quote's bd_erp_stage transitions into
 * 'in_estimating' (Bench Dogs REQ-13).
 *
 * Registration only - the class lives at custom/modules/Quotes/ (outside
 * this Ext/ tree) on purpose. Sugar's Ext-merge concatenates this entire
 * file's raw content into the compiled logichooks.ext.php, and
 * LogicHook::loadHookClass() separately require_once()s the filename given
 * below at hook-fire time - if the class definition lived in this same file,
 * both of those requires would declare it and the second one fatals with
 * "Cannot redeclare class" (see ERP-Core's
 * order_stage_opportunity_cascade.php for the full story).
 */
$hook_array['after_save'][] = array(
    2,
    'Notify estimating when a Quote enters the estimating ERP stage',
    'custom/modules/Quotes/BdEstimatingNotificationHook.php',
    'BdEstimatingNotificationHook',
    'notifyEstimating',
);

/**
 * REQ-13's other direction: notify sales when estimating hands the quote
 * back priced. Registration only, same reason as above.
 */
$hook_array['after_save'][] = array(
    3,
    'Notify sales when estimating returns a priced Quote',
    'custom/modules/Quotes/BdEstimatingNotificationHook.php',
    'BdEstimatingNotificationHook',
    'notifyPricingReturned',
);
