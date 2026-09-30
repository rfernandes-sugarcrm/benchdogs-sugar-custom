<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/** Keeps the retirement of the Bench Dogs ERP panel on the Quotes record view, and nothing else (G276, 🔒 1503, 🔒 1504, 🔒 774). */
class BdQuotesLayoutExtensions
{
    private const PANEL_NAME = 'LBL_RECORDVIEW_PANEL_BENCHDOGS';

    /**
     * @param bool $replace Rewrite the panel's field list even when it is already deployed (replace-layouts builds only).
     */
    /** The Bench Dogs panel is retired from the Quotes record view: removed on install, never added (🔒 1045). */
    public static function write(bool $replace = false): void
    {
        $viewdefs = self::loadRecordView('Quotes');
        if ($viewdefs === null) {
            return;
        }
        $panels =& $viewdefs['base']['view']['record']['panels'];

        $at = array_search(self::PANEL_NAME, array_column($panels, 'name'), true);
        if ($at === false) {
            // Never deployed, or already retired by an earlier install of
            // this version. Nothing to do -- and nothing to add.
            return;
        }

        array_splice($panels, $at, 1);
        self::deployRecordView('Quotes', $viewdefs);
    }

    /** Undo what write() guards against, from scripts/pre_uninstall.php: take the Bench Dogs panel, and with it every bd_* field reference, off the deployed Quotes record view (G276, 🔒 1504). */
    public static function remove(): void
    {
        $viewdefs = self::loadRecordView('Quotes');
        if ($viewdefs === null) {
            return;
        }
        $changed = false;

        if (!empty($viewdefs['base']['view']['record']['panels'])
            && is_array($viewdefs['base']['view']['record']['panels'])
        ) {
            $panels =& $viewdefs['base']['view']['record']['panels'];
            $keptPanels = [];
            foreach ($panels as $panel) {
                if (is_array($panel) && ($panel['name'] ?? '') === self::PANEL_NAME) {
                    $changed = true;
                    continue;
                }
                $keptPanels[] = $panel;
            }
            $panels = array_values($keptPanels);
            unset($panels);
        }

        if (!$changed) {
            return;
        }

        self::deployRecordView('Quotes', $viewdefs);
    }


    /* Removed with the panel: benchDogsPanel(), RETIRED_PANEL_FIELDS and dropRetiredPanelFields(). */


    /** The deployed record viewdef for $module — the tenant's custom copy when it has one, else stock — in the SAME ``['base']['view']['record']`` shape the ModuleBuilder parser used to hand every caller, so every mutation in this class is untouched by the swap. */
    private static function loadRecordView(string $module): ?array
    {
        $defs = (new ViewdefManager())->loadViewdef('base', $module, 'record');
        if (empty($defs)) {
            return null;
        }

        return ['base' => ['view' => ['record' => $defs]]];
    }

    /** Write the record view back, and clear the same caches the parser's deploy() cleared, so the change is visible without a repair. */
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
