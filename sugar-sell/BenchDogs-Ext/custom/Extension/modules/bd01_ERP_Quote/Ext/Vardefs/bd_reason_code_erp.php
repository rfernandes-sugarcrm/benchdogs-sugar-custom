<?php

/**
 * REQ-14: Epicor's raw QuoteHed.ReasonCode, kept beside the human wording.
 *
 * reason_code (the module's own field) carries what a person should read.
 * This one carries the mnemonic a report can group by, and exists because the
 * two are not interchangeable: BdQuoteReflectionHook::mapStage documents that
 * reason_code's documented preference order is description -> mnemonic ->
 * W/L word, so its content changes shape as core improves. Anything keyed on
 * the code needs the code.
 *
 * Nice-to-have, and honestly labelled as such: REQ-14's actual requirement is
 * the description, and that is already satisfied without this field.
 *
 * NOT named bd_* on purpose - the container extension already emits it as
 * reason_code_erp, and a field's name is a contract with whoever writes it.
 */

$dictionary['bd01_ERP_Quote']['fields']['reason_code_erp'] = array(
    'name' => 'reason_code_erp',
    'vname' => 'LBL_REASON_CODE_ERP',
    'type' => 'varchar',
    'len' => 10,
    'comment' => 'Epicor QuoteHed.ReasonCode verbatim (mnemonic), beside the human wording in reason_code',
    'reportable' => true,
    'importable' => false,
    'massupdate' => false,
);
