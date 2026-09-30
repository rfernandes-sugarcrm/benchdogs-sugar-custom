<?php

/** Bench Dogs post_install: rebuild, have ERP-Epicor place the marked fields, report (G380, 🔒 1724b, 🔒 1567, G380 (f)). */

// The installed version, from the manifest ModuleInstaller::post_execute() extracted into scope; 'unknown' when run some other way.
$bdVersion = isset($manifest['version']) ? (string) $manifest['version'] : 'unknown';

// Proof of life, and the line an operator takes this install's PID from. See
// "HOW TO READ IT" above. Once per install request: this file is not at a
// reserved path (see "WHY THIS FILE IS NOT NAMED post_install.php").
$GLOBALS['log']->fatal('BenchDogs-Ext: post_install running (' . $bdVersion . ') - writing deployed metadata');

// G294: every step below records one outcome here (ok, FAILED, MISSING or NOT-LOADED), and the block at the bottom publishes the map.
$bdStepReport = array();

// Accounts (the customer group) and Quotes (the five ADM pickers, their labels, the defaults hook) are the only modules this package extends, so they are the only ones rebuilt.
try {
    SugarAutoLoader::load('modules/Administration/QuickRepairAndRebuild.php');
    $bdRepairModules = array('Accounts', 'Quotes');
    $bdRac = new RepairAndClear();
    $bdRac->show_output = false;
    $bdRac->module_list = $bdRepairModules;
    $bdRac->clearVardefs();
    $bdRac->rebuildExtensions($bdRepairModules);
    MetaDataManager::refreshModulesCache($bdRepairModules);
    $bdStepReport['repair_rebuild'] = 'ok';
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: repair/rebuild failed: ' . $e->getMessage());
    $bdStepReport['repair_rebuild'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
}

// G380 (f) / 🔒 1724b: ERP-Core's ErpLayoutExtraFields places every field of the module whose vardef carries the `erp_layout` marker and that is on no panel yet.
$bdLayoutHelper = 'custom/include/ErpLayoutExtraFields.php';

// REQ-19: the two customer-group fields, on Accounts' panel_overview (their marker).
try {
    if (!class_exists('ErpLayoutExtraFields', false) && file_exists($bdLayoutHelper)) {
        require_once $bdLayoutHelper;
    }
    if (class_exists('ErpLayoutExtraFields', false)) {
        $bdSynced = ErpLayoutExtraFields::sync('Accounts');
        $GLOBALS['log']->fatal('BenchDogs-Ext: Accounts marked fields placed: '
            . implode(', ', (array) ($bdSynced['added'] ?? array())));
        $bdStepReport['accounts_erp_layout'] = 'ok';
    } elseif (file_exists($bdLayoutHelper)) {
        $GLOBALS['log']->fatal("BenchDogs-Ext: {$bdLayoutHelper} loaded but class ErpLayoutExtraFields is undefined");
        $bdStepReport['accounts_erp_layout'] = 'NOT-LOADED: class ErpLayoutExtraFields';
    } else {
        $GLOBALS['log']->fatal("BenchDogs-Ext: {$bdLayoutHelper} missing (ERP-Core older than the G380"
            . ' release?), Accounts fields not placed');
        $bdStepReport['accounts_erp_layout'] = 'MISSING: ' . $bdLayoutHelper;
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: Accounts layout sync failed: ' . $e->getMessage());
    $bdStepReport['accounts_erp_layout'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
}

// G380 / G381 / G460 / G606: Reference and the five ADM pickers on
// ERP-Epicor's ERP panel (their markers).
try {
    if (!class_exists('ErpLayoutExtraFields', false) && file_exists($bdLayoutHelper)) {
        require_once $bdLayoutHelper;
    }
    if (class_exists('ErpLayoutExtraFields', false)) {
        $bdSynced = ErpLayoutExtraFields::sync('Quotes');
        $GLOBALS['log']->fatal('BenchDogs-Ext: Quotes marked fields placed: '
            . implode(', ', (array) ($bdSynced['added'] ?? array())));
        $bdStepReport['quotes_erp_layout'] = 'ok';
    } elseif (file_exists($bdLayoutHelper)) {
        $GLOBALS['log']->fatal("BenchDogs-Ext: {$bdLayoutHelper} loaded but class ErpLayoutExtraFields is undefined");
        $bdStepReport['quotes_erp_layout'] = 'NOT-LOADED: class ErpLayoutExtraFields';
    } else {
        $GLOBALS['log']->fatal("BenchDogs-Ext: {$bdLayoutHelper} missing (ERP-Core older than the G380"
            . ' release?), Quotes fields not placed');
        $bdStepReport['quotes_erp_layout'] = 'MISSING: ' . $bdLayoutHelper;
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: Quotes layout sync failed: ' . $e->getMessage());
    $bdStepReport['quotes_erp_layout'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
}

// The step report (G294): each channel in its own catch, and this block never raises.
$bdNotApplied = array();
$bdApplied = 0;
foreach ($bdStepReport as $bdStepName => $bdOutcome) {
    if ($bdOutcome === 'ok') {
        $bdApplied++;
    } else {
        // Truncated: this goes into a log line and a config value, and a
        // stack-trace-length message in either is how a report stops being read.
        $bdNotApplied[] = $bdStepName . ' -> ' . substr((string) $bdOutcome, 0, 500);
    }
}
$bdSummary = 'BenchDogs-Ext: post_install step report (' . $bdVersion . '): '
    . $bdApplied . '/' . count($bdStepReport) . ' applied';
if ($bdNotApplied !== array()) {
    $bdSummary .= '; NOT APPLIED: ' . implode(' | ', $bdNotApplied);
}

// CHANNEL 1 - one aggregated line, in package_install.log with this install's PID.
try {
    $GLOBALS['log']->fatal($bdSummary);
} catch (Throwable $e) {
    // Nothing useful is left to log to.
}

// Channel 2, the durable one: the benchdogs_ext install_report setting, written on every install.
try {
    $bdReportAdmin = BeanFactory::newBean('Administration');
    $bdReportAdmin->saveSetting('benchdogs_ext', 'install_report', json_encode(array(
        'version' => $bdVersion,
        'utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'pid' => getmypid(),
        'applied' => $bdApplied,
        'total' => count($bdStepReport),
        'steps' => $bdStepReport,
    )));
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: step report config row failed: ' . $e->getMessage());
}

// Channel 3: the UpgradeHistory process_status, only when a step did not apply; it aborts nothing.
if ($bdNotApplied !== array()) {
    try {
        if (isset($this)
            && $this instanceof ModuleInstaller
            && method_exists($this, 'setInstallationError')) {
            // Leads with INSTALLED on purpose: a partial install must not read as a failed one to uninstall.
            $this->setInstallationError(
                'Bench Dogs ' . $bdVersion . ' IS INSTALLED, but ' . count($bdNotApplied)
                . ' of ' . count($bdStepReport) . ' post_install steps did NOT apply: '
                . implode(' | ', $bdNotApplied)
                . ' -- full report: config row benchdogs_ext.install_report, and'
                . ' package_install.log under this install\'s PID.'
            );
        }
    } catch (Throwable $e) {
        $GLOBALS['log']->fatal('BenchDogs-Ext: step report to installation-status failed: ' . $e->getMessage());
    }
}

$GLOBALS['log']->fatal('BenchDogs-Ext: post_install finished');
