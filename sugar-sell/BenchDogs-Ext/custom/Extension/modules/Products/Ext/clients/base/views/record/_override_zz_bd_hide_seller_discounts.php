<?php

/** G848 (🔒2151b): the quote line's own record page draws no discount_field. */
$bdHideModule = 'Products';
$bdHideView = 'record';
// The line discount and the figures derived from it. Never discount_price or
// discount_usdollar: those are the Unit Price.
$bdHideNames = array(
    'discount_field',
    'discount_amount',
    'discount_select',
    'discount_rate_percent',
    'discount_amount_usdollar',
    'discount_amount_signed',
    'deal_calc',
    'deal_calc_usdollar',
);
// No panel of its own to take off.
$bdHidePanelNames = array();

// ---- one body, the same in every file ----
$bdHidePanels = $viewdefs[$bdHideModule]['base']['view'][$bdHideView]['panels'] ?? null;
if (is_array($bdHidePanels) && $bdHidePanels !== array()) {
    try {
        $bdHideChanged = false;
        $bdHideKept = array();
        foreach ($bdHidePanels as $bdHidePanel) {
            if (is_array($bdHidePanel) && isset($bdHidePanel['fields']) && is_array($bdHidePanel['fields'])) {
                $bdHideFields = array();
                $bdHideDropped = false;
                foreach ($bdHidePanel['fields'] as $bdHideEntry) {
                    $bdHideName = is_string($bdHideEntry) ? $bdHideEntry
                        : ((is_array($bdHideEntry) && isset($bdHideEntry['name']) && is_string($bdHideEntry['name']))
                            ? $bdHideEntry['name'] : '');
                    if ($bdHideName !== '' && in_array($bdHideName, $bdHideNames, true)) {
                        $bdHideDropped = true;
                        continue;
                    }
                    // A fieldset, one level down. Never related_fields: that
                    // is what the view READS, not what it draws.
                    if (is_array($bdHideEntry) && isset($bdHideEntry['fields']) && is_array($bdHideEntry['fields'])) {
                        $bdHideMembers = array();
                        $bdHideMemberDropped = false;
                        foreach ($bdHideEntry['fields'] as $bdHideMember) {
                            $bdHideMemberName = is_string($bdHideMember) ? $bdHideMember
                                : ((is_array($bdHideMember) && isset($bdHideMember['name']) && is_string($bdHideMember['name']))
                                    ? $bdHideMember['name'] : '');
                            if ($bdHideMemberName !== '' && in_array($bdHideMemberName, $bdHideNames, true)) {
                                $bdHideMemberDropped = true;
                                continue;
                            }
                            $bdHideMembers[] = $bdHideMember;
                        }
                        if ($bdHideMemberDropped) {
                            $bdHideDropped = true;
                            if ($bdHideMembers === array()) {
                                // A fieldset of nothing but discounts goes with them.
                                continue;
                            }
                            $bdHideEntry['fields'] = $bdHideMembers;
                        }
                    }
                    $bdHideFields[] = $bdHideEntry;
                }
                if ($bdHideDropped) {
                    // Rebuilt by appending, so it stays a list: a gap in the
                    // keys would reach Sidecar as a JSON object, not an array.
                    $bdHidePanel['fields'] = $bdHideFields;
                    $bdHideChanged = true;
                }
            }
            $bdHidePanelName = (is_array($bdHidePanel) && isset($bdHidePanel['name']) && is_string($bdHidePanel['name']))
                ? $bdHidePanel['name'] : '';
            if ($bdHidePanelName !== '' && in_array($bdHidePanelName, $bdHidePanelNames, true)
                && (!isset($bdHidePanel['fields']) || $bdHidePanel['fields'] === array())) {
                $bdHideChanged = true;
                continue;
            }
            $bdHideKept[] = $bdHidePanel;
        }
        if ($bdHideChanged) {
            $viewdefs[$bdHideModule]['base']['view'][$bdHideView]['panels'] = $bdHideKept;
        }
    } catch (Throwable $bdHideError) {
        // The view stays exactly as read: $viewdefs is assigned only at the
        // end of the try. A layout rule never fails a metadata build.
        if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
            $GLOBALS['log']->error('BenchDogs-Ext: ' . $bdHideModule . ' ' . $bdHideView
                . ' discounts not hidden: ' . $bdHideError->getMessage());
        }
    }
}
unset($bdHideModule, $bdHideView, $bdHideNames, $bdHidePanelNames, $bdHidePanels, $bdHideChanged, $bdHideKept,
    $bdHidePanel, $bdHideFields, $bdHideDropped, $bdHideEntry, $bdHideName, $bdHideMembers, $bdHideMemberDropped,
    $bdHideMember, $bdHideMemberName, $bdHidePanelName, $bdHideError);
