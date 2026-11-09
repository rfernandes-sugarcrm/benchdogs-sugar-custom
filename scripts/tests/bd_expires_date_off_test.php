<?php

/**
 * rc121: the quote's Expires Date (e_expiration_date_c) off every quote view, the Due right after Valid Until, EXECUTED.
 *
 * Run:  php scripts/tests/bd_expires_date_off_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * Bench Dogs treat Sugar's Valid Until (date_quote_expected_closed) and Kinetic's QuoteHed.ExpirationDate as one date,
 * and the connector already carries Valid Until to ExpirationDate and back, so the second date on the quote goes. Its
 * field and data stay: the Bench connector extension still writes it, and core refuses a record naming a field the
 * tenant lacks. The Due, which rc120 drew right after Expires Date, is drawn right after Valid Until instead.
 *
 * What runs: this package's shipped view overlays for the Quotes record, recorddashlet, preview and list views, in the
 * order Sugar compiles a directory's files (ModuleInstaller::mergeExtensionFiles, 26.1: every file in name order, the
 * _override ones last), over the views BenchDogs-UAT-Config stores on the Bench UAT Sugars. Only Sugar's BeanFactory,
 * VardefManager and logger are faked.
 *
 * rc122: the quote's Project Name (e_project_name_c, Kinetic's QuoteHed.QuoteComment) is drawn by the same overlay, on a
 * row of its own right after Valid Until | Due, so the rows below keep their pairs (R13 to R16).
 *
 * IF IT WERE BROKEN you would see:
 *   "Expires Date is drawn nowhere" FAIL - a view still draws the second date;
 *   R13 to R16 FAIL - no Project Name on the quote record view (so none on the create form), one drawn twice, away
 *   from the Due, read-only, half a row wide (Best Case and Confidence no longer share a row), or on ERP-Epicor's own
 *   layout somewhere else;
 *   "the Due beside Valid Until" FAIL - the Due drawn away from Valid Until, twice, or read-only;
 *   "a Due rc119 or rc120 stored" FAIL - a Studio-saved layout keeps the Due where the earlier release drew it;
 *   "the other cells keep their order" FAIL - the overlay moved or dropped another field;
 *   "the field stays" FAIL - an install stops creating it, relabels it, or a release retires its data.
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
            return array('Quotes' => 'Quote')[$module] ?? '';
        }
    }

    class VardefManager
    {
        public static function loadVardef($module, $object)
        {
        }
    }

    $root = dirname(__DIR__, 2) . '/sugar-sell/BenchDogs-Ext';
    $uatRoot = dirname(__DIR__, 2) . '/sugar-sell/BenchDogs-UAT-Config/custom/modules/Quotes/clients/base/views';
    foreach (array('BdAdmRules', 'BdHiddenFields', 'BdKineticFields', 'BdFieldPlacement') as $class) {
        require $root . "/custom/src/BenchDogs/{$class}.php";
    }
    $placement = '\\Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement';
    // The rule's own entry point; before rc121 there is none, and every check below reads as failed, not as a crash.
    $takenOff = function (array $panels) use ($placement) {
        return method_exists($placement, 'takenOff') && defined($placement . '::QUOTE_TAKEN_OFF')
            ? $placement::takenOff($panels, constant($placement . '::QUOTE_TAKEN_OFF')) : null;
    };
    $kinetic = '\\Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdKineticFields';

    // A tenant holding every Kinetic quote field and Sugar's own Valid Until, as VardefManager merges them.
    $defs = array('id' => array('name' => 'id', 'type' => 'id'),
        'date_quote_expected_closed' => array('type' => 'date', 'vname' => 'LBL_QUOTE_EXPECTED_CLOSED'));
    foreach ($kinetic::FIELDS['Quotes'] as $name => $spec) {
        $defs[$name] = array('type' => $spec[0], 'vname' => $kinetic::labelKey($name));
    }
    $GLOBALS['dictionary']['Quote']['fields'] = $defs;

    /** The view after this package's overlays for it (all but $except), run in the order Sugar compiles them. */
    function bdServed(string $root, string $view, array $panels, string $except = ''): array
    {
        $dir = $root . "/custom/Extension/modules/Quotes/Ext/clients/base/views/{$view}";
        $files = array_values(array_filter(glob($dir . '/*.php') ?: array(), function ($f) use ($except) {
            return basename($f) !== $except;
        }));
        sort($files);
        $plain = array_filter($files, function ($f) {
            return strpos(basename($f), '_override') !== 0;
        });
        $override = array_diff($files, $plain);
        $viewdefs = array('Quotes' => array('base' => array('view' => array($view => array('panels' => $panels)))));
        foreach (array_merge($plain, $override) as $file) {
            include $file;
        }

        return $viewdefs['Quotes']['base']['view'][$view]['panels'];
    }

    /** The view BenchDogs-UAT-Config stores. */
    function bdStored(string $uatRoot, string $view): array
    {
        $viewdefs = array();
        include "{$uatRoot}/{$view}/{$view}.php";

        return $viewdefs['Quotes']['base']['view'][$view]['panels'];
    }

    $nameOf = function ($e) {
        return is_array($e) ? (string) ($e['name'] ?? '') : (string) $e;
    };
    $names = function (array $panels) use ($nameOf) {
        $out = array();
        foreach ($panels as $panel) {
            foreach ($panel['fields'] ?? array() as $e) {
                $out[] = $nameOf($e);
                foreach ((is_array($e) && is_array($e['fields'] ?? null)) ? $e['fields'] : array() as $m) {
                    $out[] = $nameOf($m);
                }
            }
        }

        return $out;
    };
    $panel = function (array $panels, string $name) {
        foreach ($panels as $p) {
            if (($p['name'] ?? '') === $name) {
                return $p;
            }
        }

        return array();
    };
    // The rows the record view draws a panel's cells in (Sugar's GridBuilder: a new row at the column count or 12 units).
    $rows = function (array $p) use ($nameOf) {
        $columns = max(1, (int) ($p['columns'] ?? 1));
        $rows = array();
        $row = array();
        $used = 0;
        foreach ($p['fields'] ?? array() as $cell) {
            $span = is_array($cell) && isset($cell['span']) ? (int) $cell['span'] : intdiv(12, $columns);
            if ($row !== array() && (count($row) >= $columns || $used + $span > 12)) {
                $rows[] = $row;
                $row = array();
                $used = 0;
            }
            $row[] = $nameOf($cell);
            $used += $span;
        }
        if ($row !== array()) {
            $rows[] = $row;
        }

        return $rows;
    };
    $rowOf = function (array $p, string $name) use ($rows) {
        foreach ($rows($p) as $row) {
            if (in_array($name, $row, true)) {
                return $row;
            }
        }

        return array();
    };
    $without = function (array $cells, array $gone) use ($nameOf) {
        return array_values(array_filter($cells, function ($e) use ($gone, $nameOf) {
            return !in_array($nameOf($e), $gone, true);
        }));
    };

    // ── R. the record view (and the create form, which draws its panels) ─────────
    $stored = bdStored($uatRoot, 'record');
    $served = bdServed($root, 'record', $stored);
    $body = $panel($served, 'panel_body');
    $bodyIn = $panel($stored, 'panel_body');
    check('R0 control: the stored Bench UAT record view draws Expires Date in its body, and no Due',
        in_array('e_expiration_date_c', $names(array($bodyIn)), true) && !in_array('e_due_date_c', $names($stored), true));
    check('R1 Expires Date is drawn nowhere on the record view', !in_array('e_expiration_date_c', $names($served), true),
        json_encode($names($served)));
    check('R2 the Due is drawn once, in the body, right after Valid Until', count(array_keys($names($served),
        'e_due_date_c', true)) === 1 && ($b = $names(array($body)))
        && array_search('e_due_date_c', $b, true) === array_search('date_quote_expected_closed', $b, true) + 1,
        json_encode($names(array($body))));
    check('R3 ...beside Valid Until, on its row', $rowOf($body, 'e_due_date_c') === array('date_quote_expected_closed',
        'e_due_date_c'), json_encode($rows($body)));
    $due = null;
    foreach ($body['fields'] as $e) {
        $due = $nameOf($e) === 'e_due_date_c' ? $e : $due;
    }
    check('R4 the Due is editable and not required, with its label', $due === array('name' => 'e_due_date_c',
        'label' => 'LBL_E_DUE_DATE_C'), json_encode($due));
    check('R5 the body\'s other cells keep their order (its trailing empty filler gives the Due its place)',
        $without($body['fields'], array('e_due_date_c', 'e_project_name_c')) === $without(
            array_slice($bodyIn['fields'], 0, -1), array('e_expiration_date_c')) && end($bodyIn['fields']) === array(),
        json_encode($names(array($body))));
    check('R6 the best case and the confidence stay where the layout draws them, editable', $rowOf($body,
        'e_best_case_pct_c') === array('e_best_case_pct_c', 'e_confidence_pct_c'), json_encode($rows($body)));
    $others = function (array $panels) {
        return array_values(array_filter($panels, function ($p) {
            return ($p['name'] ?? '') !== 'panel_body';
        }));
    };
    check('R7 every other panel is served as the package\'s other overlays serve it (the Kinetic fields\' overlay '
        . 'touches the body alone here)', $others($served) === $others(bdServed($root, 'record', $stored,
            'bd_kinetic_fields.php')));
    check('R8 the overlays run twice (every metadata build) change nothing', bdServed($root, 'record', $served) === $served);

    // A layout a Studio save stored while rc120 drew the Due right after Expires Date, the filler gone.
    $rc120 = $stored;
    foreach ($rc120 as $i => $p) {
        if (($p['name'] ?? '') === 'panel_body') {
            $cells = array_slice($p['fields'], 0, -1);
            $at = array_search('e_expiration_date_c', array_map($nameOf, $cells), true);
            array_splice($cells, $at + 1, 0, array(array('name' => 'e_due_date_c', 'label' => 'LBL_E_DUE_DATE_C')));
            $rc120[$i]['fields'] = $cells;
        }
    }
    check('R9 a Due a Studio save stored right after Expires Date (rc120) is drawn beside Valid Until, the same view',
        bdServed($root, 'record', $rc120) === $served, json_encode($names(bdServed($root, 'record', $rc120))));
    // ...and one stored in the ERP panel, above the status block (rc119).
    $rc119 = $stored;
    foreach ($rc119 as $i => $p) {
        if (($p['name'] ?? '') === 'LBL_RECORDVIEW_PANEL_ERP') {
            $at = array_search('erp_writeback_status', array_map($nameOf, $p['fields']), true);
            array_splice($rc119[$i]['fields'], $at, 0, array(array('name' => 'e_due_date_c', 'label' => 'LBL_E_DUE_DATE_C')));
        }
    }
    check('R10 a Due a Studio save stored in the ERP panel (rc119) is drawn beside Valid Until, the same view',
        bdServed($root, 'record', $rc119) === $served);
    // An administrator's own place for the Due stays.
    $admin = $stored;
    foreach ($admin as $i => $p) {
        if (($p['name'] ?? '') === 'panel_hidden') {
            $admin[$i]['fields'][] = 'e_due_date_c';
        }
    }
    $adminServed = bdServed($root, 'record', $admin);
    check('R11 a Due an administrator placed in another panel stays there; Expires Date still goes',
        in_array('e_due_date_c', $names(array($panel($adminServed, 'panel_hidden'))), true)
        && count(array_keys($names($adminServed), 'e_due_date_c', true)) === 1
        && !in_array('e_expiration_date_c', $names($adminServed), true));

    // A view laid out as ERP-Epicor draws it on a tenant without the UAT layout: Valid Until in the body, a filler.
    $plainView = array(
        array('name' => 'panel_header', 'header' => true, 'fields' => array('name')),
        array('name' => 'panel_body', 'columns' => 2, 'fields' => array('quote_num', 'quote_stage',
            'date_quote_expected_closed', 'erp_is_primary_quote', array())),
        array('name' => 'LBL_RECORDVIEW_PANEL_ERP', 'columns' => 2, 'fields' => array('erp_reference',
            array('name' => 'erp_writeback_status'), 'erp_writeback_msg')),
    );
    $plainServed = bdServed($root, 'record', $plainView);
    check('R12 ERP-Epicor\'s own layout: the Due beside Valid Until in the body, the best case and confidence above the '
        . 'status block, no Expires Date; rc122: the Project Name right after the Due',
        $names(array($plainServed[1])) === array('quote_num', 'quote_stage',
            'date_quote_expected_closed', 'e_due_date_c', 'e_project_name_c', 'erp_is_primary_quote')
        && $names(array($plainServed[2])) === array('erp_reference', 'e_best_case_pct_c', 'e_confidence_pct_c',
            'erp_writeback_status', 'erp_writeback_msg'), json_encode(array_map(function ($p) use ($names) {
                return $names(array($p));
            }, $plainServed)));

    // rc122: the Project Name, Kinetic's QuoteComment, on a row of its own right after Valid Until | Due.
    $projectName = array('name' => 'e_project_name_c', 'label' => 'LBL_E_PROJECT_NAME_C', 'span' => 12);
    $cell = null;
    foreach ($body['fields'] as $e) {
        $cell = $nameOf($e) === 'e_project_name_c' ? $e : $cell;
    }
    check('R13 the Project Name is drawn once on the record view (so on the create form), in the body, right after '
        . 'the Due', count(array_keys($names($served), 'e_project_name_c', true)) === 1
        && ($b = $names(array($body))) && array_search('e_project_name_c', $b, true)
            === array_search('e_due_date_c', $b, true) + 1, json_encode($names(array($body))));
    check('R14 ...editable, not required, with its label, a row of its own', $cell === $projectName
        && $rowOf($body, 'e_project_name_c') === array('e_project_name_c'), json_encode(array($cell, $rows($body))));
    check('R15 ...and the rows rc121 laid out keep their pairs: Valid Until | Due above it, Best Case | Confidence below',
        $rowOf($body, 'e_due_date_c') === array('date_quote_expected_closed', 'e_due_date_c')
        && $rowOf($body, 'e_best_case_pct_c') === array('e_best_case_pct_c', 'e_confidence_pct_c')
        && ($r = $rows($body)) && array_search(array('e_project_name_c'), $r, true)
            === array_search(array('date_quote_expected_closed', 'e_due_date_c'), $r, true) + 1,
        json_encode($rows($body)));
    $plainBody = $plainServed[1];
    $plainCell = null;
    foreach ($plainBody['fields'] as $e) {
        $plainCell = $nameOf($e) === 'e_project_name_c' ? $e : $plainCell;
    }
    check('R16 on ERP-Epicor\'s own layout too: once, after Valid Until | Due, a row of its own, editable',
        $plainCell === $projectName && $rowOf($plainBody, 'e_project_name_c') === array('e_project_name_c')
        && count(array_keys($names($plainServed), 'e_project_name_c', true)) === 1, json_encode($rows($plainBody)));

    // ── D, P, L. the record dashlet, the list preview and the list view the UAT layout stores ──────────
    foreach (array('recorddashlet' => 'D', 'preview' => 'P', 'list' => 'L') as $view => $tag) {
        $in = bdStored($uatRoot, $view);
        $out = bdServed($root, $view, $in);
        $before = $names($in);
        check("{$tag}0 control: the stored Bench UAT {$view} view draws Expires Date",
            in_array('e_expiration_date_c', $before, true));
        check("{$tag}1 the {$view} view draws no Expires Date", !in_array('e_expiration_date_c', $names($out), true),
            json_encode($names($out)));
        // What the view drew before this release's overlay (its other overlays' work) is otherwise unchanged.
        $prior = $in;
        foreach ($prior as $i => $p) {
            $prior[$i]['fields'] = $without($p['fields'] ?? array(), array('e_expiration_date_c'));
        }
        check("{$tag}2 every other cell of the {$view} view is drawn as before, in order",
            $out === bdServed($root, $view, $prior), json_encode($names($out)));
    }

    // ── C. the controls ───────────────────────────────────────────────────────
    $selection = bdStored($uatRoot, 'selection-list');
    check('C1 a view this package does not touch keeps Valid Until', in_array('date_quote_expected_closed',
        $names($selection), true));
    $noExpiry = array(array('name' => 'panel_body', 'fields' => array('quote_num', 'date_quote_expected_closed')));
    check('C2 a view drawing no Expires Date is served byte for byte', $takenOff($noExpiry) === $noExpiry);
    $inSet = array(array('name' => 'panel_body', 'fields' => array(array('name' => 'dates', 'type' => 'fieldset',
        'fields' => array('e_expiration_date_c', 'date_quote_expected_closed')))));
    check('C3 an Expires Date inside a fieldset an administrator built is left as built', $takenOff($inSet) === $inSet);
    $odd = array('panel_body', array('name' => 'x', 'fields' => 'not a list'));
    check('C4 a malformed view is served as read', $takenOff($odd) === $odd);

    // ── F. the field and its data stay ─────────────────────────────────────────
    check('F1 an install still creates Expires Date where a tenant lacks it (the Bench connector extension writes it)',
        ($kinetic::FIELDS['Quotes']['e_expiration_date_c'] ?? null) === array('date', null, null, 'Expires Date'));
    check('F2 it is not retired: no relabel, no removal path', !isset($kinetic::RETIRED['Quotes']['e_expiration_date_c']));
    check('F3 Expires Date is no longer one of the fields placed on the quote, and is the one taken off',
        !array_key_exists('e_expiration_date_c', $placement::QUOTE_FIELDS)
        && defined($placement . '::QUOTE_TAKEN_OFF')
        && constant($placement . '::QUOTE_TAKEN_OFF') === array('e_expiration_date_c'));

    echo "\n" . count($checks) . " checks, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
