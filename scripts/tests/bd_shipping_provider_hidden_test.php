<?php

/**
 * With the Bench MLP installed, the quote screen shows no Shipping Provider (Sugar's stock shipper_name): nothing sends
 * it to Epicor (Ship Via does) and Bench Dogs has no shippers. Only the view changes: the field and its data stay, and
 * a tenant without the Bench MLP keeps it.
 *
 * Run:  php scripts/tests/bd_shipping_provider_hidden_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT RUNS: the shipped BdHiddenFields and the shipped sidecar overlays for the Quotes record and preview views,
 * included the way Sugar includes a compiled view extension (into a scope holding $viewdefs), over the stock Quotes
 * record view's Show More panel (SugarEnt 26.1.0 modules/Quotes/clients/base/views/record/record.php) and a preview
 * view of the same shape.
 *
 * IF IT WERE BROKEN you would see: Shipping Provider still on the quote; a neighbouring field (Ship Via, Team,
 * Assigned to) gone; a panel's fields turned into a JSON object; or the discount overlay's work undone.
 */

error_reporting(E_ALL);
require_once __DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/src/BenchDogs/BdHiddenFields.php';
const BD_QUOTE_VIEWS = __DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/Extension/modules/Quotes/Ext/clients/base/views';
const BD_OVERLAY = '_override_zz_bd_hide_shipping_provider.php';

$checks = array();
function check(string $name, bool $ok, string $detail = ''): void
{
    global $checks;
    $checks[] = array($name, $ok);
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok || $detail === '' ? '' : "  -- $detail");
}

/** A Quotes view as stock serves it: the ERP panel (Ship Via) and Show More (Shipping Provider among the rest). */
function stockQuoteView(): array
{
    return array('panels' => array(
        array('name' => 'panel_header', 'header' => true, 'fields' => array('name')),
        array('name' => 'LBL_RECORDVIEW_PANEL_ERP', 'fields' => array(
            array('name' => 'erp_quotes_ship_via_name'),
            array('name' => 'deal_tot'),
        )),
        array('name' => 'panel_hidden', 'fields' => array(
            'date_order_shipped',
            array('name' => 'shipper_name', 'initial_filter' => 'active_shippers',
                'filter_populate' => array('module' => array('Shippers'))),
            array('name' => 'geocode_status', 'licenseFilter' => array('MAPS')),
            'team_name',
            'assigned_user_name',
        )),
    ));
}

/** Sugar's include of a compiled view extension: $viewdefs in scope, the overlay run against it. */
function applyOverlay(string $view, string $file, array $viewdefs): array
{
    include BD_QUOTE_VIEWS . "/$view/$file";

    return $viewdefs;
}

function names(array $panel): array
{
    return array_map(function ($f) {
        return is_array($f) ? (string) ($f['name'] ?? '') : (string) $f;
    }, $panel['fields'] ?? array());
}

foreach (array('record', 'preview') as $view) {
    echo "-- Quotes $view --\n";
    $file = BD_QUOTE_VIEWS . "/$view/" . BD_OVERLAY;
    check("$view: the overlay ships", is_file($file), $file);
    if (!is_file($file)) {
        continue;
    }
    $viewdefs = array('Quotes' => array('base' => array('view' => array($view => stockQuoteView()))));
    $out = applyOverlay($view, BD_OVERLAY, $viewdefs);
    $panels = $out['Quotes']['base']['view'][$view]['panels'];
    $shown = array();
    foreach ($panels as $p) {
        $shown = array_merge($shown, names($p));
    }
    check("$view: Shipping Provider is not shown", !in_array('shipper_name', $shown, true), json_encode($shown));
    check("$view: every other field stays, in its place",
        names($panels[2]) === array('date_order_shipped', 'geocode_status', 'team_name', 'assigned_user_name')
            && names($panels[1]) === array('erp_quotes_ship_via_name', 'deal_tot'),
        json_encode(array(names($panels[1]), names($panels[2]))));
    check("$view: the panel's fields stay a list", array_keys($panels[2]['fields']) === range(0, 3));
    $again = applyOverlay($view, BD_OVERLAY, $out);
    check("$view: running again changes nothing (Sugar includes an overlay more than once)", $again === $out);
    // The discount overlay in the same compiled file, either order: each removes only its own fields.
    $both = applyOverlay($view, '_override_zz_bd_hide_seller_discounts.php', $out);
    $bothNames = array();
    foreach ($both['Quotes']['base']['view'][$view]['panels'] as $p) {
        $bothNames = array_merge($bothNames, names($p));
    }
    check("$view: with the discount overlay too, both are gone and Ship Via stays",
        !in_array('shipper_name', $bothNames, true) && !in_array('deal_tot', $bothNames, true)
            && in_array('erp_quotes_ship_via_name', $bothNames, true), json_encode($bothNames));
}

echo "-- CONTROL: a view without Shipping Provider is returned exactly as read --\n";
$plain = array('Quotes' => array('base' => array('view' => array('record' => array('panels' => array(
    array('name' => 'panel_body', 'fields' => array('quote_num')),
)))))) ;
if (is_file(BD_QUOTE_VIEWS . '/record/' . BD_OVERLAY)) {
    check('unchanged', applyOverlay('record', BD_OVERLAY, $plain) === $plain);
}

$failed = count(array_filter($checks, function ($c) {
    return !$c[1];
}));
echo count($checks) . " checks, $failed failed\n";
exit($failed ? 1 : 0);
