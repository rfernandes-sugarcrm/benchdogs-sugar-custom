<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

/**
 * G380 (f) / 🔒 1724b — ANY PACKAGE'S FIELDS ON ERP-EPICOR'S RECORD VIEWS, WITH
 * NO LAYOUT CODE IN THAT PACKAGE.
 *
 * THE PROBLEM (footprint S2, SB11). The Bench Dogs package shipped its own
 * layout writers (BdAccountsLayoutExtensions, BdAdmQuoteFieldsLayout) that
 * appended fields to the DEPLOYED Accounts and Quotes record views - views
 * ERP-Epicor's AccountsLayout / QuotesLayout own. Two writers on one deployed
 * file, and any ERP-Epicor rewrite could drop the fields until Bench
 * reinstalled.
 *
 * THE CONTRACT. A package marks a field on its own vardef:
 *
 *     $dictionary['Quote']['fields']['bd_lead_source']['erp_layout'] = array(
 *         'view'  => 'record',                    // only 'record' is supported
 *         'panel' => 'LBL_RECORDVIEW_PANEL_ERP',  // a panel ERP-Epicor owns
 *         'after' => '',                          // field to follow; '' = append
 *         'type'  => 'erp-dependent-enum',        // optional: the view's field type
 *     );
 *
 * and calls sync() after its vardefs are merged (post_execute) and after they
 * are gone (post_uninstall). ERP-Epicor calls sync() itself at the end of its
 * own Quotes and Accounts layout installs, so reinstalling ERP-Epicor keeps
 * every package's marked fields.
 *
 * sync($module) does exactly three things, idempotently:
 *   1. PLACE - every field of $module whose vardef carries `erp_layout` and
 *      that is on NO panel of the deployed record view (fieldsets included)
 *      is added to the named panel, after `after` when that field is in the
 *      panel, else at the end. Panel absent -> the module's ERP panel ->
 *      panel_body -> the first non-header panel with fields. A field an admin
 *      placed anywhere is left exactly where it is.
 *   2. TYPE (G571) - when the marker names a `type`, every entry of that field
 *      on the view (placed by this class or by an admin, fieldset members
 *      included) carries it; the field does not move. This is how a field gets
 *      a client-side type (e.g. ERP-Core's erp-dependent-enum) while its vardef
 *      stays a plain `enum`: sidecar takes a field's widget type from the
 *      viewdef entry first (view/field.js: viewDefs.type || def.type). It
 *      runs on entries ALREADY placed too, because an upgraded tenant's view
 *      holds the name + label entry an earlier sync wrote, and add-if-absent
 *      would never reach it. A marker without `type` leaves every entry's type
 *      as it is. Install order does not matter: whichever of ERP-Epicor's
 *      layout install or the marking package's post_execute runs last applies
 *      it.
 *      NEVER the vardef `custom_type` key for this: SugarEnt 26.1.0 DBManager
 *      skips a field with a custom_type on insert and update, so values
 *      entered in it would silently not be saved.
 *   3. RETIRE - a field this mechanism has seen marked (recorded per module in
 *      config erp_layout.extra_fields_<Module>) that now has NO vardef is taken
 *      off every panel. Nothing else is ever removed.
 *
 * 🛑 WHY RETIRE IS BOUNDED BY THE RECORD, NOT "EVERY FIELD WITH NO VARDEF".
 * The contract's first wording was the latter. Measured before building: the
 * stock record views carry fieldsets with no vardef (date_entered_by,
 * date_modified_by) and ERP-Epicor places display-only fields with none
 * (erp_comment_log_status, erp_credit_hold_badge, erp_inactive_account_badge).
 * "No vardef -> remove" would strip them on the very install that adds this
 * class. Only names that were once MARKED are package fields by construction.
 *
 * 🛑 AND IT DOES NOTHING WHEN THE VARDEFS LOOK UNREAD. A module whose field list
 * lacks `id`/`name` means the read failed, not that every field is an orphan.
 *
 * Never throws: install scripts call it, and a layout nicety must not fail an
 * install. Every refusal is logged. Scanner-safe: no dynamic dispatch, no
 * ModuleBuilder parser (MLP019) - viewdefs go through ViewdefManager, as
 * BaseErpLayout does.
 */
class ErpLayoutExtraFields
{
    /** The only view the marker supports. */
    const VIEW = 'record';

    /** Where a marked field goes when its named panel is absent, per supported module. */
    const DEFAULT_PANELS = array(
        'Quotes' => 'LBL_RECORDVIEW_PANEL_ERP',
        'Accounts' => 'LBL_RECORDVIEW_PANEL_ERP',
    );

    /** Config category for the record of marked fields (Administration settings). */
    const SETTINGS_CATEGORY = 'erp_layout';

    /**
     * @return array{added: string[], removed: string[]}
     */
    public static function sync(string $module): array
    {
        $result = array('added' => array(), 'removed' => array());
        if (!isset(self::DEFAULT_PANELS[$module])) {
            self::log('error', "sync($module): not a supported module (" . implode(', ', array_keys(self::DEFAULT_PANELS)) . ')');
            return $result;
        }

        try {
            $fields = self::vardefs($module);
            if (!isset($fields['id'], $fields['name'])) {
                self::log('error', "sync($module): the module's vardefs could not be read, so nothing was placed or removed");
                return $result;
            }

            $manager = new ViewdefManager();
            $defs = $manager->loadViewdef('base', $module, self::VIEW);
            if (empty($defs['panels']) || !is_array($defs['panels'])) {
                self::log('error', "sync($module): the deployed record view has no panels, so nothing was placed or removed");
                return $result;
            }

            $recorded = self::recorded($module);
            $marked = array();
            foreach ($fields as $name => $def) {
                if (is_array($def) && isset($def['erp_layout']) && is_array($def['erp_layout'])) {
                    $marked[(string) $name] = $def;
                }
            }

            // 3. RETIRE first, so a name that went away frees its slot before
            // anything is placed.
            $orphans = array();
            foreach ($recorded as $name) {
                if (!isset($fields[$name])) {
                    $orphans[$name] = true;
                }
            }
            if ($orphans !== array()) {
                foreach ($defs['panels'] as $i => $panel) {
                    if (empty($panel['fields']) || !is_array($panel['fields'])) {
                        continue;
                    }
                    $kept = array();
                    foreach ($panel['fields'] as $entry) {
                        $name = self::entryName($entry);
                        if ($name !== '' && isset($orphans[$name])) {
                            $result['removed'][] = $name;
                            continue;
                        }
                        $kept[] = $entry;
                    }
                    $defs['panels'][$i]['fields'] = $kept;
                }
            }

            // The field type each marker asks for ('' = none), read once.
            $types = array();
            foreach ($marked as $name => $def) {
                if ((string) ($def['erp_layout']['view'] ?? self::VIEW) === self::VIEW) {
                    $types[$name] = self::markerType($module, $name, $def['erp_layout']);
                }
            }

            // 1. PLACE.
            $placed = self::placedNames($defs['panels']);
            foreach ($marked as $name => $def) {
                $spec = $def['erp_layout'];
                $view = (string) ($spec['view'] ?? self::VIEW);
                if ($view !== self::VIEW) {
                    self::log('error', "sync($module): $name asks for view '$view'; only 'record' is supported");
                    continue;
                }
                if (isset($placed[$name])) {
                    continue;
                }
                $target = self::targetPanel($defs['panels'], (string) ($spec['panel'] ?? ''), self::DEFAULT_PANELS[$module]);
                if ($target === null) {
                    self::log('error', "sync($module): no panel can hold $name, so it was not placed");
                    continue;
                }
                $entry = array('name' => $name);
                if (!empty($def['vname'])) {
                    $entry['label'] = (string) $def['vname'];
                }
                if (!isset($defs['panels'][$target]['fields']) || !is_array($defs['panels'][$target]['fields'])) {
                    $defs['panels'][$target]['fields'] = array();
                }
                $at = self::positionAfter($defs['panels'][$target]['fields'], (string) ($spec['after'] ?? ''));
                if ($at === null) {
                    $defs['panels'][$target]['fields'][] = $entry;
                } else {
                    array_splice($defs['panels'][$target]['fields'], $at + 1, 0, array($entry));
                }
                $placed[$name] = true;
                $result['added'][] = $name;
            }

            // 2. TYPE, on every entry of a marked field - the ones just placed too.
            $typed = false;
            foreach ($types as $name => $type) {
                if ($type === '') {
                    continue;
                }
                foreach ($defs['panels'] as $i => $panel) {
                    if (empty($panel['fields']) || !is_array($panel['fields'])) {
                        continue;
                    }
                    if (self::applyType($defs['panels'][$i]['fields'], $name, $type)) {
                        $typed = true;
                    }
                }
            }

            if ($result['added'] !== array() || $result['removed'] !== array() || $typed) {
                $manager->saveViewdef($defs, $module, 'base', self::VIEW);
                self::clearCaches($module);
            }

            // The record: what is marked now, plus nothing that was retired.
            $next = array_keys($marked);
            sort($next);
            $before = $recorded;
            sort($before);
            if ($next !== $before) {
                self::record($module, $next);
            }
        } catch (Throwable $e) {
            self::log('fatal', "sync($module) stopped: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * G606 / G608 — does any package claim a place for $field on $module's record
     * view? True when the merged vardefs mark $field itself with `erp_layout`
     * (a package asks for it to be placed), or when any marked field names it as
     * its `after` anchor (a package lays its own fields out beside it - the shape
     * a customer package shipped BEFORE it marked the field itself).
     *
     * Fails SAFE: vardefs that look unread (no id/name) or any error answer TRUE,
     * so an input a customer may need is kept on screen rather than taken off on
     * a guess. Never throws.
     */
    public static function placementClaimed(string $module, string $field): bool
    {
        try {
            $fields = self::vardefs($module);
            if (!isset($fields['id'], $fields['name'])) {
                return true;
            }
            if (isset($fields[$field]['erp_layout']) && is_array($fields[$field]['erp_layout'])) {
                return true;
            }
            foreach ($fields as $def) {
                if (is_array($def) && isset($def['erp_layout']) && is_array($def['erp_layout'])
                    && (string) ($def['erp_layout']['after'] ?? '') === $field) {
                    return true;
                }
            }

            return false;
        } catch (Throwable $e) {
            self::log('error', "placementClaimed($module, $field): " . $e->getMessage() . '; treated as claimed');

            return true;
        }
    }

    /** G606 / G608: is $field itself marked with `erp_layout` in $module's merged vardefs? */
    public static function isMarked(string $module, string $field): bool
    {
        try {
            $fields = self::vardefs($module);

            return isset($fields[$field]['erp_layout']) && is_array($fields[$field]['erp_layout']);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The module's merged field definitions, re-read from disk so a package's
     * post_uninstall (its Ext vardef already gone) sees the fields as they
     * are now, not as this request first loaded them.
     *
     * @return array<string, array>
     */
    private static function vardefs(string $module): array
    {
        $object = BeanFactory::getObjectName($module);
        if (empty($object)) {
            return array();
        }
        VardefManager::refreshVardefs($module, $object);
        $fields = $GLOBALS['dictionary'][$object]['fields'] ?? array();

        return is_array($fields) ? $fields : array();
    }

    /** @return string[] */
    private static function recorded(string $module): array
    {
        $admin = BeanFactory::newBean('Administration');
        $config = $admin->getConfigForModule(self::SETTINGS_CATEGORY, 'base', true);
        $raw = is_array($config) ? ($config['extra_fields_' . $module] ?? array()) : array();
        if (is_string($raw)) {
            $raw = $raw === '' ? array() : json_decode($raw, true);
        }
        $names = array();
        foreach ((is_array($raw) ? $raw : array()) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /** @param string[] $names */
    private static function record(string $module, array $names): void
    {
        $value = json_encode(array_values($names));
        if (!is_string($value)) {
            return;
        }
        $admin = BeanFactory::newBean('Administration');
        $admin->saveSetting(self::SETTINGS_CATEGORY, 'extra_fields_' . $module, $value, 'base');
    }

    /**
     * Every field name on any panel, fieldset members included.
     *
     * @return array<string, true>
     */
    private static function placedNames(array $panels): array
    {
        $names = array();
        foreach ($panels as $panel) {
            self::collectNames((array) ($panel['fields'] ?? array()), $names);
        }

        return $names;
    }

    private static function collectNames(array $entries, array &$names): void
    {
        foreach ($entries as $entry) {
            $name = self::entryName($entry);
            if ($name !== '') {
                $names[$name] = true;
            }
            if (is_array($entry) && isset($entry['fields']) && is_array($entry['fields'])) {
                self::collectNames($entry['fields'], $names);
            }
        }
    }

    private static function entryName($entry): string
    {
        if (is_string($entry)) {
            return $entry;
        }

        return is_array($entry) ? (string) ($entry['name'] ?? '') : '';
    }

    /**
     * The marker's `type`, or '' when it names none or names something that is
     * not a field type (lower-case letters, digits and dashes, as every
     * clients/base/fields directory is named).
     */
    private static function markerType(string $module, string $name, array $spec): string
    {
        if (!isset($spec['type'])) {
            return '';
        }
        $type = is_string($spec['type']) ? $spec['type'] : '';
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/', $type) !== 1) {
            self::log('error', "sync($module): $name asks for type '" . substr((string) json_encode($spec['type']), 0, 80)
                . "', which is not a field type name; its entries are left as they are");
            return '';
        }

        return $type;
    }

    /**
     * Give every entry named $name (fieldset members included) the type $type.
     * A bare-string entry becomes array('name' => ..., 'type' => ...).
     *
     * @return bool whether anything changed
     */
    private static function applyType(array &$entries, string $name, string $type): bool
    {
        $changed = false;
        foreach ($entries as $k => $entry) {
            if (is_string($entry)) {
                if ($entry === $name) {
                    $entries[$k] = array('name' => $name, 'type' => $type);
                    $changed = true;
                }
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            // A container (fieldset) is never retyped, even if it shares the
            // name: sidecar's metadata patcher reads its members, not its type.
            if (!isset($entry['fields']) && (string) ($entry['name'] ?? '') === $name
                && ($entry['type'] ?? null) !== $type) {
                $entries[$k]['type'] = $type;
                $changed = true;
            }
            if (isset($entry['fields']) && is_array($entry['fields'])
                && self::applyType($entries[$k]['fields'], $name, $type)) {
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * The named panel; else the module's ERP panel; else panel_body; else the
     * first panel that holds fields and is not the header.
     */
    private static function targetPanel(array $panels, string $wanted, string $moduleDefault): ?int
    {
        foreach (array($wanted, $moduleDefault, 'panel_body') as $name) {
            if ($name === '') {
                continue;
            }
            foreach ($panels as $i => $panel) {
                if (($panel['name'] ?? '') === $name) {
                    return $i;
                }
            }
        }
        foreach ($panels as $i => $panel) {
            if (!empty($panel['header'])) {
                continue;
            }
            if (isset($panel['fields']) && is_array($panel['fields']) && $panel['fields'] !== array()) {
                return $i;
            }
        }

        return null;
    }

    /** Index of $after among the panel's top-level entries, or null. */
    private static function positionAfter(array $entries, string $after): ?int
    {
        if ($after === '') {
            return null;
        }
        foreach (array_values($entries) as $i => $entry) {
            if (self::entryName($entry) === $after) {
                return $i;
            }
        }

        return null;
    }

    private static function clearCaches(string $module): void
    {
        MetaDataFiles::clearModuleClientCache($module, 'view');
        MetaDataFiles::clearModuleClientCache($module, 'layout');
        include_once 'include/TemplateHandler/TemplateHandler.php';
        if (class_exists('TemplateHandler', false)) {
            TemplateHandler::clearCache($module);
        }
    }

    /** Two literal calls, never a method named by a variable (MLP017: the cloud scanner refuses one). */
    private static function log(string $level, string $message): void
    {
        if (!isset($GLOBALS['log']) || !is_object($GLOBALS['log'])) {
            return;
        }
        if ($level === 'fatal') {
            $GLOBALS['log']->fatal('[ErpLayoutExtraFields] ' . $message);
        } else {
            $GLOBALS['log']->error('[ErpLayoutExtraFields] ' . $message);
        }
    }
}
