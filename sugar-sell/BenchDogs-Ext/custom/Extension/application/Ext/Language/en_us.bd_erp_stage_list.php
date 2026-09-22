<?php

/**
 * RETIREMENT STUB — 0.9.42-rc65, G280 / 🔒 1507.
 *
 * This file used to declare $app_list_strings['bd_erp_stage_list'] (the eight
 * keys '', draft, in_estimating, priced, revision, accepted, ordered, lost) for
 * Quotes.bd_erp_stage. 🔒 1045 retired that field in favour of core's native
 * quote_stage, and 0c17913 (2026-09-20) deleted the field, its label and this
 * list — but deleted this file by DROPPING it from the build, which retires
 * nothing: Module Loader copies a package's files and never removes the
 * previous version's (§CW / G37, rc24's lesson). Every tenant that installed a
 * build between dde47d5 and 0c17913 still has the old copy on disk and still
 * serves the list, so bd_erp_stage still reads as a supported dropdown in
 * Studio and the report builder.
 *
 * Shipping this empty file at the SAME PATH is what removes it. The list is
 * core's business now; nothing in this package declares it.
 */
