<?php

require_once 'custom/include/scripts/BaseErpLayout.php';

/**
 * Adds the native-line ordering columns to the quoted-line-items grid
 * (Products quote-data-group-list): "To Order" (bd_to_order, the tick that
 * marks a line for the next Epicor order) and "Ordered" (bd_ordered, set by
 * bd-order-selected-lines when the line lands on an order). Same deployed-
 * metadata mechanism (and idempotency by field name) as the ERP product's
 * own erp_available_qty / erp_line_links columns on this grid.
 */
class BdQliColumnsLayout extends BaseErpLayout
{
    public function install(): void
    {
        $this->addFieldsToDataGroupListView('Products', $this->bdOrderFields());
        // The grid viewdef alone only draws the columns. The VALUES travel
        // with the Quotes record fetch, whose product_bundle_items sub-field
        // allowlist is a separate metadata surface - same requirement the
        // product documents for erp_available_qty (QuotesLayout.php:
        // priceAvailabilityLineItemFields).
        $this->addFieldsToNestedCollection('Quotes', 'product_bundle_items', $this->bdOrderFieldNames());
    }

    public function uninstall(): void
    {
        $this->removeFieldsFromDataGroupListView('Products', $this->bdOrderFields());
        $this->removeFieldsFromNestedCollection('Quotes', 'product_bundle_items', $this->bdOrderFieldNames());
    }

    private function bdOrderFieldNames(): array
    {
        return ['bd_to_order', 'bd_ordered', 'bd_erp_line_num'];
    }

    private function bdOrderFields(): array
    {
        return [
            [
                'name' => 'bd_to_order',
                'label' => 'LBL_BD_TO_ORDER',
                'labelModule' => 'Products',
                'type' => 'bool',
            ],
            [
                'name' => 'bd_ordered',
                'label' => 'LBL_BD_ORDERED',
                'labelModule' => 'Products',
                'type' => 'bool',
                'readonly' => true,
            ],
        ];
    }
}
