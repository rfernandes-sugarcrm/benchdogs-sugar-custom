<?php

/**
 * RETIRED. This file intentionally registers no hook.
 *
 * It used to register this package's estimating notification on Quotes.
 *
 * 🔒 531 — OWNER RULING: "the estimating workflow moves into CORE, notifications
 * included, and Bench retires its copy." ERP-Core carries the live hook:
 * ERP-Core/src/custom/modules/Quotes/ErpEstimatingNotificationHook.php, wired
 * by .../Ext/LogicHooks/erp_estimating_notification.php, and it watches Sugar's
 * native `quote_stage` rather than any package-private stage field.
 *
 * DUPLICATE NOTIFIERS ARE NOT A REDUNDANCY, THEY ARE A DOUBLE-SEND. Two hooks
 * on the same transition mean the estimator is told twice, and "exactly once"
 * is an oracle clause of this campaign (BD-04), not a nicety.
 *
 * WHY EMPTIED RATHER THAN DELETED. §CW / G37 — see the sibling stubs.
 */
