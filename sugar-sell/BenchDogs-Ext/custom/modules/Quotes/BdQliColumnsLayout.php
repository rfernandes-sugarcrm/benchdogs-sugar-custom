<?php

require_once 'custom/include/scripts/BaseErpLayout.php';

/**
 * Makes the native-line ordering state usable on the quoted-line-items grid.
 *
 * Two things have to be true for the grid to work, and neither of them is a
 * column any more.
 *
 * 1. bd_ordered has to TRAVEL with each row. The grid viewdef draws the
 *    columns, but the values arrive on the Quotes record fetch, whose
 *    product_bundle_items sub-field allowlist is a separate metadata
 *    surface. Without this, the row decoration has no bd_ordered to read and
 *    every line looks orderable - the same requirement the product documents
 *    for erp_available_qty (QuotesLayout: priceAvailabilityLineItemFields).
 *
 * 2. The two checkbox COLUMNS that earlier versions injected have to go. Up
 *    to 0.9.20 the flow was a stored "To Order" tick plus an "Ordered"
 *    readout, drawn as two bool columns beside the grid's own multi-select
 *    checkbox. Three checkboxes on a row read as three questions. 0.9.21
 *    moves the selection onto the stock checkbox and shows ordered lines as
 *    greyed, locked rows, so the columns are removed - from the shipped
 *    viewdef, and HERE from deployed metadata, because instances that ran
 *    0.9.17 or 0.9.19 have them written into deployed metadata where the
 *    shipped file cannot reach them. Removing what is not there is a no-op,
 *    so this is safe on a clean install.
 */
class BdQliColumnsLayout extends BaseErpLayout
{
    public function install(): void
    {
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->bdOrderFieldNames());
        $this->removeFieldsFromDataGroupListView('Products', $this->bdLegacyColumnNames());
    }

    public function uninstall(): void
    {
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->bdOrderFieldNames());
        // The columns go with the shipped viewdef when the package's files are
        // removed, so there is nothing to strip out of deployed metadata.
    }

    /**
     * Fetched with every row. bd_to_order is kept in the allowlist even
     * though nothing sets it from the UI now: records written before 0.9.21
     * still carry the flag and a report or a repair script may want to read
     * it without a second round trip.
     */
    private function bdOrderFieldNames(): array
    {
        // bd_to_order / bd_ordered are gone: the lock is ERP-Epicor's own
        // erp_ordered (>= 1.0.84), which core's QuotesLayout carries onto
        // each row itself. Only the Kinetic line number is still ours.
        return ['bd_erp_line_num'];
    }

    /**
     * Columns injected by 0.9.17/0.9.19 that 0.9.21 no longer draws.
     * removeFieldsFromDataGroupListView reads these through array_column(...,
     * 'name'), so they are field DEFS, not bare names - a list of strings
     * silently matches nothing and leaves both columns in place.
     */
    private function bdLegacyColumnNames(): array
    {
        return [
            ['name' => 'bd_to_order'],
            ['name' => 'bd_ordered'],
        ];
    }
}
