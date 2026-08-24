<?php

require_once 'custom/include/scripts/BaseErpLayout.php';

/**
 * Makes the native-line ordering fields (bd_to_order, bd_ordered) usable on
 * the quoted-line-items grid.
 *
 * The COLUMNS themselves are no longer injected here. They are declared in a
 * shipped viewdef - custom/modules/Products/clients/base/views/
 * quote-data-group-list/quote-data-group-list.php - because ModuleBuilder
 * appends a new column reliably but does not reliably reorder an existing
 * one, and these two have to sit near the front of the row to be usable at
 * all. See that file for the measurements.
 *
 * What still has to happen at install time is the other half: the grid
 * viewdef draws the columns, but the VALUES travel with the Quotes record
 * fetch, whose product_bundle_items sub-field allowlist is a separate
 * metadata surface. Without this the columns render permanently empty - the
 * same requirement the product documents for erp_available_qty (QuotesLayout:
 * priceAvailabilityLineItemFields).
 */
class BdQliColumnsLayout extends BaseErpLayout
{
    public function install(): void
    {
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->bdOrderFieldNames());
    }

    public function uninstall(): void
    {
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->bdOrderFieldNames());
        // The columns go with the shipped viewdef when the package's files are
        // removed, so there is nothing to strip out of deployed metadata.
    }

    private function bdOrderFieldNames(): array
    {
        return ['bd_to_order', 'bd_ordered', 'bd_erp_line_num'];
    }
}
