<?php

/**
 * G848 (0.9.42-rc84, owner 🔒2151b) - Bench Dogs sellers do not apply
 * discounts. THE QUOTE'S LINE GRID has no "Line Discount" column and no
 * discount input in its edit row.
 *
 * WHAT GOES HERE: discount_field, stock's line-discount fieldset (ERP-Core
 * labels it LBL_ERP_LINE_DISCOUNT, "Line Discount", and places it between
 * Extended Price and Discounted Total, 🔒 1439): discount_amount (the figure)
 * and discount_select (the % / amount toggle). The rows, the edit row, the
 * column headers (Quotes quote-data-list-header) and the group layout all
 * build their columns from THIS view (app.metadata.getView('Products',
 * 'quote-data-group-list')), so one overlay takes the column off all of them.
 * Also those two members placed loose, and the line-discount figures derived
 * from them, if an admin configured one (Admin > Quotes Configuration's
 * worksheet columns write this view).
 *
 * NOT a discount, and so NOT here: discount_price is the Unit Price and
 * discount_usdollar its base-currency twin; subtotal (Extended Price) and
 * total_amount (Discounted Total, the line total) stay.
 *
 * WHAT STAYS: the stored discount and the math. Each line is still read with
 * discount_amount and discount_select (the quote record view's nested fetch
 * list, which nothing here touches), so a discount Epicor's price list or
 * estimator sets still lands in total_amount and the quote total. ERP-Core's
 * grid controller locks the discount fields it finds on a row; it finds none
 * and counts none.
 *
 * The mechanism, why it writes nothing and why uninstall gives the column
 * back: see the Quotes record view's fragment of the same name
 * (custom/Extension/modules/Quotes/Ext/clients/base/views/record/). Same body,
 * byte for byte; scripts/tests/bd_seller_discounts_hidden_test.php.
 */
$bdHideModule = 'Products';
$bdHideView = 'quote-data-group-list';
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

// ---- one body, the same in all five files ----
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
