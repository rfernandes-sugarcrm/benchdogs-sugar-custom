<?php

/**
 * Probe for test_bench_shipped_is_a_quantity_not_money.py.
 *
 * WHY THIS EXISTS. Decision 59's retirement is delivered by OVERWRITING six
 * Extension fragments with stubs that declare nothing — not by deleting them,
 * because on Sugar Cloud neither omitting a file from the manifest nor
 * uninstalling the package removes a copied custom/Extension file (both
 * measured on Bench, 2026-09-14). The test therefore cannot assert the files
 * are ABSENT. It has to assert they are PRESENT AND INERT.
 *
 * "Inert" is asserted POSITIVELY and BEHAVIOURALLY: this probe seeds the
 * superglobal a real Sugar Extension compile would provide, includes the
 * fragment exactly as Sugar would, and reports what the fragment CONTRIBUTED.
 * A stub passes only if it contributed nothing. That is strictly stronger
 * than pattern-matching a file name, which is what the previous test did and
 * which would now forbid the very mechanism that delivers the fix.
 *
 * Usage:  php bench_shipped_stub_probe.php <mode> <file>
 *         modes: vardefs | viewdefs | language | strip
 * Output: one JSON object on stdout.
 */

declare(strict_types=1);

$mode = $argv[1] ?? '';
$file = $argv[2] ?? '';

if ($mode === '' || $file === '' || !is_file($file)) {
    fwrite(STDERR, "usage: bench_shipped_stub_probe.php <mode> <file>\n");
    exit(2);
}

/** Bean-object keys a fragment might legitimately attach to. */
const MODULE_KEYS = [
    'ERP_OrderLines', 'ERP_OrderLine',
    'ERP_Orders', 'ERP_Order',
];

/**
 * Panel names the record-view fragments search for, BY NAME.
 *
 * A fragment that finds no matching panel is a silent no-op, so a panel
 * missing from this list makes the probe blind and reports a real fragment as
 * inert. That is not hypothetical: the first version of this list omitted
 * LBL_RECORDVIEW_PANEL_ORDER_DETAIL and the probe duly declared rc23's real
 * ERP_Orders fragment empty. It was caught by
 * test_the_probe_itself_detects_a_non_empty_fragment, which is exactly what
 * that test is for. When adding a fragment, add its panel here.
 */
const PANEL_NAMES = [
    'LBL_RECORDVIEW_PANEL_LINE_ITEM_DETAIL',
    'LBL_RECORDVIEW_PANEL_ORDER_DETAIL',
    'LBL_RECORDVIEW_PANEL_BENCHDOGS',
    'LBL_RECORDVIEW_PANEL_HEADER',
    'LBL_RECORDVIEW_PANEL_1',
    'LBL_RECORDVIEW_PANEL_2',
    'LBL_PANEL_1',
    'LBL_PANEL_2',
];

function emit(array $payload): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if ($mode === 'strip') {
    // Sugar's own tokenizer, so a mention inside a docblock is not mistaken
    // for a declaration. Returns the file with every comment removed.
    $code = '';
    foreach (token_get_all(file_get_contents($file)) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $tok[1];
        } else {
            $code .= $tok;
        }
    }
    emit(['code' => $code]);
}

if ($mode === 'vardefs') {
    $dictionary = [];
    foreach (MODULE_KEYS as $k) {
        $dictionary[$k] = ['fields' => [], 'table' => strtolower($k)];
    }
    require $file;
    $added = [];
    foreach (MODULE_KEYS as $k) {
        foreach (array_keys($dictionary[$k]['fields'] ?? []) as $fieldName) {
            $added[] = $k . '.' . $fieldName;
        }
    }
    emit(['contributed_fields' => $added, 'count' => count($added)]);
}

if ($mode === 'viewdefs') {
    $viewdefs = [];
    foreach (MODULE_KEYS as $k) {
        $panels = [];
        foreach (PANEL_NAMES as $p) {
            $panels[] = ['name' => $p, 'fields' => []];
        }
        $viewdefs[$k]['base']['view']['record'] = ['panels' => $panels];
    }
    require $file;
    $added = [];
    foreach (MODULE_KEYS as $k) {
        foreach ($viewdefs[$k]['base']['view']['record']['panels'] ?? [] as $panel) {
            foreach ((array) ($panel['fields'] ?? []) as $f) {
                $name = is_array($f) ? ($f['name'] ?? json_encode($f)) : $f;
                $added[] = $k . '/' . ($panel['name'] ?? '?') . '/' . $name;
            }
        }
    }
    emit(['contributed_layout_entries' => $added, 'count' => count($added)]);
}

if ($mode === 'language') {
    $mod_strings = [];
    require $file;
    $keys = array_keys($mod_strings);
    emit(['contributed_strings' => $keys, 'count' => count($keys)]);
}

fwrite(STDERR, "unknown mode: {$mode}\n");
exit(2);
