<?php

/**
 * RETIRED. This file intentionally adds nothing to any layout.
 *
 * It used to append bd_shipped_value_total to the ERP_Orders record view,
 * into a panel found by name.
 *
 * Together with its ERP_OrderLines counterpart, this fragment is what made
 * the defect visible: the vardef put a fabricated 0.00 in the database, and
 * this put it on the seller's screen. Retired by USER DECISION 59 along with
 * the vardef and the label. See the sibling
 * Ext/Vardefs/bd_shipped_value_total.php for the measurements.
 *
 * NOTE FOR ANYONE VERIFYING THE REMOVAL ON A TENANT. Emptying this fragment
 * removes the entry this PACKAGE appended. If ERP_Orders has ever been
 * customised in Studio, the live layout is
 * custom/modules/ERP_Orders/clients/base/views/record/record.php, which
 * belongs to no package and which no package can clean. Check the live
 * record-view metadata, not just the field list, before calling the removal
 * done.
 *
 * WHY IT IS EMPTIED RATHER THAN DELETED. On Sugar Cloud, neither omitting a
 * file from the new manifest (lesson BD-L-0005) NOR uninstalling the package
 * removes a copied custom/Extension file. Both were measured on Bench on
 * 2026-09-14. Only overwriting the file does anything. This stub is that
 * overwrite.
 *
 * Safe to delete this stub outright once every instance has taken a release
 * at or after this one.
 */
