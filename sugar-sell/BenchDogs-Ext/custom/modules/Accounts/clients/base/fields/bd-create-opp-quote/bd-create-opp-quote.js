/**
 * RETIRED — this controller is intentionally empty.
 *
 * The button it backed is no longer placed on any layout by this package:
 * BdQuotesLayoutExtensions sets $wanted = [] and lists every one of this
 * package's own buttons in $unwanted, so none is injected and any left in a
 * previously-deployed viewdef is stripped on install.
 *
 * Core owns the capability:
 *   bd-create-opp-quote  -> ERP-Epicor's erp_create_opp_quote_button, whose
 *                           AccountsErpActionsApi is the superset (it owns the
 *                           ETO placeholder part for REQ-20 and types the quote
 *                           for Advanced Quote). Shipping both rendered the
 *                           SAME LABEL TWICE on the Accounts record view (G15).
 *   bd-send-estimating   -> ERP-Core's 'Send to Estimation' (🔒 531).
 *   bd-best-pricing      -> ERP-Core's refresh_price_availability, "Get Best
 *                           Price & Availability". This controller was in any
 *                           case already DEAD: it POSTs to
 *                           Quotes/<id>/bd-best-pricing, an endpoint this
 *                           package does not register.
 *
 * Emptied rather than deleted: §CW / G37 — on Sugar Cloud a file a previous
 * install copied is not removed by dropping it from the build.
 */
({})
