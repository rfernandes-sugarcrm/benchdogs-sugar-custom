<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare one field on Quotes:
 *
 *   bd_erp_total   the ERP-side total of the Bench Dogs quote (currency)
 *
 * 🔒 1044 retired it. A quote has exactly one total surface and Sugar owns it:
 * `Quotes.total`, an ENFORCED ROLLUP that re-derives itself from the line
 * bundles. A second, independently-written total beside it can only ever agree
 * by luck, and when it disagrees the seller has no way to tell which is the
 * number the customer was quoted.
 *
 * WHY EMPTIED RATHER THAN DELETED. See the sibling stubs and §CW/G37: on Sugar
 * Cloud only overwriting the file retires it; dropping it from the build leaves
 * the field installed and the package INERT.
 *
 * EXISTING DATA. The column and its values remain until removed deliberately;
 * with no vardef Sugar neither reads nor displays them.
 */
