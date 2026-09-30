<?php

/**
 * G848 (0.9.42-rc84, owner 🔒2151b) - Bench Dogs sellers do not apply
 * discounts. THE QUOTE'S TOTALS FOOTER shows no "Order Level Discount" row.
 *
 * WHAT GOES HERE: erp_document_discount_amount, the row ERP-Epicor adds before
 * Shipping with the same label as the header strip's cell,
 * LBL_ERP_DOCUMENT_DISCOUNT_AMOUNT ("Order Level Discount";
 * QuotesLayout::erpTotalsFooterFields()); its controller shows it only when
 * the figure is not zero, i.e. when Epicor sent an order-level discount. With
 * the header cell hidden, this row would be the one place left that says
 * "Order Level Discount". Also any row an admin configured from the same
 * quote-level figures (deal_tot and its twins, discount,
 * erp_document_discount_percent - Admin > Quotes Configuration's footer rows
 * write this view).
 *
 * WHAT STAYS: the stored figure and the arithmetic. ERP-Epicor's footer
 * controller asks for erp_document_discount_amount / _percent itself
 * (context.addFields) and recalculates the amount from new_sub whether or not
 * a row draws it; the enforced total is the server's. Every other row
 * (new_sub, Tax (via ERP), Shipping, Grand Total) is left as it is, in order.
 *
 * The mechanism, why it writes nothing and why uninstall gives the row back:
 * see the Quotes record view's fragment of the same name
 * (custom/Extension/modules/Quotes/Ext/clients/base/views/record/). Same body,
 * byte for byte; scripts/tests/bd_seller_discounts_hidden_test.php.
 */
$bdHideModule = 'Quotes';
$bdHideView = 'quote-data-grand-totals-footer';
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
