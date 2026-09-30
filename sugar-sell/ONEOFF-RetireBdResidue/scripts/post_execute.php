<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

// Removes what earlier Bench Dogs versions installed and the current one no longer ships (scripts/leftovers.php); Module Loader rebuilds every cache after post_execute. (🔒2126b)

$bdOneoffVersion = isset($manifest['version']) ? (string) $manifest['version'] : 'unknown';
$bdLeftovers = require __DIR__ . '/leftovers.php';
$bdRemoved = array();
$bdAlreadyGone = array();
$bdSkipped = array();
$bdFailed = array();

// 1. Every listed file, by its placeholder under leftovers/ (ModuleScanner denies unlink()).
$bdPresent = array();
foreach ($bdLeftovers['remove'] as $bdPath) {
    if (file_exists($bdPath)) {
        $bdPresent[] = $bdPath;
    } else {
        $bdAlreadyGone[] = $bdPath;
    }
}
try {
    $this->uninstall_new_files(
        array('from' => $this->base_dir . '/leftovers', 'to' => '.'),
        $this->base_dir . '/no-backup'
    );
} catch (Throwable $e) {
    $bdFailed[] = 'the listed files (' . $e->getMessage() . ')';
}
foreach ($bdPresent as $bdPath) {
    if (file_exists($bdPath)) {
        $bdFailed[] = $bdPath . ' (still present after uninstall_new_files)';
    } else {
        $bdRemoved[] = $bdPath;
    }
}

// 2. Shared paths, deleted only when they hold a body Bench Dogs shipped; each has its own placeholder subtree under leftovers/if-bench/. (G599, G594)
$bdPlanner = 'custom/modules/Quotes/BdSubmitOrderPlan.php';
$bdAdapter = 'custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php';
$bdGuardIndex = 0;
foreach ($bdLeftovers['remove_if_bench'] as $bdPath => $bdBenchMd5) {
    $bdSubtree = $this->base_dir . '/leftovers/if-bench/' . $bdGuardIndex++ . '/' . dirname($bdPath);
    if (!file_exists($bdPath)) {
        $bdAlreadyGone[] = $bdPath;
        continue;
    }
    $bdMd5 = md5_file($bdPath);
    if (!in_array($bdMd5, $bdBenchMd5, true)) {
        $bdSkipped[] = $bdPath . ' - LEFT: not a Bench body, md5 ' . $bdMd5 . '; another writer owns what is there.';
        continue;
    }
    if ($bdPath === $bdAdapter && file_exists($bdPlanner)) {
        $bdSkipped[] = $bdPath . " - LEFT: Bench Dogs' adapter, but its planner " . $bdPlanner . ' is still there, so the pair still answers.';
        continue;
    }
    try {
        $this->uninstall_new_files(array('from' => $bdSubtree, 'to' => dirname($bdPath)), $this->base_dir . '/no-backup');
    } catch (Throwable $e) {
        $bdFailed[] = $bdPath . ' (' . $e->getMessage() . ')';
        continue;
    }
    if (file_exists($bdPath)) {
        $bdFailed[] = $bdPath . ' (still present after uninstall_new_files)';
    } else {
        $bdRemoved[] = $bdPath . ' (a body Bench Dogs shipped, md5 ' . $bdMd5 . ')';
    }
}

// 3. Whole directories nothing else writes.
$bdDirsPresent = array();
foreach ($bdLeftovers['directories'] as $bdDir) {
    if (file_exists('custom/modules/' . $bdDir)) {
        $bdDirsPresent[] = $bdDir;
    } else {
        $bdAlreadyGone[] = 'custom/modules/' . $bdDir . '/';
    }
}
if ($bdDirsPresent) {
    try {
        $this->uninstall_customizations($bdDirsPresent);
    } catch (Throwable $e) {
        $bdFailed[] = 'the retired directories (' . $e->getMessage() . ')';
    }
    foreach ($bdDirsPresent as $bdDir) {
        if (file_exists('custom/modules/' . $bdDir)) {
            $bdFailed[] = 'custom/modules/' . $bdDir . '/ (still present)';
        } else {
            $bdRemoved[] = 'custom/modules/' . $bdDir . '/';
        }
    }
}

// 4. K-2: the retired Bench Dogs panel off the deployed Quotes record view (🔒 1045).
try {
    $bdViews = new ViewdefManager();
    $bdRecord = $bdViews->loadViewdef('base', 'Quotes', 'record');
    $bdPanels = is_array($bdRecord) && is_array($bdRecord['panels'] ?? null) ? $bdRecord['panels'] : array();
    $bdKept = array();
    foreach ($bdPanels as $bdPanel) {
        if (!(is_array($bdPanel) && ($bdPanel['name'] ?? '') === 'LBL_RECORDVIEW_PANEL_BENCHDOGS')) {
            $bdKept[] = $bdPanel;
        }
    }
    if (count($bdKept) !== count($bdPanels)) {
        $bdRecord['panels'] = $bdKept;
        $bdViews->saveViewdef($bdRecord, 'Quotes', 'base', 'record');
        $bdRemoved[] = 'K-2 Bench Dogs panel spliced out of the deployed Quotes record view';
    } else {
        $bdAlreadyGone[] = 'K-2 Bench Dogs panel - already absent from the deployed Quotes record view';
    }
} catch (Throwable $e) {
    $bdFailed[] = 'K-2 Quotes panel (' . $e->getMessage() . ')';
}

// 5. K-3: the retired bd_governing_origin marker off the deployed Opportunities record view (G116).
try {
    $bdViews = new ViewdefManager();
    $bdRecord = $bdViews->loadViewdef('base', 'Opportunities', 'record');
    $bdFound = false;
    if (is_array($bdRecord) && is_array($bdRecord['panels'] ?? null)) {
        foreach ($bdRecord['panels'] as $bdAt => $bdPanel) {
            if (!is_array($bdPanel) || !is_array($bdPanel['fields'] ?? null)) {
                continue;
            }
            $bdFields = array();
            foreach ($bdPanel['fields'] as $bdField) {
                if ((is_array($bdField) ? ($bdField['name'] ?? '') : (string) $bdField) === 'bd_governing_origin') {
                    $bdFound = true;
                    continue;
                }
                $bdFields[] = $bdField;
            }
            $bdRecord['panels'][$bdAt]['fields'] = $bdFields;
        }
    }
    if ($bdFound) {
        $bdViews->saveViewdef($bdRecord, 'Opportunities', 'base', 'record');
        $bdRemoved[] = 'K-3 retired bd_governing_origin marker removed from the deployed Opportunities record view';
    } else {
        $bdAlreadyGone[] = 'K-3 retired bd_governing_origin marker - already absent from the deployed Opportunities record view';
    }
} catch (Throwable $e) {
    $bdFailed[] = 'K-3 Opportunity marker (' . $e->getMessage() . ')';
}

// Left on purpose, named on every run so "I left it alone" reads as plainly as "I removed it".
$bdNotOurs = array(
    'custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php'
        . ' - KEPT BY rc69: the customer category (🔒 1508 / 🔒 1514 / 🔒 1567).',
    'custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php'
        . ' - KEPT BY rc69: their two labels.',
    'custom/modules/Accounts/BdAccountsLayoutExtensions.php'
        . ' - KEPT BY rc69: placed the two kept fields; inert since rc70.',
    'custom/modules/Quotes/BdQuotesLayoutExtensions.php'
        . ' - not shipped since rc69 and inert: nothing ships or calls it.',
    'custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php'
        . ' - the same.',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php'
        . ' - ALSO SHIPPED BY Partial Fulfillment at the same path (G280 / 🔒 1508 / 🔒 1511).',
    'custom/modules/Quotes/ErpQuoteHooks/OpportunityLineRollupPolicy.php'
        . ' - hook path; Partial Fulfillment no longer consults it (🔒 1468).',
    'custom/modules/Quotes/ErpQuoteHooks/OrderSelectedLinesPolicy.php'
        . ' - hook path; with the selector blank it answers "no objection".',
    'custom/modules/ProductBundles/clients/base/views/quote-data-group-list/quote-data-group-list.js'
        . ' - ERP-Core ships this exact path.',
    'custom/modules/Products/clients/base/views/quote-data-group-list/quote-data-group-list.php'
        . ' - the Products grid viewdef ERP-Core also manages.',
);

echo '==================================================================' . "\n";
echo 'ONEOFF-RetireBdResidue ' . $bdOneoffVersion . ' - Bench Dogs retirement sweep' . "\n";
echo '==================================================================' . "\n";
if ($bdRemoved) {
    echo 'REMOVED (' . count($bdRemoved) . '):' . "\n";
    foreach ($bdRemoved as $bdItem) {
        echo '  - ' . $bdItem . "\n";
    }
} else {
    echo 'REMOVED (0): NOTHING LEFT TO REMOVE.' . "\n";
    echo '  This tenant is SPENT for every item this package carries.' . "\n";
}
echo 'ALREADY GONE (' . count($bdAlreadyGone) . ') - nothing to do for these:' . "\n";
foreach ($bdAlreadyGone as $bdItem) {
    echo '  . ' . $bdItem . "\n";
}
if ($bdSkipped) {
    echo 'SKIPPED (' . count($bdSkipped) . ') - READ THESE, the sweep is INCOMPLETE:' . "\n";
    foreach ($bdSkipped as $bdItem) {
        echo '  ? ' . $bdItem . "\n";
    }
}
if ($bdFailed) {
    echo 'FAILED (' . count($bdFailed) . ') - READ THESE, the sweep is INCOMPLETE:' . "\n";
    foreach ($bdFailed as $bdItem) {
        echo '  ! ' . $bdItem . "\n";
    }
}
echo 'NOT TOUCHED ON PURPOSE (' . count($bdNotOurs) . '):' . "\n";
foreach ($bdNotOurs as $bdItem) {
    echo '  = ' . $bdItem . "\n";
}
echo 'This package installs no file and writes no record; uninstall it now, the removals stay.' . "\n";
echo '==================================================================' . "\n";

$GLOBALS['log']->fatal(sprintf(
    'ONEOFF-RetireBdResidue %s: removed %d, already gone %d, skipped %d, failed %d, left alone on purpose %d. %s'
        . ' REMOVED: %s. SKIPPED: %s. FAILED: %s.',
    $bdOneoffVersion,
    count($bdRemoved),
    count($bdAlreadyGone),
    count($bdSkipped),
    count($bdFailed),
    count($bdNotOurs),
    $bdRemoved ? '' : 'NOTHING LEFT TO REMOVE - this tenant is spent.',
    $bdRemoved ? implode(' | ', $bdRemoved) : '(none)',
    $bdSkipped ? implode(' | ', $bdSkipped) : '(none)',
    $bdFailed ? implode(' | ', $bdFailed) : '(none)'
));
