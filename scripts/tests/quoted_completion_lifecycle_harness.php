<?php

#[AllowDynamicProperties]
class SugarBean
{
}

$GLOBALS['log'] = new class {
    public $warnings = array();

    public function warn($message)
    {
        $this->warnings[] = $message;
    }
};

require $argv[1];
$hook = new BdQuoteReflectionHook();
$stage = new ReflectionMethod($hook, 'mapStage');
$stage->setAccessible(true);
$signal = new ReflectionMethod($hook, 'quotedSignal');
$signal->setAccessible(true);
$map = function (
    $current,
    $closed,
    $reason,
    $ordered,
    $quoted,
    $date,
    $previous,
    $quotedChanged = false
)
    use ($hook, $stage) {
    return $stage->invoke(
        $hook,
        $current,
        $closed,
        $reason,
        $ordered,
        $quoted,
        $date,
        $previous,
        $quotedChanged
    );
};

$signals = array();
foreach (array(null, '', false, 0, '0', true, 1, '1', 'invalid') as $value) {
    $bean = new SugarBean();
    $bean->id = 'safe-test-id';
    $bean->quoted = $value;
    $signals[] = $signal->invoke($hook, $bean);
}

echo json_encode(array(
    'unknown_preserves_handoff' => $map('Quote', false, '', false, null, '', 'in_estimating'),
    'unknown_preserves_priced' => $map('QUOT', false, '', false, null, '', 'priced'),
    'false_preserves_handoff' => $map('QUOT', false, '', false, false, '', 'in_estimating'),
    'false_preserves_revision' => $map('QUOT', false, '', false, false, '', 'revision'),
    'false_reopens_priced' => $map('QUOT', false, '', false, false, '', 'priced', true),
    'unchanged_false_does_not_invent_revision' => $map(
        'QUOT', false, '', false, false, '', 'priced', false
    ),
    'false_initializes_draft' => $map('QUOT', false, '', false, false, '', ''),
    'true_with_date_prices' => $map(
        'QUOT',
        false,
        '',
        false,
        true,
        '2026-09-12T00:00:00-05:00',
        'in_estimating'
    ),
    'true_without_date_fails_closed' => $map(
        'QUOT', false, '', false, true, '', 'in_estimating'
    ),
    'repeat_true_is_stable' => $map(
        'QUOT', false, '', false, true, '2026-09-12T00:00:00-05:00', 'priced'
    ),
    'requoted_after_revision' => $map(
        'QUOT', false, '', false, true, '2026-09-13T00:00:00-05:00', 'revision'
    ),
    'order_outranks_incomplete' => $map(
        'QUOT', false, '', true, false, '', 'in_estimating'
    ),
    'closed_won_outranks_incomplete' => $map(
        'Closed (Won)', true, '', false, false, '', 'in_estimating'
    ),
    'closed_lost_outranks_complete' => $map(
        'Closed (Lost)',
        true,
        '',
        false,
        true,
        '2026-09-12T00:00:00-05:00',
        'priced'
    ),
    'signals' => $signals,
    'warning_count' => count($GLOBALS['log']->warnings),
));
