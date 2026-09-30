<?php

/**
 * G848 (0.9.42-rc84, owner 🔒2151b) - Bench Dogs sellers do not apply
 * discounts. THE QUOTE'S TOTALS STRIP (the bar above the lines) shows no
 * "Order Level Discount".
 *
 * WHAT GOES HERE: deal_tot. ERP-Epicor moves stock's first-place "Discount"
 * cell to follow new_sub and labels it LBL_ERP_DOCUMENT_DISCOUNT_AMOUNT
 * ("Order Level Discount"; QuotesLayout::erpTotalsHeaderFields(), 🔒 1544a),
 * and its Quotes currency field draws the order-level discount and its % in
 * it. Matched by NAME, so stock's own label goes the same way. Also any cell
 * an admin configured from the same quote-level figures (deal_tot's two
 * twins, discount, erp_document_discount_amount / _percent - Admin > Quotes
 * Configuration's summary columns write this view).
 *
 * WHAT STAYS: Line Items Discounted Subtotal (new_sub), Tax (via ERP), Shipping
 * and Grand Total, as they are and in order. deal_tot's related_fields and the
 * record's fetch list are never entered: the figure is still READ and still
 * computed, only not drawn. ERP-Epicor's header controller finds no deal_tot
 * cell to space (getField() returns nothing and it returns).
 *
 * The mechanism, why it writes nothing and why uninstall gives the cell back:
 * see the Quotes record view's fragment of the same name
 * (custom/Extension/modules/Quotes/Ext/clients/base/views/record/). Same body,
 * byte for byte; scripts/tests/bd_seller_discounts_hidden_test.php.
 */
$bdHideModule = 'Quotes';
$bdHideView = 'quote-data-grand-totals-header';
// ERP-Epicor's seller discount control, and the quote-level discount figures.
$bdHideNames = array(
    'erp_discount_panel',
    'deal_tot',
    'deal_tot_usdollar',
    'deal_tot_discount_percentage',
    'discount',
    'erp_document_discount_amount',
    'erp_document_discount_percent',
);
// The panel ERP-Epicor builds around erp_discount_panel: taken off once empty.
$bdHidePanelNames = array('LBL_RECORDVIEW_PANEL_ERP_DISCOUNT');

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
