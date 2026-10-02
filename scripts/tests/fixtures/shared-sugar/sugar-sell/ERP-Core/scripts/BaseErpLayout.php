<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

abstract class BaseErpLayout
{
    const ERP_PANEL_NAME = 'LBL_RECORDVIEW_PANEL_ERP';

    /**
     * G452 — the field names this package has placed in a panel it owns that do not carry its erp_ prefix, in any version, current or retired.
     */
    const PACKAGE_PANEL_FIELDS_WITHOUT_ERP_PREFIX = [
        'epicor_deeplink_url',        // Accounts / Quotes / ProductTemplates ERP panels
        'is_primary_quote',           // Quotes ERP panel (renamed erp_is_primary_quote)
        'quotes_erp_orders_name',     // Quotes ERP panel (retired)
        'quotes_erp_quotes_name',     // Quotes ERP panel (retired)
        'update_erp_comment_button',  // Quotes ERP Comments panel (retired, 🔒 1702b)
        'syspro_company_c',           // Contacts ERP panel (earliest ERP-Core)
        'syspro_sync_message_c',
        'syspro_telephone_ext_c',
    ];

    protected bool $replace;

    public function __construct(bool $replace = false)
    {
        // MLP019 (vault L-0081): nothing in this class may name a ModuleBuilder metadata parser class, not even as a class_exists() string.
        if (!class_exists(ViewdefManager::class)) {
            throw new RuntimeException('Sugar ViewdefManager is unavailable to the layout installer');
        }

        $this->replace = $replace;
    }

    abstract public function install(): void;
    abstract public function uninstall(): void;

    protected function addPanelToRecordView(string $module, array $panel): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];
        $panelNames = array_column($panels, 'name');
        $existingIndex = array_search($panel['name'], $panelNames);

        if ($existingIndex !== false && !$this->replace) {
            return;
        }

        if ($existingIndex !== false) {
            $existing = $panels[$existingIndex];
            array_splice($panels, $existingIndex, 1);
            $panel = $this->withoutFieldsPlacedElsewhere($module, $panel, $panels);
            $panel = $this->carryCustomerFields($module, $existing, $panel, $panels);
        } else {
            $panel = $this->withoutFieldsPlacedElsewhere($module, $panel, $panels);
        }

        $panels[] = $panel;

        $this->deployView($module, 'record', $viewdefs);
    }

    /**
     * G718 (1.1.163) — rebuild an owned panel where it stands, and, when the caller says the view is laid out a particular way.
     * @param string[]             $group
     * @param array<string,
     */
    protected function addPanelToRecordViewKeepingPlace(
        string $module,
        array $panel,
        string $markPanel = '',
        string $markField = '',
        string $beforePanel = '',
        array $group = [],
        array $properties = []
    ): void {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];
        $existingIndex = array_search($panel['name'], array_column($panels, 'name'));

        if ($existingIndex !== false && !$this->replace) {
            return;
        }

        if ($existingIndex !== false) {
            $existing = $panels[$existingIndex];
            $others = $panels;
            array_splice($others, $existingIndex, 1);
            $panel = $this->withoutFieldsPlacedElsewhere($module, $panel, $others);
            $panel = $this->carryCustomerFields($module, $existing, $panel, $others);
            $panels[$existingIndex] = $panel;
        } else {
            $panel = $this->withoutFieldsPlacedElsewhere($module, $panel, $panels);
            $panels[] = $panel;
        }

        if ($beforePanel !== '' && $group !== [] && self::panelsPlaceIn($panels, $markPanel, $markField)) {
            $panels = self::reorderPanelsBefore(array_values($panels), $group, $beforePanel, $properties);
        }

        $this->deployView($module, 'record', $viewdefs);
    }

    /** Does the panel named $panelName place the field $field (top level)? */
    private static function panelsPlaceIn(array $panels, string $panelName, string $field): bool
    {
        if ($panelName === '' || $field === '') {
            return false;
        }
        foreach ($panels as $p) {
            if (is_array($p) && ($p['name'] ?? '') === $panelName) {
                foreach ((array) ($p['fields'] ?? []) as $cell) {
                    if (self::panelCellName($cell) === $field) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function addPanelToRecordViewBefore(string $module, array $panel, string $beforePanel): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];
        $panelNames = array_column($panels, 'name');
        $existingIndex = array_search($panel['name'], $panelNames);

        if ($existingIndex !== false && !$this->replace) {
            return;
        }

        if ($existingIndex !== false) {
            $existing = $panels[$existingIndex];
            array_splice($panels, $existingIndex, 1);
            $panel = $this->withoutFieldsPlacedElsewhere($module, $panel, $panels);
            $panel = $this->carryCustomerFields($module, $existing, $panel, $panels);
        } else {
            $panel = $this->withoutFieldsPlacedElsewhere($module, $panel, $panels);
        }

        $insertIndex = array_search($beforePanel, array_column($panels, 'name'));

        if ($insertIndex !== false) {
            array_splice($panels, $insertIndex, 0, [$panel]);
        } else {
            $panels[] = $panel;
        }

        $this->deployView($module, 'record', $viewdefs);
    }

    /**
     * G452 — A rebuilt owned panel keeps the fields a customer put in it. (G718)
     * @param array $existing
     * @param array $panel
     * @param array $otherPanels
     * @return array
     */
    private function carryCustomerFields(string $module, array $existing, array $panel, array $otherPanels): array
    {
        $taken = $this->collectFieldNames($panel['fields'] ?? []);
        foreach ($otherPanels as $other) {
            $taken = array_merge($taken, $this->collectFieldNames(is_array($other) ? ($other['fields'] ?? []) : []));
        }

        // Which named cells are the customer's (unchanged rule).
        $cells = array_values((array) ($existing['fields'] ?? []));
        $keep = [];
        $names = [];
        foreach ($cells as $at => $field) {
            $name = self::panelCellName($field);
            if (self::isPaddingCell($name) || in_array($name, $taken, true)
                || $this->isErpField($name)
                || in_array($name, self::PACKAGE_PANEL_FIELDS_WITHOUT_ERP_PREFIX, true)) {
                continue;
            }
            $keep[$at] = true;
            $names[] = $name;
            $taken[] = $name;
        }

        // G718: and the empty cells that go with them, or that end the panel, or that the customer put among the package's own cells (see above).
        $carried = [];
        $gaps = [];
        $padding = 0;
        $count = count($cells);
        for ($at = 0; $at < $count; $at++) {
            if (isset($keep[$at])) {
                $carried[] = $cells[$at];
                continue;
            }
            if (!self::isPaddingCell(self::panelCellName($cells[$at]))) {
                continue;
            }
            $end = $at;
            while ($end + 1 < $count && self::isPaddingCell(self::panelCellName($cells[$end + 1]))) {
                $end++;
            }
            $from = $at;
            if ($at === 0 || !isset($keep[$at - 1])) {
                if (!isset($keep[$end + 1]) && $end + 1 < $count) {
                    // G718 (1.1.162): a run between two of the package's cells (or above the first) - kept in place when the customer put it there (isDeliberateGap).
                    $before = $at === 0 ? null : self::panelCellName($cells[$at - 1]);
                    $from = $at + $this->definitionPaddingAfter($panel['fields'] ?? [], $before);
                    if ($from <= $end && self::isDeliberateGap($cells, $from, $end, self::panelColumns($existing))) {
                        $gaps[] = ['at' => $at, 'cells' => array_slice($cells, $from, $end - $from + 1)];
                        $padding += $end - $from + 1;
                    }
                    $at = $end;
                    continue;
                }
                $before = $at === 0 ? null : self::panelCellName($cells[$at - 1]);
                $from = $at + $this->definitionPaddingAfter($panel['fields'] ?? [], $before);
            }
            for ($i = $from; $i <= $end; $i++) {
                $carried[] = $cells[$i];
                $padding++;
            }
            $at = $end;
        }
        if ($carried === [] && $gaps === []) {
            return $panel;
        }

        $panel['fields'] = array_merge(self::placeGaps($panel['fields'] ?? [], $gaps, $cells), $carried);
        // Fatal, like every other install-step line this package writes: it is the level sugarcrm.log keeps by default.
        if (!isset($GLOBALS['log']) || !is_object($GLOBALS['log'])) {
            return $panel;
        }
        $among = 0;
        foreach ($gaps as $gap) {
            $among += count($gap['cells']);
        }
        if ($names === []) {
            $GLOBALS['log']->fatal($among === 0
                ? sprintf(
                    'ERP layout: kept %d empty cell(s) the customer left at the end of %s panel %s (G718)',
                    $padding,
                    $module,
                    (string) ($panel['name'] ?? '?')
                )
                : sprintf(
                    'ERP layout: kept %d empty cell(s) the customer placed in %s panel %s, %d of them among the package\'s own fields, in place (G718)',
                    $padding,
                    $module,
                    (string) ($panel['name'] ?? '?'),
                    $among
                ));
            return $panel;
        }
        $GLOBALS['log']->fatal(sprintf(
            'ERP layout: kept %d field(s) placed outside this package in %s panel %s: %s (G452)%s',
            count($names),
            $module,
            (string) ($panel['name'] ?? '?'),
            implode(', ', $names),
            $padding > 0 ? sprintf('; and %d empty cell(s) with them, in place (G718)', $padding) : ''
        ));

        return $panel;
    }

    /**
     * The field name a panel cell places: '' for an unnamed cell.
     * @param mixed $field
     */
    private static function panelCellName($field): string
    {
        return is_array($field) ? (string) ($field['name'] ?? '') : (is_string($field) ? $field : '');
    }

    /**
     * An empty cell: unnamed ([], ['span' => N], '' - what Studio writes), or one of Studio's own '(empty)' / '(filler)' placeholders.
     */
    private static function isPaddingCell(string $name): bool
    {
        return $name === '' || $name[0] === '(';
    }

    /**
     * How many empty cells the package's definition puts right after the cell named $name (right at the top when $name is null).
     */
    private function definitionPaddingAfter(array $definition, ?string $name): int
    {
        $definition = array_values($definition);
        $at = -1;
        if ($name !== null) {
            $at = null;
            foreach ($definition as $i => $field) {
                if (self::panelCellName($field) === $name) {
                    $at = $i;
                    break;
                }
            }
            if ($at === null) {
                return 0;
            }
        }
        $n = 0;
        for ($i = $at + 1; $i < count($definition) && self::isPaddingCell(self::panelCellName($definition[$i])); $i++) {
            $n++;
        }
        return $n;
    }

    /** Sidecar's row width, in span units (a record-view panel is 12 wide). */
    const PANEL_MAX_SPAN = 12;

    /** The panel's column count (2 when unstated, the record view's default). */
    private static function panelColumns(array $panel): int
    {
        $columns = (int) ($panel['columns'] ?? 2);
        return $columns > 0 ? min($columns, self::PANEL_MAX_SPAN) : 2;
    }

    /**
     * The width of one cell, in span units: its own 'span', else one column.
     * @param mixed $cell
     */
    private static function cellSpan($cell, int $columns): int
    {
        $single = intdiv(self::PANEL_MAX_SPAN, $columns);
        $span = is_array($cell) && isset($cell['span']) ? (int) $cell['span'] : 0;
        return $span > 0 ? min($span, self::PANEL_MAX_SPAN) : $single;
    }

    /**
     * G718 (1.1.162) — did an admin put the empty cells $from..$end there, or could Studio have written them itself?
     * @param array $cells
     * @param int   $from
     * @param int   $end
     * @param int   $columns
     */
    private static function isDeliberateGap(array $cells, int $from, int $end, int $columns): bool
    {
        $used = 0;
        $inRow = 0;
        for ($i = 0; $i < $from; $i++) {
            $span = self::cellSpan($cells[$i], $columns);
            if ($inRow >= $columns || $used + $span > self::PANEL_MAX_SPAN) {
                $used = 0;
                $inRow = 0;
            }
            $used += $span;
            $inRow++;
        }
        $first = self::cellSpan($cells[$from], $columns);
        if ($inRow >= $columns || $used + $first > self::PANEL_MAX_SPAN) {
            return true;                  // The run starts a row
        }
        if ($used === 0) {
            return true;                  // The run starts a row
        }
        $start = $used;
        for ($i = $from; $i <= $end; $i++) {
            $span = self::cellSpan($cells[$i], $columns);
            if ($inRow >= $columns || $used + $span > self::PANEL_MAX_SPAN) {
                return true;              // It runs past the row's end
            }
            $used += $span;
            $inRow++;
        }
        if ($used < self::PANEL_MAX_SPAN && $inRow < $columns) {
            return true;                  // It leaves the row open
        }
        if (!isset($cells[$end + 1])) {
            return false;
        }
        // Would the next cell have fitted where the run starts?
        return $start + self::cellSpan($cells[$end + 1], $columns) <= self::PANEL_MAX_SPAN;
    }

    /**
     * G718 (1.1.162) — put each kept gap back right after the package cell it followed in the deployed panel.
     * @param array $definition
     * @param array $gaps
     * @param array $deployed
     * @return array
     */
    private static function placeGaps(array $definition, array $gaps, array $deployed): array
    {
        $definition = array_values($definition);
        if ($gaps === []) {
            return $definition;
        }
        $index = [];
        foreach ($definition as $i => $cell) {
            $name = self::panelCellName($cell);
            if ($name !== '' && !self::isPaddingCell($name) && !isset($index[$name])) {
                $index[$name] = $i;
            }
        }
        // Grouped by where they go, in deployed order: no sort callback (ModuleScanner's blacklist refuses usort() and friends, MLP002).
        $before = [];
        foreach ($gaps as $gap) {
            $at = -1;
            for ($j = (int) $gap['at'] - 1; $j >= 0; $j--) {
                $name = self::panelCellName($deployed[$j]);
                if (isset($index[$name])) {
                    $at = $index[$name];
                    break;
                }
            }
            $pos = $at + 1;
            while ($pos < count($definition) && self::isPaddingCell(self::panelCellName($definition[$pos]))) {
                $pos++;
            }
            foreach ($gap['cells'] as $cell) {
                $before[$pos][] = $cell;
            }
        }
        $out = [];
        $total = count($definition);
        for ($i = 0; $i <= $total; $i++) {
            foreach ($before[$i] ?? [] as $cell) {
                $out[] = $cell;
            }
            if ($i < $total) {
                $out[] = $definition[$i];
            }
        }

        return $out;
    }

    /**
     * G718 (1.1.162) — a rebuilt owned panel never places a field the view already places in another panel. (G606, G452, G747)
     */
    private function withoutFieldsPlacedElsewhere(string $module, array $panel, array $otherPanels): array
    {
        $elsewhere = [];
        foreach ($otherPanels as $other) {
            foreach ($this->collectFieldNames(is_array($other) ? ($other['fields'] ?? []) : []) as $name) {
                $elsewhere[$name] = true;
            }
        }
        $fields = [];
        $dropped = [];
        foreach ((array) ($panel['fields'] ?? []) as $cell) {
            $name = self::panelCellName($cell);
            if ($name !== '' && !self::isPaddingCell($name) && isset($elsewhere[$name])) {
                $dropped[] = $name;
                continue;
            }
            $fields[] = $cell;
        }
        if ($dropped === []) {
            return $panel;
        }
        $panel['fields'] = $fields;
        if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
            $GLOBALS['log']->fatal(sprintf(
                'ERP layout: %s panel %s left %s where the view already places it (G718: never twice)',
                $module,
                (string) ($panel['name'] ?? '?'),
                implode(', ', $dropped)
            ));
        }

        return $panel;
    }

    protected function addFieldsToRecordView(string $module, array $fieldsToAdd, array $targetPanelProperties = ['name' => 'panel_body'], ?string $afterName = null, ?array $createPanelWhenMissing = null): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];

        // Find the panel that matches each of the target panel properties
        if (empty($targetPanelProperties)) {
            return;
        }

        $panelIndex = null;
        foreach ($panels as $index => $panel) {
            if (empty(array_diff_assoc($targetPanelProperties, $panel))) {
                $panelIndex = $index;
            }
        }

        if (is_null($panelIndex)) {
            // A caller that only knows how to locate a panel silently does nothing on an instance where that panel no longer exists.
            if ($createPanelWhenMissing === null) {
                return;
            }

            $panels[] = array_merge($createPanelWhenMissing, $targetPanelProperties, ['fields' => []]);
            $panelIndex = array_key_last($panels);
        }

        if (!isset($panels[$panelIndex]['fields']) || !is_array($panels[$panelIndex]['fields'])) {
            $panels[$panelIndex]['fields'] = [];
        }

        $existingFields =& $panels[$panelIndex]['fields'];
        $existingFieldNames = [];

        foreach ($existingFields as $field) {
            $existingFieldNames[] = is_array($field) ? ($field['name'] ?? '') : $field;
        }

        $newFields = [];
        foreach ($fieldsToAdd as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (!in_array($name, $existingFieldNames, true)) {
                $newFields[] = $field;
            }
        }

        if (empty($newFields)) {
            return;
        }

        if ($afterName !== null) {
            $afterIndex = array_search($afterName, $existingFieldNames);
            if ($afterIndex !== false) {
                array_splice($existingFields, $afterIndex + 1, 0, $newFields);
            } else {
                array_push($existingFields, ...$newFields);
            }
        } else {
            array_push($existingFields, ...$newFields);
        }

        $this->deployView($module, 'record', $viewdefs);
    }

    /**
     * Reconcile a field definition inside a panel this package owns. (🔒 774)
     * @param array $fields
     */
    protected function reconcileFieldsInRecordViewPanel(string $module, array $targetPanelProperties, array $fields): void
    {
        if (empty($targetPanelProperties) || empty($fields)) {
            return;
        }

        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];

        $panelIndex = null;
        foreach ($panels as $index => $panel) {
            if (empty(array_diff_assoc($targetPanelProperties, $panel))) {
                $panelIndex = $index;
            }
        }

        if ($panelIndex === null || !isset($panels[$panelIndex]['fields']) || !is_array($panels[$panelIndex]['fields'])) {
            return;
        }

        $existingFields =& $panels[$panelIndex]['fields'];
        $reconciled = 0;

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = $field['name'] ?? '';
            if ($name === '') {
                continue;
            }
            foreach ($existingFields as $at => $existing) {
                $existingName = is_array($existing) ? ($existing['name'] ?? '') : $existing;
                if ($existingName !== $name) {
                    continue;
                }
                $before = is_array($existing) ? $existing : ['name' => $existing];
                $merged = array_merge($before, $field);
                if ($merged !== $existing) {
                    $existingFields[$at] = $merged;
                    $reconciled++;
                }
                break;
            }
        }

        if ($reconciled === 0) {
            return;
        }

        $this->deployView($module, 'record', $viewdefs);
    }

    /** The stock attributes of the record view's hidden ("Show more") panel. (🔒 1413) */
    protected function hiddenPanelDefinition(): array
    {
        return [
            'name' => 'panel_hidden',
            'label' => 'LBL_RECORD_SHOWMORE',
            'hide' => true,
            'placeholders' => true,
            'columns' => 2,
        ];
    }

    /** Appends field names to a nested collection field's own 'fields' list inside the record view. */
    protected function addFieldsToNestedCollection(string $module, string $collectionFieldName, array $fieldsToAdd): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];

        $changed = false;
        $this->addFieldsToNestedCollectionRecursive($panels, $collectionFieldName, $fieldsToAdd, $changed);

        if ($changed) {
            $this->deployView($module, 'record', $viewdefs);
        }
    }

    protected function removeFieldsFromNestedCollection(string $module, string $collectionFieldName, array $fieldsToRemove): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];

        $changed = false;
        $this->removeFieldsFromNestedCollectionRecursive($panels, $collectionFieldName, $fieldsToRemove, $changed);

        if ($changed) {
            $this->deployView($module, 'record', $viewdefs);
        }
    }

    private function addFieldsToNestedCollectionRecursive(&$node, string $collectionFieldName, array $fieldsToAdd, bool &$changed): void
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node['name']) && $node['name'] === $collectionFieldName && isset($node['fields']) && is_array($node['fields'])) {
            foreach ($fieldsToAdd as $field) {
                if (!in_array($field, $node['fields'], true)) {
                    $node['fields'][] = $field;
                    $changed = true;
                }
            }
            return;
        }

        foreach ($node as &$value) {
            if (is_array($value)) {
                $this->addFieldsToNestedCollectionRecursive($value, $collectionFieldName, $fieldsToAdd, $changed);
            }
        }
    }

    private function removeFieldsFromNestedCollectionRecursive(&$node, string $collectionFieldName, array $fieldsToRemove, bool &$changed): void
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node['name']) && $node['name'] === $collectionFieldName && isset($node['fields']) && is_array($node['fields'])) {
            $remaining = [];
            foreach ($node['fields'] as $field) {
                if (!in_array($field, $fieldsToRemove, true)) {
                    $remaining[] = $field;
                }
            }
            if (count($remaining) < count($node['fields'])) {
                $node['fields'] = $remaining;
                $changed = true;
            }
            return;
        }

        foreach ($node as &$value) {
            if (is_array($value)) {
                $this->removeFieldsFromNestedCollectionRecursive($value, $collectionFieldName, $fieldsToRemove, $changed);
            }
        }
    }

    /** Adds record-view dependency rules (Sidecar's real field-visibility/ required/value mechanism). */
    protected function addDependenciesToRecordView(string $module, array $dependenciesToAdd): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }

        if (empty($viewdefs['base']['view']['record']['dependencies']) || !is_array($viewdefs['base']['view']['record']['dependencies'])) {
            $viewdefs['base']['view']['record']['dependencies'] = [];
        }

        $dependencies =& $viewdefs['base']['view']['record']['dependencies'];
        $existing = [];

        foreach ($dependencies as $dependency) {
            $existing[] = serialize($dependency);
        }

        foreach ($dependenciesToAdd as $dependency) {
            if (!in_array(serialize($dependency), $existing, true)) {
                $dependencies[] = $dependency;
                $existing[] = serialize($dependency);
            }
        }

        $this->deployView($module, 'record', $viewdefs);
    }

    /**
     * Remove fields from one panel, matched the same way addFieldsToRecordView matches its target. removeFieldsFromRecordView (below) sweeps every panel.
     */
    protected function removeFieldsFromRecordViewPanelUnguarded(string $module, array $targetPanelProperties, array $fieldNamesToRemove): void
    {
        // G26: callers pass field definitions (['name' => 'x']), not names. (G148)
        $fieldNamesToRemove = $this->collectFieldNames($fieldNamesToRemove ?? []);
        if (empty($targetPanelProperties)) {
            return;
        }

        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];

        $panelIndex = null;
        foreach ($panels as $index => $panel) {
            if (empty(array_diff_assoc($targetPanelProperties, $panel))) {
                $panelIndex = $index;
            }
        }

        if (is_null($panelIndex)
            || empty($panels[$panelIndex]['fields'])
            || !is_array($panels[$panelIndex]['fields'])) {
            return;
        }

        $existingFields = $panels[$panelIndex]['fields'];
        $newFields = [];
        foreach ($existingFields as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (!in_array($name, $fieldNamesToRemove, true)) {
                $newFields[] = $field;
            }
        }

        if (count($newFields) === count($existingFields)) {
            return;
        }

        $panels[$panelIndex]['fields'] = array_values($newFields);
        $this->deployView($module, 'record', $viewdefs);
    }

    protected function removeFieldsFromRecordViewUnguarded(string $module, array $fieldNamesToRemove): void
    {
        // G26: callers pass field definitions (['name' => 'x']), not names. (G148)
        $fieldNamesToRemove = $this->collectFieldNames($fieldNamesToRemove ?? []);
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];
        $changed = false;

        foreach ($panels as &$panel) {
            if (empty($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }

            $newFields = [];
            foreach ($panel['fields'] as $field) {
                $name = is_array($field) ? ($field['name'] ?? '') : $field;
                if (!in_array($name, $fieldNamesToRemove, true)) {
                    $newFields[] = $field;
                }
            }

            if (count($newFields) < count($panel['fields'])) {
                $panel['fields'] = array_values($newFields);
                $changed = true;
            }
        }

        if ($changed) {
            $this->deployView($module, 'record', $viewdefs);
        }
    }

    protected function removeDependenciesFromRecordView(string $module, array $dependenciesToRemove): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $dependencies = $viewdefs['base']['view']['record']['dependencies'] ?? [];
        $toRemove = [];

        foreach ($dependenciesToRemove as $dependency) {
            $toRemove[] = serialize($dependency);
        }

        $newDependencies = [];

        foreach ($dependencies as $dependency) {
            if (!in_array(serialize($dependency), $toRemove, true)) {
                $newDependencies[] = $dependency;
            }
        }

        if (count($newDependencies) < count($dependencies)) {
            $viewdefs['base']['view']['record']['dependencies'] = array_values($newDependencies);
            $this->deployView($module, 'record', $viewdefs);
        }
    }

    /** G702: patch the effective (possibly Studio/PDF-customized) invoice view. */
    public function setInvoiceOverdueDueDate(bool $install): void
    {
        $viewdefs = $this->loadView('ERP_Invoices', 'record');
        if ($viewdefs === null) {
            return;
        }
        $before = $viewdefs;
        if (isset($viewdefs['base']['view']['record']['panels'])) {
            self::applyInvoiceOverdueDueDate($viewdefs['base']['view']['record']['panels'], $install);
        }
        if ($viewdefs !== $before) {
            $this->deployView('ERP_Invoices', 'record', $viewdefs);
        }
    }

    private static function applyInvoiceOverdueDueDate(array &$nodes, bool $install): void
    {
        foreach ($nodes as &$node) {
            if ($node === 'due_date' && $install) {
                $node = ['name' => 'due_date'];
            }
            if (!is_array($node)) {
                continue;
            }
            if (($node['name'] ?? '') !== 'due_date') {
                if (isset($node['fields']) && is_array($node['fields'])) {
                    self::applyInvoiceOverdueDueDate($node['fields'], $install);
                }
                continue;
            }
            // Viewdef rollback metadata, like erp_pdf_original_actions; not a vardef or a tenant configuration setting.
            $backup = 'erp_invoice_due_original_related_fields';
            if ($install) {
                if (!array_key_exists($backup, $node) && ($node['type'] ?? '') !== 'erp-invoice-due') {
                    $node[$backup] = array_intersect_key($node, ['related_fields' => true]);
                }
                $node['type'] = 'erp-invoice-due';
                if (!in_array('balance_amount', $node['related_fields'] ?? [], true)) {
                    $node['related_fields'][] = 'balance_amount';
                }
            } elseif (($node['type'] ?? '') === 'erp-invoice-due') {
                $node['type'] = 'date';
                $original = $node[$backup] ?? [];
                if (!in_array('balance_amount', $original['related_fields'] ?? [], true)) {
                    $retained = [];
                    foreach ($node['related_fields'] ?? [] as $field) {
                        if ($field !== 'balance_amount') {
                            $retained[] = $field;
                        }
                    }
                    $node['related_fields'] = $retained;
                    if ($node['related_fields'] === [] && !array_key_exists('related_fields', $original)) {
                        unset($node['related_fields']);
                    }
                }
                unset($node[$backup]);
            }
        }
        unset($node);
    }

    /** G737 + G703: the ERP Orders / ERP Invoices record views, patched on the effective viewdef. (G702, G452, G148) */
    public function setErpDocumentRecordLayout(string $module): void
    {
        try {
            $viewdefs = $this->loadView($module, 'record');
            if ($viewdefs === null || !isset($viewdefs['base']['view']['record']['panels'])
                || !is_array($viewdefs['base']['view']['record']['panels'])) {
                return;
            }
            $panels = $viewdefs['base']['view']['record']['panels'];
            $patched = self::applyErpDocumentRecordLayout($panels, $module === 'ERP_Invoices');
            if ($patched !== $panels) {
                $viewdefs['base']['view']['record']['panels'] = $patched;
                $this->deployView($module, 'record', $viewdefs);
            }
        } catch (\Throwable $e) {
            $GLOBALS['log']->fatal(
                '[BaseErpLayout] G737/G703 record layout skipped on ' . (string) $module . ' — '
                . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    /** The pure half of setErpDocumentRecordLayout(), over a view's panels. */
    public static function applyErpDocumentRecordLayout(array $panels, bool $placeInvoiceDiscount): array
    {
        $numberLinks = false;
        foreach ($panels as $p => $panel) {
            if (!is_array($panel) || !isset($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }
            foreach ($panel['fields'] as $f => $cell) {
                if (self::panelCellName($cell) !== 'erp_display_sync_key') {
                    continue;
                }
                $cell = is_array($cell) ? $cell : ['name' => 'erp_display_sync_key'];
                $related = (isset($cell['related_fields']) && is_array($cell['related_fields'])) ? $cell['related_fields'] : [];
                if (!in_array('epicor_deeplink_url', $related, true)) {
                    $related[] = 'epicor_deeplink_url';
                    $cell['related_fields'] = $related;
                    $panels[$p]['fields'][$f] = $cell;
                }
                $numberLinks = $numberLinks || ($cell['type'] ?? '') === 'erp-id-link';
            }
        }

        if ($numberLinks) {
            foreach ($panels as $p => $panel) {
                if (!is_array($panel) || ($panel['name'] ?? '') !== 'panel_hidden'
                    || !isset($panel['fields']) || !is_array($panel['fields'])) {
                    continue;
                }
                $kept = [];
                foreach ($panel['fields'] as $cell) {
                    if (self::panelCellName($cell) !== 'epicor_deeplink_url') {
                        $kept[] = $cell;
                    }
                }
                if (count($kept) !== count($panel['fields'])) {
                    $panels[$p]['fields'] = $kept;
                }
            }
        }

        if ($placeInvoiceDiscount && !self::panelsPlaceField($panels, 'discount_amount')) {
            $discount = ['name' => 'discount_amount', 'readonly' => true, 'related_fields' => ['currency_id', 'base_rate']];
            $at = self::topLevelCellPosition($panels, 'tax_amount');
            if ($at === null) {
                $at = self::topLevelCellPosition($panels, 'sub_total_amount');
                if ($at !== null) {
                    $at[1]++;
                }
            }
            if ($at === null) {
                foreach ($panels as $p => $panel) {
                    if (is_array($panel) && ($panel['name'] ?? '') === 'LBL_RECORDVIEW_PANEL_INVOICE_DETAIL') {
                        $at = [$p, count((array) ($panel['fields'] ?? []))];
                    }
                }
            }
            if ($at !== null) {
                $fields = array_values((array) ($panels[$at[0]]['fields'] ?? []));
                array_splice($fields, $at[1], 0, [$discount]);
                $panels[$at[0]]['fields'] = $fields;
            }
        }

        return $panels;
    }

    /** Is $name placed anywhere in these panels (fieldset members included)? */
    private static function panelsPlaceField(array $panels, string $name): bool
    {
        foreach ($panels as $panel) {
            foreach ((array) (is_array($panel) ? ($panel['fields'] ?? []) : []) as $cell) {
                if (self::panelCellName($cell) === $name) {
                    return true;
                }
                foreach ((array) (is_array($cell) ? ($cell['fields'] ?? []) : []) as $member) {
                    if (self::panelCellName($member) === $name) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** [panel index, position in its field list] of a top-level cell, or null. */
    private static function topLevelCellPosition(array $panels, string $name): ?array
    {
        foreach ($panels as $p => $panel) {
            $position = 0;
            foreach ((array) (is_array($panel) ? ($panel['fields'] ?? []) : []) as $cell) {
                if (self::panelCellName($cell) === $name) {
                    return [$p, $position];
                }
                $position++;
            }
        }

        return null;
    }

    /** G695: install/uninstall only our PDF menu entries, retaining other actions. */
    public function setFlatPdfActions(string $module, bool $install): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $buttons =& $viewdefs['base']['view']['record']['buttons'];
        if (!is_array($buttons)) {
            return;
        }
        self::applyFlatPdfActions($buttons, $install);
        $this->deployView($module, 'record', $viewdefs);
    }

    public static function applyFlatPdfActions(array &$nodes, bool $install): void
    {
        foreach ($nodes as &$node) {
            if (!isset($node['buttons']) || !is_array($node['buttons'])) {
                continue;
            }
            $buttons =& $node['buttons'];
            // Fresh ERP viewdefs already carry flat actions.
            if (!$install && !isset($node['erp_pdf_original_actions'])) {
                foreach ($buttons as &$button) {
                    if (($button['type'] ?? '') === 'erp-pdf-action') {
                        $button['type'] = 'pdfaction';
                    }
                }
                unset($button);
            }
            if (!$install && isset($node['erp_pdf_original_actions'])) {
                $retained = [];
                foreach ($buttons as $button) {
                    if (($button['type'] ?? '') !== 'erp-pdf-action') {
                        $retained[] = $button;
                    }
                }
                $buttons = $retained;
                foreach ($node['erp_pdf_original_actions'] as $index => $original) {
                    array_splice($buttons, (int)$index, 0, [$original]);
                }
                unset($node['erp_pdf_original_actions']);
            } elseif ($install && !isset($node['erp_pdf_original_actions'])) {
                $originals = [];
                $updated = [];
                $hasPdf = false;
                foreach ($buttons as $button) {
                    $hasPdf = $hasPdf || ($button['type'] ?? '') === 'pdfaction';
                }
                if ($hasPdf) {
                    $emailAdded = false;
                    $downloadAdded = false;
                    // Preserve Share and every other non-PDF action verbatim.
                    foreach (array_values($buttons) as $index => $button) {
                        $type = $button['type'] ?? '';
                        if ($type !== 'pdfaction') {
                            $updated[] = $button;
                            continue;
                        }
                        $originals[$index] = $button;
                        $email = ($button['action'] ?? '') === 'email';
                        $download = ($button['action'] ?? '') === 'download';
                        if (($email && !$emailAdded) || ($download && !$downloadAdded)) {
                            $action = $email ? 'email' : 'download';
                            $updated[] = [
                                'type' => 'erp-pdf-action', 'name' => $action . '-pdf',
                                'label' => $email ? 'LBL_PDF_EMAIL' : 'LBL_PDF_VIEW',
                                'action' => $action, 'acl_action' => 'view',
                            ];
                            $emailAdded = $emailAdded || $email;
                            $downloadAdded = $downloadAdded || $download;
                        }
                    }
                    if (!$emailAdded) {
                        $updated[] = ['type' => 'erp-pdf-action', 'name' => 'email-pdf',
                            'label' => 'LBL_PDF_EMAIL', 'action' => 'email', 'acl_action' => 'view'];
                    }
                    if (!$downloadAdded) {
                        $updated[] = ['type' => 'erp-pdf-action', 'name' => 'download-pdf',
                            'label' => 'LBL_PDF_VIEW', 'action' => 'download', 'acl_action' => 'view'];
                    }
                    $node['erp_pdf_original_actions'] = $originals;
                    $buttons = $updated;
                }
            }
            self::applyFlatPdfActions($buttons, $install);
            unset($buttons);
        }
        unset($node);
    }

    protected function getStockRecordViewButtons(string $module): array
    {
        $file = "modules/{$module}/clients/base/views/record/record.php";

        if (!file_exists($file)) {
            return [];
        }

        $viewdefs = [];
        include $file;

        return $viewdefs[$module]['base']['view']['record']['buttons'] ?? [];
    }

    protected function addButtonsToRecordView(string $module, array $buttonsToAdd, ?string $beforeName = null): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }

        if (empty($viewdefs['base']['view']['record']['buttons']) || !is_array($viewdefs['base']['view']['record']['buttons'])) {
            $viewdefs['base']['view']['record']['buttons'] = $this->getStockRecordViewButtons($module);
        }

        $buttons =& $viewdefs['base']['view']['record']['buttons'];
        $existingNames = array_column($buttons, 'name');

        // Add is not enough — an existing button must be reconciled (🔒 774).
        $newButtons = [];
        $reconciled = 0;
        foreach ($buttonsToAdd as $button) {
            $at = array_search($button['name'], $existingNames, true);
            if ($at === false) {
                $newButtons[] = $button;
                continue;
            }
            $before = is_array($buttons[$at]) ? $buttons[$at] : [];
            $merged = array_merge($before, $button);
            if ($merged !== $before) {
                $buttons[$at] = $merged;
                $reconciled++;
            }
        }

        if (empty($newButtons) && $reconciled === 0) {
            return;
        }

        if (empty($newButtons)) {
            // Nothing to insert, but a definition changed and must be written.
            $this->deployView($module, 'record', $viewdefs);
            return;
        }

        $insertIndex = $beforeName !== null ? array_search($beforeName, array_column($buttons, 'name')) : false;

        if ($insertIndex !== false) {
            array_splice($buttons, $insertIndex, 0, $newButtons);
        } else {
            array_push($buttons, ...$newButtons);
        }

        $this->deployView($module, 'record', $viewdefs);
    }

    protected function removeButtonsFromRecordView(string $module, array $buttonNames): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }

        if (empty($viewdefs['base']['view']['record']['buttons']) || !is_array($viewdefs['base']['view']['record']['buttons'])) {
            $viewdefs['base']['view']['record']['buttons'] = $this->getStockRecordViewButtons($module);
        }

        $buttons = $viewdefs['base']['view']['record']['buttons'];
        $newButtons = [];

        foreach ($buttons as $b) {
            if (!in_array($b['name'] ?? '', $buttonNames, true)) {
                $newButtons[] = $b;
            }
        }

        if (count($newButtons) < count($buttons)) {
            $viewdefs['base']['view']['record']['buttons'] = array_values($newButtons);
            $this->deployView($module, 'record', $viewdefs);
        }
    }

    protected function addButtonsToDropdown(string $module, string $dropdownName, array $buttonsToAdd): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }

        if (empty($viewdefs['base']['view']['record']['buttons']) || !is_array($viewdefs['base']['view']['record']['buttons'])) {
            $viewdefs['base']['view']['record']['buttons'] = $this->getStockRecordViewButtons($module);
        }

        $buttons =& $viewdefs['base']['view']['record']['buttons'];
        $dropdownIndex = array_search($dropdownName, array_column($buttons, 'name'));

        if ($dropdownIndex === false) {
            return;
        }

        if (!isset($buttons[$dropdownIndex]['buttons']) || !is_array($buttons[$dropdownIndex]['buttons'])) {
            $buttons[$dropdownIndex]['buttons'] = [];
        }

        $nested =& $buttons[$dropdownIndex]['buttons'];
        $existingNames = array_column($nested, 'name');

        foreach ($buttonsToAdd as $button) {
            if (!in_array($button['name'], $existingNames, true)) {
                $nested[] = $button;
                $existingNames[] = $button['name'];
            }
        }

        $this->deployView($module, 'record', $viewdefs);
    }

    protected function removeButtonsFromDropdown(string $module, string $dropdownName, array $buttonNames): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $buttons = $viewdefs['base']['view']['record']['buttons'] ?? [];
        $dropdownIndex = array_search($dropdownName, array_column($buttons, 'name'));

        if ($dropdownIndex === false || !isset($buttons[$dropdownIndex]['buttons'])) {
            return;
        }

        $nested = $buttons[$dropdownIndex]['buttons'];
        $newNested = [];

        foreach ($nested as $b) {
            if (!in_array($b['name'] ?? '', $buttonNames, true)) {
                $newNested[] = $b;
            }
        }

        if (count($newNested) < count($nested)) {
            $viewdefs['base']['view']['record']['buttons'][$dropdownIndex]['buttons'] = array_values($newNested);
            $this->deployView($module, 'record', $viewdefs);
        }
    }

    protected function setPanelBodyAsNewTab(string $module): void
    {
        $this->setPanelAsNewTab($module, 'panel_body');
    }

    protected function setPanelAsNewTab(string $module, string $panelName): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];
        $panelIndex = array_search($panelName, array_column($panels, 'name'));

        if ($panelIndex === false) {
            return;
        }

        $panels[$panelIndex]['newTab'] = true;
        $this->deployView($module, 'record', $viewdefs);
    }

    /** G606 / G608 — is $fieldName on any panel of the deployed record view. */
    protected function recordViewHasField(string $module, string $fieldName): bool
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return false;
        }
        foreach ((array) ($viewdefs['base']['view']['record']['panels'] ?? []) as $panel) {
            foreach ((array) (is_array($panel) ? ($panel['fields'] ?? []) : []) as $entry) {
                $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
                if ($name === $fieldName) {
                    return true;
                }
                if (is_array($entry) && isset($entry['fields']) && is_array($entry['fields'])
                    && in_array($fieldName, $this->collectFieldNames($entry['fields']), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 🔒 1795b — set (a value) or drop (null) keys on every entry of one field on the record view, whichever panel holds it (fieldset members included).
     * @param array<string,
     */
    protected function setFieldPropertiesInRecordView(string $module, string $fieldName, array $properties): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];
        $changed = false;
        foreach ($panels as $i => $panel) {
            if (!is_array($panel) || !isset($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }
            $fields = self::applyFieldProperties($panel['fields'], $fieldName, $properties);
            if ($fields !== $panel['fields']) {
                $panels[$i]['fields'] = $fields;
                $changed = true;
            }
        }
        if ($changed) {
            $this->deployView($module, 'record', $viewdefs);
        }
    }

    /**
     * The pure half of setFieldPropertiesInRecordView(), over one panel's fields.
     * @param array<string,
     */
    public static function applyFieldProperties(array $fields, string $fieldName, array $properties): array
    {
        foreach ($fields as $k => $entry) {
            if (is_array($entry) && isset($entry['fields']) && is_array($entry['fields'])) {
                $fields[$k]['fields'] = self::applyFieldProperties($entry['fields'], $fieldName, $properties);
                continue;
            }
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
            if ($name !== $fieldName) {
                continue;
            }
            $normalised = is_array($entry) ? $entry : ['name' => $fieldName];
            foreach ($properties as $key => $value) {
                if ($value === null) {
                    unset($normalised[$key]);
                } else {
                    $normalised[$key] = $value;
                }
            }
            $fields[$k] = $normalised;
        }

        return $fields;
    }

    /**
     * G606 — move existing record-view panels to sit right before another one, setting (a value) or dropping (null) the given keys on each moved panel.
     * @param string[] $panelNames
     * @param array<string,
     */
    protected function movePanelsBefore(string $module, array $panelNames, string $beforePanel, array $properties = []): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];
        $updated = self::reorderPanelsBefore($panels, $panelNames, $beforePanel, $properties);
        if ($updated === $panels) {
            return;
        }
        $panels = $updated;
        $this->deployView($module, 'record', $viewdefs);
    }

    /**
     * The pure half of movePanelsBefore(), static so a test can run it over a served view.
     * @param string[] $panelNames
     * @param array<string,
     */
    public static function reorderPanelsBefore(array $panels, array $panelNames, string $beforePanel, array $properties = []): array
    {
        $moving = [];
        $rest = [];
        foreach ($panels as $panel) {
            $name = is_array($panel) ? (string) ($panel['name'] ?? '') : '';
            if ($name !== '' && $name !== $beforePanel && in_array($name, $panelNames, true)) {
                foreach ($properties as $key => $value) {
                    if ($value === null) {
                        unset($panel[$key]);
                    } else {
                        $panel[$key] = $value;
                    }
                }
                $moving[$name] = $panel;
                continue;
            }
            $rest[] = $panel;
        }
        if ($moving === []) {
            return $panels;
        }
        $ordered = [];
        foreach ($panelNames as $name) {
            if (isset($moving[$name])) {
                $ordered[] = $moving[$name];
            }
        }
        $out = [];
        $placed = false;
        foreach ($rest as $panel) {
            if (!$placed && is_array($panel) && ($panel['name'] ?? '') === $beforePanel) {
                foreach ($ordered as $moved) {
                    $out[] = $moved;
                }
                $placed = true;
            }
            $out[] = $panel;
        }

        return $placed ? $out : $panels;
    }

    /**
     * G606 — move one field's entry (its whole definition, unchanged) from one record-view panel to another, after $afterName there (else at the end).
     */
    protected function moveFieldToPanel(string $module, string $fieldName, string $fromPanel, string $toPanel, ?string $afterName = null): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];
        $from = null;
        $to = null;
        foreach ($panels as $i => $panel) {
            $name = is_array($panel) ? (string) ($panel['name'] ?? '') : '';
            if ($name === $fromPanel) {
                $from = $i;
            }
            if ($name === $toPanel) {
                $to = $i;
            }
        }
        if ($from === null || $to === null || $from === $to) {
            return;
        }
        $entry = null;
        $kept = [];
        foreach ((array) ($panels[$from]['fields'] ?? []) as $field) {
            $name = is_array($field) ? (string) ($field['name'] ?? '') : (string) $field;
            if ($name === $fieldName && $entry === null) {
                $entry = $field;
                continue;
            }
            $kept[] = $field;
        }
        if ($entry === null) {
            return;
        }
        $panels[$from]['fields'] = $kept;
        $target = array_values((array) ($panels[$to]['fields'] ?? []));
        if (!in_array($fieldName, $this->collectFieldNames($target), true)) {
            // The index in $target itself: collectFieldNames() skips Studio's unnamed padding cells, so its positions are not the panel's.
            $at = null;
            foreach ($target as $i => $field) {
                $name = is_array($field) ? (string) ($field['name'] ?? '') : (string) $field;
                if ($afterName !== null && $name === $afterName) {
                    $at = $i;
                }
            }
            if ($at === null) {
                $target[] = $entry;
            } else {
                array_splice($target, $at + 1, 0, [$entry]);
            }
        }
        $panels[$to]['fields'] = array_values($target);
        $this->deployView($module, 'record', $viewdefs);
    }

    /** G148: these five removers had never run, and making them run broke an install. (G26) */

    protected function removeFieldsFromRecordViewPanel(string $module, array $targetPanelProperties, array $fieldNamesToRemove)
    {
        // G148: statically-named call + inline try/catch.
        try {
            $this->removeFieldsFromRecordViewPanelUnguarded($module, $targetPanelProperties, $fieldNamesToRemove);
        } catch (\Throwable $e) {
            $GLOBALS['log']->fatal(
                '[BaseErpLayout] G148 removal skipped: removeFieldsFromRecordViewPanel on '
                . (string) $module . ' — ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    protected function removeFieldsFromRecordView(string $module, array $fieldNamesToRemove)
    {
        // G148: statically-named call + inline try/catch.
        try {
            $this->removeFieldsFromRecordViewUnguarded($module, $fieldNamesToRemove);
        } catch (\Throwable $e) {
            $GLOBALS['log']->fatal(
                '[BaseErpLayout] G148 removal skipped: removeFieldsFromRecordView on '
                . (string) $module . ' — ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    protected function removeErpFieldsFromSubpanel(string $module, string $subpanel, array $fieldDefs)
    {
        // G148: statically-named call + inline try/catch.
        try {
            $this->removeErpFieldsFromSubpanelUnguarded($module, $subpanel, $fieldDefs);
        } catch (\Throwable $e) {
            $GLOBALS['log']->fatal(
                '[BaseErpLayout] G148 removal skipped: removeErpFieldsFromSubpanel on '
                . (string) $module . ' — ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    protected function removeFieldsFromDataGroupListView(string $module, array $fieldsToRemove)
    {
        // G148: statically-named call + inline try/catch.
        try {
            $this->removeFieldsFromDataGroupListViewUnguarded($module, $fieldsToRemove);
        } catch (\Throwable $e) {
            $GLOBALS['log']->fatal(
                '[BaseErpLayout] G148 removal skipped: removeFieldsFromDataGroupListView on '
                . (string) $module . ' — ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    protected function removeFieldsFromTotalsFooter(
        string $module,
        array $fieldNamesToRemove,
        string $view = 'quote-data-grand-totals-footer'
    )
    {
        // G148: statically-named call + inline try/catch.
        try {
            $this->removeFieldsFromTotalsFooterUnguarded($module, $fieldNamesToRemove, $view);
        } catch (\Throwable $e) {
            $GLOBALS['log']->fatal(
                '[BaseErpLayout] G148 removal skipped: removeFieldsFromTotalsFooter on '
                . (string) $module . ' — ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    protected function collectFieldNames(?array $fields): array
    {
        $out = [];
        foreach (($fields ?? []) as $f) {
            $name = is_array($f) ? ($f['name'] ?? '') : $f;
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    private function mergeManagedFields(array &$existingFields, array $fieldsToAdd): void
    {
        if ($this->replace) {
            $adding = $this->collectFieldNames($fieldsToAdd);
            $existing = [];
            foreach ($existingFields as $f) {
                $entry = is_array($f) ? $f : ['name' => $f];
                $entry['default'] = false;
                $name = $entry['name'] ?? '';
                if ($name === '' || $this->isErpField($name) || in_array($name, $adding, true)) {
                    continue;
                }
                $existing[] = $entry;
            }
            $existingFields = array_merge($existing, $fieldsToAdd);
            return;
        }

        $existingFieldNames = $this->collectFieldNames($existingFields);
        foreach ($fieldsToAdd as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if ($name === '' || in_array($name, $existingFieldNames, true)) {
                continue;
            }
            $existingFields[] = $field;
            $existingFieldNames[] = $name;
        }
    }

    protected function addFieldsToListView(string $module, array $fieldsToAdd): void
    {
        $viewdefs = $this->loadView($module, 'list');
        if ($viewdefs === null) {
            return;
        }
        $existingFields =& $viewdefs['base']['view']['list']['panels'][0]['fields'];

        $this->mergeManagedFields($existingFields, $fieldsToAdd);

        $this->deployView($module, 'list', $viewdefs);
    }

    protected function addFieldsToSelectionList(string $module, array $fieldsToAdd): void
    {
        $viewdefs = $this->loadView($module, 'selection-list');
        if ($viewdefs === null) {
            return;
        }
        $existingFields =& $viewdefs['base']['view']['selection-list']['panels'][0]['fields'];

        $this->mergeManagedFields($existingFields, $fieldsToAdd);

        $this->deployView($module, 'selection-list', $viewdefs);
    }

    protected function addFieldsToSubpanel(string $module, string $subpanel, array $fieldsToAdd): void
    {
        $target = $this->loadSubpanelView($module, $subpanel);
        if ($target === null) {
            return;
        }
        $viewdefs = $target['defs'];
        $existingFields =& $viewdefs['panels'][0]['fields'];

        $this->mergeManagedFields($existingFields, $fieldsToAdd);

        $this->deploySubpanelView($target, $viewdefs);
    }

    protected function addAndReorderSubpanelFields(string $module, string $subpanel, array $fieldsToAdd, array $fieldNamesToDeactivate): void
    {
        $target = $this->loadSubpanelView($module, $subpanel);
        if ($target === null) {
            return;
        }
        $viewdefs = $target['defs'];
        $fields =& $viewdefs['panels'][0]['fields'];

        if ($this->replace) {
            $existing = [];
            foreach ($fields as $f) {
                $entry = is_array($f) ? $f : ['name' => $f];
                $entry['default'] = false;
                if (!$this->isErpField($entry['name'] ?? '')) {
                    $existing[] = $entry;
                }
            }
            $fields = array_merge($existing, $fieldsToAdd);
        } else {
            $existingFieldNames = array_column($fields, 'name');
            foreach ($fieldsToAdd as $field) {
                if (!in_array($field['name'], $existingFieldNames)) {
                    $fields[] = $field;
                    $existingFieldNames[] = $field['name'];
                }
            }
        }

        $remaining = [];
        $toAppend = [];
        foreach ($fields as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (in_array($name, $fieldNamesToDeactivate, true)) {
                $toAppend[] = is_array($field)
                    ? array_merge($field, ['enabled' => true, 'default' => false])
                    : ['name' => $name, 'enabled' => true, 'default' => false];
            } else {
                $remaining[] = $field;
            }
        }
        $fields = array_merge($remaining, $toAppend);

        $this->deploySubpanelView($target, $viewdefs);
    }

    protected function moveListViewFieldsToEnd(string $module, array $fieldNamesToDeactivate): void
    {
        $viewdefs = $this->loadView($module, 'list');
        if ($viewdefs === null) {
            return;
        }
        $fields =& $viewdefs['base']['view']['list']['panels'][0]['fields'];

        $remaining = [];
        $toAppend = [];
        foreach ($fields as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (in_array($name, $fieldNamesToDeactivate, true)) {
                $toAppend[] = is_array($field)
                    ? array_merge($field, ['enabled' => true, 'default' => false])
                    : ['name' => $name, 'enabled' => true, 'default' => false];
            } else {
                $remaining[] = $field;
            }
        }
        $fields = array_merge($remaining, $toAppend);

        $this->deployView($module, 'list', $viewdefs);
    }

    protected function moveRecordViewFieldsToHidden(string $module, array $fieldNamesToHide): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];
        $panelNames = array_column($panels, 'name');
        $panelBodyIndex = array_search('panel_body', $panelNames);
        $panelHiddenIndex = array_search('panel_hidden', $panelNames);

        if ($panelBodyIndex === false || $panelHiddenIndex === false) {
            return;
        }

        $panelBody =& $panels[$panelBodyIndex];
        $panelHidden =& $panels[$panelHiddenIndex];

        $remaining = [];
        $toHide = [];
        foreach ($panelBody['fields'] as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (in_array($name, $fieldNamesToHide, true)) {
                $toHide[] = $field;
            } else {
                $remaining[] = $field;
            }
        }
        $panelBody['fields'] = $remaining;
        $panelHidden['fields'] = array_merge($panelHidden['fields'], $toHide);

        $this->deployView($module, 'record', $viewdefs);
    }

    protected function addFieldsToDataGroupListView(string $module, array $fieldsToAdd): void
    {
        $viewdefs = $this->loadView($module, 'quote-data-group-list');
        if ($viewdefs === null) {
            return;
        }
        $existingFields =& $viewdefs['base']['view']['quote-data-group-list']['panels'][0]['fields'];

        if ($this->replace) {
            $existing = [];
            foreach ($existingFields as $f) {
                $entry = is_array($f) ? $f : ['name' => $f];
                $entry['default'] = false;
                if (!$this->isErpField($entry['name'] ?? '')) {
                    $existing[] = $entry;
                }
            }
            $existingFields = array_merge($existing, $fieldsToAdd);
        } else {
            $existingFieldNames = array_column($existingFields, 'name');
            foreach ($fieldsToAdd as $field) {
                if (!in_array($field['name'], $existingFieldNames)) {
                    $existingFields[] = $field;
                }
            }
        }

        $this->deployView($module, 'quote-data-group-list', $viewdefs);
    }

    // Same shape as addFieldsToDataGroupListView, but inserts before a named field instead of appending - for a column that needs to render first.
    protected function addFieldsToDataGroupListViewBefore(string $module, array $fieldsToAdd, string $beforeFieldName): void
    {
        $viewdefs = $this->loadView($module, 'quote-data-group-list');
        if ($viewdefs === null) {
            return;
        }
        $existingFields =& $viewdefs['base']['view']['quote-data-group-list']['panels'][0]['fields'];
        $existingFieldNames = array_column($existingFields, 'name');

        $newFields = [];
        foreach ($fieldsToAdd as $field) {
            if (!in_array($field['name'], $existingFieldNames, true)) {
                $newFields[] = $field;
            }
        }

        if (empty($newFields)) {
            return;
        }

        $insertIndex = array_search($beforeFieldName, $existingFieldNames, true);

        if ($insertIndex !== false) {
            array_splice($existingFields, $insertIndex, 0, $newFields);
        } else {
            array_unshift($existingFields, ...$newFields);
        }

        $this->deployView($module, 'quote-data-group-list', $viewdefs);
    }

    /** Re-label and re-order columns that are already on the quoted-lines grid (🔒 1435 / G175). */
    protected function orderAndRelabelDataGroupListColumns(
        string $module,
        array $spec,
        string $afterField
    ): void {
        $viewdefs = $this->loadView($module, 'quote-data-group-list');
        if ($viewdefs === null) {
            return;
        }
        $fields =& $viewdefs['base']['view']['quote-data-group-list']['panels'][0]['fields'];
        $updated = self::reorderAndRelabelGridColumns($fields, $spec, $afterField);
        if ($updated === $fields) {
            return;
        }
        $fields = $updated;
        $this->deployView($module, 'quote-data-group-list', $viewdefs);
    }

    /**
     * G605 — set (a value) or drop (null) keys on one existing grid column. (decision 803)
     * @param array<string,
     */
    protected function setDataGroupListColumnProperties(string $module, string $fieldName, array $properties): void
    {
        $viewdefs = $this->loadView($module, 'quote-data-group-list');
        if ($viewdefs === null) {
            return;
        }
        $fields =& $viewdefs['base']['view']['quote-data-group-list']['panels'][0]['fields'];
        $updated = self::applyGridColumnProperties($fields, $fieldName, $properties);
        if ($updated === $fields) {
            return;
        }
        $fields = $updated;
        $this->deployView($module, 'quote-data-group-list', $viewdefs);
    }

    /**
     * The pure half of setDataGroupListColumnProperties(), static so a test can run it over the grid a tenant actually serves.
     * @param array $fields
     * @param array<string,
     * @return array
     */
    public static function applyGridColumnProperties(array $fields, string $fieldName, array $properties): array
    {
        $out = [];
        foreach ($fields as $entry) {
            if (self::gridColumnName($entry) !== $fieldName) {
                $out[] = $entry;
                continue;
            }
            $normalised = is_array($entry) ? $entry : ['name' => $fieldName];
            foreach ($properties as $key => $value) {
                if ($value === null) {
                    unset($normalised[$key]);
                } else {
                    $normalised[$key] = $value;
                }
            }
            $out[] = $normalised;
        }
        return $out;
    }

    /** 🔒 1444 — set an existing record-view field's display type. */
    protected function setFieldTypeInRecordView(string $module, string $fieldName, string $type): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];
        if (!is_array($panels)) {
            return;
        }
        $changed = false;
        foreach ($panels as &$panel) {
            if (!isset($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }
            $updated = self::applyFieldType($panel['fields'], $fieldName, $type);
            if ($updated !== $panel['fields']) {
                $panel['fields'] = $updated;
                $changed = true;
            }
        }
        unset($panel);
        if (!$changed) {
            return;
        }
        $this->deployView($module, 'record', $viewdefs);
    }

    /**
     * 🔒 1434 (G121 ruling 1) — tell the quote's PDF menu which templates it must not offer, by ID.
     * @param string $module
     * @param string[] $templateIds
     * @param string $defKey
     */
    protected function setPdfActionTemplateExclusions(
        string $module,
        array $templateIds,
        string $defKey = 'erp_excluded_template_ids'
    ): void {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $buttons =& $viewdefs['base']['view']['record']['buttons'];
        if (!is_array($buttons)) {
            $GLOBALS['log']->fatal(
                'ERP PDF menu: ' . $module . ' record view declares no buttons - '
                . 'the quote PDF exclusion was NOT applied'
            );

            return;
        }

        $applied = 0;
        self::applyPdfActionExclusions($buttons, array_values($templateIds), $defKey, $applied);
        unset($buttons);

        if ($applied === 0) {
            // Never a silent success.
            $GLOBALS['log']->fatal(
                'ERP PDF menu: no pdfaction entry found on the ' . $module
                . ' record view - the PDF template exclusion was NOT applied'
            );

            return;
        }

        $GLOBALS['log']->fatal(
            'ERP PDF menu: excluded ' . count($templateIds) . ' PDF template id(s) on '
            . $applied . ' pdfaction entr' . ($applied === 1 ? 'y' : 'ies') . ' of the '
            . $module . ' record view'
        );
        $this->deployView($module, 'record', $viewdefs);
    }

    /**
     * Pure, recursive half of setPdfActionTemplateExclusions(), so the walk can be run over a real served viewdef in a test without a Sugar instance.
     * @param array $nodes
     * @param array $templateIds
     * @param string $defKey
     * @param int $applied
     */
    public static function applyPdfActionExclusions(
        array &$nodes,
        array $templateIds,
        string $defKey,
        int &$applied
    ): void {
        foreach ($nodes as &$node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['erp_pdf_original_actions'])) {
                self::applyPdfActionExclusions($node['erp_pdf_original_actions'], $templateIds, $defKey, $applied);
            }
            if (($node['type'] ?? '') === 'pdfaction') {
                $node[$defKey] = $templateIds;
                $applied++;
            }
            if (isset($node['buttons']) && is_array($node['buttons'])) {
                self::applyPdfActionExclusions($node['buttons'], $templateIds, $defKey, $applied);
            }
        }
        unset($node);
    }

    /** Pure half of setFieldTypeInRecordView, so the behaviour is testable without a Sugar instance. */
    public static function applyFieldType(array $fields, string $fieldName, string $type): array
    {
        $out = [];
        foreach ($fields as $entry) {
            if (self::gridColumnName($entry) !== $fieldName) {
                $out[] = $entry;
                continue;
            }
            $normalised = is_array($entry) ? $entry : ['name' => $fieldName];
            $normalised['type'] = $type;
            $out[] = $normalised;
        }
        return $out;
    }

    /**
     * A grid entry's field name, whichever of the two legal viewdef shapes it is (a bare string, or an array with 'name').
     */
    private static function gridColumnName($entry): string
    {
        return is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
    }

    /**
     * The pure half of orderAndRelabelDataGroupListColumns().
     * @param array $fields
     * @param array $spec
     * @param string $afterField
     * @return array
     */
    public static function reorderAndRelabelGridColumns(
        array $fields,
        array $spec,
        string $afterField
    ): array {
        $moving = [];
        foreach ($spec as $wanted) {
            foreach ($fields as $entry) {
                if (self::gridColumnName($entry) !== $wanted['name']) {
                    continue;
                }
                // A string entry becomes an array so it can carry a label; an array entry keeps every key it already had.
                $normalised = is_array($entry) ? $entry : ['name' => $wanted['name']];
                // A spec entry with no 'label' key means "move this column, leave its label alone".
                if (array_key_exists('label', $wanted)) {
                    $normalised['label'] = $wanted['label'];
                }
                // A spec entry may also carry a display type.
                if (array_key_exists('type', $wanted)) {
                    $normalised['type'] = $wanted['type'];
                }
                if (isset($wanted['labelModule'])) {
                    $normalised['labelModule'] = $wanted['labelModule'];
                }
                // G187 — and a column may carry a link target, for the same reason 'type' is here.
                if (array_key_exists('erp_product_link', $wanted)) {
                    $normalised['erp_product_link'] = $wanted['erp_product_link'];
                }
                $moving[$wanted['name']] = $normalised;
                break;
            }
        }

        if ($moving === []) {
            return $fields;
        }

        $rest = [];
        foreach ($fields as $entry) {
            if (!isset($moving[self::gridColumnName($entry)])) {
                $rest[] = $entry;
            }
        }

        // The anchor may itself be absent (another package removed it): then the group goes to the end rather than to index 0.
        $out = [];
        $placed = false;
        foreach ($rest as $entry) {
            $out[] = $entry;
            if (!$placed && self::gridColumnName($entry) === $afterField) {
                foreach ($moving as $m) {
                    $out[] = $m;
                }
                $placed = true;
            }
        }
        if (!$placed) {
            foreach ($moving as $m) {
                $out[] = $m;
            }
        }

        return array_values($out);
    }

    protected function removePanelFromRecordView(string $module): void
    {
        $this->removePanelsFromRecordView($module, [self::ERP_PANEL_NAME]);
    }

    /** Generalized form of removePanelFromRecordView() for modules. */
    protected function removePanelsFromRecordView(string $module, array $panelNames): void
    {
        $viewdefs = $this->loadView($module, 'record');
        if ($viewdefs === null) {
            return;
        }
        $panels = $viewdefs['base']['view']['record']['panels'];
        $newPanels = [];

        foreach ($panels as $p) {
            if (!in_array($p['name'], $panelNames, true)) {
                $newPanels[] = $p;
            }
        }

        if (count($newPanels) < count($panels)) {
            $viewdefs['base']['view']['record']['panels'] = $newPanels;
            $this->deployView($module, 'record', $viewdefs);
        }
    }

    /**
     * Remove named fields from the list view, whatever they are called. removeErpFieldsFromListView() below matches on the `erp_` prefix alone. (decision 693)
     * @param array $fieldNames
     */
    protected function removeFieldsFromListView(string $module, array $fieldNames): void
    {
        $this->removeNamedFieldsFromSinglePanelView($module, 'list', $fieldNames);
    }

    /** Same, for the selection list (the drawer a relate field opens). */
    protected function removeFieldsFromSelectionList(string $module, array $fieldNames): void
    {
        $this->removeNamedFieldsFromSinglePanelView($module, 'selection-list', $fieldNames);
    }

    private function removeNamedFieldsFromSinglePanelView(string $module, string $view, array $fieldNames): void
    {
        $namesToRemove = $this->collectFieldNames($fieldNames);
        if (empty($namesToRemove)) {
            return;
        }

        $viewdefs = $this->loadView($module, $view);
        if ($viewdefs === null || !isset($viewdefs['base']['view'][$view]['panels'][0]['fields'])) {
            return;
        }

        $existingFields = $viewdefs['base']['view'][$view]['panels'][0]['fields'];
        $newFields = [];

        foreach ($existingFields as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (!in_array($name, $namesToRemove, true)) {
                $newFields[] = $field;
            }
        }

        if (count($newFields) < count($existingFields)) {
            $viewdefs['base']['view'][$view]['panels'][0]['fields'] = array_values($newFields);
            $this->deployView($module, $view, $viewdefs);
        }
    }

    protected function removeErpFieldsFromListView(string $module): void
    {
        $viewdefs = $this->loadView($module, 'list');
        if ($viewdefs === null) {
            return;
        }
        $existingFields = $viewdefs['base']['view']['list']['panels'][0]['fields'];
        $newFields = [];

        foreach ($existingFields as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (!$this->isErpField($name)) {
                $newFields[] = $field;
            }
        }

        if (count($newFields) < count($existingFields)) {
            $viewdefs['base']['view']['list']['panels'][0]['fields'] = array_values($newFields);
            $this->deployView($module, 'list', $viewdefs);
        }
    }

    protected function removeErpFieldsFromSelectionList(string $module): void
    {
        $viewdefs = $this->loadView($module, 'selection-list');
        if ($viewdefs === null) {
            return;
        }
        $existingFields = $viewdefs['base']['view']['selection-list']['panels'][0]['fields'];
        $newFields = [];

        foreach ($existingFields as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (!$this->isErpField($name)) {
                $newFields[] = $field;
            }
        }

        if (count($newFields) < count($existingFields)) {
            $viewdefs['base']['view']['selection-list']['panels'][0]['fields'] = array_values($newFields);
            $this->deployView($module, 'selection-list', $viewdefs);
        }
    }

    protected function removeErpFieldsFromSubpanelUnguarded(string $module, string $subpanel, array $fieldDefs): void
    {
        // G26: callers pass field definitions (['name' => 'x']), not names. (G148)
        $fieldNamesToRemove = $this->collectFieldNames($fieldNamesToRemove ?? []);
        $target = $this->loadSubpanelView($module, $subpanel);
        if ($target === null) {
            return;
        }
        $viewdefs = $target['defs'];

        if (!isset($viewdefs['panels'][0]['fields'])) {
            return;
        }

        $fieldNamesToRemove = [];
        foreach ($fieldDefs as $fieldDef) {
            $name = is_array($fieldDef) ? ($fieldDef['name'] ?? '') : $fieldDef;
            if ($this->isErpField($name)) {
                $fieldNamesToRemove[] = $name;
            }
        }

        if (empty($fieldNamesToRemove)) {
            return;
        }

        $existingFields = $viewdefs['panels'][0]['fields'];
        $filteredFields = [];

        foreach ($existingFields as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (!in_array($name, $fieldNamesToRemove, true)) {
                $filteredFields[] = $field;
            }
        }

        if (count($filteredFields) < count($existingFields)) {
            $viewdefs['panels'][0]['fields'] = array_values($filteredFields);
            $this->deploySubpanelView($target, $viewdefs);
        }
    }

    protected function removeFieldsFromDataGroupListViewUnguarded(string $module, array $fieldsToRemove): void
    {
        // G26: callers pass field definitions (['name' => 'x']), not names. (G148)
        $fieldNamesToRemove = $this->collectFieldNames($fieldNamesToRemove ?? []);
        $viewdefs = $this->loadView($module, 'quote-data-group-list');
        if ($viewdefs === null) {
            return;
        }
        $existingFields = $viewdefs['base']['view']['quote-data-group-list']['panels'][0]['fields'];
        $fieldNamesToRemove = array_column($fieldsToRemove, 'name');
        $newFields = [];

        foreach ($existingFields as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (!in_array($name, $fieldNamesToRemove, true)) {
                $newFields[] = $field;
            }
        }

        if (count($newFields) < count($existingFields)) {
            $viewdefs['base']['view']['quote-data-group-list']['panels'][0]['fields'] = array_values($newFields);
            $this->deployView($module, 'quote-data-group-list', $viewdefs);
        }
    }

    protected function isErpField(string $fieldName): bool
    {
        return (strpos($fieldName, 'erp_') === 0);
    }

    // Row-level action-menu entries (view.meta.selection.actions, rendered by addMultiSelectionAction.
    protected function addRowActionsToDataGroupListView(string $module, array $actionsToAdd): void
    {
        $viewdefs = $this->loadView($module, 'quote-data-group-list');
        if ($viewdefs === null) {
            return;
        }
        $existingActions =& $viewdefs['base']['view']['quote-data-group-list']['selection']['actions'];

        if (!is_array($existingActions)) {
            $existingActions = [];
        }

        $existingNames = array_column($existingActions, 'name');
        foreach ($actionsToAdd as $action) {
            if (!in_array($action['name'], $existingNames, true)) {
                $existingActions[] = $action;
                $existingNames[] = $action['name'];
            }
        }

        $this->deployView($module, 'quote-data-group-list', $viewdefs);
    }

    protected function removeRowActionsFromDataGroupListView(string $module, array $actionNamesToRemove): void
    {
        $viewdefs = $this->loadView($module, 'quote-data-group-list');
        if ($viewdefs === null) {
            return;
        }
        $existingActions = $viewdefs['base']['view']['quote-data-group-list']['selection']['actions'] ?? [];
        $newActions = [];

        foreach ($existingActions as $action) {
            if (!in_array($action['name'] ?? '', $actionNamesToRemove, true)) {
                $newActions[] = $action;
            }
        }

        if (count($newActions) < count($existingActions)) {
            $viewdefs['base']['view']['quote-data-group-list']['selection']['actions'] = array_values($newActions);
            $this->deployView($module, 'quote-data-group-list', $viewdefs);
        }
    }

    // ------------------------------------------------------------------------- Viewdef access.

    /** G718 (1.1.162) — an install reads back what it just wrote, not php's compiled copy of the file before it. */
    protected static function forgetCompiledViewdef(string $module, string $view, bool $isLayout = false): void
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        $type = $isLayout ? 'layout' : 'view';
        @opcache_invalidate("custom/modules/{$module}/clients/base/{$type}s/{$view}/{$view}.php", true);
    }

    /**
     * The deployed viewdef for $view (the tenant's custom copy, else stock), or null when the module has no such view.
     */
    private function loadView(string $module, string $view): ?array
    {
        self::forgetCompiledViewdef($module, $view);
        $defs = (new ViewdefManager())->loadViewdef('base', $module, $view);
        if (empty($defs)) {
            return null;
        }

        return ['base' => ['view' => [$view => $defs]]];
    }

    private function deployView(string $module, string $view, array $viewdefs): void
    {
        $manager = new ViewdefManager();
        $defs = $viewdefs['base']['view'][$view];
        $manager->saveViewdef($defs, $module, 'base', $view);
        self::forgetCompiledViewdef($module, $view);

        // The parser also deleted a Studio working copy here.

        // Core keeps the product catalog's drawer panels in step with the ProductTemplates record view on every deploy; keep doing that.
        if ($module === 'ProductTemplates' && $view === 'record' && isset($defs['panels'])) {
            self::forgetCompiledViewdef($module, 'product-catalog-dashlet-drawer-record');
            $drawer = $manager->loadViewdef('base', $module, 'product-catalog-dashlet-drawer-record', true);
            if (!empty($drawer)) {
                $drawer['panels'] = $defs['panels'];
                $manager->saveViewdef($drawer, $module, 'base', 'product-catalog-dashlet-drawer-record');
                self::forgetCompiledViewdef($module, 'product-catalog-dashlet-drawer-record');
            }
        }

        MetaDataFiles::clearModuleClientCache($module, 'view');
        MetaDataFiles::clearModuleClientCache($module, 'layout');
        include_once 'include/TemplateHandler/TemplateHandler.php';
        TemplateHandler::clearCache($module);
    }

    /**
     * Resolve the subpanel $link on $module the way Sugar's Studio does: the view lives on the related module as subpanel-for-<module>-<link>.
     * @return array{module:
     */
    private function loadSubpanelView(string $module, string $link): ?array
    {
        $bean = BeanFactory::newBean($module);
        if (empty($bean) || !$bean->load_relationship($link)) {
            return null;
        }
        $related = $bean->$link->getRelatedModuleName();
        if (empty($related)) {
            return null;
        }

        $manager = new ViewdefManager();
        $name = strtolower("subpanel-for-{$module}-{$link}");
        $defs = [];
        foreach ($this->subpanelViewCandidates($module, $link, $name) as $candidate) {
            self::forgetCompiledViewdef($related, $candidate);
            $defs = $manager->loadViewdef('base', $related, $candidate);
            if (!empty($defs)) {
                break;
            }
        }
        if (empty($defs)) {
            return null;
        }

        return ['module' => $module, 'link' => $link, 'related' => $related, 'name' => $name, 'defs' => $defs];
    }

    /**
     * View names to try, in the parser's order: an override_subpanel_list_view entry for this link (stock layout or its compiled extension).
     */
    private function subpanelViewCandidates(string $module, string $link, string $name): array
    {
        // Stock components first, then the compiled extension's, the order the parser saw them in when it included both files into one $viewdefs.
        $components = [];
        foreach ([
            "modules/{$module}/clients/base/layouts/subpanels/subpanels.php",
            "custom/modules/{$module}/Ext/clients/base/layouts/subpanels/subpanels.ext.php",
        ] as $file) {
            $defs = $this->includeViewdefsFile($file);
            foreach ($defs[$module]['base']['layout']['subpanels']['components'] ?? [] as $component) {
                $components[] = $component;
            }
        }

        $explicit = null;
        $contextual = null;
        foreach ($components as $component) {
            $override = $component['override_subpanel_list_view'] ?? null;
            if (is_array($override) && ($override['link'] ?? null) === $link && !empty($override['view'])) {
                $explicit = $override['view'];
                break;
            }
            if (is_string($override) && ($component['context']['link'] ?? null) === $link) {
                $contextual = $contextual ?? $override;
            }
        }

        // A plain loop: the cloud package scanner denies array_filter().
        $candidates = [];
        foreach ([$explicit, $name, $contextual, 'subpanel-list'] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && !in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /** A viewdef file's $viewdefs, included in a scope of its own. */
    private function includeViewdefsFile(string $viewdefsFile): array
    {
        if (!file_exists($viewdefsFile)) {
            return [];
        }
        $viewdefs = [];
        include $viewdefsFile;

        return is_array($viewdefs) ? $viewdefs : [];
    }

    /** Write the subpanel view under the related module, as Studio's subpanel deploy does. */
    private function deploySubpanelView(array $target, array $defs): void
    {
        $defs['type'] = 'subpanel-list';
        (new ViewdefManager())->saveViewdef($defs, $target['related'], 'base', $target['name']);
        self::forgetCompiledViewdef($target['related'], $target['name']);

        MetaDataFiles::clearModuleClientCache($target['related'], 'view');
        MetaDataFiles::clearModuleClientCache($target['module'], 'layout');
    }

    // ------------------------------------------------------------------------- Layout components (e.g. clients/base/layouts/extra-info/extra-info.php).

    protected function getDeployedLayout(string $module, string $layoutName): array
    {
        self::forgetCompiledViewdef($module, $layoutName, true);
        return (new ViewdefManager())->loadViewdef('base', $module, $layoutName, false, true);
    }

    protected function deployLayout(string $module, string $layoutName, array $layout): void
    {
        (new ViewdefManager())->saveViewdef($layout, $module, 'base', $layoutName, true);
        self::forgetCompiledViewdef($module, $layoutName, true);

        MetaDataFiles::clearModuleClientCache($module, 'layout');
        include_once 'include/TemplateHandler/TemplateHandler.php';
        TemplateHandler::clearCache($module);
    }

    /**
     * Inserts $component into the layout's 'components' list, right before the first entry matching $beforeMatch (appended at the end if no match is found).
     */
    protected function addLayoutComponentBefore(string $module, string $layoutName, array $component, array $beforeMatch): void
    {
        $layout = $this->getDeployedLayout($module, $layoutName);
        $components = $layout['components'] ?? [];
        $insertIndex = null;

        foreach ($components as $index => $existing) {
            if ($this->componentMatches($existing, $beforeMatch)) {
                $insertIndex = $index;
                break;
            }
        }

        if ($insertIndex !== null && $insertIndex > 0 && $this->componentMatches($components[$insertIndex - 1], $component)) {
            return;
        }

        if ($insertIndex !== null) {
            array_splice($components, $insertIndex, 0, [$component]);
        } else {
            $components[] = $component;
        }

        $layout['components'] = $components;
        $this->deployLayout($module, $layoutName, $layout);
    }

    /** Appends $component to the end of the layout's 'components' list. */
    protected function addLayoutComponentAtEnd(string $module, string $layoutName, array $component): void
    {
        $layout = $this->getDeployedLayout($module, $layoutName);
        $components = $layout['components'] ?? [];

        if (!empty($components) && $this->componentMatches(end($components), $component)) {
            return;
        }

        $components[] = $component;
        $layout['components'] = $components;
        $this->deployLayout($module, $layoutName, $layout);
    }

    protected function removeLayoutComponents(string $module, string $layoutName, array $match): void
    {
        $layout = $this->getDeployedLayout($module, $layoutName);
        $components = $layout['components'] ?? [];
        $newComponents = [];

        foreach ($components as $component) {
            if (!$this->componentMatches($component, $match)) {
                $newComponents[] = $component;
            }
        }

        if (count($newComponents) < count($components)) {
            $layout['components'] = $newComponents;
            $this->deployLayout($module, $layoutName, $layout);
        }
    }

    private function componentMatches(array $component, array $match): bool
    {
        foreach ($match as $key => $value) {
            if (!array_key_exists($key, $component) || $component[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /** Add a row to a Quote's grand totals footer, before a named anchor row. */
    protected function addFieldsToTotalsFooterBefore(
        string $module,
        array $fieldsToAdd,
        string $beforeFieldName,
        string $view = 'quote-data-grand-totals-footer'
    ): void {
        $viewdefs = $this->loadView($module, $view);
        if ($viewdefs === null) {
            return;
        }
        if (!isset($viewdefs['base']['view'][$view]['panels'][0]['fields'])) {
            return;
        }
        $existing =& $viewdefs['base']['view'][$view]['panels'][0]['fields'];
        $present = $this->collectFieldNames($existing);

        $pending = array();
        foreach ($fieldsToAdd as $field) {
            if (!in_array($field['name'] ?? '', $present, true)) {
                $pending[] = $field;
            }
        }
        if (!$pending) {
            return;
        }

        $out = array();
        $placed = false;
        foreach ($existing as $f) {
            $name = is_array($f) ? ($f['name'] ?? '') : $f;
            if (!$placed && $name === $beforeFieldName) {
                foreach ($pending as $row) {
                    $out[] = $row;
                }
                $placed = true;
            }
            $out[] = $f;
        }
        if (!$placed) {
            foreach ($pending as $row) {
                $out[] = $row;
            }
        }
        $existing = $out;

        $this->deployView($module, $view, $viewdefs);
    }

    /** Remove rows this package added to a Quote totals footer. */
    protected function removeFieldsFromTotalsFooterUnguarded(
        string $module,
        array $fieldNamesToRemove,
        string $view = 'quote-data-grand-totals-footer'
    ): void {
        // G26: callers pass field definitions (['name' => 'x']), not names. (G148)
        $fieldNamesToRemove = $this->collectFieldNames($fieldNamesToRemove ?? []);
        $viewdefs = $this->loadView($module, $view);
        if ($viewdefs === null) {
            return;
        }
        if (!isset($viewdefs['base']['view'][$view]['panels'][0]['fields'])) {
            return;
        }
        $existing =& $viewdefs['base']['view'][$view]['panels'][0]['fields'];
        $kept = array();
        $changed = false;
        foreach ($existing as $f) {
            $name = is_array($f) ? ($f['name'] ?? '') : $f;
            if (in_array($name, $fieldNamesToRemove, true)) {
                $changed = true;
                continue;
            }
            $kept[] = $f;
        }
        if (!$changed) {
            return;
        }
        $existing = $kept;
        $this->deployView($module, $view, $viewdefs);
    }
}
