<?php

/**
 * Decision 72 auto-selection. Registration only - the class lives at
 * custom/modules/bd01_ERP_Quote_Line/ for the "Cannot redeclare class" reason
 * bd_governing_line.php sets out.
 *
 * Priority 3 on purpose: 1 is D29-R1's single-governing enforcement and 2 is
 * the contribution refresh, so by the time this runs the quote's roles are
 * already settled and the auto-selector only ever sees a stated selection.
 *
 * READ BdGoverningAutoSelectHook's DOCBLOCK BEFORE CHANGING EITHER ENTRY
 * POINT. `autoSelectOnNewLine` is gated on `isUpdate === false` and that gate
 * is the whole reason this change cannot reach a line that already exists on a
 * tenant. Registering it for updates too - or moving the call into the rollup
 * funnel - would auto-select every unselected quote on the next connector
 * resync.
 */
$hook_array['after_save'][] = array(
    3,
    'Auto-select the lowest-total governing line on a newly created ERP quote line',
    'custom/modules/bd01_ERP_Quote_Line/BdGoverningAutoSelectHook.php',
    'BdGoverningAutoSelectHook',
    'autoSelectOnNewLine',
);

/**
 * The connector creates a line and links its ERP quote in separate calls, so
 * a created line usually has no parent to select within yet. Same reason
 * bd_quote_line_refresh.php registers a relationship hook.
 */
$hook_array['after_relationship_add'][] = array(
    3,
    'Auto-select the lowest-total governing line when a new line is linked',
    'custom/modules/bd01_ERP_Quote_Line/BdGoverningAutoSelectHook.php',
    'BdGoverningAutoSelectHook',
    'autoSelectOnLink',
);
