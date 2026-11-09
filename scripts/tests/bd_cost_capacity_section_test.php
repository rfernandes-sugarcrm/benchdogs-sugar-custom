<?php

/**
 * Bench Dogs' Cost & capacity section on the quoted line item's record view: directly under ERP-Epicor's Epicor Cost
 * Worksheet panel, none of that panel's values in it, Kinetic's own fields read-only, and still there after
 * ERP-Epicor re-applies its layout on an upgrade.
 *
 * Run:  php scripts/tests/bd_cost_capacity_section_test.php
 *       (BD_PRODUCTS_LAYOUT / BD_BASE_ERP_LAYOUT name ERP-Core's ProductsLayout and BaseErpLayout; unset, the
 *       ERP-Epicor 1.2.107 copies under fixtures/rc107/ are used)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT IS REAL: ERP-Epicor 1.2.107's ProductsLayout::install() and BaseErpLayout (byte copies of
 * erp-integration-sugar 52c2d0eb), run over Sugar's stock quoted line item record view (recorded from Mango); this
 * package's shipped overlays for that view, compiled the way ModuleInstaller::mergeExtensionFiles() compiles a
 * sidecar view directory (plain fragments in name order, then the _override ones) and included twice per build, as
 * MetaDataFiles::getClientFileContents() reaches the compiled file; BdKineticFields' list of the line's fields.
 * WHAT IS FAKED: ViewdefManager (the stored custom view, else the stock one; like Sugar's findModuleViewdef() it
 * never reads a compiled .ext.php, so an installer never sees an overlay's output), Sugar's caches, and BeanFactory
 * and VardefManager (a tenant holding ERP-Epicor's and Bench Dogs' line fields).
 *
 * IF IT WERE BROKEN you would see:
 *   A1/A2 FAIL - the section drawn somewhere else (the release before this one drew it last, under the hidden
 *                "show more" panel);
 *   B*    FAIL - a cost worksheet value drawn in the section, or twice on the view;
 *   C2    FAIL - a field only Kinetic writes drawn editable, or one the seller sends to Kinetic locked;
 *   D*    FAIL - the section moving, doubling or being stored when the view is built again;
 *   E*    FAIL - the section leaving the panel's side after ERP-Epicor re-applies its layout (upgrade, REPLACE);
 *   F*    FAIL - a section an earlier save stored somewhere else left where it was;
 *   T2-T5 FAIL - Bench Dogs' fields a Studio save stored inside the cost worksheet panel left there, so no section is
 *                drawn (the release before this one; fixtures/rc108 holds the shape as served);
 *   T0, T1, T6-T9 (controls and rebuilds) FAIL - the view drawn where nothing is stored changed, a field that is not
 *                ours or one stored in the body moved, a blank cell between two cost values dropped, or a rebuild differs;
 *   U2-U4, U11 FAIL - blank cells Studio stored beside our fields in that panel ([] or a bare span, as Studio 26.1
 *                writes them; fixtures/rc109) still drawn after our fields leave it: that Sugar drew 16 cells after
 *                rc108, 8 empty, for the eight values;
 *   U1, U5-U10 (premise, controls, rebuilds) FAIL - the fixture no longer what that Sugar drew, the section or body
 *                changed, an admin's own field or a blank cell sharing no row with ours dropped (Studio's own removal
 *                keeps them), a blank cell outside the panel dropped, or a rebuild differs;
 *   G*    (controls) FAIL - the fields lost on a view without the cost panel, a view changed on a tenant without
 *                the fields, or the position checks unable to see a section in the wrong place.
 */

namespace Sugarcrm\Sugarcrm\MetaData {
    /** The stored custom view, else the stock one; a compiled .ext.php is never read, as in Sugar. */
    class ViewdefManager
    {
        public static $stock = array();
        public static $stored = array();
        public static $saves = 0;

        public function loadViewdef($platform, $module, $view, $loadBase = false, $isLayout = false)
        {
            return self::$stored[$module][$view] ?? (self::$stock[$module][$view] ?? array());
        }

        public function saveViewdef($viewdef, $module, $platform, $view, $isLayout = false)
        {
            self::$stored[$module][$view] = $viewdef;
            self::$saves++;
        }
    }
}

namespace {
    use Sugarcrm\Sugarcrm\custom\BenchDogs\BdFieldPlacement;
    use Sugarcrm\Sugarcrm\custom\BenchDogs\BdKineticFields;
    use Sugarcrm\Sugarcrm\MetaData\ViewdefManager;

    error_reporting(E_ALL);
    $GLOBALS['bd_notices'] = array();
    set_error_handler(static function ($no, $str, $file, $line) {
        $GLOBALS['bd_notices'][] = "$str @ " . basename((string) $file) . ":$line";
        return true;
    });

    $checks = array();
    function check(string $name, bool $ok, string $detail = ''): void
    {
        global $checks;
        $checks[] = array($name, $ok);
        printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok || $detail === '' ? '' : "  -- $detail");
    }

    $GLOBALS['log'] = new class {
        public function __call($level, $args)
        {
        }
    };

    class MetaDataFiles
    {
        public static function clearModuleClientCache($module = null, $type = '')
        {
        }
    }

    class TemplateHandler
    {
        public static function clearCache($module = '')
        {
        }
    }

    class BeanFactory
    {
        public static function getObjectName($module)
        {
            return $module === 'Products' ? 'Product' : '';
        }
    }

    class VardefManager
    {
        public static function loadVardef($module, $object)
        {
        }
    }

    $bdPkg = dirname(__DIR__, 2) . '/sugar-sell/BenchDogs-Ext';
    require_once $bdPkg . '/custom/src/BenchDogs/BdKineticFields.php';
    // BdFieldPlacement names BdAdmRules' field constant (Sugar autoloads both).
    require_once $bdPkg . '/custom/src/BenchDogs/BdAdmRules.php';
    require_once $bdPkg . '/custom/src/BenchDogs/BdFieldPlacement.php';
    require_once $bdPkg . '/custom/src/BenchDogs/BdHiddenFields.php';

    // ProductsLayout require_once's the path the two packages install BaseErpLayout at; give it that path.
    $bdLayout = getenv('BD_PRODUCTS_LAYOUT') ?: __DIR__ . '/fixtures/rc107/ProductsLayout.php.txt';
    $bdBase = getenv('BD_BASE_ERP_LAYOUT') ?: __DIR__ . '/fixtures/rc107/BaseErpLayout.php.txt';
    $bdRoot = sys_get_temp_dir() . '/bd-cost-capacity-' . getmypid();
    @mkdir("$bdRoot/custom/include/scripts", 0777, true);
    @mkdir("$bdRoot/include/TemplateHandler", 0777, true);
    copy($bdBase, "$bdRoot/custom/include/scripts/BaseErpLayout.php");
    file_put_contents("$bdRoot/include/TemplateHandler/TemplateHandler.php", "<?php\n");
    set_include_path($bdRoot . PATH_SEPARATOR . get_include_path());
    require_once $bdLayout;

    // The shipped overlays for the line's record view, compiled as Sugar compiles their directory.
    $bdDir = $bdPkg . '/custom/Extension/modules/Products/Ext/clients/base/views/record';
    $bdFiles = glob("$bdDir/*.php");
    sort($bdFiles, SORT_STRING);
    $bdPlain = array();
    $bdOverride = array();
    foreach ($bdFiles as $bdFile) {
        $bdBody = str_replace(array('<?php', '?>', '<?PHP', '<?'), '', (string) file_get_contents($bdFile));
        if (strpos(basename($bdFile), '_override') === 0) {
            $bdOverride[] = $bdBody;
        } else {
            $bdPlain[] = $bdBody;
        }
    }
    define('BD_COMPILED', "$bdRoot/record.ext.php");
    file_put_contents(BD_COMPILED, "<?php\n" . implode("\n", array_merge($bdPlain, $bdOverride)));
    register_shutdown_function(static function () use ($bdRoot) {
        @unlink(BD_COMPILED);
        @unlink("$bdRoot/custom/include/scripts/BaseErpLayout.php");
        @unlink("$bdRoot/include/TemplateHandler/TemplateHandler.php");
        foreach (array('custom/include/scripts', 'custom/include', 'custom', 'include/TemplateHandler', 'include', '')
            as $bdSub) {
            @rmdir(rtrim("$bdRoot/$bdSub", '/'));
        }
    });

    $stock = json_decode((string) file_get_contents(__DIR__ . '/fixtures/rc107/products_record_stock.json'), true);
    ViewdefManager::$stock['Products']['record'] = $stock['viewdefs']['Products']['base']['view']['record'];

    const BD_SECTION = 'LBL_BD_PANEL_COST_CAPACITY';
    define('BD_COST', ProductsLayout::COST_WORKSHEET_PANEL_NAME);
    // The two line fields the Bench connector sends to Kinetic (its SENT_LINE_FIELDS); every other one only reads back.
    const BD_SENT_TO_KINETIC = array('e_sales_category_c', 'e_quantity_quoted_c');
    $bdLine = array_keys(BdKineticFields::FIELDS['Products']);

    /** ERP-Epicor's install of the line's record view over $stored (null: the stock view), and what it stores. */
    function bd_install(?array $stored, bool $replace = false): array
    {
        ViewdefManager::$stored = $stored === null ? array() : array('Products' => array('record' => $stored));
        (new ProductsLayout($replace))->install();

        return ViewdefManager::$stored['Products']['record'] ?? ViewdefManager::$stock['Products']['record'];
    }

    /** The view as a metadata build serves it: the stored view, then the compiled overlays, reached $times times. */
    function bd_build(array $stored, int $times = 2): array
    {
        $viewdefs = array('Products' => array('base' => array('view' => array('record' => $stored))));
        for ($i = 0; $i < $times; $i++) {
            include BD_COMPILED;
        }

        return $viewdefs['Products']['base']['view']['record'];
    }

    function bd_name($entry): string
    {
        return is_string($entry) ? $entry : (is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : '');
    }

    function bd_panel_names(array $view): array
    {
        return array_map(static function ($p) {
            return (string) ($p['name'] ?? '');
        }, array_values($view['panels'] ?? array()));
    }

    function bd_panel(array $view, string $name): ?array
    {
        foreach ($view['panels'] ?? array() as $panel) {
            if (($panel['name'] ?? '') === $name) {
                return $panel;
            }
        }

        return null;
    }

    function bd_field_names(?array $panel): array
    {
        return array_values(array_map('bd_name', (array) ($panel['fields'] ?? array())));
    }

    /** How many times $field is drawn on the view, fieldset members included. */
    function bd_drawn(array $view, string $field): int
    {
        $n = 0;
        foreach ($view['panels'] ?? array() as $panel) {
            foreach ((array) ($panel['fields'] ?? array()) as $entry) {
                $n += bd_name($entry) === $field ? 1 : 0;
                foreach ((is_array($entry) && is_array($entry['fields'] ?? null)) ? $entry['fields'] : array() as $m) {
                    $n += bd_name($m) === $field ? 1 : 0;
                }
            }
        }

        return $n;
    }

    /**
     * A panel's rows as the record view lays them out (Sugar 26.1 GridBuilder.js:88-99, :117, :202-219): one column a
     * cell unless it has a span, a new row once a row holds 'columns' cells or the next one does not fit in 12. Names,
     * '' for a cell with no name.
     */
    function bd_rows(?array $panel): array
    {
        $columns = max(1, (int) ($panel['columns'] ?? 0));
        $rows = array();
        $row = array();
        $used = 0;
        foreach (array_values((array) ($panel['fields'] ?? array())) as $entry) {
            $span = is_array($entry) && isset($entry['span']) ? max(1, min(12, (int) $entry['span']))
                : max(1, intdiv(12, $columns));
            if ($row !== array() && (count($row) >= $columns || $used + $span > 12)) {
                $rows[] = $row;
                $row = array();
                $used = 0;
            }
            $row[] = bd_name($entry);
            $used += $span;
        }
        if ($row !== array()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The record-cell divs Sugar's record template draws for a panel, and how many are empty (tabspanels.hbs:80-88):
     * one for a named cell, two for a cell with no name (a filler cell and the cell inside it).
     */
    function bd_cells(?array $panel): array
    {
        $cells = 0;
        $empty = 0;
        foreach ((array) ($panel['fields'] ?? array()) as $entry) {
            if (bd_name($entry) !== '') {
                $cells++;
            } else {
                $cells += 2;
                $empty += 2;
            }
        }

        return array($cells, $empty);
    }

    /** True when the section is the panel right after the cost worksheet panel. */
    function bd_directly_under(array $view): bool
    {
        $names = bd_panel_names($view);
        $cost = array_search(BD_COST, $names, true);
        $section = array_search(BD_SECTION, $names, true);

        return $cost !== false && $section !== false && $section === $cost + 1;
    }

    /** A tenant holding ERP-Epicor's line fields and Bench Dogs' 17, as Studio custom fields. */
    function bd_tenant(bool $withBench = true): void
    {
        $fields = array('id' => array('name' => 'id', 'type' => 'id'));
        foreach (array('erp_material_cost', 'erp_labor_cost', 'erp_material_burden', 'erp_labor_burden',
            'erp_subcontract_cost', 'erp_misc_cost', 'erp_quoted_markup_pct', 'erp_quoted_profit_pct',
            'erp_unit_of_measure', 'erp_need_by_date', 'erp_ship_by_date') as $name) {
            $fields[$name] = array('name' => $name, 'type' => 'decimal', 'vname' => 'LBL_' . strtoupper($name));
        }
        foreach ($withBench ? BdKineticFields::FIELDS['Products'] : array() as $name => $spec) {
            $fields[$name] = array('name' => $name, 'type' => $spec[0], 'vname' => BdKineticFields::labelKey($name));
        }
        $GLOBALS['dictionary']['Product']['fields'] = $fields;
    }

    bd_tenant();
    $anchor = defined(BdFieldPlacement::class . '::COST_WORKSHEET_PANEL')
        ? constant(BdFieldPlacement::class . '::COST_WORKSHEET_PANEL') : null;

    // ── P. the premises: ERP-Epicor 1.2.107's own install of the view ─────────────────────────────────
    $fresh = bd_install(null);
    $costPanel = bd_panel($fresh, BD_COST);
    $worksheet = bd_field_names($costPanel);
    check('P1 the section is anchored on the panel name ERP-Epicor gives its cost worksheet', $anchor === BD_COST,
        var_export($anchor, true));
    check('P2 ERP-Epicor puts its cost worksheet panel, collapsed, with 8 values, just before the hidden panel',
        bd_panel_names($fresh) === array('panel_header', 'panel_body', BD_COST, 'panel_hidden')
        && ($costPanel['panelDefault'] ?? '') === 'collapsed' && count($worksheet) === 8
        && count(array_unique($worksheet)) === 8, json_encode(array(bd_panel_names($fresh), $worksheet)));
    check('P3 ERP-Epicor stores no Bench Dogs section (the overlay is never written into its view)',
        bd_panel($fresh, BD_SECTION) === null);

    // ── A. where the section is drawn ────────────────────────────────────────────────────────────────
    $saves = ViewdefManager::$saves;
    $built = bd_build($fresh);
    $section = bd_panel($built, BD_SECTION);
    check('A1 the Cost & capacity section is the panel directly under the cost worksheet panel',
        bd_directly_under($built), json_encode(bd_panel_names($built)));
    check('A2 the panels read: header, body, cost worksheet, Cost & capacity, then the hidden panel',
        bd_panel_names($built) === array('panel_header', 'panel_body', BD_COST, BD_SECTION, 'panel_hidden'),
        json_encode(bd_panel_names($built)));
    check('A3 the cost worksheet panel is served exactly as ERP-Epicor stored it (still collapsed)',
        bd_panel($built, BD_COST) === $costPanel, json_encode(bd_panel($built, BD_COST)));
    check('A4 the section is open and two columns wide, as before',
        ($section['panelDefault'] ?? '') === 'expanded' && ($section['columns'] ?? 0) === 2, json_encode($section));

    // ── B. no cost worksheet value in the section ────────────────────────────────────────────────────
    check('B1 none of the eight cost worksheet values is drawn in the section',
        $section !== null && array_intersect($worksheet, bd_field_names($section)) === array(),
        json_encode(bd_field_names($section)));
    $once = array();
    foreach ($worksheet as $name) {
        $once[$name] = bd_drawn($built, $name);
    }
    check('B2 each cost worksheet value is drawn once on the view, in its own panel',
        $worksheet !== array() && array_unique(array_values($once)) === array(1), json_encode($once));
    check('B3 the section\'s field list names no cost worksheet value, on any tenant',
        array_intersect($worksheet, array_keys(BdFieldPlacement::LINE_FIELDS)) === array(),
        json_encode(array_values(array_intersect($worksheet, array_keys(BdFieldPlacement::LINE_FIELDS)))));

    // ── C. which fields, and which are read-only ─────────────────────────────────────────────────────
    check('C1 the section holds exactly Bench Dogs\' 17 line fields, in the mapping\'s order, each drawn once',
        bd_field_names($section) === $bdLine && array_sum(array_map(static function ($n) use ($built) {
            return bd_drawn($built, $n);
        }, $bdLine)) === 17, json_encode(bd_field_names($section)));
    $flags = array();
    foreach ((array) ($section['fields'] ?? array()) as $entry) {
        $flags[bd_name($entry)] = !empty($entry['readonly']);
    }
    $wanted = array();
    foreach ($bdLine as $name) {
        $wanted[$name] = !in_array($name, BD_SENT_TO_KINETIC, true);
    }
    check('C2 read-only except the two fields the seller sends to Kinetic (sales category, quantity quoted)',
        $flags === $wanted, json_encode(array_diff_assoc($flags, $wanted) + array_diff_assoc($wanted, $flags)));
    check('C3 each field carries its own label', $section !== null && array_filter((array) $section['fields'],
        static function ($e) {
            return ($e['label'] ?? '') !== BdKineticFields::labelKey($e['name']);
        }) === array());

    // ── D. building again changes nothing ────────────────────────────────────────────────────────────
    check('D1 the overlays reached three times draw what two times draw', bd_build($fresh, 3) === $built);
    check('D2 the overlays over their own output change nothing', bd_build($built) === $built);
    check('D3 a build writes nothing: no save, and the stored view has no section',
        ViewdefManager::$saves === $saves && bd_panel(ViewdefManager::$stored['Products']['record'], BD_SECTION)
        === null);

    // ── E. ERP-Epicor re-applies its layout (an upgrade or reinstall) ─────────────────────────────────
    $again = bd_install($fresh);
    $builtAgain = bd_build($again);
    check('E1 after ERP-Epicor re-applies its layout the section is still directly under the cost panel',
        bd_directly_under($builtAgain) && $builtAgain === $built, json_encode(bd_panel_names($builtAgain)));
    // The view an earlier ERP-Epicor left: the panel open, and the rule that hid it while its values were empty.
    $older = $fresh;
    $retired = new ReflectionMethod(ProductsLayout::class, 'retiredCostWorksheetPanelDependencies');
    $older['dependencies'] = $retired->invoke(new ProductsLayout());
    foreach ($older['panels'] as $i => $panel) {
        if (($panel['name'] ?? '') === BD_COST) {
            $older['panels'][$i]['panelDefault'] = 'expanded';
        }
    }
    $upgraded = bd_install($older);
    $builtUpgraded = bd_build($upgraded);
    check('E2 the upgrade from a release that hid the panel: rule gone, panel collapsed, section directly under it',
        empty($upgraded['dependencies']) && (bd_panel($upgraded, BD_COST)['panelDefault'] ?? '') === 'collapsed'
        && bd_directly_under($builtUpgraded), json_encode(bd_panel_names($builtUpgraded)));
    $replaced = bd_install($fresh, true);
    check('E3 after a REPLACE install of ERP-Epicor the section is still directly under the cost panel',
        bd_directly_under(bd_build($replaced)), json_encode(bd_panel_names(bd_build($replaced))));

    // ── F. a section an earlier save stored elsewhere ────────────────────────────────────────────────
    // A save of the served view (Studio, for one) would store the section where it was drawn at the time.
    $storedSection = array('name' => BD_SECTION, 'label' => BD_SECTION, 'columns' => 2, 'fields' => $bdLine);
    $storedLast = $fresh;
    $storedLast['panels'][] = $storedSection;
    $builtLast = bd_build($storedLast);
    check('F1 a section stored last, under the hidden panel, is drawn directly under the cost panel, its fields once',
        bd_directly_under($builtLast) && count(array_keys(bd_panel_names($builtLast), BD_SECTION, true)) === 1
        && bd_field_names(bd_panel($builtLast, BD_SECTION)) === $bdLine, json_encode(bd_panel_names($builtLast)));
    // ERP-Epicor's REPLACE install takes its panel out and puts it back before the hidden panel: under a stored section.
    $storedUnder = $fresh;
    array_splice($storedUnder['panels'], 3, 0, array($storedSection));
    $storedUnder = bd_install($storedUnder, true);
    $builtUnder = bd_build($storedUnder);
    check('F2 premise: a REPLACE install over a section stored under the cost panel leaves it ABOVE that panel',
        bd_panel_names($storedUnder) === array('panel_header', 'panel_body', BD_SECTION, BD_COST, 'panel_hidden'),
        json_encode(bd_panel_names($storedUnder)));
    check('F3 that section is drawn directly under the cost panel again, its fields once',
        bd_directly_under($builtUnder) && bd_field_names(bd_panel($builtUnder, BD_SECTION)) === $bdLine,
        json_encode(bd_panel_names($builtUnder)));

    // ── T. the two shapes two Bench Sugars served after rc107 (fixtures/rc108, read r45) ──────────────
    $read = json_decode((string) file_get_contents(__DIR__ . '/fixtures/rc108/products_record_read_r45.json'), true);
    $other = $read['without_customer_package'];
    $editable = array();
    foreach ((array) (bd_panel($built, BD_SECTION)['fields'] ?? array()) as $entry) {
        if (empty($entry['readonly'])) {
            $editable[] = bd_name($entry);
        }
    }
    check('T0 control: where nothing of Bench Dogs\' is stored, the view is drawn as that Sugar read it',
        bd_panel_names($built) === $other['panels']
        && bd_field_names(bd_panel($built, BD_COST)) === $other['cost_worksheet_fields']
        && bd_field_names(bd_panel($built, BD_SECTION)) === $other['section_fields']
        && $editable === $other['section_editable'], json_encode(array(bd_panel_names($built), $editable)));

    /**
     * ERP-Epicor's stored view with the cost panel and the body as a Studio save left them: a field as Studio stores
     * it; '' or an array (a blank cell as stored, e.g. [] or ['span' => 6]) taken as it is.
     */
    function bd_studio_shape(array $fresh, array $costNames, array $bodyAdds): array
    {
        $own = array();
        foreach ((array) bd_panel($fresh, BD_COST)['fields'] as $entry) {
            $own[bd_name($entry)] = $entry;
        }
        $entry = static function ($name) use ($own) {
            if (is_array($name) || $name === '') {
                return $name;
            }

            return $own[$name] ?? array('name' => $name, 'label' => BdKineticFields::labelKey($name));
        };
        foreach ($fresh['panels'] as $i => $panel) {
            if (($panel['name'] ?? '') === BD_COST) {
                $fresh['panels'][$i]['fields'] = array_map($entry, $costNames);
            } elseif (($panel['name'] ?? '') === 'panel_body') {
                $fresh['panels'][$i]['fields'] = array_merge($panel['fields'], array_map($entry, $bodyAdds));
            }
        }

        return $fresh;
    }

    $t9 = $read['with_customer_package'];
    $stored = bd_studio_shape($fresh, $t9['cost_worksheet_fields'], $t9['body_bench_fields']);
    check('T1 premise: the stored shape is what that Sugar read, and holds no Cost & capacity section',
        bd_panel_names($stored) === $t9['panels'] && bd_field_names(bd_panel($stored, BD_COST))
        === $t9['cost_worksheet_fields'], json_encode(bd_panel_names($stored)));
    $inPanel = array_values(array_intersect($t9['cost_worksheet_fields'], $bdLine));
    $inBody = $t9['body_bench_fields'];
    $mappingOrder = array_values(array_intersect($bdLine, $inPanel));
    $saves = ViewdefManager::$saves;
    $builtT9 = bd_build($stored);
    check('T2 Bench Dogs\' fields stored inside the cost worksheet panel: the section is drawn directly under it',
        bd_directly_under($builtT9) && bd_panel_names($builtT9)
        === array('panel_header', 'panel_body', BD_COST, BD_SECTION, 'panel_hidden'), json_encode(bd_panel_names($builtT9)));
    check('T3 the cost worksheet panel then holds only its eight values, in its own order, still collapsed',
        bd_field_names(bd_panel($builtT9, BD_COST)) === $worksheet
        && (bd_panel($builtT9, BD_COST)['panelDefault'] ?? '') === 'collapsed',
        json_encode(bd_field_names(bd_panel($builtT9, BD_COST))));
    $sectionT9 = bd_panel($builtT9, BD_SECTION);
    check('T4 the section holds the 14 that were in the panel, in the mapping\'s order, each drawn once on the view',
        count($inPanel) === 14 && bd_field_names($sectionT9) === $mappingOrder
        && array_sum(array_map(static function ($n) use ($builtT9) {
            return bd_drawn($builtT9, $n);
        }, $bdLine)) === 17, json_encode(bd_field_names($sectionT9)));
    $flagsT9 = array();
    foreach ((array) ($sectionT9['fields'] ?? array()) as $entry) {
        $flagsT9[bd_name($entry)] = !empty($entry['readonly']);
    }
    $wantedT9 = array();
    foreach ($mappingOrder as $name) {
        $wantedT9[$name] = !in_array($name, BD_SENT_TO_KINETIC, true);
    }
    check('T5 there, read-only except Quantity Quoted, with their own labels', $flagsT9 === $wantedT9
        && array_filter((array) ($sectionT9['fields'] ?? array()), static function ($e) {
            return ($e['label'] ?? '') !== BdKineticFields::labelKey($e['name']);
        }) === array(), json_encode($flagsT9));
    $bodyT9 = array();
    foreach ((array) bd_panel($builtT9, 'panel_body')['fields'] as $entry) {
        if (in_array(bd_name($entry), $inBody, true)) {
            $bodyT9[bd_name($entry)] = !empty($entry['readonly']);
        }
    }
    check('T6 control: the three a Studio save put in the body stay there (Sales Category editable)',
        $bodyT9 === array('e_sales_category_c' => false, 'e_expected_qty_c' => true, 'e_expected_qty_uom_c' => true)
        && bd_field_names(bd_panel($builtT9, 'panel_body')) === bd_field_names(bd_panel(array('panels' =>
            \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::strip($stored['panels'],
            \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::LINE_FIELDS, array())), 'panel_body')),
        json_encode($bodyT9));
    check('T7 building again changes nothing, and nothing is stored', bd_build($stored, 3) === $builtT9
        && bd_build($builtT9) === $builtT9 && ViewdefManager::$saves === $saves);
    // An administrator's own field (the customer's comment field, not one of ours) and a blank cell between two values.
    $adminNames = $t9['cost_worksheet_fields'];
    array_splice($adminNames, array_search('erp_labor_cost', $adminNames, true) + 1, 0, array('e_line_comment_c'));
    array_splice($adminNames, array_search('erp_subcontract_cost', $adminNames, true) + 1, 0, array(''));
    $builtAdmin = bd_build(bd_studio_shape($fresh, $adminNames, $inBody));
    $adminCost = bd_field_names(bd_panel($builtAdmin, BD_COST));
    $after = static function (array $names, string $anchor) {
        $at = array_search($anchor, $names, true);

        return $at === false ? null : ($names[$at + 1] ?? null);
    };
    check('T8 control: a field that is not ours and a blank cell between two cost values stay where they were',
        $after($adminCost, 'erp_labor_cost') === 'e_line_comment_c' && $after($adminCost, 'erp_subcontract_cost') === ''
        && !in_array('e_line_comment_c', bd_field_names(bd_panel($builtAdmin, BD_SECTION)), true),
        json_encode($adminCost));
    $upgradedT9 = bd_build(bd_install($stored));
    $replacedT9 = bd_build(bd_install($stored, true));
    $tidy = static function (array $view) use ($worksheet, $bdLine) {
        return bd_directly_under($view) && bd_field_names(bd_panel($view, BD_COST)) === $worksheet
            && array_sum(array_map(static function ($n) use ($view) {
                return bd_drawn($view, $n);
            }, $bdLine)) === 17;
    };
    check('T9 after ERP-Epicor re-applies its layout over that stored view (upgrade, REPLACE) the same is drawn',
        $tidy($upgradedT9) && $tidy($replacedT9), json_encode(array(bd_panel_names($upgradedT9),
        bd_field_names(bd_panel($upgradedT9, BD_COST)), bd_panel_names($replacedT9))));
    // Two of ours dragged back into the panel, in two rows of their own as Studio writes rows: one after a blank,
    // one before one (rc109: Studio writes whole rows, so a blank sharing a row with a cost value is U9's case).
    $dragged = $builtT9;
    foreach ($dragged['panels'] as $i => $panel) {
        if (($panel['name'] ?? '') === BD_SECTION) {
            $dragged['panels'][$i]['fields'] = array_values(array_filter($panel['fields'], static function ($e) {
                return !in_array(bd_name($e), array('e_material_pct_c', 'e_total_cost_c'), true);
            }));
        } elseif (($panel['name'] ?? '') === BD_COST) {
            array_splice($dragged['panels'][$i]['fields'], 6, 0, array('', 'e_material_pct_c', 'e_total_cost_c', ''));
        }
    }
    $builtDragged = bd_build($dragged);
    check('T10 a field of ours dragged back into the panel returns to the section; the blank cells around it go',
        $tidy($builtDragged) && count(array_intersect(array('e_material_pct_c', 'e_total_cost_c'),
        bd_field_names(bd_panel($builtDragged, BD_SECTION)))) === 2, json_encode(array(bd_panel_names($builtDragged),
        bd_field_names(bd_panel($builtDragged, BD_COST)))));

    // ── U. the blank cells as Studio stores them (rc109; fixtures/rc109, that Sugar's read after rc108) ──
    $u = json_decode((string) file_get_contents(__DIR__ . '/fixtures/rc109/products_record_studio_blanks.json'), true);
    $storedU = bd_studio_shape($fresh, $u['cost_worksheet_fields'], $u['body_bench_fields']);
    $storedCostU = bd_panel($storedU, BD_COST);
    // What that Sugar drew after rc108: our fields gone, every stored blank cell left where it was.
    $keptBlanks = $storedCostU;
    $keptBlanks['fields'] = array_values(array_filter((array) $storedCostU['fields'], static function ($e) use ($bdLine) {
        return !in_array(bd_name($e), $bdLine, true);
    }));
    check('U1 premise: the stored order is r45\'s, its blank cells are arrays, and keeping them draws what it drew after rc108',
        bd_field_names($storedCostU) === $t9['cost_worksheet_fields']
        && !in_array('', (array) $storedCostU['fields'], true)
        && bd_cells($keptBlanks) === array($u['after_rc108_read']['record_cells'], $u['after_rc108_read']['empty_record_cells'])
        && (bd_rows($keptBlanks)[0] ?? null) === $u['after_rc108_read']['first_row'],
        json_encode(array(bd_cells($keptBlanks), bd_rows($keptBlanks))));
    $saves = ViewdefManager::$saves;
    $builtU = bd_build($storedU);
    $costU = bd_panel($builtU, BD_COST);
    check('U2 on that shape the cost worksheet panel holds exactly its eight values, in their order, no blank cell',
        bd_field_names($costU) === $worksheet, json_encode(bd_field_names($costU)));
    check('U3 it draws 8 record cells, none empty, two values a row (as the Sugar without the package draws it)',
        bd_cells($costU) === array($u['other_sugar_after_rc108_read']['record_cells'], 0)
        && bd_rows($costU) === array_chunk($worksheet, 2), json_encode(array(bd_cells($costU), bd_rows($costU))));
    $forms = array();
    foreach (array('span' => array('span' => 6), 'string' => '', 'unnamed' => array('name' => '')) as $form => $blank) {
        $names = array();
        foreach ($u['cost_worksheet_fields'] as $n) {
            $names[] = is_array($n) ? $blank : $n;
        }
        $forms[$form] = bd_field_names(bd_panel(bd_build(bd_studio_shape($fresh, $names, $u['body_bench_fields'])), BD_COST));
    }
    check('U4 the same eight with each blank cell stored as a bare span, as \'\' or with an empty name',
        $forms === array('span' => $worksheet, 'string' => $worksheet, 'unnamed' => $worksheet), json_encode($forms));
    check('U5 the section is still directly under the panel with the 14 in the mapping\'s order; the body keeps its three',
        bd_directly_under($builtU) && bd_field_names(bd_panel($builtU, BD_SECTION)) === $mappingOrder
        && bd_field_names(bd_panel($builtU, 'panel_body')) === bd_field_names(bd_panel($builtT9, 'panel_body')),
        json_encode(bd_panel_names($builtU)));
    check('U6 control: where nothing of ours is stored the cost panel is served as stored, 8 record cells, none empty',
        bd_panel($built, BD_COST) === $costPanel && bd_cells(bd_panel($built, BD_COST)) === array(8, 0),
        json_encode(bd_cells(bd_panel($built, BD_COST))));
    // An administrator's own field: in a row of its own with a blank, and in a row it shared with one of ours.
    $adminU = $u['cost_worksheet_fields'];
    array_splice($adminU, array_search('erp_misc_cost', $adminU, true) + 1, 0, array('e_line_comment_c', array()));
    $adminU[array_search('e_total_markup_price_c', $adminU, true) + 1] = 'e_line_note_c';
    $costAdmin = bd_panel(bd_build(bd_studio_shape($fresh, $adminU, $u['body_bench_fields'])), BD_COST);
    $note = null;
    foreach ((array) ($costAdmin['fields'] ?? array()) as $entry) {
        $note = bd_name($entry) === 'e_line_note_c' ? $entry : $note;
    }
    check('U7 control: an admin\'s own field stays, its blank too; one that shared a row with ours takes that row',
        bd_rows($costAdmin) === array(array('erp_material_cost', 'erp_labor_cost'),
            array('erp_material_burden', 'erp_labor_burden'), array('erp_subcontract_cost', 'erp_misc_cost'),
            array('e_line_comment_c', ''), array('e_line_note_c'), array('erp_quoted_markup_pct', 'erp_quoted_profit_pct'))
        && (int) ($note['span'] ?? 0) === 12, json_encode(array(bd_rows($costAdmin), $note)));
    // Blank cells an admin placed outside the panel: in the body right after one of ours, and in the hidden panel.
    $outside = bd_studio_shape($fresh, $u['cost_worksheet_fields'], array_merge($u['body_bench_fields'], array(array())));
    foreach ($outside['panels'] as $i => $panel) {
        if (($panel['name'] ?? '') === 'panel_hidden') {
            array_splice($outside['panels'][$i]['fields'], 1, 0, array(array('span' => 6)));
        }
    }
    $builtOutside = bd_build($outside);
    $blanksIn = static function (array $view, string $panel) {
        return count(array_keys(bd_field_names(bd_panel($view, $panel)), '', true));
    };
    $bodyOutside = bd_field_names(bd_panel($builtOutside, 'panel_body'));
    check('U8 control: a blank cell outside the cost panel stays, even right after one of ours in the body',
        $blanksIn($builtOutside, 'panel_body') === $blanksIn($outside, 'panel_body')
        && $blanksIn($builtOutside, 'panel_hidden') === $blanksIn($outside, 'panel_hidden')
        && $blanksIn($outside, 'panel_body') >= 1 && $blanksIn($outside, 'panel_hidden') >= 1
        && ($bodyOutside[array_search('e_expected_qty_uom_c', $bodyOutside, true) + 1] ?? null) === '',
        json_encode(array($bodyOutside, bd_field_names(bd_panel($builtOutside, 'panel_hidden')))));
    // Blank cells an admin placed among the values: a row of its own, full width, and a blank before a value after
    // one of ours drawn full width (only its row goes, as Studio's own removal plucks it).
    $amongU = $u['cost_worksheet_fields'];
    array_splice($amongU, array_search('erp_labor_burden', $amongU, true) + 1, 0, array(array('span' => 12)));
    $at = array_search('e_total_markup_price_c', $amongU, true);
    array_splice($amongU, $at, 2, array(array('name' => 'e_total_markup_price_c', 'span' => 12), array()));
    $amongU[] = array();
    $costAmong = bd_panel(bd_build(bd_studio_shape($fresh, $amongU, $u['body_bench_fields'])), BD_COST);
    check('U9 control: blank cells sharing no row with ours stay (a full-width row; a blank before a value)',
        bd_rows($costAmong) === array(array('erp_material_cost', 'erp_labor_cost'),
            array('erp_material_burden', 'erp_labor_burden'), array(''), array('erp_subcontract_cost', 'erp_misc_cost'),
            array('', 'erp_quoted_markup_pct'), array('erp_quoted_profit_pct', '')), json_encode(bd_rows($costAmong)));
    check('U10 building again changes nothing, and nothing is stored', bd_build($storedU, 3) === $builtU
        && bd_build($builtU) === $builtU && ViewdefManager::$saves === $saves);
    $clean = static function (array $view) use ($worksheet) {
        return bd_field_names(bd_panel($view, BD_COST)) === $worksheet && bd_cells(bd_panel($view, BD_COST)) === array(8, 0)
            && bd_directly_under($view);
    };
    $upgradedU = bd_build(bd_install($storedU));
    $replacedU = bd_build(bd_install($storedU, true));
    check('U11 the upgrade over that Sugar as stored now: after ERP-Epicor re-applies its layout (upgrade, REPLACE) the same eight',
        $clean($upgradedU) && $clean($replacedU), json_encode(array(bd_rows(bd_panel($upgradedU, BD_COST)),
        bd_rows(bd_panel($replacedU, BD_COST)))));

    // ── G. controls ──────────────────────────────────────────────────────────────────────────────────
    $stockView = ViewdefManager::$stock['Products']['record'];
    $builtStock = bd_build($stockView);
    check('G1 control: a view with no cost worksheet panel still draws the section, last, as before',
        bd_panel_names($builtStock) === array('panel_header', 'panel_body', 'panel_hidden', BD_SECTION)
        && array_intersect($bdLine, bd_field_names(bd_panel($builtStock, BD_SECTION))) === $bdLine,
        json_encode(bd_panel_names($builtStock)));
    bd_tenant(false);
    // The other overlay in the directory still takes the line discount off; nothing else may change.
    $noBench = $fresh;
    $noBench['panels'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::strip($fresh['panels'],
        \Sugarcrm\Sugarcrm\custom\BenchDogs\BdHiddenFields::LINE_FIELDS, array());
    check('G2 control: a tenant without Bench Dogs\' fields is served ERP-Epicor\'s view, no section added',
        bd_build($fresh) === $noBench && bd_panel($noBench, BD_SECTION) === null);
    bd_tenant();
    $unanchored = $fresh;
    $unanchored['panels'] = BdFieldPlacement::place($fresh['panels'], BdFieldPlacement::fieldDefs('Products'),
        BdFieldPlacement::LINE_FIELDS, BdFieldPlacement::COST_CAPACITY_PANEL);
    check('G3 control: placed with no anchor the section lands last, and the position check says so',
        !bd_directly_under($unanchored) && end($unanchored['panels'])['name'] === BD_SECTION,
        json_encode(bd_panel_names($unanchored)));

    check('Z no PHP notice or warning on any path', $GLOBALS['bd_notices'] === array(),
        json_encode($GLOBALS['bd_notices']));

    $failed = count(array_filter($checks, static function ($c) {
        return !$c[1];
    }));
    printf("\n%d checks, %d failed\n", count($checks), $failed);
    exit($failed ? 1 : 0);
}
