<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * Keeps the RETIREMENT of the "Bench Dogs ERP" panel on the Quotes record view,
 * and nothing else.
 *
 * 🛑 The panel is no longer appended by this class. It is removed on install
 * and never added back -- see write() for the field-by-field evidence and the
 * owner's words.
 *
 * 🛑 G276 / 🔒 1503 + 🔒 1504 - THIS CLASS CARRIES NO BUTTON LOGIC, AND NOTHING
 * IN THIS PACKAGE DOES. The owner, verbatim: *"a seller should have both
 * selected line and submited order use core dont use anything from bench dog
 * extension logic for buttons!"*, *"Donthave any logic on bench that is not on
 * core"* and *"remove now all button logic from bench"*. Decision 91 (rc26:
 * strip create_erp_order_button and refresh_price_availability_button off the
 * Bench Quote view, stash their definitions in config benchdogs /
 * removed_quote_buttons, restore them on uninstall) is RETIRED with it.
 *
 * Removed in 0.9.42-rc64: writeButtons() (the strip + stash, called on every
 * install and by the bd-tools/repair-ui route), nextSurvivor(), stashRemoved(),
 * stashedButtons(), clearStash(), the STASH_* constants, and remove()'s two
 * button steps (drop this package's retired bd_* buttons; put the stashed ERP
 * buttons back).
 *
 * 📌 HOW THE STRIPPED BUTTONS COME BACK: through CORE, not through us. Every
 * ERP-Epicor install runs QuotesLayout::install(), which calls
 * BaseErpLayout::addButtonsToRecordView() UNCONDITIONALLY (replace build or
 * not) and adds any missing button AND reconciles an existing one to core's
 * current definition (🔒 774). The stash is deliberately NOT replayed: it holds
 * the definitions as they stood when rc26 first stripped them, and core has
 * changed create_erp_order_button since (188b8de, 34ed435). Restoring those
 * would be this package writing a stale copy of a core button - the very logic
 * the ruling removes. The orphaned config row is tenant data and is left alone.
 *
 * Uses ViewdefManager directly (load -> mutate -> save; see MLP019 on deploy),
 * the same mechanism ERP-Core's BaseErpLayout uses - getViewdefs() loads the
 * CURRENTLY DEPLOYED (merged) definition, so every other customization on this
 * view survives, and the removal is idempotent (a no-op once the panel is gone).
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
    /**
     * 🛑 THE BENCH DOGS PANEL IS RETIRED FROM THE QUOTES RECORD VIEW. It is
     *    now REMOVED on install and never added.
     *
     * Owner, on Bench 2026-09-20, looking at quote 273: *"shoudl not have BD
     * here why we have bd"*, and separately *"wh i see this copy this should
     * be form core"* against a panel header rendering as
     * `LBL_RECORDVIEW_PANEL_BENCHDOGS`.
     *
     * THE EVIDENCE, NOT THE INTENTION. Every field this panel carried is a
     * private Bench copy of something core already owns, and the ruling is
     * recorded in connector_ext_benchdogs/models/crm/sell/quote_kpi.py under
     * 🔒 1045, in these words: "only bd_customer_group /
     * bd_customer_group_code stay in the Bench layer; everything else belongs
     * to core and its MLPs". Measured against the connector lanes today:
     *
     *   bd_erp_total   RETIRED -> core's `quote_total`, off the IDENTICAL
     *                  Epicor column DocTotalQuote.
     *   bd_erp_stage   RETIRED -> core owns the estimating lifecycle on
     *                  Sugar's native `quote_stage`.
     *   bd_reason_code RETIRED -> core's `Quotes.erp_reason_code`.
     *   bd_sent_to_estimating_at, bd_governing_line, bd_priced_at
     *                  RETIRED, no writer at all -- a search of every
     *                  connector lane returns ZERO python files for
     *                  bd_sent_to_estimating_at.
     *
     * So the panel showed four fields nothing populates, two of them as raw
     * label keys over "No data". Removing it is the fix; adding the missing
     * label would only have made a dead field look supported.
     *
     * 🚩 THE FIELDS THEMSELVES ARE NOT TOUCHED, and that is deliberate.
     * `bd_erp_stage` still has a live writer --
     * BdBenchDogsActionsApi stamps `in_estimating` on it -- so this removes
     * the panel from the RECORD VIEW only, never a vardef and never that
     * writer. "Nothing shows it" and "nothing writes it" are different
     * claims, and only the first one is being made here.
     *
     * $replace is kept in the signature: callers pass it, and both values
     * now mean the same thing, because there is no longer a version of this
     * panel to deploy.
     */
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

    /**
     * Undo what write() guards against, from scripts/pre_uninstall.php: take
     * the Bench Dogs panel, and with it every bd_* field reference, off the
     * deployed Quotes record view.
     *
     * Called from scripts/pre_uninstall.php - PRE, not post, because deployed
     * metadata surgery needs this class file, and uninstall removes it.
     *
     * 🛑 BUTTONS ARE NOT TOUCHED (G276 / 🔒 1504). Until rc64 this method also
     * dropped this package's retired bd_* buttons and put back the ERP-Epicor
     * buttons writeButtons() had stashed. Both halves were button logic, and
     * both are gone: the buttons array is left exactly as deployed.
     *
     * Removing what is not there is a no-op, so this is safe to run on an
     * instance that never had the panel.
     */
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


    /*
     * 🗑️ REMOVED WITH THE PANEL: benchDogsPanel(), RETIRED_PANEL_FIELDS and
     * dropRetiredPanelFields().
     *
     * They existed to prune dead fields OUT of a panel that is itself now
     * removed on install, so keeping them would leave three private members
     * that read as live machinery and can never run. This package has already
     * paid for code that looks active and is not (a guarded class_exists that
     * silently switched a feature off, three install cycles), so dead helpers
     * go out with the thing they served rather than being left "just in case".
     *
     * The field-by-field evidence they carried is preserved in write()'s
     * docblock above, which is where a reader now asks why there is no panel.
     */


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
