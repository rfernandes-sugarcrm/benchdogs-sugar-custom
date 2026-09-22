<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

/**
 * Takes decision 72's `bd_governing_origin` marker OFF the Opportunity record
 * view, on install as well as on uninstall, and never puts it back.
 *
 * 🛑 THIS CLASS NO LONGER PLACES ANYTHING (G116). Until rc56 it shipped
 * writeGoverningOriginField(), called from scripts/post_install.php, which
 * APPENDED `bd_governing_origin` to the record view with the label
 * `LBL_BD_GOVERNING_ORIGIN`. Both halves of that field had already been
 * retired by 🔒 1044 and are 1044 stubs that declare NOTHING:
 *
 *   custom/Extension/modules/Opportunities/Ext/Vardefs/bd_governing_origin.php
 *   custom/Extension/modules/Opportunities/Ext/Language/en_us.bd_governing_origin.php
 *
 * so what a live tenant got on every install was a row labelled with the raw
 * key `LBL_BD_GOVERNING_ORIGIN` rendering "No data" — the exact G96/G99 shape
 * the Quotes panel retirement had just been declared closed on. It survived
 * that closure because the "bd_* FULLY RETIRED on Ophir (rc56)" census read
 * QUOTES module metadata only, and this placement is on OPPORTUNITIES.
 *
 * WHY THE CALL STAYS AND ONLY THE WRITER GOES. Deleting the call would stop a
 * FRESH tenant getting the field, and would leave every tenant that has
 * already installed rc26..rc56 carrying the placement for good: deployed
 * metadata is covered by no installdef, so nothing removes it on its own.
 * scripts/post_install.php therefore calls remove() — the retirement is a
 * REMOVAL that runs on install, exactly as BdQuotesLayoutExtensions::write()
 * removes the Bench Dogs panel and never adds it.
 *
 * NOTHING WRITES THE FIELD EITHER. BdGoverningAutoSelect and the one-off
 * bd_governing_backfill.php went with the retired quote mirror (decisions
 * 901/903), so every row is null. The per-line governing pin is
 * `Products.erp_governing`, owned by ERP-Epicor-PartialFulfillment.
 *
 * 🚩 MLP019 still applies to how the view is read and written — see
 * loadRecordView() below. That hazard is about the CLASS NAME the hosted
 * Rector scan resolves, not about what the mutation does, so it outlives the
 * placement it was written for.
 */
class BdOpportunitiesLayoutExtensions
{
    private const FIELD = 'bd_governing_origin';

    /**
     * Take the field off the record view, from scripts/post_install.php and
     * from scripts/pre_uninstall.php alike.
     *
     * Searched across EVERY panel rather than the one it used to be appended
     * to, because an admin may have moved it — a moved field left behind is
     * exactly the orphan this method exists to prevent.
     *
     * Writes only when it finds something, so the second install after the
     * first one cleaned up touches no metadata at all.
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
