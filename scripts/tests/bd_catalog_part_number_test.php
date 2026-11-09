<?php

/**
 * rc117: the Product Catalog shows each product's Kinetic part number (the stored mft_part_num, no new field) as a
 * read-only "Part Number" column right after the product name, in the ProductTemplates list and in its Search and
 * Select list (the drawer a quote line's product field opens); and a search by part number finds the product.
 *
 * Run:  php scripts/tests/bd_catalog_part_number_test.php
 *       (BD_PART_NUMBER_SEARCH names ERP-Core's erp_part_number.php; unset, the pin under fixtures/shared-sugar/)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT IS REAL: the two shipped overlays and the class they call (BdFieldPlacement::gridColumn), included the way
 * SugarEnt 26.1.0 includes a sidecar view fragment (MetaDataFiles::getClientFileContents: the viewdef in a
 * method-local $viewdefs, the compiled .ext.php included into that scope, more than once per build); ERP-Core's own
 * ProductTemplates quick-search fragment (erp_part_number.php), which this package relies on and does not copy.
 *
 * WHAT IS TRANSCRIBED (SugarEnt 26.1.0): the stock ProductTemplates list and selection-list columns
 * (modules/ProductTemplates/clients/base/views/list/list.php, selection-list/selection-list.php: name, type_name,
 * category_name, status, active_status, qty_in_stock, cost_price, list_price, discount_price[, service,
 * service_duration]), the name column's entry, the basic filter's quick search (filters/basic/basic.php:14-15:
 * name, category_name, type_name at priority 1), and the label LBL_LIST_LBL_MFT_PART_NUM 'Part Number'
 * (language/en_us.lang.php:42). Sugar's Filters model builds a quick search as a $starts term on every
 * quicksearch_field (modules/Filters/clients/base/datas/model/model.js:62-110, pinned in fixtures/stock-sugar/).
 *
 * RED BEFORE (rc116): no overlay, so no Part Number column in either list (A1, S1).
 *
 * IF IT WERE BROKEN you would see: no Part Number column, or not right after the name (A1/S1); a column a seller can
 * type in (A2); hidden by default (A3); two after a rebuild (B1); an admin's own column moved or doubled (C); a
 * field list sent to Sidecar as a JSON object (A5); the part number not among the quick-search fields (Q).
 */

namespace {
    error_reporting(E_ALL);
    $GLOBALS['bd_notices'] = array();
    set_error_handler(static function ($no, $str, $file, $line) {
        $GLOBALS['bd_notices'][] = "$str @ " . basename((string) $file) . ":$line";
        return true;
    });

    const BD_PKG = __DIR__ . '/../../sugar-sell/BenchDogs-Ext';
    const BD_VIEWS = BD_PKG . '/custom/Extension/modules/ProductTemplates/Ext/clients/base/views';
    const BD_STOCK_COLUMNS = array('name', 'type_name', 'category_name', 'status', 'active_status', 'qty_in_stock',
        'cost_price', 'list_price', 'discount_price');

    if (is_file(BD_PKG . '/custom/src/BenchDogs/BdFieldPlacement.php')) {
        require_once BD_PKG . '/custom/src/BenchDogs/BdFieldPlacement.php';
    }

    $checks = array();
    function check(string $name, bool $ok, string $detail = ''): void
    {
        global $checks;
        $checks[] = array($name, $ok);
        printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok || $detail === '' ? '' : "  -- $detail");
    }

    /** A stock ProductTemplates list as served: the name column as stock writes it, then the rest. */
    function bd_view(string $view): array
    {
        $fields = array(array('name' => 'name', 'link' => true, 'enabled' => true, 'default' => true));
        foreach (array_slice(BD_STOCK_COLUMNS, 1) as $name) {
            $fields[] = array('name' => $name, 'enabled' => true, 'default' => true);
        }
        if ($view === 'list') {
            $fields[] = array('name' => 'service');
        }
        // ERP-Core's ProductTemplatesLayout appends its ERP ID column to both deployed lists.
        $fields[] = array('name' => 'erp_display_sync_key', 'label' => 'LBL_ERP_DISPLAY_SYNC_KEY', 'type' => 'erp-id-link',
            'enabled' => true, 'default' => true);

        return array('panels' => array(array('name' => 'panel_header', 'fields' => $fields)));
    }

    /** The shipped fragment for $view included $times into one method scope, as MetaDataFiles does. */
    function bd_include(string $view, ?array $meta, int $times = 1): array
    {
        $module = 'ProductTemplates';
        $type = 'view';
        $platform = 'base';
        $bean = null;
        $results = array($view => array('meta' => $meta));
        $fileInfo = array('path' => "custom/modules/ProductTemplates/Ext/clients/base/views/$view/$view.ext.php",
            'platform' => 'base', 'subPath' => $view, 'template' => false);
        $ext = '.ext.php';
        $viewdefs = array();
        if ($meta !== null) {
            $viewdefs[$module][$platform][$type][$view] = $meta;
        }
        $file = BD_VIEWS . "/$view/bd_part_number_column.php";
        $locals = compact('module', 'type', 'platform', 'bean', 'results', 'fileInfo', 'ext', 'view', 'file');
        $before = array_keys(get_defined_vars());
        for ($i = 0; $i < $times; $i++) {
            if (is_file($file)) {
                include $file;
            }
        }
        $leaked = array_values(array_diff(array_keys(get_defined_vars()), $before, array('i', 'before')));
        $changed = array();
        foreach ($locals as $k => $v) {
            if ($$k !== $v) {
                $changed[] = $k;
            }
        }

        return array($viewdefs[$module]['base']['view'][$view] ?? null, $leaked, $changed);
    }

    function bd_names(?array $meta): array
    {
        return array_map(static function ($e) {
            return is_array($e) ? (string) ($e['name'] ?? '') : (string) $e;
        }, $meta['panels'][0]['fields'] ?? array());
    }

    function bd_entry(?array $meta, string $name): array
    {
        $found = array();
        foreach ($meta['panels'][0]['fields'] ?? array() as $e) {
            if ((is_array($e) ? ($e['name'] ?? '') : $e) === $name) {
                $found[] = $e;
            }
        }

        return $found;
    }

    foreach (array('list' => 'A', 'selection-list' => 'S') as $view => $tag) {
        check("{$tag}0 the overlay ships at the $view Extension path", is_file(BD_VIEWS . "/$view/bd_part_number_column.php"));
        [$out, $leaked, $changed] = bd_include($view, bd_view($view));
        $names = bd_names($out);
        check("{$tag}1 $view: Part Number (mft_part_num) right after the product name, every other column in place",
            $names === array_merge(array('name', 'mft_part_num'), array_slice(bd_names(bd_view($view)), 1)),
            json_encode($names));
        $part = bd_entry($out, 'mft_part_num');
        check("{$tag}2 $view: read-only, captioned 'Part Number' (Sugar's own LBL_LIST_LBL_MFT_PART_NUM)",
            count($part) === 1 && ($part[0]['readonly'] ?? null) === true
            && ($part[0]['label'] ?? null) === 'LBL_LIST_LBL_MFT_PART_NUM', json_encode($part));
        check("{$tag}3 $view: shown by default (enabled and default), not only in the column chooser",
            ($part[0]['enabled'] ?? null) === true && ($part[0]['default'] ?? null) === true, json_encode($part));
        check("{$tag}4 $view: no link out of the list and no field type of its own (the vardef's)",
            !isset($part[0]['link']) && !isset($part[0]['type']), json_encode($part));
        check("{$tag}5 $view: the field list stays a list (a gap in its keys reaches Sidecar as a JSON object)",
            array_keys($out['panels'][0]['fields']) === range(0, count($out['panels'][0]['fields']) - 1));
        check("{$tag}6 $view: nothing left behind in Sugar's method scope, no local changed, no notice",
            $leaked === array() && $changed === array() && $GLOBALS['bd_notices'] === array(),
            json_encode(array($leaked, $changed, $GLOBALS['bd_notices'])));
        [$thrice] = bd_include($view, bd_view($view), 3);
        check("{$tag}7 $view: included three times in one build, one column", $thrice === $out);
    }

    // ── C. a column an admin already placed ──────────────────────────────────────
    $admin = bd_view('list');
    $admin['panels'][0]['fields'][] = array('name' => 'mft_part_num', 'enabled' => true, 'default' => false);
    [$c] = bd_include('list', $admin);
    $kept = bd_entry($c, 'mft_part_num');
    check('C1 an admin\'s own Part Number column stays where it is, once, as the admin set it (hidden), read-only',
        count($kept) === 1 && end($c['panels'][0]['fields'])['name'] === 'mft_part_num'
        && ($kept[0]['default'] ?? null) === false && ($kept[0]['readonly'] ?? null) === true, json_encode($kept));

    // ── D. nothing to work on ────────────────────────────────────────────────────
    [$d1] = bd_include('list', null);
    check('D1 no list view loaded: none is invented', $d1 === null);
    $noName = array('panels' => array(array('name' => 'panel_header', 'fields' => array(array('name' => 'status')))));
    [$d2] = bd_include('list', $noName);
    check('D2 a list without a name column: Part Number last', bd_names($d2) === array('status', 'mft_part_num'),
        json_encode(bd_names($d2)));

    // ── Q. a search by part number finds the product ─────────────────────────────
    $search = (string) getenv('BD_PART_NUMBER_SEARCH');
    if ($search === '') {
        $search = __DIR__ . '/fixtures/shared-sugar/sugar-sell/ERP-Core/src/custom/Extension/modules/ProductTemplates/Ext/'
            . 'clients/base/filters/basic/erp_part_number.php';
    }
    $viewdefs = array();
    $viewdefs['ProductTemplates']['base']['filter']['basic'] = array(
        'quicksearch_field' => array('name', 'category_name', 'type_name'),
        'quicksearch_priority' => 1,
    );
    include $search;
    include $search;
    $quick = $viewdefs['ProductTemplates']['base']['filter']['basic']['quicksearch_field'];
    check('Q1 ERP-Core\'s fragment makes the part number a quick-search field of the catalog (once, over a rebuild), so '
        . 'typing 211-822 in the list, the Search and Select list or a quote line\'s product field asks for '
        . 'mft_part_num starting with it', $quick === array('name', 'category_name', 'type_name', 'mft_part_num'),
        json_encode($quick));
    check('Q2 and it is the column this package shows: the same field', bd_column_name() === 'mft_part_num');

    function bd_column_name(): string
    {
        $class = 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement';
        return defined($class . '::CATALOG_PART_NUMBER') ? (string) constant($class . '::CATALOG_PART_NUMBER')['name'] : '';
    }

    $failed = count(array_filter($checks, static function ($c) { return !$c[1]; }));
    printf("\n%d checks, %d failed\n", count($checks), $failed);
    exit($failed ? 1 : 0);
}
