<?php

/**
 * Labels for the Quote fields this package still declares.
 *
 * 🔒 1044 trimmed this file. It carried twelve labels; NINE of them named
 * fields that no longer exist — bd_priced_at and bd_governing_line (retired by
 * this release), and bd_comment_pending, bd_comment_text,
 * bd_order_requested_at, bd_comment_requested_at, bd_print_requested_at,
 * bd_print_status and bd_print_link, whose vardefs were retired by earlier
 * releases while their labels were left behind. Measured: zero vardef
 * declarations for any of the nine anywhere under custom/.
 *
 * A label with no field is not inert bookkeeping. Sugar resolves mod_strings
 * independently of vardefs, so an orphan label is what lets a retired field
 * keep rendering a friendly name in Studio, in the report builder's field
 * chooser and in list-view column pickers — the field reads as available and
 * supported when nothing writes it.
 *
 * The three that remain are live TODAY and are themselves slated to move to
 * ERP-Core (bd_erp_total -> a new core quote-total field; bd_erp_stage ->
 * core's erp_estimate_stage; bd_reason_code -> core's erp_reason_code). When
 * each lands in core, delete its line here in the same change that stubs its
 * vardef.
 */

// The panel these three fields sit in. It had NO definition anywhere in the
// tree, so Sugar rendered the key itself as the panel heading — app.lang.get()
// returns the key on a miss (🔒 1416), which is why it read as
// LBL_RECORDVIEW_PANEL_BENCHDOGS on screen rather than as a blank.
// 🛑 Deliberately NOT adding a label for bd_sent_to_estimating_at: that field
// is retired, and the docstring above is explicit that an orphan label makes a
// dead field look supported. It is removed from the panel instead — see
// RETIRED_PANEL_FIELDS in custom/modules/Quotes/BdQuotesLayoutExtensions.php.
$mod_strings['LBL_RECORDVIEW_PANEL_BENCHDOGS'] = 'Bench Dogs';

$mod_strings['LBL_BD_ERP_TOTAL'] = 'ERP Quote Total';
$mod_strings['LBL_BD_ERP_STAGE'] = 'ERP Quote Stage';
$mod_strings['LBL_BD_REASON_CODE'] = 'ERP Reason Code';
