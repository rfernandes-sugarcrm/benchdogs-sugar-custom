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
                // Already deployed - keep whatever the admin has done since,
                // except the retired fields this panel itself once shipped.
                if (self::dropRetiredPanelFields($panels[$at])) {
                    $deploy->setViewdefs($viewdefs);
                    $deploy->deploy($viewdefs);
                }
                return;
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
            // Removed for the Bench Dogs demo: catalog best-pricing is not part
            // of the pure-quote story on either simple or advanced quotes - the
            // estimator's price on the quote is the only price. Listed here as
            // well as dropped from $wanted because the button is already in the
            // deployed viewdefs and $wanted alone would not take it back out.
            'bd_best_pricing_button',
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
        ];


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

        $deploy->setViewdefs($viewdefs);
        $deploy->deploy($viewdefs);
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
        require_once 'modules/ModuleBuilder/parsers/constants.php';
        require_once 'modules/ModuleBuilder/parsers/views/DeployedMetaDataImplementation.php';

        $deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, 'Quotes', 'base');
        $viewdefs = $deploy->getViewdefs();
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

        $deploy->setViewdefs($viewdefs);
        $deploy->deploy($viewdefs);
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
            ],
        ];
    }

    /**
     * Fields earlier versions placed on this panel that nothing writes any more.
     *
     * bd_governing_line: decision 29 (2026-09-13) makes the governing selection
     * the bd01_ERP_Quote_Line.governing flag a person sets in Sugar, read
     * fail-closed by ErpQuoteOpportunityContribution. No writer derives a
     * Quote-level label from it, so the field only ever showed an empty or
     * stale value.
     *
     * Removed from THIS panel only, on every install. The append path above
     * otherwise returns early and would keep the old entry forever. An admin
     * who placed the field on another panel keeps it.
     */
    private const RETIRED_PANEL_FIELDS = ['bd_governing_line'];

    private static function dropRetiredPanelFields(array &$panel): bool
    {
        if (empty($panel['fields']) || !is_array($panel['fields'])) {
            return false;
        }
        $kept = [];
        foreach ($panel['fields'] as $field) {
            $name = is_array($field) ? ($field['name'] ?? '') : $field;
            if (!in_array($name, self::RETIRED_PANEL_FIELDS, true)) {
                $kept[] = $field;
            }
        }
        if (count($kept) === count($panel['fields'])) {
            return false;
        }
        $panel['fields'] = $kept;
        return true;
    }
}
