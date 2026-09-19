<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare one field on Quotes:
 *
 *   bd_reason_code   the win/loss reason reflected from the Bench ERP quote
 *
 * 🔒 1044 / owner ruling 2026-09-19: the Bench package keeps two fields and
 * retires the rest. Core owns the win/loss reason on the Quote as
 * `erp_reason_code`.
 *
 * 🚩 READ THIS BEFORE ASSUMING THE ROW IS A CLEAN DUPLICATE. Measured live on
 * Bench 2026-09-19, the two fields carried DIFFERENT HALVES of the same fact:
 *
 *     erp_reason_code (core)  = "PRICE"                  <- the CODE
 *     bd_reason_code  (here)  = "Best Competitive Price" <- the LABEL
 *
 * so retiring this one leaves the Quote showing a code with no human-readable
 * label on the 44 quotes that carried one. The owner accepted that, twice.
 *
 * THE LABEL IS DERIVABLE, WHICH IS WHY STORING IT AGAIN WAS NEVER RIGHT.
 * 'Reason_' || erp_reason_code resolves EXACTLY against the live
 * ERP_LookupValues master of type `Reason` — measured on Bench, 10 active rows,
 * CDOL -> "Pricing Factor", PRICE -> "Best Competitive Price" — matching this
 * field value-for-value on every non-blank row. The stored copy was also the
 * LESS reliable of the two: quote REQ4-FINAL-0512 carried code PRICE and a
 * BLANK label, 1 of 5 sampled rows, so a seller already saw no reason there.
 *
 * OWED IN CORE: render the label from that lookup on the Quote. Until it does,
 * the reason reads as a code.
 *
 * WHY EMPTIED RATHER THAN DELETED. §CW / G37 — on Sugar Cloud only overwriting
 * the file retires it; the column and its values remain untouched, so nothing
 * is destroyed and a core-side renderer can still read them if wanted.
 */
