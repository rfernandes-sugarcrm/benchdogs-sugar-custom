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

// Bench Dogs' Kinetic fields, created as Studio custom fields where the tenant lacks them; one it has is kept as is.
try {
    // Autoloaded: install_copy rebuilds Sugar's class map before this script runs.
    if (!class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdKineticFields')
        || !class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdStudioFields')) {
        $GLOBALS['log']->fatal('BenchDogs-Ext: class BdKineticFields not found, Kinetic fields not created');
        $bdStepReport['kinetic_fields'] = 'MISSING: class BdKineticFields';
    } else {
        $bdKinetic = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::createMissing(
            new \Sugarcrm\Sugarcrm\custom\BenchDogs\BdStudioFields()
        );
        $GLOBALS['log']->fatal('BenchDogs-Ext: Kinetic fields created: '
            . (implode(', ', $bdKinetic['created']) ?: 'none') . '; kept as they are: ' . count($bdKinetic['kept']));
        if ($bdKinetic['failed'] === array()) {
            $bdStepReport['kinetic_fields'] = 'ok';
        } else {
            $bdStepReport['kinetic_fields'] = 'FAILED, not created: ' . implode('; ', $bdKinetic['failed']);
        }
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: Kinetic fields not created: ' . $e->getMessage());
    $bdStepReport['kinetic_fields'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
}

// Labels an earlier release created that now read differently, changed only where they still read the old text.
try {
    if (class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdKineticFields')
        && method_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdKineticFields', 'relabelShipped')) {
        $bdRelabel = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::relabelShipped(
            new \Sugarcrm\Sugarcrm\custom\BenchDogs\BdStudioFields()
        );
        $GLOBALS['log']->fatal('BenchDogs-Ext: Kinetic labels changed: '
            . (implode(', ', $bdRelabel['relabeled']) ?: 'none') . '; kept: ' . count($bdRelabel['kept']));
        if ($bdRelabel['failed'] === array()) {
            $bdStepReport['kinetic_labels'] = 'ok';
        } else {
            $bdStepReport['kinetic_labels'] = 'FAILED, not relabeled: ' . implode('; ', $bdRelabel['failed']);
        }
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: Kinetic labels not changed: ' . $e->getMessage());
    $bdStepReport['kinetic_labels'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
}

// Accounts and Quotes carry the fields ERP-Core's layout step places below; Products and ERP_Orders the Kinetic fields.
// Opportunities needs no step here: Module Loader's own repair after this script rebuilds every module and adds columns.
try {
    SugarAutoLoader::load('modules/Administration/QuickRepairAndRebuild.php');
    $bdRepairModules = array('Accounts', 'Quotes', 'Products', 'ERP_Orders');
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

// G380 (f) / 🔒 1724b: ERP-Core's ErpLayoutExtraFields (custom/include/, found by Sugar's autoloader) places every marked field that is on no panel yet.
foreach (array('Accounts' => 'accounts_erp_layout', 'Quotes' => 'quotes_erp_layout') as $bdModule => $bdStep) {
    try {
        if (class_exists('ErpLayoutExtraFields')) {
            $bdSynced = ErpLayoutExtraFields::sync($bdModule);
            $GLOBALS['log']->fatal('BenchDogs-Ext: ' . $bdModule . ' marked fields placed: '
                . implode(', ', (array) ($bdSynced['added'] ?? array())));
            $bdStepReport[$bdStep] = 'ok';
        } else {
            $GLOBALS['log']->fatal('BenchDogs-Ext: class ErpLayoutExtraFields not found (ERP-Core older than the G380'
                . ' release?), ' . $bdModule . ' fields not placed');
            $bdStepReport[$bdStep] = 'MISSING: class ErpLayoutExtraFields';
        }
    } catch (Throwable $e) {
        $GLOBALS['log']->fatal('BenchDogs-Ext: ' . $bdModule . ' layout sync failed: ' . $e->getMessage());
        $bdStepReport[$bdStep] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
    }
}

// The one-time steps of the install that moves the Project onto the quote lines, decided from Module Loader's history.
$bdFirstLineProjectInstall = null;
try {
    if (!class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdInstallHistory')) {
        $bdStepReport['install_history'] = 'MISSING: class BdInstallHistory';
    } else {
        $bdFirstLineProjectInstall = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdInstallHistory::firstInstallOf(
            \Sugarcrm\Sugarcrm\custom\BenchDogs\BdInstallHistory::LINE_PROJECT_RELEASE,
            \Sugarcrm\Sugarcrm\custom\BenchDogs\BdInstallHistory::installedVersions()
        );
        $bdStepReport['install_history'] = 'ok';
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: install history not read, the one-time steps do not run: ' . $e->getMessage());
    $bdStepReport['install_history'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
}

// Each line takes its quote's own Project where it has none, once per tenant.
try {
    if ($bdFirstLineProjectInstall === null) {
        $bdStepReport['line_project_copy'] = 'NOT RUN: the install history was not read';
    } elseif (!class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdLineProject')) {
        $bdStepReport['line_project_copy'] = 'MISSING: class BdLineProject';
    } else {
        $bdLineCopyText = 'not due (it ran with an earlier install)';
        $bdLineCopyFailed = 0;
        if ($bdFirstLineProjectInstall) {
            $bdLineCopy = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdLineProject::copyFromQuotes();
            $bdLineCopyText = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdLineProject::summary($bdLineCopy);
            $bdLineCopyFailed = $bdLineCopy['failed'];
        }
        $GLOBALS['log']->fatal('BenchDogs-Ext: line Project copy: ' . $bdLineCopyText);
        if ($bdLineCopyFailed === 0) {
            $bdStepReport['line_project_copy'] = 'ok';
        } else {
            $bdStepReport['line_project_copy'] = 'FAILED, ' . $bdLineCopyText;
        }
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: line Project copy failed: ' . $e->getMessage());
    $bdStepReport['line_project_copy'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
}

// Create Opportunity & Quote makes its quote with Needs Price Estimate on, set once per tenant.
try {
    if ($bdFirstLineProjectInstall === null) {
        $bdStepReport['opp_quote_type'] = 'NOT RUN: the install history was not read';
    } elseif (!class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdQuoteTypeDefault')) {
        $bdStepReport['opp_quote_type'] = 'MISSING: class BdQuoteTypeDefault';
    } else {
        $GLOBALS['log']->fatal('BenchDogs-Ext: Create Opportunity & Quote type: ' . ($bdFirstLineProjectInstall
            ? \Sugarcrm\Sugarcrm\custom\BenchDogs\BdQuoteTypeDefault::apply(BeanFactory::newBean('Administration'))
            : 'not due (set with an earlier install)'));
        $bdStepReport['opp_quote_type'] = 'ok';
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: Create Opportunity & Quote type not set: ' . $e->getMessage());
    $bdStepReport['opp_quote_type'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
}

// The customer's quote layout as a PDF Manager template on Quotes: created, or brought to this release's body.
try {
    if (!class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdQuotePdf')) {
        $GLOBALS['log']->fatal('BenchDogs-Ext: class BdQuotePdf not found, quote PDF template not installed');
        $bdStepReport['quote_pdf_template'] = 'MISSING: class BdQuotePdf';
    } else {
        $bdQuotePdf = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdQuotePdf::install();
        $GLOBALS['log']->fatal('BenchDogs-Ext: quote PDF template ' . $bdQuotePdf);
        $bdStepReport['quote_pdf_template'] = 'ok';
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: quote PDF template not installed: ' . $e->getMessage());
    $bdStepReport['quote_pdf_template'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
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
