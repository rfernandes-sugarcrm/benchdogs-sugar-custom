<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

/** Takes decision 72's `bd_governing_origin` marker OFF the Opportunity record view, on install as well as on uninstall, and never puts it back (G116, 🔒 1044, G96, G99). */
class BdOpportunitiesLayoutExtensions
{
    private const FIELD = 'bd_governing_origin';

    /** Take the field off the record view, from scripts/post_install.php and from scripts/pre_uninstall.php alike. */
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
            $kept = array();
            foreach ($panel['fields'] as $field) {
                $name = is_array($field) ? ($field['name'] ?? '') : (string) $field;
                if ($name === self::FIELD) {
                    $found = true;
                    continue;
                }
                $kept[] = $field;
            }
            $panels[$p]['fields'] = $kept;
        }
        if (!$found) {
            return;
        }

        self::deployRecordView('Opportunities', $viewdefs);
    }

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
