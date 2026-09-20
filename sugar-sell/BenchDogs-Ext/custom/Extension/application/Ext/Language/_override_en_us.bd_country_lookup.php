<?php

/**
 * RETIRED (G50). This file intentionally defines no labels.
 *
 * It defined one list entry:
 *
 *   $app_list_strings['erp_lookup_type_list']['bd_country'] = 'Country (Bench Dogs)';
 *
 * the display name for the ERP_LookupValues type this package published for
 * REQ-15 option (c) — one row per Epicor country, read by
 * custom/modules/Accounts/BdAccountCountryGuard.php.
 *
 * WHY IT GOES. NOTHING IN THIS PACKAGE READS THOSE ROWS ANY MORE. Bench's
 * guard is registered by nothing: its logic hook,
 * custom/Extension/modules/Accounts/Ext/LogicHooks/bd_account_country_guard.php,
 * is itself a retirement stub, because ERP-Core owns the billing-country check
 * (ErpAccountCountryGuard) and two guards on one field can disagree with the
 * seller seeing whichever ran last. A type label is the last thing that made
 * the retired rows look supported — it is what puts "Country (Bench Dogs)" in
 * the ERP_LookupValues list view, its filters and the report field chooser,
 * which is the same reason every 🔒 1044 field label was emptied.
 *
 * ORDER MATTERS, AND THIS IS THE FIRST HALF. The rows themselves are DATA on
 * the tenant, not files in this package: 12 ERP_LookupValues records of type
 * `bd_country`. This fragment and the test that pinned it had to be retired
 * before those rows could be, or the next install would republish the label
 * over them. Until the rows are removed they render with the raw type value
 * `bd_country` instead of a name; they are read by nothing either way.
 *
 * WHY EMPTIED RATHER THAN DELETED, AND WHY THE NAME IS UNCHANGED. §CW / G37 —
 * on Sugar Cloud, dropping a custom/Extension file from the build leaves the
 * previously-installed copy in place and the retirement INERT (rc24, Bench,
 * 2026-09-14). Only overwriting the SAME PATH retires it, so the `_override_`
 * prefix and the `en_us` in the name stay exactly as they were: they are what
 * makes this file land on top of the copy a tenant already has, and what made
 * the original entry survive ERP-Epicor's whole-array `erp_lookup_type_list`
 * assignments (rc23).
 *
 * Safe to delete this stub outright once every instance has taken a release at
 * or after this one.
 */
