<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * Appends the Bench Dogs "Create Opportunity & Quote" button to the Accounts
 * record view at install time (REQ-20 / build commitments #1-#2).
 *
 * Buttons only - this package deliberately ships no Accounts fields or
 * panels: ERP-Epicor's AccountsLayout owns that view's ERP panels in replace
 * mode, and writing panels from here would fight the package that declares
 * their fields (see post_install.php's docblock). A button append is
 * the one safe, idempotent touch.
 *
 * Same DeployedMetaDataImplementation get -> mutate -> set -> deploy
 * mechanism as BdQuotesLayoutExtensions, and self-contained for the same
 * "Cannot redeclare class" reason documented there.
 */
class BdAccountsLayoutExtensions
{
    public static function writeButtons(): void
    {
        require_once 'modules/ModuleBuilder/parsers/constants.php';
        require_once 'modules/ModuleBuilder/parsers/views/DeployedMetaDataImplementation.php';

        // The deployed Accounts record view (ERP-Epicor's AccountsLayout)
        // ships no 'buttons' key at all - Sidecar falls back to the BASE
        // record template's buttons at render time, so the view looks
        // normal while there is nothing here to splice into. Materialise
        // those base defaults first (exactly what Studio does on first
        // customisation), then inject ours.
        $baseDefaults = array();
        $baseFile = 'clients/base/views/record/record.php';
        if (file_exists($baseFile)) {
            $viewdefsScratch = null;
            $viewdefs = array();
            include $baseFile; // populates $viewdefs['base']['view']['record']
            $baseDefaults = $viewdefs['base']['view']['record']['buttons'] ?? array();
        }

        $deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, 'Accounts', 'base');
        $viewdefs = $deploy->getViewdefs();

        if (empty($viewdefs['base']['view']['record']['buttons'])
            || !is_array($viewdefs['base']['view']['record']['buttons'])
        ) {
            if (count($baseDefaults) === 0) {
                $GLOBALS['log']->error('BenchDogs-Ext: Accounts record view has no deployed buttons array and no base defaults; skipping button injection');
                return;
            }
            $viewdefs['base']['view']['record']['buttons'] = $baseDefaults;
        }

        $buttons =& $viewdefs['base']['view']['record']['buttons'];
        if (in_array('bd_create_opp_quote_button', array_column($buttons, 'name'), true)) {
            return;
        }

        $button = [
            'type' => 'bd-create-opp-quote',
            'event' => 'button:bd_create_opp_quote_button:click',
            'name' => 'bd_create_opp_quote_button',
            'label' => 'LBL_BD_CREATE_OPP_QUOTE_BUTTON',
            'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-primary ml-2',
            'showOn' => 'view',
            'acl_action' => 'edit',
        ];

        $at = array_search('main_dropdown', array_column($buttons, 'name'), true);
        if ($at !== false) {
            array_splice($buttons, $at, 0, [$button]);
        } else {
            $buttons[] = $button;
        }

        $deploy->setViewdefs($viewdefs);
        $deploy->deploy($viewdefs);
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
     * a field to a deployed grid is the one DeployedMetaDataImplementation
     * operation that has proved reliable here; reordering has not.
     */
    public static function writeCustomerGroupField(): void
    {
        require_once 'modules/ModuleBuilder/parsers/views/DeployedMetaDataImplementation.php';

        $deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, 'Accounts', 'base');
        $viewdefs = $deploy->getViewdefs();

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

        $deploy->setViewdefs($viewdefs);
        $deploy->deploy($viewdefs);
    }
}
