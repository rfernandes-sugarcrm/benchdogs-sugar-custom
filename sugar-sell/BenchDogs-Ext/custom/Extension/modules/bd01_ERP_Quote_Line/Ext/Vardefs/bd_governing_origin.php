<?php

/**
 * Decision 72's marker at the row it is actually a fact about: did this
 * package auto-select this line, or did something else set `governing` on it?
 *
 *   'auto' - BdGoverningAutoSelect chose this line because the quote arrived
 *            with none chosen. It may still move itself to a cheaper break as
 *            the connector delivers the rest of the ladder.
 *   ''     - anything else, including every line a person selected. Only an
 *            auto-selection is marked; a human selection is the ordinary case
 *            and is recorded by the ABSENCE of the marker, which is what makes
 *            decision 72 item 4 ("the marker clears the moment a person sets
 *            the governing line") literally true in the stored data.
 *
 * NO 'default' KEY, for the reason set out at length on the Opportunity
 * counterpart: a defaulted column reads back as a value nobody wrote, and that
 * is how 994 fabricated `0.00` rows happened in this release.
 *
 * Placed on the quote line's own RECORD view, next to `governing` - the marker
 * is a fact about this row and belonged on it. It is deliberately kept OFF the
 * lines subpanel: that grid is where a seller reads and toggles `governing`
 * itself, and a second column there would widen the detail row for a fact that
 * only qualifies the flag beside it. The marker decision 72 asked for by name
 * is still the Opportunity one, which is unchanged.
 */
$dictionary['bd01_ERP_Quote_Line']['fields']['bd_governing_origin'] = array(
    'name' => 'bd_governing_origin',
    'vname' => 'LBL_BD_GOVERNING_ORIGIN',
    'type' => 'varchar',
    'len' => 8,
    'comment' => 'Decision 72: auto = this package selected the line; empty = a person did',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    'studio' => false,
);
