<?php

// This class extends ERP-Core's BaseErpLayout, so it cannot be defined at
// all unless that package is still installed - a require_once on a missing
// file is a fatal compile error, not a \Throwable, so the file_exists guard
// has to come first (same convention as BdBenchDogsActionsApi.php's guard
// on BaseErpActionsApi.php).
$parentLayoutFile = 'custom/include/scripts/BaseErpLayout.php';
if (file_exists($parentLayoutFile)) {
    require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($parentLayoutFile);

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
            $this->applyColumnOrder();
        }

        /**
         * 🚩 THIS PACKAGE USED TO DELETE OTHER PACKAGES' COLUMNS ON EVERY
         * INSTALL, SILENTLY. Until 0.9.43 the authored column list shipped AS
         * custom/modules/Products/clients/base/views/quote-data-group-list/
         * quote-data-group-list.php - the exact path ViewdefManager::saveViewdef()
         * writes. Module Loader's file copy therefore REPLACED the whole custom
         * viewdef, and any column another package had appended went with it.
         * Nothing "removed" it; the file it lived in was overwritten.
         *
         * Measured: the shipped file declared 9 fields and the served view
         * returned exactly those 9, in that order, with
         * ERP-Epicor-QuantityAlternatives' erp_break_select ABSENT - twice,
         * each time around a Bench Dogs install. 🚩 Reinstalling the victim
         * package looked like a fix and was a countdown: it died again at the
         * next release. (decision 803.)
         *
         * So the authored list is now a TEMPLATE at a path Sugar does not read
         * as a viewdef, and this method MERGES instead of replacing: this
         * package's fields in this package's order, then EVERY field already
         * deployed that the template does not name, appended untouched. A
         * fourth package appending a column keeps it too, which is the
         * property that made merging the right answer rather than adding the
         * one known foreign field to the file.
         *
         * The template still owns ORDER and per-column CONFIG, which is why it
         * stays a file rather than becoming a PHP array: it carries
         * discount_field's discount_amount/discount_select sub-fields and
         * erp_line_links' two deeplink sub-fields, and hand-transcribing that
         * is how a column quietly loses its sub-fields. It also remains the
         * answer to the original problem - the parser APPENDS reliably and
         * REORDERS unreliably (0.9.17 and 0.9.19 both failed to reorder).
         */
        private function applyColumnOrder(): void
        {
            $template = $this->authoredColumns();
            if ($template === null) {
                return;
            }

            $viewdefs = $this->loadView('Products', 'quote-data-group-list');
            if ($viewdefs === null) {
                // No deployed view to merge into. Writing a nearly empty custom
                // file over a view that was never there is the failure
                // loadView()'s null contract exists to prevent.
                return;
            }

            $deployed =& $viewdefs['base']['view']['quote-data-group-list']['panels'][0]['fields'];
            $deployed = $this->mergeColumns($template, is_array($deployed) ? $deployed : array());

            $this->deployView('Products', 'quote-data-group-list', $viewdefs);
        }

        /**
         * This package's authored column list, or null if the template is
         * missing - a partial install must not rewrite the grid from nothing.
         */
        private function authoredColumns(): ?array
        {
            $file = 'custom/modules/Quotes/BdQliColumnTemplate.php';
            if (!file_exists($file)) {
                return null;
            }

            $viewdefs = array();
            include \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($file);

            $fields = $viewdefs['Products']['base']['view']['quote-data-group-list']['panels'][0]['fields']
                ?? null;

            return is_array($fields) && $fields !== array() ? $fields : null;
        }

        /**
         * Authored fields in the authored order, then every deployed field the
         * template does not name, in the order it was already in.
         *
         * Comparison is by NAME, and a bare string entry counts as a name -
         * Sugar allows either form in a field list, and treating a string as
         * unnamed would append a duplicate of a column that is already there.
         */
        private function mergeColumns(array $authored, array $deployed): array
        {
            $authoredNames = array();
            foreach ($authored as $f) {
                $name = is_array($f) ? ($f['name'] ?? '') : (string) $f;
                if ($name !== '') {
                    $authoredNames[$name] = true;
                }
            }

            $merged = $authored;
            foreach ($deployed as $f) {
                $name = is_array($f) ? ($f['name'] ?? '') : (string) $f;
                if ($name !== '' && !isset($authoredNames[$name])) {
                    $merged[] = $f;
                }
            }

            return $merged;
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
            // each row itself.
            //
            // 🔒 1032 — the Kinetic line number is NO LONGER OURS EITHER. It
            // is `Products.erp_quote_line_num`, an ERP-Core field written by
            // connector-core's QuoteLineCoreTransformer; Bench's
            // `bd_erp_line_num` copy is retired. This list is now an
            // injection of a CORE column into the quoted-lines grid, kept here
            // only because ERP-Core's own ProductsLayout does not draw it yet.
            // When it does, this method has nothing left to add and the whole
            // injection should go — do not add a Bench field back to keep it
            // alive.
            return ['erp_quote_line_num'];
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
}
