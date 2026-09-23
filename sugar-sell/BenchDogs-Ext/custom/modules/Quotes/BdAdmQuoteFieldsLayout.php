<?php

use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * G380 / G381 (🔒 1705b): places the four Bench Dogs ADM fields (Reference,
 * Lead Source, Lead Type, Project) on the DEPLOYED Quotes record view at
 * install time, and takes them off again on uninstall. Nothing else.
 *
 * WHERE: the ERP panel ERP-Epicor's QuotesLayout deploys
 * (LBL_RECORDVIEW_PANEL_ERP), beside Billing Terms and Ship Via, because they
 * are values the ERP document needs. If that panel is absent, the stock
 * panel_body; failing that, the first panel that holds fields.
 *
 * APPEND ONLY, the BdAccountsLayoutExtensions rule: ERP-Epicor's QuotesLayout
 * owns this view, so this class never reorders, removes or rewrites anything
 * it did not add. QuotesLayout's own reinstall reconciles only the fields it
 * names (BaseErpLayout::reconcileFieldsInRecordViewPanel), so an appended
 * field survives it. Install order stays ERP-Epicor first.
 *
 * Idempotent: a field already on any panel is not added twice. A new name,
 * not the retired BdQuotesLayoutExtensions (G280 forbids reaching for it).
 */
class BdAdmQuoteFieldsLayout
{
    public const ERP_PANEL = 'LBL_RECORDVIEW_PANEL_ERP';

    /** The four fields, in the order a seller fills them. */
    public const FIELDS = array(
        array('name' => 'bd_lead_source', 'label' => 'LBL_BD_LEAD_SOURCE'),
        array('name' => 'bd_lead_type', 'label' => 'LBL_BD_LEAD_TYPE'),
        array('name' => 'bd_reference', 'label' => 'LBL_BD_REFERENCE'),
        array('name' => 'bd_project_id', 'label' => 'LBL_BD_PROJECT_ID'),
    );

    /** Returns the field names it added (empty when all four were there). */
    public static function place(): array
    {
        $defs = (new ViewdefManager())->loadViewdef('base', 'Quotes', 'record');
        if (empty($defs['panels']) || !is_array($defs['panels'])) {
            $GLOBALS['log']->error('BenchDogs-Ext: Quotes record view has no panels; ADM fields not placed');
            return array();
        }
        $panels =& $defs['panels'];

        $present = array();
        foreach ($panels as $panel) {
            foreach ((array) ($panel['fields'] ?? array()) as $field) {
                $present[is_array($field) ? (string) ($field['name'] ?? '') : (string) $field] = true;
            }
        }
        $missing = array();
        foreach (self::FIELDS as $field) {
            if (!isset($present[$field['name']])) {
                $missing[] = $field;
            }
        }
        if ($missing === array()) {
            return array();
        }

        $target = self::targetPanel($panels);
        if ($target === null) {
            $GLOBALS['log']->error('BenchDogs-Ext: no Quotes panel can hold fields; ADM fields not placed');
            return array();
        }
        foreach ($missing as $field) {
            $panels[$target]['fields'][] = $field;
        }
        unset($panels);

        self::deploy($defs);

        $added = array();
        foreach ($missing as $field) {
            $added[] = $field['name'];
        }

        return $added;
    }

    /** Takes the four fields off every panel. Returns how many entries it removed. */
    public static function remove(): int
    {
        $defs = (new ViewdefManager())->loadViewdef('base', 'Quotes', 'record');
        if (empty($defs['panels']) || !is_array($defs['panels'])) {
            return 0;
        }
        $drop = array();
        foreach (self::FIELDS as $field) {
            $drop[$field['name']] = true;
        }
        $removed = 0;
        foreach ($defs['panels'] as $i => $panel) {
            if (empty($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }
            $kept = array();
            foreach ($panel['fields'] as $field) {
                $name = is_array($field) ? (string) ($field['name'] ?? '') : (string) $field;
                if (isset($drop[$name])) {
                    $removed++;
                    continue;
                }
                $kept[] = $field;
            }
            $defs['panels'][$i]['fields'] = $kept;
        }
        if ($removed > 0) {
            self::deploy($defs);
        }

        return $removed;
    }

    private static function targetPanel(array $panels): ?int
    {
        $body = null;
        $first = null;
        foreach ($panels as $i => $panel) {
            if (!isset($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }
            if (($panel['name'] ?? '') === self::ERP_PANEL) {
                return $i;
            }
            if ($body === null && ($panel['name'] ?? '') === 'panel_body') {
                $body = $i;
            }
            if ($first === null) {
                $first = $i;
            }
        }

        return $body ?? $first;
    }

    private static function deploy(array $defs): void
    {
        (new ViewdefManager())->saveViewdef($defs, 'Quotes', 'base', 'record');
        MetaDataFiles::clearModuleClientCache('Quotes', 'view');
        MetaDataFiles::clearModuleClientCache('Quotes', 'layout');
        include_once 'include/TemplateHandler/TemplateHandler.php';
        TemplateHandler::clearCache('Quotes');
    }
}
