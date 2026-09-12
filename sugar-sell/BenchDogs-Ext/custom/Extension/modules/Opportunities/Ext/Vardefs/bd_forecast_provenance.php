<?php

/**
 * Provenance for Bench-managed Best/Worst forecast values.
 *
 * The visible native fields remain best_case and worst_case.  These private
 * fields only answer whether the last value in each native field was written
 * by this package or deliberately changed by a person.  Keeping the origins
 * separate is load-bearing: a forecaster may override Best while leaving
 * Worst system-managed (or the reverse).
 */
$dictionary['Opportunity']['fields']['bd_forecast_managed_value'] = array(
    'name' => 'bd_forecast_managed_value',
    'vname' => 'LBL_BD_FORECAST_MANAGED_VALUE',
    'type' => 'decimal',
    'len' => 26,
    'precision' => 6,
    'default' => null,
    'comment' => 'Last Opportunity-currency value written to Bench-managed forecast cases',
    'reportable' => false,
    'audited' => false,
    'importable' => false,
    'massupdate' => false,
    'studio' => false,
);

foreach (array('best', 'worst') as $bdForecastCase) {
    $name = 'bd_' . $bdForecastCase . '_case_origin';
    $dictionary['Opportunity']['fields'][$name] = array(
        'name' => $name,
        'vname' => 'LBL_' . strtoupper($name),
        'type' => 'varchar',
        'len' => 8,
        'default' => '',
        'comment' => 'Forecast ownership: system or human; blank is pre-upgrade legacy state',
        'reportable' => false,
        'audited' => false,
        'importable' => false,
        'massupdate' => false,
        'studio' => false,
    );
}
unset($bdForecastCase, $name);
