<?php

/**
 * Lead Source, Lead Type, Mktg Campaign and Marketing Event are read-only on a quote that is ordered or closed, so a
 * seller cannot make an edit Epicor will discard.
 *
 * Measured on a Bench customer tenant: on an ordered, closed Epicor quote the seller changed Lead Source; the
 * write-back reported it sent, Epicor kept its own value, and the next read-back put Epicor's value back over the
 * seller's. The signal used is the one Sugar already holds: the quote's stage once it is ordered in full (Closed
 * Accepted), ordered in part (Partially Fulfilled), or closed (Closed Lost, Closed Dead).
 *
 * Run:  php scripts/tests/bd_lead_fields_read_only_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT RUNS: the shipped vardef file, loaded the way Sugar loads a Vardefs extension. Sugar builds a ReadOnly
 * dependency for a field only when it carries BOTH `readonly` and `readonly_formula` and is not calculated
 * (SugarEnt 26.1.0 include/Expressions/DependencyManager.php getReadOnlyFieldDependencies, line 227); that gate is
 * checked as written there, and the formula is evaluated for every quote stage.
 *
 * IF IT WERE BROKEN you would see: a lead field editable on an ordered or closed quote; a lead field read-only on a
 * quote still being quoted; or Project made read-only too (it is not one of the four).
 */

error_reporting(E_ALL);
$dictionary = array();
require __DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php';
$fields = $dictionary['Quote']['fields'];

$checks = array();
function check(string $name, bool $ok, string $detail = ''): void
{
    global $checks;
    $checks[] = array($name, $ok);
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok || $detail === '' ? '' : "  -- $detail");
}

/** Sugar's gate for building the field's ReadOnly dependency (DependencyManager.php:227). */
function sugarBuildsTheRule(array $def): bool
{
    return !empty($def['readonly']) && isset($def['readonly_formula']) && empty($def['calculated']);
}

/**
 * The formula for one quote stage. Only the shapes this package writes are read: isInList($quote_stage,
 * createList("…", …)); anything else is reported as unreadable rather than guessed.
 */
function readOnlyAt(string $formula, string $stage): ?bool
{
    if (!preg_match('/^isInList\(\$quote_stage,\s*createList\(((?:"[^"]*"\s*,?\s*)+)\)\)$/', trim($formula), $m)) {
        return null;
    }
    preg_match_all('/"([^"]*)"/', $m[1], $items);

    return in_array($stage, $items[1], true);
}

$closed = array('Closed Accepted', 'Partially Fulfilled', 'Closed Lost', 'Closed Dead');
$open = array('Draft', 'Negotiation', 'Delivered', 'On Hold', 'Confirmed', 'In Estimating', 'Priced');

foreach (array('bd_lead_source', 'bd_lead_type', 'bd_marketing_campaign', 'bd_marketing_event') as $name) {
    echo "-- $name --\n";
    $def = $fields[$name] ?? array();
    check("$name: Sugar builds its read-only rule", sugarBuildsTheRule($def), json_encode(array(
        'readonly' => $def['readonly'] ?? null, 'readonly_formula' => $def['readonly_formula'] ?? null)));
    $formula = (string) ($def['readonly_formula'] ?? '');
    foreach ($closed as $stage) {
        check("$name: read-only at $stage", readOnlyAt($formula, $stage) === true, $formula);
    }
    foreach ($open as $stage) {
        check("$name: editable at $stage", readOnlyAt($formula, $stage) === false, $formula);
    }
    check("$name: still required until the quote is in the ERP, as before", !empty($def['erp_required_until_synced']));
}

echo "-- CONTROL --\n";
check('Project is not one of the four: Sugar builds no read-only rule for it', !sugarBuildsTheRule($fields['bd_project_id'] ?? array()));

$failed = count(array_filter($checks, function ($c) {
    return !$c[1];
}));
echo count($checks) . " checks, $failed failed\n";
exit($failed ? 1 : 0);
