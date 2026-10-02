<?php

/**
 * G458 (0.9.42-rc82): the five Epicor contact fields - Function, Role, Primary
 * Billing, Primary Purchasing, Primary Shipping - are SHOWN, READ-ONLY, on the
 * Contacts record view, and NOTHING is referenced on a tenant that lacks them.
 *
 * Run:  php scripts/tests/bd_contact_fields_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * THE DEFECT (benchdogs-dev, 2026-09-30 11:20-11:22Z, Codex on screen): the
 * Bench connector extension (0.3.6, level L807) writes epicor_function_c /
 * role_c / primary_billing_c / primary_purchasing_c / primary_shipping_c onto
 * 1,510 contacts, and every one read "Not shown" on five contacts: the fields
 * come from the customer's own package (Bench_Dogs_Account_Contact_Fields
 * 1.0.0, custom_fields installdefs), and no Contacts panel names them.
 *
 * WHAT IS REAL: the shipped sidecar extension fragment
 * custom/Extension/modules/Contacts/Ext/clients/base/views/record/
 * bd_epicor_contact_fields.php, INCLUDED the way SugarEnt 26.1.0 includes it:
 * MetaDataFiles::getClientFileContents() (modules/ModuleBuilder/parsers/
 * MetaDataFiles.php:1240-1247) `require`s the record viewdef into a METHOD-local
 * $viewdefs and then `include`s the compiled record.ext.php into that same
 * scope - and reaches the .ext.php a second time as its own list entry, so the
 * fragment runs MORE THAN ONCE per build (section C).
 *
 * WHAT IS FAKED: VardefManager::loadVardef, which fills
 * $GLOBALS['dictionary']['Contact'] with the tenant's MERGED vardefs (stock +
 * Ext + fields_meta_data, the vardef cache Sugar keeps), and the viewdefs.
 *
 * THE VIEW is the Contacts record view SERVED by benchdogs-dev AND
 * benchdogs-sandbox (read-only GET /metadata?module_filter=Contacts through the
 * stage core, 2026-09-30): panel_header, panel_body (9 fields),
 * LBL_RECORDVIEW_PANEL_ERP (ERP-Core's ContactsLayout panel, label "ERP": the
 * write-back status/at/message and the ERP Contact ID, every entry readonly).
 * THE FIELDS are the five as served there: custom_fields, varchar / enum
 * (contact_role_list) / bool x3, vnames LBL_*_C.
 *
 * If the overlay were broken the reading would be: section A's ERP panel still
 * ends at erp_display_sync_key (the "Not shown" symptom), or section B's view
 * differs from the input on a tenant WITHOUT the customer's package (a field
 * the tenant does not have is referenced - the thing an install must never do).
 */

namespace {
    error_reporting(E_ALL);
    $GLOBALS['bd_notices'] = [];
    set_error_handler(static function ($no, $str, $file, $line) {
        $GLOBALS['bd_notices'][] = "$str @ " . basename((string) $file) . ":$line";
        return true;
    });

    /** Sugar's VardefManager, reduced to the one call the overlay makes. */
    class VardefManager
    {
        public static $calls = [];
        /** What the tenant's vardef cache holds, per object; null = unreadable. */
        public static $tenant = [];

        public static function loadVardef($module, $object, $refresh = false, $params = [])
        {
            self::$calls[] = [$module, $object];
            if (!empty($GLOBALS['bd_throw'])) {
                throw new RuntimeException('vardef cache unreadable');
            }
            if (empty($GLOBALS['dictionary'][$object]) && isset(self::$tenant[$object])) {
                $GLOBALS['dictionary'][$object] = self::$tenant[$object];
            }
        }
    }

    const BD_OVERLAY = __DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/Extension/modules/Contacts/Ext/clients/base/views/record/bd_epicor_contact_fields.php';
    const BD_FIVE = ['epicor_function_c', 'role_c', 'primary_billing_c', 'primary_purchasing_c', 'primary_shipping_c'];

    $checks = [];
    function check(string $name, bool $ok, string $detail = ''): void
    {
        global $checks;
        $checks[] = [$name, $ok, $detail];
        printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok || $detail === '' ? '' : "  -- $detail");
    }

    /** The Contacts record view served by benchdogs-dev / benchdogs-sandbox, 2026-09-30. */
    function bd_dev_view(): array
    {
        return [
            'buttons' => [['type' => 'button', 'name' => 'cancel_button']],
            'panels' => [
                [
                    'name' => 'panel_header',
                    'header' => true,
                    'fields' => ['picture', ['name' => 'name', 'type' => 'fullname',
                        'fields' => ['salutation', 'first_name', 'last_name']], 'favorite', 'follow'],
                ],
                [
                    'name' => 'panel_body',
                    'label' => 'LBL_RECORD_BODY',
                    'columns' => 2,
                    'placeholders' => true,
                    'fields' => ['title', 'phone_mobile', 'department', 'do_not_call', 'account_name',
                        'business_center_name', 'market_score', 'email', 'tag'],
                ],
                [
                    'name' => 'LBL_RECORDVIEW_PANEL_ERP',
                    'label' => 'LBL_RECORDVIEW_PANEL_ERP',
                    'columns' => 2,
                    'placeholders' => true,
                    'newTab' => false,
                    'panelDefault' => 'expanded',
                    'fields' => [
                        ['readonly' => true, 'name' => 'erp_writeback_status', 'label' => 'LBL_ERP_WRITEBACK_STATUS'],
                        ['readonly' => true, 'name' => 'erp_writeback_at', 'label' => 'LBL_ERP_WRITEBACK_AT',
                            'type' => 'erp-readonly-datetime'],
                        ['readonly' => true, 'name' => 'erp_writeback_msg', 'label' => 'LBL_ERP_WRITEBACK_MSG', 'span' => 12],
                        ['readonly' => true, 'name' => 'erp_display_sync_key', 'label' => 'LBL_ERP_DISPLAY_SYNC_KEY',
                            'type' => 'erp-contact-key', 'related_fields' => ['erp_sync_key']],
                    ],
                ],
            ],
        ];
    }

    /** The Contact vardefs: stock ids + the five as benchdogs-dev serves them. */
    function bd_contact_fields(array $only = BD_FIVE): array
    {
        $served = [
            'epicor_function_c' => ['type' => 'varchar', 'vname' => 'LBL_EPICOR_FUNCTION_C', 'len' => 100],
            'role_c' => ['type' => 'enum', 'vname' => 'LBL_ROLE_C', 'options' => 'contact_role_list'],
            'primary_billing_c' => ['type' => 'bool', 'vname' => 'LBL_PRIMARY_BILLING_C', 'readonly' => true],
            'primary_purchasing_c' => ['type' => 'bool', 'vname' => 'LBL_PRIMARY_PURCHASING_C', 'readonly' => true],
            'primary_shipping_c' => ['type' => 'bool', 'vname' => 'LBL_PRIMARY_SHIPPING_C', 'readonly' => true],
        ];
        $fields = [
            'id' => ['name' => 'id', 'type' => 'id'],
            'name' => ['name' => 'name', 'type' => 'fullname', 'source' => 'non-db'],
            'title' => ['name' => 'title', 'type' => 'varchar'],
        ];
        foreach ($only as $f) {
            $fields[$f] = ['name' => $f, 'source' => 'custom_fields', 'custom_module' => 'Contacts'] + $served[$f];
        }
        return $fields;
    }

    /**
     * Run the fragment the way MetaDataFiles::getClientFileContents() does: a
     * METHOD scope that already holds $viewdefs[<module>][<platform>][<type>]
     * [<subPath>] and the method's own locals, then `include`. $times > 1 is
     * the second inclusion of the same compiled .ext.php.
     *
     * @return array{0: ?array, 1: string[], 2: string[]} the record view after, the
     *   variables the include left behind, the method locals it changed
     */
    function bd_include(?array $view, int $times = 1): array
    {
        $module = 'Contacts';
        $type = 'view';
        $platform = 'base';
        $bean = null;
        $results = ['record' => ['meta' => $view]];
        $fileInfo = ['path' => 'custom/modules/Contacts/Ext/clients/base/views/record/record.ext.php',
            'platform' => 'base', 'subPath' => 'record', 'template' => false];
        $ext = '.ext.php';
        $viewdefs = [];
        if ($view !== null) {
            $viewdefs[$module][$platform][$type]['record'] = $view;
        }
        $locals = compact('module', 'type', 'platform', 'bean', 'results', 'fileInfo', 'ext');
        $before = array_keys(get_defined_vars());
        for ($i = 0; $i < $times; $i++) {
            include BD_OVERLAY;
        }
        $after = array_keys(get_defined_vars());
        $leaked = array_values(array_diff($after, $before, ['i', 'before']));
        $changed = [];
        foreach ($locals as $k => $v) {
            if ($$k !== $v) {
                $changed[] = $k;
            }
        }
        return [$viewdefs['Contacts']['base']['view']['record'] ?? null, $leaked, $changed];
    }

    /** Field names of one panel, top level only (fieldset members not expanded). */
    function bd_panel_names(array $view, string $panel): array
    {
        foreach ($view['panels'] as $p) {
            if (($p['name'] ?? '') === $panel) {
                return array_map(static function ($e) {
                    return is_string($e) ? $e : (string) ($e['name'] ?? '');
                }, $p['fields'] ?? []);
            }
        }
        return [];
    }

    /** Every entry (fieldset members included) named $field, anywhere on the view. */
    function bd_entries(array $view, string $field): array
    {
        $found = [];
        foreach ($view['panels'] as $p) {
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

    function bd_tenant(?array $fields): void
    {
        $GLOBALS['dictionary'] = [];
        VardefManager::$calls = [];
        VardefManager::$tenant = $fields === null ? [] : ['Contact' => ['fields' => $fields]];
        $GLOBALS['bd_notices'] = [];
    }

    $ERP = 'LBL_RECORDVIEW_PANEL_ERP';
    $ERP_BEFORE = ['erp_writeback_status', 'erp_writeback_at', 'erp_writeback_msg', 'erp_display_sync_key'];

    check('P0 the overlay ships at the sidecar record-view Extension path', is_file(BD_OVERLAY), BD_OVERLAY);
    if (!is_file(BD_OVERLAY)) {
        printf("\n%d checks, %d failed\n", count($checks), count($checks));
        exit(1);
    }

    // ── A. benchdogs-dev: the customer's package is installed ────────────────────
    bd_tenant(bd_contact_fields());
    [$a, $leaked, $changed] = bd_include(bd_dev_view());
    check('A1 the five follow the ERP Contact ID on the ERP panel, in Epicor order',
        bd_panel_names($a, $ERP) === array_merge($ERP_BEFORE, BD_FIVE), json_encode(bd_panel_names($a, $ERP)));
    $allReadonly = true;
    $labels = true;
    foreach (BD_FIVE as $f) {
        $e = bd_entries($a, $f);
        $allReadonly = $allReadonly && count($e) === 1 && is_array($e[0]) && ($e[0]['readonly'] ?? null) === true;
        $labels = $labels && is_array($e[0] ?? null) && ($e[0]['label'] ?? null) === bd_contact_fields()[$f]['vname'];
    }
    check('A2 each of the five is on the view ONCE and readonly (Epicor-owned, ERP -> Sugar only)', $allReadonly);
    check('A3 each carries its own vardef label (LBL_*_C, the customer package\'s)', $labels);
    $dev = bd_dev_view();
    check('A4 panel_header and panel_body are untouched', $a['panels'][0] === $dev['panels'][0] && $a['panels'][1] === $dev['panels'][1]);
    check('A5 ERP-Core\'s own four ERP-panel entries are untouched, first',
        array_slice($a['panels'][2]['fields'], 0, 4) === $dev['panels'][2]['fields']);
    check('A6 the panel itself keeps its name, label, columns and flags',
        array_diff_key($a['panels'][2], ['fields' => 1]) === array_diff_key($dev['panels'][2], ['fields' => 1]));
    check('A7 nothing else on the view changed (buttons, panel count)',
        $a['buttons'] === $dev['buttons'] && count($a['panels']) === 3);
    check('A8 the include leaves no variable behind in Sugar\'s method scope', $leaked === [], json_encode($leaked));
    check('A9 and overwrites none of the method\'s own locals', $changed === [], json_encode($changed));
    check('A10 no notice or warning', $GLOBALS['bd_notices'] === [], json_encode($GLOBALS['bd_notices']));
    check('A11 the guard reads the MERGED Contact vardefs through VardefManager::loadVardef',
        in_array(['Contacts', 'Contact'], VardefManager::$calls, true), json_encode(VardefManager::$calls));

    // ── B. a tenant WITHOUT the customer's package (Ophir, stock, et) ────────────
    bd_tenant(bd_contact_fields([]));
    [$b, $leaked] = bd_include(bd_dev_view());
    check('B1 the view is returned EXACTLY as read: no field the tenant lacks is referenced',
        $b === bd_dev_view());
    foreach (BD_FIVE as $f) {
        check("B2 $f is nowhere on the view", bd_entries($b, $f) === []);
    }
    check('B3 no notice, nothing left behind', $GLOBALS['bd_notices'] === [] && $leaked === [],
        json_encode([$GLOBALS['bd_notices'], $leaked]));

    // ── C. the compiled .ext.php runs twice in one build ─────────────────────────
    bd_tenant(bd_contact_fields());
    [$c] = bd_include(bd_dev_view(), 2);
    check('C1 a second inclusion changes nothing (no duplicate rows)', $c === $a);
    bd_tenant(bd_contact_fields());
    [$c3] = bd_include(bd_dev_view(), 3);
    check('C2 nor does a third', $c3 === $a);

    // ── D. an admin already placed some of them (Studio) ─────────────────────────
    bd_tenant(bd_contact_fields());
    $admin = bd_dev_view();
    $admin['panels'][1]['fields'][] = 'role_c';
    $admin['panels'][1]['fields'][] = ['name' => 'bd_billing_set', 'type' => 'fieldset', 'label' => 'LBL_X',
        'fields' => ['primary_billing_c', ['name' => 'primary_shipping_c', 'readonly' => false]]];
    [$d] = bd_include($admin);
    check('D1 the admin\'s placement is kept: role_c stays in panel_body, not moved',
        in_array('role_c', bd_panel_names($d, 'panel_body'), true) && !in_array('role_c', bd_panel_names($d, $ERP), true));
    check('D2 fieldset members count as placed: neither billing nor shipping is added to the ERP panel',
        bd_panel_names($d, $ERP) === array_merge($ERP_BEFORE, ['epicor_function_c', 'primary_purchasing_c']),
        json_encode(bd_panel_names($d, $ERP)));
    $each = true;
    foreach (BD_FIVE as $f) {
        $e = bd_entries($d, $f);
        $each = $each && count($e) === 1 && is_array($e[0]) && ($e[0]['readonly'] ?? null) === true;
    }
    check('D3 every one is on the view once, and READ-ONLY where the admin put it too', $each,
        json_encode(array_map(static function ($f) use ($d) { return bd_entries($d, $f); }, BD_FIVE)));
    check('D4 the admin\'s fieldset keeps its own keys', (static function () use ($d) {
        foreach ($d['panels'][1]['fields'] as $e) {
            if (is_array($e) && ($e['name'] ?? '') === 'bd_billing_set') {
                return $e['type'] === 'fieldset' && $e['label'] === 'LBL_X' && count($e['fields']) === 2;
            }
        }
        return false;
    })());

    // ── E. a field the tenant only half has ──────────────────────────────────────
    bd_tenant(['epicor_function_c' => ['erp_layout' => ['view' => 'record']]] + bd_contact_fields(['role_c', 'primary_billing_c']));
    [$e1] = bd_include(bd_dev_view());
    check('E1 a def with no type (a stray override, no real field) is NOT placed; the real ones are',
        bd_panel_names($e1, $ERP) === array_merge($ERP_BEFORE, ['role_c', 'primary_billing_c']),
        json_encode(bd_panel_names($e1, $ERP)));
    check('E2 no notice', $GLOBALS['bd_notices'] === [], json_encode($GLOBALS['bd_notices']));

    // ── F. where the ERP panel is not ────────────────────────────────────────────
    bd_tenant(bd_contact_fields());
    $noErp = bd_dev_view();
    unset($noErp['panels'][2]);
    $noErp['panels'] = array_values($noErp['panels']);
    [$f1] = bd_include($noErp);
    check('F1 no ERP panel (an admin removed it) -> the end of panel_body',
        bd_panel_names($f1, 'panel_body') === array_merge(bd_dev_view()['panels'][1]['fields'], BD_FIVE),
        json_encode(bd_panel_names($f1, 'panel_body')));
    bd_tenant(bd_contact_fields());
    $studio = ['panels' => [
        ['name' => 'panel_header', 'header' => true, 'fields' => ['picture', 'name']],
        ['name' => 'LBL_RECORDVIEW_PANEL1', 'label' => 'LBL_RECORDVIEW_PANEL1', 'columns' => 2, 'fields' => ['title', 'email']],
        ['name' => 'LBL_RECORDVIEW_PANEL2', 'label' => 'LBL_RECORDVIEW_PANEL2', 'columns' => 2, 'fields' => ['department']],
    ]];
    [$f2] = bd_include($studio);
    check('F2 no ERP panel and no panel_body (Studio-renamed) -> the first non-header panel with fields',
        bd_panel_names($f2, 'LBL_RECORDVIEW_PANEL1') === array_merge(['title', 'email'], BD_FIVE)
        && bd_panel_names($f2, 'LBL_RECORDVIEW_PANEL2') === ['department'],
        json_encode($f2['panels']));
    bd_tenant(bd_contact_fields());
    $headerOnly = ['panels' => [['name' => 'panel_header', 'header' => true, 'fields' => ['picture', 'name']]]];
    [$f3] = bd_include($headerOnly);
    check('F3 only a header panel -> nothing is placed anywhere (never into the header)', $f3 === $headerOnly);
    bd_tenant(bd_contact_fields());
    $erpEmpty = bd_dev_view();
    unset($erpEmpty['panels'][2]['fields']);
    [$f4] = bd_include($erpEmpty);
    check('F4 an ERP panel with no field list gets one with the five', bd_panel_names($f4, $ERP) === BD_FIVE,
        json_encode(bd_panel_names($f4, $ERP)));

    bd_tenant(bd_contact_fields());
    $bodyLater = ['panels' => [
        ['name' => 'panel_header', 'header' => true, 'fields' => ['picture', 'name']],
        ['name' => 'LBL_RECORDVIEW_PANEL1', 'label' => 'LBL_RECORDVIEW_PANEL1', 'columns' => 2, 'fields' => ['title']],
        ['name' => 'panel_body', 'label' => 'LBL_RECORD_BODY', 'columns' => 2, 'fields' => ['email']],
    ]];
    [$f5] = bd_include($bodyLater);
    check('F5 no ERP panel, a Studio panel BEFORE panel_body -> panel_body still wins',
        bd_panel_names($f5, 'panel_body') === array_merge(['email'], BD_FIVE)
        && bd_panel_names($f5, 'LBL_RECORDVIEW_PANEL1') === ['title'], json_encode($f5['panels']));

    // ── G. nothing to work on ────────────────────────────────────────────────────
    bd_tenant(bd_contact_fields());
    [$g1, $leaked] = bd_include(null);
    check('G1 no record view loaded -> no Contacts view is invented', $g1 === null);
    check('G2 ... quietly', $GLOBALS['bd_notices'] === [] && $leaked === [], json_encode([$GLOBALS['bd_notices'], $leaked]));
    bd_tenant(bd_contact_fields());
    [$g3] = bd_include(['panels' => []]);
    check('G3 a view with no panels is left alone', $g3 === ['panels' => []]);
    bd_tenant(null);
    [$g4] = bd_include(bd_dev_view());
    check('G4 vardefs that cannot be read (no id/name) -> nothing placed', $g4 === bd_dev_view());
    bd_tenant(bd_contact_fields());
    $GLOBALS['dictionary']['Contact'] = ['fields' => ['id' => ['name' => 'id', 'type' => 'id'], 'name' => ['name' => 'name', 'type' => 'fullname']]];
    [$g5] = bd_include(bd_dev_view());
    check('G5 a dictionary already loaded WITHOUT the five (loadVardef leaves it) -> nothing placed', $g5 === bd_dev_view());

    bd_tenant(null);
    $GLOBALS['dictionary']['Contact'] = ['fields' => array_diff_key(bd_contact_fields(), ['id' => 1, 'name' => 1])];
    [$g6] = bd_include(bd_dev_view());
    check('G6 a field list with no id / name (a half-read dictionary) -> nothing placed', $g6 === bd_dev_view());

    // ── H. the file itself ───────────────────────────────────────────────────────
    $tokens = token_get_all((string) file_get_contents(BD_OVERLAY));
    $decl = [];
    $refLoop = false;
    foreach ($tokens as $i => $t) {
        if (is_array($t) && in_array($t[0], [T_FUNCTION, T_CLASS, T_FN, T_INTERFACE, T_TRAIT], true)) {
            $decl[] = token_name($t[0]);
        }
        if ($t === '&') {
            $refLoop = true;
        }
    }
    check('H1 concatenated into record.ext.php and included twice: no function/class declarations', $decl === [], json_encode($decl));
    check('H2 no reference (&) anywhere - a foreach by reference leaks into the next fragment', !$refLoop);
    $src = (string) file_get_contents(BD_OVERLAY);
    check('H3 every variable it assigns is bd-prefixed', (static function () use ($tokens) {
        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_VARIABLE && !in_array($t[1], ['$viewdefs', '$GLOBALS'], true)
                && strpos($t[1], '$bd') !== 0) {
                return false;
            }
        }
        return true;
    })());
    check('H4 it catches Throwable: a metadata build must never die on a layout nicety',
        preg_match('/catch\s*\(\s*\\\\?Throwable\s+\$bd/', $src) === 1);

    // ── I. a vardef load that throws, and a malformed dictionary ────────────────
    bd_tenant(bd_contact_fields());
    $GLOBALS['bd_throw'] = true;
    [$i1, $leaked] = bd_include(bd_dev_view());
    $GLOBALS['bd_throw'] = false;
    check('I1 loadVardef throws -> the view as read, nothing left behind, the build goes on',
        $i1 === bd_dev_view() && $leaked === [], json_encode($leaked));
    bd_tenant(bd_contact_fields());
    $GLOBALS['dictionary']['Contact'] = ['fields' => 'broken'];
    [$i2] = bd_include(bd_dev_view());
    check('I2 a dictionary whose field list is not an array -> the view as read, no notice',
        $i2 === bd_dev_view() && $GLOBALS['bd_notices'] === [], json_encode($GLOBALS['bd_notices']));

    $failed = count(array_filter($checks, static function ($c) { return !$c[1]; }));
    printf("\n%d checks, %d failed\n", count($checks), $failed);
    exit($failed ? 1 : 0);
}
