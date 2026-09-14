<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare one field on ERP_Orders:
 *
 *   bd_shipped_value_total   sum of the order's shipped line values, as money
 *
 * USER DECISION 59 retired it, together with its per-line counterpart
 * ERP_OrderLines.bd_shipped_value. Core owns the one shipped surface and it
 * is a QUANTITY (ERP_OrderLines.shipped_quantity, rendered by core's
 * erp-fulfillment field). A Bench duplicate in money — or a rebuilt
 * bd_shipped_quantity — is the same defect wearing a better name.
 *
 * WHY IT WAS URGENT. The field carried 'default' => 0.0 with NO WRITER
 * BEHIND IT: the Bench connector module that wrote it was gated off on the
 * QA tenants. Module Loader adds a column with its vardef default, so every
 * order read 0.00 before anything had measured anything, and the
 * record-view fragment beside this file rendered that fabricated zero to a
 * seller. Measured on Bench before removal: 690 of 690 ERP_Orders rows held
 * exactly 0.00 — not one non-zero value and not one null. Across both
 * modules that is 994 rows, 100% fabricated by a vardef default.
 *
 * WHY THIS FILE IS EMPTIED RATHER THAN DELETED — AND THE PART THAT IS NEW.
 * Deleting it from the package does not remove it from an installed
 * instance. Two mechanisms, and BOTH were measured on Bench, not assumed:
 *
 *   1. An upgrade install copies the files the new version ships and leaves
 *      everything else alone (lesson BD-L-0005), and Sugar Cloud's package
 *      scanner denylists every file-removal call, so no install script can
 *      delete it either. This was already known.
 *
 *   2. NEITHER DOES AN UNINSTALL. On 2026-09-14 Bench Dogs Ext 0.9.42-rc23
 *      was uninstalled from ophirsx177 cleanly (16/16 steps, no error, data
 *      tables retained) and NOT ONE copied custom/Extension file was
 *      removed — every bd_* field this package ships survived the uninstall
 *      AND two full Quick Repairs, and the uninstall log contains no
 *      file-removal line at all. rc24, which deleted these six files from
 *      the build, was therefore INERT: installed, clean scan, QRR synced,
 *      and the field still live in vardefs and still on the record view.
 *
 * So the rule is wider than BD-L-0005 states: on Sugar Cloud neither
 * omitting a file from the manifest nor uninstalling the package removes a
 * copied custom/Extension file. ONLY OVERWRITING IT DOES. This stub is that
 * overwrite, following the proven precedent of
 * Contacts/Ext/Vardefs/bd_contact_sync_fields.php.
 *
 * EXISTING DATA. Removing a vardef does not drop the underlying column.
 * erp_orders_cstm.bd_shipped_value_total keeps its stored 0.00s until
 * someone removes it deliberately, which is harmless: with no vardef, Sugar
 * neither reads nor displays it. Reversible by restoring the three files and
 * running Quick Repair.
 *
 * THE CONNECTOR HALF. benchdogs-erp-custom cb5ec60 removes the writer and IS
 * origin/main. Confirm the deployed image is built at or after it before
 * assuming nothing writes this field.
 *
 * Safe to delete this stub outright once every instance has taken a release
 * at or after this one.
 */
