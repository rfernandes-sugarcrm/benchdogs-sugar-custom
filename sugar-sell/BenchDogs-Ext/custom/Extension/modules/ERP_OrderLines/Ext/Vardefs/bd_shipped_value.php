<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare one field on ERP_OrderLines:
 *
 *   bd_shipped_value   shipped quantity x unit price for the line, as money
 *
 * USER DECISION 59 retired it. There is exactly ONE shipped surface on an
 * order line, it belongs to ERP-Core, and it is a QUANTITY:
 * ERP_OrderLines.shipped_quantity, rendered by core's erp-fulfillment field
 * as "12 of 15". This package must not ship a second one, in money or in
 * quantity: a rebuilt bd_shipped_quantity would be the same defect wearing a
 * better name.
 *
 * WHY IT WAS URGENT. The field carried 'default' => 0.0 with NO WRITER
 * BEHIND IT — the Bench connector module that wrote it was gated off on the
 * QA tenants (SUGARAI_BD_MLP_FIELDS did not list shipped_value). Module
 * Loader adds a column with its vardef default, so every row read 0.00
 * before anything had measured anything, and the record-view fragment beside
 * this file rendered it as "Shipped Value (not invoiced): $0.00" on
 * essentially every line. "Nothing shipped" and "we never measured this"
 * rendered identically to a seller. Measured on Bench before removal:
 * 304 of 304 ERP_OrderLines rows held exactly 0.00 — not one non-zero value
 * and not one null, which is the fabrication proved rather than inferred.
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
 *      removed — every bd_* field this package ships on Opportunities,
 *      Products, Accounts, Quotes, ERP_OrderLines and ERP_Orders survived
 *      the uninstall AND two full Quick Repairs. The uninstall log contains
 *      no file-removal line at all. rc24 had deleted these six files from
 *      the build and was therefore INERT: installed, clean, and the field
 *      still there.
 *
 * So the rule is wider than BD-L-0005 states: on Sugar Cloud neither
 * omitting a file from the manifest nor uninstalling the package removes a
 * copied custom/Extension file. ONLY OVERWRITING IT DOES. This stub is that
 * overwrite. The sibling Ext/Language and Ext/clients/.../record fragments
 * are emptied for the same reason, and Contacts/Ext/Vardefs/
 * bd_contact_sync_fields.php is the precedent this follows — proven live,
 * since all three fields it retired read absent on the tenant.
 *
 * EXISTING DATA. Removing a vardef does not drop the underlying column.
 * erp_orderlines_cstm.bd_shipped_value keeps its stored 0.00s until someone
 * removes it deliberately, which is harmless: with no vardef, Sugar neither
 * reads nor displays it. Left in place on purpose, so this release is
 * reversible by restoring the three files and running Quick Repair, with no
 * data lost in the meantime. Nothing of value is in the column in any case —
 * every stored value is the vardef default.
 *
 * THE CONNECTOR HALF. benchdogs-erp-custom cb5ec60 ("fix(req21): retire the
 * Bench shipped quantity/value duplicate; core owns it") removes the writer
 * and IS origin/main. Confirm the deployed image is built at or after it
 * before assuming nothing writes this field.
 *
 * Safe to delete this stub outright once every instance has taken a release
 * at or after this one.
 */
