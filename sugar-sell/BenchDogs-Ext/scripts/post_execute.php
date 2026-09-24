<?php

/**
 * Bench Dogs post_install: rebuild, have ERP-Epicor place the marked fields, report.
 *
 * WHAT THIS FILE DOES, AND ALL IT DOES (0.9.42-rc70, G380 / 🔒 1724b)
 *
 * Owner, verbatim: *"Benchdog MLP shoudl be mininal with mininal foot print of
 * overide"* (🔒 1567), and 🔒 1724b: Bench keeps ONLY ADM config and ADM rules.
 * This package ships NO layout code any more. Its fields carry ERP-Epicor's
 * `erp_layout` vardef marker (G380 (f)), and ERP-Core's ErpLayoutExtraFields
 * places them on the record views ERP-Epicor owns. So this file has three steps:
 *
 *   repair_rebuild  vardef / extension / metadata cache for Accounts and
 *       Quotes, the only modules this package extends. FIRST, so the steps
 *       below read the merged vardefs this install just added.
 *   accounts_erp_layout  ErpLayoutExtraFields::sync('Accounts') places the two
 *       REQ-19 customer-group fields (panel_overview, after Industry - rc72,
 *       G507) when they are on no panel. A field already on the view - an
 *       admin's placement, or rc69's HEADER placement on a view without
 *       panel_body (Ophir) - is left where it is: taking them out of the header
 *       is the one-off ONEOFF-MoveBdCustomerGroup's job, not this package's
 *       (🔒 1724b: no layout code here).
 *   quotes_erp_layout  ErpLayoutExtraFields::sync('Quotes') places Lead Source,
 *       Lead Type and Project on ERP-Epicor's ERP panel, after Reference.
 *
 * ERP-Epicor also calls sync() at the end of its own Quotes and Accounts layout
 * installs, so reinstalling ERP-Epicor keeps these fields. The file is ERP-Core's
 * (custom/include/ErpLayoutExtraFields.php); a MISSING report here means ERP-Core
 * / ERP-Epicor is older than the manifest floor allows.
 *
 * RETIRED under 🔒 1724b: accounts_customer_group_field
 * (BdAccountsLayoutExtensions) and quotes_adm_fields (BdAdmQuoteFieldsLayout),
 * this package's own writers on views ERP-Epicor owns - replaced by the marker
 * and sync() above.
 *
 * WHAT WENT, AND WHERE IT LIVES NOW - each removal is behaviour-neutral on
 * every QA tenant, and the reason is recorded here rather than in a release
 * note nobody reads:
 *
 *   partial_order_stage_config  wrote erp_integration.partial_order_sales_stage
 *       = 'Partial Production Ordered' when absent. Partial Fulfillment >= 1.0.43
 *       carries that exact value as ErpOpportunityValuation::
 *       DEFAULT_PARTIAL_STAGE, a READER-side default (G305 / 🔒 1519), so a
 *       tenant with no row now behaves as though this had written it. A tenant
 *       that already has the row keeps it: it is data, and nothing here
 *       deletes it. The manifest's Partial Fulfillment floor is 1.0.43 for
 *       exactly this reason.
 *   quotes_layout_extensions (K-2), opportunities_marker_removal (K-3),
 *   stage_fragment_removal (K-5)  one-shot retirements of the Bench Dogs Quotes
 *       panel, the retired bd_governing_origin marker and the accumulated
 *       zz_bd_stage_doms fragment. SPENT: the disposable one-off "Retire Bench
 *       Dogs Residue" (sugar-sell/ONEOFF-RetireBdResidue) performs all three
 *       and ran on every QA tenant - et 1.0.0 at 2026-09-22 22:22Z, stock and
 *       Ophir 1.0.1 at 2026-09-23 00:58Z, failed 0 everywhere (G234 CLOSED) -
 *       and nothing re-adds any of them. Re-run that one-off, not this file,
 *       after any Bench Dogs uninstall.
 *   relationship_rebuild  its reason (the zzz_ quotes_erp_orders dictionary)
 *       was retired long ago; it rebuilt Quotes / ERP_Orders caches for a
 *       package that no longer touches either module.
 *   language_rebuild  existed to recompile the EMPTIED stage fragments and to
 *       report on Partial Fulfillment's stage vocabulary. There are no emptied
 *       fragments left in this build, and ModuleInstaller::install() has
 *       already run install_extensions() -> rebuild_extensions() ->
 *       rebuild_languages() before post_execute, which is what compiles the two
 *       customer-group labels (SugarEnt 26.1.0 ModuleInstaller.php:258-270,
 *       1030-1081).
 *
 * TOP-LEVEL CODE, NOT A FUNCTION (0.9.42-rc26)
 *
 * ModuleInstaller::post_execute() (read directly in SugarEnt-Full 25.2.0 and
 * 26.1.0, ModuleInstall/ModuleInstaller.php:426-440) only require_once's each
 * registered file; it never calls a global function named after the installdef
 * key. Until rc25 this body sat inside a function nothing called, and every line
 * of it was dead on every install while Module Loader reported success.
 * Top-level code is correct whether the loader merely requires the file or also
 * calls a function named for the key; a function is correct under only one.
 *
 * WHY THIS FILE IS NOT NAMED post_install.php (0.9.42-rc69, G294 / G295)
 *
 * Through rc68 it was, and Sugar RAN IT TWICE per install. scripts/
 * post_install.php is one of four RESERVED paths (PackageZipFile::
 * PACKAGE_SCRIPT_LIST, byte-identical in SugarEnt 25.2.0 and 26.1.0), and
 * PackageManager::installPackage() plain-`include`s it (runPackageScript())
 * right AFTER ModuleInstaller::install() has already require_once'd it as the
 * post_execute installdef. `include` does not consult the require_once table.
 * Measured on rc68 (Ophir, one PID, 2438880): pass 1 at 01:30:27 inside the
 * installer logged "step report (0.9.42-rc68): 8/8"; pass 2 at 01:31:54, with no
 * $manifest and $this a PackageZipFile, logged "step report (unknown): 8/8",
 * OVERWROTE the durable config row below with version "unknown", and could not
 * reach installation-status. So a failure in pass 1 was wiped by pass 2, and a
 * failure in pass 2 never reached channel 3. Under this name runPackageScript()
 * finds nothing and returns: the installdef is the only route, so this runs
 * ONCE, inside the installer, with the version in scope. ERP-Epicor ships its
 * install step as scripts/post_execute.php, and the same grade measured it
 * logging a single pass. Do not rename it back;
 * scripts/tests/test_g294_single_pass.py runs both routes over the built zip.
 *
 * NOTHING IN THIS FILE MAY THROW PAST ITS OWN CATCH
 *
 * On the post_execute path an uncaught throw is a FAILED INSTALL, and
 * PackageManager::installPackage() catches Throwable around the whole install
 * and calls forceUninstall() (SugarEnt 25.2.0 and 26.1.0,
 * src/PackageManager/PackageManager.php:751-769) - a throw here does not leave
 * the previous version in place, it takes Bench Dogs off the tenant. So every
 * block keeps its own try/catch (Throwable) and its own file_exists /
 * class_exists guard.
 *
 * HOW A FAILED STEP IS REPORTED (0.9.42-rc67, G294)
 *
 * Until rc66 each block caught its own Throwable and wrote ONE ->fatal() line,
 * and that was the whole of it: no aggregation, no marker, and no channel to
 * `installation-status`. So a step could fail while Module Loader reported N/N
 * steps, the scanner verdict came back clean and the rendered Module Loader row
 * said Installed. That is not hypothetical: `BenchDogs-Ext: QLI columns failed:
 * Call to private method BaseErpLayout::loadView() from scope
 * BdQliColumnsLayout` was in the log on EVERY install from 2026-09-17 through
 * rc65 and every gate read green.
 *
 * MAKING A STEP THROW IS NOT THE FIX AND WOULD BE WORSE (see above). So every
 * step still catches, and the outcome is REPORTED instead of raised. Each block
 * records into $bdStepReport - 'ok', or what went wrong verbatim - and the block
 * at the bottom publishes that map on THREE channels, each in its own
 * try/catch, each answering a question the other two cannot:
 *
 *  1. ONE AGGREGATED ->fatal() LINE: `post_install step report (<version>):
 *     M/N applied`, naming every step that did not apply. Cheapest channel, and
 *     the one that still works when the database is the thing that is broken.
 *
 *  2. A CONFIG ROW, benchdogs_ext.install_report: a JSON map of every step's
 *     outcome with the version, the UTC timestamp and the PID. This is the
 *     DURABLE channel. A log rotates and has to be retrieved by hand;
 *     `process_status` (channel 3) belongs to ONE UpgradeHistory row, and
 *     installPackage() resets it at the start of the next install and
 *     mark_deleted()s the previous row on an upgrade. A config row outlives
 *     both, and comes back in the SAME Diagnostic Tool run as the log
 *     (DiagnosticRun.php:281 dumps `config` and `upgrade_history` under the
 *     table-dump item). Written on EVERY install, pass or fail, because a clean
 *     report is the positive evidence and channel 3 is deliberately silent on
 *     success.
 *
 *  3. `installation-status`, AND ONLY WHEN SOMETHING FAILED.
 *     ModuleInstaller::post_execute() require_once's this file from inside its
 *     own method body (ModuleInstall/ModuleInstaller.php:426, identical in
 *     25.2.0 and 26.1.0), so $this here IS the running ModuleInstaller.
 *     setInstallationError() is public in both and does exactly one thing:
 *     writes a string into that UpgradeHistory row's process_status JSON - the
 *     `error` field GET Administration/packages/<id>/installation-status
 *     returns beside `is_done`. It aborts nothing. Force-uninstall is driven by
 *     a thrown Throwable or an E_ERROR shutdown handler, never by this field.
 *     Every access is guarded (isset($this), instanceof, method_exists) and
 *     wrapped, so a Sugar that ever changes that shape degrades to channels 1
 *     and 2 rather than throwing.
 *
 * WHAT A CLEAN RUN LOOKS LIKE: one `M/M applied` line with no NOT APPLIED
 * clause, a config row whose every step is 'ok', and NOTHING in
 * `installation-status`. The absence of an error there is deliberately NOT the
 * proof of a clean install - absence is what the defect looked like for five
 * weeks. The config row is the positive proof.
 *
 * WHERE THE OUTPUT GOES, AND HOW TO FIND IT (0.9.42-rc67, G295)
 *
 * NOT sugarcrm.log. Module Loader's commit step calls
 * MlpLogger::replaceDefault() (modules/Administration/UpgradeWizard_commit.php:17,
 * for Install AND Uninstall), which repoints the DEFAULT logger's file name to
 * `package_install` and forces its level to debug
 * (modules/Administration/MlpLogger.php:15-21, byte-identical in SugarEnt
 * 25.2.0 and 26.1.0). So every $GLOBALS['log'] line this file writes lands in
 * package_install.log - same directory as sugarcrm.log, which is how
 * DiagnosticRun.php:318 resolves it. Sugar's own Module Loader footer says the
 * same: "Download Package Install Log File in Diagnostic Tool". On a hosted
 * tenant a missing line in sugarcrm.log proves NOTHING here.
 *
 * HOW TO READ IT, in order:
 *
 *  1. RETRIEVE THE LOG. Admin > Diagnostic Tool, tick ONLY "Package Install
 *     Log File" - untick everything the page pre-selects - then Execute
 *     Diagnostic and download. Tick "MySQL Table Dumps" as well, and only then,
 *     when you also want the config row and the upgrade_history process_status
 *     described above (DiagnosticRun.php:281).
 *
 *  2. FIND THIS INSTALL'S PID. Every Sugar log line is prefixed with the PID of
 *     the process that wrote it. Locate `BenchDogs-Ext: post_install running
 *     (<version>)` naming the version you installed and take the PID off that
 *     line; on Bench's rc65 install it was 2069085. The version is in that line
 *     from rc69 on. Through rc68 it was not, and the line appeared TWICE per
 *     install under one PID (see "WHY THIS FILE IS NOT NAMED post_install.php"):
 *     for those, take the PID off the verdict line in step 4, which does name
 *     the version, and ignore its "(unknown)" twin.
 *
 *  3. SCOPE TO IT. Read only the lines carrying that PID. TIMING IS NOT THE
 *     ATTRIBUTION: two installs minutes apart interleave. mergeModuleFiles,
 *     post_install and the scanner verdict for one install all share one PID.
 *
 *  4. READ THE VERDICT LINE:
 *     `BenchDogs-Ext: post_install step report (<version>): M/N applied`.
 *     M == N with no `NOT APPLIED` clause is a clean install; anything else
 *     names the steps that did not apply and why.
 *
 *  5. NO `post_install running` LINE AT ALL, on a package_install.log that
 *     DOES carry that install's other lines (the scanner verdict,
 *     mergeModuleFiles), is the one case that still means "treat the wiring as
 *     broken" - that is what this line was always for. From rc69 it is emitted
 *     once per install request, because this file is no longer at a path Sugar
 *     runs a second time. TWO running lines under one PID means a reserved-name
 *     copy is shipping again.
 *
 * WHY EVERY LINE LOGS AT FATAL
 *
 * ->error() lines do not survive an instance whose log level is fatal. On the
 * install route MlpLogger has already forced the level to debug, so during an
 * install the level argument is moot; fatal is kept because these lines are
 * also read outside an install window, where the instance's own level applies.
 *
 * WHY EVERY LOCAL IS bd-PREFIXED
 *
 * require_once inside ModuleInstaller::post_execute() executes this file in
 * that METHOD's scope, which has already run extract($data) over the manifest.
 * An unprefixed $manifest/$installdefs/$modules here would overwrite the
 * installer's own locals mid-install. bd_pre_uninstall.php prefixes for the same
 * reason.
 */

// The installed version. ModuleInstaller::post_execute() has run
// extract($data) over the manifest before require_once'ing this file, so
// $manifest is in scope; it is read once, here, for the running line and the
// report. 'unknown' means this file was run some other way.
$bdVersion = isset($manifest['version']) ? (string) $manifest['version'] : 'unknown';

// Proof of life, and the line an operator takes this install's PID from. See
// "HOW TO READ IT" above. Once per install request: this file is not at a
// reserved path (see "WHY THIS FILE IS NOT NAMED post_install.php").
$GLOBALS['log']->fatal('BenchDogs-Ext: post_install running (' . $bdVersion . ') - writing deployed metadata');

// G294. Every block below writes exactly one entry here before it leaves, and
// the block at the bottom publishes the whole map. 'ok' means the step ran;
// anything else is what stopped it, verbatim. Three outcomes are NOT 'ok' and
// all three used to be invisible:
//   FAILED:     the step threw and its own catch swallowed it (the rc65 case)
//   MISSING:    the helper file the step needs is not on disk, so install_copy
//               did not land it - the step never ran at all
//   NOT-LOADED: the file is there and was require_once'd, but the class is
//               still undefined. That is the class_exists-without-require
//               shape pointed the other way, and it is a silent off switch.
$bdStepReport = array();

// Accounts (the customer group) and Quotes (the three ADM pickers, their
// labels, the defaults hook) are the only modules this package extends, so they
// are the only ones rebuilt. FIRST: the two layout steps below read the merged
// vardefs, and the markers they place by are in this package's Ext fragments.
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

// G380 (f) / 🔒 1724b: ERP-Core's ErpLayoutExtraFields places every field of
// the module whose vardef carries the `erp_layout` marker and that is on no
// panel yet. It never throws and never moves a field an admin placed. One block
// per module, so a failure names the module it hit.
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

// G380 / G381: Lead Source, Lead Type and Project on ERP-Epicor's ERP panel,
// after Reference (their markers).
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

// 🛑 THE STEP REPORT (0.9.42-rc67, G294). See "HOW A FAILED STEP IS REPORTED"
// at the top of this file for why this exists and why it must not throw.
//
// 🚩 EVERY LINE BELOW IS IN A CATCH OF ITS OWN, and the three publishes are
// SEPARATE catches on purpose: the whole point of this block is that a failure
// is not lost, so a reporting channel that is itself broken must not take the
// other two down with it. The one thing this block may never do is raise - a
// reporter that force-uninstalls the package is worse than the defect it
// reports. $bdVersion is set at the top of this file.
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

// CHANNEL 2 - the durable one. Written on every install, pass or fail: a clean
// report is the POSITIVE evidence, and channel 3 is silent on success.
// benchdogs_ext, not erp_integration: that category is core's, and a Bench
// diagnostic does not belong in it.
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

// CHANNEL 3 - installation-status, ONLY when something did not apply. This is
// the channel that makes `is_done` N/N stop reading clean over a failed step.
// It writes a string into the UpgradeHistory row's process_status and aborts
// nothing; see the header for why that is safe and how it was verified.
if ($bdNotApplied !== array()) {
    try {
        if (isset($this)
            && $this instanceof ModuleInstaller
            && method_exists($this, 'setInstallationError')) {
            // Leads with INSTALLED on purpose. The package IS on the tenant -
            // this field is the only public way to say "and one of its steps
            // did not apply", and an operator who reads it as "the install
            // failed" and uninstalls would be doing damage over a partial.
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
