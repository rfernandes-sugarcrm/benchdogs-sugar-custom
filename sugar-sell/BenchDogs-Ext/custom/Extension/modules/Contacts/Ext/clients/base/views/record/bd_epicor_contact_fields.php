<?php

/** G458 (🔒2102b): the five Epicor contact fields, read-only on the Contacts record view, and nothing on a tenant that does not have them. */
$bdContactPanels = $viewdefs['Contacts']['base']['view']['record']['panels'] ?? null;
if (is_array($bdContactPanels) && $bdContactPanels !== array()) {
    try {
        // Epicor CustCnt Func, RoleCode, PrimaryBilling, PrimaryPurchasing, PrimaryShipping.
        $bdEpicorContactFields = array(
            'epicor_function_c',
            'role_c',
            'primary_billing_c',
            'primary_purchasing_c',
            'primary_shipping_c',
        );

        // THE GUARD: the merged vardefs, field by field.
        VardefManager::loadVardef('Contacts', 'Contact');
        $bdContactDefs = $GLOBALS['dictionary']['Contact']['fields'] ?? null;
        $bdHave = array();
        if (is_array($bdContactDefs) && isset($bdContactDefs['id'], $bdContactDefs['name'])) {
            foreach ($bdEpicorContactFields as $bdField) {
                $bdDef = $bdContactDefs[$bdField] ?? null;
                if (is_array($bdDef) && isset($bdDef['type']) && is_string($bdDef['type']) && $bdDef['type'] !== '') {
                    $bdHave[$bdField] = (isset($bdDef['vname']) && is_string($bdDef['vname'])) ? $bdDef['vname'] : '';
                }
            }
        }

        if ($bdHave !== array()) {
            // 1. READ-ONLY on every entry already on the view (an admin's
            // placement included, fieldset members one level down), and note
            // where each one already is. Nothing is moved.
            $bdPlaced = array();
            foreach ($bdContactPanels as $bdPanelKey => $bdPanel) {
                if (!is_array($bdPanel) || !isset($bdPanel['fields']) || !is_array($bdPanel['fields'])) {
                    continue;
                }
                foreach ($bdPanel['fields'] as $bdEntryKey => $bdEntry) {
                    $bdName = is_string($bdEntry) ? $bdEntry
                        : ((is_array($bdEntry) && isset($bdEntry['name']) && is_string($bdEntry['name'])) ? $bdEntry['name'] : '');
                    if (isset($bdHave[$bdName]) && !isset($bdEntry['fields'])) {
                        $bdEntry = is_string($bdEntry) ? array('name' => $bdEntry) : $bdEntry;
                        $bdEntry['readonly'] = true;
                        $bdContactPanels[$bdPanelKey]['fields'][$bdEntryKey] = $bdEntry;
                        $bdPlaced[$bdName] = true;
                    }
                    if (is_array($bdEntry) && isset($bdEntry['fields']) && is_array($bdEntry['fields'])) {
                        foreach ($bdEntry['fields'] as $bdMemberKey => $bdMember) {
                            $bdMemberName = is_string($bdMember) ? $bdMember
                                : ((is_array($bdMember) && isset($bdMember['name']) && is_string($bdMember['name'])) ? $bdMember['name'] : '');
                            if (!isset($bdHave[$bdMemberName])) {
                                continue;
                            }
                            $bdMember = is_string($bdMember) ? array('name' => $bdMember) : $bdMember;
                            $bdMember['readonly'] = true;
                            $bdContactPanels[$bdPanelKey]['fields'][$bdEntryKey]['fields'][$bdMemberKey] = $bdMember;
                            $bdPlaced[$bdMemberName] = true;
                        }
                    }
                }
            }

            // 2. PLACE the ones on no panel: the ERP panel, else panel_body,
            // else the first non-header panel with fields. None -> nothing.
            $bdTarget = null;
            foreach (array('LBL_RECORDVIEW_PANEL_ERP', 'panel_body') as $bdWanted) {
                foreach ($bdContactPanels as $bdPanelKey => $bdPanel) {
                    if ($bdTarget === null && is_array($bdPanel) && ($bdPanel['name'] ?? '') === $bdWanted) {
                        $bdTarget = $bdPanelKey;
                    }
                }
            }
            if ($bdTarget === null) {
                foreach ($bdContactPanels as $bdPanelKey => $bdPanel) {
                    if ($bdTarget === null && is_array($bdPanel) && empty($bdPanel['header'])
                        && isset($bdPanel['fields']) && is_array($bdPanel['fields']) && $bdPanel['fields'] !== array()) {
                        $bdTarget = $bdPanelKey;
                    }
                }
            }
            if ($bdTarget !== null) {
                if (!isset($bdContactPanels[$bdTarget]['fields']) || !is_array($bdContactPanels[$bdTarget]['fields'])) {
                    $bdContactPanels[$bdTarget]['fields'] = array();
                }
                foreach ($bdHave as $bdField => $bdLabel) {
                    if (isset($bdPlaced[$bdField])) {
                        continue;
                    }
                    $bdEntry = array('name' => $bdField, 'readonly' => true);
                    if ($bdLabel !== '') {
                        $bdEntry['label'] = $bdLabel;
                    }
                    $bdContactPanels[$bdTarget]['fields'][] = $bdEntry;
                    $bdPlaced[$bdField] = true;
                }
            }

            $viewdefs['Contacts']['base']['view']['record']['panels'] = $bdContactPanels;
        }
    } catch (Throwable $bdError) {
        // The view stays exactly as read: $viewdefs is assigned only at the end
        // of the try. A layout nicety never fails a metadata build.
        if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
            $GLOBALS['log']->error('BenchDogs-Ext: Contacts Epicor fields not placed: ' . $bdError->getMessage());
        }
    }
}
unset($bdContactPanels, $bdEpicorContactFields, $bdContactDefs, $bdHave, $bdField, $bdDef, $bdPlaced, $bdPanelKey,
    $bdPanel, $bdEntryKey, $bdEntry, $bdName, $bdMemberKey, $bdMember, $bdMemberName, $bdTarget, $bdWanted, $bdLabel,
    $bdError);
