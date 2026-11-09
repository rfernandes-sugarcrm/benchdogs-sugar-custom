<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

use Throwable;

/**
 * Bench Dogs' Kinetic fields on a record view, as an overlay at every metadata build (ERP-Epicor's files untouched).
 * Only fields the tenant has; one already drawn stays where it is, except inside the panel ours sits under.
 * Kinetic's own are read-only. Also list columns (gridColumn, gridColumnWidth): the quote grid's, the Product Catalog's.
 */
class BdFieldPlacement
{
    /**
     * Quote record view: the header fields, in Bench Dogs' mapping's order, then the Due date (drawn after Valid Until,
     * FOLLOWS) and the project name (drawn after the Due, on a row of its own: FULL_ROW), and whether each is Kinetic's
     * alone (the seller sets all four). Expires Date is not drawn (QUOTE_TAKEN_OFF).
     */
    public const QUOTE_FIELDS = array(
        'e_best_case_pct_c' => false,
        'e_confidence_pct_c' => false,
        'e_due_date_c' => false,
        'e_project_name_c' => false,
    );

    /**
     * Fields drawn the full width of a two-column panel, on a row of their own: the quote's project name, free text
     * that reads better wide, and a whole row leaves the half-width rows around it paired as they were.
     */
    public const FULL_ROW = array('e_project_name_c');

    /** Off every quote view: Kinetic's expiry, the same date as Valid Until. The field and its data stay. */
    public const QUOTE_TAKEN_OFF = array('e_expiration_date_c');

    /**
     * The quoted line item's record view: the package's line fields, grouped "Cost & capacity" in the mapping's order.
     * Only sales category and quantity quoted are sent to Kinetic; the rest are read back from it, so read-only.
     */
    public const LINE_FIELDS = array(
        'e_expected_qty_c' => true,
        'e_expected_qty_uom_c' => true,
        'e_sales_category_c' => false,
        'e_quantity_quoted_c' => false,
        'e_gross_margin_c' => true,
        'e_est_engineering_hours_c' => true,
        'e_material_pct_c' => true,
        'e_labor_pct_c' => true,
        'e_material_labor_pct_c' => true,
        'e_machine_hours_c' => true,
        'e_finish_hours_c' => true,
        'e_assembly_hours_c' => true,
        'e_case_goods_hours_c' => true,
        'e_install_hours_c' => true,
        'e_total_cost_c' => true,
        'e_total_markup_c' => true,
        'e_total_markup_price_c' => true,
    );

    /** ERP order record view: Project Manager and Engineer, both Kinetic's. */
    public const ORDER_FIELDS = array(
        'e_project_manager_c' => true,
        'e_engineer_c' => true,
    );

    /** ERP-Epicor's fields a stored order view may lack, each drawn right after the field named: its lines total. */
    public const ORDER_AFTER = array('erp_line_total' => 'erp_misc_amount');

    /** The line fields' own panel, and its label (Products language fragment). */
    public const COST_CAPACITY_PANEL = 'LBL_BD_PANEL_COST_CAPACITY';

    /**
     * ERP-Epicor's Epicor Cost Worksheet panel, which the line fields' panel sits under. Named here because the class
     * that declares it (ERP-Core's ProductsLayout) is an install script, never loaded by a metadata build.
     */
    public const COST_WORKSHEET_PANEL = 'LBL_RECORDVIEW_PANEL_ERP_COST_WORKSHEET';

    /** Where a header field goes when it has no panel of its own: ERP-Epicor's ERP panel, else the body. */
    public const HEADER_PANELS = array('LBL_RECORDVIEW_PANEL_ERP', 'panel_body');

    /** ERP-Core's quote status block closes the ERP panel; header fields go above it. */
    public const BEFORE = 'erp_writeback_status';

    /**
     * A field drawn right after the first of these the view draws: the quote's Due beside Valid Until (rc121), then
     * the project name right after the Due (rc122). In this order: each moves after the one before it has moved.
     */
    public const FOLLOWS = array(
        'e_due_date_c' => array('date_quote_expected_closed', 'e_best_case_pct_c', 'e_confidence_pct_c'),
        'e_project_name_c' => array('e_due_date_c', 'date_quote_expected_closed', 'e_best_case_pct_c',
            'e_confidence_pct_c'),
    );

    /** Where rc120 drew a FOLLOWS field: a Due a Studio save stored right after Expires Date moves too. */
    public const FOLLOWED_BEFORE = array('e_due_date_c' => 'e_expiration_date_c');

    /**
     * The quote line grid's Product Group column: the line's stock category, which the sync fills from the Epicor
     * product group. Read-only (a new product's group is picked in ERP-Core's dropdown on the name), plain text rather
     * than a link to the category's admin record, captioned by the category's own label (relabelled in en_us).
     */
    public const GRID_PRODUCT_GROUP = array(
        'name' => 'category_name',
        'label' => 'LBL_CATEGORY_NAME',
        'labelModule' => 'Products',
        'link' => false,
        'readonly' => true,
    );

    /** The columns the Product Group follows, the first one the grid draws: Part Number, else the product name. */
    public const GRID_PRODUCT_GROUP_AFTER = array('mft_part_num', 'product_template_name');

    /** The Product Catalog's Part Number column (list and Search and Select): the Kinetic part number the sync stores on each product, read-only. */
    public const CATALOG_PART_NUMBER = array(
        'name' => 'mft_part_num',
        'label' => 'LBL_LIST_LBL_MFT_PART_NUM',
        'enabled' => true,
        'default' => true,
        'readonly' => true,
    );

    /** The catalog column the Part Number follows: the product name. */
    public const CATALOG_PART_NUMBER_AFTER = array('name');

    /** Each quote line's own Project, editable in the quote line grid; a blank one stays blank. */
    public const GRID_LINE_PROJECT = array(
        'name' => BdAdmRules::FIELD_PROJECT,
        'label' => 'LBL_BD_PROJECT_ID',
        'labelModule' => 'Products',
        'type' => 'enum',
    );

    /** The columns the line's Project follows, the first one the grid draws: Product Group, Part Number, the product. */
    public const GRID_LINE_PROJECT_AFTER = array('category_name', 'mft_part_num', 'product_template_name');

    /** The quote line grid's Product column (the product picker), drawn wider so a seller reads more of the name. */
    public const GRID_PRODUCT = 'product_template_name';

    /** Sugar's widest list column class, 460px; stock draws the Product column 'cell-large', 180px. */
    public const GRID_PRODUCT_WIDTH = 'cell-xxlarge';

    /** The quoted line item's record view: its Project, the seller's (editable). */
    public const LINE_PROJECT = array(BdAdmRules::FIELD_PROJECT => false);

    /** The quote's own fetch of its lines (the bundles' nested list), which must name every field the grid draws. */
    public const QUOTE_LINE_FETCH = 'product_bundle_items';

    /** Off the quote screen: the quote-level Project, now each line's own (its column and data stay). */
    public const QUOTE_RETIRED = array(BdAdmRules::FIELD_PROJECT);

    /**
     * The panels with the named fields the tenant has, placed. $fields is name => Kinetic's alone (drawn read-only).
     * $ownPanel names a panel of this package's (created when absent), kept directly after the panel $after names when
     * the view has it, else last. Exactly the panels as read when there is nothing to place or anything fails.
     * @param array<string, bool> $fields
     */
    public static function place(
        array $panels,
        array $fieldDefs,
        array $fields,
        string $ownPanel = '',
        string $after = ''
    ): array {
        try {
            $have = array();
            foreach ($fields as $name => $kinetic) {
                $def = $fieldDefs[$name] ?? null;
                if (is_array($def) && is_string($def['type'] ?? null) && $def['type'] !== '') {
                    $have[$name] = $kinetic;
                }
            }
            if ($have === array() || !isset($fieldDefs['id'])) {
                return $panels;
            }
            if ($ownPanel !== '' && $after !== '') {
                $panels = self::takenOut($panels, $after, $have);
            }

            $placed = array();
            foreach ($panels as $p => $panel) {
                foreach ((is_array($panel['fields'] ?? null) ? $panel['fields'] : array()) as $e => $entry) {
                    // A fieldset's members count as drawn, one level down, and are left as they are.
                    if (is_array($entry) && is_array($entry['fields'] ?? null)) {
                        foreach ($entry['fields'] as $member) {
                            if (isset($have[self::nameOf($member)])) {
                                $placed[self::nameOf($member)] = true;
                            }
                        }
                    }
                    $name = self::nameOf($entry);
                    if (!isset($have[$name])) {
                        continue;
                    }
                    $placed[$name] = true;
                    // An e_* field already drawn takes Kinetic's read-only marker; ERP-Epicor's own are left alone.
                    if ($have[$name] && strpos($name, 'e_') === 0 && is_array($entry)) {
                        $panels[$p]['fields'][$e]['readonly'] = true;
                    } elseif ($have[$name] && strpos($name, 'e_') === 0) {
                        $panels[$p]['fields'][$e] = array('name' => $name, 'readonly' => true);
                    }
                }
            }

            $entries = array();
            foreach ($have as $name => $kinetic) {
                if (isset($placed[$name])) {
                    continue;
                }
                $entry = array('name' => $name);
                if (!empty($fieldDefs[$name]['vname']) && is_string($fieldDefs[$name]['vname'])) {
                    $entry['label'] = $fieldDefs[$name]['vname'];
                }
                if ($kinetic) {
                    $entry['readonly'] = true;
                }
                if (in_array($name, self::FULL_ROW, true)) {
                    $entry['span'] = 12;
                }
                $entries[] = $entry;
            }
            if ($entries === array()) {
                $panels = self::followed($panels, $have, $placed);

                return $ownPanel !== '' ? self::keptAfter($panels, $ownPanel, $after) : $panels;
            }

            $target = $ownPanel !== '' ? self::panelNamed($panels, $ownPanel) : self::headerPanel($panels);
            if ($target === null && $ownPanel !== '') {
                $panels[] = array(
                    'name' => $ownPanel,
                    'label' => $ownPanel,
                    'columns' => 2,
                    'labelsOnTop' => true,
                    'placeholders' => true,
                    'newTab' => false,
                    'panelDefault' => 'expanded',
                    'fields' => array(),
                );
                $target = array_key_last($panels);
            }
            if ($target === null) {
                return $panels;
            }
            $existing = is_array($panels[$target]['fields'] ?? null) ? array_values($panels[$target]['fields']) : array();
            $at = count($existing);
            foreach ($existing as $i => $entry) {
                if (self::nameOf($entry) === self::BEFORE) {
                    $at = $i;
                    break;
                }
            }
            array_splice($existing, $at, 0, $entries);
            $panels[$target]['fields'] = $existing;
            $panels = self::followed($panels, $have, $placed);

            return $ownPanel !== '' ? self::keptAfter($panels, $ownPanel, $after) : $panels;
        } catch (Throwable $e) {
            if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
                $GLOBALS['log']->error('BenchDogs-Ext: Kinetic fields not placed: ' . $e->getMessage());
            }

            return $panels;
        }
    }

    /**
     * The panels with each $retired field taken off, and each $after field (name => the field it follows) drawn right
     * after that field where the tenant has it and the view draws it nowhere: in a retired field's cell found there,
     * else inserted, read-only as ERP-Epicor draws it. Exactly the panels as read when anything fails.
     * @param string[] $retired
     * @param array<string, string> $after
     */
    public static function retire(array $panels, array $fieldDefs, array $retired, array $after = array()): array
    {
        $read = $panels;
        try {
            $gone = array_flip($retired);
            $wanted = array();
            foreach ($after as $name => $anchor) {
                if (isset($fieldDefs['id']) && self::isField($fieldDefs, $name) && !self::isDrawn($panels, $name)) {
                    $wanted[$name] = $anchor;
                }
            }
            // A retired field right after the anchor gives up its cell before the others go, so the rows keep shape.
            foreach ($wanted as $name => $anchor) {
                $panels = self::drawnAfter($panels, $name, $anchor, $gone, false);
            }
            foreach ($panels as $p => $panel) {
                if (is_array($panel) && self::holdsAny($panel, $gone)) {
                    $panels[$p] = self::withoutCells($panel, $gone);
                }
            }
            foreach ($wanted as $name => $anchor) {
                if (!self::isDrawn($panels, $name)) {
                    $panels = self::drawnAfter($panels, $name, $anchor, $gone, true);
                }
            }

            return $panels;
        } catch (Throwable $e) {
            if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
                $GLOBALS['log']->error('BenchDogs-Ext: retired fields not taken off: ' . $e->getMessage());
            }

            return $read;
        }
    }

    /**
     * The panels without the cells of $names: each leaves its list and the cells after it move up (a fieldset member
     * stays). Exactly the panels as read when anything fails.
     * @param string[] $names
     */
    public static function takenOff(array $panels, array $names): array
    {
        $read = $panels;
        try {
            $gone = array_flip($names);
            foreach ($panels as $p => $panel) {
                if (!is_array($panel) || !self::holdsAny($panel, $gone)) {
                    continue;
                }
                $kept = array();
                foreach ($panel['fields'] as $entry) {
                    if (!isset($gone[self::nameOf($entry)])) {
                        $kept[] = $entry;
                    }
                }
                $panels[$p]['fields'] = $kept;
            }

            return $panels;
        } catch (Throwable $e) {
            if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
                $GLOBALS['log']->error('BenchDogs-Ext: fields not taken off: ' . $e->getMessage());
            }

            return $read;
        }
    }

    /**
     * A list view's panels with $column drawn once: one already drawn stays where it is (made read-only when $column
     * is); otherwise right after the first of $after the view draws, else last. As read when nothing fits or it fails.
     * @param string[] $after
     */
    public static function gridColumn(array $panels, array $column, array $after): array
    {
        try {
            $name = self::nameOf($column);
            if ($name === '') {
                return $panels;
            }
            foreach ($panels as $p => $panel) {
                if (!is_array($panel) || !is_array($panel['fields'] ?? null)) {
                    continue;
                }
                foreach ($panel['fields'] as $e => $entry) {
                    if (self::nameOf($entry) === $name) {
                        if (($column['readonly'] ?? null) !== true) {
                            return $panels;
                        }
                        if (!is_array($entry)) {
                            $panels[$p]['fields'][$e] = array('name' => $name, 'readonly' => true);
                        } elseif (($entry['readonly'] ?? null) !== true) {
                            $panels[$p]['fields'][$e]['readonly'] = true;
                        }

                        return $panels;
                    }
                    // A fieldset member counts as drawn and is left as it is.
                    foreach ((is_array($entry) && is_array($entry['fields'] ?? null)) ? $entry['fields'] : array() as $member) {
                        if (self::nameOf($member) === $name) {
                            return $panels;
                        }
                    }
                }
            }

            $first = null;
            foreach ($panels as $p => $panel) {
                if (is_array($panel) && is_array($panel['fields'] ?? null)) {
                    $first = $first ?? $p;
                }
            }
            if ($first === null) {
                return $panels;
            }
            foreach ($after as $anchor) {
                foreach ($panels as $p => $panel) {
                    if (!is_array($panel) || !is_array($panel['fields'] ?? null)) {
                        continue;
                    }
                    // Rebuilt as a list: a gap in the keys reaches Sidecar as a JSON object.
                    $fields = array_values($panel['fields']);
                    foreach ($fields as $i => $entry) {
                        if (self::nameOf($entry) === $anchor) {
                            array_splice($fields, $i + 1, 0, array($column));
                            $panels[$p]['fields'] = $fields;

                            return $panels;
                        }
                    }
                }
            }
            $fields = array_values($panels[$first]['fields']);
            $fields[] = $column;
            $panels[$first]['fields'] = $fields;

            return $panels;
        } catch (Throwable $e) {
            if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
                $GLOBALS['log']->error('BenchDogs-Ext: grid column not placed: ' . $e->getMessage());
            }

            return $panels;
        }
    }

    /**
     * A list view's panels with the column named $name drawn $widthClass wide (the class Sugar puts on its header cell).
     * Only that column changes, where it is; as read when the view does not draw it or anything fails.
     */
    public static function gridColumnWidth(array $panels, string $name, string $widthClass): array
    {
        $read = $panels;
        try {
            if ($name === '' || $widthClass === '') {
                return $read;
            }
            foreach ($panels as $p => $panel) {
                if (!is_array($panel) || !is_array($panel['fields'] ?? null)) {
                    continue;
                }
                $found = false;
                // Rebuilt as a list: a gap in the keys reaches Sidecar as a JSON object.
                $fields = array_values($panel['fields']);
                foreach ($fields as $i => $entry) {
                    if (self::nameOf($entry) !== $name) {
                        continue;
                    }
                    $found = true;
                    if (!is_array($entry)) {
                        $fields[$i] = array('name' => $name, 'widthClass' => $widthClass);
                    } else {
                        $fields[$i]['widthClass'] = $widthClass;
                    }
                }
                if ($found) {
                    $panels[$p]['fields'] = $fields;
                }
            }

            return $panels;
        } catch (Throwable $e) {
            if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
                $GLOBALS['log']->error('BenchDogs-Ext: grid column width not set: ' . $e->getMessage());
            }

            return $read;
        }
    }

    /**
     * The panels with $field named in every nested $collection field list (a quote fetches its lines through one), once.
     * Exactly the panels as read when there is none or anything fails.
     */
    public static function fetchedWithLines(array $panels, string $collection, string $field): array
    {
        try {
            return self::withNestedField($panels, $collection, $field);
        } catch (Throwable $e) {
            if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
                $GLOBALS['log']->error('BenchDogs-Ext: line field not fetched: ' . $e->getMessage());
            }

            return $panels;
        }
    }

    /** The node with $field appended to each list named $collection under it (one already holding it is left). */
    private static function withNestedField(array $node, string $collection, string $field): array
    {
        if (($node['name'] ?? null) === $collection && is_array($node['fields'] ?? null)) {
            foreach ($node['fields'] as $entry) {
                if (self::nameOf($entry) === $field) {
                    return $node;
                }
            }
            $node['fields'] = array_values($node['fields']);
            $node['fields'][] = $field;

            return $node;
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::withNestedField($value, $collection, $field);
            }
        }

        return $node;
    }

    /** The merged field definitions of a module, for place(). Empty when they cannot be read. */
    public static function fieldDefs(string $module): array
    {
        try {
            $object = \BeanFactory::getObjectName($module);
            if (empty($object)) {
                return array();
            }
            \VardefManager::loadVardef($module, $object);
            $defs = $GLOBALS['dictionary'][$object]['fields'] ?? array();

            return is_array($defs) ? $defs : array();
        } catch (Throwable $e) {
            return array();
        }
    }

    /**
     * The panels with each FOLLOWS field the tenant has moved right after its first sibling drawn: one this build placed,
     * or one where an earlier release drew it (whereEarlier); one stored elsewhere, or in a fieldset, stays.
     */
    private static function followed(array $panels, array $have, array $stored): array
    {
        foreach (self::FOLLOWS as $name => $siblings) {
            $from = isset($have[$name]) ? self::cellOf($panels, $name) : null;
            if ($from === null || (isset($stored[$name]) && !self::whereEarlier($panels, $name, $from))) {
                continue;
            }
            $sibling = null;
            foreach ($siblings as $candidate) {
                if (self::cellOf($panels, $candidate) !== null) {
                    $sibling = $candidate;
                    break;
                }
            }
            $at = $sibling === null ? null : self::cellOf($panels, $sibling);
            if ($at === null || ($at[0] === $from[0] && $at[1] + 1 === $from[1])) {
                continue;
            }
            $source = array_values($panels[$from[0]]['fields']);
            $entry = array_splice($source, $from[1], 1)[0];
            $panels[$from[0]]['fields'] = $source;
            $at = self::cellOf($panels, $sibling);
            $fields = array_values($panels[$at[0]]['fields']);
            array_splice($fields, $at[1] + 1, 0, array($entry));
            // A panel that gains a cell gives it the first empty filler after it, as a Studio drop does. A row of its
            // own fills no half row, so it takes none: the filler pads a row further down, which stays as it is.
            $wide = is_array($entry) && (int) ($entry['span'] ?? 0) === 12;
            for ($i = $at[1] + 2; $at[0] !== $from[0] && !$wide && $i < count($fields); $i++) {
                if ($fields[$i] === array() || $fields[$i] === '') {
                    array_splice($fields, $i, 1);
                    break;
                }
            }
            $panels[$at[0]]['fields'] = $fields;
        }

        return $panels;
    }

    /** Whether the cell $at of $name is where an earlier release drew it: the ERP panel (rc119), or after FOLLOWED_BEFORE. */
    private static function whereEarlier(array $panels, string $name, array $at): bool
    {
        if (($panels[$at[0]]['name'] ?? '') === self::HEADER_PANELS[0]) {
            return true;
        }
        $cells = array_values($panels[$at[0]]['fields']);

        return isset(self::FOLLOWED_BEFORE[$name]) && $at[1] > 0
            && self::nameOf($cells[$at[1] - 1]) === self::FOLLOWED_BEFORE[$name];
    }

    /** Where the view draws $name as a cell of its own: [panel key, position in its field list], or null. */
    private static function cellOf(array $panels, string $name): ?array
    {
        foreach ($panels as $p => $panel) {
            foreach (array_values(is_array($panel['fields'] ?? null) ? $panel['fields'] : array()) as $i => $entry) {
                if (self::nameOf($entry) === $name) {
                    return array($p, $i);
                }
            }
        }

        return null;
    }

    /** The panels with our fields taken out of the panel $from names (withoutCells). */
    private static function takenOut(array $panels, string $from, array $ours): array
    {
        $p = self::panelNamed($panels, $from);
        if ($p === null || !is_array($panels[$p]['fields'] ?? null)) {
            return $panels;
        }
        $panels[$p] = self::withoutCells($panels[$p], $ours);

        return $panels;
    }

    /**
     * The panel with the $ours cells taken out, row by row as the record view draws it, the way Studio's own removal
     * leaves a row: one left with only blank cells goes, a named cell left takes the width.
     */
    private static function withoutCells(array $panel, array $ours): array
    {
        $columns = self::columnsOf($panel);
        $kept = array();
        foreach (self::rowsOf(array_values($panel['fields']), $columns) as $row) {
            $left = array();
            $widths = array();
            $lead = 0;
            $moved = false;
            $named = false;
            foreach ($row as $cell) {
                $span = self::spanOf($cell, $columns);
                if (isset($ours[self::nameOf($cell)])) {
                    // Its width goes to the cell before it in the row, or to the next one when it led the row.
                    $moved = true;
                    if ($left === array()) {
                        $lead += $span;
                    } else {
                        $widths[count($widths) - 1] += $span;
                    }
                    continue;
                }
                $left[] = $cell;
                $widths[] = $span + $lead;
                $lead = 0;
                $named = $named || !self::isBlank($cell);
            }
            if (!$moved) {
                $kept = array_merge($kept, $row);
                continue;
            }
            // A row that held only our fields and blank cells goes whole: the blanks padded our fields' row.
            if (!$named) {
                continue;
            }
            foreach ($left as $i => $cell) {
                $kept[] = $widths[$i] === self::spanOf($cell, $columns) ? $cell : self::withSpan($cell, $widths[$i]);
            }
        }
        $panel['fields'] = $kept;

        return $panel;
    }

    /**
     * The cells in the rows the record view draws them in: a new row once a row holds the panel's column count or the
     * next cell does not fit in its 12 units (Sugar's GridBuilder).
     */
    private static function rowsOf(array $cells, int $columns): array
    {
        $rows = array();
        $row = array();
        $used = 0;
        foreach ($cells as $cell) {
            $span = self::spanOf($cell, $columns);
            if ($row !== array() && (count($row) >= $columns || $used + $span > 12)) {
                $rows[] = $row;
                $row = array();
                $used = 0;
            }
            $row[] = $cell;
            $used += $span;
        }
        if ($row !== array()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** The panel's columns, as the record view reads them (one when unset). */
    private static function columnsOf(array $panel): int
    {
        $columns = (int) ($panel['columns'] ?? 0);

        return $columns < 1 ? 1 : min($columns, 12);
    }

    /** A cell's width in a row of 12: its own span, else one column (the record view's sizing). */
    private static function spanOf($cell, int $columns): int
    {
        if (is_array($cell) && isset($cell['span']) && is_numeric($cell['span'])) {
            return max(1, min(12, (int) $cell['span']));
        }

        return max(1, intdiv(12, $columns));
    }

    /** The cell drawn $span units wide, as Studio writes a widened cell. */
    private static function withSpan($cell, int $span): array
    {
        $cell = is_array($cell) ? $cell : ($cell === '' ? array() : array('name' => $cell));
        $cell['span'] = $span;

        return $cell;
    }

    /** A blank cell as Sugar stores one: '' or a cell with no name (Studio 26.1 writes [] or a bare span). */
    private static function isBlank($entry): bool
    {
        if (is_array($entry)) {
            return self::nameOf($entry) === '' && empty($entry['type']) && empty($entry['fields']);
        }

        return $entry === '' || $entry === null;
    }

    /**
     * The panels with $name drawn directly after the top-level cell $anchor: in the next cell when it is a $gone
     * field (keeping its width), else inserted when $insert. As read when the view has no $anchor.
     */
    private static function drawnAfter(array $panels, string $name, string $anchor, array $gone, bool $insert): array
    {
        foreach ($panels as $p => $panel) {
            $cells = is_array($panel) && is_array($panel['fields'] ?? null) ? array_values($panel['fields']) : array();
            foreach ($cells as $i => $cell) {
                if (self::nameOf($cell) !== $anchor) {
                    continue;
                }
                $entry = array('name' => $name, 'readonly' => true);
                $next = $cells[$i + 1] ?? null;
                if ($next !== null && isset($gone[self::nameOf($next)])) {
                    $cells[$i + 1] = is_array($next) && isset($next['span']) ? $entry + array('span' => $next['span'])
                        : $entry;
                } elseif ($insert) {
                    array_splice($cells, $i + 1, 0, array($entry));
                } else {
                    return $panels;
                }
                $panels[$p]['fields'] = $cells;

                return $panels;
            }
        }

        return $panels;
    }

    /** Whether the tenant has $name: a definition with a type. */
    private static function isField(array $fieldDefs, string $name): bool
    {
        $def = $fieldDefs[$name] ?? null;

        return is_array($def) && is_string($def['type'] ?? null) && $def['type'] !== '';
    }

    /** Whether the view draws $name anywhere, a fieldset's members included. */
    private static function isDrawn(array $panels, string $name): bool
    {
        foreach ($panels as $panel) {
            foreach ((is_array($panel) && is_array($panel['fields'] ?? null) ? $panel['fields'] : array()) as $entry) {
                if (self::nameOf($entry) === $name) {
                    return true;
                }
                foreach ((is_array($entry) && is_array($entry['fields'] ?? null) ? $entry['fields'] : array()) as $m) {
                    if (self::nameOf($m) === $name) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Whether the panel has a top-level cell named in $names (name => anything). */
    private static function holdsAny(array $panel, array $names): bool
    {
        foreach ((is_array($panel['fields'] ?? null) ? $panel['fields'] : array()) as $entry) {
            if (isset($names[self::nameOf($entry)])) {
                return true;
            }
        }

        return false;
    }

    /** The panels with $name moved to directly after $after; as read when either is missing or it is there already. */
    private static function keptAfter(array $panels, string $name, string $after): array
    {
        $order = array_keys($panels);
        $at = self::panelNamed($panels, $name);
        $anchor = $after !== '' ? self::panelNamed($panels, $after) : null;
        if ($at === null || $anchor === null || $at === $anchor
            || array_search($at, $order, true) === array_search($anchor, $order, true) + 1) {
            return $panels;
        }
        $moved = $panels[$at];
        unset($panels[$at]);
        $out = array();
        foreach ($panels as $p => $panel) {
            $out[] = $panel;
            if ($p === $anchor) {
                $out[] = $moved;
            }
        }

        return $out;
    }

    private static function panelNamed(array $panels, string $name)
    {
        foreach ($panels as $p => $panel) {
            if (is_array($panel) && ($panel['name'] ?? '') === $name) {
                return $p;
            }
        }

        return null;
    }

    /** The ERP panel, else the body, else the first panel with fields that is not the header. */
    private static function headerPanel(array $panels)
    {
        foreach (self::HEADER_PANELS as $wanted) {
            $found = self::panelNamed($panels, $wanted);
            if ($found !== null) {
                return $found;
            }
        }
        foreach ($panels as $p => $panel) {
            if (is_array($panel) && empty($panel['header']) && !empty($panel['fields']) && is_array($panel['fields'])) {
                return $p;
            }
        }

        return null;
    }

    private static function nameOf($entry): string
    {
        if (is_string($entry)) {
            return $entry;
        }

        return is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : '';
    }
}
