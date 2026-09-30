<?php

/**
 * G848 (0.9.42-rc84, owner 🔒2151b): with the Bench MLP installed a seller sees
 * NO discount on a quote - not the DISCOUNT panel, not the totals' "Order Level
 * Discount", not the grid's "Line Discount" column or its edit-row input, not
 * the line's own record page - and nothing stored changes.
 *
 * Run:  php scripts/tests/bd_seller_discounts_hidden_test.php
 *       (BD_QUOTES_LAYOUT / BD_BASE_ERP_LAYOUT name ERP-Epicor's QuotesLayout and
 *       ERP-Core's BaseErpLayout; unset, the pins under fixtures/shared-sugar/ are
 *       used - ERP-Epicor 1.2.0, erp-integration-sugar 280e0929)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT IS REAL: the five shipped sidecar overlays
 *   custom/Extension/modules/Quotes/Ext/clients/base/views/{record,
 *     quote-data-grand-totals-header,quote-data-grand-totals-footer}/
 *   custom/Extension/modules/Products/Ext/clients/base/views/{quote-data-group-list,record}/
 *   each _override_zz_bd_hide_seller_discounts.php,
 * INCLUDED the way SugarEnt 26.1.0 includes them (MetaDataFiles::
 * getClientFileContents, modules/ModuleBuilder/parsers/MetaDataFiles.php
 * 1196-1247: the viewdef `require`d into a METHOD-local $viewdefs, then the
 * compiled <view>.ext.php `include`d into that scope, and the .ext.php reached a
 * second time as its own list entry - so a fragment runs more than once per
 * build); COMPILED the way ModuleInstaller::mergeExtensionFiles() (26.1.0
 * ModuleInstaller.php 2292-2328) compiles a sidecar view directory: every PHP
 * tag str_replace'd out, plain fragments in glob order, `_override*` ones last.
 * And ERP-Epicor's OWN definitions of what it puts on those views, read off its
 * QuotesLayout by reflection: the Discount panel (erpDiscountPanel), the header
 * strip's cells (erpTotalsHeaderFields), the footer rows (erpTotalsFooterFields),
 * the ERP panel (erpPanel) and the panel's SetVisibility rules
 * (erpDiscountTypeVisibilityDependencies).
 *
 * WHAT IS TRANSCRIBED: the stock parts of each view, reduced to their shape
 * (names, fieldsets, the nested fetch list), and the Bench grid's served column
 * order (G175's closing reading, ERP-Epicor 1.1.90 on Bench: line_num quantity
 * product_template_name mft_part_num discount_price erp_unit_of_measure
 * erp_break_select erp_stock_availability subtotal discount_field total_amount).
 *
 * IF IT WERE BROKEN the reading would be: the "Discount" panel or erp_discount_panel
 * still on the Quotes record view (A1/A2 - the owner's screenshot); deal_tot
 * ("Order Level Discount") still in the header strip (B1); discount_field ("Line
 * Discount") still a grid column (D1); a panel or field list serialised as a JSON
 * OBJECT after a removal (A4/B3/D3 - Sidecar reads panel.fields as an array);
 * discount_amount gone from the quote's nested FETCH list (A5 - the line totals
 * would lose their input); discount_price (Unit Price) gone (D2).
 */

namespace Sugarcrm\Sugarcrm\MetaData {
    /** BaseErpLayout's constructor only asks that this class exists. */
    class ViewdefManager
    {
    }
}

namespace {
    error_reporting(E_ALL);
    $GLOBALS['bd_notices'] = [];
    set_error_handler(static function ($no, $str, $file, $line) {
        $GLOBALS['bd_notices'][] = "$str @ " . basename((string) $file) . ":$line";
        return true;
    });

    const BD_FILE = '_override_zz_bd_hide_seller_discounts.php';
    const BD_EXT = __DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/Extension/modules';
    const BD_SURFACES = [
        'Quotes/record' => ['Quotes', 'record'],
        'Quotes/quote-data-grand-totals-header' => ['Quotes', 'quote-data-grand-totals-header'],
        'Quotes/quote-data-grand-totals-footer' => ['Quotes', 'quote-data-grand-totals-footer'],
        'Products/quote-data-group-list' => ['Products', 'quote-data-group-list'],
        'Products/record' => ['Products', 'record'],
    ];

    function bd_fragment(string $surface): string
    {
        [$module, $view] = BD_SURFACES[$surface];
        return BD_EXT . "/$module/Ext/clients/base/views/$view/" . BD_FILE;
    }

    $checks = [];
    function check(string $name, bool $ok, string $detail = ''): void
    {
        global $checks;
        $checks[] = [$name, $ok, $detail];
        printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok || $detail === '' ? '' : "  -- $detail");
    }

    // ── ERP-Epicor's own definitions, by reflection ───────────────────────────
    $bdLayout = getenv('BD_QUOTES_LAYOUT') ?: __DIR__ . '/fixtures/shared-sugar/sugar-sell/ERP-Epicor/scripts/Modules/QuotesLayout.php';
    $bdBase = getenv('BD_BASE_ERP_LAYOUT') ?: __DIR__ . '/fixtures/shared-sugar/sugar-sell/ERP-Core/scripts/BaseErpLayout.php';
    // QuotesLayout require_once's 'custom/include/scripts/BaseErpLayout.php', the
    // path the two packages install it at; give that path to the include_path.
    $bdRoot = sys_get_temp_dir() . '/g848-' . getmypid();
    @mkdir("$bdRoot/custom/include/scripts", 0777, true);
    copy($bdBase, "$bdRoot/custom/include/scripts/BaseErpLayout.php");
    set_include_path($bdRoot . PATH_SEPARATOR . get_include_path());
    require_once $bdLayout;
    register_shutdown_function(static function () use ($bdRoot) {
        @unlink("$bdRoot/custom/include/scripts/BaseErpLayout.php");
        @rmdir("$bdRoot/custom/include/scripts");
        @rmdir("$bdRoot/custom/include");
        @rmdir("$bdRoot/custom");
        @rmdir($bdRoot);
    });
    $GLOBALS['bd_ql'] = new QuotesLayout(true);
    function bd_erp(string $method): array
    {
        $m = new ReflectionMethod(QuotesLayout::class, $method);
        $m->setAccessible(true);
        return $m->invoke($GLOBALS['bd_ql']);
    }

    const BD_DISCOUNT_PANEL = 'LBL_RECORDVIEW_PANEL_ERP_DISCOUNT';
    $ERP_DISCOUNT_PANEL = bd_erp('erpDiscountPanel');
    check('P0 ERP-Epicor still names its Discount panel ' . BD_DISCOUNT_PANEL . ', one field erp_discount_panel (erp-discount)',
        QuotesLayout::DISCOUNT_PANEL_NAME === BD_DISCOUNT_PANEL && ($ERP_DISCOUNT_PANEL['name'] ?? '') === BD_DISCOUNT_PANEL
        && array_column($ERP_DISCOUNT_PANEL['fields'], 'name') === ['erp_discount_panel']
        && $ERP_DISCOUNT_PANEL['fields'][0]['type'] === 'erp-discount', json_encode($ERP_DISCOUNT_PANEL));
    $ERP_HEADER = bd_erp('erpTotalsHeaderFields');
    check('P0 ERP-Epicor\'s header strip cells are deal_tot ("Order Level Discount") and erp_tax_amount',
        array_column($ERP_HEADER, 'name') === ['deal_tot', 'erp_tax_amount']
        && $ERP_HEADER[0]['label'] === 'LBL_ERP_DOCUMENT_DISCOUNT_AMOUNT', json_encode($ERP_HEADER));
    $ERP_FOOTER = bd_erp('erpTotalsFooterFields');
    check('P0 ERP-Epicor\'s footer rows are erp_document_discount_amount ("Order Level Discount") and erp_tax_amount',
        array_column($ERP_FOOTER, 'name') === ['erp_document_discount_amount', 'erp_tax_amount'], json_encode($ERP_FOOTER));
    $ERP_PANEL = bd_erp('erpPanel');
    $ERP_DEPS = bd_erp('erpDiscountTypeVisibilityDependencies');

    foreach (BD_SURFACES as $surface => $unused) {
        check("P1 $surface: the overlay ships at the sidecar view Extension path", is_file(bd_fragment($surface)),
            bd_fragment($surface));
    }

    // ── The views, as a Bench tenant serves them ──────────────────────────────

    /** The quote's nested fetch list (stock record.php panel_header 'name'): what the grid's rows are READ with. */
    function bd_fetch_list(): array
    {
        return [[
            'name' => 'bundles',
            'fields' => ['id', 'currency_id', 'base_rate', 'name', 'deal_tot', 'deal_tot_usdollar',
                'deal_tot_discount_percentage', 'new_sub', 'shipping', 'subtotal', 'tax', 'total', 'position',
                ['name' => 'product_bundle_items', 'fields' => ['quantity', 'product_template_name',
                    'product_template_id', 'deal_calc', 'mft_part_num', 'discount_price', 'discount_amount',
                    'subtotal', 'position', 'currency_id', 'base_rate', 'discount_select', 'total_amount',
                    'erp_unit_of_measure', 'erp_line_ship_by'], 'max_num' => -1]],
            'max_num' => -1,
            'order_by' => 'position:asc',
        ]];
    }

    /** The Quotes record view on a Bench tenant: G606's Business Card page with ERP-Epicor's panels. */
    function bd_quotes_record(): array
    {
        global $ERP_DISCOUNT_PANEL, $ERP_PANEL, $ERP_DEPS;
        return [
            'buttons' => [['type' => 'button', 'name' => 'cancel_button'], ['type' => 'button', 'name' => 'save_button']],
            'panels' => [
                ['name' => 'panel_header', 'label' => 'LBL_PANEL_HEADER', 'header' => true, 'fields' => [
                    ['name' => 'picture', 'type' => 'avatar', 'size' => 'medium', 'dismiss_label' => true, 'readonly' => true],
                    ['name' => 'name', 'events' => ['keyup' => 'update:quote'], 'related_fields' => bd_fetch_list()],
                    ['name' => 'favorite', 'label' => 'LBL_FAVORITE', 'type' => 'favorite', 'dismiss_label' => true],
                    ['name' => 'follow', 'label' => 'LBL_FOLLOW', 'type' => 'follow', 'readonly' => true, 'dismiss_label' => true],
                ]],
                ['name' => 'panel_body', 'label' => 'LBL_RECORD_BODY', 'columns' => 2, 'placeholders' => true, 'fields' => [
                    'quote_num',
                    QuotesLayout::ERP_DISPLAY_SYNC_KEY_FIELD,
                    'purchase_order_num',
                    'date_quote_expected_closed',
                    ['name' => 'opportunity_name', 'related_fields' => ['subtotal', 'discount', 'new_sub', 'tax', 'shipping']],
                    'quote_stage',
                    ['name' => 'erp_is_primary_quote', 'label' => 'LBL_ERP_IS_PRIMARY_QUOTE'],
                ]],
                ['name' => 'panel_hidden', 'label' => 'LBL_SHOW_MORE', 'hide' => true, 'columns' => 2,
                    'newTab' => false, 'panelDefault' => 'collapsed', 'fields' => [
                    'description', 'assigned_user_name',
                    ['name' => 'date_entered_by', 'readonly' => true, 'type' => 'fieldset', 'label' => 'LBL_DATE_ENTERED',
                        'fields' => [['name' => 'date_entered'], ['type' => 'label', 'default_value' => 'LBL_BY'],
                            ['name' => 'created_by_name']]],
                ]],
                $ERP_DISCOUNT_PANEL,
                ['name' => 'panel_setting_body', 'label' => 'LBL_QUOTE_SETTINGS', 'columns' => 2, 'newTab' => true,
                    'panelDefault' => 'expanded', 'fields' => ['currency_id', 'show_line_nums']],
                $ERP_PANEL,
            ],
            'dependencies' => $ERP_DEPS,
        ];
    }

    /** The header strip ERP-Epicor leaves: [new_sub, deal_tot, erp_tax_amount, shipping, total] (QuotesLayout G321). */
    function bd_quotes_header(): array
    {
        global $ERP_HEADER;
        return [
            'buttons' => [['type' => 'quote-data-actiondropdown', 'name' => 'panel_dropdown', 'no_default_action' => true,
                'buttons' => [['type' => 'button', 'icon' => 'sicon-plus', 'name' => 'create_qli_button',
                    'label' => 'LBL_CREATE_QLI_BUTTON_LABEL', 'acl_action' => 'create']]]],
            'panels' => [[
                'name' => 'panel_quote_data_grand_totals_header',
                'label' => 'LBL_QUOTE_DATA_GRAND_TOTALS_HEADER',
                'fields' => array_merge(
                    [['name' => 'new_sub', 'css_class' => 'quote-totals-row-item']],
                    $ERP_HEADER,
                    [['name' => 'shipping', 'css_class' => 'quote-totals-row-item'],
                        ['name' => 'total', 'label' => 'LBL_LIST_GRAND_TOTAL', 'css_class' => 'quote-totals-row-item']]
                ),
            ]],
        ];
    }

    /** The footer ERP-Epicor leaves: stock's rows less tax, its two rows before shipping. */
    function bd_quotes_footer(): array
    {
        global $ERP_FOOTER;
        return [
            'panels' => [[
                'name' => 'panel_quote_data_grand_totals_footer',
                'label' => 'LBL_QUOTE_DATA_GRAND_TOTALS_FOOTER',
                'fields' => array_merge(
                    [['name' => 'new_sub', 'type' => 'currency']],
                    $ERP_FOOTER,
                    [['name' => 'shipping', 'type' => 'quote-footer-currency', 'css_class' => 'quote-footer-currency', 'default' => '0.00'],
                        ['name' => 'total', 'label' => 'LBL_LIST_GRAND_TOTAL', 'type' => 'currency', 'css_class' => 'grand-total',
                            'convertToBase' => false]]
                ),
            ]],
        ];
    }

    /** Stock's line-discount fieldset (Products quote-data-group-list / record), relabelled by ERP-Core on the grid. */
    function bd_discount_field(string $label): array
    {
        return [
            'name' => 'discount_field',
            'type' => 'fieldset',
            'css_class' => 'discount-field quote-discount-percent',
            'label' => $label,
            'labelModule' => 'Products',
            'show_child_labels' => false,
            'sortable' => false,
            'fields' => [
                ['name' => 'discount_amount', 'label' => 'LBL_DISCOUNT_AMOUNT', 'type' => 'discount-amount',
                    'discountFieldName' => 'discount_select', 'related_fields' => ['currency_id'],
                    'convertToBase' => true, 'base_rate_field' => 'base_rate', 'showTransactionalAmount' => true],
                ['type' => 'discount-select', 'name' => 'discount_select', 'options' => []],
            ],
        ];
    }

    /** The Bench quote grid, in G175's served order. */
    function bd_products_grid(): array
    {
        return [
            'panels' => [[
                'name' => 'products_quote_data_group_list',
                'label' => 'LBL_PRODUCTS_QUOTE_DATA_LIST',
                'fields' => [
                    ['name' => 'line_num', 'label' => null, 'widthClass' => 'cell-xsmall', 'css_class' => 'line_num text-center',
                        'type' => 'line-num', 'readonly' => true, 'link' => true],
                    ['name' => 'quantity', 'label' => 'LBL_QUANTITY', 'labelModule' => 'Products', 'type' => 'float'],
                    ['name' => 'product_template_name', 'label' => 'LBL_PRODUCT_TEMPLATE', 'labelModule' => 'Products',
                        'type' => 'quote-data-relate', 'required' => true, 'related_fields' => ['service', 'renewable']],
                    ['name' => 'mft_part_num', 'label' => 'LBL_MFT_PART_NUM', 'labelModule' => 'Products', 'type' => 'base'],
                    ['name' => 'discount_price', 'label' => 'LBL_DISCOUNT_PRICE', 'labelModule' => 'Products', 'type' => 'currency',
                        'convertToBase' => true, 'showTransactionalAmount' => true,
                        'related_fields' => ['discount_price', 'currency_id', 'base_rate']],
                    ['name' => 'erp_unit_of_measure'],
                    ['name' => 'erp_break_select'],
                    ['name' => 'erp_stock_availability', 'label' => 'LBL_ERP_STOCK_AVAILABILITY', 'labelModule' => 'Products',
                        'type' => 'erp-onhand-qty', 'erp_product_link' => 'product_template_id'],
                    ['name' => 'subtotal', 'label' => 'LBL_ERP_EXTENDED_PRICE', 'labelModule' => 'Products', 'type' => 'currency'],
                    bd_discount_field('LBL_ERP_LINE_DISCOUNT'),
                    ['name' => 'total_amount', 'label' => 'LBL_ERP_DISCOUNTED_TOTAL', 'labelModule' => 'Products', 'type' => 'currency',
                        'widthClass' => 'cell-medium', 'showTransactionalAmount' => true,
                        'related_fields' => ['total_amount', 'currency_id', 'base_rate']],
                ],
            ]],
        ];
    }

    /** The Quoted Line Item record page (line_num links here, G605): stock panel_body + ERP-Core's rows. */
    function bd_products_record(): array
    {
        return [
            'panels' => [
                ['name' => 'panel_header', 'header' => true, 'fields' => ['picture', 'name', 'favorite', 'follow']],
                ['name' => 'panel_body', 'label' => 'LBL_RECORD_BODY', 'columns' => 2, 'placeholders' => true, 'fields' => [
                    'product_template_name', 'account_name', 'quote_name', 'status', 'quantity',
                    ['name' => 'discount_price', 'related_fields' => ['discount_price', 'currency_id', 'base_rate']],
                    'cost_price', 'list_price', 'mft_part_num',
                    ['name' => 'erp_unit_of_measure'],
                    bd_discount_field('LBL_DISCOUNT_AMOUNT'),
                    ['name' => 'service_duration', 'type' => 'fieldset', 'label' => 'LBL_SERVICE_DURATION',
                        'fields' => ['service_duration_value', 'service_duration_unit']],
                    'service', 'tag',
                ]],
                ['name' => 'LBL_RECORDVIEW_PANEL_ERP_COST_WORKSHEET', 'label' => 'LBL_RECORDVIEW_PANEL_ERP_COST_WORKSHEET',
                    'columns' => 2, 'fields' => [['name' => 'erp_cost_material'], ['name' => 'erp_cost_labor']]],
                ['name' => 'panel_hidden', 'hide' => true, 'fields' => ['assigned_user_name', 'description']],
            ],
        ];
    }

    /**
     * Run a fragment the way MetaDataFiles::getClientFileContents() does: a METHOD
     * scope holding $viewdefs[<module>][<platform>][<type>][<view>] and the
     * method's own locals, then `include`. $times > 1 is the same compiled
     * .ext.php reached again. A fragment that is not there is not included - the
     * view is served as read (the red-first reading).
     *
     * @return array{0: ?array, 1: string[], 2: string[]}
     */
    function bd_include(string $surface, ?array $view, int $times = 1, ?string $file = null): array
    {
        [$module, $subPath] = BD_SURFACES[$surface];
        $type = 'view';
        $platform = 'base';
        $bean = null;
        $results = [$subPath => ['meta' => $view]];
        $fileInfo = ['path' => "custom/modules/$module/Ext/clients/base/views/$subPath/$subPath.ext.php",
            'platform' => 'base', 'subPath' => $subPath, 'template' => false];
        $ext = '.ext.php';
        $viewdefs = [];
        if ($view !== null) {
            $viewdefs[$module][$platform][$type][$subPath] = $view;
        }
        $file = $file ?? bd_fragment($surface);
        $locals = compact('module', 'type', 'platform', 'bean', 'results', 'fileInfo', 'ext', 'subPath', 'file');
        $before = array_keys(get_defined_vars());
        for ($i = 0; $i < $times; $i++) {
            if (is_file($file)) {
                include $file;
            }
        }
        $after = array_keys(get_defined_vars());
        $leaked = array_values(array_diff($after, $before, ['i', 'before']));
        $changed = [];
        foreach ($locals as $k => $v) {
            if ($$k !== $v) {
                $changed[] = $k;
            }
        }
        return [$viewdefs[$module]['base']['view'][$subPath] ?? null, $leaked, $changed];
    }

    function bd_names(array $fields): array
    {
        return array_map(static function ($e) {
            return is_string($e) ? $e : (string) ($e['name'] ?? '');
        }, $fields);
    }

    /** Every entry named $field anywhere on the view's panels, fieldset members included (never related_fields). */
    function bd_entries(array $view, string $field): array
    {
        $found = [];
        foreach ($view['panels'] ?? [] as $p) {
            foreach ($p['fields'] ?? [] as $e) {
                if ($e === $field || (is_array($e) && ($e['name'] ?? '') === $field)) {
                    $found[] = $e;
                }
                if (is_array($e) && isset($e['fields']) && is_array($e['fields'])) {
                    foreach ($e['fields'] as $m) {
                        if ($m === $field || (is_array($m) && ($m['name'] ?? '') === $field)) {
                            $found[] = $m;
                        }
                    }
                }
            }
        }
        return $found;
    }

    /** Lists stay lists: panels, and every panel's fields, have keys 0..n-1 (JSON arrays, not objects). */
    function bd_all_lists(array $view): bool
    {
        if (!array_is_list($view['panels'] ?? [])) {
            return false;
        }
        foreach ($view['panels'] as $p) {
            if (isset($p['fields']) && (!array_is_list($p['fields']))) {
                return false;
            }
            foreach ($p['fields'] ?? [] as $e) {
                if (is_array($e) && isset($e['fields']) && is_array($e['fields']) && !array_is_list($e['fields'])) {
                    return false;
                }
            }
        }
        return true;
    }

    function bd_panel(array $view, string $name): ?array
    {
        foreach ($view['panels'] ?? [] as $p) {
            if (($p['name'] ?? '') === $name) {
                return $p;
            }
        }
        return null;
    }

    const BD_QUOTE_NAMES = ['erp_discount_panel', 'deal_tot', 'deal_tot_usdollar', 'deal_tot_discount_percentage',
        'discount', 'erp_document_discount_amount', 'erp_document_discount_percent'];
    const BD_LINE_NAMES = ['discount_field', 'discount_amount', 'discount_select', 'discount_rate_percent',
        'discount_amount_usdollar', 'discount_amount_signed', 'deal_calc', 'deal_calc_usdollar'];

    // ── A. the Quotes record view: the DISCOUNT panel ─────────────────────────
    $GLOBALS['bd_notices'] = [];
    $in = bd_quotes_record();
    [$a, $leaked, $changed] = bd_include('Quotes/record', $in);
    $a = $a ?? [];
    check('A1 the Discount panel (' . BD_DISCOUNT_PANEL . ': "Apply a discount / Whole order / % / Apply") is gone',
        bd_panel($a, BD_DISCOUNT_PANEL) === null, json_encode(array_column($a['panels'] ?? [], 'name')));
    check('A2 erp_discount_panel is nowhere on the view', bd_entries($a, 'erp_discount_panel') === []);
    $expectedPanels = array_values(array_filter($in['panels'], static function ($p) {
        return $p['name'] !== BD_DISCOUNT_PANEL;
    }));
    check('A3 every other panel is byte-identical, in the same order', ($a['panels'] ?? null) === $expectedPanels,
        json_encode(array_column($a['panels'] ?? [], 'name')));
    check('A4 panels is still a LIST (keys 0..n-1): Sidecar reads it as an array', bd_all_lists($a)
        && strpos(json_encode($a['panels'] ?? []), '[') === 0);
    check('A5 the quote\'s nested FETCH list is untouched: discount_amount, discount_select, deal_tot still read',
        ($a['panels'][0]['fields'][1]['related_fields'] ?? null) === bd_fetch_list());
    check('A6 opportunity_name\'s related_fields (subtotal, discount, new_sub, ...) are untouched',
        (bd_entries($a, 'opportunity_name')[0]['related_fields'] ?? null) === ['subtotal', 'discount', 'new_sub', 'tax', 'shipping']);
    check('A7 the ERP panel keeps erp_discount_refusal (a read-only warning, not an input) and every row',
        bd_panel($a, 'LBL_RECORDVIEW_PANEL_ERP') === $GLOBALS['ERP_PANEL']
        && count(bd_entries($a, 'erp_discount_refusal')) === 1);
    check('A8 ERP-Epicor\'s SetVisibility rules are left as deployed (a missing target is a no-op there)',
        ($a['dependencies'] ?? null) === $in['dependencies']);
    check('A9 the buttons are untouched', ($a['buttons'] ?? null) === $in['buttons']);
    check('A10 nothing else on the view changed (the keys)', array_keys($a) === array_keys($in));
    check('A11 the include leaves no variable in Sugar\'s method scope', $leaked === [], json_encode($leaked));
    check('A12 and overwrites none of the method\'s locals', $changed === [], json_encode($changed));
    check('A13 no notice or warning', $GLOBALS['bd_notices'] === [], json_encode($GLOBALS['bd_notices']));
    [$a2] = bd_include('Quotes/record', bd_quotes_record(), 2);
    [$a3] = bd_include('Quotes/record', bd_quotes_record(), 3);
    check('A14 a second and a third inclusion change nothing more', $a2 === $a && $a3 === $a);

    // A panel an admin put more into, and one already empty.
    $admin = bd_quotes_record();
    $admin['panels'][3]['fields'][] = ['name' => 'erp_reference', 'label' => 'LBL_ERP_REFERENCE'];
    $admin['panels'][1]['fields'][] = ['name' => 'erp_discount_panel', 'type' => 'erp-discount'];
    $admin['panels'][1]['fields'][] = 'deal_tot';
    [$ad] = bd_include('Quotes/record', $admin);
    $ad = $ad ?? [];
    check('A15 a Discount panel that also holds an admin\'s field is KEPT, with only that field',
        (bd_panel($ad, BD_DISCOUNT_PANEL)['fields'] ?? null) === [['name' => 'erp_reference', 'label' => 'LBL_ERP_REFERENCE']],
        json_encode(bd_panel($ad, BD_DISCOUNT_PANEL)));
    check('A16 a discount control or quote discount an admin moved to another panel goes too',
        bd_entries($ad, 'erp_discount_panel') === [] && bd_entries($ad, 'deal_tot') === []
        && bd_names(bd_panel($ad, 'panel_body')['fields'] ?? []) === bd_names(bd_quotes_record()['panels'][1]['fields']));
    $empty = bd_quotes_record();
    $empty['panels'][3]['fields'] = [];
    [$em] = bd_include('Quotes/record', $empty);
    check('A17 a Discount panel already left empty is taken off (no bare "Discount" heading)',
        bd_panel($em ?? [], BD_DISCOUNT_PANEL) === null);

    // ── B. the header strip: "Order Level Discount" ───────────────────────────
    $GLOBALS['bd_notices'] = [];
    $hin = bd_quotes_header();
    [$b, $leaked] = bd_include('Quotes/quote-data-grand-totals-header', $hin);
    $b = $b ?? [];
    check('B1 deal_tot ("Order Level Discount") is gone from the header strip', bd_entries($b, 'deal_tot') === [],
        json_encode(bd_names($b['panels'][0]['fields'] ?? [])));
    $keep = array_values(array_filter($hin['panels'][0]['fields'], static function ($f) {
        return $f['name'] !== 'deal_tot';
    }));
    check('B2 Line Items Discounted Subtotal, Tax (via ERP), Shipping and Grand Total stay, byte-identical, in order',
        ($b['panels'][0]['fields'] ?? null) === $keep && bd_names($keep) === ['new_sub', 'erp_tax_amount', 'shipping', 'total']);
    check('B3 the field list is still a LIST', bd_all_lists($b));
    check('B4 the panel keeps its name and label; the buttons are untouched',
        array_diff_key($b['panels'][0] ?? [], ['fields' => 1]) === array_diff_key($hin['panels'][0], ['fields' => 1])
        && ($b['buttons'] ?? null) === $hin['buttons']);
    check('B5 no notice, nothing left behind', $GLOBALS['bd_notices'] === [] && $leaked === [],
        json_encode([$GLOBALS['bd_notices'], $leaked]));
    $stockHeader = $hin;
    $stockHeader['panels'][0]['fields'] = [
        ['name' => 'deal_tot', 'label' => 'LBL_LIST_DEAL_TOT', 'css_class' => 'quote-totals-row-item',
            'related_fields' => ['deal_tot_discount_percentage']],
        ['name' => 'new_sub', 'css_class' => 'quote-totals-row-item'],
        ['name' => 'shipping', 'css_class' => 'quote-totals-row-item'],
        ['name' => 'total', 'label' => 'LBL_LIST_GRAND_TOTAL', 'css_class' => 'quote-totals-row-item'],
    ];
    [$bs] = bd_include('Quotes/quote-data-grand-totals-header', $stockHeader);
    check('B6 matched by NAME, not label: stock\'s first-place "Discount" deal_tot cell goes as well',
        bd_names(($bs ?? [])['panels'][0]['fields'] ?? []) === ['new_sub', 'shipping', 'total']);
    $adminHeader = $hin;
    array_splice($adminHeader['panels'][0]['fields'], 1, 0, [['name' => 'deal_tot_discount_percentage'], ['name' => 'discount']]);
    [$ba] = bd_include('Quotes/quote-data-grand-totals-header', $adminHeader);
    check('B7 an admin\'s Quotes Configuration discount cells (deal_tot_discount_percentage, discount) go too',
        bd_names(($ba ?? [])['panels'][0]['fields'] ?? []) === ['new_sub', 'erp_tax_amount', 'shipping', 'total']);

    // ── C. the footer rows ────────────────────────────────────────────────────
    $GLOBALS['bd_notices'] = [];
    $fin = bd_quotes_footer();
    [$c] = bd_include('Quotes/quote-data-grand-totals-footer', $fin);
    $c = $c ?? [];
    check('C1 erp_document_discount_amount ("Order Level Discount") is gone from the footer',
        bd_entries($c, 'erp_document_discount_amount') === []);
    $keep = array_values(array_filter($fin['panels'][0]['fields'], static function ($f) {
        return $f['name'] !== 'erp_document_discount_amount';
    }));
    check('C2 new_sub, erp_tax_amount, shipping and total stay, byte-identical, in order',
        ($c['panels'][0]['fields'] ?? null) === $keep && bd_names($keep) === ['new_sub', 'erp_tax_amount', 'shipping', 'total']);
    check('C3 the field list is still a LIST; no notice', bd_all_lists($c) && $GLOBALS['bd_notices'] === []);

    // ── D. the quote grid: "Line Discount", the edit row and the column header ─
    $GLOBALS['bd_notices'] = [];
    $gin = bd_products_grid();
    [$d, $leaked, $changed] = bd_include('Products/quote-data-group-list', $gin);
    $d = $d ?? [];
    check('D1 discount_field ("Line Discount": discount_amount + the %/amount select) is not a grid column',
        bd_entries($d, 'discount_field') === [] && bd_entries($d, 'discount_amount') === []
        && bd_entries($d, 'discount_select') === [], json_encode(bd_names($d['panels'][0]['fields'] ?? [])));
    $keep = array_values(array_filter($gin['panels'][0]['fields'], static function ($f) {
        return $f['name'] !== 'discount_field';
    }));
    check('D2 Unit Price (discount_price), Extended Price, Discounted Total and every other column stay, byte-identical, in order',
        ($d['panels'][0]['fields'] ?? null) === $keep && in_array('discount_price', bd_names($keep), true),
        json_encode(bd_names($d['panels'][0]['fields'] ?? [])));
    check('D3 the column list is still a LIST', bd_all_lists($d));
    check('D4 nothing left behind, no local changed, no notice',
        $leaked === [] && $changed === [] && $GLOBALS['bd_notices'] === [], json_encode([$leaked, $changed, $GLOBALS['bd_notices']]));
    $adminGrid = $gin;
    $adminGrid['panels'][0]['fields'][] = ['name' => 'discount_amount', 'type' => 'discount-amount'];
    $adminGrid['panels'][0]['fields'][] = 'discount_select';
    $adminGrid['panels'][0]['fields'][] = ['name' => 'bd_admin_set', 'type' => 'fieldset',
        'fields' => ['discount_rate_percent', ['name' => 'list_price']]];
    $adminGrid['panels'][0]['fields'][] = ['name' => 'bd_admin_only_discounts', 'type' => 'fieldset',
        'fields' => [['name' => 'deal_calc'], 'discount_amount_usdollar']];
    $adminGrid['panels'][0]['fields'][] = ['name' => 'discount_usdollar'];
    [$da] = bd_include('Products/quote-data-group-list', $adminGrid);
    $da = $da ?? [];
    check('D5 loose discount_amount / discount_select columns an admin configured go too',
        bd_entries($da, 'discount_amount') === [] && bd_entries($da, 'discount_select') === []);
    $set = bd_entries($da, 'bd_admin_set');
    check('D6 an admin fieldset keeps its other members and loses only the discount one',
        count($set) === 1 && ($set[0]['fields'] ?? null) === [['name' => 'list_price']] && $set[0]['type'] === 'fieldset',
        json_encode($set));
    check('D7 a fieldset holding nothing but discount members goes with them',
        bd_entries($da, 'bd_admin_only_discounts') === []);
    check('D8 discount_usdollar is Unit Price (US Dollar), not a discount: it stays',
        count(bd_entries($da, 'discount_usdollar')) === 1);
    check('D9 still a LIST after removals in the middle and at the end', bd_all_lists($da));
    [$d2] = bd_include('Products/quote-data-group-list', bd_products_grid(), 3);
    check('D10 three inclusions = one', $d2 === $d);

    // ── E. the line's own record page (line_num links to it) ─────────────────
    $GLOBALS['bd_notices'] = [];
    $ein = bd_products_record();
    [$e] = bd_include('Products/record', $ein);
    $e = $e ?? [];
    check('E1 the line record page has no discount_field / discount_amount / discount_select',
        bd_entries($e, 'discount_field') === [] && bd_entries($e, 'discount_amount') === []
        && bd_entries($e, 'discount_select') === []);
    $expected = $ein;
    $expected['panels'][1]['fields'] = array_values(array_filter($ein['panels'][1]['fields'], static function ($f) {
        return !(is_array($f) && ($f['name'] ?? '') === 'discount_field');
    }));
    check('E2 Unit Price, the unit, the cost worksheet and every other row stay, byte-identical, in order',
        $e === $expected, json_encode(bd_names($e['panels'][1]['fields'] ?? [])));
    check('E3 still LISTS; no notice', bd_all_lists($e) && $GLOBALS['bd_notices'] === []);

    // ── F. a view with nothing to hide is served exactly as read ─────────────
    foreach (BD_SURFACES as $surface => $unused) {
        $clean = ['panels' => [3 => ['name' => 'p1', 'fields' => ['name', ['name' => 'discount_price'],
            ['name' => 'fs', 'type' => 'fieldset', 'fields' => ['quantity', 'total_amount']]]],
            8 => ['name' => 'p2', 'fields' => [5 => 'x', 9 => 'y']]]];
        [$f] = bd_include($surface, $clean);
        check("F1 $surface: no discount on the view -> returned EXACTLY as read (even a tenant's odd keys)", $f === $clean);
    }

    // ── G. nothing to work on, or something malformed ─────────────────────────
    foreach (BD_SURFACES as $surface => $unused) {
        $GLOBALS['bd_notices'] = [];
        [$g1, $leaked] = bd_include($surface, null);
        [$g2] = bd_include($surface, ['panels' => []]);
        [$g3] = bd_include($surface, ['panels' => 'broken']);
        [$g4] = bd_include($surface, ['panels' => [['name' => 'x', 'fields' => 'broken'], 'y', null]]);
        check("G1 $surface: no view -> none invented; no panels / malformed panels -> left alone, quietly",
            $g1 === null && $g2 === ['panels' => []] && $g3 === ['panels' => 'broken']
            && $g4 === ['panels' => [['name' => 'x', 'fields' => 'broken'], 'y', null]]
            && $leaked === [] && $GLOBALS['bd_notices'] === [], json_encode([$g1, $g4, $leaked, $GLOBALS['bd_notices']]));
    }

    // ── H. compiled the way Sugar compiles a sidecar view directory ───────────
    /**
     * ModuleInstaller::mergeExtensionFiles() (26.1.0, 2292-2328), transcribed:
     * each file's PHP tags str_replace'd out ANYWHERE in it, plain files in glob
     * order, then every `_override*` file, all behind one opening tag.
     */
    function bd_compile(array $files): string
    {
        $tags = ['<?php', '?>', '<?PHP', '<?'];
        $ext = "<?php\n// WARNING: The contents of this file are auto-generated.\n";
        ksort($files, SORT_STRING);
        $override = [];
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

    $hostile = [
        'Quotes/record' => '<?php $viewdefs[\'Quotes\'][\'base\'][\'view\'][\'record\'][\'panels\'][] = '
            . "array('name' => 'LBL_RECORDVIEW_PANEL_ERP_DISCOUNT', 'fields' => array(array('name' => 'erp_discount_panel')));",
        'Quotes/quote-data-grand-totals-header' => '<?php $viewdefs[\'Quotes\'][\'base\'][\'view\'][\'quote-data-grand-totals-header\'][\'panels\'][0][\'fields\'][] = '
            . "array('name' => 'deal_tot');",
        'Quotes/quote-data-grand-totals-footer' => '<?php $viewdefs[\'Quotes\'][\'base\'][\'view\'][\'quote-data-grand-totals-footer\'][\'panels\'][0][\'fields\'][] = '
            . "array('name' => 'erp_document_discount_amount');",
        'Products/quote-data-group-list' => '<?php $viewdefs[\'Products\'][\'base\'][\'view\'][\'quote-data-group-list\'][\'panels\'][0][\'fields\'][] = '
            . "array('name' => 'discount_field', 'type' => 'fieldset', 'fields' => array('discount_amount', 'discount_select'));",
        'Products/record' => '<?php $viewdefs[\'Products\'][\'base\'][\'view\'][\'record\'][\'panels\'][0][\'fields\'][] = '
            . "array('name' => 'discount_amount');",
    ];
    $bdTmpExt = "$bdRoot/compiled.ext.php";
    foreach (BD_SURFACES as $surface => $unused) {
        $body = is_file(bd_fragment($surface)) ? (string) file_get_contents(bd_fragment($surface)) : '';
        $open = substr_count($body, '<?') + substr_count($body, '?>');
        check("H1 $surface: the only PHP tag in the file is its opening one (Sugar strips every tag it finds)",
            $body !== '' && strpos($body, '<?php') === 0 && $open === 1, "tags: $open");
        // A package fragment in the same directory that PUTS a discount back,
        // whatever its name sorts as: this one is `_override`, so it runs last.
        foreach (['aaa_other.php', 'erp_other.php', 'zzz_other.php'] as $sibling) {
            file_put_contents($bdTmpExt, bd_compile([$sibling => $hostile[$surface], BD_FILE => $body]));
            $start = ['panels' => [['name' => 'p', 'fields' => [['name' => 'quantity']]]]];
            [$h] = bd_include($surface, $start, 2, $bdTmpExt);
            $all = 0;
            foreach (array_merge(BD_QUOTE_NAMES, BD_LINE_NAMES) as $n) {
                $all += count(bd_entries($h ?? [], $n));
            }
            check("H2 $surface + a sibling '$sibling' that adds a discount -> none survives the compiled file",
                $all === 0 && bd_panel($h ?? [], BD_DISCOUNT_PANEL) === null && count(bd_entries($h ?? [], 'quantity')) === 1,
                json_encode($h));
        }
    }
    @unlink($bdTmpExt);

    // ── I. the files themselves ───────────────────────────────────────────────
    $bodies = [];
    foreach (BD_SURFACES as $surface => [$module, $view]) {
        $src = is_file(bd_fragment($surface)) ? (string) file_get_contents(bd_fragment($surface)) : '';
        $tokens = token_get_all($src);
        $decl = [];
        $amp = false;
        $vars = true;
        foreach ($tokens as $t) {
            if (is_array($t) && in_array($t[0], [T_FUNCTION, T_CLASS, T_FN, T_INTERFACE, T_TRAIT, T_NAMESPACE,
                    T_RETURN, T_DECLARE, T_EXIT], true)) {
                $decl[] = token_name($t[0]);
            }
            if ($t === '&') {
                $amp = true;
            }
            if (is_array($t) && $t[0] === T_VARIABLE && !in_array($t[1], ['$viewdefs', '$GLOBALS'], true)
                && strpos($t[1], '$bdHide') !== 0) {
                $vars = false;
            }
        }
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
        check("I1 $surface: no function/class/namespace/return/exit - it is concatenated and included twice",
            $src !== '' && $decl === [], json_encode($decl));
        check("I2 $surface: no reference (&) - a by-reference loop leaks into the next fragment", $src !== '' && !$amp);
        check("I3 $surface: every variable is \$bdHide-prefixed", $src !== '' && $vars);
        check("I4 $surface: it catches Throwable - a metadata build never dies on a layout nicety",
            preg_match('/catch\s*\(\s*\\\\?Throwable\s+\$bdHide/', $src) === 1);
        check("I5 $surface: it WRITES nothing - no viewdef save, no file, no record (a stored value cannot move)",
            $src !== '' && preg_match('/saveViewdef|ViewdefManager|file_put_contents|write_array_to_file|sugar_file_put|'
                . 'BeanFactory|->save\(|\$db\b|DBManager|MetaDataManager|unlink|rmdir/i', $code) === 0);
        check("I6 $surface: it names only its own view", $src !== '' && strpos($code, "\$bdHideModule = '$module';") !== false
            && strpos($code, "\$bdHideView = '$view';") !== false);
        $at = strpos($src, '// ---- one body, the same in all five files ----');
        $bodies[$surface] = $at === false ? '' : substr($src, $at);
    }
    check('I7 the five files share ONE body, byte for byte (a fix reaches every surface)',
        count(array_unique($bodies)) === 1 && reset($bodies) !== '');

    $failed = count(array_filter($checks, static function ($c) { return !$c[1]; }));
    printf("\n%d checks, %d failed\n", count($checks), $failed);
    exit($failed ? 1 : 0);
}
