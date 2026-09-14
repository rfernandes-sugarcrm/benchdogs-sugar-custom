<?php

/**
 * Decision 72's marker: was this Opportunity's amount valued from a line a
 * PERSON chose, or from one the system auto-selected because nobody had?
 *
 * THREE STATES, AND THE EMPTY ONE IS LOAD-BEARING
 *
 *   'auto'  - exactly one governing line, and this package selected it because
 *             the quote arrived with none. Nobody has reviewed this number.
 *   'human' - exactly one governing line, and something other than this
 *             package's auto-selector put it there. Somebody is accountable
 *             for the number.
 *   ''      - nothing to report: no ERP quote, no governing line, or the
 *             ambiguous two-selected state that still fails closed.
 *
 * THERE IS NO 'default' KEY HERE, AND THERE MUST NEVER BE ONE.
 *
 * A vardef default is precisely what produced 994 fabricated `0.00` rows in
 * this release (decision 59): a column that has never been written reads back
 * as a value somebody then treats as a measurement. An Opportunity this
 * package has never evaluated must read EMPTY - not 'human', which would claim
 * a person reviewed it, and not 'auto', which would put it on the review
 * report it does not belong on. Empty reads empty.
 *
 * The value is DERIVED from the ERP quote's lines on every rollup
 * (BdQuoteReflectionHook::refreshGoverningOrigin), never remembered, so it
 * cannot drift away from what the rows actually say.
 *
 * WHY varchar AND NOT enum. An enum needs an app_list_strings domain, and
 * whole-array dropdown writes are exactly the surface that ERP-Epicor's
 * SalesStageDomDropdown can clobber on its next install. A varchar has no such
 * exposure, and Reports filters it with a plain "equals auto" just as well.
 */
$dictionary['Opportunity']['fields']['bd_governing_origin'] = array(
    'name' => 'bd_governing_origin',
    'vname' => 'LBL_BD_GOVERNING_ORIGIN',
    'type' => 'varchar',
    'len' => 8,
    'comment' => 'Decision 72: auto = machine-selected governing line, human = person-selected, empty = not evaluated',
    // Reportable is the POINT of this field, not a detail. The on-screen
    // marker helps whoever opens one record; the saved report
    // (BdAutoSelectedReport) is what surfaces every unreviewed quote at once,
    // including the ones nobody thinks to open.
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    // Read-only to a person: it records what happened, and editing it by hand
    // would only make it disagree with the lines it is derived from.
    'studio' => false,
);
