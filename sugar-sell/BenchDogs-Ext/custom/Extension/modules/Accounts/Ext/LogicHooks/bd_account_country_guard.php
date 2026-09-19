<?php

/**
 * RETIRED. This file intentionally registers no hook.
 *
 * It used to register BdAccountCountryGuard on Accounts.
 *
 * ERP-Core owns the billing-country guard and its hook IS registered and live:
 * ERP-Core/src/custom/modules/Accounts/ErpAccountCountryGuard.php, wired by
 * ERP-Core/src/custom/Extension/modules/Accounts/Ext/LogicHooks/
 * erp_account_country_guard.php. Core's guard reads the country lookup types
 * from a REGISTRY rather than naming any one package's rows, which is exactly
 * what lets a customer package publish its own country rows and be picked up
 * by the same check — no second guard required.
 *
 * TWO GUARDS ON ONE FIELD IS WORSE THAN ONE. They can disagree, and the seller
 * sees whichever ran last with no way to tell which rule refused them.
 *
 * WHY EMPTIED RATHER THAN DELETED. §CW / G37 — on Sugar Cloud, dropping a
 * custom/Extension file from the build leaves the previously-installed copy in
 * place and the package INERT (rc24, Bench, 2026-09-14). Only overwriting it
 * retires it.
 */
