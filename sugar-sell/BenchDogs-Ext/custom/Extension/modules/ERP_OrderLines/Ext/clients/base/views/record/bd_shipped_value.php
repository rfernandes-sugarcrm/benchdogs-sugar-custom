<?php

/**
 * RETIRED. This file intentionally adds nothing to any layout.
 *
 * It used to append bd_shipped_value to the ERP_OrderLines record view, into
 * the panel found by name LBL_RECORDVIEW_PANEL_LINE_ITEM_DETAIL.
 *
 * THIS FILE IS THE ONE THAT MADE THE DEFECT VISIBLE. The vardef alone put a
 * fabricated 0.00 in the database; this fragment put it on the seller's
 * screen, as "Shipped Value (not invoiced): $0.00", on essentially every
 * order line, from 0.9.41 onward. Retired by USER DECISION 59 along with the
 * vardef and the label. See the sibling Ext/Vardefs/bd_shipped_value.php for
 * the measurements.
 *
 * NOTE FOR ANYONE VERIFYING THE REMOVAL ON A TENANT. Emptying this fragment
 * removes the entry this PACKAGE appended. If ERP_OrderLines has ever been
 * customised in Studio, the live layout is
 * custom/modules/ERP_OrderLines/clients/base/views/record/record.php, which
 * belongs to no package and which no package can clean. Check the live
 * record-view metadata, not just the field list, before calling the removal
 * done.
 *
 * WHY IT IS EMPTIED RATHER THAN DELETED. On Sugar Cloud, neither omitting a
 * file from the new manifest (lesson BD-L-0005) NOR uninstalling the package
 * removes a copied custom/Extension file. Both were measured on Bench on
 * 2026-09-14: a clean 16/16 uninstall with tables retained removed not one
 * such file, and rc24 — which deleted these six files from the build — left
 * the field rendering on the record view exactly as before. Only overwriting
 * the file does anything. This stub is that overwrite.
 *
 * Safe to delete this stub outright once every instance has taken a release
 * at or after this one.
 */
