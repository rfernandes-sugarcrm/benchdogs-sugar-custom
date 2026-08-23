<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * Appends a "Bench Dogs ERP" panel to the Quotes record view at install time.
 *
 * Uses DeployedMetaDataImplementation directly (get -> mutate -> set ->
 * deploy), the same mechanism CORE-ShippingAddresses'
 * ShippingAddressQuotesExtensions and ERP-Core's BaseErpLayout use -
 * getViewdefs() loads the CURRENTLY DEPLOYED (merged) definition, so any
 * existing customization on this view (e.g. the ERP-Epicor panel) survives:
 * this class only ever APPENDS its own panel, and is idempotent (skips when
 * the panel is already present), so re-running it after another package
 * re-deploys the view simply restores the Bench Dogs panel.
 *
 * Deliberately self-contained and NOT named BaseErpLayout/QuotesLayout:
 * ERP-Epicor installs classes under those names into
 * custom/include/scripts/, and reusing them would fatal with
 * "Cannot redeclare class" (or couple this package to ERP-Epicor's
 * uninstall, which removes those files).
 */
class BdQuotesLayoutExtensions
{
    private const PANEL_NAME = 'LBL_RECORDVIEW_PANEL_BENCHDOGS';

    /**
     * @param bool $replace Rewrite the panel's field list even when it is
     *   already deployed. Only the replace-layouts build passes true: on an
     *   existing tenant that has since edited the panel in Studio, rewriting it
     *   discards their work, which is exactly what the append-only build exists
     *   to avoid. Fresh installs have nothing to lose and want the packaged
     *   definition to be authoritative.
     */
    public static function write(bool $replace = false): void
    {
        require_once 'modules/ModuleBuilder/parsers/constants.php';
        require_once 'modules/ModuleBuilder/parsers/views/DeployedMetaDataImplementation.php';

        $deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, 'Quotes', 'base');
        $viewdefs = $deploy->getViewdefs();
        $panels =& $viewdefs['base']['view']['record']['panels'];

        $at = array_search(self::PANEL_NAME, array_column($panels, 'name'), true);
        if ($at !== false) {
            if (!$replace) {
                return; // already deployed - keep whatever the admin has done since
            }
            $panels[$at] = self::benchDogsPanel();
        } else {
            $panels[] = self::benchDogsPanel();
        }

        $deploy->setViewdefs($viewdefs);
        $deploy->deploy($viewdefs);
    }


    /**
     * Appends the two Bench Dogs quote-action buttons to the Quotes record
     * view, before main_dropdown so they sit next to Edit - the exact
     * insertion BaseErpLayout::addButtonsToRecordView performs for the
     * product's own buttons, cloned here (self-contained, same reasoning as
     * the panel above). Idempotent by button name. If the deployed view has
     * no buttons array at all (never seen on an instance that has ERP-Epicor
     * installed, which always deploys one), this logs and skips rather than
     * guessing at the stock set.
     */
    public static function writeButtons(): void
    {
        require_once 'modules/ModuleBuilder/parsers/constants.php';
        require_once 'modules/ModuleBuilder/parsers/views/DeployedMetaDataImplementation.php';

        $deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, 'Quotes', 'base');
        $viewdefs = $deploy->getViewdefs();

        if (empty($viewdefs['base']['view']['record']['buttons'])
            || !is_array($viewdefs['base']['view']['record']['buttons'])
        ) {
            $GLOBALS['log']->error('BenchDogs-Ext: Quotes record view has no deployed buttons array; skipping button injection');
            return;
        }

        $buttons =& $viewdefs['base']['view']['record']['buttons'];
        $existing = array_column($buttons, 'name');

        // Bench Dogs owns the quote header: ONE entry point per action.
        // The product's whole-quote buttons (Advanced Quote / Submit Order /
        // Refresh Price & Availability) and the superseded winning-line
        // button are REMOVED below - the per-line model replaces them.
        $unwanted = [
            'advanced_quote_button',
            'create_erp_order_button',
            'refresh_price_availability_button',
            'bd_order_winning_button',
        ];

        $wanted = [
            [
                'type' => 'bd-send-estimating',
                'event' => 'button:bd_send_estimating_button:click',
                'name' => 'bd_send_estimating_button',
                'label' => 'LBL_BD_SEND_ESTIMATING_BUTTON',
                'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-primary ml-2',
                'showOn' => 'view',
                'acl_action' => 'edit',
            ],
            [
                'type' => 'bd-best-pricing',
                'event' => 'button:bd_best_pricing_button:click',
                'name' => 'bd_best_pricing_button',
                'label' => 'LBL_BD_BEST_PRICING_BUTTON',
                'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-secondary ml-2',
                'showOn' => 'view',
                'acl_action' => 'edit',
            ],
            [
                'type' => 'bd-order-selected',
                'event' => 'button:bd_order_selected_button:click',
                'name' => 'bd_order_selected_button',
                'label' => 'LBL_BD_ORDER_SELECTED_BUTTON',
                'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-primary ml-2',
                'showOn' => 'view',
                'acl_action' => 'edit',
            ],
        ];

        $kept = [];
        $removed = 0;
        foreach ($buttons as $b) {
            if (is_array($b) && in_array($b['name'] ?? '', $unwanted, true)) {
                $removed++;
                continue;
            }
            $kept[] = $b;
        }
        $buttons = $kept;
        $existing = array_column($buttons, 'name');

        $new = [];
        foreach ($wanted as $button) {
            if (!in_array($button['name'], $existing, true)) {
                $new[] = $button;
            }
        }
        if (empty($new) && $removed === 0) {
            return;
        }

        if (!empty($new)) {
            $at = array_search('main_dropdown', array_column($buttons, 'name'), true);
            if ($at !== false) {
                array_splice($buttons, $at, 0, $new);
            } else {
                array_push($buttons, ...$new);
            }
        }

        $deploy->setViewdefs($viewdefs);
        $deploy->deploy($viewdefs);
    }

    private static function benchDogsPanel(): array
    {
        return [
            'name' => self::PANEL_NAME,
            'label' => self::PANEL_NAME,
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => [
                ['name' => 'bd_erp_total', 'label' => 'LBL_BD_ERP_TOTAL', 'readonly' => true],
                ['name' => 'bd_erp_stage', 'label' => 'LBL_BD_ERP_STAGE', 'readonly' => true],
                ['name' => 'bd_priced_at', 'label' => 'LBL_BD_PRICED_AT', 'readonly' => true],
                ['name' => 'bd_reason_code', 'label' => 'LBL_BD_REASON_CODE', 'readonly' => true],
                ['name' => 'bd_governing_line', 'label' => 'LBL_BD_GOVERNING_LINE', 'readonly' => true],
            ],
        ];
    }
}
