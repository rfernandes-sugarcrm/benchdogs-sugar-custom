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

    public static function write(): void
    {
        require_once 'modules/ModuleBuilder/parsers/constants.php';
        require_once 'modules/ModuleBuilder/parsers/views/DeployedMetaDataImplementation.php';

        $deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, 'Quotes', 'base');
        $viewdefs = $deploy->getViewdefs();
        $panels =& $viewdefs['base']['view']['record']['panels'];

        if (in_array(self::PANEL_NAME, array_column($panels, 'name'), true)) {
            return; // already deployed - keep whatever the admin has done since
        }

        $panels[] = self::benchDogsPanel();

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
