<?php

/**
 * EMPTIED IN 0.9.42-rc65 — G278 / 🔒 1506 + G280 / 🔒 1507.
 *
 * This file declared the Bench stage vocabulary: quote_stage_dom
 * 'Partially Fulfilled', sales_stage_dom 'Prototype Ordered' and
 * 'Partial Production Ordered', and their 80/90 probabilities. Every one of
 * them is now shipped by Partial Fulfillment (>= 1.0.40, the manifest floor),
 * in _override_ fragments that Sugar merges LAST — which is a better place for
 * them than this file ever was: a non-override fragment can be, and repeatedly
 * was, wiped by ERP-Core's whole-array sales_stage_dom replace (G220/G273).
 *
 * Owner, on where the keys belong: *"this hsoudl happen in the core"*.
 *
 * EMPTIED, NOT DROPPED. Sugar loads this by PATH from every tenant that ever
 * installed a build carrying it, and Module Loader deletes nothing (§CW / G37).
 * Leaving it out of the build would leave the old declarations live and a
 * second package declaring the same keys. Shipping the path empty is the
 * retirement. The accumulated en_us.zz_bd_stage_doms.php that post_install used
 * to append to is removed by post_install itself, through
 * ModuleInstaller::uninstall_languages().
 */
