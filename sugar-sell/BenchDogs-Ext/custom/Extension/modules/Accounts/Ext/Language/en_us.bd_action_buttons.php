<?php

/**
 * EMPTIED IN 0.9.42-rc64 — G276 / 🔒 1503 + 🔒 1504.
 *
 * LBL_BD_CREATE_OPP_QUOTE_BUTTON and LBL_BD_CREATE_OPP_QUOTE_RUNNING labelled
 * the Bench "Create Opportunity & Quote" button, retired by 🔒 1044 / G15;
 * rc64 removed the last code that could place or remove it. Core's
 * erp_create_opp_quote_button owns the action and carries its own label,
 * LBL_ERP_CREATE_OPP_QUOTE_BUTTON — the two strings were IDENTICAL, which is
 * exactly how the duplicate button reached a seller's screen.
 *
 * EMPTIED, NOT DROPPED: a file a previous install copied stays on a hosted
 * tenant when a later build omits it (§CW / G37). Only overwriting retires it.
 */
