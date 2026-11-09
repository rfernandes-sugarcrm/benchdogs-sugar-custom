<?php

/**
 * The ERP order's lines total on a Bench Dogs tenant: ERP-Epicor's own ERP Line Total (erp_line_total) drawn right
 * after its ERP Misc. Charges on the record view, its company-currency twin not drawn, and the Bench Dogs copy it
 * replaced (e_total_charges_c, a Studio field the tenant keeps) drawn on neither the record view nor the list view.
 *
 * Run:  php scripts/tests/bd_order_line_total_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT IS REAL: ERP-Core's own ERP_Orders record view, list view and vardefs of ERP-Epicor 1.2.107 (before ERP Line
 * Total) and 1.2.112 (byte copies under fixtures/rc111/, held by test_rc111_layout_pins.py); ERP-Epicor's own install
 * of the order's record view (BaseErpLayout's setFlatPdfActions and setErpDocumentRecordLayout, as its post_execute
 * calls them; they store the effective view on every install, so a tenant that had ERP-Epicor before 1.2.111 keeps a
 * stored view without ERP Line Total after the upgrade); this package's shipped overlays for the two views, compiled
 * the way ModuleInstaller::mergeExtensionFiles() compiles a sidecar view directory (plain fragments in name order,
 * then the _override ones) and included twice per build, as MetaDataFiles::getClientFileContents() reaches them.
 * WHAT IS FAKED: ViewdefManager (the stored custom view, else the stock one; it never reads a compiled .ext.php),
 * Sugar's caches, BeanFactory, and VardefManager (its 'basic' template gives the id and name the overlays ask for;
 * the Bench Dogs Studio fields are added the way Studio's fields_meta_data rows merge into the vardefs).
 *
 * IF IT WERE BROKEN you would see:
 *   A1-A4 FAIL - the upgraded tenant's order shows no ERP Line Total, shows it somewhere other than right after ERP
 *                Misc. Charges, editable, or still shows the Bench Dogs copy (the release before this one drew
 *                e_total_charges_c, labelled "ERP Line Total", and not the product field);
 *   A5, A6 FAIL - the company-currency twin drawn, or Project Manager / Engineer lost;
 *   A0, B*, C1, C2 (premise and controls) FAIL - ERP-Epicor's install no longer behaves as measured, a view that
 *                already draws ERP Line Total changed, or a field the tenant lacks (an older ERP-Epicor) drawn;
 *   C3, D* FAIL - the Bench Dogs copy still drawn where a stored layout holds it, the row it leaves holding a blank,
 *                or ERP Line Total drawn twice where an administrator already placed it;
 *   E1 FAIL - ERP Line Total drawn at a guessed place where the view has no ERP Misc. Charges;
 *   F*  FAIL - the Bench Dogs copy still a column (or in the column chooser) of the ERP Orders list, or another
 *                column moved;
 *   G1 FAIL - with the order's fields unreadable, a field drawn on a guess or the Bench Dogs copy kept;
 *   R*  FAIL - a rebuild that differs from the first build.
 */

namespace Sugarcrm\Sugarcrm\MetaData {
    /** The stored custom view, else the stock one; a compiled .ext.php is never read, as in Sugar. */
    class ViewdefManager
    {
        public static $stock = array();
        public static $stored = array();

        public function loadViewdef($platform, $module, $view, $loadBase = false, $isLayout = false)
        {
            return self::$stored[$module][$view] ?? (self::$stock[$module][$view] ?? array());
        }

        public function saveViewdef($viewdef, $module, $platform, $view, $isLayout = false)
        {
            self::$stored[$module][$view] = $viewdef;
        }
    }
}

namespace {
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
        /** When set, the order's vardefs cannot be read (fieldDefs() answers none). */
        public static $unreadable = false;

        public static function getObjectName($module)
        {
            return $module === 'ERP_Orders' && !self::$unreadable ? 'ERP_Order' : '';
        }
    }

    class VardefManager
    {
        public static function loadVardef($module, $object)
        {
        }

        /** Sugar's 'basic' template's id and name, the two template fields the overlays read. */
        public static function createVardef($module, $object, $templates = array())
        {
            $fields = $GLOBALS['dictionary'][$object]['fields'] ?? array();
            $fields['id'] = ($fields['id'] ?? array()) + array('name' => 'id', 'type' => 'id');
            $fields['name'] = ($fields['name'] ?? array()) + array('name' => 'name', 'type' => 'name');
            $GLOBALS['dictionary'][$object]['fields'] = $fields;
        }
    }

    const BD_FIX = __DIR__ . '/fixtures/rc111';
    const BD_DETAIL = 'LBL_RECORDVIEW_PANEL_ORDER_DETAIL';
    const BD_RETIRED = 'e_total_charges_c';
    const BD_LINE_TOTAL = 'erp_line_total';

    $bdPkg = dirname(__DIR__, 2) . '/sugar-sell/BenchDogs-Ext';
    require_once $bdPkg . '/custom/src/BenchDogs/BdKineticFields.php';
    require_once $bdPkg . '/custom/src/BenchDogs/BdFieldPlacement.php';

    // ERP-Epicor's layout installer, at the path the two packages install it (its bytes are 1.2.112's).
    $bdRoot = sys_get_temp_dir() . '/bd-order-line-total-' . getmypid();
    @mkdir("$bdRoot/custom/include/scripts", 0777, true);
    @mkdir("$bdRoot/include/TemplateHandler", 0777, true);
    copy(__DIR__ . '/fixtures/rc107/BaseErpLayout.php.txt', "$bdRoot/custom/include/scripts/BaseErpLayout.php");
    file_put_contents("$bdRoot/include/TemplateHandler/TemplateHandler.php", "<?php\n");
    set_include_path($bdRoot . PATH_SEPARATOR . get_include_path());
    require_once 'custom/include/scripts/BaseErpLayout.php';

    /** ERP-Epicor's own layout class for the order's two install steps (QuotePdfMenuLayout's are these, inherited). */
    class BdOrderLayout extends BaseErpLayout
    {
        public function install(): void
        {
        }

        public function uninstall(): void
        {
        }
    }

    /** The shipped overlays of one ERP_Orders view directory, compiled as Sugar compiles it; none when absent. */
    function bd_compile(string $view, string $root): string
    {
        $dir = dirname(__DIR__, 2) . "/sugar-sell/BenchDogs-Ext/custom/Extension/modules/ERP_Orders/Ext/clients/base/views/$view";
        $files = glob("$dir/*.php") ?: array();
        sort($files, SORT_STRING);
        $plain = array();
        $override = array();
        foreach ($files as $file) {
            $body = str_replace(array('<?php', '?>', '<?PHP', '<?'), '', (string) file_get_contents($file));
            if (strpos(basename($file), '_override') === 0) {
                $override[] = $body;
            } else {
                $plain[] = $body;
            }
        }
        $out = "$root/$view.ext.php";
        file_put_contents($out, "<?php\n" . implode("\n", array_merge($plain, $override)));

        return $out;
    }

    define('BD_RECORD_EXT', bd_compile('record', $bdRoot));
    define('BD_LIST_EXT', bd_compile('list', $bdRoot));
    register_shutdown_function(static function () use ($bdRoot) {
        @unlink(BD_RECORD_EXT);
        @unlink(BD_LIST_EXT);
        @unlink("$bdRoot/custom/include/scripts/BaseErpLayout.php");
        @unlink("$bdRoot/include/TemplateHandler/TemplateHandler.php");
        foreach (array('custom/include/scripts', 'custom/include', 'custom', 'include/TemplateHandler', 'include', '')
            as $bdSub) {
            @rmdir(rtrim("$bdRoot/$bdSub", '/'));
        }
    });

    /** One view of ERP-Core's own file for ERP_Orders, as php fills $viewdefs from it. */
    function bd_base(string $file, string $view): array
    {
        $viewdefs = array();
        include BD_FIX . '/' . $file;

        return $viewdefs['ERP_Orders']['base']['view'][$view];
    }

    /** The tenant's ERP order fields: ERP-Core's vardefs of the release, plus the Studio fields it holds. */
    function bd_tenant(string $vardefs, array $studio): void
    {
        global $dictionary;
        $dictionary = array();
        include BD_FIX . '/' . $vardefs;
        foreach ($studio as $name => $type) {
            $dictionary['ERP_Order']['fields'][$name] = array(
                'name' => $name, 'vname' => 'LBL_' . strtoupper($name), 'type' => $type, 'source' => 'custom_fields',
            );
        }
    }

    /** ERP-Epicor's install of the order's record view over what the tenant stored (null: nothing), and what it stores. */
    function bd_install(array $stock, ?array $stored): array
    {
        ViewdefManager::$stock = array('ERP_Orders' => array('record' => $stock));
        ViewdefManager::$stored = $stored === null ? array() : array('ERP_Orders' => array('record' => $stored));
        (new BdOrderLayout())->setFlatPdfActions('ERP_Orders', true);
        (new BdOrderLayout())->setErpDocumentRecordLayout('ERP_Orders');

        return ViewdefManager::$stored['ERP_Orders']['record'] ?? $stock;
    }

    /** The view as a metadata build serves it: the stored view, then the compiled overlays, reached $times times. */
    function bd_build(string $view, array $stored, int $times = 2): array
    {
        $viewdefs = array('ERP_Orders' => array('base' => array('view' => array($view => $stored))));
        for ($i = 0; $i < $times; $i++) {
            include $view === 'list' ? BD_LIST_EXT : BD_RECORD_EXT;
        }

        return $viewdefs['ERP_Orders']['base']['view'][$view];
    }

    function bd_name($entry): string
    {
        return is_string($entry) ? $entry : (is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : '');
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

    /** The one top-level cell named $field on the view, or null. */
    function bd_cell(array $view, string $field)
    {
        foreach ($view['panels'] ?? array() as $panel) {
            foreach ((array) ($panel['fields'] ?? array()) as $entry) {
                if (bd_name($entry) === $field) {
                    return $entry;
                }
            }
        }

        return null;
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

    /** A panel's rows as the record view lays them out (Sugar 26.1 GridBuilder.js): names, '' for an unnamed cell. */
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

    /** $view with $cell inserted into the panel $panelName right after the cell named $after ('' = at the end). */
    function bd_with_cell(array $view, string $panelName, string $after, $cell): array
    {
        foreach ($view['panels'] as $p => $panel) {
            if (($panel['name'] ?? '') !== $panelName) {
                continue;
            }
            $fields = array_values($panel['fields']);
            $at = count($fields);
            foreach ($fields as $i => $entry) {
                if ($after !== '' && bd_name($entry) === $after) {
                    $at = $i + 1;
                }
            }
            array_splice($fields, $at, 0, array($cell));
            $view['panels'][$p]['fields'] = $fields;
        }

        return $view;
    }

    /** $view with every top-level cell named $field taken out, nothing else changed. */
    function bd_without(array $view, string $field): array
    {
        foreach ($view['panels'] as $p => $panel) {
            $view['panels'][$p]['fields'] = array_values(array_filter((array) ($panel['fields'] ?? array()),
                static function ($entry) use ($field) {
                    return bd_name($entry) !== $field;
                }));
        }

        return $view;
    }

    /** The view's panels other than $skip, by name, as they are. */
    function bd_other_panels(array $view, array $skip): array
    {
        $out = array();
        foreach ($view['panels'] ?? array() as $panel) {
            if (!in_array($panel['name'] ?? '', $skip, true)) {
                $out[] = $panel;
            }
        }

        return $out;
    }

    // The two Studio fields a Bench Dogs tenant keeps on its orders, and the copy of the lines total rc100-rc110 created.
    $bdKinetic = array('e_project_manager_c' => 'varchar', 'e_engineer_c' => 'varchar');
    $bdHeld = $bdKinetic + array(BD_RETIRED => 'currency');
    // A cell for the copy as Studio stores a field dragged onto the layout.
    $bdStudioCell = array('name' => BD_RETIRED, 'label' => 'LBL_E_TOTAL_CHARGES_C');

    $base107 = bd_base('ERP_Orders.record.1.2.107.php.txt', 'record');
    $base112 = bd_base('ERP_Orders.record.1.2.112.php.txt', 'record');
    // A fresh 1.2.112 tenant's stored view; and a tenant that had ERP-Epicor before 1.2.111, then upgraded to 1.2.112.
    $fresh = bd_install($base112, null);
    $upgraded = bd_install($base112, bd_install($base107, null));
    $freshRows = bd_rows(bd_panel($fresh, BD_DETAIL));

    // ── A: a Bench Dogs tenant upgraded to ERP-Epicor 1.2.112, holding rc110's copy of the lines total ──
    echo "-- A: an upgraded Bench Dogs tenant holding the old copy (the live case)\n";
    check('A0 premise: ERP-Epicor stores ERP Line Total right after ERP Misc. Charges on a fresh tenant, and its '
        . 'upgrade leaves a stored view without it', bd_drawn($upgraded, BD_LINE_TOTAL) === 0
        && in_array('erp_misc_amount', bd_field_names(bd_panel($upgraded, BD_DETAIL)), true)
        && bd_drawn($fresh, BD_LINE_TOTAL) === 1 && $freshRows[3] === array('erp_misc_amount', BD_LINE_TOTAL),
        json_encode($freshRows));
    bd_tenant('ERP_Orders.vardefs.1.2.112.php.txt', $bdHeld);
    $a = bd_build('record', $upgraded);
    $aDetail = bd_field_names(bd_panel($a, BD_DETAIL));
    $aMisc = array_search('erp_misc_amount', $aDetail, true);
    check('A1 ERP Line Total is drawn once, right after ERP Misc. Charges in the Order Detail panel',
        bd_drawn($a, BD_LINE_TOTAL) === 1 && $aMisc !== false && ($aDetail[$aMisc + 1] ?? '') === BD_LINE_TOTAL,
        json_encode($aDetail));
    check("A2 it is read-only, the cell ERP-Core's own view draws", bd_cell($a, BD_LINE_TOTAL) !== null
        && bd_cell($a, BD_LINE_TOTAL) === bd_cell($fresh, BD_LINE_TOTAL), json_encode(bd_cell($a, BD_LINE_TOTAL)));
    check('A3 the old copy (e_total_charges_c) is drawn nowhere on the record view', bd_drawn($a, BD_RETIRED) === 0,
        json_encode(bd_field_names(bd_panel($a, 'panel_body'))));
    check("A4 the Order Detail panel's rows are a fresh 1.2.112 tenant's",
        bd_rows(bd_panel($a, BD_DETAIL)) === $freshRows, json_encode(bd_rows(bd_panel($a, BD_DETAIL))));
    check('A5 its company-currency twin (erp_line_total_usdollar) is not drawn',
        bd_drawn($a, 'erp_line_total_usdollar') === 0);
    $aPm = bd_cell($a, 'e_project_manager_c');
    $aEng = bd_cell($a, 'e_engineer_c');
    check('A6 Project Manager and Engineer are still drawn once each, read-only', bd_drawn($a, 'e_project_manager_c')
        === 1 && bd_drawn($a, 'e_engineer_c') === 1 && !empty($aPm['readonly']) && !empty($aEng['readonly']));
    check('A7 every other panel is as stored', bd_other_panels($a, array(BD_DETAIL, 'panel_body'))
        === bd_other_panels($upgraded, array(BD_DETAIL, 'panel_body')));
    check('R1 a rebuild draws the same view (once, twice, three times)', bd_build('record', $upgraded, 1) === $a
        && bd_build('record', $upgraded, 3) === $a);

    // ── B: a fresh ERP-Epicor 1.2.112 tenant, which this release gives no copy ──
    echo "-- B: a fresh tenant\n";
    bd_tenant('ERP_Orders.vardefs.1.2.112.php.txt', $bdKinetic);
    $b = bd_build('record', $fresh);
    check('B1 the Order Detail panel is exactly as ERP-Epicor stored it', bd_panel($b, BD_DETAIL)
        === bd_panel($fresh, BD_DETAIL));
    check('B2 ERP Line Total is drawn once and its twin not at all', bd_drawn($b, BD_LINE_TOTAL) === 1
        && bd_drawn($b, 'erp_line_total_usdollar') === 0);
    bd_tenant('ERP_Orders.vardefs.1.2.112.php.txt', $bdHeld);
    check('B3 a fresh view on a tenant that holds the copy: the Order Detail panel as stored, the copy not drawn',
        bd_panel(bd_build('record', $fresh), BD_DETAIL) === bd_panel($fresh, BD_DETAIL)
        && bd_drawn(bd_build('record', $fresh), BD_RETIRED) === 0);

    // ── C: an older ERP-Epicor (1.2.107: no erp_line_total field) ──
    echo "-- C: an older ERP-Epicor\n";
    $stored107 = bd_install($base107, null);
    bd_tenant('ERP_Orders.vardefs.1.2.107.php.txt', $bdHeld);
    $c = bd_build('record', $stored107);
    check('C1 ERP Line Total is not drawn where the tenant has no such field', bd_drawn($c, BD_LINE_TOTAL) === 0);
    check('C2 the Order Detail panel is exactly as stored', bd_panel($c, BD_DETAIL) === bd_panel($stored107, BD_DETAIL));
    check('C3 the old copy is drawn nowhere there either', bd_drawn($c, BD_RETIRED) === 0);

    // ── D: a stored layout that holds the copy (a Studio save) ──
    echo "-- D: the copy in a stored layout\n";
    bd_tenant('ERP_Orders.vardefs.1.2.112.php.txt', $bdHeld);
    $d1 = bd_build('record', bd_with_cell($upgraded, BD_DETAIL, 'erp_misc_amount', $bdStudioCell));
    check('D1 the copy stored right after ERP Misc. Charges: ERP Line Total takes its cell, the rows a fresh '
        . "tenant's", bd_rows(bd_panel($d1, BD_DETAIL)) === $freshRows && bd_drawn($d1, BD_RETIRED) === 0
        && bd_drawn($d1, BD_LINE_TOTAL) === 1, json_encode(bd_rows(bd_panel($d1, BD_DETAIL))));
    $d2Stored = bd_with_cell(bd_with_cell($upgraded, 'panel_body', '', $bdStudioCell), 'panel_body', '', '');
    $d2 = bd_build('record', $d2Stored);
    check('D2 the copy stored at the end of the body with a blank beside it: that row goes whole',
        bd_field_names(bd_panel($d2, 'panel_body')) === array_merge(bd_field_names(bd_panel($upgraded, 'panel_body')),
            array('e_project_manager_c', 'e_engineer_c')), json_encode(bd_field_names(bd_panel($d2, 'panel_body'))));
    $d3Stored = bd_with_cell($upgraded, BD_DETAIL, '', '');
    $d3 = bd_build('record', $d3Stored);
    check("D3 control: a blank cell that shares no row with the copy stays", in_array('', bd_field_names(
        bd_panel($d3, BD_DETAIL)), true) && bd_drawn($d3, BD_LINE_TOTAL) === 1,
        json_encode(bd_rows(bd_panel($d3, BD_DETAIL))));
    $d4Stored = bd_with_cell(bd_with_cell($upgraded, 'panel_body', '', array('name' => BD_LINE_TOTAL)), BD_DETAIL,
        'erp_misc_amount', $bdStudioCell);
    $d4 = bd_build('record', $d4Stored);
    check('D4 ERP Line Total an administrator put in the body stays there, once, though the copy sat right after '
        . 'ERP Misc. Charges', bd_drawn($d4, BD_LINE_TOTAL) === 1 && bd_drawn($d4, BD_RETIRED) === 0
        && in_array(BD_LINE_TOTAL, bd_field_names(bd_panel($d4, 'panel_body')), true),
        json_encode(bd_field_names(bd_panel($d4, BD_DETAIL))));
    check('R2 a rebuild of the stored copies draws the same views', bd_build('record',
        bd_with_cell($upgraded, BD_DETAIL, 'erp_misc_amount', $bdStudioCell), 3) === $d1
        && bd_build('record', $d2Stored, 1) === $d2);

    // ── E: an administrator took ERP Misc. Charges off the view ──
    echo "-- E: no ERP Misc. Charges on the view\n";
    $e = bd_build('record', bd_without($upgraded, 'erp_misc_amount'));
    check('E1 ERP Line Total is not drawn at a guessed place, and the copy is still off',
        bd_drawn($e, BD_LINE_TOTAL) === 0 && bd_drawn($e, BD_RETIRED) === 0
        && bd_panel($e, BD_DETAIL) === bd_panel(bd_without($upgraded, 'erp_misc_amount'), BD_DETAIL));

    // ── F: the ERP Orders list view ──
    echo "-- F: the list view\n";
    $list = bd_base('ERP_Orders.list.1.2.112.php.txt', 'list');
    $listShown = $list;
    $listShown['panels'][0]['fields'][] = array('name' => BD_RETIRED, 'label' => 'LBL_E_TOTAL_CHARGES_C',
        'enabled' => true, 'default' => true);
    $listChooser = $list;
    array_splice($listChooser['panels'][0]['fields'], 5, 0, array(array('name' => BD_RETIRED,
        'label' => 'LBL_E_TOTAL_CHARGES_C', 'enabled' => true, 'default' => false)));
    $f1 = bd_build('list', $listShown);
    check('F1 a stored list showing the copy as a column no longer has it', bd_drawn($f1, BD_RETIRED) === 0);
    check('F2 every other column stays, in order, as stored', $f1 === $list);
    check("F3 the copy offered only in the column chooser is gone from it too, the rest as stored",
        bd_build('list', $listChooser) === $list);
    check("F4 control: ERP-Core's own list is untouched", bd_build('list', $list) === $list);
    check('R3 a rebuild of the list draws the same view', bd_build('list', $listShown, 1) === $f1
        && bd_build('list', $listShown, 3) === $f1);

    // ── G: the order's fields cannot be read ──
    echo "-- G: unreadable fields\n";
    BeanFactory::$unreadable = true;
    $gStored = bd_with_cell($upgraded, BD_DETAIL, 'erp_misc_amount', $bdStudioCell);
    $g = bd_build('record', $gStored);
    BeanFactory::$unreadable = false;
    check('G1 nothing is drawn on a guess and the copy is still off', bd_drawn($g, BD_LINE_TOTAL) === 0
        && bd_drawn($g, BD_RETIRED) === 0 && bd_drawn($g, 'e_project_manager_c') === 0);

    check('no PHP notice or warning while building the views', $GLOBALS['bd_notices'] === array(),
        json_encode(array_slice($GLOBALS['bd_notices'], 0, 5)));

    $failed = count(array_filter($checks, static function ($c) {
        return !$c[1];
    }));
    echo "\n" . count($checks) . " checks, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
