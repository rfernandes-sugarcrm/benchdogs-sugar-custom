<?php

/**
 * RETIRED. This file intentionally defines no strings.
 *
 * It used to define one label on ERP_OrderLines:
 *
 *   LBL_BD_SHIPPED_VALUE = 'Shipped Value (not invoiced)'
 *
 * That label titled the fabricated money field bd_shipped_value, retired by
 * USER DECISION 59. See the sibling Ext/Vardefs/bd_shipped_value.php for the
 * full reasoning and the measurements.
 *
 * WHY A LANGUAGE FRAGMENT NEEDS ITS OWN STUB — AND ITS OWN TEST. This file's
 * ONLY content was the label constant. It never contained the string
 * "bd_shipped_value" at all, so `git grep bd_shipped_value` does NOT find
 * it: a footprint survey that greps the field name alone reports a clean
 * removal and leaves this file and its ERP_Orders twin behind, still
 * compiling a label into the tenant's language pack. The retirement had to
 * be established by searching file NAMES as well as contents. That is why
 * the regression test checks both, and why this stub exists rather than a
 * deletion.
 *
 * WHY IT IS EMPTIED RATHER THAN DELETED. On Sugar Cloud, neither omitting a
 * file from the new manifest (lesson BD-L-0005) NOR uninstalling the package
 * removes a copied custom/Extension file — both measured on Bench on
 * 2026-09-14, where a clean 16/16 uninstall removed not one such file. Only
 * overwriting it does. This stub is that overwrite.
 *
 * Safe to delete this stub outright once every instance has taken a release
 * at or after this one.
 */
