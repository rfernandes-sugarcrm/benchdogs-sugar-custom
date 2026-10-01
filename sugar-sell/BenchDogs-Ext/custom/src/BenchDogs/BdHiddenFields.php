<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

use Throwable;

/** G848 (🔒2151b, 🔒2159b): Bench Dogs sellers see no discount; the sidecar view overlays strip these at every metadata build, nothing stored changes. */
class BdHiddenFields
{
    /** ERP-Epicor's seller discount control, and the quote-level discount figures. */
    public const QUOTE_FIELDS = array(
        'erp_discount_panel',
        'deal_tot',
        'deal_tot_usdollar',
        'deal_tot_discount_percentage',
        'discount',
        'erp_document_discount_amount',
        'erp_document_discount_percent',
    );

    /** The panel ERP-Epicor builds around erp_discount_panel, taken off once empty. */
    public const QUOTE_PANELS = array('LBL_RECORDVIEW_PANEL_ERP_DISCOUNT');

    /** The line discount and its derived figures; never discount_price or discount_usdollar (the Unit Price). */
    public const LINE_FIELDS = array(
        'discount_field',
        'discount_amount',
        'discount_select',
        'discount_rate_percent',
        'discount_amount_usdollar',
        'discount_amount_signed',
        'deal_calc',
        'deal_calc_usdollar',
    );

    /**
     * The panels without the named fields (a fieldset one level down too) and without a named panel left empty; exactly as read when nothing matched or anything failed.
     * @param string[] $names
     * @param string[] $panelNames
     */
    public static function strip(array $panels, array $names, array $panelNames): array
    {
        try {
            $changed = false;
            $kept = array();
            foreach ($panels as $panel) {
                if (is_array($panel) && isset($panel['fields']) && is_array($panel['fields'])) {
                    $fields = array();
                    $dropped = false;
                    foreach ($panel['fields'] as $entry) {
                        if (in_array(self::nameOf($entry), $names, true)) {
                            $dropped = true;
                            continue;
                        }
                        // A fieldset, one level down; never related_fields, which the view reads rather than draws.
                        if (is_array($entry) && isset($entry['fields']) && is_array($entry['fields'])) {
                            $members = array();
                            $memberDropped = false;
                            foreach ($entry['fields'] as $member) {
                                if (in_array(self::nameOf($member), $names, true)) {
                                    $memberDropped = true;
                                    continue;
                                }
                                $members[] = $member;
                            }
                            if ($memberDropped) {
                                $dropped = true;
                                if ($members === array()) {
                                    continue;
                                }
                                $entry['fields'] = $members;
                            }
                        }
                        $fields[] = $entry;
                    }
                    if ($dropped) {
                        // Rebuilt by appending, so it stays a list: a gap in the keys reaches Sidecar as a JSON object.
                        $panel['fields'] = $fields;
                        $changed = true;
                    }
                }
                $panelName = (is_array($panel) && isset($panel['name']) && is_string($panel['name'])) ? $panel['name'] : '';
                if ($panelName !== '' && in_array($panelName, $panelNames, true)
                    && (!isset($panel['fields']) || $panel['fields'] === array())) {
                    $changed = true;
                    continue;
                }
                $kept[] = $panel;
            }

            return $changed ? $kept : $panels;
        } catch (Throwable $e) {
            // A layout rule never fails a metadata build: the view stays exactly as read.
            if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
                $GLOBALS['log']->error('BenchDogs-Ext: discounts not hidden: ' . $e->getMessage());
            }

            return $panels;
        }
    }

    /** A view entry's field name, '' when it has none. */
    private static function nameOf($entry): string
    {
        if (is_string($entry)) {
            return $entry;
        }

        return (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) ? $entry['name'] : '';
    }
}
