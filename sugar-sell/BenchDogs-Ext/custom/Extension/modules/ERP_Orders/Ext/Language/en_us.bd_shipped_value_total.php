<?php

/**
 * RETIRED. This file intentionally defines no strings.
 *
 * It used to define one label on ERP_Orders:
 *
 *   LBL_BD_SHIPPED_VALUE_TOTAL
 *
 * That label titled the fabricated money field bd_shipped_value_total,
 * retired by USER DECISION 59. See the sibling
 * Ext/Vardefs/bd_shipped_value_total.php for the full reasoning and the
 * measurements.
 *
 * WHY A LANGUAGE FRAGMENT NEEDS ITS OWN STUB. This file's only content was
 * the label constant; it never contained the field name, so a grep for
 * "bd_shipped_value_total" does not find it. A footprint survey by content
 * alone reports a clean removal and leaves this file behind, still compiling
 * a label into the tenant's language pack. Established by searching file
 * NAMES as well as contents.
 *
 * WHY IT IS EMPTIED RATHER THAN DELETED. On Sugar Cloud, neither omitting a
 * file from the new manifest (lesson BD-L-0005) NOR uninstalling the package
 * removes a copied custom/Extension file — both measured on Bench on
 * 2026-09-14. Only overwriting it does. This stub is that overwrite.
 *
 * Safe to delete this stub outright once every instance has taken a release
 * at or after this one.
 */
