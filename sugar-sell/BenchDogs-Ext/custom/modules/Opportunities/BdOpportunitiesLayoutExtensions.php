<?php

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
 * deployed grid is the one DeployedMetaDataImplementation operation that has
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
        require_once 'modules/ModuleBuilder/parsers/constants.php';
        require_once 'modules/ModuleBuilder/parsers/views/DeployedMetaDataImplementation.php';

        $deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, 'Opportunities', 'base');
        $viewdefs = $deploy->getViewdefs();

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

        $deploy->setViewdefs($viewdefs);
        $deploy->deploy($viewdefs);
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
        require_once 'modules/ModuleBuilder/parsers/constants.php';
        require_once 'modules/ModuleBuilder/parsers/views/DeployedMetaDataImplementation.php';

        $deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, 'Opportunities', 'base');
        $viewdefs = $deploy->getViewdefs();

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

        $deploy->setViewdefs($viewdefs);
        $deploy->deploy($viewdefs);
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
}
