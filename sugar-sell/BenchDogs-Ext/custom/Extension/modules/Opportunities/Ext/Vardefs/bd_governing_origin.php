<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare one field on Opportunities:
 *
 *   bd_governing_origin   decision 72 provenance: auto | human | empty
 *
 * 🔒 1044 retired it. ORPHANED BY ITS OWN WRITER'S RETIREMENT. Added by 993aa66 alongside the Bench estimating layer; that layer was retired by 6e483c1 ('retire the Bench estimating layer; core owns it now') and the bd01_* module trees by 55bf113, but this vardef survived both. Measured across connector_ext_benchdogs, the Bench MLP and erp-integration-sugar: ZERO writers. Provenance for a governing selection this package no longer makes.
 *
 * WHY THIS FILE IS EMPTIED RATHER THAN DELETED. On Sugar Cloud, neither
 * omitting a file from the build nor uninstalling the package removes a
 * custom/Extension file a previous install already copied — proven on Bench
 * 2026-09-14, when rc24 dropped six such files and was INERT: installed clean,
 * fields still present. ONLY OVERWRITING THE FILE RETIRES IT. This stub is that
 * overwrite; see ERP_OrderLines/Ext/Vardefs/bd_shipped_value.php for the full
 * measurement.
 *
 * EXISTING DATA. Removing a vardef does not drop the column; stored values stay
 * until someone removes them deliberately, which is harmless — with no vardef
 * Sugar neither reads nor displays them. With no writer, every row is null.
 *
 * Safe to delete this stub outright once every instance has taken a release at
 * or after this one.
 */
