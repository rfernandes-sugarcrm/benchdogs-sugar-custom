<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

abstract class BaseErpLayout
{
    const ERP_PANEL_NAME = 'LBL_RECORDVIEW_PANEL_ERP';

    protected bool $replace;

    public function __construct(bool $replace = false)
    {
        // MLP019 (vault L-0081): nothing in this class may name a ModuleBuilder
        // metadata parser class, not even as a class_exists() string. SugarCloud's
        // Rector scan resolves every class a package names through the tenant's
        // autoloader, which has no rule for modules/ModuleBuilder/parsers/views/,
        // so on an affected tenant it refuses the package at the scan, or - when
        // the scan passes - the same resolution fails mid-install and the emergency
        // uninstall dies on it too, leaving a half-applied package (ERP-Epicor
        // rc8-rc11.2, and rc15/rc15.1 on both QA tenants on 2026-09-13). Viewdefs
        // are read and written with ViewdefManager, which is namespaced and
        // autoloads through src/.
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
            array_splice($panels, $existingIndex, 1);
        }

        $panels[] = $panel;

        $this->deployView($module, 'record', $viewdefs);
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
            array_splice($panels, $existingIndex, 1);
        }

        $insertIndex = array_search($beforePanel, array_column($panels, 'name'));

        if ($insertIndex !== false) {
            array_splice($panels, $insertIndex, 0, [$panel]);
        } else {
            $panels[] = $panel;
        }

        $this->deployView($module, 'record', $viewdefs);
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
            // A caller that only knows how to LOCATE a panel silently does
            // nothing on an instance where that panel no longer exists, and
            // a record view can genuinely lose one: deploying the whole view
            // through Studio's metadata parser (which several installers
            // do, to move a panel) returns a normalised viewdef with the
            // hidden panel dropped. The fields never get placed, so Sidecar
            // never requests them, so anything reading them off the model -
            // erp-id-link's href, for one - degrades with no error anywhere.
            // Reinstalling could not repair it, because this returned here.
            //
            // Opt-in: a caller that passes the panel definition it wants gets
            // the panel created; every existing caller passes nothing and
            // keeps the previous locate-or-give-up behaviour.
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
     * 🛑 RECONCILE A FIELD DEFINITION INSIDE A PANEL THIS PACKAGE OWNS (🔒 774,
     * one level down from the button case).
     *
     * addPanelToRecordViewBefore()/addPanelToRecordView() RETURN EARLY when
     * the panel already exists and this is not a replace build, and
     * addFieldsToRecordView() dedupes by name. Between them, **no change to a
     * field's definition inside an already-deployed panel can ever reach an
     * upgraded tenant** - the same silent failure that made the 1.1.39
     * `related_fields` attempt "inert" on buttons, and the reason it was
     * deleted rather than fixed.
     *
     * That matters for more than cosmetics: `related_fields` on a PANEL FIELD
     * is the only supported way to make Sidecar FETCH a field the view does
     * not render (sidecar/src/view/view.js:433 getFieldNames() plucks
     * 'related_fields' from `this.meta.panels` and from nothing else), so a
     * guard's input arrives on the model through a key added here.
     *
     * Reconcile, do not remove-and-re-add: the packaged keys win, any other
     * key an admin put on the deployed definition is preserved, and the
     * field keeps its position in the panel. A bare-string entry is promoted
     * to an array so it can carry keys at all. Scoped to the panel named by
     * $targetPanelProperties (L-0009: a placement an admin made on another
     * panel is theirs to keep).
     *
     * @param array $fields Entries may be arrays with a 'name' or bare strings.
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

    /**
     * The stock attributes of the record view's hidden ("Show more") panel.
     *
     * Fields put here are not there to be read - they are there to be
     * FETCHED. Sidecar only requests fields that appear somewhere in the
     * view's own metadata, so a field on no panel is never loaded onto the
     * model, and anything reading it there silently falls back. Declaring it
     * hidden fetches it without putting a raw value on the page.
     *
     * ⚠️ ON THE QUOTES RECORD VIEW THIS IS NOT INVISIBLE. panel_hidden is
     * rendered - collapsed behind "Show more", and, once the view is in tabs
     * mode, grouped under the last newTab panel, which on Sugar Sell's quote
     * is "Quote Settings". A field a seller must never see goes on no panel
     * at all and is fetched with reconcileFieldsInRecordViewPanel() above
     * instead (🔒 1413).
     *
     * Pass this as addFieldsToRecordView()'s $createPanelWhenMissing so the
     * panel is recreated on an instance that lost it, rather than the call
     * quietly doing nothing.
     */
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

    /**
     * Appends field names to a nested collection field's own 'fields' list
     * inside the record view (e.g. the 'bundles' panel field's nested
     * 'product_bundle_items' collection, which the client fetches with an
     * explicit sub-field list baked into this same viewdefs file - a
     * collection field's data is NOT included by the generic record fetch
     * unless its name is listed here, regardless of vardefs/other viewdefs).
     * Recursive/path-agnostic: finds the first field entry anywhere in
     * 'panels' whose 'name' matches $collectionFieldName, wherever it's
     * nested, and appends to its 'fields' array (idempotent by name).
     */
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

    /**
     * Adds record-view dependency rules (Sidecar's real field-visibility/
     * required/value mechanism - a top-level 'dependencies' array of
     * hooks/triggerFields/actions, NOT a per-field 'depends_on' property,
     * which nothing in the client ever reads). Idempotent by exact rule match.
     */
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
     * Remove fields from ONE panel, matched the same way addFieldsToRecordView
     * matches its target.
     *
     * removeFieldsFromRecordView (below) sweeps every panel, which is right for
     * uninstall and wrong for a migration: a name this package once pinned into
     * the header may be somewhere an admin deliberately placed it now, and a
     * blanket removal on every upgrade would keep silently deleting their work.
     * Scope the removal when the intent is "drop our old header entry", not
     * "drop this field everywhere".
     */
    protected function removeFieldsFromRecordViewPanelUnguarded(string $module, array $targetPanelProperties, array $fieldNamesToRemove): void
    {
        // G26: callers pass field DEFINITIONS (['name' => 'x']), not names.
        // Every one of these five removers then compared a string field name
        // against a list of arrays with strict in_array(), which is ALWAYS
        // false -- so nothing was ever removed, the "did anything change?"
        // guard short-circuited, and deployView() was never reached. The
        // removal has never once worked, silently, on any tenant.
        //
        // Measured live 2026-09-20 on Bench: LBL_SYSPRO_TELEPHONE_EXT,
        // LBL_SYSPRO_SYNC_MESSAGE_C and LBL_SYSPRO_COMPANY_C all render as
        // raw label keys on the Contacts record view, while the SAME field
        // was genuinely gone from the list view and the selection list --
        // because those two removers DO normalise. That split is the tell.
        // G148b: a caller may pass null — BenchDogs-Ext's QLI-columns step does,
        // and the deployed log caught it:
        //   collectFieldNames(): Argument #1 ($fields) must be of type array,
        //   null given, called in .../BaseErpLayout.php on line 1222
        // Before G26 a null reached the loop and the earlier empty()/count
        // guards absorbed it; normalising at the TOP moved the null forward
        // into a typed parameter and turned a survivable no-op into a fatal.
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
        // G26: callers pass field DEFINITIONS (['name' => 'x']), not names.
        // Every one of these five removers then compared a string field name
        // against a list of arrays with strict in_array(), which is ALWAYS
        // false -- so nothing was ever removed, the "did anything change?"
        // guard short-circuited, and deployView() was never reached. The
        // removal has never once worked, silently, on any tenant.
        //
        // Measured live 2026-09-20 on Bench: LBL_SYSPRO_TELEPHONE_EXT,
        // LBL_SYSPRO_SYNC_MESSAGE_C and LBL_SYSPRO_COMPANY_C all render as
        // raw label keys on the Contacts record view, while the SAME field
        // was genuinely gone from the list view and the selection list --
        // because those two removers DO normalise. That split is the tell.
        // G148b: a caller may pass null — BenchDogs-Ext's QLI-columns step does,
        // and the deployed log caught it:
        //   collectFieldNames(): Argument #1 ($fields) must be of type array,
        //   null given, called in .../BaseErpLayout.php on line 1222
        // Before G26 a null reached the loop and the earlier empty()/count
        // guards absorbed it; normalising at the TOP moved the null forward
        // into a typed parameter and turned a survivable no-op into a fatal.
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

        // 🛑 ADD IS NOT ENOUGH — AN EXISTING BUTTON MUST BE RECONCILED (🔒 774).
        //
        // This loop used to skip any button whose name was already deployed and
        // return early when nothing was new. The consequence was silent and
        // general: **no change to an already-deployed button definition could
        // ever reach an upgraded tenant.** 1.1.39 shipped a `related_fields`
        // key on create_erp_order_button; the zip carried it, the served
        // metadata never had it, and 1.1.38's definition survived the upgrade.
        // The key was then judged "inert" and deleted — treating the symptom,
        // while the installer kept dropping every future button fix on exactly
        // the tenants that already had the button.
        //
        // Reconcile instead: the PACKAGED keys win, and any other key on the
        // deployed definition is preserved, so an admin's own additions survive
        // while the keys this package owns are brought up to date. Deploy when
        // either something was added OR something changed — the old
        // `empty($newButtons)` early return is exactly what swallowed the fix.
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

    /**
     * Merge a managed field list into an existing one, in place.
     *
     * Shared by the list view, the selection list and subpanels, which had three
     * byte-identical copies of this and therefore three copies of the same bug.
     *
     * REPLACE mode drops the fields it is about to add as well as the ERP ones.
     * Dropping only ERP fields is not enough, and the gap is silent:
     * isErpField() matches the erp_ prefix alone, so a managed field WITHOUT
     * that prefix survived as though it belonged to the customer and was then
     * appended a second time - another copy on every install. The Quotes list
     * view reached 272 entries for 19 distinct names this way (quote_num 22
     * times, epicor_deeplink_url 17), because its managed list is mostly
     * unprefixed stock fields. Accounts never showed it only because its managed
     * fields happen to be erp_-prefixed. Keying on the names actually being
     * added makes replace mode idempotent whatever they are called, and
     * self-heals an instance that already accumulated copies.
     *
     * APPEND mode is unchanged in intent - add only what is missing - but it now
     * normalises entries before collecting names, because array_column() skips a
     * bare string entry, so a field already present in string form read as absent
     * and was appended again.
     *
     * @param array $existingFields Passed by reference; rewritten in place.
     * @param array $fieldsToAdd The fields this layout manages.
     */
    /**
     * Field names from a viewdef field list, accepting either shape.
     *
     * A private method rather than a local closure on purpose: ModuleScanner
     * rejects calling a closure held in a variable as a "dynamically-named
     * function call", which fails the package at the Scanning Package step.
     *
     * @param array $fields Entries may be arrays with a 'name' or bare strings.
     * @return string[]
     */

    /**
     * 🛑 G148: THESE FIVE REMOVERS HAD NEVER RUN, AND MAKING THEM RUN BROKE AN INSTALL.
     *
     * Until G26 they compared a string field name against a list of
     * ['name' => ...] arrays with strict in_array(), which is always false, so
     * every one of them returned without reaching deployView(). Fixing that
     * comparison turned FIVE dormant write paths live in a single release, and
     * ERP-Epicor 1.1.69 fataled at install step 13/19 — inside post_execute,
     * right after "Rebuilding TableDictionary", which is where these run.
     * Artifact diff: 1,212 files compared, only manifest.php and this file
     * differed from the 1.1.67 that installs cleanly.
     *
     * A layout CLEANUP must never abort an install. Each remover is now called
     * through a guard that catches Throwable, logs WHICH remover failed and on
     * what module/view, and lets the install continue. The other four still do
     * their work; the install still completes; and the log names the culprit
     * instead of leaving a dead staged row and a step number to guess from.
     *
     * fatal() deliberately — this line is the diagnosis, and it must survive
     * whatever log level the tenant is set to.
     */

    protected function removeFieldsFromRecordViewPanel(string $module, array $targetPanelProperties, array $fieldNamesToRemove)
    {
        // G148: statically-named call + inline try/catch. Factoring this guard
        // into a helper that takes a callable and invokes it is a
        // "dynamically-named function call", which ModuleScanner REJECTS -
        // 1.1.71 was refused at Scanning Package on exactly that line. So the
        // guard is written out once per remover, deliberately, not factored.
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
        // G148: statically-named call + inline try/catch. Factoring this guard
        // into a helper that takes a callable and invokes it is a
        // "dynamically-named function call", which ModuleScanner REJECTS -
        // 1.1.71 was refused at Scanning Package on exactly that line. So the
        // guard is written out once per remover, deliberately, not factored.
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
        // G148: statically-named call + inline try/catch. Factoring this guard
        // into a helper that takes a callable and invokes it is a
        // "dynamically-named function call", which ModuleScanner REJECTS -
        // 1.1.71 was refused at Scanning Package on exactly that line. So the
        // guard is written out once per remover, deliberately, not factored.
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
        // G148: statically-named call + inline try/catch. Factoring this guard
        // into a helper that takes a callable and invokes it is a
        // "dynamically-named function call", which ModuleScanner REJECTS -
        // 1.1.71 was refused at Scanning Package on exactly that line. So the
        // guard is written out once per remover, deliberately, not factored.
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
        // G148: statically-named call + inline try/catch. Factoring this guard
        // into a helper that takes a callable and invokes it is a
        // "dynamically-named function call", which ModuleScanner REJECTS -
        // 1.1.71 was refused at Scanning Package on exactly that line. So the
        // guard is written out once per remover, deliberately, not factored.
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

    // Same shape as addFieldsToDataGroupListView, but inserts before a named
    // field instead of appending - for a column that needs to render first
    // (e.g. left of line_num), rather than at the end of the row. A
    // leftColumns/JS-controller approach was tried first for this (icons
    // next to the checkbox) and abandoned: CSS in row.hbs only styles the
    // first child of the left-column-buttons wrapper, so anything beyond
    // that one stock entry renders visually broken/hidden - confirmed live
    // 2026-07-16. A real column, first in the row, is the simpler fix.
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

    /**
     * Re-label AND re-order columns that are ALREADY on the quoted-lines grid
     * (🔒 1435 / G175). The two helpers above are add-if-absent by design, so
     * neither can touch a column that exists — which is exactly what renaming
     * "Subtotal" to "Extended Price" and moving it next to its siblings needs.
     *
     * 🛑 MERGES, NEVER REPLACES. Decision 803, and the Bench Dogs rc44
     * episode: a grid viewdef written wholesale deletes the columns other
     * packages own. Only the named columns are touched; every other entry
     * keeps its position relative to the untouched ones, and a name that is
     * not on the grid is SKIPPED rather than created (creation stays with
     * addFieldsToDataGroupListView, which owns the full field definition).
     */
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
     * 🔒 1444 — set an EXISTING record-view field's display TYPE.
     *
     * Every other field helper on this class is add-if-absent, which is right
     * for a field we ship and wrong for this: `qty_in_stock` is Sugar's own
     * field, already on the ProductTemplates record view, and the owner wants
     * the number it already shows to read "-50 (back ordered)". Adding it
     * again would be a no-op on every tenant that already has it — the exact
     * trap recorded as "MLP layout helpers are add-if-absent: editing a
     * shipped field's type/label never reaches an upgraded tenant".
     *
     * Searches every panel, because a stock field's panel name is not ours to
     * assume and Sugar moves fields between panels across versions.
     */
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
     * 🔒 1434 (G121 ruling 1) — TELL THE QUOTE'S PDF MENU WHICH TEMPLATES IT
     * MUST NOT OFFER, BY ID.
     *
     * Owner, verbatim: *"IW ant to have PDF for invice order and quote but when
     * you are on quote i dont want to see inthemenu PDF for incoive udpate the
     * gap"*. So the invoice templates stay PUBLISHED — the owner wants all
     * three documents to exist — and simply stop appearing on a quote.
     *
     * 🛑 WHY THE IDS TRAVEL ON THE VIEWDEF AND NOT IN A CONFIG SETTING. The
     * exclusion has to key on TEMPLATE ID (an admin renaming a template must
     * not silently re-expose it, which is why 🔒 1434 rejected the name-match
     * idea), and ids differ per tenant, so they must be RESOLVED on the tenant
     * at install. A resolved list then has to reach a Sidecar field. The record
     * viewdef is already served to the client as metadata and is already
     * written by this installer, so putting the ids on the pdfaction entry
     * needs no new API, no client-side config plumbing and no extra request.
     *
     * 🛑 AND IT REFUSES QUIETLY-DOING-NOTHING. The Download PDF submenu is not
     * built from `main_dropdown` buttons at all — it is a `pdfaction` ROWACTION
     * FIELD whose template iterates a PdfManager bean collection, which is why
     * the runtime prune in Quotes' record.js cannot reach it and says so in its
     * own docblock. If no pdfaction entry is found, this logs at fatal (so
     * package_install.log carries it) rather than returning success over a
     * no-op, which is the failure mode this whole area keeps producing.
     *
     * @param string $module The module whose record view carries the menu.
     * @param string[] $templateIds PdfManager ids to hide. An EMPTY array is a
     *        meaningful value: it clears a previous exclusion.
     * @param string $defKey The viewdef key the field reads.
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
            // Never a silent success. A menu that still offers an invoice on a
            // quote is the defect; "the installer ran" is not evidence.
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
     * Pure, recursive half of setPdfActionTemplateExclusions(), so the walk can
     * be run over a real served viewdef in a test without a Sugar instance.
     *
     * Recursion is required rather than tidy: pdfaction entries live inside
     * `main_dropdown`'s own `buttons` array, one level down from the top-level
     * button list, and a flat loop over `buttons` finds nothing at all.
     *
     * @param array $nodes  A viewdef button list, by reference.
     * @param array $templateIds
     * @param string $defKey
     * @param int $applied Count of entries changed, by reference.
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

    /**
     * Pure half of setFieldTypeInRecordView, so the behaviour is testable
     * without a Sugar instance.
     *
     * A field entry is legally either a bare string or an array with 'name'.
     * A string entry must be PROMOTED to an array to carry a type — dropping
     * that case is how this silently does nothing on a stock viewdef, which
     * is where `qty_in_stock` actually lives. Every other key on an array
     * entry is preserved: they are not ours to discard.
     */
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
     * A grid entry's field name, whichever of the two legal viewdef shapes it
     * is (a bare string, or an array with 'name').
     *
     * 🛑 A NAMED METHOD, NOT A CLOSURE, and the linter is why: ModuleScanner
     * refuses a call through a variable ($fn()) anywhere in a packaged file,
     * and ONE occurrence rejects the whole upload. The first cut of this
     * helper used a static closure and MLP017 blocked the 1.1.89 build at the
     * pre-flight gate - before it reached a tenant, which is the entire point
     * of running the gate before handing anything over.
     */
    private static function gridColumnName($entry): string
    {
        return is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
    }

    /**
     * The pure half of orderAndRelabelDataGroupListColumns(), kept static and
     * side-effect free so a test can run it over the viewdef a TENANT actually
     * serves rather than over a hand-written idea of one.
     *
     * Idempotent: running it over its own output changes nothing.
     *
     * @param array $fields  the panel's field entries (strings or arrays)
     * @param array $spec    ordered [['name' => ..., 'label' => ...], ...]
     * @param string $afterField  the column the group is placed directly after
     * @return array the new field list
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
                // A string entry becomes an array so it can carry a label;
                // an array entry keeps every key it already had (width,
                // css_class, type, readonly - none of them ours to drop).
                $normalised = is_array($entry) ? $entry : ['name' => $wanted['name']];
                // A spec entry with NO 'label' key means "move this column,
                // leave its label alone". Required as soon as we reorder a
                // column another package contributed (erp_break_select is
                // ERP-Epicor's): hard-coding its label key here would make
                // core the second owner of a string it does not ship, and
                // assigning null would blank the header outright.
                if (array_key_exists('label', $wanted)) {
                    $normalised['label'] = $wanted['label'];
                }
                // 🛑 A SPEC ENTRY MAY ALSO CARRY A DISPLAY TYPE, and this is
                // the only route that reaches an EXISTING grid column.
                // addFieldsToDataGroupListView is add-if-absent, so re-adding
                // erp_stock_availability with a new 'type' was a silent no-op
                // on every tenant that already had the column - i.e. all of
                // them. The served viewdef still read type "relate" after
                // 1.1.92 installed cleanly, which is exactly the documented
                // trap ("MLP layout helpers are add-if-absent: editing a
                // shipped field's type/label never reaches an upgraded
                // tenant") that setFieldTypeInRecordView was written for on
                // the RECORD view. The grid has the same hole.
                if (array_key_exists('type', $wanted)) {
                    $normalised['type'] = $wanted['type'];
                }
                if (isset($wanted['labelModule'])) {
                    $normalised['labelModule'] = $wanted['labelModule'];
                }
                // 🛑 G187 — AND A COLUMN MAY CARRY A LINK TARGET, for the same
                // reason 'type' is here: this is the only path that reaches a
                // column an earlier version already added. erp_product_link
                // names the field holding the record id the figure should link
                // to (the quote grid passes 'product_template_id'), and its
                // ABSENCE is meaningful - the identical field type also renders
                // ProductTemplates' own qty_in_stock, where a link would point
                // at the page the seller is already on. So it is never
                // defaulted, only ever passed explicitly by a spec.
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

        // The anchor may itself be absent (another package removed it): then
        // the group goes to the end rather than to index 0, which would put
        // money columns in front of the line number.
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

    /**
     * Generalized form of removePanelFromRecordView() for modules (e.g.
     * Accounts) that split their ERP data across more than one named panel
     * instead of the single self::ERP_PANEL_NAME panel every other module
     * uses - removes every panel whose name is in $panelNames.
     */
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
     * Remove NAMED fields from the list view, whatever they are called.
     *
     * removeErpFieldsFromListView() below matches on the `erp_` prefix alone,
     * so a managed field without that prefix cannot be retired by it - and
     * "stop adding it" retires nothing, because Module Loader does not run the
     * previous version's uninstall() on an upgrade (decision 693) and the
     * deployed viewdef keeps whatever an earlier version put there for ever.
     * §CW in the small: a placement leaves this package by being OVERWRITTEN.
     *
     * @param array $fieldNames Entries may be arrays with a 'name' or bare strings.
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
        // G26: callers pass field DEFINITIONS (['name' => 'x']), not names.
        // Every one of these five removers then compared a string field name
        // against a list of arrays with strict in_array(), which is ALWAYS
        // false -- so nothing was ever removed, the "did anything change?"
        // guard short-circuited, and deployView() was never reached. The
        // removal has never once worked, silently, on any tenant.
        //
        // Measured live 2026-09-20 on Bench: LBL_SYSPRO_TELEPHONE_EXT,
        // LBL_SYSPRO_SYNC_MESSAGE_C and LBL_SYSPRO_COMPANY_C all render as
        // raw label keys on the Contacts record view, while the SAME field
        // was genuinely gone from the list view and the selection list --
        // because those two removers DO normalise. That split is the tell.
        // G148b: a caller may pass null — BenchDogs-Ext's QLI-columns step does,
        // and the deployed log caught it:
        //   collectFieldNames(): Argument #1 ($fields) must be of type array,
        //   null given, called in .../BaseErpLayout.php on line 1222
        // Before G26 a null reached the loop and the earlier empty()/count
        // guards absorbed it; normalising at the TOP moved the null forward
        // into a typed parameter and turned a survivable no-op into a fatal.
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
        // G26: callers pass field DEFINITIONS (['name' => 'x']), not names.
        // Every one of these five removers then compared a string field name
        // against a list of arrays with strict in_array(), which is ALWAYS
        // false -- so nothing was ever removed, the "did anything change?"
        // guard short-circuited, and deployView() was never reached. The
        // removal has never once worked, silently, on any tenant.
        //
        // Measured live 2026-09-20 on Bench: LBL_SYSPRO_TELEPHONE_EXT,
        // LBL_SYSPRO_SYNC_MESSAGE_C and LBL_SYSPRO_COMPANY_C all render as
        // raw label keys on the Contacts record view, while the SAME field
        // was genuinely gone from the list view and the selection list --
        // because those two removers DO normalise. That split is the tell.
        // G148b: a caller may pass null — BenchDogs-Ext's QLI-columns step does,
        // and the deployed log caught it:
        //   collectFieldNames(): Argument #1 ($fields) must be of type array,
        //   null given, called in .../BaseErpLayout.php on line 1222
        // Before G26 a null reached the loop and the earlier empty()/count
        // guards absorbed it; normalising at the TOP moved the null forward
        // into a typed parameter and turned a survivable no-op into a fatal.
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

    // Row-level action-menu entries (view.meta.selection.actions, rendered
    // by addMultiSelectionAction() - the same mechanism that already
    // produces the stock edit_row_button/delete_row_button entries) rather
    // than a 'fields' viewdefs entry or a leftColumns push: this array is
    // declarative and, unlike leftColumns, doesn't need any JS controller
    // override to render (a leftColumns approach was tried and abandoned -
    // see ProductsLayout.php's install() comment).
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

    // -------------------------------------------------------------------------
    // Viewdef access (MLP019). loadView() returns the same
    // ['base']['view'][<view>] shape the Module Builder parser used to hand every
    // caller, so the manipulation code above is unchanged; deployView() writes the
    // same custom/modules/<Module>/clients/base/views/<view>/<view>.php file and
    // clears the same caches the parser's deploy() did.
    // -------------------------------------------------------------------------

    /**
     * The deployed viewdef for $view (the tenant's custom copy, else stock), or
     * null when the module has no such view - a caller must not write a nearly
     * empty custom file over a view that was never there.
     */
    private function loadView(string $module, string $view): ?array
    {
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

        // The parser also deleted a Studio working copy here. A package cannot:
        // the cloud package scanner denies unlink() (MLP002). A working copy only
        // matters to a Studio session left open mid-edit, not to what renders.

        // Core keeps the product catalog's drawer panels in step with the
        // ProductTemplates record view on every deploy; keep doing that.
        if ($module === 'ProductTemplates' && $view === 'record' && isset($defs['panels'])) {
            $drawer = $manager->loadViewdef('base', $module, 'product-catalog-dashlet-drawer-record', true);
            if (!empty($drawer)) {
                $drawer['panels'] = $defs['panels'];
                $manager->saveViewdef($drawer, $module, 'base', 'product-catalog-dashlet-drawer-record');
            }
        }

        MetaDataFiles::clearModuleClientCache($module, 'view');
        MetaDataFiles::clearModuleClientCache($module, 'layout');
        include_once 'include/TemplateHandler/TemplateHandler.php';
        TemplateHandler::clearCache($module);
    }

    /**
     * Resolve the subpanel $link on $module the way Sugar's Studio does: the
     * view lives on the related module as subpanel-for-<module>-<link>, and
     * until that file exists its starting content is whatever the subpanel
     * renders today - an override the parent's subpanels layout names for this
     * link, then the related module's generic subpanel-list.
     *
     * @return array{module: string, link: string, related: string, name: string, defs: array}|null
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
     * View names to try, in the parser's order: an override_subpanel_list_view
     * entry for this link (stock layout or its compiled extension), our own
     * subpanel-for-* view, a context-link override, then subpanel-list.
     */
    private function subpanelViewCandidates(string $module, string $link, string $name): array
    {
        // Stock components first, then the compiled extension's, the order the
        // parser saw them in when it included both files into one $viewdefs.
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

    /**
     * A viewdef file's $viewdefs, included in a scope of its own: Sugar's
     * layout files run arbitrary code that can overwrite or unset the caller's
     * local variables (a stock subpanels layout did, on the 26.1 repro).
     */
    private function includeViewdefsFile(string $viewdefsFile): array
    {
        if (!file_exists($viewdefsFile)) {
            return [];
        }
        $viewdefs = [];
        include $viewdefsFile;

        return is_array($viewdefs) ? $viewdefs : [];
    }

    /**
     * Write the subpanel view under the related module, as Studio's subpanel
     * deploy does. Studio also writes the parent's override_subpanel_list_view
     * extension (custom/Extension/modules/<Module>/Ext/clients/base/layouts/
     * subpanels/_override<view>.php) and deletes older ones for the same link.
     * A package can do neither at install time - the cloud scanner denies
     * mkdir_recursive(), sugar_file_put_contents(), write_array_to_file(),
     * glob() and unlink() - so the package ships those override files as plain
     * copied files instead, byte-identical to what Studio writes, one per
     * subpanel a layout deploys (pinned by test_layout_dependency_loading).
     */
    private function deploySubpanelView(array $target, array $defs): void
    {
        $defs['type'] = 'subpanel-list';
        (new ViewdefManager())->saveViewdef($defs, $target['related'], 'base', $target['name']);

        MetaDataFiles::clearModuleClientCache($target['related'], 'view');
        MetaDataFiles::clearModuleClientCache($target['module'], 'layout');
    }

    // -------------------------------------------------------------------------
    // Layout components (e.g. clients/base/layouts/extra-info/extra-info.php).
    // Module Builder's metadata parser only understands
    // Studio-managed *views* (record, list, ...), not arbitrary *layouts* -
    // ViewdefManager is the stock class for that (same one core uses in
    // modules/Leads/upgrade/scripts/post/9_LeadFixLeadConvertDashboard.php to
    // splice a component into the 'convert' layout). loadViewdef() already
    // reads the already-deployed custom file first and only falls back to
    // stock, so a tenant's own customization of the layout survives a
    // reinstall.
    // -------------------------------------------------------------------------

    protected function getDeployedLayout(string $module, string $layoutName): array
    {
        return (new ViewdefManager())->loadViewdef('base', $module, $layoutName, false, true);
    }

    protected function deployLayout(string $module, string $layoutName, array $layout): void
    {
        (new ViewdefManager())->saveViewdef($layout, $module, 'base', $layoutName, true);

        MetaDataFiles::clearModuleClientCache($module, 'layout');
        include_once 'include/TemplateHandler/TemplateHandler.php';
        TemplateHandler::clearCache($module);
    }

    /**
     * Inserts $component into the layout's 'components' list, right before the
     * first entry matching $beforeMatch (appended at the end if no match is
     * found). Idempotent: skips the insert if $component is already sitting
     * immediately before that anchor.
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

    /**
     * Appends $component to the end of the layout's 'components' list.
     * Idempotent: skips the insert if $component is already the last entry.
     */
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

    /**
     * Add a row to a Quote's GRAND TOTALS FOOTER, before a named anchor row.
     *
     * The totals footer is ordinary viewdef metadata - a single panel whose
     * `fields` array Sugar renders in order (`new_sub`, `tax`, `shipping`,
     * `total`) - so a row is added exactly the way one is added to any other
     * view, and no part of Sugar's own quote grid is overridden or replaced.
     *
     * Anchored rather than appended because the grand total is the last thing
     * read down a totals column: a row placed after it reads as sitting
     * outside the sum.
     *
     * Additive and idempotent - a row already present is left alone, so
     * installing over an existing version cannot duplicate it.
     */
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

    /**
     * Remove rows this package added to a Quote totals footer.
     */
    protected function removeFieldsFromTotalsFooterUnguarded(
        string $module,
        array $fieldNamesToRemove,
        string $view = 'quote-data-grand-totals-footer'
    ): void {
        // G26: callers pass field DEFINITIONS (['name' => 'x']), not names.
        // Every one of these five removers then compared a string field name
        // against a list of arrays with strict in_array(), which is ALWAYS
        // false -- so nothing was ever removed, the "did anything change?"
        // guard short-circuited, and deployView() was never reached. The
        // removal has never once worked, silently, on any tenant.
        //
        // Measured live 2026-09-20 on Bench: LBL_SYSPRO_TELEPHONE_EXT,
        // LBL_SYSPRO_SYNC_MESSAGE_C and LBL_SYSPRO_COMPANY_C all render as
        // raw label keys on the Contacts record view, while the SAME field
        // was genuinely gone from the list view and the selection list --
        // because those two removers DO normalise. That split is the tell.
        // G148b: a caller may pass null — BenchDogs-Ext's QLI-columns step does,
        // and the deployed log caught it:
        //   collectFieldNames(): Argument #1 ($fields) must be of type array,
        //   null given, called in .../BaseErpLayout.php on line 1222
        // Before G26 a null reached the loop and the earlier empty()/count
        // guards absorbed it; normalising at the TOP moved the null forward
        // into a typed parameter and turned a survivable no-op into a fatal.
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
