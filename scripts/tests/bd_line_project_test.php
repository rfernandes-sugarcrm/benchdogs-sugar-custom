<?php

/**
 * rc117 (owner's ruling of 2026-10-26): the Project is each quote line's own. A Products field bd_project_id (the same
 * ADM projects as the quote's), editable and blank by default, is drawn in the quote line grid and on the line's record
 * view, and the quote fetches it with its lines. The quote-level Project leaves the quote screen (record, create and
 * dashlet views) while its column and data stay. The install that moves it copies each quote's own Project, once,
 * onto that quote's lines whose Project is blank, never over a line that has one.
 *
 * Run:  php scripts/tests/bd_line_project_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT IS REAL: the shipped vardefs, labels and view overlays (included the way SugarEnt 26.1.0 includes a sidecar
 * fragment, more than once per build), BdFieldPlacement, BdAdmRules, BdLineProject and BdInstallHistory, the UAT
 * configuration package's stored Quotes record and recorddashlet views, and its stored Products record view.
 *
 * WHAT IS FAKED: Sugar's SugarQuery (equals, isNotEmpty, in, order, limit, offset over in-memory rows; deleted rows are
 * left out, as SugarQuery leaves them out by default), BeanFactory, VardefManager (the merged Products fields: stock
 * id and quote_id, then this package's vardef), and the database (get_columns, addColumn, updateParams over rows).
 * The quotes table has its own columns, listed by get_columns in the shape Sugar's MysqlManager returns, and a quote
 * read naming a column it lacks throws Doctrine's InvalidFieldNameException with the message a real install logged.
 *
 * RED BEFORE (rc116): no line field, no overlays, no copy; the quote views still draw the quote-level Project.
 * RED BEFORE (rc126): section F. On a Sugar whose quotes table has no bd_project_id column yet, the copy threw
 * "Unknown column 'quotes.bd_project_id'", so the install's step report named line_project_copy as not applied.
 *
 * IF IT WERE BROKEN you would see: no Project column in the grid, or one a seller cannot edit (G); the grid empty
 * because the quote never fetches it (Q3); the quote-level Project still on a quote view (Q, U); a line's own Project
 * overwritten or a blank one left blank by the copy (C); the copy running again on a later install and refilling what
 * a seller cleared (H); the copy writing without its column, or through a save that fires hooks and sends to Kinetic;
 * the copy failing on a Sugar that never had the quote-level Project, or skipping one whose column list it could not
 * read (F).
 */

namespace Doctrine\DBAL\Exception {
    /** Doctrine's exception for a query naming a column the table lacks (Sugar raises it through its DBAL connection). */
    class InvalidFieldNameException extends \RuntimeException
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
    const BD_UAT = __DIR__ . '/../../sugar-sell/BenchDogs-UAT-Config';
    const BD_EXT = BD_PKG . '/custom/Extension/modules';
    const BD_FIELD = 'bd_project_id';

    foreach (array('BdAdmRules', 'BdFieldPlacement', 'BdHiddenFields', 'BdLineProject', 'BdInstallHistory') as $bdClass) {
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

    // ── Sugar, faked ─────────────────────────────────────────────────────────
    class UpgradeHistory
    {
        const STATUS_INSTALLED = 'installed';
        const STATUS_STAGED = 'staged';
    }

    class BdBean
    {
        public $module_name;

        public function __construct(string $module)
        {
            $this->module_name = $module;
        }
    }

    class BeanFactory
    {
        public static function newBean($module)
        {
            return new BdBean((string) $module);
        }

        public static function getObjectName($module)
        {
            return array('Products' => 'Product', 'Quotes' => 'Quote')[$module] ?? '';
        }
    }

    /** The rows each module's table holds; 'deleted' => 1 rows are SugarQuery's to leave out. */
    class BdRows
    {
        public static $tables = array();
        public static $queries = array();
        public static $failRead = '';
    }

    class BdWhere
    {
        public $tests = array();

        public function equals($f, $v)
        {
            $this->tests[] = array('equals', $f, $v);
            return $this;
        }

        public function isNotEmpty($f)
        {
            $this->tests[] = array('notEmpty', $f, null);
            return $this;
        }

        public function in($f, $values)
        {
            $this->tests[] = array('in', $f, array_values((array) $values));
            return $this;
        }
    }

    class SugarQuery
    {
        public $module = '';
        public $options = array();
        public $select = array();
        public $where;
        public $order = array();
        public $limit = null;
        public $offset = 0;

        public function __construct()
        {
            $this->where = new BdWhere();
            BdRows::$queries[] = $this;
        }

        public function from($bean, $options = array())
        {
            $this->module = $bean->module_name;
            $this->options = $options;
        }

        public function select($fields)
        {
            $this->select = $fields;
        }

        public function where()
        {
            return $this->where;
        }

        public function orderBy($field, $dir)
        {
            $this->order = array($field, $dir);
        }

        public function limit($n)
        {
            $this->limit = $n;
        }

        public function offset($n)
        {
            $this->offset = $n;
        }

        public function execute()
        {
            if (BdRows::$failRead === $this->module) {
                throw new RuntimeException("$this->module unreadable");
            }
            if ($this->module === 'Quotes' && ($GLOBALS['db'] ?? null) instanceof BdDb) {
                $GLOBALS['db']->refuseUnknownQuoteColumns($this->select, $this->where->tests);
            }
            $out = array();
            foreach (BdRows::$tables[$this->module] ?? array() as $row) {
                if (!empty($row['deleted'])) {
                    continue;
                }
                foreach ($this->where->tests as [$op, $f, $v]) {
                    $have = $row[$f] ?? null;
                    if (($op === 'equals' && (string) $have !== (string) $v)
                        || ($op === 'notEmpty' && trim((string) $have) === '')
                        || ($op === 'in' && !in_array((string) $have, array_map('strval', $v), true))) {
                        continue 2;
                    }
                }
                $out[] = array_intersect_key($row, array_flip($this->select));
            }
            if ($this->order !== array()) {
                [$by, $dir] = $this->order;
                usort($out, static fn($a, $b) => (strtoupper($dir) === 'DESC' ? -1 : 1)
                    * strcmp((string) ($a[$by] ?? ''), (string) ($b[$by] ?? '')));
            }

            return array_slice($out, $this->offset, $this->limit);
        }
    }

    class VardefManager
    {
        public static $loads = array();

        public static function loadVardef($module, $object, $refresh = false, $params = array())
        {
            self::$loads[] = array($module, $object, $refresh);
            $dictionary = array();
            $dictionary['Product']['fields'] = array(
                'id' => array('name' => 'id', 'type' => 'id'),
                'quote_id' => array('name' => 'quote_id', 'type' => 'id'),
                'date_modified' => array('name' => 'date_modified', 'type' => 'datetime'),
            );
            if (empty($GLOBALS['bd_no_vardef'])) {
                include BD_EXT . '/Products/Ext/Vardefs/bd_line_project.php';
            }
            $GLOBALS['dictionary'][$object] = $dictionary[$object] ?? array();
        }
    }

    /** The database: the products and quotes tables' columns, the products rows, and every write. */
    class BdDb
    {
        public $columns = array('id' => 1, 'quote_id' => 1, 'date_modified' => 1);
        /** The quotes table's own columns, in the shape MysqlManager::get_columns returns (lowercase name => its def). */
        public $quoteColumns = array(
            'id' => array('name' => 'id', 'type' => 'char', 'len' => '36'),
            'name' => array('name' => 'name', 'type' => 'varchar', 'len' => '50'),
            'deleted' => array('name' => 'deleted', 'type' => 'tinyint'),
            BD_FIELD => array('name' => BD_FIELD, 'type' => 'varchar', 'len' => '50'),
        );
        /** What get_columns('quotes') answers when it differs from the table: an empty list, or not an array. */
        public $quoteColumnsRead = null;
        public $columnReads = array();
        public $added = array();
        public $writes = array();
        public $failIds = array();

        public function get_columns($table)
        {
            $this->columnReads[] = $table;
            if ($table === 'quotes') {
                return $this->quoteColumnsRead ?? $this->quoteColumns;
            }

            return $table === 'products' ? $this->columns : array();
        }

        /** A quote read naming a column the quotes table lacks fails as MySQL fails it, with the message a real install logged. */
        public function refuseUnknownQuoteColumns(array $select, array $tests): void
        {
            foreach (array('field list' => $select, 'where clause' => array_column($tests, 1)) as $clause => $fields) {
                foreach ($fields as $field) {
                    if (!isset($this->quoteColumns[$field])) {
                        throw new \Doctrine\DBAL\Exception\InvalidFieldNameException(
                            "An exception occurred while executing a query: Unknown column 'quotes.$field' in '$clause'"
                        );
                    }
                }
            }
        }

        public function addColumn($table, $def)
        {
            $this->added[] = array($table, $def['name'] ?? '', $def['len'] ?? null);
            $this->columns[$def['name']] = 1;
        }

        public function updateParams($table, $defs, $data, array $where = array())
        {
            if (!isset($this->columns[BD_FIELD])) {
                throw new RuntimeException('Unknown column bd_project_id');
            }
            $id = (string) ($where['id'] ?? '');
            if (in_array($id, $this->failIds, true)) {
                throw new RuntimeException('deadlock');
            }
            $this->writes[] = array($table, array_keys($data), array_keys($where), $id);
            foreach (BdRows::$tables['Products'] as $i => $row) {
                if ((string) $row['id'] === $id) {
                    foreach ($data as $k => $v) {
                        BdRows::$tables['Products'][$i][$k] = $v;
                    }
                }
            }
            return true;
        }
    }

    $GLOBALS['log'] = new class {
        public $lines = array();

        public function __call($level, $args)
        {
            $this->lines[] = $level . ': ' . (string) ($args[0] ?? '');
        }
    };

    /** A sidecar fragment included $times into one method scope, as MetaDataFiles includes it. */
    function bd_include(string $file, string $module, string $view, ?array $meta, int $times = 1): ?array
    {
        $type = 'view';
        $platform = 'base';
        $viewdefs = array();
        if ($meta !== null) {
            $viewdefs[$module][$platform][$type][$view] = $meta;
        }
        for ($i = 0; $i < $times; $i++) {
            include $file;
        }

        return $viewdefs[$module]['base']['view'][$view] ?? null;
    }

    /** Every top-level cell name of a view, panel by panel. */
    function bd_cells(?array $meta): array
    {
        $out = array();
        foreach ($meta['panels'] ?? array() as $p) {
            foreach ($p['fields'] ?? array() as $e) {
                $out[] = is_array($e) ? (string) ($e['name'] ?? '') : (string) $e;
            }
        }

        return $out;
    }

    /** The nested line fetch list(s) a quote view carries. */
    function bd_fetch_lists($node): array
    {
        $lists = array();
        if (is_array($node)) {
            if (($node['name'] ?? null) === 'product_bundle_items' && is_array($node['fields'] ?? null)) {
                $lists[] = $node['fields'];
            }
            foreach ($node as $v) {
                $lists = array_merge($lists, bd_fetch_lists($v));
            }
        }

        return $lists;
    }

    function bd_stored(string $file, string $module, string $view): array
    {
        $viewdefs = array();
        include $file;

        return $viewdefs[$module]['base']['view'][$view];
    }

    $class = 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\';

    // ── V. the field ─────────────────────────────────────────────────────────
    $dictionary = array();
    if (is_file(BD_EXT . '/Products/Ext/Vardefs/bd_line_project.php')) {
        include BD_EXT . '/Products/Ext/Vardefs/bd_line_project.php';
    }
    include BD_EXT . '/Quotes/Ext/Vardefs/bd_adm_required_fields.php';
    $line = $dictionary['Product']['fields'][BD_FIELD] ?? array();
    $quoteLevel = $dictionary['Quote']['fields'][BD_FIELD] ?? array();
    check('V1 Products has bd_project_id: an enum of the same ADM projects as the quote\'s (BdAdmRules::projectOptions)',
        ($line['type'] ?? '') === 'enum' && ($line['function'] ?? null) === ($quoteLevel['function'] ?? 'x')
        && ($line['function']['name'] ?? '') === 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAdmRules::projectOptions'
        && ($line['len'] ?? null) === 50, json_encode($line));
    check('V2 blank by default and never pre-picked; editable; audited; not required (a blank stays blank)',
        !array_key_exists('default', $line) && ($line['defaultToBlank'] ?? null) === true && empty($line['readonly'])
        && ($line['audited'] ?? null) === true && empty($line['required']) && !isset($line['required_formula'])
        && empty($line['massupdate']), json_encode($line));
    $mod_strings = array();
    if (is_file(BD_EXT . '/Products/Ext/Language/en_us.bd_line_project.php')) {
        include BD_EXT . '/Products/Ext/Language/en_us.bd_line_project.php';
    }
    check('V3 captioned "Project" on the quote line', ($mod_strings[$line['vname'] ?? ''] ?? null) === 'Project');
    check('V4 the quote-level field stays (its column and data): same name, same options, still audited',
        ($quoteLevel['type'] ?? '') === 'enum' && ($quoteLevel['audited'] ?? null) === true);
    check('V5 but is placed and prefilled nowhere: no layout marker, no prefill, no required key',
        !isset($quoteLevel['erp_layout']) && !isset($quoteLevel['erp_prefill_from_account_latest'])
        && !isset($quoteLevel['erp_required_until_synced']) && !isset($quoteLevel['erp_required_when_options']),
        json_encode($quoteLevel));
    check('V6 the field name is one constant, BdAdmRules::FIELD_PROJECT, shared by the line and the copy',
        defined($class . 'BdLineProject::FIELD') && constant($class . 'BdLineProject::FIELD') === BD_FIELD
        && constant($class . 'BdAdmRules::FIELD_PROJECT') === BD_FIELD);

    // ── G. the quote line grid ───────────────────────────────────────────────
    $GRID_DIR = BD_EXT . '/Products/Ext/clients/base/views/quote-data-group-list';
    $grid = array('panels' => array(array('name' => 'products_quote_data_group_list', 'fields' => array(
        array('name' => 'line_num', 'type' => 'line-num', 'readonly' => true),
        array('name' => 'quantity', 'type' => 'float'),
        array('name' => 'product_template_name', 'type' => 'quote-data-relate'),
        array('name' => 'mft_part_num', 'type' => 'base'),
        array('name' => 'discount_price', 'type' => 'currency'),
        array('name' => 'erp_unit_of_measure'),
        array('name' => 'subtotal', 'type' => 'currency'),
        array('name' => 'discount_field', 'type' => 'fieldset', 'fields' => array(array('name' => 'discount_amount'))),
        array('name' => 'total_amount', 'type' => 'currency'),
    ))));
    // Compiled as ModuleInstaller compiles a view directory: plain files in name order, then the _override ones.
    $gridFiles = glob("$GRID_DIR/*.php") ?: array();
    usort($gridFiles, static fn($a, $b) => array((int) (strpos(basename($a), '_override') === 0), basename($a))
        <=> array((int) (strpos(basename($b), '_override') === 0), basename($b)));
    $compiled = sys_get_temp_dir() . '/bd-line-project-grid-' . getmypid() . '.php';
    $body = "<?php\n";
    foreach ($gridFiles as $f) {
        $body .= "\n" . preg_replace('/^<\?php\s*/', '', (string) file_get_contents($f)) . "\n";
    }
    file_put_contents($compiled, $body);
    $g = bd_include($compiled, 'Products', 'quote-data-group-list', $grid, 3);
    $names = array_map(static fn($e) => $e['name'], $g['panels'][0]['fields']);
    check('G1 the grid draws Project once, right after Product Group (both after Part Number), Line Discount still off',
        $names === array('line_num', 'quantity', 'product_template_name', 'mft_part_num', 'category_name', BD_FIELD,
            'discount_price', 'erp_unit_of_measure', 'subtotal', 'total_amount'), json_encode($names));
    $col = array_values(array_filter($g['panels'][0]['fields'], static fn($e) => $e['name'] === BD_FIELD))[0] ?? array();
    check('G2 editable (no read-only flag), an enum, captioned by the line\'s own label',
        !isset($col['readonly']) && ($col['type'] ?? '') === 'enum' && ($col['label'] ?? '') === 'LBL_BD_PROJECT_ID'
        && ($col['labelModule'] ?? '') === 'Products', json_encode($col));
    $own = $grid;
    $own['panels'][0]['fields'][] = array('name' => BD_FIELD, 'readonly' => false, 'type' => 'enum');
    $o = bd_include("$GRID_DIR/bd_project_column.php", 'Products', 'quote-data-group-list', $own);
    $ownCol = array_values(array_filter($o['panels'][0]['fields'], static fn($e) => $e['name'] === BD_FIELD));
    check('G3 a grid that already draws it keeps it where it is, as it is (never made read-only)',
        count($ownCol) === 1 && end($o['panels'][0]['fields'])['name'] === BD_FIELD && $ownCol[0]['readonly'] === false,
        json_encode($ownCol));
    @unlink($compiled);

    // ── R. the line's record view ────────────────────────────────────────────
    $GLOBALS['dictionary'] = array();
    $GLOBALS['bd_no_vardef'] = false;
    $storedLine = bd_stored(BD_UAT . '/custom/modules/Products/clients/base/views/record/record.php', 'Products', 'record');
    $r = bd_include(BD_EXT . '/Products/Ext/clients/base/views/record/bd_project.php', 'Products', 'record', $storedLine, 2);
    $body = array();
    foreach ($r['panels'] as $p) {
        if (($p['name'] ?? '') === 'panel_body') {
            $body = $p['fields'];
        }
    }
    $entry = array_values(array_filter($body, static fn($e) => is_array($e) && ($e['name'] ?? '') === BD_FIELD));
    check('R1 the Bench UAT line record view draws Project once, in its body, editable',
        count($entry) === 1 && !isset($entry[0]['readonly']) && count(array_keys(bd_cells($r), BD_FIELD)) === 1,
        json_encode($entry));
    check('R2 everything else on it is where it was', array_values(array_diff(bd_cells($r), array(BD_FIELD)))
        === bd_cells($storedLine));
    $GLOBALS['dictionary'] = array();
    $GLOBALS['bd_no_vardef'] = true;
    $rNone = bd_include(BD_EXT . '/Products/Ext/clients/base/views/record/bd_project.php', 'Products', 'record', $storedLine);
    check('R3 a tenant whose vardefs lack the field: the view exactly as read', $rNone === $storedLine);
    $GLOBALS['bd_no_vardef'] = false;

    // ── Q. the quote's views ─────────────────────────────────────────────────
    $quoteOverlay = BD_EXT . '/Quotes/Ext/clients/base/views/%s/bd_line_project.php';
    foreach (array('record' => 'record', 'recorddashlet' => 'recorddashlet') as $view => $tag) {
        $stored = bd_stored(BD_UAT . "/custom/modules/Quotes/clients/base/views/$view/$view.php", 'Quotes', $view);
        // The shape a tenant without this release's UAT layout serves: the quote-level Project placed after Lead Type.
        $rc116 = $stored;
        foreach ($rc116['panels'] as $i => $p) {
            $at = array_search('bd_lead_type', array_map(static fn($e) => is_array($e) ? ($e['name'] ?? '') : $e,
                $p['fields']), true);
            if ($at !== false) {
                array_splice($rc116['panels'][$i]['fields'], $at + 1, 0, array(array('name' => BD_FIELD,
                    'label' => 'LBL_BD_PROJECT_ID', 'type' => 'erp-dependent-enum')));
            }
        }
        $file = sprintf($quoteOverlay, $view);
        $out = is_file($file) ? bd_include($file, 'Quotes', $view, $rc116, 3) : $rc116;
        check("Q1 $tag: the quote-level Project is on no panel of the view", !in_array(BD_FIELD, bd_cells($out), true),
            json_encode(bd_cells($out)));
        check("Q2 $tag: every other cell stays, in order", bd_cells($out) === bd_cells($stored));
        $lists = bd_fetch_lists($out);
        check("Q3 $tag: the quote fetches each line's Project with its lines (once, after a rebuild)",
            $lists !== array() && count(array_keys($lists[0], BD_FIELD, true)) === 1
            && array_values(array_diff($lists[0], array(BD_FIELD))) === bd_fetch_lists($stored)[0],
            json_encode(array_slice($lists[0] ?? array(), -3)));
        check("Q4 $tag: the field lists stay lists", (static function () use ($out) {
            foreach ($out['panels'] as $p) {
                if (array_keys($p['fields']) !== range(0, count($p['fields']) - 1)) {
                    return false;
                }
            }
            return true;
        })());
        // ── U. the UAT package's own stored view ─────────────────────────────
        check("U1 $tag: the UAT package's stored view no longer draws the quote-level Project",
            !in_array(BD_FIELD, bd_cells($stored), true));
        check("U2 $tag: and its panels' keys run 0..n (a gap reaches Sidecar as a JSON object)", (static function () use ($stored) {
            foreach ($stored['panels'] as $p) {
                if (array_keys($p['fields']) !== range(0, count($p['fields']) - 1)) {
                    return false;
                }
            }
            return true;
        })());
    }

    // ── H. once per tenant, from Module Loader's history ─────────────────────
    $first = $class . 'BdInstallHistory';
    $cases = array(
        'an upgrade from rc116' => array(array('0.9.46-rc116'), true),
        'a fresh install (nothing installed)' => array(array(), true),
        'an upgrade from an rc99 tenant' => array(array('0.9.44-rc99'), true),
        'rc117 installed (a later install)' => array(array('0.9.46-rc117'), false),
        'rc118 installed' => array(array('0.9.46-rc118'), false),
        'a later x.y.z' => array(array('0.9.47-rc120'), false),
        'a v-prefixed rc117' => array(array('v0.9.46-rc117'), false),
        'a blank version row' => array(array(''), true),
    );
    $got = array();
    $want = array();
    foreach ($cases as $label => [$installed, $expected]) {
        $want[$label] = $expected;
        $got[$label] = class_exists($first) ? $first::firstInstallOf('0.9.46-rc117', $installed) : null;
    }
    check('H1 the copy is due exactly when no installed version is rc117 or later', $got === $want, json_encode($got));
    BdRows::$tables['UpgradeHistory'] = array(
        array('id_name' => 'sugarai_benchdogs_ext', 'status' => 'installed', 'version' => '0.9.46-rc116', 'deleted' => 0),
        array('id_name' => 'sugarai_benchdogs_ext', 'status' => 'staged', 'version' => '0.9.46-rc117', 'deleted' => 0),
        array('id_name' => 'sugarai_benchdogs_ext', 'status' => 'installed', 'version' => '0.9.46-rc115', 'deleted' => 1),
        array('id_name' => 'sugarai_erp_epicor', 'status' => 'installed', 'version' => '1.2.121', 'deleted' => 0),
    );
    BdRows::$queries = array();
    $versions = class_exists($first) ? $first::installedVersions() : array();
    $q = BdRows::$queries[0] ?? null;
    check('H2 during rc117\'s install (its own row staged, the replaced rc116 installed, older rows deleted) the '
        . 'history reads rc116 alone, so the copy is due',
        $versions === array('0.9.46-rc116') && class_exists($first) && $first::firstInstallOf('0.9.46-rc117', $versions),
        json_encode($versions));
    check('H3 one read, of this package\'s installed rows, across teams',
        $q !== null && $q->module === 'UpgradeHistory' && ($q->options['team_security'] ?? null) === false
        && $q->where->tests === array(array('equals', 'id_name', 'sugarai_benchdogs_ext'),
            array('equals', 'status', 'installed')), json_encode($q ? $q->where->tests : null));
    BdRows::$tables['UpgradeHistory'][1]['status'] = 'installed';
    $later = class_exists($first) ? $first::installedVersions() : array();
    check('H4 at the next install rc117 is installed: not due, so a Project a seller cleared since stays clear',
        class_exists($first) && !$first::firstInstallOf('0.9.46-rc117', $later), json_encode($later));
    $pack = (string) file_get_contents(BD_PKG . '/pack.php');
    check('H5 the package id is pack.php\'s own (the one copy, held equal)',
        preg_match('/\$packageID\s*=\s*\'([^\']+)\'/', $pack, $m) === 1 && defined($first . '::PACKAGE_ID')
        && $m[1] === constant($first . '::PACKAGE_ID'), $m[1] ?? '');
    // The one-time steps belong to the release that introduced them, rc117, for good: a later version never moves the
    // constant, or its upgrade over rc117 would copy the Projects again and reset the quote-type setting.
    $bdVersion = trim((string) file_get_contents(BD_PKG . '/version'));
    check('H6 the release named for the one-time steps stays rc117, and this version is that one or later',
        defined($first . '::LINE_PROJECT_RELEASE')
        && constant($first . '::LINE_PROJECT_RELEASE') === '0.9.46-rc117'
        && version_compare($bdVersion, constant($first . '::LINE_PROJECT_RELEASE'), '>='),
        $bdVersion);

    // ── C. the one-time copy ─────────────────────────────────────────────────
    $copy = $class . 'BdLineProject';
    $world = static function (): void {
        BdRows::$tables['Quotes'] = array(
            array('id' => 'q-a', 'bd_project_id' => '17879', 'deleted' => 0),
            array('id' => 'q-b', 'bd_project_id' => '20065', 'deleted' => 0),
            array('id' => 'q-none', 'bd_project_id' => '', 'deleted' => 0),
            array('id' => 'q-null', 'bd_project_id' => null, 'deleted' => 0),
            array('id' => 'q-gone', 'bd_project_id' => '17879', 'deleted' => 1),
        );
        BdRows::$tables['Products'] = array(
            array('id' => 'l-a1', 'quote_id' => 'q-a', 'bd_project_id' => null, 'date_modified' => 'D1', 'deleted' => 0),
            array('id' => 'l-a2', 'quote_id' => 'q-a', 'bd_project_id' => '', 'date_modified' => 'D2', 'deleted' => 0),
            array('id' => 'l-a3', 'quote_id' => 'q-a', 'bd_project_id' => '20065', 'date_modified' => 'D3', 'deleted' => 0),
            array('id' => 'l-a4', 'quote_id' => 'q-a', 'bd_project_id' => null, 'date_modified' => 'D4', 'deleted' => 1),
            array('id' => 'l-b1', 'quote_id' => 'q-b', 'bd_project_id' => '  ', 'date_modified' => 'D5', 'deleted' => 0),
            array('id' => 'l-n1', 'quote_id' => 'q-none', 'bd_project_id' => null, 'date_modified' => 'D6', 'deleted' => 0),
            array('id' => 'l-g1', 'quote_id' => 'q-gone', 'bd_project_id' => null, 'date_modified' => 'D7', 'deleted' => 0),
        );
        BdRows::$failRead = '';
        BdRows::$queries = array();
        $GLOBALS['db'] = new BdDb();
        $GLOBALS['dictionary'] = array();
        $GLOBALS['bd_no_vardef'] = false;
    };
    $project = static function (string $id): ?string {
        foreach (BdRows::$tables['Products'] as $row) {
            if ($row['id'] === $id) {
                return $row['bd_project_id'] === null ? null : (string) $row['bd_project_id'];
            }
        }
        return 'missing';
    };
    $run = static function () use ($copy) {
        try {
            return class_exists($copy) ? $copy::copyFromQuotes() : 'no BdLineProject';
        } catch (Throwable $e) {
            return 'threw: ' . $e->getMessage();
        }
    };

    $world();
    $report = $run();
    check('C1 each blank line (none, empty, spaces) takes its own quote\'s Project',
        array($project('l-a1'), $project('l-a2'), $project('l-b1')) === array('17879', '17879', '20065'),
        json_encode(BdRows::$tables['Products']));
    check('C2 a line with a Project of its own keeps it (20065 on a 17879 quote)', $project('l-a3') === '20065');
    check('C3 the lines of a quote with no Project, and a deleted quote\'s and a deleted line, are not touched',
        array($project('l-n1'), $project('l-g1'), $project('l-a4')) === array(null, null, null));
    check('C4 the counts: 2 quotes with a Project, their 4 live lines, 3 filled, 1 kept, 0 failed',
        is_array($report) && array_intersect_key($report, array_flip(array('quotes', 'lines', 'filled', 'kept', 'failed')))
            === array('quotes' => 2, 'lines' => 4, 'filled' => 3, 'kept' => 1, 'failed' => 0), json_encode($report));
    $writes = $GLOBALS['db']->writes;
    check('C5 each fill writes only the Project, by line id: no hooks, no date_modified (nothing is sent to Kinetic)',
        count($writes) === 3 && $writes[0] === array('products', array(BD_FIELD), array('id'), 'l-a1')
        && array_column(BdRows::$tables['Products'], 'date_modified') === array('D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7'),
        json_encode($writes));
    check('C6 the column is added first (Module Loader adds it only after the install script), from the vardef',
        $GLOBALS['db']->added === array(array('products', BD_FIELD, 50)), json_encode($GLOBALS['db']->added));
    check('C7 the vardefs are read fresh, after the install merged this package\'s',
        in_array(array('Products', 'Product', true), VardefManager::$loads, true));
    $quoteRead = BdRows::$queries[0] ?? null;
    $lineRead = BdRows::$queries[1] ?? null;
    check('C8 the quotes read are only those holding a Project, across teams, a page at a time in id order; their '
        . 'lines in one read by quote id',
        $quoteRead && $quoteRead->module === 'Quotes' && $quoteRead->where->tests === array(array('notEmpty', BD_FIELD, null))
        && ($quoteRead->options['team_security'] ?? null) === false && $quoteRead->order === array('id', 'ASC')
        && $quoteRead->limit === 200 && $lineRead && $lineRead->module === 'Products'
        && $lineRead->where->tests === array(array('in', 'quote_id', array('q-a', 'q-b'))),
        json_encode(array($quoteRead ? $quoteRead->where->tests : null, $lineRead ? $lineRead->where->tests : null)));
    check('C9 the summary names every count', class_exists($copy) && is_array($report)
        && $copy::summary($report) === '2 quotes with a Project, 4 of their lines: 3 filled, 1 kept (a Project of their '
        . 'own), 0 failed');

    $before = BdRows::$tables['Products'];
    $writesBefore = count($GLOBALS['db']->writes);
    $second = $run();
    check('C10 a second run changes nothing: every line kept, no write, the column not added again',
        is_array($second) && $second['filled'] === 0 && $second['kept'] === 4 && BdRows::$tables['Products'] === $before
        && count($GLOBALS['db']->writes) === $writesBefore && count($GLOBALS['db']->added) === 1, json_encode($second));

    $world();
    $GLOBALS['db']->columns[BD_FIELD] = 1;
    $run();
    check('C11 a tenant that already has the column: none added', $GLOBALS['db']->added === array());

    $world();
    $GLOBALS['db']->failIds = array('l-a2');
    $failed = $run();
    check('C12 a failed line write is counted and named; the other lines are still filled',
        is_array($failed) && $failed['failed'] === 1 && $failed['filled'] === 2 && $project('l-b1') === '20065'
        && strpos(implode(' ', $failed['named']), 'l-a2 (deadlock)') !== false, json_encode($failed));

    $world();
    BdRows::$failRead = 'Quotes';
    $refused = $run();
    check('C13 a quote read that fails: the copy refuses (throws) and writes nothing',
        is_string($refused) && strpos($refused, 'threw:') === 0 && $GLOBALS['db']->writes === array(), (string) json_encode($refused));
    $world();
    BdRows::$failRead = 'Products';
    $refusedLines = $run();
    check('C14 a line read that fails: the same', is_string($refusedLines) && strpos($refusedLines, 'threw:') === 0
        && $GLOBALS['db']->writes === array());
    $world();
    $GLOBALS['bd_no_vardef'] = true;
    $noField = $run();
    check('C15 no merged line field: the copy refuses before it adds a column or writes',
        is_string($noField) && strpos($noField, 'threw:') === 0 && $GLOBALS['db']->added === array()
        && $GLOBALS['db']->writes === array(), (string) json_encode($noField));

    // Paging: more quotes than a page, each with one blank line.
    $world();
    BdRows::$tables['Quotes'] = array();
    BdRows::$tables['Products'] = array();
    for ($i = 0; $i < 450; $i++) {
        $id = sprintf('q-%04d', $i);
        BdRows::$tables['Quotes'][] = array('id' => $id, 'bd_project_id' => '17879', 'deleted' => 0);
        BdRows::$tables['Products'][] = array('id' => "l-$i", 'quote_id' => $id, 'bd_project_id' => '',
            'date_modified' => 'D', 'deleted' => 0);
    }
    $paged = $run();
    $pages = count(array_filter(BdRows::$queries, static fn($q) => $q->module === 'Quotes'));
    check('C16 450 quotes: three pages of 200, every line filled once',
        is_array($paged) && $paged['quotes'] === 450 && $paged['filled'] === 450 && $pages === 3
        && count(array_filter(BdRows::$tables['Products'], static fn($r) => $r['bd_project_id'] === '17879')) === 450,
        json_encode(array($paged, $pages)));

    // ── F. a Sugar that never had the quote-level Project ───────────────────
    // Section C runs on a quotes table that has the column. On a Sugar that never had this package, the install script
    // runs before Module Loader's own repair adds the quotes column (and the lines' one), so no quote can hold a
    // Project. That Sugar may already have quotes and lines from the integration.
    $fresh = static function () use ($world): void {
        $world();
        unset($GLOBALS['db']->quoteColumns[BD_FIELD]);
        BdRows::$tables['Quotes'] = array(
            array('id' => 'q-1', 'deleted' => 0),
            array('id' => 'q-2', 'deleted' => 0),
        );
        BdRows::$tables['Products'] = array(
            array('id' => 'l-1', 'quote_id' => 'q-1', 'date_modified' => 'D1', 'deleted' => 0),
            array('id' => 'l-2', 'quote_id' => 'q-2', 'date_modified' => 'D2', 'deleted' => 0),
        );
    };
    $fresh();
    $freshBefore = BdRows::$tables['Products'];
    $freshReport = $run();
    check('F1 no quotes column yet: the copy reports instead of throwing, with nothing failed, so the step reads ok',
        is_array($freshReport) && $freshReport['failed'] === 0, (string) json_encode($freshReport));
    check('F2 its counts are all zero',
        is_array($freshReport) && array_intersect_key($freshReport, array_flip(array('quotes', 'lines', 'filled', 'kept', 'failed')))
            === array('quotes' => 0, 'lines' => 0, 'filled' => 0, 'kept' => 0, 'failed' => 0), (string) json_encode($freshReport));
    $freshSummary = is_array($freshReport) && class_exists($copy) ? $copy::summary($freshReport) : '';
    check('F3 the summary says there was nothing to copy, and why',
        $freshSummary === 'nothing to copy: the quotes table has no bd_project_id column yet, so no quote holds a Project',
        $freshSummary);
    check('F4 nothing is written and no column added: Module Loader\'s own repair adds both columns after the script',
        $GLOBALS['db']->writes === array() && $GLOBALS['db']->added === array() && BdRows::$tables['Products'] === $freshBefore,
        json_encode(array($GLOBALS['db']->writes, $GLOBALS['db']->added)));
    check('F5 no quote or line is read: the quote read would name the missing column',
        array_values(array_filter(BdRows::$queries, static fn($q) => in_array($q->module, array('Quotes', 'Products'), true)))
            === array(), json_encode(array_map(static fn($q) => $q->module, BdRows::$queries)));
    check('F6 the answer comes from the quotes table\'s own column list',
        in_array('quotes', $GLOBALS['db']->columnReads, true), json_encode($GLOBALS['db']->columnReads));

    // Only a column list that names the quotes' id and not their Project means "nothing to copy". A list that comes
    // back empty or is not an array leaves it to the quote read, as before: that read copies, or fails and is reported.
    $world();
    $GLOBALS['db']->quoteColumnsRead = array();
    $emptyList = $run();
    check('F7 an empty column list on a quotes table that has the column: the copy still runs (3 filled)',
        is_array($emptyList) && $emptyList['filled'] === 3 && $project('l-a1') === '17879', (string) json_encode($emptyList));
    $world();
    $GLOBALS['db']->quoteColumnsRead = false;
    $noList = $run();
    check('F8 a column list that is not an array: the same',
        is_array($noList) && $noList['filled'] === 3 && $project('l-a1') === '17879', (string) json_encode($noList));
    $fresh();
    $GLOBALS['db']->quoteColumnsRead = array();
    $emptyFresh = $run();
    check('F9 an empty column list on a quotes table without the column: the quote read fails, the copy throws and '
        . 'writes nothing, never "nothing to copy"',
        is_string($emptyFresh)
        && strpos($emptyFresh, "threw: An exception occurred while executing a query: Unknown column 'quotes.bd_project_id'") === 0
        && $GLOBALS['db']->writes === array(), (string) json_encode($emptyFresh));
    $world();
    BdRows::$tables['Quotes'] = array(array('id' => 'q-none', 'bd_project_id' => '', 'deleted' => 0));
    $noneHeld = $run();
    $noneSummary = is_array($noneHeld) && class_exists($copy) ? $copy::summary($noneHeld) : '';
    check('F10 the column there and no quote holding a Project: the usual summary, not "nothing to copy"',
        $noneSummary === '0 quotes with a Project, 0 of their lines: 0 filled, 0 kept (a Project of their own), 0 failed',
        $noneSummary);

    check('Z no notice or warning', $GLOBALS['bd_notices'] === array(), json_encode($GLOBALS['bd_notices']));

    $failedCount = count(array_filter($checks, static function ($c) { return !$c[1]; }));
    printf("\n%d checks, %d failed\n", count($checks), $failedCount);
    exit($failedCount ? 1 : 0);
}
