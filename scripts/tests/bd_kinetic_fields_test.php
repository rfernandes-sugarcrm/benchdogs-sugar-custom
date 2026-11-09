<?php

/**
 * Bench Dogs' Kinetic fields, EXECUTED: which fields an install creates, how they are drawn on the three record views,
 * the Sales Category list, and the retired lines total (e_total_charges_c): no longer created, relabelled, never deleted.
 *
 * Run:  php scripts/tests/bd_kinetic_fields_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * Measured read-only on 2026-10-17 (server metadata): one Bench Dogs tenant holds all 23 fields as Studio fields, on
 * no record view; the other holds none. So an install creates exactly the fields a tenant lacks and never touches one
 * it has, and the record views draw whichever the tenant has. Since rc111 the order lines total is ERP-Epicor's own
 * erp_line_total: a new tenant gets no e_total_charges_c, and one that has it keeps it as "Line Total (old)". Only Sugar's BeanFactory, VardefManager and logger are
 * faked; the package's classes and its three view overlay files run as shipped.
 *
 * rc119 adds one field, approved by the owner on 2026-10-27: the quote's Due date (e_due_date_c, a date labelled "Due",
 * Kinetic's QuoteHed.DueDate both ways through the connector), never required, drawn editable in the quote's ERP panel.
 * rc120 (the owner's ruling of 2026-10-29): the Due is drawn right after Expires Date wherever that is drawn (the Bench
 * UAT layout draws it in the body), above the status block only when none of the top fields is drawn; the create form
 * draws the record view's panels (SugarEnt 26.1.0, recorded), so it shows there too.
 * rc121 (Bench Dogs: Valid Until and Kinetic's expiry are one date): Expires Date is no longer placed, and the Due is
 * drawn right after Valid Until; a Due a Studio save stored where rc119 or rc120 drew it moves there too. The overlay
 * that takes Expires Date off every quote view is bd_expires_date_off_test.php's.
 * rc122 adds one field, approved by the owner: the quote's Project Name (e_project_name_c, labelled "Project Name"),
 * which the connector fills from Kinetic's QuoteHed.QuoteComment and writes back. QuoteComment is free text with no
 * declared length, so the field is a text field (Studio's TextArea, as ERP-Core's own quote comment is), never a
 * varchar that would cut it short. Drawn editable, on a row of its own right after the Due, so the rows rc121 laid out
 * (Valid Until | Due, Best Case | Confidence) keep their pairs.
 *
 * IF IT WERE BROKEN you would see:
 *   "a tenant holding every field creates none" FAIL - an install would alter or duplicate a tenant's Studio fields;
 *   "a new tenant gets 24" FAIL - a field the read-backs write would be missing, or the retired lines total created
 *   again (rc110 created it, beside ERP-Epicor's own ERP Line Total);
 *   the Project Name checks - no field for Kinetic's QuoteComment, one too short for it (a varchar), required, with
 *   another label, read-only, drawn twice, away from the Due, or half a row wide (breaking rc121's row pairs), or a
 *   filler cell further down the panel taken, which shifts the rows after it;
 *   the Due checks - no Due field (the connector's read-back then has nowhere to write Kinetic's Due date), one that is
 *   not a date, is required, reads another label, is drawn read-only, twice, or away from Expires Date (rc120);
 *   the retired lines total checks - it keeps reading "ERP Line Total" (two fields with one label on the order), a
 *   label someone set by hand is overwritten, or an install gains a way to delete a field;
 *   "an unreadable module creates nothing" FAIL - fields created on a guess;
 *   "one failed create does not stop the next" FAIL - one bad field leaves the rest uncreated;
 *   the placement checks - a field drawn twice, in the wrong panel, editable when Kinetic owns it, or drawn where the
 *   tenant does not have it;
 *   the list checks - a tenant's own Sales Category list replaced, or no list where it has none.
 */

namespace {
    $checks = array();
    $failed = 0;

    function check(string $name, bool $ok, string $why = ''): void
    {
        global $checks, $failed;
        $checks[] = $name;
        if ($ok) {
            echo "  pass {$name}\n";
            return;
        }
        $failed++;
        echo "  FAIL {$name}" . ($why !== '' ? ": {$why}" : '') . "\n";
    }

    $GLOBALS['log'] = new class {
        public $lines = array();

        public function __call($level, $args)
        {
            $this->lines[] = $level . ': ' . (string) ($args[0] ?? '');
        }
    };

    class BeanFactory
    {
        public static function getObjectName($module)
        {
            return array('Quotes' => 'Quote', 'Products' => 'Product', 'ERP_Orders' => 'ERP_Order')[$module] ?? '';
        }
    }

    class VardefManager
    {
        public static function loadVardef($module, $object)
        {
        }
    }

    $root = dirname(__DIR__, 2) . '/sugar-sell/BenchDogs-Ext';
    require $root . '/custom/src/BenchDogs/BdKineticFields.php';
    require $root . '/custom/src/BenchDogs/BdStudioFields.php';
    require $root . '/custom/src/BenchDogs/BdFieldPlacement.php';

    /** The Studio calls, answered from memory: a tenant's field names, and every create asked for. */
    class FakeStudio extends \Sugarcrm\Sugarcrm\custom\BenchDogs\BdStudioFields
    {
        public $have;
        public $created = array();
        public $unreadable = array();
        public $refuse = array();

        public function __construct(array $have)
        {
            $this->have = $have;
        }

        public function fieldNames(string $module): array
        {
            if (in_array($module, $this->unreadable, true)) {
                throw new RuntimeException('the metadata read failed');
            }

            return array_merge(array('id', 'name'), $this->have[$module] ?? array());
        }

        public function create(string $module, string $name, array $spec): void
        {
            if (in_array($name, $this->refuse, true)) {
                throw new RuntimeException('Cannot create column');
            }
            $this->created[$module . '.' . $name] = $spec;
        }

        /** The tenant's current label text per module and key, and every label set asked for. */
        public $labels = array();
        public $setLabels = array();

        public function labelValue(string $module, string $key): ?string
        {
            return $this->labels[$module][$key] ?? null;
        }

        public function setLabel(string $module, string $key, string $value): void
        {
            $this->setLabels[$module . '.' . $key] = $value;
        }
    }

    $fields = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::FIELDS;
    $all = array();
    foreach ($fields as $module => $defs) {
        $all[$module] = array_keys($defs);
    }
    // The fields a tenant on rc100-rc110 holds: every field plus the order lines total those releases created.
    $held = $all;
    $held['ERP_Orders'][] = 'e_total_charges_c';

    // ── which fields an install creates ──────────────────────────────────────
    $every = new FakeStudio($held);
    $report = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::createMissing($every);
    check('a tenant holding every field creates none and keeps 24', $every->created === array()
        && count($report['kept']) === 24 && $report['failed'] === array(), json_encode($report));

    $none = new FakeStudio(array());
    $report = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::createMissing($none);
    check('a new tenant gets 24, and no e_total_charges_c (the lines total is ERP-Epicor\'s erp_line_total)',
        count($none->created) === 24 && count($report['created']) === 24 && $report['kept'] === array()
        && !isset($none->created['ERP_Orders.e_total_charges_c']), json_encode(array_keys($none->created)));
    check('the counts are 5 on Quotes, 17 on the line, 2 on the order', count($fields['Quotes']) === 5
        && count($fields['Products']) === 17 && count($fields['ERP_Orders']) === 2);
    $spec = $none->created['Quotes.e_project_name_c'] ?? array();
    check('rc122: the Project Name is a text field (Kinetic\'s QuoteComment has no declared length) labelled Project '
        . 'Name (LBL_E_PROJECT_NAME_C), with no size, places or list',
        ($spec['type'] ?? '') === 'text' && $spec['len'] === null && $spec['precision'] === null
        && $spec['options'] === null && $spec['label'] === 'LBL_E_PROJECT_NAME_C'
        && $spec['label_value'] === 'Project Name', json_encode($spec));
    $some = new FakeStudio(array('Quotes' => array('e_project_name_c')));
    $report = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::createMissing($some);
    check('rc122: a tenant that already has a Project Name keeps it as it is (not created again)',
        !isset($some->created['Quotes.e_project_name_c']) && in_array('Quotes.e_project_name_c', $report['kept'], true),
        json_encode($report['kept']));
    check('the order\'s two are Project Manager and Engineer', array_keys($fields['ERP_Orders'])
        === array('e_project_manager_c', 'e_engineer_c'), json_encode(array_keys($fields['ERP_Orders'])));
    $spec = $none->created['Quotes.e_confidence_pct_c'] ?? array();
    check('the confidence is a decimal 18 with 3 places, label LBL_E_CONFIDENCE_PCT_C', ($spec['type'] ?? '') === 'decimal'
        && $spec['len'] === 18 && $spec['precision'] === 3 && $spec['label'] === 'LBL_E_CONFIDENCE_PCT_C'
        && $spec['label_value'] === 'Confidence Percentage', json_encode($spec));
    $spec = $none->created['Products.e_sales_category_c'] ?? array();
    check('the sales category is an enum 100 on e_sales_category_list', ($spec['type'] ?? '') === 'enum'
        && $spec['len'] === 100 && $spec['options'] === 'e_sales_category_list', json_encode($spec));
    $spec = $none->created['Products.e_total_cost_c'] ?? array();
    check('the total cost is a currency 26 with 2 places', ($spec['type'] ?? '') === 'currency' && $spec['len'] === 26
        && $spec['precision'] === 2, json_encode($spec));
    $spec = $none->created['Quotes.e_expiration_date_c'] ?? array();
    check('the expiry is a date', ($spec['type'] ?? '') === 'date' && $spec['len'] === null, json_encode($spec));
    $spec = $none->created['Quotes.e_due_date_c'] ?? array();
    check('rc119: the Due is a date labelled Due (LBL_E_DUE_DATE_C), with no size, places or list',
        ($spec['type'] ?? '') === 'date' && $spec['len'] === null && $spec['precision'] === null
        && $spec['options'] === null && $spec['label'] === 'LBL_E_DUE_DATE_C' && $spec['label_value'] === 'Due',
        json_encode($spec));
    check('rc119: no field is created required (a blank Due saves)', array_filter($none->created, function ($spec) {
        return array_key_exists('required', $spec) || array_key_exists('default', $spec);
    }) === array(), json_encode($none->created['Quotes.e_due_date_c'] ?? null));
    $spec = $none->created['ERP_Orders.e_project_manager_c'] ?? array();
    check('the project manager is a varchar 100', ($spec['type'] ?? '') === 'varchar' && $spec['len'] === 100);

    // ── the retired order lines total: relabelled where it reads a label a release gave it, never deleted ──
    $live = new FakeStudio($held);
    $live->labels = array('ERP_Orders' => array('LBL_E_TOTAL_CHARGES_C' => 'ERP Line Total'));
    $relabel = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::relabelShipped($live);
    check('a tenant whose copy reads rc106\'s ERP Line Total gets Line Total (old)',
        $live->setLabels === array('ERP_Orders.LBL_E_TOTAL_CHARGES_C' => 'Line Total (old)'), json_encode($relabel));
    $old = new FakeStudio($held);
    $old->labels = array('ERP_Orders' => array('LBL_E_TOTAL_CHARGES_C' => 'Charges'));
    \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::relabelShipped($old);
    check('a tenant whose copy still reads rc100\'s Charges gets Line Total (old)',
        $old->setLabels === array('ERP_Orders.LBL_E_TOTAL_CHARGES_C' => 'Line Total (old)'), json_encode($old->setLabels));
    $own = new FakeStudio($held);
    $own->labels = array('ERP_Orders' => array('LBL_E_TOTAL_CHARGES_C' => 'Total Charges'));
    \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::relabelShipped($own);
    check('CONTROL: a label someone set by hand is kept', $own->setLabels === array(), json_encode($own->setLabels));
    $done = new FakeStudio($held);
    $done->labels = array('ERP_Orders' => array('LBL_E_TOTAL_CHARGES_C' => 'Line Total (old)'));
    \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::relabelShipped($done);
    check('a second install sets nothing', $done->setLabels === array(), json_encode($done->setLabels));
    $absent = new FakeStudio(array());
    \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::relabelShipped($absent);
    check('a tenant without the label is left alone', $absent->setLabels === array(), json_encode($absent->setLabels));
    $studio = array_map(function ($m) {
        return $m->getName();
    }, (new ReflectionClass('\\Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdStudioFields'))->getMethods(ReflectionMethod::IS_PUBLIC));
    sort($studio);
    check('an install can read, create and relabel a field, and has no way to delete one (the copy and its data stay)',
        $studio === array('create', 'fieldNames', 'labelValue', 'setLabel'), json_encode($studio));

    $some = new FakeStudio(array('Quotes' => array('e_confidence_pct_c')));
    \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::createMissing($some);
    check('a field the tenant has is never created again', !isset($some->created['Quotes.e_confidence_pct_c'])
        && isset($some->created['Quotes.e_best_case_pct_c']));

    $blind = new FakeStudio(array());
    $blind->unreadable = array('Products');
    $report = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::createMissing($blind);
    $onLine = array_filter(array_keys($blind->created), function ($k) {
        return strpos($k, 'Products.') === 0;
    });
    check('an unreadable module creates nothing and is reported', $onLine === array()
        && count(array_filter($report['failed'], function ($f) {
            return strpos($f, 'Products.') === 0;
        })) === 17 && count($blind->created) === 7, json_encode($report['failed']));

    $stubborn = new FakeStudio(array());
    $stubborn->refuse = array('e_gross_margin_c');
    $report = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields::createMissing($stubborn);
    check('one failed create does not stop the next, and is named', count($stubborn->created) === 23
        && count($report['failed']) === 1 && strpos($report['failed'][0], 'Products.e_gross_margin_c') === 0,
        json_encode($report['failed']));

    // ── how the record views draw them ───────────────────────────────────────
    $place = array('\\Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement', 'place');
    $defs = function (array $names) {
        $out = array('id' => array('type' => 'id'), 'name' => array('type' => 'name'));
        foreach ($names as $name) {
            $out[$name] = array('type' => 'decimal', 'vname' => 'LBL_' . strtoupper($name));
        }

        return $out;
    };
    $lineNames = array_keys(\Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::LINE_FIELDS);
    $erpPanel = array('name' => 'LBL_RECORDVIEW_PANEL_ERP', 'fields' => array('erp_material_cost', 'erp_labor_cost',
        array('name' => 'erp_writeback_status'), 'erp_writeback_msg'));
    $panels = array(array('name' => 'panel_header', 'header' => true, 'fields' => array('name')),
        array('name' => 'panel_body', 'fields' => array('quantity')), $erpPanel);

    $out = call_user_func($place, $panels, $defs($lineNames),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::LINE_FIELDS,
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::COST_CAPACITY_PANEL);
    $cost = end($out);
    $names = array_map(function ($e) {
        return is_array($e) ? $e['name'] : $e;
    }, $cost['fields'] ?? array());
    $expected = array_values(array_diff($lineNames, array('erp_material_cost', 'erp_labor_cost')));
    check('the line fields get their own Cost & capacity panel, in the mapping order', ($cost['name'] ?? '')
        === 'LBL_BD_PANEL_COST_CAPACITY' && $names === $expected, json_encode($names));
    check("ERP-Epicor's cost fields already drawn stay where they are, once", $out[2] === $erpPanel
        && !in_array('erp_material_cost', $names, true));
    $byName = array();
    foreach ($cost['fields'] as $entry) {
        $byName[$entry['name']] = $entry;
    }
    check("Kinetic's own line fields are read-only, the two the seller sends to Kinetic editable",
        !empty($byName['e_gross_margin_c']['readonly']) && !empty($byName['e_expected_qty_c']['readonly'])
        && !empty($byName['e_expected_qty_uom_c']['readonly']) && empty($byName['e_sales_category_c']['readonly'])
        && empty($byName['e_quantity_quoted_c']['readonly']) && !isset($byName['erp_material_burden']));
    check('a placed field carries its label', ($byName['e_total_cost_c']['label'] ?? '') === 'LBL_E_TOTAL_COST_C');

    $again = call_user_func($place, $out, $defs($lineNames),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::LINE_FIELDS,
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::COST_CAPACITY_PANEL);
    check('placing twice changes nothing (every metadata build runs it)', $again === $out);

    $tenantWithout = call_user_func($place, $panels, $defs(array('erp_material_cost', 'erp_labor_cost')),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::LINE_FIELDS,
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::COST_CAPACITY_PANEL);
    check('a tenant without the fields gets no panel and no change', $tenantWithout === $panels);

    $adminPlaced = $panels;
    $adminPlaced[1]['fields'][] = 'e_total_cost_c';
    $adminPlaced[1]['fields'][] = array('name' => 'combo', 'fields' => array('e_install_hours_c'));
    $out = call_user_func($place, $adminPlaced, $defs($lineNames),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::LINE_FIELDS,
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::COST_CAPACITY_PANEL);
    $costNames = array_map(function ($e) {
        return $e['name'];
    }, end($out)['fields']);
    check("an administrator's placement is kept (fieldset members too) and made read-only",
        !in_array('e_total_cost_c', $costNames, true) && !in_array('e_install_hours_c', $costNames, true)
        && ($out[1]['fields'][1] === array('name' => 'e_total_cost_c', 'readonly' => true)), json_encode($out[1]));

    $quote = call_user_func($place, $panels, $defs(array_keys(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS)),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    $erpNames = array_map(function ($e) {
        return is_array($e) ? $e['name'] : $e;
    }, $quote[2]['fields']);
    check('the quote header fields go in the ERP panel, above the status block; rc121: no Expires Date, and with no '
        . 'Valid Until drawn the Due right after the best case; rc122: the Project Name right after the Due',
        $erpNames === array('erp_material_cost', 'erp_labor_cost', 'e_best_case_pct_c', 'e_due_date_c',
        'e_project_name_c', 'e_confidence_pct_c', 'erp_writeback_status', 'erp_writeback_msg'), json_encode($erpNames));
    check('rc119: the Due (the seller sets it, Kinetic too) is editable, with its label',
        ($quote[2]['fields'][3] ?? null) === array('name' => 'e_due_date_c', 'label' => 'LBL_E_DUE_DATE_C'),
        json_encode($quote[2]['fields'][3] ?? null));
    check('rc122: the Project Name (the seller sets it, Kinetic too) is editable, with its label, a row of its own',
        ($quote[2]['fields'][4] ?? null) === array('name' => 'e_project_name_c', 'label' => 'LBL_E_PROJECT_NAME_C',
            'span' => 12), json_encode($quote[2]['fields'][4] ?? null));
    check('rc121: Expires Date is not placed; the best case (the seller sets it) and the confidence are editable',
        !in_array('e_expiration_date_c', $erpNames, true) && empty($quote[2]['fields'][2]['readonly'])
        && ($quote[2]['fields'][5]['name'] ?? '') === 'e_confidence_pct_c' && empty($quote[2]['fields'][5]['readonly']),
        json_encode($quote[2]['fields']));
    check('rc120: placing the quote fields twice changes nothing',
        call_user_func($place, $quote, $defs(array_keys(\Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS)),
            \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS) === $quote);
    $noSiblings = call_user_func($place, $panels, $defs(array('e_due_date_c')),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    check('rc120: a tenant with the Due and none of the other three: the Due right above the status block, as in rc119',
        array_map(function ($e) {
            return is_array($e) ? $e['name'] : $e;
        }, $noSiblings[2]['fields']) === array('erp_material_cost', 'erp_labor_cost', 'e_due_date_c', 'erp_writeback_status',
            'erp_writeback_msg'), json_encode($noSiblings[2]['fields']));
    $noExpiry = $panels;
    $noExpiry[1]['fields'] = array('quantity', array('name' => 'e_best_case_pct_c'), 'x');
    $noExpiryOut = call_user_func($place, $noExpiry, $defs(array('e_due_date_c', 'e_best_case_pct_c')),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    check('rc121: a view drawing no Valid Until: the Due right after the next of the top fields it draws',
        $noExpiryOut[1]['fields'] === array('quantity', array('name' => 'e_best_case_pct_c'),
            array('name' => 'e_due_date_c', 'label' => 'LBL_E_DUE_DATE_C'), 'x')
        && $noExpiryOut[2] === $panels[2], json_encode($noExpiryOut));
    $storedBestCase = $panels;
    $storedBestCase[1]['fields'][] = array('name' => 'e_best_case_pct_c', 'label' => 'LBL_E_BEST_CASE_PCT_C');
    $quoteStored = call_user_func($place, $storedBestCase, $defs(array_keys(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS)),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    check('rc117: a best case a stored view already draws (the Bench UAT layout) is left editable, where it is; '
        . 'rc121: with no Valid Until drawn the Due follows it; rc122: the Project Name follows the Due',
        array_slice($quoteStored[1]['fields'], 1) === array(
            array('name' => 'e_best_case_pct_c', 'label' => 'LBL_E_BEST_CASE_PCT_C'),
            array('name' => 'e_due_date_c', 'label' => 'LBL_E_DUE_DATE_C'),
            array('name' => 'e_project_name_c', 'label' => 'LBL_E_PROJECT_NAME_C', 'span' => 12)),
        json_encode($quoteStored[1]['fields']));

    // The Bench UAT quote layout as BenchDogs-UAT-Config ships it: Valid Until, Expires Date and the other two in its
    // body, not the Due.
    $viewdefs = array();
    include dirname(__DIR__, 2)
        . '/sugar-sell/BenchDogs-UAT-Config/custom/modules/Quotes/clients/base/views/record/record.php';
    $uatPanels = $viewdefs['Quotes']['base']['view']['record']['panels'];
    $uatNames = function (array $panel) {
        return array_map(function ($e) {
            return is_array($e) ? ($e['name'] ?? '') : $e;
        }, $panel['fields'] ?? array());
    };
    $uatPanelOf = function (array $panels, string $name) use ($uatNames) {
        foreach ($panels as $panel) {
            if (in_array($name, $uatNames($panel), true)) {
                return $panel['name'] ?? '';
            }
        }

        return null;
    };
    $uat = call_user_func($place, $uatPanels, $defs(array_keys(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS)),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    $erpPanelNames = array();
    foreach ($uat as $panel) {
        if (($panel['name'] ?? '') === 'LBL_RECORDVIEW_PANEL_ERP') {
            $erpPanelNames = $uatNames($panel);
        }
    }
    $uatBody = array();
    $uatBodyIn = array();
    foreach ($uat as $i => $panel) {
        if (($panel['name'] ?? '') === 'panel_body') {
            $uatBody = $panel['fields'];
            $uatBodyIn = $uatPanels[$i]['fields'];
        }
    }
    // rc121: Expires Date is not one of the placed fields, so place() leaves it as stored (the overlay takes it off).
    $uatBodyNames = $uatNames(array('fields' => $uatBody));
    $at = array_search('date_quote_expected_closed', $uatBodyNames, true);
    check('rc121: on the Bench UAT layout the Due is drawn in the body, right after Valid Until, editable, with its label',
        $uatPanelOf($uatPanels, 'e_due_date_c') === null && $at !== false
        && ($uatBody[$at + 1] ?? null) === array('name' => 'e_due_date_c', 'label' => 'LBL_E_DUE_DATE_C'),
        json_encode($uatBodyNames));
    check('rc121: ...and nowhere else: not in the ERP panel, once in the whole view',
        !in_array('e_due_date_c', $erpPanelNames, true)
        && count(array_keys(array_merge(...array_map($uatNames, $uat)), 'e_due_date_c', true)) === 1,
        json_encode(array_map($uatNames, $uat)));
    $projectName = array('name' => 'e_project_name_c', 'label' => 'LBL_E_PROJECT_NAME_C', 'span' => 12);
    check('rc122: on the Bench UAT layout the Project Name is drawn in the body right after the Due, editable, with its '
        . 'label, a row of its own', $uatPanelOf($uatPanels, 'e_project_name_c') === null && $at !== false
        && ($uatBody[$at + 2] ?? null) === $projectName, json_encode($uatBody[$at + 2] ?? null));
    check('rc122: ...and nowhere else: not in the ERP panel, once in the whole view',
        !in_array('e_project_name_c', $erpPanelNames, true)
        && count(array_keys(array_merge(...array_map($uatNames, $uat)), 'e_project_name_c', true)) === 1,
        json_encode(array_map($uatNames, $uat)));
    $bodyWithoutDue = $uatBody;
    array_splice($bodyWithoutDue, $at + 1, 2);
    check('rc121: the body keeps its other cells in order; the empty filler cell at its end gives the Due its place; '
        . 'rc122: the Project Name, a row of its own, adds one cell and moves none',
        array_merge($bodyWithoutDue, array(array())) === $uatBodyIn && count($uatBody) === count($uatBodyIn) + 1,
        json_encode(array(count($uatBody), count($uatBodyIn))));
    check('rc119: on the Bench UAT layout the other three stay where it draws them, and nothing is drawn twice',
        $uatPanelOf($uat, 'e_best_case_pct_c') === 'panel_body' && $uatPanelOf($uat, 'e_confidence_pct_c') === 'panel_body'
        && $uatPanelOf($uat, 'e_expiration_date_c') === 'panel_body', json_encode(array_map($uatNames, $uat)));
    $others = function (array $panels) {
        foreach ($panels as $i => $panel) {
            if (($panel['name'] ?? '') === 'panel_body') {
                unset($panels[$i]);
            }
        }

        return array_values($panels);
    };
    check('rc120: on the Bench UAT layout every other panel is served as stored', $others($uat) === $others($uatPanels));
    check('rc120: placing twice on the Bench UAT layout changes nothing', call_user_func($place, $uat, $defs(array_keys(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS)),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS) === $uat);

    // A layout a Studio save stored while rc119 drew the Due in the ERP panel: the Due moves up beside Expires Date.
    $studioRc119 = $uatPanels;
    foreach ($studioRc119 as $i => $panel) {
        if (($panel['name'] ?? '') === 'LBL_RECORDVIEW_PANEL_ERP') {
            $erpAt = array_search('erp_writeback_status', $uatNames($panel), true);
            array_splice($studioRc119[$i]['fields'], $erpAt, 0, array(array('name' => 'e_due_date_c', 'label' => 'LBL_E_DUE_DATE_C')));
        }
    }
    $fromStudio = call_user_func($place, $studioRc119, $defs(array_keys(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS)),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    check('rc121: a stored layout holding the Due in the ERP panel (a Studio save under rc119) draws it after Valid Until',
        $fromStudio === $uat, json_encode(array_map($uatNames, $fromStudio)));
    // A layout a Studio save stored while rc120 drew the Due right after Expires Date (the filler gone): it moves too.
    $studioRc120 = $uatPanels;
    foreach ($studioRc120 as $i => $panel) {
        if (($panel['name'] ?? '') === 'panel_body') {
            $cells = $panel['fields'];
            array_pop($cells);
            $expiresAt = array_search('e_expiration_date_c', $uatNames(array('fields' => $cells)), true);
            array_splice($cells, $expiresAt + 1, 0, array(array('name' => 'e_due_date_c', 'label' => 'LBL_E_DUE_DATE_C')));
            $studioRc120[$i]['fields'] = $cells;
        }
    }
    $fromRc120 = call_user_func($place, $studioRc120, $defs(array_keys(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS)),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    check('rc121: a stored layout holding the Due right after Expires Date (a Studio save under rc120) draws it after '
        . 'Valid Until', $fromRc120 === $uat, json_encode(array_map($uatNames, $fromRc120)));
    // An administrator's own place for the Due outside the ERP panel is kept.
    $adminDue = $uatPanels;
    foreach ($adminDue as $i => $panel) {
        if (($panel['name'] ?? '') === 'panel_hidden') {
            $adminDue[$i]['fields'][] = 'e_due_date_c';
        }
    }
    // The view less this release's Project Name cell, to compare the rest with what was stored.
    $withoutProjectName = function (array $panels) {
        foreach ($panels as $i => $panel) {
            $panels[$i]['fields'] = array_values(array_filter($panel['fields'] ?? array(), function ($e) {
                return !(is_array($e) && ($e['name'] ?? '') === 'e_project_name_c');
            }));
        }

        return $panels;
    };
    $after = function (array $panels, string $name) use ($uatNames) {
        foreach ($panels as $panel) {
            $cells = $uatNames($panel);
            $i = array_search($name, $cells, true);
            if ($i !== false) {
                return array($panel['name'] ?? '', $cells[$i + 1] ?? null);
            }
        }

        return null;
    };
    $adminDueOut = call_user_func($place, $adminDue, $defs(array_keys(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS)),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    check('rc120: a Due an administrator placed in another panel stays there, as placed; rc122: the Project Name '
        . 'follows it there', $withoutProjectName($adminDueOut) === $adminDue
        && $after($adminDueOut, 'e_due_date_c') === array('panel_hidden', 'e_project_name_c'),
        json_encode(array_map($uatNames, $adminDueOut)));
    $inSet = $uatPanels;
    $inSet[1]['fields'][] = array('name' => 'dates', 'type' => 'fieldset', 'fields' => array('e_due_date_c'));
    $inSetOut = call_user_func($place, $inSet, $defs(array_keys(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS)),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    check('rc120: a Due drawn inside a fieldset is left as it is; rc122: the Project Name then follows Valid Until',
        $withoutProjectName($inSetOut) === $inSet
        && $after($inSetOut, 'date_quote_expected_closed') === array('panel_body', 'e_project_name_c'),
        json_encode(array_map($uatNames, $inSetOut)));

    // rc122: a Project Name already on the stored view (an administrator's place, or a Studio save) stays where it is.
    $adminName = $uatPanels;
    $adminName[1]['fields'][] = array('name' => 'e_project_name_c');
    $adminNameOut = call_user_func($place, $adminName, $defs(array_keys(
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS)),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    check('rc122: a Project Name an administrator placed is kept where it is, as placed, and drawn once',
        end($adminNameOut[1]['fields']) === array('name' => 'e_project_name_c')
        && count(array_keys(array_merge(...array_map($uatNames, $adminNameOut)), 'e_project_name_c', true)) === 1,
        json_encode(array_map($uatNames, $adminNameOut)));
    // rc122: a row of its own takes no filler cell: one further down the panel pads its own row, which stays as it is.
    $filler = array(
        array('name' => 'panel_body', 'columns' => 2, 'fields' => array('quote_num', 'date_quote_expected_closed',
            array('name' => 'e_due_date_c', 'label' => 'LBL_E_DUE_DATE_C'), 'a', array(), 'b', 'c')),
        array('name' => 'LBL_RECORDVIEW_PANEL_ERP', 'columns' => 2, 'fields' => array('erp_writeback_status')),
    );
    $fillerOut = call_user_func($place, $filler, $defs(array('e_due_date_c', 'e_project_name_c')),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::QUOTE_FIELDS);
    check('rc122: the Project Name after the Due takes no filler cell further down (the rows after it keep their cells)',
        $fillerOut[0]['fields'] === array('quote_num', 'date_quote_expected_closed',
            array('name' => 'e_due_date_c', 'label' => 'LBL_E_DUE_DATE_C'), $projectName, 'a', array(), 'b', 'c')
        && $fillerOut[1] === $filler[1], json_encode($fillerOut));

    // The create form draws the record view's panels (SugarEnt 26.1.0, recorded under fixtures/real-sidecar/).
    $sugar = __DIR__ . '/fixtures/real-sidecar/sugar-sell/ERP-Core/tests/support/sugar-26.1';
    $baseCreate = json_decode((string) file_get_contents("$sugar/clients/base/views/create/create.php.json"), true);
    $createJs = (string) file_get_contents("$sugar/clients/base/views/create/create.js");
    $quotesCreateJs = (string) file_get_contents("$sugar/modules/Quotes/clients/base/views/create/create.js");
    check('rc120: the create form takes the record view\'s panels: the base create view has none of its own, and '
        . 'CreateView lays the record view under it',
        array_keys($baseCreate['viewdefs']['base']['view']['create'] ?? array()) === array('template', 'buttons')
        && strpos($createJs, "this.meta = _.extend({}, app.metadata.getView(this.module, 'record'), this.meta);") !== false);
    check('rc120: ...and the Quotes create view sets no panels of its own (so the Due shows on create where it shows '
        . 'on the record)', $quotesCreateJs !== '' && preg_match('/this\.meta\s*=|meta\.panels\s*=/', $quotesCreateJs) === 0);

    $orderPanels = array(array('name' => 'panel_header', 'header' => true, 'fields' => array('name')),
        array('name' => 'panel_body', 'fields' => array('order_total')));
    $order = call_user_func($place, $orderPanels, $defs($held['ERP_Orders']),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::ORDER_FIELDS);
    check('the order fields go in the body when there is no ERP panel, read-only, and never the retired lines total',
        array_map(function ($e) {
            return is_array($e) ? $e['name'] . (empty($e['readonly']) ? '' : ' ro') : $e;
        }, $order[1]['fields']) === array('order_total', 'e_project_manager_c ro', 'e_engineer_c ro'),
        json_encode($order[1]['fields']));

    check('nothing is placed when the vardefs could not be read', call_user_func($place, $panels, array(),
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::LINE_FIELDS, 'LBL_BD_PANEL_COST_CAPACITY') === $panels);

    // The three shipped overlays, run as Sugar runs them, over a tenant that holds every field (the retired one too).
    foreach (array('Quotes' => 'Quote', 'Products' => 'Product', 'ERP_Orders' => 'ERP_Order') as $module => $object) {
        $GLOBALS['dictionary'][$object]['fields'] = $defs(array_merge(array('erp_material_cost', 'erp_labor_cost'),
            $held[$module]));
        $viewdefs = array($module => array('base' => array('view' => array('record' => array('panels' => $panels)))));
        include $root . "/custom/Extension/modules/{$module}/Ext/clients/base/views/record/bd_kinetic_fields.php";
        $drawn = array();
        foreach ($viewdefs[$module]['base']['view']['record']['panels'] as $panel) {
            foreach ($panel['fields'] as $entry) {
                $drawn[] = is_array($entry) ? $entry['name'] : $entry;
            }
        }
        // rc121: the quote view draws no Expires Date (Valid Until is the same date); the field stays the tenant's.
        $off = $module === 'Quotes' ? array('e_expiration_date_c') : array();
        check("the shipped {$module} overlay draws every field the tenant has, once, and not the retired lines total"
            . ($off ? ' nor Expires Date' : ''),
            array_diff($all[$module], $drawn, $off) === array() && count($drawn) === count(array_unique($drawn))
            && !in_array('e_total_charges_c', $drawn, true) && array_intersect($off, $drawn) === array(),
            json_encode($drawn));
    }

    // ── the Sales Category list ──────────────────────────────────────────────
    $list = $root . '/custom/Extension/application/Ext/Language/_override_en_us.bd_sales_category_list.php';
    $app_list_strings = array('e_sales_category_list' => array('' => '', 'X' => 'A tenant list'));
    include $list;
    check("a tenant's own Sales Category list is kept", $app_list_strings['e_sales_category_list']
        === array('' => '', 'X' => 'A tenant list'));
    $app_list_strings = array();
    include $list;
    $codes = array_keys($app_list_strings['e_sales_category_list'] ?? array());
    check("where there is none, ADM's codes are the keys (the four recent quote lines use among them)",
        array_diff(array('PRTN', 'DISP', 'RETL', 'DRRT'), $codes) === array() && in_array('', $codes, true),
        json_encode($codes));

    echo "\n" . count($checks) . " checks, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
