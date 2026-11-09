<?php

/** Sets the displayed module tabs and the hidden subpanels, reads both back, and reports any step that did not apply. */

// The installed version, from the manifest ModuleInstaller::post_execute() extracted into scope.
$uatVersion = isset($manifest['version']) ? (string) $manifest['version'] : 'unknown';

// Displayed tabs, in this order, as Admin > Display Modules and Subpanels saves them.
$uatTabs = array(
    'Home',
    'Accounts',
    'Contacts',
    'Opportunities',
    'Quotes',
    'Reports',
    'ERP_Orders',
    'ERP_Invoices',
    'pmse_Inbox',
    'pmse_Project',
    'pmse_Business_Rules',
    'pmse_Emails_Templates',
    'ERP_SalesTargets',
);

// Hidden subpanels, by the subpanel names that screen uses.
$uatHiddenSubpanels = array(
    'calls',
    'leads',
    'project',
    'bugs',
    'products',
    'contracts',
    'dataprivacy',
    'cj_forms',
    'dri_workflows',
    'dri_workflow_task_templates',
    'cj_webhooks',
    'externalusers',
    'changetimers',
    'revenuelineitems',
);

$GLOBALS['log']->fatal('BenchDogs-UAT-Config: post_install running (' . $uatVersion . ')');

// Each step records ok or why not; nothing here may throw, because a throw makes Module Loader uninstall the package.
$uatSteps = array();

try {
    if (!class_exists('TabController')) {
        require_once 'modules/MySettings/TabController.php';
    }
    $uatTabController = new TabController();
    $uatTabController->set_system_tabs($uatTabs);
    // Read back without the access filter but with the module-list check, so a tab for a module this Sugar lacks shows.
    $uatTabsNow = array_values($uatTabController->get_system_tabs(false, true));
    if ($uatTabsNow === $uatTabs) {
        $uatSteps['tabs'] = 'ok';
    } else {
        $uatSteps['tabs'] = 'NOT APPLIED: the tabs read back as ' . implode(', ', $uatTabsNow);
    }
} catch (Throwable $e) {
    $uatSteps['tabs'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
}

try {
    if (!class_exists('SubPanelDefinitions')) {
        require_once 'include/SubPanel/SubPanelDefinitions.php';
    }
    // set_hidden_subpanels stores the KEYS of what it is given, as the admin screen passes them.
    $uatHiddenKeys = array();
    foreach ($uatHiddenSubpanels as $uatPanel) {
        $uatHiddenKeys[$uatPanel] = $uatPanel;
    }
    SubPanelDefinitions::set_hidden_subpanels($uatHiddenKeys);
    $uatHiddenNow = SubPanelDefinitions::get_hidden_subpanels();
    if (is_string($uatHiddenNow)) {
        $uatHiddenNow = json_decode($uatHiddenNow, true);
    }
    $uatHiddenNow = is_array($uatHiddenNow) ? array_values($uatHiddenNow) : array();
    $uatHiddenWanted = $uatHiddenSubpanels;
    sort($uatHiddenNow);
    sort($uatHiddenWanted);
    if ($uatHiddenNow === $uatHiddenWanted) {
        $uatSteps['hidden_subpanels'] = 'ok';
    } else {
        $uatSteps['hidden_subpanels'] = 'NOT APPLIED: the hidden subpanels read back as ' . implode(', ', $uatHiddenNow);
    }
} catch (Throwable $e) {
    $uatSteps['hidden_subpanels'] = 'FAILED: ' . get_class($e) . ': ' . $e->getMessage();
}

$uatNotApplied = array();
foreach ($uatSteps as $uatStep => $uatOutcome) {
    if ($uatOutcome !== 'ok') {
        $uatNotApplied[] = $uatStep . ' -> ' . substr($uatOutcome, 0, 500);
    }
}

try {
    $GLOBALS['log']->fatal('BenchDogs-UAT-Config: post_install step report (' . $uatVersion . '): '
        . (count($uatSteps) - count($uatNotApplied)) . '/' . count($uatSteps) . ' applied'
        . ($uatNotApplied === array() ? '' : '; NOT APPLIED: ' . implode(' | ', $uatNotApplied)));
} catch (Throwable $e) {
    // Nothing useful is left to log to.
}

// A step that did not apply goes on the install's status line too; it aborts nothing.
if ($uatNotApplied !== array()) {
    try {
        if (isset($this) && $this instanceof ModuleInstaller && method_exists($this, 'setInstallationError')) {
            $this->setInstallationError(
                'Bench Dogs UAT Config ' . $uatVersion . ' IS INSTALLED, but ' . count($uatNotApplied) . ' of '
                . count($uatSteps) . ' navigation steps did NOT apply: ' . implode(' | ', $uatNotApplied)
                . ' -- set them in Admin > Display Modules and Subpanels.'
            );
        }
    } catch (Throwable $e) {
        $GLOBALS['log']->fatal('BenchDogs-UAT-Config: step report to installation-status failed: ' . $e->getMessage());
    }
}

$GLOBALS['log']->fatal('BenchDogs-UAT-Config: post_install finished');
