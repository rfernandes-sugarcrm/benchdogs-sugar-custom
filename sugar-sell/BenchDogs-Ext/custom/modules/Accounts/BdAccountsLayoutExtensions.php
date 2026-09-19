<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * Appends the Bench Dogs "Create Opportunity & Quote" button, and the REQ-19
 * customer group fields, to the Accounts record view at install time.
 *
 * Both are append-only: ERP-Epicor's AccountsLayout owns that view's ERP
 * panels in replace mode, so this class never reorders, removes or rewrites
 * a panel - it only appends to the buttons array or to the end of a panel's
 * field list if not already present (see post_install.php's docblock).
 *
 * Same ViewdefManager load -> mutate -> save
 * mechanism as BdQuotesLayoutExtensions, and self-contained for the same
 * "Cannot redeclare class" reason documented there.
 */
class BdAccountsLayoutExtensions
{
    /**
     * 🛑 G15 — RETIRE the Bench "Create Opportunity & Quote" button. Do not re-add it.
     *
     * THE DEFECT, SEEN ON SCREEN. The Accounts record view rendered the button
     * TWICE. The header read, literally:
     *
     *     ADDISON WB I85L06 ... Distribution DIST
     *     Create Opportunity & Quote  Create Opportunity & Quote  Edit
     *
     * WHY THE OLD GUARD DID NOT CATCH IT. This class refused to inject when a
     * button named `bd_create_opp_quote_button` was already present. ERP-Epicor's
     * AccountsLayout ships one named `erp_create_opp_quote_button` — a DIFFERENT
     * name carrying an IDENTICAL label, `LBL_ERP_CREATE_OPP_QUOTE_BUTTON` =
     * "Create Opportunity & Quote". A guard keyed on the name cannot see a
     * duplicate keyed on the label, so each package correctly concluded it was
     * the only one and both injected.
     *
     * WHY CORE'S IS THE ONE THAT SURVIVES, and this one goes:
     *   - 🔒 1044 — the Bench layer is TWO fields, bd_customer_group{,_code}, and
     *     the connector code that writes them. Nothing else belongs here.
     *   - core's AccountsErpActionsApi is the SUPERSET, not an equivalent: it
     *     carries DEFAULT_PLACEHOLDER_PART = 'ETO-PENDING' (the exact placeholder
     *     REQ-20's oracle names), is tenant-configurable through
     *     opp_quote_placeholder_{enabled,name,part}, and types the quote for
     *     Advanced Quote. Retiring core's and keeping this one would LOSE those.
     *
     * 🚩 WHY THIS METHOD STILL EXISTS INSTEAD OF BEING DELETED — the mechanism
     * that has cost this project the most. The button was written into the
     * tenant's DEPLOYED viewdef by saveViewdef(). Deleting the code that wrote
     * it does NOT remove it: a deployed custom viewdef outlives the package that
     * created it, exactly as a copied custom/Extension file does (rc24 dropped
     * six files, installed clean, and was INERT — the fields were still there).
     * ONLY OVERWRITING RETIRES. So this method must keep running, and must now
     * actively remove what it used to add.
     *
     * It touches nothing else: the materialised base buttons array stays (it is
     * the stock set Sidecar would fall back to anyway), and the REQ-19 customer
     * group fields are written by writeCustomerGroupField() and are untouched.
     */
    public static function writeButtons(): void
    {
        $viewdefs = self::loadRecordView('Accounts');
        if ($viewdefs === null) {
            return;
        }

        if (empty($viewdefs['base']['view']['record']['buttons'])
            || !is_array($viewdefs['base']['view']['record']['buttons'])
        ) {
            return; // nothing deployed here, so nothing of ours to retire
        }

        $buttons =& $viewdefs['base']['view']['record']['buttons'];
        $kept = array();
        $removed = 0;
        foreach ($buttons as $b) {
            if (is_array($b) && ($b['name'] ?? '') === 'bd_create_opp_quote_button') {
                $removed++;
                continue;
            }
            $kept[] = $b;
        }
        $buttons = array_values($kept);
        unset($buttons);

        if ($removed === 0) {
            return; // already retired; do not rewrite the view for nothing
        }

        $GLOBALS['log']->info(
            'BenchDogs-Ext: retired ' . $removed . ' bd_create_opp_quote_button ' .
            "from the Accounts record view (G15 - ERP-Epicor's erp_create_opp_quote_button owns this action)"
        );
        self::deployRecordView('Accounts', $viewdefs);
    }

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
     * Undo both writes above, from scripts/pre_uninstall.php.
     *
     * Deployed metadata is not covered by any installdef, so without this the
     * uninstall left the Accounts record view carrying a button whose field
     * type had just been removed and two fields whose vardefs had just been
     * removed. Nothing else on the view is ours - ERP-Epicor's AccountsLayout
     * owns the ERP panels in replace mode - so this removes exactly four things
     * and touches nothing else, which is the same append-only discipline the
     * install side keeps.
     *
     * The two fields are searched for across EVERY panel rather than in the one
     * writeCustomerGroupField() put them in, because an admin may since have
     * moved them somewhere better. Leaving a moved field behind would leave
     * exactly the orphan this method exists to prevent.
     *
     * One get -> mutate -> set -> deploy cycle for both repairs. Removing what
     * is not there is a no-op, so this is safe on an instance that never got
     * the fields placed.
     */
    public static function remove(): void
    {
        $viewdefs = self::loadRecordView('Accounts');
        if ($viewdefs === null) {
            return;
        }
        $changed = false;

        if (!empty($viewdefs['base']['view']['record']['buttons'])
            && is_array($viewdefs['base']['view']['record']['buttons'])
        ) {
            $buttons =& $viewdefs['base']['view']['record']['buttons'];
            $kept = array();
            foreach ($buttons as $b) {
                if (is_array($b) && ($b['name'] ?? '') === 'bd_create_opp_quote_button') {
                    $changed = true;
                    continue;
                }
                $kept[] = $b;
            }
            $buttons = array_values($kept);
            unset($buttons);
        }

        // The buttons array writeButtons() may have MATERIALISED from the base
        // record template is deliberately left in place. It is the stock set,
        // identical to what Sidecar would fall back to, so removing it would be
        // a second change with no visible effect and some risk.

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
