<?php

/**
 * rc116 (G1338, owner's ruling of 2026-10-25): on a Bench quote the line grid shows each line's Product Group, the
 * Epicor group the sync stores as the line's stock category, as a read-only column right after Part Number; and
 * Category reads "Product Group" on quoted line items and on products. No field, list or vardef is added.
 *
 * Run:  php scripts/tests/bd_quote_line_product_group_test.php
 *       (BD_QUOTES_LAYOUT / BD_BASE_ERP_LAYOUT name ERP-Epicor's QuotesLayout and ERP-Core's BaseErpLayout; unset,
 *       the pins under fixtures/shared-sugar/ are used)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT IS REAL: the shipped grid overlay
 *   custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list/bd_product_group_column.php
 * and the class it calls (BdFieldPlacement::gridColumn), included the way SugarEnt 26.1.0 includes a sidecar view
 * fragment (MetaDataFiles::getClientFileContents: the viewdef in a method-local $viewdefs, the compiled .ext.php
 * included into that scope, more than once per build); compiled beside the shipped G848 grid overlay the way
 * ModuleInstaller::mergeExtensionFiles() compiles a view directory (plain files in name order, then `_override*`);
 * the two shipped language fragments; and ERP-Epicor's own list of the line fields the quote fetches for the grid
 * (QuotesLayout::productGroupLineItemFields, by reflection), without which the column would draw empty.
 *
 * WHAT IS TRANSCRIBED: the Bench grid's served column order (the same reading bd_seller_discounts_hidden_test.php
 * uses: line_num quantity product_template_name mft_part_num discount_price erp_unit_of_measure erp_break_select
 * erp_stock_availability subtotal discount_field total_amount), and stock Sugar 26.1.0's category labels:
 * Products en_us.lang.php:68 LBL_CATEGORY 'Category:', :101 LBL_CATEGORY_NAME 'Category Name:' (category_name's vname,
 * Products vardefs.php:287); ProductTemplates en_us.lang.php:24-25 the same two, :37 LBL_LIST_CATEGORY 'Category:'.
 *
 * RED BEFORE (rc115): no grid overlay, no language fragments; every section below fails.
 *
 * IF IT WERE BROKEN you would see: no Product Group column, or one that is not right after Part Number (A1/A2); a
 * column a seller can type in, or that links out of the quote (A3/A4); two columns after a rebuild (B1, Sugar runs
 * a fragment more than once); a field list sent to Sidecar as a JSON object (A6); a column an admin already placed
 * moved or doubled (C); the label still "Category Name:" (E); a caption another package sets later winning (E7).
 */

namespace Sugarcrm\Sugarcrm\MetaData {
    /** BaseErpLayout's constructor only asks that this class exists. */
    class ViewdefManager
    {
    }
}

namespace {
    error_reporting(E_ALL);
    $GLOBALS['bd_notices'] = array();
    set_error_handler(static function ($no, $str, $file, $line) {
        $GLOBALS['bd_notices'][] = "$str @ " . basename((string) $file) . ":$line";
        return true;
    });

    const BD_PKG = __DIR__ . '/../../sugar-sell/BenchDogs-Ext';
    const BD_GRID_DIR = BD_PKG . '/custom/Extension/modules/Products/Ext/clients/base/views/quote-data-group-list';
    const BD_COLUMN_FILE = BD_GRID_DIR . '/bd_product_group_column.php';
    const BD_HIDE_FILE = BD_GRID_DIR . '/_override_zz_bd_hide_seller_discounts.php';
    const BD_LANG_PRODUCTS = BD_PKG . '/custom/Extension/modules/Products/Ext/Language/_override_en_us.bd_product_group.php';
    const BD_LANG_TEMPLATES = BD_PKG
        . '/custom/Extension/modules/ProductTemplates/Ext/Language/_override_en_us.bd_product_group.php';

    foreach (array('BdFieldPlacement.php', 'BdHiddenFields.php') as $bdClass) {
        if (is_file(BD_PKG . '/custom/src/BenchDogs/' . $bdClass)) {
            require_once BD_PKG . '/custom/src/BenchDogs/' . $bdClass;
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

    /** The Bench grid as served (see the header), with its stock and ERP-Epicor entry shapes. */
    function bd_grid(): array
    {
        return array('panels' => array(array(
            'name' => 'products_quote_data_group_list',
            'label' => 'LBL_PRODUCTS_QUOTE_DATA_LIST',
            'fields' => array(
                array('name' => 'line_num', 'label' => null, 'widthClass' => 'cell-xsmall', 'type' => 'line-num',
                    'readonly' => true, 'link' => true),
                array('name' => 'quantity', 'label' => 'LBL_QUANTITY', 'labelModule' => 'Products', 'type' => 'float'),
                array('name' => 'product_template_name', 'label' => 'LBL_PRODUCT_TEMPLATE', 'labelModule' => 'Products',
                    'type' => 'quote-data-relate', 'required' => true, 'related_fields' => array('service', 'renewable')),
                array('name' => 'mft_part_num', 'label' => 'LBL_MFT_PART_NUM', 'labelModule' => 'Products', 'type' => 'base'),
                array('name' => 'discount_price', 'label' => 'LBL_DISCOUNT_PRICE', 'labelModule' => 'Products',
                    'type' => 'currency', 'related_fields' => array('discount_price', 'currency_id', 'base_rate')),
                array('name' => 'erp_unit_of_measure'),
                array('name' => 'erp_break_select'),
                array('name' => 'erp_stock_availability', 'label' => 'LBL_ERP_STOCK_AVAILABILITY',
                    'labelModule' => 'Products', 'type' => 'erp-onhand-qty', 'readonly' => true),
                array('name' => 'subtotal', 'label' => 'LBL_ERP_EXTENDED_PRICE', 'labelModule' => 'Products',
                    'type' => 'currency'),
                array('name' => 'discount_field', 'type' => 'fieldset', 'label' => 'LBL_ERP_LINE_DISCOUNT',
                    'fields' => array(array('name' => 'discount_amount'), array('name' => 'discount_select'))),
                array('name' => 'total_amount', 'label' => 'LBL_ERP_DISCOUNTED_TOTAL', 'labelModule' => 'Products',
                    'type' => 'currency'),
            ),
        )));
    }

    /** The fragment (or a compiled .ext.php) included $times into one method scope, as MetaDataFiles does. */
    function bd_include(?array $view, int $times = 1, string $file = BD_COLUMN_FILE): array
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

    /** Every entry named $field on the view, a fieldset's members included. */
    function bd_entries(?array $view, string $field): array
    {
        $found = array();
        foreach ($view['panels'] ?? array() as $p) {
            foreach ($p['fields'] ?? array() as $e) {
                if ($e === $field || (is_array($e) && ($e['name'] ?? '') === $field)) {
                    $found[] = $e;
                }
                foreach ((is_array($e) && is_array($e['fields'] ?? null)) ? $e['fields'] : array() as $m) {
                    if ($m === $field || (is_array($m) && ($m['name'] ?? '') === $field)) {
                        $found[] = $m;
                    }
                }
            }
        }

        return $found;
    }

    /** ModuleInstaller::mergeExtensionFiles() (26.1.0), as the G848 test transcribes it. */
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

    $bdRoot = sys_get_temp_dir() . '/g1338-' . getmypid();
    @mkdir("$bdRoot/custom/include/scripts", 0777, true);

    // ── P. what the column reads: ERP-Epicor fetches each line's category for the grid ─────────────────
    $bdLayout = getenv('BD_QUOTES_LAYOUT')
        ?: __DIR__ . '/fixtures/shared-sugar/sugar-sell/ERP-Epicor/scripts/Modules/QuotesLayout.php';
    $bdBase = getenv('BD_BASE_ERP_LAYOUT') ?: __DIR__ . '/fixtures/shared-sugar/sugar-sell/ERP-Core/scripts/BaseErpLayout.php';
    copy($bdBase, "$bdRoot/custom/include/scripts/BaseErpLayout.php");
    set_include_path($bdRoot . PATH_SEPARATOR . get_include_path());
    require_once $bdLayout;
    // A private method invokes through reflection with no setAccessible() since PHP 8.1 (deprecated in 8.5).
    $bdFetch = new ReflectionMethod(QuotesLayout::class, 'productGroupLineItemFields');
    $bdFetched = $bdFetch->invoke(new QuotesLayout(true));
    check('P1 ERP-Epicor fetches category_id and category_name for every quote line (productGroupLineItemFields)',
        $bdFetched === array('category_id', 'category_name'), json_encode($bdFetched));
    check('P2 ...into the grid rows\' nested fetch list on install (product_bundle_items), so the column has a value',
        strpos((string) file_get_contents($bdLayout),
            "addFieldsToNestedCollection('Quotes', 'product_bundle_items', \$this->productGroupLineItemFields())") !== false);

    // ── A. the column, on the Bench grid ───────────────────────────────────────────────────────────────
    check('A0 the overlay ships at the sidecar view Extension path of the Products quote line grid', is_file(BD_COLUMN_FILE));
    $bdNotices = count($GLOBALS['bd_notices']);
    [$a, $leaked, $changed] = bd_include(bd_grid());
    $names = bd_names($a);
    $col = bd_entries($a, 'category_name');
    $at = array_search('category_name', $names, true);
    check('A1 one Product Group column (category_name) on the grid', count($col) === 1, json_encode($names));
    check('A2 ...directly after Part Number, every other column in its place',
        $names === array('line_num', 'quantity', 'product_template_name', 'mft_part_num', 'category_name', 'discount_price',
            'erp_unit_of_measure', 'erp_break_select', 'erp_stock_availability', 'subtotal', 'discount_field', 'total_amount'),
        json_encode($names));
    check('A3 ...read-only (Sugar holds a read-only column in detail mode in the edit row)',
        ($col[0]['readonly'] ?? null) === true, json_encode($col));
    check('A4 ...plain text, not a link out of the quote into the Product Categories admin page',
        ($col[0]['link'] ?? null) === false, json_encode($col));
    check('A5 ...captioned by the category\'s own label, LBL_CATEGORY_NAME in Products (no new label)',
        ($col[0]['label'] ?? null) === 'LBL_CATEGORY_NAME' && ($col[0]['labelModule'] ?? null) === 'Products',
        json_encode($col));
    check('A6 the field list is still a list (Sidecar reads panel.fields as an array)',
        is_array($a['panels'][0]['fields'] ?? null) && array_keys($a['panels'][0]['fields']) === range(0, count($names) - 1)
        && strpos(json_encode($a['panels'][0]['fields']), '[') === 0);
    $other = bd_grid();
    unset($other['panels'][0]['fields']);
    $aOther = $a;
    unset($aOther['panels'][0]['fields']);
    check('A7 nothing else on the view changes (panel name, label)', $aOther === $other, json_encode($aOther));
    check('A8 the fragment leaks no variable into Sugar\'s scope and changes none of its locals',
        $leaked === array() && $changed === array(), json_encode(array($leaked, $changed)));
    check('A9 and raises no notice', count($GLOBALS['bd_notices']) === $bdNotices,
        json_encode(array_slice($GLOBALS['bd_notices'], $bdNotices)));

    // ── B. rebuilds ────────────────────────────────────────────────────────────────────────────────────
    [$b] = bd_include(bd_grid(), 3);
    check('B1 the fragment run three times in one build: still one column, in the same place', $b === $a, json_encode(bd_names($b)));
    [$b2] = bd_include($a);
    check('B2 a view that already has the column (the next build): served unchanged', $b2 === $a);

    // ── C. a grid an admin already changed ─────────────────────────────────────────────────────────────
    $placed = bd_grid();
    $placed['panels'][0]['fields'][] = array('name' => 'category_name', 'label' => 'LBL_CUSTOM_GROUP');
    [$c1] = bd_include($placed, 2);
    $c1col = bd_entries($c1, 'category_name');
    check('C1 a grid that already draws category_name keeps it where it is, once, with its own label',
        count($c1col) === 1 && end($c1['panels'][0]['fields'])['name'] === 'category_name'
        && ($c1col[0]['label'] ?? null) === 'LBL_CUSTOM_GROUP', json_encode(bd_names($c1)));
    check('C2 ...and read-only', ($c1col[0]['readonly'] ?? null) === true, json_encode($c1col));
    $asString = bd_grid();
    array_splice($asString['panels'][0]['fields'], 1, 0, array('category_name'));
    [$c3] = bd_include($asString);
    check('C3 one drawn as a bare name stays in its place, read-only', bd_names($c3)[1] === 'category_name'
        && count(bd_entries($c3, 'category_name')) === 1
        && ($c3['panels'][0]['fields'][1] ?? null) === array('name' => 'category_name', 'readonly' => true),
        json_encode($c3['panels'][0]['fields'][1] ?? null));
    $noPart = bd_grid();
    array_splice($noPart['panels'][0]['fields'], 3, 1);
    [$c4] = bd_include($noPart);
    check('C4 a grid with no Part Number column: the Product Group follows the product name',
        array_slice(bd_names($c4), 2, 2) === array('product_template_name', 'category_name'), json_encode(bd_names($c4)));
    $bare = array('panels' => array(array('name' => 'p', 'fields' => array(array('name' => 'quantity')))));
    [$c5] = bd_include($bare);
    check('C5 a grid with neither: the Product Group is the last column', bd_names($c5) === array('quantity', 'category_name'),
        json_encode(bd_names($c5)));
    $gaps = bd_grid();
    $gaps['panels'][0]['fields'] = array(3 => array('name' => 'mft_part_num'), 7 => array('name' => 'quantity'));
    [$c6] = bd_include($gaps);
    check('C6 a field list with gaps in its keys comes back a list',
        array_keys($c6['panels'][0]['fields'] ?? array()) === array(0, 1, 2)
        && bd_names($c6) === array('mft_part_num', 'category_name', 'quantity'), json_encode($c6));

    // ── D. fails open ──────────────────────────────────────────────────────────────────────────────────
    [$d1] = bd_include(null);
    check('D1 no grid view at all: none is invented', $d1 === null, json_encode($d1));
    $odd = array('panels' => 'not a list');
    [$d2] = bd_include($odd);
    check('D2 a view whose panels are not a list is served as read', $d2 === $odd, json_encode($d2));
    $noFields = array('panels' => array(array('name' => 'p')));
    [$d3] = bd_include($noFields);
    check('D3 a panel with no field list is served as read', $d3 === $noFields, json_encode($d3));
    if (method_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement', 'gridColumn')) {
        $bdPanels = array(array('name' => 'p', 'fields' => array(array('name' => 'mft_part_num'))));
        $bdBroken = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement::gridColumn($bdPanels, array(), array('mft_part_num'));
        check('D4 a column with no name: the panels as read', $bdBroken === $bdPanels, json_encode($bdBroken));
        unset($bdPanels, $bdBroken);
    } else {
        check('D4 BdFieldPlacement::gridColumn exists', false);
    }
    $bdProbe = "$bdRoot/probe-noclass.php";
    file_put_contents($bdProbe, '<?php $viewdefs = ["Products" => ["base" => ["view" => ["quote-data-group-list" => ["panels" => '
        . '[["name" => "p", "fields" => [["name" => "mft_part_num"]]]]]]]]];'
        . ' include ' . var_export(BD_COLUMN_FILE, true) . '; echo json_encode($viewdefs);');
    $bdNoClass = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bdProbe) . ' 2>&1'), true);
    @unlink($bdProbe);
    check('D5 no BdFieldPlacement loadable (one step of an uninstall): the grid is served exactly as read, no fatal',
        is_file(BD_COLUMN_FILE)
        && ($bdNoClass['Products']['base']['view']['quote-data-group-list']['panels'][0]['fields'] ?? null)
            === array(array('name' => 'mft_part_num')), json_encode($bdNoClass));

    // ── H. compiled with the discounts overlay that shares the directory ───────────────────────────────
    $colBody = is_file(BD_COLUMN_FILE) ? (string) file_get_contents(BD_COLUMN_FILE) : '';
    $hideBody = (string) file_get_contents(BD_HIDE_FILE);
    check('H1 the only PHP tag in the overlay is its opening one (Sugar strips every tag it finds)',
        $colBody !== '' && strpos($colBody, '<?php') === 0 && substr_count($colBody, '<?') + substr_count($colBody, '?>') === 1);
    $bdExt = "$bdRoot/compiled.ext.php";
    file_put_contents($bdExt, bd_compile(array(
        basename(BD_COLUMN_FILE) => $colBody,
        basename(BD_HIDE_FILE) => $hideBody,
    )));
    [$h] = bd_include(bd_grid(), 2, $bdExt);
    @unlink($bdExt);
    check('H2 compiled with the G848 overlay and run twice: the Product Group after Part Number, no Line Discount',
        bd_names($h) === array('line_num', 'quantity', 'product_template_name', 'mft_part_num', 'category_name',
            'discount_price', 'erp_unit_of_measure', 'erp_break_select', 'erp_stock_availability', 'subtotal', 'total_amount'),
        json_encode(bd_names($h)));

    // ── I. the overlay itself ──────────────────────────────────────────────────────────────────────────
    $code = preg_replace('#(?m)^\s*//.*$#', '', $colBody);
    check('I1 it writes nothing (no viewdef save, file, record or database call)',
        $colBody !== '' && preg_match('/saveViewdef|ViewdefManager|file_put_contents|write_array_to_file|sugar_file_put|'
            . 'BeanFactory|->save\(|\$db\b|DBManager|MetaDataManager|unlink|rmdir/i', (string) $code) === 0);
    preg_match_all("/\\\$viewdefs\\['([^']+)'\\]\\['base'\\]\\['view'\\]\\['([^']+)'\\]/", (string) $code, $named, PREG_SET_ORDER);
    check('I2 it names only the Products quote line grid', $named !== array() && array_values(array_unique(array_map(
        static function ($m) { return $m[1] . '/' . $m[2]; }, $named))) === array('Products/quote-data-group-list'),
        json_encode($named));
    check('I3 it calls BdFieldPlacement::gridColumn once by its full name, behind a class_exists guard',
        substr_count((string) $code, '\\Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdFieldPlacement::gridColumn(') === 1
        && strpos((string) $code, "class_exists('Sugarcrm\\\\Sugarcrm\\\\custom\\\\BenchDogs\\\\BdFieldPlacement')") !== false);
    $classSrc = (string) @file_get_contents(BD_PKG . '/custom/src/BenchDogs/BdFieldPlacement.php');
    check('I4 gridColumn() catches Throwable and hands the panels back as read',
        preg_match('/function gridColumn\(.*?catch\s*\(\s*Throwable\s+\$e\s*\)\s*\{.*?return \$panels;/s', $classSrc) === 1);

    // ── E. the label: "Product Group" on quoted line items and on products ─────────────────────────────
    /** A module's en_us strings after stock and the fragment, as Sugar's language merge leaves them. */
    function bd_lang(array $stock, string $file): array
    {
        $mod_strings = $stock;
        if (is_file($file)) {
            include $file;
        }

        return $mod_strings;
    }
    $stockProducts = array('LBL_CATEGORY' => 'Category:', 'LBL_CATEGORY_NAME' => 'Category Name:',
        'LBL_PRODUCT_CATEGORIES' => 'Product Categories', 'LBL_MFT_PART_NUM' => 'Mft Part Number:');
    $stockTemplates = array('LBL_CATEGORY_NAME' => 'Category Name:', 'LBL_CATEGORY' => 'Category:',
        'LBL_LIST_CATEGORY' => 'Category:', 'LBL_LIST_CATEGORY_ID' => 'Category ID:', 'LBL_CATEGORY_ID' => 'Category ID',
        'LBL_PRODUCT_CATEGORIES' => 'Product Categories');
    $p = bd_lang($stockProducts, BD_LANG_PRODUCTS);
    check('E1 Products: the category reads "Product Group" (LBL_CATEGORY_NAME, the column and the line page)',
        is_file(BD_LANG_PRODUCTS) && ($p['LBL_CATEGORY_NAME'] ?? null) === 'Product Group', json_encode($p));
    check('E2 Products: LBL_CATEGORY (category_id, reports and filters) reads "Product Group"',
        ($p['LBL_CATEGORY'] ?? null) === 'Product Group', json_encode($p));
    check('E3 Products: no other string changes, none is added',
        array_diff_key($p, $stockProducts) === array()
        && array_diff_assoc($p, $stockProducts) === array('LBL_CATEGORY' => 'Product Group', 'LBL_CATEGORY_NAME' => 'Product Group'),
        json_encode($p));
    $t = bd_lang($stockTemplates, BD_LANG_TEMPLATES);
    check('E4 ProductTemplates: Category, Category Name and the list\'s Category read "Product Group"',
        is_file(BD_LANG_TEMPLATES) && ($t['LBL_CATEGORY_NAME'] ?? null) === 'Product Group'
        && ($t['LBL_CATEGORY'] ?? null) === 'Product Group' && ($t['LBL_LIST_CATEGORY'] ?? null) === 'Product Group',
        json_encode($t));
    check('E5 ProductTemplates: the id labels and every other string stay stock, none is added',
        array_diff_key($t, $stockTemplates) === array()
        && array_diff_assoc($t, $stockTemplates) === array('LBL_CATEGORY_NAME' => 'Product Group',
            'LBL_CATEGORY' => 'Product Group', 'LBL_LIST_CATEGORY' => 'Product Group'), json_encode($t));
    check('E6 the column\'s caption resolves to "Product Group"',
        ($p[$col[0]['label'] ?? ''] ?? null) === 'Product Group');
    // A plain fragment another package ships with the same key, merged the way Sugar merges a module's Language
    // directory (plain files, then `_override*` ones): the Bench caption is the one left.
    $bdLang = "$bdRoot/lang.ext.php";
    $langOk = is_file(BD_LANG_PRODUCTS) && is_file(BD_LANG_TEMPLATES);
    foreach (array(BD_LANG_PRODUCTS, BD_LANG_TEMPLATES) as $bdFile) {
        foreach (array('en_us.aaa_other.php', 'en_us.zzz_other.php') as $bdSibling) {
            file_put_contents($bdLang, bd_compile(array(
                $bdSibling => "<?php\n\$mod_strings['LBL_CATEGORY_NAME'] = 'Category';\n",
                basename($bdFile) => is_file($bdFile) ? (string) file_get_contents($bdFile) : '',
            )));
            $langOk = $langOk && (bd_lang(array(), $bdLang)['LBL_CATEGORY_NAME'] ?? null) === 'Product Group';
        }
    }
    @unlink($bdLang);
    check('E7 a plain fragment of another package with the same key, in either name order: "Product Group" is left',
        $langOk);
    $langSrc = (string) @file_get_contents(BD_LANG_PRODUCTS) . (string) @file_get_contents(BD_LANG_TEMPLATES);
    check('E8 the language fragments define no field, list or dropdown (no $dictionary, no $app_list_strings)',
        $langSrc !== '' && preg_match('/\$dictionary|\$app_list_strings|\$app_strings/', $langSrc) === 0);
    $all = $langSrc . $colBody;
    check('E9 nothing here touches ERP-Core\'s LBL_ERP_PRODUCT_GROUP or a product group list',
        $all !== '' && preg_match('/LBL_ERP_PRODUCT_GROUP|product_group_list/', $all) === 0);

    @unlink("$bdRoot/custom/include/scripts/BaseErpLayout.php");
    @rmdir("$bdRoot/custom/include/scripts");
    @rmdir("$bdRoot/custom/include");
    @rmdir("$bdRoot/custom");
    @rmdir($bdRoot);

    check('Z no notice or warning anywhere in the run', $GLOBALS['bd_notices'] === array(), json_encode($GLOBALS['bd_notices']));

    $failed = count(array_filter($checks, static function ($c) { return !$c[1]; }));
    printf("\n%d checks, %d failed\n", count($checks), $failed);
    exit($failed ? 1 : 0);
}
