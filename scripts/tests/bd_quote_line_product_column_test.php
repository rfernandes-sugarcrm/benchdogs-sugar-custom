<?php

/**
 * rc120: on the quote record's line grid the Product column is drawn wider, so a seller reads more of a product's
 * name, in the line's edit row too. No field, list, label or file of ERP-Epicor changes.
 *
 * Run:  php scripts/tests/bd_quote_line_product_column_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT IS REAL: the shipped grid overlay
 *   custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list/bd_product_column_width.php
 * and the class it calls (BdFieldPlacement::gridColumnWidth), included the way SugarEnt 26.1.0 includes a sidecar view
 * fragment (a method-local $viewdefs, more than once per build); compiled with the other three shipped overlays of
 * that directory the way ModuleInstaller::mergeExtensionFiles() compiles it (plain files in name order, then
 * `_override*`); stock Sugar 26.1.0's own Products quote-data-group-list viewdef and its relate field controller, as
 * recorded under fixtures/real-sidecar/ (pinned, sha256-checked by test_real_sidecar_pins.py).
 *
 * WHY THE WIDTH CLASS (SugarEnt 26.1.0, read where it lives): the grid's header row
 * (Quotes quote-data-list-header.hbs) draws each column's <th> with the class its viewdef entry names in widthClass,
 * and the theme gives each class one width (styleguide/less/sugar/datatables.less: cell-large 180px min and max,
 * cell-xxlarge 460px min and max). The row's <td> carries no width of its own (row.hbs, recorded) and holds its text
 * on one line, cut with an ellipsis (datatables.less td: nowrap, max-width 21px), so the column is as wide as its
 * header. Stock draws the Product column cell-large; this package draws it cell-xxlarge, Sugar's widest. In the edit
 * row the product picker is select2 at 100% of its cell (relate.js, recorded), so it widens with the column. The
 * grid scrolls sideways when its columns are wider than the page, so no other column gets narrower.
 *
 * RED BEFORE (rc119): no such overlay; the Product column stays cell-large; 15 of the 33 checks fail (in A, C, D, H
 * and I).
 *
 * IF IT WERE BROKEN you would see: the Product column still 180px wide (A1); its picker, its required flag or its
 * related fields changed (A2); another column moved or changed (A3/A4); a second column after a rebuild (B1); a grid
 * an admin changed reordered (C); a fatal or an invented view on an odd layout or mid-uninstall (D); the other
 * overlays of the directory undoing it or it undoing them (H); the overlay writing anything (I).
 */

namespace {
    error_reporting(E_ALL);
    $GLOBALS['bd_notices'] = array();
    set_error_handler(static function ($no, $str, $file, $line) {
        $GLOBALS['bd_notices'][] = "$str @ " . basename((string) $file) . ":$line";
        return true;
    });

    const BD_PKG = __DIR__ . '/../../sugar-sell/BenchDogs-Ext';
    const BD_GRID_DIR = BD_PKG . '/custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list';
    const BD_WIDTH_FILE = BD_GRID_DIR . '/bd_product_column_width.php';
    const BD_SUGAR = __DIR__ . '/fixtures/real-sidecar/sugar-sell/ERP-Core/tests/support/sugar-26.1';
    const BD_STOCK_GRID = BD_SUGAR . '/modules/Products/clients/base/views/quote-data-group-list/quote-data-group-list.php.json';
    const BD_PRODUCT = 'product_template_name';
    const BD_WIDE = 'cell-xxlarge';

    foreach (array('BdAdmRules', 'BdFieldPlacement', 'BdHiddenFields') as $bdClass) {
        if (is_file(BD_PKG . "/custom/src/BenchDogs/$bdClass.php")) {
            require_once BD_PKG . "/custom/src/BenchDogs/$bdClass.php";
        }
    }
    unset($bdClass);

    $checks = array();
    function check(string $name, bool $ok, string $detail = ''): void
    {
        global $checks;
        $checks[] = array($name, $ok);
        printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok || $detail === '' ? '' : "  -- $detail");
    }

    /** Stock Sugar 26.1.0's Products quote-data-group-list view, as recorded. */
    function bd_stock(): array
    {
        $recorded = json_decode((string) file_get_contents(BD_STOCK_GRID), true);

        return $recorded['viewdefs']['Products']['base']['view']['quote-data-group-list'];
    }

    /**
     * The grid as served with ERP-Epicor installed: stock's entries, with ERP-Epicor's columns in the order its install
     * leaves them (the reading bd_seller_discounts_hidden_test.php and bd_quote_line_product_group_test.php use).
     */
    function bd_grid(): array
    {
        $stock = array();
        foreach (bd_stock()['panels'][0]['fields'] as $entry) {
            $stock[$entry['name']] = $entry;
        }
        $view = bd_stock();
        $view['panels'][0]['fields'] = array(
            $stock['line_num'] + array('link' => true),
            $stock['quantity'],
            $stock[BD_PRODUCT],
            $stock['mft_part_num'],
            $stock['discount_price'],
            array('name' => 'erp_unit_of_measure', 'label' => 'LBL_ERP_UNIT_OF_MEASURE', 'labelModule' => 'Products',
                'type' => 'varchar', 'readonly' => false),
            array('name' => 'erp_break_select'),
            array('name' => 'erp_stock_availability', 'label' => 'LBL_ERP_STOCK_AVAILABILITY', 'labelModule' => 'Products',
                'type' => 'erp-onhand-qty', 'erp_product_link' => 'product_template_id'),
            array('name' => 'subtotal', 'label' => 'LBL_ERP_EXTENDED_PRICE', 'labelModule' => 'Products', 'type' => 'currency'),
            array('label' => 'LBL_ERP_LINE_DISCOUNT', 'labelModule' => 'Products') + $stock['discount_field'],
            array('label' => 'LBL_ERP_DISCOUNTED_TOTAL', 'labelModule' => 'Products') + $stock['total_amount'],
        );

        return $view;
    }

    /** The fragment (or a compiled .ext.php) included $times into one method scope, as MetaDataFiles does. */
    function bd_include(?array $view, int $times = 1, string $file = BD_WIDTH_FILE): array
    {
        $module = 'Products';
        $type = 'view';
        $platform = 'base';
        $bean = null;
        $subPath = 'quote-data-group-list';
        $results = array($subPath => array('meta' => $view));
        $fileInfo = array('path' => "custom/modules/Products/Ext/clients/base/views/$subPath/$subPath.ext.php",
            'platform' => 'base', 'subPath' => $subPath, 'template' => false);
        $ext = '.ext.php';
        $viewdefs = array();
        if ($view !== null) {
            $viewdefs[$module][$platform][$type][$subPath] = $view;
        }
        $locals = compact('module', 'type', 'platform', 'bean', 'results', 'fileInfo', 'ext', 'subPath', 'file');
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

        return array($viewdefs[$module]['base']['view'][$subPath] ?? null, $leaked, $changed);
    }

    /** The top-level column names of the view's first panel. */
    function bd_names(?array $view): array
    {
        return array_map(static function ($e) {
            return is_string($e) ? $e : (string) ($e['name'] ?? '');
        }, $view['panels'][0]['fields'] ?? array());
    }

    /** The view's top-level entry named $field (null when it draws none). */
    function bd_entry(?array $view, string $field)
    {
        foreach ($view['panels'][0]['fields'] ?? array() as $e) {
            if ($e === $field || (is_array($e) && ($e['name'] ?? '') === $field)) {
                return $e;
            }
        }

        return null;
    }

    /** $view with the Product column's widthClass taken off, to compare everything else. */
    function bd_without_width(?array $view): ?array
    {
        if (!is_array($view)) {
            return $view;
        }
        foreach ($view['panels'] ?? array() as $p => $panel) {
            foreach ($panel['fields'] ?? array() as $i => $e) {
                if (is_array($e) && ($e['name'] ?? '') === BD_PRODUCT) {
                    unset($view['panels'][$p]['fields'][$i]['widthClass']);
                }
            }
        }

        return $view;
    }

    /** ModuleInstaller::mergeExtensionFiles() (26.1.0), as bd_seller_discounts_hidden_test.php transcribes it. */
    function bd_compile(array $files): string
    {
        $tags = array('<?php', '?>', '<?PHP', '<?');
        $ext = "<?php\n// WARNING: The contents of this file are auto-generated.\n";
        ksort($files, SORT_STRING);
        $override = array();
        foreach ($files as $base => $body) {
            if (substr($base, 0, 9) === '_override') {
                $override[] = $body;
            } else {
                $ext .= "\n" . str_replace($tags, '', $body);
            }
        }
        foreach ($override as $body) {
            $ext .= "\n" . str_replace($tags, '', $body);
        }

        return $ext;
    }

    $bdRoot = sys_get_temp_dir() . '/rc120-' . getmypid();
    @mkdir($bdRoot, 0777, true);

    // ── S. the recorded stock shapes this relies on ───────────────────────────────────────────────────
    $stockProduct = bd_entry(bd_stock(), BD_PRODUCT);
    check('S1 stock Sugar 26.1.0 draws the Product column cell-large (180px), as a quote-data-relate picker',
        ($stockProduct['widthClass'] ?? null) === 'cell-large' && ($stockProduct['type'] ?? null) === 'quote-data-relate',
        json_encode($stockProduct));
    $relate = (string) file_get_contents(BD_SUGAR . '/clients/base/fields/relate/relate.js');
    check('S2 the edit row\'s product picker is select2 at 100% of its cell outside a record list (relate.js, recorded)',
        strpos($relate, "width: this.view.name === 'recordlist' ? 'off' : '100%',") !== false);
    $row = (string) file_get_contents(BD_SUGAR . '/modules/Products/clients/base/views/quote-data-group-list/row.hbs');
    check('S3 a grid row\'s cells carry no width of their own (row.hbs, recorded): the header sets it',
        strpos($row, '<td class="relative" data-type="{{type}}">') !== false && strpos($row, 'widthClass') === false);

    // ── A. the Product column, on the served grid ─────────────────────────────────────────────────────
    check('A0 the overlay ships at the sidecar view Extension path of the Products quote line grid', is_file(BD_WIDTH_FILE));
    $bdNotices = count($GLOBALS['bd_notices']);
    [$a, $leaked, $changed] = bd_include(bd_grid());
    $product = bd_entry($a, BD_PRODUCT);
    check('A1 the Product column is drawn cell-xxlarge (460px), Sugar\'s widest list column',
        ($product['widthClass'] ?? null) === BD_WIDE, json_encode($product));
    $before = bd_entry(bd_grid(), BD_PRODUCT);
    unset($before['widthClass']);
    $after = $product;
    unset($after['widthClass']);
    check('A2 ...and nothing else of it changes: the quote-data-relate picker, required, its label and related fields',
        $after === $before, json_encode($after));
    check('A3 every column stays in its place', bd_names($a) === bd_names(bd_grid()), json_encode(bd_names($a)));
    check('A4 no other column and nothing else on the view changes', bd_without_width($a) === bd_without_width(bd_grid()));
    check('A5 the field list is still a list (Sidecar reads panel.fields as an array)',
        is_array($a['panels'][0]['fields'] ?? null)
        && array_keys($a['panels'][0]['fields']) === range(0, count(bd_names($a)) - 1));
    check('A6 the fragment leaks no variable into Sugar\'s scope and changes none of its locals',
        $leaked === array() && $changed === array(), json_encode(array($leaked, $changed)));
    check('A7 and raises no notice', count($GLOBALS['bd_notices']) === $bdNotices,
        json_encode(array_slice($GLOBALS['bd_notices'], $bdNotices)));
    [$s] = bd_include(bd_stock());
    check('A8 on stock Sugar\'s own grid (no ERP-Epicor columns) the same: Product cell-xxlarge, the rest as recorded',
        (bd_entry($s, BD_PRODUCT)['widthClass'] ?? null) === BD_WIDE && bd_without_width($s) === bd_without_width(bd_stock()),
        json_encode(bd_entry($s, BD_PRODUCT)));

    // ── B. rebuilds ────────────────────────────────────────────────────────────────────────────────────
    [$b] = bd_include(bd_grid(), 3);
    check('B1 the fragment run three times in one build: the same view', $b === $a, json_encode(bd_names($b)));
    [$b2] = bd_include($a);
    check('B2 a view already drawn wide (the next build): served unchanged', $b2 === $a);

    // ── C. a grid an admin already changed ─────────────────────────────────────────────────────────────
    $moved = bd_grid();
    $moved['panels'][0]['fields'] = array_reverse($moved['panels'][0]['fields']);
    [$c1] = bd_include($moved);
    check('C1 the Product column an admin moved is widened where it is, the order kept',
        bd_names($c1) === bd_names($moved) && (bd_entry($c1, BD_PRODUCT)['widthClass'] ?? null) === BD_WIDE,
        json_encode(bd_names($c1)));
    $bare = bd_grid();
    $bare['panels'][0]['fields'][2] = BD_PRODUCT;
    [$c2] = bd_include($bare);
    check('C2 a Product column stored as a bare name stays in its place, with its name and the width',
        ($c2['panels'][0]['fields'][2] ?? null) === array('name' => BD_PRODUCT, 'widthClass' => BD_WIDE),
        json_encode($c2['panels'][0]['fields'][2] ?? null));
    $none = bd_grid();
    array_splice($none['panels'][0]['fields'], 2, 1);
    [$c3] = bd_include($none);
    check('C3 a grid with no Product column: served as read, no column added', $c3 === $none, json_encode(bd_names($c3)));
    $gaps = array('panels' => array(array('name' => 'p',
        'fields' => array(3 => array('name' => 'quantity'), 7 => array('name' => BD_PRODUCT, 'widthClass' => 'cell-large')))));
    [$c4] = bd_include($gaps);
    check('C4 a field list with gaps in its keys comes back a list, the column widened',
        array_keys($c4['panels'][0]['fields'] ?? array()) === array(0, 1)
        && ($c4['panels'][0]['fields'][1] ?? null) === array('name' => BD_PRODUCT, 'widthClass' => BD_WIDE),
        json_encode($c4));
    $member = array('panels' => array(array('name' => 'p', 'fields' => array(
        array('name' => 'set', 'type' => 'fieldset', 'fields' => array(array('name' => BD_PRODUCT)))))));
    [$c5] = bd_include($member);
    check('C5 a fieldset member named like it is not a grid column: left as it is', $c5 === $member, json_encode($c5));

    // ── D. fails open ──────────────────────────────────────────────────────────────────────────────────
    [$d1] = bd_include(null);
    check('D1 no grid view at all: none is invented', $d1 === null, json_encode($d1));
    $odd = array('panels' => 'not a list');
    [$d2] = bd_include($odd);
    check('D2 a view whose panels are not a list is served as read', $d2 === $odd, json_encode($d2));
    $noFields = array('panels' => array(array('name' => 'p')));
    [$d3] = bd_include($noFields);
    check('D3 a panel with no field list is served as read', $d3 === $noFields, json_encode($d3));
    $bdClass = 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement';
    if (method_exists($bdClass, 'gridColumnWidth')) {
        $bdPanels = array(array('name' => 'p', 'fields' => array(array('name' => BD_PRODUCT))));
        check('D4 no column name or no width class: the panels as read',
            $bdClass::gridColumnWidth($bdPanels, '', BD_WIDE) === $bdPanels
            && $bdClass::gridColumnWidth($bdPanels, BD_PRODUCT, '') === $bdPanels);
        unset($bdPanels);
    } else {
        check('D4 BdFieldPlacement::gridColumnWidth exists', false);
    }
    $bdProbe = "$bdRoot/probe-noclass.php";
    file_put_contents($bdProbe, '<?php $viewdefs = ["Products" => ["base" => ["view" => ["quote-data-group-list" => ["panels" => '
        . '[["name" => "p", "fields" => [["name" => "' . BD_PRODUCT . '", "widthClass" => "cell-large"]]]]]]]]];'
        . ' include ' . var_export(BD_WIDTH_FILE, true) . '; echo json_encode($viewdefs);');
    $bdNoClass = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bdProbe) . ' 2>&1'), true);
    @unlink($bdProbe);
    check('D5 no BdFieldPlacement loadable (one step of an uninstall): the grid is served exactly as read, no fatal',
        is_file(BD_WIDTH_FILE)
        && ($bdNoClass['Products']['base']['view']['quote-data-group-list']['panels'][0]['fields'] ?? null)
            === array(array('name' => BD_PRODUCT, 'widthClass' => 'cell-large')), json_encode($bdNoClass));

    // ── H. compiled with the other overlays of the directory ───────────────────────────────────────────
    $bodies = array();
    foreach (glob(BD_GRID_DIR . '/*.php') as $bdFile) {
        $bodies[basename($bdFile)] = (string) file_get_contents($bdFile);
    }
    check('H0 the directory holds this overlay and the three earlier ones (Product Group, Project, no Line Discount)',
        array_keys($bodies) === array('_override_zz_bd_hide_seller_discounts.php', 'bd_product_column_width.php',
            'bd_product_group_column.php', 'bd_project_column.php'), json_encode(array_keys($bodies)));
    $widthBody = $bodies['bd_product_column_width.php'] ?? '';
    check('H1 the only PHP tag in the overlay is its opening one (Sugar strips every tag it finds)',
        $widthBody !== '' && strpos($widthBody, '<?php') === 0
        && substr_count($widthBody, '<?') + substr_count($widthBody, '?>') === 1);
    $bdExt = "$bdRoot/compiled.ext.php";
    file_put_contents($bdExt, bd_compile($bodies));
    [$h] = bd_include(bd_grid(), 2, $bdExt);
    @unlink($bdExt);
    check('H2 compiled and run twice: Product cell-xxlarge, Product Group and Project after Part Number, no Line Discount',
        bd_names($h) === array('line_num', 'quantity', BD_PRODUCT, 'mft_part_num', 'category_name', 'bd_project_id',
            'discount_price', 'erp_unit_of_measure', 'erp_break_select', 'erp_stock_availability', 'subtotal', 'total_amount')
        && (bd_entry($h, BD_PRODUCT)['widthClass'] ?? null) === BD_WIDE, json_encode(bd_names($h)));
    $others = $bodies;
    unset($others['bd_product_column_width.php']);
    file_put_contents($bdExt, bd_compile($others));
    [$h3] = bd_include(bd_grid(), 2, $bdExt);
    @unlink($bdExt);
    check('H3 CONTROL the three earlier overlays alone leave the Product column as stock draws it (cell-large)',
        (bd_entry($h3, BD_PRODUCT)['widthClass'] ?? null) === 'cell-large' && bd_without_width($h3) === bd_without_width($h),
        json_encode(bd_entry($h3, BD_PRODUCT)));

    // ── I. the overlay itself ──────────────────────────────────────────────────────────────────────────
    $code = preg_replace('#(?m)^\s*//.*$#', '', $widthBody);
    check('I1 it writes nothing (no viewdef save, file, record or database call)',
        $widthBody !== '' && preg_match('/saveViewdef|ViewdefManager|file_put_contents|write_array_to_file|sugar_file_put|'
            . 'BeanFactory|->save\(|\$db\b|DBManager|MetaDataManager|unlink|rmdir/i', (string) $code) === 0);
    preg_match_all("/\\\$viewdefs\\['([^']+)'\\]\\['base'\\]\\['view'\\]\\['([^']+)'\\]/", (string) $code, $named, PREG_SET_ORDER);
    check('I2 it names only the Products quote line grid', $named !== array() && array_values(array_unique(array_map(
        static function ($m) { return $m[1] . '/' . $m[2]; }, $named))) === array('Products/quote-data-group-list'),
        json_encode($named));
    check('I3 it calls BdFieldPlacement::gridColumnWidth once by its full name, behind a class_exists guard',
        substr_count((string) $code, '\\Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement::gridColumnWidth(') === 1
        && strpos((string) $code, "class_exists('Sugarcrm\\\\Sugarcrm\\\\custom\\\\BenchDogs\\\\BdFieldPlacement')") !== false);
    $classSrc = (string) @file_get_contents(BD_PKG . '/custom/src/BenchDogs/BdFieldPlacement.php');
    check('I4 gridColumnWidth() catches Throwable and hands the panels back as read',
        preg_match('/function gridColumnWidth\(.*?catch\s*\(\s*Throwable\s+\$e\s*\)\s*\{.*?return \$read;/s', $classSrc) === 1);

    @rmdir($bdRoot);

    check('Z no notice or warning anywhere in the run', $GLOBALS['bd_notices'] === array(), json_encode($GLOBALS['bd_notices']));

    $failed = count(array_filter($checks, static function ($c) { return !$c[1]; }));
    printf("\n%d checks, %d failed\n", count($checks), $failed);
    exit($failed ? 1 : 0);
}
