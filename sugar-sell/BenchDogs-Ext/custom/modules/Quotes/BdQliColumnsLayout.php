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
        // Positioned, not appended. Appending puts both columns to the right
        // of every stock column on the quoted-line-items grid, which on a
        // normal window width lands them off-screen: measured live on quote
        // 1196, 24 Aug 2026, the grid had to be scrolled horizontally eight
        // ticks before "To Order" appeared. A tick nobody can see is not a
        // control, and the "already ordered, locked" state that the whole
        // tiered model turns on was equally invisible. They belong where the
        // rep is already reading - which line, how many, am I ordering it -
        // so they go in immediately after Quantity.
        $this->positionOrderingColumns('Products');
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

    /**
     * Put the two ordering columns where the rep will actually see them, in
     * ONE read-modify-deploy of the grid viewdef.
     *
     * Appending them - which is all the base helper does - puts them to the
     * right of every stock column, and at a normal window width that is
     * off-screen: measured live on quote 1196, 24 Aug 2026, the grid needed
     * eight horizontal scroll ticks before "To Order" appeared. A tick nobody
     * can see is not a control, and the locked "Ordered" state that the whole
     * ordering story turns on was equally invisible.
     *
     * Done as a single pass on purpose. The obvious implementation - call the
     * base remove helper, then the base insert-before helper - does not work:
     * each opens its own DeployedMetaDataImplementation, and the second reads
     * a viewdef that does not yet reflect the first one's write, so it still
     * sees the columns as present, skips them as already-installed, and the
     * grid comes back unchanged (measured: 0.9.17 shipped exactly that and
     * moved nothing). Reading once and writing once removes the ordering
     * problem rather than trying to sequence around it.
     *
     * Idempotent, and non-destructive to everything else: the stock columns
     * and the ERP product's own erp_available_qty / erp_line_links keep their
     * order, only the two bd_ columns move.
     */
    private function positionOrderingColumns(string $module): void
    {
        $deploy = new DeployedMetaDataImplementation(MB_SIDECARQUOTEDATAGROUPLIST, $module, 'base');
        $viewdefs = $deploy->getViewdefs();
        $fields = $viewdefs['base']['view']['quote-data-group-list']['panels'][0]['fields'] ?? [];

        $ours = $this->bdOrderFields();
        $ourNames = array_column($ours, 'name');

        // Everything that is not ours, in its existing order.
        $kept = [];
        foreach ($fields as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (!in_array($name, $ourNames, true)) {
                $kept[] = $field;
            }
        }

        // Insert after the columns that identify the line - line number,
        // quantity, product, part number - because that is the order the rep
        // reads it in: which line, how many, which product, am I ordering it.
        $keptNames = [];
        foreach ($kept as $field) {
            $keptNames[] = is_array($field) ? ($field['name'] ?? '') : $field;
        }
        $insertAt = 0;
        foreach (['line_num', 'quantity', 'product_template_name', 'mft_part_num'] as $identifying) {
            $at = array_search($identifying, $keptNames, true);
            if ($at !== false && $at + 1 > $insertAt) {
                $insertAt = (int) $at + 1;
            }
        }

        array_splice($kept, $insertAt, 0, $ours);

        $viewdefs['base']['view']['quote-data-group-list']['panels'][0]['fields'] = array_values($kept);
        $deploy->setViewdefs($viewdefs);
        $deploy->deploy($viewdefs);

        $GLOBALS['log']->info(
            'BdQliColumnsLayout: ordering columns positioned at index ' . $insertAt
            . ' of the ' . $module . ' quoted-line-items grid.'
        );
    }
}
