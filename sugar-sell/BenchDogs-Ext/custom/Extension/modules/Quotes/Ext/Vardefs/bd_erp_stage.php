<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare one field on Quotes:
 *
 *   bd_erp_stage   the ERP-side stage of the Bench Dogs quote (enum)
 *
 * 🔒 1044 retired it. The ERP-side stage belongs to ERP-Core, which declares
 * and draws its own. Bench's copy was additionally a TRAP: it carries a value
 * spelled 'accepted' of its own, distinct from `quote_stage`'s stored
 * 'Closed Accepted', and core's packages carry standing comments warning
 * maintainers not to gate decision 127's re-price refusal on the wrong one.
 * Retiring the field removes the ambiguity at the source.
 *
 * WHY EMPTIED RATHER THAN DELETED. On Sugar Cloud neither omitting a file from
 * the build nor uninstalling the package removes a custom/Extension file a
 * previous install already copied — proven on Bench 2026-09-14 when rc24
 * dropped six such files and installed clean while the fields stayed. ONLY
 * OVERWRITING THE FILE RETIRES IT. This stub is that overwrite (§CW, G37).
 *
 * EXISTING DATA. Removing a vardef does not drop the column; stored values stay
 * in quotes_cstm until removed deliberately, and with no vardef Sugar neither
 * reads nor displays them.
 */
