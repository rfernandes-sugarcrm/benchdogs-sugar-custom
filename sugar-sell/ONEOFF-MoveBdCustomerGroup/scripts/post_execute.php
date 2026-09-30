<?php

/** ONEOFF-MoveBdCustomerGroup (G507): take the two customer-group fields out of the Account record header and put them, labelled, on the record's first tab (🔒 1724b, G116). */

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
