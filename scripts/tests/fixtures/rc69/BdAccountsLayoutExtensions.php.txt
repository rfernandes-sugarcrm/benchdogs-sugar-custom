<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * Appends the REQ-19 customer group fields to the Accounts record view at
 * install time, and takes them off again on uninstall. Nothing else.
 *
 * APPEND ONLY: ERP-Epicor's AccountsLayout owns that view's ERP panels in
 * replace mode, so this class never reorders, removes or rewrites a panel - it
 * only appends to the end of a panel's field list if not already present (see
 * post_install.php's docblock).
 *
 * 🛑 G276 / 🔒 1504 - NO BUTTON LOGIC. Until rc64 this class also carried
 * writeButtons(), which stripped this package's retired
 * `bd_create_opp_quote_button` (G15) from the deployed view on every install,
 * and remove() dropped it again on uninstall. The owner's ruling is *"remove
 * now all button logic from bench"*, so both are gone. The Accounts header
 * button is core's `erp_create_opp_quote_button`, placed by ERP-Epicor's
 * AccountsLayout; nothing here adds, removes or reorders any button.
 *
 * Same ViewdefManager load -> mutate -> save mechanism as ERP-Core's
 * BaseErpLayout, and self-contained for the "Cannot redeclare class" reason
 * documented in BdQuotesLayoutExtensions.
 */
class BdAccountsLayoutExtensions
{
    /**
     * REQ-19: put the Epicor customer group on the Accounts record view.
     *
     * The two fields are synced and correct - 31 accounts across 12 groups on
     * this tenant, measured 24 Aug 2026 - but they were declared in vardefs
     * and never placed on a layout, so the answer existed everywhere except
     * where a salesperson would look. Data that is present and invisible is
     * not delivered: it still sends someone to Kinetic to find out which
     * group an account is in, which is the lookup this project exists to
     * remove.
     *
     * APPEND ONLY, and deliberately so. ERP-Epicor's AccountsLayout owns this
     * view's panels in replace mode, so this method never reorders, never
     * removes and never rewrites a panel - it adds two fields to the end of
     * the body panel if they are not already somewhere on the view. Appending
     * a field to a deployed grid is the one viewdef
     * operation that has proved reliable here; reordering has not.
     */
    public static function writeCustomerGroupField(): void
    {
        $viewdefs = self::loadRecordView('Accounts');
        if ($viewdefs === null) {
            return;
        }

        if (empty($viewdefs['base']['view']['record']['panels'])
            || !is_array($viewdefs['base']['view']['record']['panels'])
        ) {
            $GLOBALS['log']->error('BenchDogs-Ext: Accounts record view has no panels; skipping customer group placement');
            return;
        }

        $panels =& $viewdefs['base']['view']['record']['panels'];

        // Already present anywhere on the view? Then leave it exactly alone -
        // an admin may have moved it somewhere better than we would.
        $wanted = array('bd_customer_group', 'bd_customer_group_code');
        foreach ($panels as $panel) {
            if (empty($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }
            foreach ($panel['fields'] as $field) {
                $name = is_array($field) ? ($field['name'] ?? '') : (string) $field;
                foreach ($wanted as $w) {
                    if ($name === $w) {
                        return;
                    }
                }
            }
        }

        // Prefer the body panel - the one a user sees without expanding
        // "Show more". Fall back to the first panel that can hold fields.
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
            $GLOBALS['log']->error('BenchDogs-Ext: no Accounts panel can hold fields; skipping customer group placement');
            return;
        }

        $panels[$target]['fields'][] = array(
            'name' => 'bd_customer_group',
            'label' => 'LBL_BD_CUSTOMER_GROUP',
        );
        $panels[$target]['fields'][] = array(
            'name' => 'bd_customer_group_code',
            'label' => 'LBL_BD_CUSTOMER_GROUP_CODE',
        );

        self::deployRecordView('Accounts', $viewdefs);
    }

    /**
     * Undo writeCustomerGroupField(), from scripts/pre_uninstall.php.
     *
     * Deployed metadata is not covered by any installdef, so without this the
     * uninstall left the Accounts record view carrying two fields whose vardefs
     * had just been removed. Nothing else on the view is ours - ERP-Epicor's
     * AccountsLayout owns the ERP panels in replace mode - so this removes
     * exactly those two fields and touches nothing else. The buttons array is
     * not read (G276 / 🔒 1504).
     *
     * The two fields are searched for across EVERY panel rather than in the one
     * writeCustomerGroupField() put them in, because an admin may since have
     * moved them somewhere better. Leaving a moved field behind would leave
     * exactly the orphan this method exists to prevent.
     *
     * Removing what is not there is a no-op, so this is safe on an instance that
     * never got the fields placed.
     */
    public static function remove(): void
    {
        $viewdefs = self::loadRecordView('Accounts');
        if ($viewdefs === null) {
            return;
        }
        $changed = false;

        if (!empty($viewdefs['base']['view']['record']['panels'])
            && is_array($viewdefs['base']['view']['record']['panels'])
        ) {
            $panels =& $viewdefs['base']['view']['record']['panels'];
            $drop = array('bd_customer_group', 'bd_customer_group_code');
            foreach ($panels as $i => $panel) {
                if (empty($panel['fields']) || !is_array($panel['fields'])) {
                    continue;
                }
                $kept = array();
                foreach ($panel['fields'] as $field) {
                    $name = is_array($field) ? ($field['name'] ?? '') : (string) $field;
                    if (in_array($name, $drop, true)) {
                        $changed = true;
                        continue;
                    }
                    $kept[] = $field;
                }
                $panels[$i]['fields'] = array_values($kept);
            }
            unset($panels);
        }

        if (!$changed) {
            return;
        }

        self::deployRecordView('Accounts', $viewdefs);
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
