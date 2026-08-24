<?php

/**
 * REQ-28: what happened when this Kinetic-born quote met Sugar.
 *
 * bd_materialized_quote_id is the DURABLE link to the native Sugar Quote
 * this ERP quote was materialized into, and it is deliberately NOT
 * sugar_quote_id.
 *
 * sugar_quote_id is connector-owned: the container derives it from the
 * Kinetic QuoteComment on every sync (transformers/quotes.py,
 * parse_sugar_quote_id) and writes '' whenever a comment exists without a
 * Sugar marker - which is exactly the shape of a quote born in Kinetic. A
 * materialization stamped there would be erased on the next sync that
 * touched the row, and the guard against re-materializing would go with it:
 * one Kinetic quote, a new Sugar quote every sync. This field is in no
 * container payload, so nothing upstream can clear it.
 *
 * bd_materialize_status / _msg say why nothing was created when nothing
 * was. A Kinetic quote whose customer has no matching Sugar account must
 * NOT invent one - it waits visibly, on the record, with the reason legible
 * to a human, and is retried on every later sync.
 */

$dictionary['bd01_ERP_Quote']['fields']['bd_materialized_quote_id'] = array(
    'name' => 'bd_materialized_quote_id',
    'vname' => 'LBL_BD_MATERIALIZED_QUOTE_ID',
    'type' => 'varchar',
    'len' => 36,
    'comment' => 'id of the native Sugar Quote materialized from this Kinetic-born ERP quote (MLP-owned, never written by the connector)',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
);

$dictionary['bd01_ERP_Quote']['fields']['bd_materialize_status'] = array(
    'name' => 'bd_materialize_status',
    'vname' => 'LBL_BD_MATERIALIZE_STATUS',
    'type' => 'varchar',
    'len' => 30,
    'comment' => 'materialized | adopted | waiting_account | sugar_origin | below_floor',
    'reportable' => true,
    'importable' => false,
    'massupdate' => false,
);

$dictionary['bd01_ERP_Quote']['fields']['bd_materialize_msg'] = array(
    'name' => 'bd_materialize_msg',
    'vname' => 'LBL_BD_MATERIALIZE_MSG',
    'type' => 'varchar',
    'len' => 255,
    'comment' => 'human-readable reason the materialization did or did not happen',
    'reportable' => true,
    'importable' => false,
    'massupdate' => false,
);
