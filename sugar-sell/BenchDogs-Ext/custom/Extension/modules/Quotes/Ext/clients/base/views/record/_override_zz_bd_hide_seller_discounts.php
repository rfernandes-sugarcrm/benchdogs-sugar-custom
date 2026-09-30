<?php

/**
 * G848 (0.9.42-rc84, owner 🔒2151b) - BENCH DOGS SELLERS DO NOT APPLY
 * DISCOUNTS: THE QUOTE SHOWS NO DISCOUNT PANEL AND NO DISCOUNT FIGURE.
 *
 * Owner, 2026-09-30: "benchdog dont want seller to apply discount so both the
 * discount pannel and the line item doscounts should be not vissible on
 * benchdog MLP". This file is the Quotes RECORD view's part of it, and so the
 * CREATE form's too: stock clients/base/views/create/create.js:189 builds the
 * create form from this same record meta, and the Account's "Create
 * Opportunity & Quote" creates both on the server and then opens this view.
 * Its four siblings carry the same name and the same body:
 *   Quotes   quote-data-grand-totals-header  the totals strip's "Order Level Discount" (deal_tot)
 *   Quotes   quote-data-grand-totals-footer  the footer's "Order Level Discount" row
 *                                            (erp_document_discount_amount)
 *   Products quote-data-group-list           the grid's "Line Discount" column: its cells, its
 *                                            edit-row input and its header (all read this view)
 *   Products record                          the line's own page (the line number links to it)
 *
 * WHAT GOES HERE. ERP-Epicor's DISCOUNT panel, LBL_RECORDVIEW_PANEL_ERP_DISCOUNT
 * ("Apply a discount" / "Whole order" / % or Amount / Apply), whose one field
 * is erp_discount_panel (type erp-discount, QuotesLayout::erpDiscountPanel()),
 * and any quote-level discount figure an admin placed on this view (deal_tot
 * and its two twins, discount, erp_document_discount_amount / _percent). A
 * field is taken out of every panel, and out of a fieldset one level down; the
 * Discount panel goes once nothing is left in it (an admin's own field in it
 * keeps the panel, with that field).
 *
 * WHAT DOES NOT CHANGE: anything stored or computed. This file changes what
 * the view DRAWS and nothing else - no record, no vardef, no deployed viewdef.
 * The quote's nested fetch list (panel_header's name -> related_fields ->
 * bundles -> product_bundle_items) is never entered, so every line is still
 * READ with discount_amount and discount_select, and a discount Epicor sends
 * (price list, estimator) still lands in the line and quote totals, which
 * still equal Epicor's (🔒2151b (a)). The Unit Price is discount_price and is
 * not a discount. The ERP panel's Discount Warning (erp_discount_refusal,
 * read-only, shown only when it has something to say) stays. The SetVisibility
 * rules ERP-Epicor deployed for the panel stay too: Sugar's action returns
 * when it cannot find its target (include/Expressions/Actions/
 * VisibilityAction.php, exec()), and rewriting them would make this package a
 * second writer on core's rules. A discount sent through the API is not
 * refused (🔒2151b (d)).
 *
 * HOW, AND WHY IT IS SAFE ON EVERY TENANT (decision 803: merge, never replace).
 * A sidecar view Extension fragment. Sugar compiles this directory into
 * custom/modules/Quotes/Ext/clients/base/views/record/record.ext.php
 * (ModuleInstaller::mergeExtensionFiles(), which strips every PHP tag it finds
 * in the file, so there is none past the opening one) and includes it right
 * after the record viewdef - ERP-Epicor's deployed one, Studio's or stock's -
 * is loaded (MetaDataFiles::getClientFileContents()). So it applies to
 * whatever ERP-Epicor last wrote, in either install order, and it writes
 * nothing back: ViewdefManager::loadViewdef() skips .ext. paths, so neither
 * ERP-Epicor's installer, nor Admin > Quotes Configuration, nor Studio ever
 * saves the hidden view. The `_override` prefix merges it after any plain
 * fragment another package puts in this directory. Module Loader copies it and
 * rebuilds every module's extensions on install (install() -> rebuild_all(true)
 * -> rebuild_extensions() over getModuleDirs()), deletes it and rebuilds on
 * uninstall, and clears the API metadata cache both times: uninstall gives the
 * fields back with nothing to undo. Only the Bench package ships it; a tenant
 * without that package (stock, et) keeps every discount field. The name is on
 * no list of ONEOFF-RetireBdResidue's.
 *
 * CONCATENATED WITH OTHER FILES AND INCLUDED INSIDE A SUGAR METHOD, more than
 * once per build: no function or class, no reference, every variable
 * bdHide-prefixed and unset at the end, every step idempotent, and $viewdefs
 * is assigned only when something was taken out, so a view with nothing to
 * hide is served exactly as read.
 *
 * scripts/tests/bd_seller_discounts_hidden_test.php runs all five the way
 * getClientFileContents() does, on the views a Bench tenant serves (ERP-Epicor's
 * own panel and totals definitions, read off its QuotesLayout), and compiled
 * the way mergeExtensionFiles() compiles them.
 */
$bdHideModule = 'Quotes';
$bdHideView = 'record';
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
