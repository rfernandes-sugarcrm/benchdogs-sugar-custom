<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * Appends the Bench Dogs "Create Opportunity & Quote" button to the Accounts
 * record view at install time (REQ-20 / build commitments #1-#2).
 *
 * Buttons only - this package deliberately ships no Accounts fields or
 * panels: ERP-Epicor's AccountsLayout owns that view's ERP panels in replace
 * mode, and writing panels from here would fight the package that declares
 * their fields (see post_install_replace.php's docblock). A button append is
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
}
