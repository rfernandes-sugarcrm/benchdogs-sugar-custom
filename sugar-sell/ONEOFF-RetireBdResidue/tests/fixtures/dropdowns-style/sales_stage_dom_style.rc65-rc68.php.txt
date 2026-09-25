<?php

/**
 * EMPTIED IN 0.9.42-rc65 — G278 / 🔒 1506 + G280 / 🔒 1507.
 *
 * This file styled the two Bench release milestones in sales_stage_dom_style.
 * The stages themselves moved to Partial Fulfillment, and PF ships the two
 * styles with them (partial_fulfillment_sales_stage_style.php, each entry
 * guarded on the key so a tenant's own styling is never overwritten). A style
 * is not decoration here: with applyFormatting enabled, a domain key with no
 * style renders BLANK on the record — the L-0004 defect. So the styles had to
 * travel with the keys, and they did.
 *
 * EMPTIED, NOT DROPPED: Sugar loads this by path from every tenant that ever
 * installed it, and Module Loader deletes nothing (§CW / G37). Keeping a Bench
 * style for a core-owned key would also be exactly the duplication the owner
 * ruled out — *"Donthave any logic on bench that is not on core"*.
 */
