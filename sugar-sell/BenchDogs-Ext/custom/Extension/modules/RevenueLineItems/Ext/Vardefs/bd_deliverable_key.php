<?php

/**
 * RETIREMENT STUB — 0.9.42-rc65, G280 / 🔒 1507.
 *
 * This file used to declare RevenueLineItem.bd_deliverable_key (varchar 80,
 * studio-visible, reportable) and an index on it, for the REQ-6 deliverable
 * materialisation. fc5ec59 (2026-09-12, "enforce quote-line-only Bench
 * workflow") retired that model and dropped this file from the build — which
 * retires nothing on a hosted tenant: the copied file stays on disk and the
 * vardef keeps being compiled, so a retired field keeps offering itself in
 * Studio, in the report builder and in every column picker, with nothing
 * writing it.
 *
 * Shipping the path EMPTY is the only thing that takes the vardef away
 * (§CW / G37). The database column and its index are left alone on purpose:
 * removing a column is data loss, and an unused column is invisible to a user
 * once no vardef declares it.
 */
