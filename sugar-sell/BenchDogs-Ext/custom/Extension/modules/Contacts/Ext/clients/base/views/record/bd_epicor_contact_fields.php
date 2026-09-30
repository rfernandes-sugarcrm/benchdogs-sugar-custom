<?php

/**
 * G458 (0.9.42-rc82) - THE FIVE EPICOR CONTACT FIELDS ON THE CONTACTS RECORD
 * VIEW, READ-ONLY, AND NOTHING ON A TENANT THAT DOES NOT HAVE THEM.
 *
 * THE DEFECT. The Bench connector extension (0.3.6, level L807) writes Epicor
 * CustCnt Func / RoleCode / PrimaryBilling / PrimaryPurchasing / PrimaryShipping
 * onto epicor_function_c / role_c / primary_billing_c / primary_purchasing_c /
 * primary_shipping_c (1,510 benchdogs-dev contacts, 2026-09-30 11:13Z), and no
 * Contacts view showed any of them (Codex on screen, 11:20-11:22Z: "Not shown"
 * for all five on five contacts). The five are NOT this package's fields: Bench
 * Dogs' own package Bench_Dogs_Account_Contact_Fields 1.0.0 creates them
 * (custom_fields installdefs, so they live in fields_meta_data), and it ships
 * no layout.
 *
 * WHERE. ERP-Core's "ERP" panel (LBL_RECORDVIEW_PANEL_ERP, placed by
 * ERP-Core's ContactsLayout; served on benchdogs-dev and benchdogs-sandbox with
 * the write-back status / at / message and the ERP Contact ID, every entry
 * readonly), after what is already there: the five are Epicor's values about
 * this contact, one-way like the rest of that panel. In Epicor order: Function,
 * Role, Primary Billing, Primary Purchasing, Primary Shipping. No ERP panel (an
 * admin removed it) -> the end of panel_body -> the first non-header panel with
 * fields; the header never.
 *
 * READ-ONLY on every entry of the five on this view, including one an admin
 * placed (it is not moved). Epicor owns them and the sync is ERP -> Sugar only
 * (owner, G458 🔒2102b), so an edit in Sugar would be overwritten by the next
 * pass. The customer's package locks only the three Primary flags (its vardef
 * override); Function and Role are locked here, on this view.
 *
 * WHY A METADATA-TIME OVERLAY AND NOT THE `erp_layout` MARKER this package uses
 * for its own Quote and Account fields (🔒 1724b):
 *  - ERP-Core's ErpLayoutExtraFields::sync() supports Quotes and Accounts only
 *    (DEFAULT_PANELS); sync('Contacts') refuses. Extending it is an ERP-Core
 *    release plus a floor bump, for a panel ERP-Core does not own the fields of.
 *  - The marker is a key on the FIELD's vardef, and these fields are not ours.
 *    SugarEnt 26.1.0 includes the compiled Ext vardefs BEFORE DynamicField
 *    merges fields_meta_data (VardefManager::refreshVardefs, 677-732;
 *    DynamicField::saveToVardef fills only keys still unset and drops only defs
 *    that carry custom_module). So a marker fragment cannot see whether the
 *    customer's field exists (a guard there is always false - an off switch),
 *    and on a tenant WITHOUT the customer's package it would leave a typeless
 *    phantom def ['erp_layout' => ...] in the Contact dictionary.
 * This file writes NOTHING to the tenant's deployed viewdef (no second writer
 * on ERP-Core's view, the problem 🔒 1724b solved). Sugar concatenates this
 * directory into custom/modules/Contacts/Ext/clients/base/views/record/
 * record.ext.php (ModuleInstaller::rebuild_extensions, extension 'sidecar') and
 * includes it right after the Contacts record viewdef - base, ERP-Core's or
 * Studio's - is loaded (MetaDataFiles::getClientFileContents, 1240-1247). So it
 * follows a tenant's own layout, reaches an upgraded tenant on install (no
 * add-if-absent trap), and leaves with this package's uninstall (uninstall_copy
 * deletes it, uninstall_extensions recompiles, install/uninstall clear the API
 * metadata cache). Same shape as ERP-Core's G785 recorddashlet overlay
 * (Accounts/Ext/clients/base/views/recorddashlet/erp_rule_inputs.php).
 *
 * THE GUARD - never reference a field the tenant does not have. A field is
 * placed only when the Contact vardefs MERGED with fields_meta_data (what
 * VardefManager::loadVardef leaves in the dictionary; getClientFiles has already
 * built a Contacts bean for this same request) hold it with a type. On Ophir,
 * stock and et (no customer package) this file changes nothing. Vardefs that
 * look unread (no id / name), a field list that is not an array, or any
 * Throwable: the view is left exactly as read.
 *
 * CONCATENATED WITH OTHER FILES AND INCLUDED INSIDE A SUGAR METHOD - and the
 * loop reaches record.ext.php a second time as its own entry, so this runs more
 * than once per build: no function or class declarations, no reference (&),
 * every variable bd-prefixed and unset at the end, and every step idempotent
 * (a field already on the view is never added again).
 *
 * scripts/tests/bd_contact_fields_test.php runs this file the way
 * getClientFileContents does, on the view benchdogs-dev serves.
 */
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
