<?php

/**
 * ONEOFF-MoveBdCustomerGroup (G507) - take the two Bench Dogs customer-group
 * fields OUT of the Account record HEADER and put them, labelled, on the
 * record's first tab. Once. Nothing else.
 *
 * THE DEFECT, MEASURED (Ophir, SugarEnt 26.1.0, served Accounts record view,
 * 2026-09-24): panel_header carried bd_customer_group and
 * bd_customer_group_code, so "Distribution" and "DIST" rendered UNLABELLED
 * beside the account name, where sellers read them as buttons. Owner, looking
 * at ADDISON WB I85L06: "can we move this fields into the overview?".
 *
 * HOW THEY GOT THERE. BenchDogs-Ext rc69's writer (BdAccountsLayoutExtensions::
 * writeCustomerGroupField, retired in rc70) appended the two fields to the panel
 * named panel_body, else to THE FIRST PANEL THAT HOLDS FIELDS. Ophir's Accounts
 * view has no panel_body - its tabs are panel_overview ("Overview"),
 * panel_phone_and_address, ... - and its first panel is panel_header.
 *
 * WHY A ONE-OFF AND NOT THE BENCH DOGS PACKAGE. 🔒 1724b: the shipped package
 * carries NO layout code; its fields carry ERP-Epicor's `erp_layout` marker and
 * ERP-Core's ErpLayoutExtraFields::sync() places them. sync() never moves a field
 * that is already on the view (so an admin's placement survives), and a header
 * entry IS on the view - so no marker change can take them out of the header.
 * The same shape as ONEOFF-RetireBdResidue: a disposable package for a one-time
 * repair of deployed metadata, so the shipped package stays minimal.
 *
 * WHAT IT DOES, on the deployed Accounts record view only:
 *   1. EVICT: removes bd_customer_group / bd_customer_group_code from every
 *      HEADER panel ('header' => true). Studio cannot place a field in the
 *      header (it skips the header panel), so a header entry is a package
 *      artefact, never an admin's choice.
 *   2. PLACE what it evicted, recreated as array('name', 'label') - the old
 *      entries are NOT moved: rc69-era header entries carry a baked 'type' =>
 *      'text' of unknown origin that has no business on a body panel - into THE
 *      FIRST TAB: the first non-header panel with 'newTab' set (panel_overview on
 *      Ophir; panel_body on a stock 26.1.0 view, which ERP-Epicor makes a tab),
 *      else the first non-header panel with fields. Position: after `industry`
 *      when that panel has it (beside Type / Industry), else at the end; the code
 *      follows the name. The same slots BenchDogs-Ext rc72's marker gives
 *      (panel_overview, after industry), so an upgraded tenant ends where a
 *      fresh install would.
 *      NOT placed, and said so in the log:
 *        - a field that is ALSO on a non-header panel (an admin put it there;
 *          only the header duplicate goes);
 *        - a field the module does not define (no vardef: Bench Dogs is not
 *          installed) - it is retired from the header and placed nowhere, never
 *          an orphaned placement (the G116 shape).
 *   3. Writes and clears the Accounts view caches ONLY when 1 or 2 changed
 *      something. A second run finds nothing in the header and writes nothing.
 *
 * WHAT IT DOES NOT DO: it installs no file (NO 'copy' key: uninstall_copy() has
 * nothing to walk or restore, so uninstalling this package is a genuine no-op and
 * cannot put the header entries back), creates or drops no table, writes no
 * record and no config row, touches no other view, panel, field or button. It
 * never adds a field that was not in the header. If no panel can take a field
 * it would evict, it changes NOTHING and says SKIPPED - a field is never simply
 * dropped off the view.
 *
 * SCANNER: no unlink / file functions, no dynamic dispatch (MLP017: no closures,
 * no variable calls), no ModuleBuilder parser (MLP019) - the view goes through
 * ViewdefManager, as ERP-Core's BaseErpLayout does. Every local is mv-prefixed:
 * ModuleInstaller::post_execute() require_once's this file inside its own method
 * after extract($data), so an unprefixed $manifest / $installdefs here would
 * overwrite the installer's locals mid-install. Nothing escapes the catch: on the
 * post_execute path an uncaught throw is a failed install.
 *
 * Tests: scripts/tests/bd_customer_group_move_test.php runs THIS file against
 * Ophir-shaped and stock-shaped views, and beside BenchDogs-Ext's real
 * lifecycle scripts and ERP-Core's real ErpLayoutExtraFields.
 */

$mvVersion = isset($manifest['version']) ? (string) $manifest['version'] : 'unknown';
$mvModule = 'Accounts';
// Canonical order: name first, code second (the code follows the name).
$mvFields = array(
    'bd_customer_group' => 'LBL_BD_CUSTOMER_GROUP',
    'bd_customer_group_code' => 'LBL_BD_CUSTOMER_GROUP_CODE',
);
$mvAfter = array(
    'bd_customer_group' => 'industry',
    'bd_customer_group_code' => 'bd_customer_group',
);
$mvOutcome = '';
$mvNotes = array();

try {
    $mvManager = new \Sugarcrm\Sugarcrm\MetaData\ViewdefManager();
    $mvDefs = $mvManager->loadViewdef('base', $mvModule, 'record');

    $mvBean = BeanFactory::newBean($mvModule);
    $mvFieldDefs = (is_object($mvBean) && isset($mvBean->field_defs) && is_array($mvBean->field_defs))
        ? $mvBean->field_defs : array();

    if (empty($mvDefs['panels']) || !is_array($mvDefs['panels'])) {
        $mvOutcome = 'SKIPPED';
        $mvNotes[] = 'the deployed Accounts record view has no panels; nothing was read or written';
    } elseif (!isset($mvFieldDefs['id'], $mvFieldDefs['name'])) {
        // A field list without id/name means the read failed, not that the
        // fields are undefined - evicting on that reading would drop them.
        $mvOutcome = 'SKIPPED';
        $mvNotes[] = 'the Accounts vardefs could not be read; nothing was changed';
    } else {
        // Names on any NON-header panel, fieldset members included.
        $mvBody = array();
        foreach ($mvDefs['panels'] as $mvPanel) {
            if (!empty($mvPanel['header']) || empty($mvPanel['fields']) || !is_array($mvPanel['fields'])) {
                continue;
            }
            foreach ($mvPanel['fields'] as $mvEntry) {
                if (is_string($mvEntry)) {
                    $mvBody[$mvEntry] = true;
                } elseif (is_array($mvEntry)) {
                    if (isset($mvEntry['name']) && is_string($mvEntry['name'])) {
                        $mvBody[$mvEntry['name']] = true;
                    }
                    if (isset($mvEntry['fields']) && is_array($mvEntry['fields'])) {
                        foreach ($mvEntry['fields'] as $mvMember) {
                            if (is_string($mvMember)) {
                                $mvBody[$mvMember] = true;
                            } elseif (is_array($mvMember) && isset($mvMember['name']) && is_string($mvMember['name'])) {
                                $mvBody[$mvMember['name']] = true;
                            }
                        }
                    }
                }
            }
        }

        // The first tab: the first non-header panel with newTab set, else the
        // first non-header panel that holds fields.
        $mvTarget = null;
        foreach ($mvDefs['panels'] as $mvIndex => $mvPanel) {
            if (empty($mvPanel['header']) && !empty($mvPanel['newTab'])) {
                $mvTarget = $mvIndex;
                break;
            }
        }
        if ($mvTarget === null) {
            foreach ($mvDefs['panels'] as $mvIndex => $mvPanel) {
                if (empty($mvPanel['header']) && !empty($mvPanel['fields']) && is_array($mvPanel['fields'])) {
                    $mvTarget = $mvIndex;
                    break;
                }
            }
        }

        // 1. EVICT from every header panel (on a copy, so a SKIPPED run writes nothing).
        $mvNew = $mvDefs;
        $mvEvicted = array();
        foreach ($mvNew['panels'] as $mvIndex => $mvPanel) {
            if (empty($mvPanel['header']) || empty($mvPanel['fields']) || !is_array($mvPanel['fields'])) {
                continue;
            }
            $mvKept = array();
            foreach ($mvPanel['fields'] as $mvEntry) {
                $mvName = is_string($mvEntry) ? $mvEntry
                    : ((is_array($mvEntry) && isset($mvEntry['name']) && is_string($mvEntry['name'])) ? $mvEntry['name'] : '');
                if ($mvName !== '' && isset($mvFields[$mvName])) {
                    $mvEvicted[$mvName] = true;
                    continue;
                }
                $mvKept[] = $mvEntry;
            }
            $mvNew['panels'][$mvIndex]['fields'] = $mvKept;
        }

        // 2. PLACE what was evicted, in canonical order.
        $mvToPlace = array();
        foreach ($mvFields as $mvName => $mvLabel) {
            if (!isset($mvEvicted[$mvName])) {
                continue;
            }
            if (isset($mvBody[$mvName])) {
                $mvNotes[] = $mvName . ': header duplicate removed; the placement elsewhere on the view is kept';
            } elseif (!isset($mvFieldDefs[$mvName])) {
                $mvNotes[] = $mvName . ': retired from the header and NOT placed (no vardef on this tenant)';
            } else {
                $mvToPlace[] = $mvName;
            }
        }

        if ($mvEvicted === array()) {
            $mvOutcome = 'ALREADY CLEAN';
            $mvNotes[] = 'neither field is in the Accounts header; nothing was written';
        } elseif ($mvToPlace !== array() && $mvTarget === null) {
            $mvOutcome = 'SKIPPED';
            $mvNotes = array('no non-header panel can hold ' . implode(', ', $mvToPlace)
                . '; the header was left exactly as it is');
        } else {
            $mvPanelName = $mvTarget === null ? '' : (string) ($mvNew['panels'][$mvTarget]['name'] ?? $mvTarget);
            foreach ($mvToPlace as $mvName) {
                if (!isset($mvNew['panels'][$mvTarget]['fields']) || !is_array($mvNew['panels'][$mvTarget]['fields'])) {
                    $mvNew['panels'][$mvTarget]['fields'] = array();
                }
                $mvAt = null;
                foreach (array_values($mvNew['panels'][$mvTarget]['fields']) as $mvPos => $mvEntry) {
                    $mvName2 = is_string($mvEntry) ? $mvEntry
                        : ((is_array($mvEntry) && isset($mvEntry['name']) && is_string($mvEntry['name'])) ? $mvEntry['name'] : '');
                    if ($mvName2 === $mvAfter[$mvName]) {
                        $mvAt = $mvPos;
                        break;
                    }
                }
                $mvNewEntry = array('name' => $mvName, 'label' => $mvFields[$mvName]);
                if ($mvAt === null) {
                    $mvNew['panels'][$mvTarget]['fields'][] = $mvNewEntry;
                } else {
                    $mvNew['panels'][$mvTarget]['fields'] = array_values($mvNew['panels'][$mvTarget]['fields']);
                    array_splice($mvNew['panels'][$mvTarget]['fields'], $mvAt + 1, 0, array($mvNewEntry));
                }
                $mvNotes[] = $mvName . ': moved from the header to ' . $mvPanelName
                    . ($mvAt === null ? ' (end of panel)' : ' (after ' . $mvAfter[$mvName] . ')');
            }

            // 3. WRITE, once, and clear what the parser's deploy() cleared.
            $mvManager->saveViewdef($mvNew, $mvModule, 'base', 'record');
            MetaDataFiles::clearModuleClientCache($mvModule, 'view');
            MetaDataFiles::clearModuleClientCache($mvModule, 'layout');
            include_once 'include/TemplateHandler/TemplateHandler.php';
            if (class_exists('TemplateHandler', false)) {
                TemplateHandler::clearCache($mvModule);
            }
            $mvOutcome = $mvToPlace === array() ? 'RETIRED FROM HEADER' : 'MOVED';
        }
    }
} catch (Throwable $mvError) {
    $mvOutcome = 'FAILED';
    $mvNotes[] = get_class($mvError) . ': ' . $mvError->getMessage();
}

$mvSummary = 'ONEOFF-MoveBdCustomerGroup ' . $mvVersion . ' (G507): ' . $mvOutcome
    . ($mvNotes === array() ? '' : ' - ' . implode('; ', $mvNotes));

echo '==================================================================' . "\n";
echo $mvSummary . "\n";
echo 'This package installed no file, created no table and wrote no record;' . "\n";
echo 'uninstalling it takes nothing back. Uninstall it now.' . "\n";
echo '==================================================================' . "\n";

// fatal() so the outcome survives any log level and lands in package_install.log
// (MlpLogger points the default logger there for the duration of an install).
$GLOBALS['log']->fatal($mvSummary);
