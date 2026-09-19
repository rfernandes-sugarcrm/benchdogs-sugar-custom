<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

/**
 * Put decision 72's marker where a forecaster will actually meet it: on the
 * Opportunity record view, next to the number it is a fact about.
 *
 * WHY THIS FILE CAN EXIST AT ALL, AND COULD NOT BEFORE 2026-09-14
 *
 * The installer route that writes deployed metadata - scripts/post_install.php
 * under the `post_execute` installdef - ran for the FIRST TIME IN THIS
 * PACKAGE'S HISTORY at 21:22:24Z on 2026-09-14 (0.9.42-rc26). Until rc25 the
 * whole file sat inside `if (function_exists('post_execute') === false) {
 * function post_execute() { ... } }` and nothing ever called it, so any
 * placement written before rc26 would have produced A FIELD NOTHING RENDERS -
 * a bean value with no way to see it, which is a second invisible state rather
 * than observability. The D29-R1 agent was right to refuse to ship one.
 *
 * So this class goes through the same `post_execute` path that just proved
 * out, and nothing else.
 *
 * APPEND ONLY, exactly as BdAccountsLayoutExtensions::writeCustomerGroupField()
 * is. This never reorders, never removes and never rewrites a panel: if the
 * field is already ANYWHERE on the view it returns untouched, because an admin
 * may have moved it somewhere better than we would. Appending a field to a
 * deployed grid is the one viewdef operation that has
 * proved reliable here; reordering has not.
 *
 * NOT EXPOSED TO THE SalesStageDomDropdown HAZARD. ERP-Epicor writes
 * `sales_stage_dom` as a WHOLE ARRAY, so anything of ours that lived in an
 * app_list_strings domain could be clobbered by its next install. This field
 * is a plain varchar with a module string for its label and no dropdown
 * domain at all, and this method touches only the Opportunities record view -
 * neither surface is one ERP-Epicor rewrites. That is why the vardef is a
 * varchar rather than the enum it would otherwise naturally be.
 */
class BdOpportunitiesLayoutExtensions
{
    private const FIELD = 'bd_governing_origin';
    private const LABEL = 'LBL_BD_GOVERNING_ORIGIN';

    public static function writeGoverningOriginField(): void
    {
        $viewdefs = self::loadRecordView('Opportunities');
        if ($viewdefs === null) {
            return;
        }

        if (empty($viewdefs['base']['view']['record']['panels'])
            || !is_array($viewdefs['base']['view']['record']['panels'])
        ) {
            $GLOBALS['log']->fatal(
                'BenchDogs-Ext: Opportunities record view has no panels; skipping value-source placement'
            );
            return;
        }

        $panels =& $viewdefs['base']['view']['record']['panels'];

        if (self::indexOf($panels) !== null) {
            return;
        }

        // Prefer the body panel - the one a person sees without expanding
        // "Show more". A marker nobody expands to find is not a marker.
        $target = null;
        foreach ($panels as $i => $panel) {
            if (!isset($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }
            if (($panel['name'] ?? '') === 'panel_body') {
                $target = $i;
                break;
            }
            if ($target === null) {
                $target = $i;
            }
        }
        if ($target === null) {
            $GLOBALS['log']->fatal(
                'BenchDogs-Ext: no Opportunities panel can hold fields; skipping value-source placement'
            );
            return;
        }

        $panels[$target]['fields'][] = array(
            'name' => self::FIELD,
            'label' => self::LABEL,
            // Read-only on the view for the same reason the vardef is
            // studio => false: the value is DERIVED from the quote's lines on
            // every rollup, so a hand edit would be overwritten and would have
            // meant nothing while it lasted. Choosing a different governing
            // line is the way to change it, and that is what decision 72 says.
            'readonly' => true,
        );

        self::deployRecordView('Opportunities', $viewdefs);
    }

    /**
     * Undo the placement, from scripts/pre_uninstall.php.
     *
     * Deployed metadata is covered by no installdef, so without this an
     * uninstall leaves the record view carrying a field whose vardef has just
     * been removed. Searched across EVERY panel rather than the one it was
     * appended to, because an admin may have moved it - leaving a moved field
     * behind would leave exactly the orphan this method exists to prevent.
     */
    public static function remove(): void
    {
        $viewdefs = self::loadRecordView('Opportunities');
        if ($viewdefs === null) {
            return;
        }

        if (empty($viewdefs['base']['view']['record']['panels'])
            || !is_array($viewdefs['base']['view']['record']['panels'])
        ) {
            return;
        }

        $panels =& $viewdefs['base']['view']['record']['panels'];
        $found = false;
        foreach ($panels as $p => $panel) {
            if (empty($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }
            foreach ($panel['fields'] as $f => $field) {
                $name = is_array($field) ? ($field['name'] ?? '') : (string) $field;
                if ($name === self::FIELD) {
                    unset($panels[$p]['fields'][$f]);
                    $found = true;
                }
            }
            if ($found) {
                $panels[$p]['fields'] = array_values($panels[$p]['fields']);
            }
        }
        if (!$found) {
            return;
        }

        self::deployRecordView('Opportunities', $viewdefs);
    }

    /** Which panel already carries the field, if any. */
    private static function indexOf(array $panels)
    {
        foreach ($panels as $i => $panel) {
            if (empty($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }
            foreach ($panel['fields'] as $field) {
                $name = is_array($field) ? ($field['name'] ?? '') : (string) $field;
                if ($name === self::FIELD) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * The deployed record viewdef for $module — the tenant's custom copy when
     * it has one, else stock — in the SAME ``['base']['view']['record']``
     * shape the ModuleBuilder parser used to hand every caller, so every
     * mutation in this class is untouched by the swap. null when the module
     * has no such view: a caller must not write a nearly empty custom file
     * over a view that was never there.
     *
     * 🚩 MLP019. Naming DeployedMetaDataImplementation makes SugarCloud's
     * Rector scan resolve it through the tenant's own autoloader, which has
     * no rule for modules/ModuleBuilder/parsers/views/, and the WHOLE package
     * is refused before anything installs — `Class
     * "AbstractMetaDataImplementation" not found`. Guarding the include,
     * deferring it into a constructor and class_exists() were all tried and
     * all still failed on a hosted tenant; not naming the class is what
     * actually removes the risk. ViewdefManager is namespaced and autoloads
     * through src/. This mirrors ERP-Core's BaseErpLayout, which has shipped
     * the same pair through the hosted scan.
     */
    private static function loadRecordView(string $module): ?array
    {
        $defs = (new ViewdefManager())->loadViewdef('base', $module, 'record');
        if (empty($defs)) {
            return null;
        }

        return ['base' => ['view' => ['record' => $defs]]];
    }

    /**
     * Write the record view back, and clear the same caches the parser's
     * deploy() cleared, so the change is visible without a repair.
     *
     * Three things deploy() also did are deliberately NOT carried over:
     * saveHistory(), which only feeds Studio's "restore previous layout";
     * the Studio working-copy unlink(), because a package may not call
     * unlink() at all (MLP002 — one occurrence rejects the upload) and a
     * working copy only matters to a Studio session left open mid-edit, not
     * to what renders; and cleanDependentLayoutMetadataFiles(), which only
     * concerns role- and dropdown-based layout variants this package never
     * creates.
     */
    private static function deployRecordView(string $module, array $viewdefs): void
    {
        (new ViewdefManager())->saveViewdef(
            $viewdefs['base']['view']['record'],
            $module,
            'base',
            'record'
        );

        MetaDataFiles::clearModuleClientCache($module, 'view');
        MetaDataFiles::clearModuleClientCache($module, 'layout');
        include_once 'include/TemplateHandler/TemplateHandler.php';
        TemplateHandler::clearCache($module);
    }

}
