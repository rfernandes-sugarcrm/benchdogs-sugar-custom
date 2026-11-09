<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

use Throwable;

/**
 * Bench Dogs' Kinetic fields (owner-approved mapping, 2026-10-17, then the quote's Due and project name), created as
 * Studio fields where a tenant lacks them. None is required, so a blank always saves. A field a tenant has is never
 * altered (a retired one only relabelled); uninstall leaves them all, data included.
 */
class BdKineticFields
{
    /** The drop-down list e_sales_category_c draws from (this package's language fragment defines it where absent). */
    public const SALES_CATEGORY_LIST = 'e_sales_category_list';

    /**
     * module => field => [type, len, precision, label key, label]. Types, sizes and the list are the existing Studio
     * fields' (decimal 18, currency 26, varchar 50 or 100, enum 100); places are the mapping's (2, or 3 for the
     * confidence); labels are its Kinetic field names, except the two the customer named: "Due" and "Project Name".
     */
    public const FIELDS = array(
        'Quotes' => array(
            'e_expiration_date_c' => array('date', null, null, 'Expires Date'),
            'e_best_case_pct_c' => array('decimal', 18, 2, 'Best Case Percentage'),
            'e_confidence_pct_c' => array('decimal', 18, 3, 'Confidence Percentage'),
            // Kinetic's QuoteHed.DueDate, the date the quote needs to be quoted by; the connector syncs it both ways.
            'e_due_date_c' => array('date', null, null, 'Due'),
            // Kinetic's QuoteHed.QuoteComment, where the customer keeps the quote's project name; the connector syncs it
            // both ways. Free text with no declared length, so a text field (Studio's TextArea, the type ERP-Core gives
            // its own copy of a quote comment): Sugar refuses a longer value rather than cutting it short.
            'e_project_name_c' => array('text', null, null, 'Project Name'),
        ),
        'Products' => array(
            'e_expected_qty_c' => array('decimal', 18, 2, 'Expected Quantity'),
            'e_expected_qty_uom_c' => array('varchar', 50, null, 'Expected Qty UOM'),
            'e_sales_category_c' => array('enum', 100, null, 'Sales Category'),
            'e_quantity_quoted_c' => array('decimal', 18, 2, 'Quantity Quoted'),
            'e_gross_margin_c' => array('currency', 26, 2, 'Gross Margin'),
            'e_est_engineering_hours_c' => array('decimal', 18, 2, 'Estimated Engineering Hours'),
            'e_material_pct_c' => array('decimal', 18, 2, 'Material %'),
            'e_labor_pct_c' => array('decimal', 18, 2, 'Labor %'),
            'e_material_labor_pct_c' => array('decimal', 18, 2, 'Material and Labor %'),
            'e_machine_hours_c' => array('decimal', 18, 2, 'Machine Hours'),
            'e_finish_hours_c' => array('decimal', 18, 2, 'Finish Hours'),
            'e_assembly_hours_c' => array('decimal', 18, 2, 'Assembly Hours'),
            'e_case_goods_hours_c' => array('decimal', 18, 2, 'Case Goods Hours'),
            'e_install_hours_c' => array('decimal', 18, 2, 'Install Hours'),
            'e_total_cost_c' => array('currency', 26, 2, 'Total Cost'),
            'e_total_markup_c' => array('currency', 26, 2, 'Total Markup'),
            'e_total_markup_price_c' => array('currency', 26, 2, 'Total Markup Price'),
        ),
        'ERP_Orders' => array(
            'e_project_manager_c' => array('varchar', 100, null, 'Project Manager'),
            'e_engineer_c' => array('varchar', 100, null, 'Engineer'),
        ),
    );

    /**
     * Fields an earlier release created that this one no longer does: module => field => [labels a release gave it,
     * its label now]. A tenant keeps the field and its data; the views stop drawing it (BdFieldPlacement::retire) and
     * the install relabels it only while it reads one of those labels, so a label someone set by hand is kept.
     */
    public const RETIRED = array(
        'ERP_Orders' => array(
            // The order lines total: ERP-Epicor's own erp_line_total, labelled 'ERP Line Total', holds it now.
            'e_total_charges_c' => array(array('Charges', 'ERP Line Total'), 'Line Total (old)'),
        ),
    );

    /** The label key Studio gives a custom field: LBL_ and its name in capitals. */
    public static function labelKey(string $field): string
    {
        return 'LBL_' . strtoupper($field);
    }

    /**
     * Relabel each RETIRED field whose label still reads one a release gave it, and say what happened: relabeled, kept
     * (it reads something else, or nothing), failed (with why). One failure never stops the others.
     * @return array{relabeled: string[], kept: string[], failed: string[]}
     */
    public static function relabelShipped(BdStudioFields $sugar): array
    {
        $report = array('relabeled' => array(), 'kept' => array(), 'failed' => array());
        foreach (self::RETIRED as $module => $fields) {
            foreach ($fields as $name => $labels) {
                list($from, $to) = $labels;
                $key = self::labelKey($name);
                try {
                    if (in_array($sugar->labelValue($module, $key), $from, true)) {
                        $sugar->setLabel($module, $key, $to);
                        $report['relabeled'][] = $module . '.' . $name;
                    } else {
                        $report['kept'][] = $module . '.' . $name;
                    }
                } catch (Throwable $e) {
                    $report['failed'][] = $module . '.' . $name . ' (' . $e->getMessage() . ')';
                }
            }
        }
        return $report;
    }

    /**
     * The fields to create: each one FIELDS names that $existing (module => field names the tenant has) does not.
     * @param array<string, string[]> $existing
     * @return array<string, array<string, array>>
     */
    public static function missing(array $existing): array
    {
        $out = array();
        foreach (self::FIELDS as $module => $fields) {
            $have = array_flip($existing[$module] ?? array());
            foreach ($fields as $name => $def) {
                if (!isset($have[$name])) {
                    $out[$module][$name] = $def;
                }
            }
        }

        return $out;
    }

    /**
     * Create every missing field through Sugar's own Studio field code (DynamicField and its field templates), and say
     * what happened: created, kept (the tenant has it), failed (with why). One failure never stops the others.
     * $sugar answers the three Sugar calls this needs, so the rule is the same code in a test and on a tenant.
     * @return array{created: string[], kept: string[], failed: string[]}
     */
    public static function createMissing(BdStudioFields $sugar): array
    {
        $report = array('created' => array(), 'kept' => array(), 'failed' => array());
        $existing = array();
        foreach (array_keys(self::FIELDS) as $module) {
            try {
                $existing[$module] = $sugar->fieldNames($module);
            } catch (Throwable $e) {
                // Unknown is never "absent": nothing is created on a module whose fields could not be read.
                foreach (array_keys(self::FIELDS[$module]) as $name) {
                    $report['failed'][] = $module . '.' . $name . ' (its fields could not be read: '
                        . $e->getMessage() . ')';
                }
                $existing[$module] = array_keys(self::FIELDS[$module]);
                continue;
            }
            foreach (array_keys(self::FIELDS[$module]) as $name) {
                if (in_array($name, $existing[$module], true)) {
                    $report['kept'][] = $module . '.' . $name;
                }
            }
        }
        foreach (self::missing($existing) as $module => $fields) {
            foreach ($fields as $name => $def) {
                list($type, $len, $precision, $label) = $def;
                try {
                    $sugar->create($module, $name, array(
                        'type' => $type,
                        'len' => $len,
                        'precision' => $precision,
                        'options' => $type === 'enum' ? self::SALES_CATEGORY_LIST : null,
                        'label' => self::labelKey($name),
                        'label_value' => $label,
                    ));
                    $report['created'][] = $module . '.' . $name;
                } catch (Throwable $e) {
                    $report['failed'][] = $module . '.' . $name . ' (' . $e->getMessage() . ')';
                }
            }
        }

        return $report;
    }
}
