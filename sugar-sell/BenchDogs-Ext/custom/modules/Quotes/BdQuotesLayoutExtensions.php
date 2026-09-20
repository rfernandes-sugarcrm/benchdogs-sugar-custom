<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * Keeps the Bench Dogs surfaces on the Quotes record view: the two quote-action
 * BUTTONS, and the REMOVAL of the retired "Bench Dogs ERP" panel.
 *
 * 🛑 The panel is no longer appended by this class. It is removed on install
 * and never added back -- see write() for the field-by-field evidence and the
 * owner's words. What remains here is button placement and the uninstall
 * sweep.
 *
 * Uses ViewdefManager directly (load -> mutate -> save; see MLP019 on
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
     * Where writeButtons() parks the definitions of the buttons it takes off
     * the deployed view, so remove() can hand them back on uninstall.
     *
     * This exists because the buttons this package deletes are NOT ITS OWN.
     * advanced_quote_button, create_erp_order_button and
     * refresh_price_availability_button belong to ERP-Epicor, and deployed
     * metadata is not covered by any installdef - uninstalling Bench Dogs
     * therefore used to leave ERP-Epicor permanently short three buttons, with
     * reinstalling ERP-Epicor the only way to get them back. Stashing the exact
     * definition (and where it sat) at install time is what makes the removal
     * reversible.
     */
    private const STASH_CATEGORY = 'benchdogs';
    private const STASH_KEY = 'removed_quote_buttons';

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
        $viewdefs = self::loadRecordView('Quotes');
        if ($viewdefs === null) {
            return;
        }

        if (empty($viewdefs['base']['view']['record']['buttons'])
            || !is_array($viewdefs['base']['view']['record']['buttons'])
        ) {
            $GLOBALS['log']->error('BenchDogs-Ext: Quotes record view has no deployed buttons array; skipping button injection');
            return;
        }

        $buttons =& $viewdefs['base']['view']['record']['buttons'];
        $existing = array_column($buttons, 'name');

        // Bench Dogs owns the quote header: ONE entry point per action.
        // The product's whole-quote buttons (Submit Order / Refresh Price &
        // Availability) and the superseded winning-line button are REMOVED
        // below - the per-line model replaces them.
        //
        // 🛑 'advanced_quote_button' IS NO LONGER REMOVED (owner ruling, D2-BTN).
        //
        // A seller was seeing TWO estimating buttons: this package's blue
        // "Quote Estimate" and ERP-Core's "Send to Estimation", side by side,
        // doing the same thing - create the Kinetic quote and move the stage to
        // In Estimating. The owner ruled the PRODUCT button survives and this
        // package's duplicate goes.
        //
        // 🚩 WHY BOTH WERE VISIBLE, WHICH IS NOT WHAT IT LOOKS LIKE. This list
        // stripped advanced_quote_button on every Bench install - so the
        // duplicate was never supposed to exist. It appeared because ERP-Epicor
        // is installed AFTER Bench Dogs and its QuotesLayout::install() ADDS
        // that button back. The duplicate is an INSTALL-ORDER artifact, not two
        // packages both asking for a button.
        //
        // 📌 So removing ours is only half the fix: leaving
        // advanced_quote_button in $unwanted would strip the one button we are
        // keeping on every Bench install, and a seller would have NO estimating
        // action until the next ERP-Epicor install happened to re-add it.
        $unwanted = [
            'create_erp_order_button',
            'refresh_price_availability_button',
            'bd_order_winning_button',
            // Removed for the Bench Dogs demo: catalog best-pricing is not part
            // of the pure-quote story on either simple or advanced quotes - the
            // estimator's price on the quote is the only price. Listed here as
            // well as dropped from $wanted because the button is already in the
            // deployed viewdefs and $wanted alone would not take it back out.
            'bd_best_pricing_button',
            // The retired duplicate. Listed here for exactly the reason
            // bd_best_pricing_button is: it is already in the deployed
            // viewdefs on every Bench tenant, and dropping it from $wanted
            // alone would leave it sitting there forever.
            'bd_send_estimating_button',
        ];

        // Nothing of this package's own is placed on the Quotes header any
        // more: the estimating action is ERP-Core's 'Send to Estimation'.
        $wanted = [];

        $kept = [];
        $removed = 0;
        $stash = [];
        // bd_order_selected_button was this package's own predecessor to
        // Order Selected Lines, superseded once the product shipped the
        // same idea as erp_order_selected_button (now
        // ERP-Epicor-PartialFulfillment). Still removed here like the
        // other retired whole-quote buttons above - but no patching of
        // THAT button's own definition needed or done anymore: it shows
        // for every quote type once its package is installed, with no
        // per-deployment flag to set on it.
        $unwanted[] = 'bd_order_selected_button';
        foreach ($buttons as $i => $b) {
            if (is_array($b) && in_array($b['name'] ?? '', $unwanted, true)) {
                $removed++;
                // Record the whole definition and where it sat, before it is
                // dropped - see self::STASH_KEY's docblock for why. Anchored by
                // the name of the next SURVIVING button rather than by index,
                // because the index is meaningless once the array is rebuilt.
                $stash[$b['name']] = [
                    'def' => $b,
                    'before' => self::nextSurvivor($buttons, $i, $unwanted),
                ];
                continue;
            }
            $kept[] = $b;
        }
        $buttons = $kept;
        if (!empty($stash)) {
            self::stashRemoved($stash);
        }
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

        self::deployRecordView('Quotes', $viewdefs);
    }

    /**
     * Undo everything write()/writeButtons() did to the deployed Quotes record
     * view, and give ERP-Epicor its buttons back.
     *
     * Called from scripts/pre_uninstall.php - PRE, not post, because deployed
     * metadata surgery needs this class file, and uninstall removes it. There
     * was no uninstall path at all before, which is what broke the instance the
     * last time this package was removed: the Bench Dogs panel stayed on the
     * view pointing at five bd_* fields whose vardefs had just gone, the
     * Send to Estimating button stayed pointing at a field type whose JS had
     * just gone, and ERP-Epicor's three buttons stayed gone.
     *
     * One get -> mutate -> set -> deploy cycle for all three repairs, so a
     * half-finished uninstall cannot leave the view deployed twice in
     * different states. Removing what is not there is a no-op throughout, so
     * this is safe to run on an instance that never had some of it.
     */
    public static function remove(): void
    {
        $viewdefs = self::loadRecordView('Quotes');
        if ($viewdefs === null) {
            return;
        }
        $changed = false;

        // 1. The Bench Dogs panel, and with it every bd_* field reference.
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

        if (!empty($viewdefs['base']['view']['record']['buttons'])
            && is_array($viewdefs['base']['view']['record']['buttons'])
        ) {
            $buttons =& $viewdefs['base']['view']['record']['buttons'];

            // 2. Our own buttons. bd_order_winning_button, bd_best_pricing_button
            // and bd_order_selected_button are superseded and writeButtons()
            // already strips them, but an instance that stopped at an older
            // version still has them deployed - and they would be just as
            // broken. Listed here so one uninstall clears every generation.
            $ours = [
                'bd_send_estimating_button',
                'bd_order_winning_button',
                'bd_best_pricing_button',
                'bd_order_selected_button',
            ];
            $keptButtons = [];
            foreach ($buttons as $b) {
                if (is_array($b) && in_array($b['name'] ?? '', $ours, true)) {
                    $changed = true;
                    continue;
                }
                $keptButtons[] = $b;
            }
            $buttons = array_values($keptButtons);

            // 3. Hand back what we took from ERP-Epicor.
            $stashed = self::stashedButtons();
            if (empty($stashed)) {
                // An empty stash is not necessarily good news, and this is the
                // one case this mechanism CANNOT repair. On an instance whose
                // last install predates the stash, ERP-Epicor's buttons are
                // already gone from the deployed view and nothing recorded what
                // they were - and reinstalling this package does not recapture
                // them, because writeButtons() can only stash what is still
                // there to remove. Say so loudly rather than finish quietly and
                // leave an admin to discover three missing buttons later.
                $GLOBALS['log']->error(
                    'BenchDogs-Ext: no stashed buttons to restore. If advanced_quote_button, '
                    . 'create_erp_order_button or refresh_price_availability_button are missing '
                    . 'from the Quotes record view after this uninstall, re-run ERP-Epicor\'s own '
                    . 'installer to write them back - this package removed them before it recorded '
                    . 'what it was removing.'
                );
            }
            foreach ($stashed as $name => $entry) {
                $def = is_array($entry) ? ($entry['def'] ?? null) : null;
                if (!is_array($def) || empty($def['name'])) {
                    continue;
                }
                if (in_array($name, array_column($buttons, 'name'), true)) {
                    continue; // already back - the owning package was reinstalled
                }
                $at = false;
                if (!empty($entry['before'])) {
                    $at = array_search($entry['before'], array_column($buttons, 'name'), true);
                }
                if ($at === false) {
                    $at = array_search('main_dropdown', array_column($buttons, 'name'), true);
                }
                if ($at === false) {
                    $buttons[] = $def;
                } else {
                    array_splice($buttons, $at, 0, [$def]);
                }
                $changed = true;
            }
            unset($buttons);
        }

        if (!$changed) {
            return;
        }

        self::deployRecordView('Quotes', $viewdefs);
    }

    /**
     * The name of the first button after $from that writeButtons() is NOT about
     * to remove. A run of consecutive unwanted buttons would otherwise anchor
     * the first of them to the second, which is itself gone by the time
     * remove() looks for it.
     *
     * Empty when the removed button was last: remove() then falls back to
     * main_dropdown, which is where writeButtons() inserts anyway.
     */
    private static function nextSurvivor(array $buttons, int $from, array $unwanted): string
    {
        $count = count($buttons);
        for ($i = $from + 1; $i < $count; $i++) {
            $name = is_array($buttons[$i]) ? ($buttons[$i]['name'] ?? '') : '';
            if ($name !== '' && !in_array($name, $unwanted, true)) {
                return $name;
            }
        }

        return '';
    }

    /**
     * Merge into the stash; never overwrite it.
     *
     * writeButtons() runs again on every upgrade install, and by then the
     * buttons it removed the first time are already gone - so it captures
     * nothing and a blind write would replace a good stash with an empty one.
     * The uninstall would then have nothing to give back, which is the exact
     * failure this whole mechanism exists to prevent. First capture of a given
     * button name wins.
     */
    private static function stashRemoved(array $entries): void
    {
        try {
            $stash = self::stashedButtons();
            foreach ($entries as $name => $entry) {
                if (!isset($stash[$name])) {
                    $stash[$name] = $entry;
                }
            }
            $json = json_encode($stash);
            if ($json === false) {
                $GLOBALS['log']->error('BenchDogs-Ext: could not encode removed buttons; uninstall will not be able to restore them');

                return;
            }
            // base64 so the value survives the config table round trip
            // untouched, whatever escaping the settings layer applies to the
            // quotes and slashes in a JSON blob.
            $admin = BeanFactory::newBean('Administration');
            $admin->saveSetting(self::STASH_CATEGORY, self::STASH_KEY, base64_encode($json));
        } catch (Throwable $e) {
            // A stash that cannot be written must never fail the install. It
            // only costs the uninstall its button restore, which is exactly
            // where we already were.
            $GLOBALS['log']->error('BenchDogs-Ext: could not stash removed buttons: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, array> Keyed by button name; empty when nothing is
     *   stashed, which is also what an instance installed before this mechanism
     *   existed looks like.
     */
    private static function stashedButtons(): array
    {
        try {
            $admin = BeanFactory::newBean('Administration');
            $admin->retrieveSettings(self::STASH_CATEGORY);
            $raw = $admin->settings[self::STASH_CATEGORY . '_' . self::STASH_KEY] ?? '';
            if (!is_string($raw) || $raw === '') {
                return [];
            }
            $json = base64_decode($raw, true);
            if ($json === false) {
                return [];
            }
            $decoded = json_decode($json, true);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: could not read stashed buttons: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Blank the stash once remove() has handed the buttons back, so a later
     * reinstall starts from the view as it actually is. Blanked rather than
     * deleted: writing an empty string is the scanner-safe operation the
     * settings API gives us, and stashedButtons() already treats empty as none.
     */
    public static function clearStash(): void
    {
        try {
            $admin = BeanFactory::newBean('Administration');
            $admin->saveSetting(self::STASH_CATEGORY, self::STASH_KEY, '');
        } catch (Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: could not clear the button stash: ' . $e->getMessage());
        }
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
