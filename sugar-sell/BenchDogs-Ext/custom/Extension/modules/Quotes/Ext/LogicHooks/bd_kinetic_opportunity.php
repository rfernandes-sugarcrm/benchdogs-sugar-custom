<?php

/**
 * 🛑 G243 / 🔒 1499 — THE QUOTE -> OPPORTUNITY PAIRING WRITER IS RETIRED.
 * THIS FILE REGISTERS NOTHING, AND THAT EMPTINESS IS THE WHOLE FIX.
 *
 * ============ WHAT THIS FILE USED TO DO ============
 *
 * Up to and including 0.9.42-rc39/rc40 (built from `fe18b38`/`5d74e9b`, branches
 * `build/0.9.42-rc39-d314-on-rc38` and `build/0.9.42-rc40-estimating-retired`,
 * neither of which was ever merged to main) this same path shipped TWO
 * registrations:
 *
 *   after_save            prio 2  BdKineticOpportunityHook::pairOnSave
 *   after_relationship_add prio 2  BdKineticOpportunityHook::pairOnAccountLink
 *
 * Between them they CREATED and LINKED a brand-new Opportunity for every
 * Kinetic-born quote above a history floor - the relocated
 * `BdQuoteReflectionHook::ensureOpportunity()` from the retired `bd01_ERP_Quote`
 * mirror.
 *
 * ============ WHY IT IS GONE, AND WHY IT HAD TO GO FROM HERE ============
 *
 * 🔒 1499 (2026-09-22), the owner's answer in full: *"YES — the sync must never
 * create one"*, confirming 🔒 1473 *"No, quote only"*. An Opportunity is a
 * FORECASTABLE object; one raised per synced ERP quote inflates the pipeline
 * with deals no seller made, no seller owns, and named after a Kinetic quote
 * number. G243 measured it on Bench read-only at 06:51Z: quotes 317
 * (`EPIC06__1269`) and 318 (`EPIC06__1270`) created 22:13:14 by
 * `svc erpconnector.bench`, each carrying an Opportunity
 * "Dalton Manufacturing - Kinetic Quote <num>" created by the SAME account at
 * 22:13:20 - six seconds later, because the header create and the
 * `billing_accounts` link are SEPARATE connector calls and `pairOnAccountLink`
 * is the seam that fires on the second one.
 *
 * 🚩 NOT SHIPPING THE FILE WOULD HAVE REMOVED NOTHING. rc60 already ships no
 * `custom/Extension/modules/Quotes/Ext/LogicHooks/*` at all, and the pairing
 * kept running on Bench anyway: Module Loader COPIES a package's files, it does
 * not delete the previous version's, so rc39's registration and class sat on
 * disk and stayed compiled into `custom/modules/Quotes/Ext/LogicHooks/
 * logichooks.ext.php`. This is rc24's lesson written down in
 * `test_create_opp_quote_button_retired.py` - **ONLY OVERWRITING RETIRES** -
 * and it is why this file exists as an empty registration rather than as an
 * absence. `install_copy` overwrites rc39's copy with this one and
 * `install_extensions` (which runs BEFORE `post_execute`, SugarEnt 26.1.0
 * `ModuleInstall/ModuleInstaller.php:261`) then rebuilds the compiled hook file
 * without either entry.
 *
 * 🚩 AND `unlink()` WAS NOT AN OPTION, which is why the retirement is a copy and
 * not a sweep: `ModuleScanner`'s function blacklist carries `unlink` and `rmdir`
 * (26.1.0 `ModuleInstall/ModuleScanner.php:199,204`) and its method blacklist
 * carries `unlink` for `SugarAutoLoader` (:540). A package that deletes the file
 * does not install at all.
 *
 * ============ WHAT DELIBERATELY DID NOT CHANGE ============
 *
 * 🛑 THE SELLER'S BUTTON IS THE CONTROL AND IT IS UNTOUCHED. A quote a person
 * raises through "Create Opportunity & Quote" must still get its Opportunity.
 * That button is ERP-Epicor's (`erp_create_opp_quote_button` ->
 * `AccountsErpActionsApi::createOppQuote`); Bench's own duplicate was retired by
 * 🔒 1044 / G15. Neither creator has ever routed through this hook - they build
 * the Opportunity bean inline in their own action handler, under a caller's ACL
 * check - so an empty registration here cannot reach them.
 *
 * ⚠️ THE OPPORTUNITIES THIS HOOK ALREADY CREATED ON BENCH ARE NOT TOUCHED by
 * this change or by anything in this package. Leaving them keeps the forecast
 * inflated; deleting them strands their synced quotes. That is an owner
 * decision that 🔒 1499 explicitly does NOT cover, and it is tenant data, not
 * code.
 *
 * 📌 DO NOT "TIDY THIS FILE AWAY". Deleting it re-arms the defect on every
 * tenant that ever installed rc39 or rc40, silently, with no diff to point at.
 * `scripts/tests/test_g243_kinetic_opportunity_pairing_retired.py` fails if it
 * goes missing or gains a hook entry.
 */

// Intentionally no $hook_array entries. See above.
