<?php

/**
 * On a Bench quote, a new product that is not in the catalog takes the Part Number the seller types; every other line
 * keeps the read-only Part Number ERP-Core gives every customer.
 *
 * Run:  php scripts/tests/bd_new_product_part_number_test.php
 *       (BD_ERP_PART_NUMBER_RULE names ERP-Core's rule file; the shared pin is used when it is unset)
 * Exit: 0 all passed, 1 one or more failed.
 *
 * WHAT RUNS: the Products dependencies as Sugar merges them into one $dependencies (DependencyManager reads the
 * module's dependencydefs.php, then the merged Dependencies extension): stock's read_only_fields rule, transcribed
 * from SugarEnt 26.1.0 modules/Products/metadata/dependencydefs.php:12-46 exactly as ERP-Core's own test does; then
 * ERP-Core's REAL erp_part_number_read_only.php; then the shipped Bench file. The order is Sugar's: the extension
 * merge puts every `_override` file after the others (ModuleInstaller::sortExtensionFiles, is_override then mtime,
 * SugarEnt 26.1.0 ModuleInstall/ModuleInstaller.php:2445-2450). Every ReadOnly on mft_part_num is then evaluated the
 * way the browser does: true disables, FALSE ENABLES (sidecarExpressionContext.js setFieldDisabled:909-913), and an
 * empty, null or blank field reads as "" (sidecarExpressionContext.js getValue:160-215). The quote line grid takes
 * these rules from the Products record view (ProductBundles quote-data-group-list.js:297-312); that view served
 * ERP-Core's rule as `ProductsRecordView_erp_part_number_read_only`, value "true", on the Bench QA tenant (read
 * 2026-10-13).
 *
 * THE LINES ARE RECORDED ROWS, read from the Bench QA tenant's Products on 2026-10-13 (ids below). No unsent
 * new-product line existed there that day, so the unsent one is a sent new-product row with erp_sync_key as Sugar
 * serves it on an unsent line ("" on the unsent catalog row read the same day); the browser's own shapes are the
 * client's: stock sets product_template_id "" on the new-product choice (Products quote-data-relate.js:103-108) and
 * ERP-Core clears the group there (quote-data-relate.js:754) until the seller picks one (:663).
 *
 * RED BEFORE: ERP-Core's rule alone reads "true" on every line, so the new product's Part Number is the gray box.
 *
 * IF IT WERE BROKEN you would see: the new product's Part Number still read-only (no Bench rule, or merged before
 * ERP-Core's and so overwritten); a catalog line's Part Number typeable (the catalog clause dropped: catalog lines
 * carry a category too); a new product typeable before its group is picked or after the ERP holds it; a second rule
 * on the field; or a change on a tenant where ERP-Core's rule is absent.
 */

error_reporting(E_ALL);

const BD_RULE = __DIR__ . '/../../sugar-sell/BenchDogs-Ext/custom/Extension/modules/Products/Ext/Dependencies/'
    . '_override_zz_bd_new_product_part_number.php';
const BD_PINNED_ERP_CORE_RULE = __DIR__ . '/fixtures/shared-sugar/sugar-sell/ERP-Core/src/custom/Extension/modules/'
    . 'Products/Ext/Dependencies/erp_part_number_read_only.php';

$erpCoreRule = getenv('BD_ERP_PART_NUMBER_RULE') ?: BD_PINNED_ERP_CORE_RULE;

$checks = array();
function check(string $name, bool $ok, string $detail = ''): void
{
    global $checks;
    $checks[] = array($name, $ok);
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok || $detail === '' ? '' : "  -- $detail");
}

/** Stock's Products read_only_fields rule (SugarEnt 26.1.0 modules/Products/metadata/dependencydefs.php:12-46). */
function stockProductsDependencies(): array
{
    $dependencies = array('Products' => array());
    $dependencies['Products']['read_only_fields'] = array(
        'hooks' => array('edit'),
        'trigger' => 'true',
        'triggerFields' => array('product_template_id'),
        'onload' => true,
        'actions' => array(),
    );
    foreach (array('category_name', 'list_price', 'cost_price', 'tax_class', 'mft_part_num', 'weight') as $field) {
        $dependencies['Products']['read_only_fields']['actions'][] = array(
            'name' => 'ReadOnly',
            'params' => array('target' => $field, 'label' => $field . '_label',
                'value' => 'not(equal($product_template_id,""))'),
        );
    }

    return $dependencies;
}

/** Each file included into one $dependencies, in the order given, the way the merged deps.ext.php runs them. */
function mergeDependencies(array $dependencies, array $files): array
{
    foreach ($files as $file) {
        include $file;
    }

    return $dependencies;
}

/** A field's value as the browser's SugarLogic reads it: null, missing and blank are all "". */
function fieldValue(array $line, string $name): string
{
    $value = $line[$name] ?? null;
    if (!is_string($value)) {
        return $value === null ? '' : (string) $value;
    }

    return trim(str_replace("\n", '', $value)) === '' ? '' : $value;
}

/** The SugarLogic these rules use: true, false, not(), equal($field, "text"), and()/or() of any arity. */
function evaluate(string $expr, array $line): bool
{
    $expr = trim($expr);
    if ($expr === 'true' || $expr === 'false') {
        return $expr === 'true';
    }
    if (preg_match('/^equal\(\s*\$(\w+)\s*,\s*"([^"]*)"\s*\)$/', $expr, $m)) {
        return fieldValue($line, $m[1]) === $m[2];
    }
    if (preg_match('/^(not|and|or)\((.*)\)$/s', $expr, $m)) {
        $args = splitArguments($m[2]);
        if ($m[1] === 'not') {
            if (count($args) !== 1) {
                throw new RuntimeException('not() takes one argument: ' . $expr);
            }
            return !evaluate($args[0], $line);
        }
        $values = array_map(function ($arg) use ($line) {
            return evaluate($arg, $line);
        }, $args);

        return $m[1] === 'and' ? !in_array(false, $values, true) : in_array(true, $values, true);
    }
    throw new RuntimeException('unread SugarLogic: ' . $expr);
}

/** Top-level comma-separated arguments; commas inside parentheses or quotes belong to a nested argument. */
function splitArguments(string $text): array
{
    $args = array();
    $depth = 0;
    $quoted = false;
    $current = '';
    foreach (str_split($text) as $char) {
        if ($char === '"') {
            $quoted = !$quoted;
        } elseif (!$quoted && $char === '(') {
            $depth++;
        } elseif (!$quoted && $char === ')') {
            $depth--;
        } elseif (!$quoted && $depth === 0 && $char === ',') {
            $args[] = $current;
            $current = '';
            continue;
        }
        $current .= $char;
    }
    $args[] = $current;

    return $args;
}

/** Every ReadOnly on $target that fires on the given view action. */
function readOnlyRules(array $deps, string $target, string $viewAction): array
{
    $out = array();
    foreach ($deps as $name => $def) {
        $hooks = empty($def['hooks']) ? array('all') : (array) $def['hooks'];
        if (!in_array('all', $hooks, true) && !in_array($viewAction, $hooks, true)) {
            continue;
        }
        foreach ((array) ($def['actions'] ?? array()) as $action) {
            if (($action['name'] ?? '') === 'ReadOnly' && ($action['params']['target'] ?? '') === $target) {
                $out[] = array('rule' => $name, 'value' => (string) $action['params']['value']);
            }
        }
    }

    return $out;
}

/** Is the Part Number read-only on this line? null when the rules disagree (a fight the browser settles by order). */
function partNumberReadOnly(array $deps, array $line, string $viewAction): ?bool
{
    $answers = array_unique(array_map(function ($rule) use ($line) {
        return evaluate($rule['value'], $line);
    }, readOnlyRules($deps, 'mft_part_num', $viewAction)));
    if (count($answers) !== 1) {
        return null;
    }

    return reset($answers);
}

// Recorded on the Bench QA tenant, 2026-10-13 (Products REST read).
$catalogUnsent = array(   // fa0945c6-c753-11f1-a2ae-02b131184c4f
    'name' => 'APPLIANCE+ SAMPLE BOARD-WHITE', 'product_template_id' => '0e1020e0-c37f-11f1-b70e-066ddea26c4f',
    'category_id' => '46fb49fe-c37d-11f1-a395-066ddea26c4f', 'mft_part_num' => '20498P07W', 'erp_sync_key' => '',
);
$catalogSent = array(     // d098dabc-c6a4-11f1-88aa-066ddea26c4f
    'name' => 'APPLIANCE+ SAMPLE BOARD-WHITE', 'product_template_id' => '0e1020e0-c37f-11f1-b70e-066ddea26c4f',
    'category_id' => '46fb49fe-c37d-11f1-a395-066ddea26c4f', 'mft_part_num' => '20498P07W',
    'erp_sync_key' => 'ADM__8824_3_1',
);
$newProductSent = array(  // 548ca3bc-e6df-593a-8382-26dbaa551292, Product Group 3PL
    'name' => 'SAIQA C2 NEW BRACKET', 'product_template_id' => '', 'category_id' => '463a8318-c37d-11f1-a20d-066ddea26c4f',
    'mft_part_num' => 'TO-BE-CREATED', 'erp_sync_key' => 'ADM__8737_2_1',
);
$stampedSent = array(     // 0b63cfb5-c34a-5806-ba3c-5be688f8bcf5, Product Group PROTOTYPES/R&D
    'name' => 'New Auto Widget Case', 'product_template_id' => '',
    'category_id' => '47d3c554-c37d-11f1-b378-066ddea26c4f', 'mft_part_num' => 'NEW-Q15476-L1',
    'erp_sync_key' => 'ADM__8809_1_1',
);
// The same new product before the ERP holds it, as the seller edits it.
$newProductUnsent = array('erp_sync_key' => '') + $newProductSent;
$typed = array('mft_part_num' => 'BD-BRACKET-3PL-0001') + $newProductUnsent;
$cleared = array('mft_part_num' => '') + $newProductUnsent;
$noGroupYet = array('category_id' => '') + $newProductUnsent;
$blankRow = array('name' => '', 'product_template_id' => '', 'category_id' => '', 'mft_part_num' => '',
    'erp_sync_key' => '');
$nullsNewProduct = array('erp_sync_key' => null) + $newProductUnsent;
$nullsBlankRow = array('product_template_id' => null, 'category_id' => null, 'mft_part_num' => null,
    'erp_sync_key' => null, 'name' => null);

echo "-- Sugar's merge order puts the Bench file after ERP-Core's --\n";
check('the Bench file is an _override file, so it merges after the non-override ones',
    strpos(basename(BD_RULE), '_override') === 0, basename(BD_RULE));
check("ERP-Core's rule file is not an _override file", strpos(basename($erpCoreRule), '_override') !== 0,
    basename($erpCoreRule));

$stock = stockProductsDependencies();
$erpCoreOnly = mergeDependencies($stock, array($erpCoreRule))['Products'];
$merged = mergeDependencies($stock, array($erpCoreRule, BD_RULE))['Products'];

echo "-- RED BEFORE: ERP-Core's rule alone --\n";
check("ERP-Core's real rule is the one the Bench file rewrites: erp_part_number_read_only on mft_part_num",
    isset($erpCoreOnly['erp_part_number_read_only'])
        && count(readOnlyRules(array($erpCoreOnly['erp_part_number_read_only']), 'mft_part_num', 'edit')) === 1,
    json_encode($erpCoreOnly['erp_part_number_read_only'] ?? null));
check("without the Bench file, a new product's Part Number is the gray box (read-only)",
    partNumberReadOnly($erpCoreOnly, $newProductUnsent, 'edit') === true);

foreach (array('edit', 'create', 'view') as $view) {
    echo "-- $view --\n";
    $rules = readOnlyRules($merged, 'mft_part_num', $view);
    check("$view: ONE rule on mft_part_num, so no second rule can re-lock or re-open it", count($rules) === 1,
        json_encode($rules));
    check("$view: a new product not in the catalog, group picked, not in the ERP: Part Number EDITABLE",
        partNumberReadOnly($merged, $newProductUnsent, $view) === false);
    check("$view: ...and still editable once the seller typed a part number over the placeholder",
        partNumberReadOnly($merged, $typed, $view) === false);
    check("$view: ...and still editable with the box cleared, so a typo can be typed over",
        partNumberReadOnly($merged, $cleared, $view) === false);
    check("$view: ...with the browser's null for a key not fetched yet: editable",
        partNumberReadOnly($merged, $nullsNewProduct, $view) === false);
    check("$view: a catalog line (it carries a category too): read-only",
        partNumberReadOnly($merged, $catalogUnsent, $view) === true);
    check("$view: a catalog line the ERP holds: read-only", partNumberReadOnly($merged, $catalogSent, $view) === true);
    check("$view: a new product before its Product Group is picked: read-only",
        partNumberReadOnly($merged, $noGroupYet, $view) === true);
    check("$view: a new product the ERP holds (the placeholder): read-only",
        partNumberReadOnly($merged, $newProductSent, $view) === true);
    check("$view: a new product the ERP holds (its own NEW-Q part): read-only",
        partNumberReadOnly($merged, $stampedSent, $view) === true);
    check("$view: a blank new row, no product chosen: read-only",
        partNumberReadOnly($merged, $blankRow, $view) === true);
    check("$view: a blank new row as the browser holds it, all null: read-only",
        partNumberReadOnly($merged, $nullsBlankRow, $view) === true);
}

echo "-- what else the Bench file leaves as it was --\n";
$rule = $merged['erp_part_number_read_only'];
$before = $erpCoreOnly['erp_part_number_read_only'];
$value = (string) $rule['actions'][0]['params']['value'];
check('the rule never reads the Part Number itself, so typing in it cannot lock it',
    strpos($value, '$mft_part_num') === false, $value);
preg_match_all('/\$(\w+)/', $value, $read);
check('every field the rule reads re-checks it when it changes',
    array_diff($read[1], $rule['triggerFields']) === array(), json_encode(array($read[1], $rule['triggerFields'])));
check("ERP-Core's own triggers are kept", array_diff($before['triggerFields'], $rule['triggerFields']) === array(),
    json_encode($rule['triggerFields']));
$unchanged = $rule;
unset($unchanged['triggerFields'], $unchanged['actions'], $before['triggerFields'], $before['actions']);
check("ERP-Core's hooks, trigger and onload are kept", $unchanged === $before, json_encode($unchanged));
check('the other Products rules are untouched',
    array_diff_key($merged, array('erp_part_number_read_only' => 1))
        === array_diff_key($erpCoreOnly, array('erp_part_number_read_only' => 1)));

echo "-- a tenant where ERP-Core's rule is absent --\n";
check('the Bench file changes nothing there', mergeDependencies($stock, array(BD_RULE)) === $stock);
$leftBehind = (function (string $erpCoreRule): array {
    $dependencies = stockProductsDependencies();
    include $erpCoreRule;
    include BD_RULE;
    $names = array_keys(get_defined_vars());
    sort($names);

    return $names;
})($erpCoreRule);
check('merged after ERP-Core, it leaves no variable of its own in the shared scope',
    $leftBehind === array('dependencies', 'erpCoreRule'), json_encode($leftBehind));

echo "-- CONTROL: merged before ERP-Core's file, the Bench change would be lost --\n";
$wrongOrder = mergeDependencies($stock, array(BD_RULE, $erpCoreRule))['Products'];
check("in the other order the new product's Part Number is read-only again (why the file is an _override)",
    partNumberReadOnly($wrongOrder, $newProductUnsent, 'edit') === true);

$failed = count(array_filter($checks, function ($c) {
    return !$c[1];
}));
printf("\n%d checks, %d failed\n", count($checks), $failed);
exit($failed === 0 ? 0 : 1);
