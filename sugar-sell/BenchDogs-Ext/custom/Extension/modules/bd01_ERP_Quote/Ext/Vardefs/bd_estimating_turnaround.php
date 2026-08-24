<?php

/**
 * REQ-13, the reportable half: how long estimating took.
 *
 * Value is already covered (the deliverable RLIs are live and Sugar rolls
 * Opportunities.amount up from them - this package never writes that amount
 * directly), and committed history is already covered (Sugar's Forecast
 * worksheet snapshots on commit). Turnaround is the one thing no layer
 * captured, and it is Bench Dogs' biggest process question.
 *
 * TWO TIMESTAMPS, NO DURATION. A stored elapsed-time field goes stale the
 * moment either end is corrected; a report that subtracts two datetimes
 * never does. So this ships the raw pair and leaves the arithmetic to
 * reporting - both fields are reportable and studio-visible for exactly that
 * reason.
 *
 * They live on bd01_ERP_Quote, not on the Sugar Quote, because the ERP quote
 * row IS the unit of work estimating touches: a re-estimate arrives as a new
 * Kinetic quote (1194 -> 1195 measured live), and each of those rows carries
 * its own turnaround rather than averaging them into one field on the deal.
 *
 * Neither field is ever written by the connector, which is what makes them
 * trustworthy: the container's payload has no such key, so nothing upstream
 * can blank them the way erp_writeback_status gets stamped 'error' over
 * 'success'.
 */

$dictionary['bd01_ERP_Quote']['fields']['bd_sent_to_estimating_at'] = array(
    'name' => 'bd_sent_to_estimating_at',
    'vname' => 'LBL_BD_SENT_TO_ESTIMATING_AT',
    'type' => 'datetime',
    'comment' => 'When Send to Estimating raised this Kinetic quote (start of estimating turnaround)',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
);

$dictionary['bd01_ERP_Quote']['fields']['bd_priced_back_at'] = array(
    'name' => 'bd_priced_back_at',
    'vname' => 'LBL_BD_PRICED_BACK_AT',
    'type' => 'datetime',
    // FIRST price back wins. Guarded by an emptiness check on the field
    // itself, never by a status field: statuses get rewritten underneath us
    // (measured live - erp_writeback_status stamped 'error' over 'success'
    // by a skip core turned into an empty POST), and a turnaround that a
    // later Kinetic revision can overwrite measures nothing at all.
    'comment' => 'When estimating first handed this quote back priced - set once, never overwritten',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
);
