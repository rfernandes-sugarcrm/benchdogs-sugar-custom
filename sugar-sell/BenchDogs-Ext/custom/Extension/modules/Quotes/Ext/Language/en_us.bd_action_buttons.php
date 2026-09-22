<?php

/**
 * EMPTIED IN 0.9.42-rc64 — G276 / 🔒 1503 + 🔒 1504.
 *
 * This file carried ten labels, and every one of them named a Bench Dogs
 * QUOTE ACTION that no longer exists: LBL_BD_SEND_ESTIMATING_BUTTON/_RUNNING,
 * LBL_BD_ORDER_WINNING_BUTTON/_RUNNING, LBL_BD_ORDER_SELECTED_BUTTON/_RUNNING/
 * _NONE, LBL_BD_BEST_PRICING_BUTTON/_RUNNING and LBL_BD_LINE_ALREADY_ORDERED.
 * The buttons were retired earlier; rc64 removes the last of the button logic
 * itself, so nothing in this package can render any of these strings.
 *
 * The package's own rule, stated in en_us.bd_erp_fields.php: a label with no
 * thing behind it is not inert bookkeeping. Sugar resolves mod_strings
 * independently, so an orphan label keeps a retired action reading as
 * available and supported in Studio, the report builder's field chooser and
 * column pickers.
 *
 * EMPTIED, NOT DROPPED (§CW / G37, and the bd_country precedent): on Sugar
 * Cloud a file a previous install copied is NOT removed by leaving it out of
 * the build. Only overwriting retires it, so this file must keep shipping.
 *
 * Core owns every one of these actions now: 'Send to Estimation' (🔒 531),
 * Order Selected Lines (ERP-Epicor-PartialFulfillment) and Get Best Price &
 * Availability (ERP-Core), each with its own label in its own package.
 */
