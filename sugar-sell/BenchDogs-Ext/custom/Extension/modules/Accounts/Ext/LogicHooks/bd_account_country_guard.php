<?php

/**
 * Refuse saving an Account billing country that no Epicor country matches
 * (Bench Dogs REQ-15, option c).
 *
 * Registration only - the class lives at custom/modules/Accounts/ (outside this
 * Ext/ tree) on purpose. Sugar's Ext-merge concatenates this file's raw content
 * into the compiled logichooks.ext.php, and LogicHook::loadHookClass()
 * separately require_once()s the filename below at hook-fire time; a class
 * declared in this file would be declared twice and fatal with "Cannot
 * redeclare class" (same reason as bd_governing_line.php).
 */
$hook_array['before_save'][] = array(
    1,
    'Refuse an Account billing country unknown to Epicor',
    'custom/modules/Accounts/BdAccountCountryGuard.php',
    'BdAccountCountryGuard',
    'refuseUnknownCountry',
);
